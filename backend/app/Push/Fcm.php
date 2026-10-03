<?php

namespace App\Push;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging (HTTP v1) for the native iOS / Android app shell.
 * Authenticates with a Google service account (FCM_CREDENTIALS: JSON or a path to it).
 */
class Fcm
{
    public static function credentials(): ?array
    {
        $raw = config('services.fcm.credentials');
        if (! $raw) {
            return null;
        }
        $json = str_starts_with(trim($raw), '{') ? $raw : (is_file($raw) ? file_get_contents($raw) : null);
        $c = $json ? json_decode($json, true) : null;

        return ($c['client_email'] ?? null) && ($c['private_key'] ?? null) && ($c['project_id'] ?? null) ? $c : null;
    }

    public static function enabled(): bool
    {
        return self::credentials() !== null;
    }

    public function send(string $token, string $title, string $body, ?string $url): Response
    {
        $c = self::credentials() ?? throw new RuntimeException('FCM is not configured.');

        return Http::timeout(10)->withToken($this->accessToken($c))->post("https://fcm.googleapis.com/v1/projects/{$c['project_id']}/messages:send", [
            'message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => ['url' => (string) $url],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ],
        ]);
    }

    private function accessToken(array $c): string
    {
        return Cache::remember('fcm_token:'.md5($c['client_email']), 3000, function () use ($c) {
            $b64 = fn ($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $now = time();
            $input = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                'iss' => $c['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            openssl_sign($input, $sig, $c['private_key'], OPENSSL_ALGO_SHA256) ?: throw new RuntimeException('Invalid FCM service account key.');

            return Http::timeout(10)->asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $input.'.'.$b64($sig),
            ])->throw()->json('access_token');
        });
    }
}
