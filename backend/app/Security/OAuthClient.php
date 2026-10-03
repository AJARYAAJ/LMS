<?php

namespace App\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Minimal OAuth 2.0 / OpenID Connect client (authorization code + PKCE).
 * Used for single sign-on and for connecting Google / Microsoft mailboxes.
 * Identity comes from the provider's userinfo endpoint, called with the
 * access token we received directly from its token endpoint over TLS.
 */
class OAuthClient
{
    /** Well-known issuers. */
    public static function issuer(string $provider, array $settings = []): string
    {
        return match ($provider) {
            'google' => 'https://accounts.google.com',
            'microsoft' => 'https://login.microsoftonline.com/'.($settings['tenant'] ?? null ?: 'common').'/v2.0',
            default => rtrim((string) ($settings['issuer'] ?? ''), '/'),
        };
    }

    /** @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: ?string} */
    public function discover(string $issuer): array
    {
        if (! preg_match('~^https://~', $issuer)) {
            throw new RuntimeException('The issuer must be an https:// URL.');
        }

        return Cache::remember('oidc:'.sha1($issuer), 3600, function () use ($issuer) {
            $doc = Http::timeout(10)->acceptJson()->get($issuer.'/.well-known/openid-configuration')->throw()->json();
            if (empty($doc['authorization_endpoint']) || empty($doc['token_endpoint'])) {
                throw new RuntimeException('The identity provider did not publish its endpoints.');
            }

            return [
                'authorization_endpoint' => $doc['authorization_endpoint'],
                'token_endpoint' => $doc['token_endpoint'],
                'userinfo_endpoint' => $doc['userinfo_endpoint'] ?? null,
            ];
        });
    }

    /**
     * Start an authorization: returns the URL to send the browser to. The state
     * (with the PKCE verifier and whatever $context the caller needs) is kept
     * server-side for ten minutes.
     */
    public function authorizeUrl(array $endpoints, string $clientId, string $redirectUri, array $scopes, array $context, array $extra = []): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        Cache::put("oauth_state:{$state}", [...$context, 'verifier' => $verifier, 'redirect_uri' => $redirectUri], now()->addMinutes(10));

        return $endpoints['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            ...$extra,
        ]);
    }

    /** One-time: the context saved by authorizeUrl(), or null if unknown/expired. */
    public function pullState(string $state): ?array
    {
        return strlen($state) === 40 ? Cache::pull("oauth_state:{$state}") : null;
    }

    /** @return array{access_token: string, refresh_token?: string, expires_in?: int, id_token?: string} */
    public function exchange(string $tokenEndpoint, string $clientId, string $clientSecret, string $code, string $redirectUri, string $verifier): array
    {
        return $this->token($tokenEndpoint, [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri,
            'client_id' => $clientId, 'client_secret' => $clientSecret, 'code_verifier' => $verifier,
        ]);
    }

    public function refresh(string $tokenEndpoint, string $clientId, string $clientSecret, string $refreshToken): array
    {
        return $this->token($tokenEndpoint, [
            'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'client_id' => $clientId, 'client_secret' => $clientSecret,
        ]);
    }

    /** @return array{email: ?string, email_verified: bool, name: ?string} */
    public function userinfo(string $endpoint, string $accessToken): array
    {
        $info = Http::timeout(10)->withToken($accessToken)->acceptJson()->get($endpoint)->throw()->json();
        $email = $info['email'] ?? $info['preferred_username'] ?? $info['upn'] ?? null;

        return [
            'email' => $email ? strtolower(trim($email)) : null,
            // Microsoft doesn't send email_verified; its addresses are directory-managed.
            'email_verified' => filter_var($info['email_verified'] ?? true, FILTER_VALIDATE_BOOL),
            'name' => $info['name'] ?? trim(($info['given_name'] ?? '').' '.($info['family_name'] ?? '')) ?: null,
        ];
    }

    private function token(string $endpoint, array $form): array
    {
        $response = Http::timeout(15)->asForm()->acceptJson()->post($endpoint, $form);
        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException('The identity provider refused the sign-in: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        return $response->json();
    }
}
