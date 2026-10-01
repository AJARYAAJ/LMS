<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OAuthAppsTest extends TestCase
{
    use RefreshDatabase;

    private function pkce(): array
    {
        $verifier = str_repeat('v', 50);

        return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /** Consent as the signed-in person; returns the code from the redirect. */
    private function consent(User $user, array $app, string $scope, ?string $challenge): string
    {
        $q = array_filter(['client_id' => $app['client_id'], 'redirect_uri' => 'https://zap.example.com/cb', 'response_type' => 'code', 'scope' => $scope, 'state' => 'xyz',
            'code_challenge' => $challenge, 'code_challenge_method' => $challenge ? 'S256' : null]);
        $this->as($user)->getJson('/api/v1/oauth/authorize?'.http_build_query($q))->assertOk()
            ->assertJsonPath('data.app.name', 'Zapier')->assertJsonPath('data.redirect_host', 'zap.example.com');
        $redirect = $this->as($user)->postJson('/api/v1/oauth/authorize', [...$q, 'approve' => true])->assertOk()->json('data.redirect');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $r);
        $this->assertSame('xyz', $r['state']);

        return $r['code'];
    }

    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_confidential_app_gets_scoped_tokens_and_refreshes_them(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $this->as($rep)->postJson('/api/v1/settings/oauth-apps', ['name' => 'X', 'redirect_uris' => ['https://x.test/cb'], 'confidential' => true])->assertForbidden();
        $res = $this->as($admin)->postJson('/api/v1/settings/oauth-apps', ['name' => 'Zapier', 'redirect_uris' => ['https://zap.example.com/cb'], 'confidential' => true])->assertCreated();
        $app = $res->json('data');
        $secret = $res->json('client_secret');
        $this->as($admin)->postJson('/api/v1/settings/oauth-apps', ['name' => 'Bad', 'redirect_uris' => ['http://evil.test/cb'], 'confidential' => true])->assertStatus(422);

        $code = $this->consent($rep, $app, 'read', null);
        $this->postJson('/api/v1/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $app['client_id'], 'client_secret' => 'wrong', 'code' => $code, 'redirect_uri' => 'https://zap.example.com/cb'])->assertStatus(401);
        $tokens = $this->postJson('/api/v1/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $app['client_id'], 'client_secret' => $secret, 'code' => $code, 'redirect_uri' => 'https://zap.example.com/cb'])
            ->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('scope', 'read')->json();
        // Codes are single-use.
        $this->postJson('/api/v1/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $app['client_id'], 'client_secret' => $secret, 'code' => $code, 'redirect_uri' => 'https://zap.example.com/cb'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // Read-only token: can read, cannot write, cannot touch settings or account.
        $this->bearer($tokens['access_token'])->getJson('/api/v1/leads')->assertOk();
        $this->bearer($tokens['access_token'])->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('email', $rep->email);
        $this->bearer($tokens['access_token'])->postJson('/api/v1/leads', ['first_name' => 'Nope'])->assertForbidden();
        $this->bearer($tokens['access_token'])->getJson('/api/v1/auth/two-factor')->assertForbidden();
        $this->bearer($tokens['access_token'])->getJson('/api/v1/push')->assertForbidden();

        // Refresh rotates; reusing the old refresh token ends the grant.
        $next = $this->postJson('/api/v1/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $app['client_id'], 'client_secret' => $secret, 'refresh_token' => $tokens['refresh_token']])->assertOk()->json();
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
        $this->bearer($tokens['access_token'])->getJson('/api/v1/leads')->assertUnauthorized();
        $this->bearer($next['access_token'])->getJson('/api/v1/leads')->assertOk();
        $this->postJson('/api/v1/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $app['client_id'], 'client_secret' => $secret, 'refresh_token' => $tokens['refresh_token']])->assertStatus(400);
        $this->bearer($next['access_token'])->getJson('/api/v1/leads')->assertUnauthorized();
    }

    public function test_public_app_must_use_pkce_and_write_scope_allows_changes(): void
    {
        $admin = $this->organization();
        $app = $this->as($admin)->postJson('/api/v1/settings/oauth-apps', ['name' => 'Zapier', 'redirect_uris' => ['https://zap.example.com/cb'], 'confidential' => false])->json('data');
        $this->as($admin)->getJson('/api/v1/oauth/authorize?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => 'https://zap.example.com/cb', 'response_type' => 'code']))->assertStatus(422);
        $this->as($admin)->getJson('/api/v1/oauth/authorize?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => 'https://other.test/cb', 'response_type' => 'code', 'code_challenge' => str_repeat('a', 43)]))->assertStatus(422);

        [$verifier, $challenge] = $this->pkce();
        $code = $this->consent($admin, $app, 'read write', $challenge);
        $this->postJson('/api/v1/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $app['client_id'], 'code' => $code, 'redirect_uri' => 'https://zap.example.com/cb', 'code_verifier' => 'wrong'])->assertStatus(400);

        $code = $this->consent($admin, $app, 'read write', $challenge);
        $t = $this->postJson('/api/v1/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $app['client_id'], 'code' => $code, 'redirect_uri' => 'https://zap.example.com/cb', 'code_verifier' => $verifier])->assertOk()->json();
        $this->bearer($t['access_token'])->postJson('/api/v1/leads', ['first_name' => 'From Zapier'])->assertCreated();
        $this->bearer($t['access_token'])->getJson('/api/v1/settings/users')->assertForbidden();

        // Deny sends the person back with an error; deleting the app signs it out.
        $deny = $this->as($admin)->postJson('/api/v1/oauth/authorize', ['client_id' => $app['client_id'], 'redirect_uri' => 'https://zap.example.com/cb', 'response_type' => 'code', 'code_challenge' => $challenge, 'approve' => false])->json('data.redirect');
        $this->assertStringContainsString('error=access_denied', $deny);
        $this->as($admin)->getJson('/api/v1/settings/oauth-apps')->assertJsonPath('data.0.active_users', 1);
        $this->as($admin)->deleteJson("/api/v1/settings/oauth-apps/{$app['id']}")->assertNoContent();
        $this->bearer($t['access_token'])->getJson('/api/v1/leads')->assertUnauthorized();
    }

    public function test_the_spa_session_keeps_full_access(): void
    {
        $admin = $this->organization();
        Sanctum::actingAs($admin, ['*']);
        $this->getJson('/api/v1/settings/oauth-apps')->assertOk();
    }
}
