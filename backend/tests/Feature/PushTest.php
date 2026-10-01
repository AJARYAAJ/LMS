<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Notifications\AppNotification;
use App\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushTest extends TestCase
{
    use RefreshDatabase;

    /** A browser's subscription keys, kept so the test can decrypt like the browser would. */
    private function browser(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($key)['ec'];
        $public = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        return ['key' => $key, 'public' => $public, 'auth' => random_bytes(16)];
    }

    /** RFC 8291 decryption, as the browser does it. */
    private function decrypt(string $body, array $browser): string
    {
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $cipher = substr($body, 21 + $idLen);
        $shared = openssl_pkey_derive(WebPush::publicKey($asPublic), $browser['key'], 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$browser['public'].$asPublic, $browser['auth']);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertNotFalse($plain, 'payload did not decrypt');

        return rtrim($plain, "\x02\0");
    }

    public function test_web_push_delivers_an_encrypted_signed_message_the_browser_can_read(): void
    {
        config(['services.webpush.public_key' => null]);
        $admin = $this->organization();
        $browser = $this->browser();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';
        $vapid = $this->as($admin)->getJson('/api/v1/push')->assertOk()->json('data.vapid_public_key');
        $this->as($admin)->postJson('/api/v1/push/subscriptions', ['endpoint' => $endpoint, 'keys' => ['p256dh' => WebPush::b64($browser['public']), 'auth' => WebPush::b64($browser['auth'])]])->assertCreated();
        $this->as($admin)->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://x.test/1', 'keys' => ['p256dh' => 'short', 'auth' => 'x']])->assertStatus(422);

        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $admin->notify(new AppNotification('New lead for you', 'Dana from Globex', '/leads/7', 'assignment'));

        Http::assertSent(function (Request $r) use ($browser, $vapid, $endpoint) {
            if ($r->url() !== $endpoint) {
                return false;
            }
            $this->assertSame('aes128gcm', $r->header('Content-Encoding')[0]);
            $message = json_decode($this->decrypt($r->body(), $browser), true);
            $this->assertSame(['title' => 'New lead for you', 'body' => 'Dana from Globex', 'url' => '/leads/7', 'tag' => 'assignment'], $message);

            // VAPID: the JWT is signed with the key the browser subscribed with.
            preg_match('/vapid t=([^,]+), k=(.+)/', $r->header('Authorization')[0], $m);
            $this->assertSame($vapid, $m[2]);
            [$h, $c, $sig] = explode('.', $m[1]);
            $this->assertSame('https://fcm.googleapis.com', json_decode(WebPush::unb64($c), true)['aud']);
            $raw = WebPush::unb64($sig);
            $int = fn ($b) => ($b = ltrim($b, "\0")) === '' ? "\x02\x01\x00" : (ord($b[0]) > 0x7F ? "\x02".chr(strlen($b) + 1)."\0".$b : "\x02".chr(strlen($b)).$b);
            $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32));
            $this->assertSame(1, openssl_verify("{$h}.{$c}", "\x30".chr(strlen($seq)).$seq, WebPush::publicKey(WebPush::unb64($vapid)), OPENSSL_ALGO_SHA256));

            return true;
        });
        $this->assertNotNull(PushSubscription::first()->last_used_at);
    }

    public function test_expired_subscriptions_are_removed_and_preferences_are_respected(): void
    {
        $admin = $this->organization();
        $browser = $this->browser();
        $this->as($admin)->postJson('/api/v1/push/subscriptions', ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/x', 'keys' => ['p256dh' => WebPush::b64($browser['public']), 'auth' => WebPush::b64($browser['auth'])]]);

        $status = 201;
        Http::fake(function () use (&$status) {
            return Http::response('', $status);
        });
        $admin->notify(new AppNotification('Automation ran', '', null, 'automation')); // push is off for this kind by default
        Http::assertNothingSent();

        $status = 410;
        $admin->notify(new AppNotification('Lead assigned', '', null, 'assignment'));
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_native_app_tokens_are_sent_through_fcm(): void
    {
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rsa, $pem);
        config(['services.fcm.credentials' => json_encode(['project_id' => 'leadflow-app', 'client_email' => 'push@leadflow.iam.gserviceaccount.com', 'private_key' => $pem])]);
        $admin = $this->organization();
        $this->as($admin)->getJson('/api/v1/push')->assertJsonPath('data.fcm', true);
        $this->as($admin)->postJson('/api/v1/push/subscriptions', ['fcm_token' => 'device-token-1', 'device' => 'iPhone'])->assertCreated();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x']),
            'fcm.googleapis.com/v1/*' => Http::response(['name' => 'projects/leadflow-app/messages/1']),
        ]);
        $admin->notify(new AppNotification('Meeting booked', 'Tue 10:00', '/tasks', 'booking'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/leadflow-app/messages:send'
            && $r['message']['token'] === 'device-token-1' && $r['message']['data']['url'] === '/tasks' && $r->header('Authorization')[0] === 'Bearer ya29.x');

        $this->as($admin)->deleteJson('/api/v1/push/subscriptions', ['fcm_token' => 'device-token-1'])->assertNoContent();
        $this->assertSame(0, PushSubscription::count());
    }
}
