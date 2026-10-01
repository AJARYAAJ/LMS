<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\User;
use App\Security\OAuthClient;
use App\Services\AuditLogger;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Single sign-on with Google Workspace, Microsoft Entra ID or any OpenID
 * Connect provider. The person's email domain picks the organization.
 */
class SsoController extends Controller
{
    public function __construct(private OAuthClient $oauth) {}

    /** The organization's SSO connection for an email address, if any. */
    public static function forEmail(string $email): ?Integration
    {
        $domain = Str::lower(Str::after($email, '@'));

        return Integration::withoutGlobalScopes()->where('category', 'sso')->where('is_active', true)->get()
            ->first(fn (Integration $i) => in_array($domain, self::domains($i), true));
    }

    /** @return list<string> */
    public static function domains(Integration $i): array
    {
        return array_values(array_filter(array_map(fn ($d) => Str::lower(trim($d, " \t@")), explode(',', (string) $i->setting('domains')))));
    }

    public static function enforced(User $user): bool
    {
        $i = self::forEmail($user->email);

        return $i && $i->organization_id === $user->organization_id && $i->setting('enforce') === 'yes' && $user->role !== User::ADMIN;
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $i = self::forEmail($data['email']);
        if (! $i) {
            throw ValidationException::withMessages(['email' => 'Single sign-on isn’t set up for this email domain. Sign in with your password instead.']);
        }
        try {
            $endpoints = $this->oauth->discover(OAuthClient::issuer(str_replace('_sso', '', $i->provider), $i->config ?? []));
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['email' => 'Your identity provider could not be reached. Ask your admin to check the SSO settings.']);
        }
        $url = $this->oauth->authorizeUrl($endpoints, $i->setting('client_id'), url('/api/v1/sso/callback'), ['openid', 'email', 'profile'],
            ['purpose' => 'sso', 'integration_id' => $i->id], ['login_hint' => $data['email'], 'prompt' => 'select_account', 'nonce' => Str::random(24)]);

        return response()->json(['data' => ['url' => $url]]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $app = rtrim((string) config('app.frontend_url'), '/');
        $fail = fn (string $message) => redirect()->away("{$app}/login?".http_build_query(['sso_error' => $message]));

        if ($request->query('error')) {
            return $fail((string) ($request->query('error_description') ?: 'Sign-in was canceled.'));
        }
        $state = $this->oauth->pullState((string) $request->query('state', ''));
        if (! $state || ($state['purpose'] ?? null) !== 'sso') {
            return $fail('This sign-in link expired. Please try again.');
        }
        $i = Integration::withoutGlobalScopes()->where('is_active', true)->find($state['integration_id']);
        if (! $i) {
            return $fail('Single sign-on was turned off for your organization.');
        }

        try {
            $endpoints = $this->oauth->discover(OAuthClient::issuer(str_replace('_sso', '', $i->provider), $i->config ?? []));
            $tokens = $this->oauth->exchange($endpoints['token_endpoint'], $i->setting('client_id'), $i->setting('client_secret'), (string) $request->query('code'), $state['redirect_uri'], $state['verifier']);
            $person = $this->oauth->userinfo($endpoints['userinfo_endpoint'] ?? throw new \RuntimeException('The identity provider has no userinfo endpoint.'), $tokens['access_token']);
        } catch (Throwable $e) {
            report($e);

            return $fail('Your identity provider did not confirm the sign-in.');
        }

        if (! $person['email'] || ! $person['email_verified'] || ! in_array(Str::after($person['email'], '@'), self::domains($i), true)) {
            return $fail('That account isn’t allowed to sign in to this workspace.');
        }

        $user = User::withoutGlobalScopes()->where('email', $person['email'])->first();
        if ($user && $user->organization_id !== $i->organization_id) {
            return $fail('This email belongs to a different workspace.');
        }
        if ($user && ! $user->is_active) {
            return $fail('Your account has been deactivated.');
        }
        $user ??= Tenant::run($i->organization_id, function () use ($i, $person) {
            $role = in_array($i->setting('default_role'), [User::SALES_REP, User::VIEWER, User::MANAGER], true) ? $i->setting('default_role') : User::SALES_REP;
            $created = User::create([
                'organization_id' => $i->organization_id, 'name' => $person['name'] ?: Str::before($person['email'], '@'),
                'email' => $person['email'], 'password' => Str::random(40), 'role' => $role,
            ]);
            app(AuditLogger::class)->log('created', $created, [], ['via' => 'single sign-on']);

            return $created;
        });

        $code = Str::random(48);
        Cache::put("sso_login:{$code}", $user->id, now()->addMinutes(2));

        return redirect()->away("{$app}/login?".http_build_query(['sso' => $code]));
    }
}
