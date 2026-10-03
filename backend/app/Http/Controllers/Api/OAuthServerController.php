<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OAuthApp;
use App\Models\OAuthRefreshToken;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * OAuth 2.0 authorization server for the public API (RFC 6749 authorization
 * code grant with PKCE, RFC 7636; refresh tokens rotate on every use; RFC 7009
 * revocation). Access tokens are Sanctum tokens limited to the granted scopes.
 */
class OAuthServerController extends Controller
{
    private const ACCESS_TTL = 3600;

    private const REFRESH_DAYS = 60;

    /** The consent screen asks: which app, which permissions, where we'll send you. */
    public function describe(Request $request): JsonResponse
    {
        [$app, $scopes, $redirect] = $this->validateAuthorize($request);

        return response()->json(['data' => [
            'app' => ['name' => $app->name, 'client_id' => $app->client_id],
            'organization' => $request->user()->organization->name,
            'scopes' => collect($scopes)->map(fn ($s) => ['key' => $s, 'label' => OAuthApp::SCOPES[$s]])->values(),
            'redirect_host' => parse_url($redirect, PHP_URL_HOST),
        ]]);
    }

    /** The person clicked Allow or Deny. */
    public function approve(Request $request): JsonResponse
    {
        [$app, $scopes, $redirect] = $this->validateAuthorize($request);
        $state = $request->input('state');
        if (! $request->boolean('approve')) {
            return response()->json(['data' => ['redirect' => $this->append($redirect, array_filter(['error' => 'access_denied', 'state' => $state]))]]);
        }

        $request->user()->notify(new AppNotification("{$app->name} can now access your account", 'Permissions: '.implode(', ', $scopes).'. An admin can disconnect it under Settings → OAuth apps.', '/profile', 'security'));
        $code = Str::random(64);
        Cache::put('oauth_code:'.hash('sha256', $code), [
            'app_id' => $app->id, 'user_id' => $request->user()->id, 'scopes' => $scopes, 'redirect_uri' => $redirect,
            'challenge' => $request->input('code_challenge'),
        ], now()->addMinutes(10));

        return response()->json(['data' => ['redirect' => $this->append($redirect, array_filter(['code' => $code, 'state' => $state]))]]);
    }

    public function token(Request $request): JsonResponse
    {
        $app = OAuthApp::withoutGlobalScopes()->where('client_id', (string) $request->input('client_id'))->first();
        if (! $app || ($app->isConfidential() && ! Hash::check((string) $request->input('client_secret'), $app->secret_hash))) {
            return $this->error('invalid_client', 'Unknown client or wrong client secret.', 401);
        }

        return match ($request->input('grant_type')) {
            'authorization_code' => $this->fromCode($request, $app),
            'refresh_token' => $this->fromRefresh($request, $app),
            default => $this->error('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    public function revoke(Request $request): JsonResponse
    {
        $token = (string) $request->input('token');
        $app = OAuthApp::withoutGlobalScopes()->where('client_id', (string) $request->input('client_id'))->first();
        if ($app && $token) {
            $refresh = OAuthRefreshToken::where('oauth_app_id', $app->id)->where('token_hash', hash('sha256', $token))->first();
            if ($refresh) {
                $this->revokeGrant($refresh);
            } elseif (($access = PersonalAccessToken::findToken($token)) && $access->name === $app->tokenName()) {
                $access->delete();
            }
        }

        return response()->json([], 200); // RFC 7009: always 200
    }

    // ------------------------------------------------------------ grants

    private function fromCode(Request $request, OAuthApp $app): JsonResponse
    {
        $grant = Cache::pull('oauth_code:'.hash('sha256', (string) $request->input('code')));
        if (! $grant || $grant['app_id'] !== $app->id || $grant['redirect_uri'] !== $request->input('redirect_uri')) {
            return $this->error('invalid_grant', 'The code is invalid, expired or was already used.');
        }
        if ($grant['challenge'] || ! $app->isConfidential()) {
            $verifier = (string) $request->input('code_verifier');
            $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            if (! $grant['challenge'] || ! hash_equals($grant['challenge'], $expected)) {
                return $this->error('invalid_grant', 'PKCE verification failed.');
            }
        }
        $user = User::withoutGlobalScopes()->where('is_active', true)->find($grant['user_id']);
        if (! $user || $user->organization_id !== $app->organization_id) {
            return $this->error('invalid_grant', 'The user is no longer active.');
        }

        return $this->issue($app, $user, $grant['scopes']);
    }

    private function fromRefresh(Request $request, OAuthApp $app): JsonResponse
    {
        $refresh = OAuthRefreshToken::where('oauth_app_id', $app->id)->where('token_hash', hash('sha256', (string) $request->input('refresh_token')))->first();
        if (! $refresh || $refresh->expires_at->isPast()) {
            return $this->error('invalid_grant', 'The refresh token is invalid or expired.');
        }
        // A refresh token used twice means it leaked: end the whole grant.
        if ($refresh->revoked_at) {
            OAuthRefreshToken::where('oauth_app_id', $app->id)->where('user_id', $refresh->user_id)->get()->each(fn ($r) => $this->revokeGrant($r));

            return $this->error('invalid_grant', 'The refresh token was already used.');
        }
        $user = User::withoutGlobalScopes()->where('is_active', true)->find($refresh->user_id);
        if (! $user) {
            return $this->error('invalid_grant', 'The user is no longer active.');
        }
        $this->revokeGrant($refresh);

        return $this->issue($app, $user, $refresh->scopes);
    }

    private function issue(OAuthApp $app, User $user, array $scopes): JsonResponse
    {
        return DB::transaction(function () use ($app, $user, $scopes) {
            $access = $user->createToken($app->tokenName(), $scopes, now()->addSeconds(self::ACCESS_TTL));
            $refresh = Str::random(64);
            OAuthRefreshToken::create([
                'oauth_app_id' => $app->id, 'user_id' => $user->id, 'access_token_id' => $access->accessToken->id,
                'token_hash' => hash('sha256', $refresh), 'scopes' => $scopes, 'expires_at' => now()->addDays(self::REFRESH_DAYS),
            ]);

            return response()->json([
                'access_token' => $access->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => self::ACCESS_TTL,
                'refresh_token' => $refresh, 'scope' => implode(' ', $scopes),
            ])->header('Cache-Control', 'no-store');
        });
    }

    private function revokeGrant(OAuthRefreshToken $refresh): void
    {
        $refresh->forceFill(['revoked_at' => $refresh->revoked_at ?? now()])->save();
        if ($refresh->access_token_id) {
            PersonalAccessToken::whereKey($refresh->access_token_id)->delete();
        }
    }

    // ------------------------------------------------------------ helpers

    /** @return array{0: OAuthApp, 1: list<string>, 2: string} */
    private function validateAuthorize(Request $request): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'string'],
            'redirect_uri' => ['required', 'string'],
            'response_type' => ['required', 'in:code'],
            'scope' => ['nullable', 'string'],
            'state' => ['nullable', 'string', 'max:500'],
            'code_challenge' => ['nullable', 'string', 'min:43', 'max:128'],
            'code_challenge_method' => ['nullable', 'in:S256'],
        ]);
        $app = OAuthApp::where('client_id', $data['client_id'])->first();
        abort_unless($app, 404, 'This app isn’t registered with your workspace.');
        abort_unless(in_array($data['redirect_uri'], $app->redirect_uris, true), 422, 'The app’s redirect address isn’t registered.');
        abort_if(! $app->isConfidential() && empty($data['code_challenge']), 422, 'This app must use PKCE (code_challenge).');
        $scopes = array_values(array_unique(array_filter(preg_split('/[\s,]+/', $data['scope'] ?? 'read'))));
        abort_if(array_diff($scopes, array_keys(OAuthApp::SCOPES)), 422, 'The app asked for an unknown permission.');

        return [$app, $scopes ?: ['read'], $data['redirect_uri']];
    }

    private function append(string $url, array $query): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    private function error(string $code, string $description, int $status = 400): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], $status);
    }
}
