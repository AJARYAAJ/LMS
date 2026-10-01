<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Security\OAuthClient;
use App\Services\Mailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/** Connect your own Gmail / Outlook mailbox and calendar. */
class ConnectedAccountController extends Controller
{
    public function __construct(private OAuthClient $oauth, private Mailbox $mailbox) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'accounts' => ConnectedAccount::where('user_id', $request->user()->id)->get(),
            'providers' => collect(ConnectedAccount::PROVIDERS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'available' => Mailbox::configured($key)])->values(),
        ]]);
    }

    public function connect(Request $request, string $provider): JsonResponse
    {
        abort_unless(isset(ConnectedAccount::PROVIDERS[$provider]), 404);
        abort_unless(Mailbox::configured($provider), 422, 'Your administrator hasn’t set up '.ucfirst($provider).' connections on this server yet.');
        $endpoints = $this->oauth->discover(Mailbox::issuer($provider));
        $extra = $provider === 'google' ? ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'] : ['prompt' => 'select_account'];
        $url = $this->oauth->authorizeUrl($endpoints, config("services.{$provider}.client_id"), url('/api/v1/connected-accounts/callback'), Mailbox::SCOPES[$provider],
            ['purpose' => 'mailbox', 'provider' => $provider, 'user_id' => $request->user()->id], [...$extra, 'login_hint' => $request->user()->email]);

        return response()->json(['data' => ['url' => $url]]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $back = fn (array $q) => redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/profile?'.http_build_query($q));
        $state = $this->oauth->pullState((string) $request->query('state', ''));
        if (! $state || ($state['purpose'] ?? null) !== 'mailbox') {
            return $back(['connect_error' => 'This link expired. Please try connecting again.']);
        }
        if ($request->query('error')) {
            return $back(['connect_error' => 'The connection was canceled.']);
        }
        $user = User::withoutGlobalScopes()->where('is_active', true)->findOrFail($state['user_id']);
        $provider = $state['provider'];

        try {
            $endpoints = $this->oauth->discover(Mailbox::issuer($provider));
            $t = $this->oauth->exchange($endpoints['token_endpoint'], config("services.{$provider}.client_id"), config("services.{$provider}.client_secret"), (string) $request->query('code'), $state['redirect_uri'], $state['verifier']);
            $email = $this->oauth->userinfo($endpoints['userinfo_endpoint'], $t['access_token'])['email'] ?? throw new \RuntimeException('No email address on that account.');
        } catch (Throwable $e) {
            report($e);

            return $back(['connect_error' => 'Google / Microsoft did not confirm the connection.']);
        }

        ConnectedAccount::withoutGlobalScopes()->updateOrCreate(['user_id' => $user->id, 'provider' => $provider], [
            'organization_id' => $user->organization_id, 'email' => $email,
            'access_token' => $t['access_token'], 'refresh_token' => $t['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($t['expires_in'] ?? 3600)), 'last_error' => null,
        ]);

        return $back(['connected' => $provider]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $account = ConnectedAccount::where('user_id', $request->user()->id)->findOrFail($id);
        $account->update($request->validate(['sync_mail' => ['sometimes', 'boolean'], 'sync_calendar' => ['sometimes', 'boolean']]));

        return response()->json(['data' => $account]);
    }

    public function sync(Request $request, int $id): JsonResponse
    {
        $account = ConnectedAccount::where('user_id', $request->user()->id)->findOrFail($id);
        $account->forceFill(['last_error' => null])->save();
        $added = $this->mailbox->safely($account, fn () => $this->mailbox->syncMail($account));
        abort_if($added === null, 422, 'Sync failed: '.$account->fresh()->last_error);

        return response()->json(['data' => $account->fresh(), 'message' => $added ? "{$added} new email".($added === 1 ? '' : 's').' added to timelines.' : 'Up to date — no new emails with leads.']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        ConnectedAccount::where('user_id', $request->user()->id)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
