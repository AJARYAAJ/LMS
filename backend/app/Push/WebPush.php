<?php

namespace App\Push;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Standard Web Push (RFC 8030) with message encryption (RFC 8291, aes128gcm)
 * and VAPID sender identification (RFC 8292), using only OpenSSL.
 * Works in Chrome, Edge, Firefox and Safari (macOS, and iOS home-screen apps).
 */
class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /** @return array{public: string, private: string} base64url raw keys (65-byte public, 32-byte private) */
    public static function keys(): array
    {
        if (config('services.webpush.public_key') && config('services.webpush.private_key')) {
            return ['public' => config('services.webpush.public_key'), 'private' => config('services.webpush.private_key')];
        }
        // No keys configured: create a pair once and keep it with the app's storage.
        $path = storage_path('app/private/vapid.json');
        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, json_encode(self::generateKeys()));
            @chmod($path, 0600);
        }

        return json_decode(File::get($path), true);
    }

    /** @return array{public: string, private: string} */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($key)['ec'];

        return ['public' => self::b64(self::rawPublic($d)), 'private' => self::b64(str_pad($d['d'], 32, "\0", STR_PAD_LEFT))];
    }

    /**
     * Encrypt and deliver one message. Returns the push service's response
     * (201 = accepted; 404 / 410 = the subscription is gone).
     */
    public function send(string $endpoint, string $p256dh, string $auth, string $payload, int $ttl = 86400): Response
    {
        $body = $this->encrypt($payload, self::unb64($p256dh), self::unb64($auth));
        $keys = self::keys();

        return Http::timeout(10)->withHeaders([
            'Authorization' => 'vapid t='.$this->vapidJwt($endpoint, $keys).', k='.$keys['public'],
            'Content-Encoding' => 'aes128gcm',
            'TTL' => (string) $ttl,
            'Urgency' => 'normal',
        ])->withBody($body, 'application/octet-stream')->post($endpoint);
    }

    /** RFC 8291 aes128gcm content encoding, one record. */
    public function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $salt = null, $ephemeral = null): string
    {
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Invalid push subscription keys.');
        }
        $ephemeral ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublic = self::rawPublic(openssl_pkey_get_details($ephemeral)['ec']);
        $shared = openssl_pkey_derive(self::publicKey($uaPublic), $ephemeral, 32);
        if ($shared === false) {
            throw new RuntimeException('Could not derive the shared secret.');
        }

        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $salt ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    /** ES256 JWT identifying this server to the push service. */
    public function vapidJwt(string $endpoint, array $keys): string
    {
        $parts = parse_url($endpoint);
        $claims = [
            'aud' => $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''),
            'exp' => time() + 12 * 3600,
            'sub' => config('services.webpush.subject') ?: 'mailto:'.(config('mail.from.address') ?: 'admin@example.com'),
        ];
        $input = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        openssl_sign($input, $der, self::privateKey(self::unb64($keys['private']), self::unb64($keys['public'])), OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64(self::derToRaw($der));
    }

    // ------------------------------------------------------------ helpers

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    private static function rawPublic(array $ec): string
    {
        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function publicKey(string $raw)
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX).$raw), 64, "\n")."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) ?: throw new RuntimeException('Invalid P-256 public key.');
    }

    private static function privateKey(string $d, string $public)
    {
        // SEC1 ECPrivateKey: version 1, the 32-byte secret, the curve and the public point.
        $der = hex2bin('30770201010420').$d.hex2bin('a00a06082a8648ce3d030107a144034200').$public;
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";

        return openssl_pkey_get_private($pem) ?: throw new RuntimeException('Invalid VAPID private key.');
    }

    /** OpenSSL gives an ASN.1 signature; JWS wants the raw 64-byte r || s. */
    private static function derToRaw(string $der): string
    {
        $offset = 2;
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }
}
