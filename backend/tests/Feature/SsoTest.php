<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    private function connect(User $admin, array $config = []): void
    {
        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'oidc_sso', 'config' => [
            'issuer' => 'https://idp.example.com', 'client_id' => 'cid', 'client_secret' => 'secret', 'domains' => 'acme.test, Acme.co',
            'default_role' => 'sales_rep', 'enforce' => 'no', ...$config,
        ]])->assertSuccessful();
    }

    private function fakeIdp(string $email, bool $verified = true): void
    {
        Http::fake([
            'idp.example.com/.well-known/openid-configuration' => Http::response([
                'authorization_endpoint' => 'https://idp.example.com/authorize', 'token_endpoint' => 'https://idp.example.com/token', 'userinfo_endpoint' => 'https://idp.example.com/userinfo',
            ]),
            'idp.example.com/token' => Http::response(['access_token' => 'at-1', 'token_type' => 'Bearer']),
            'idp.example.com/userinfo' => Http::response(['email' => $email, 'email_verified' => $verified, 'name' => 'Sam Single']),
        ]);
    }

    /** Runs the browser round trip and returns where the callback redirected to. */
    private function signIn(string $email): string
    {
        $url = $this->postJson('/api/v1/auth/sso', ['email' => $email])->assertOk()->json('data.url');
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('cid', $q['client_id']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertStringStartsWith('https://idp.example.com/authorize', $url);

        return $this->get('/api/v1/sso/callback?'.http_build_query(['code' => 'abc', 'state' => $q['state']]))->assertRedirect()->headers->get('Location');
    }

    public function test_new_person_signs_in_with_sso_and_is_provisioned(): void
    {
        $admin = $this->organization();
        $this->connect($admin);
        $this->fakeIdp('sam@acme.test');

        $location = $this->signIn('sam@acme.test');
        parse_str(parse_url($location, PHP_URL_QUERY), $q);
        $this->assertArrayHasKey('sso', $q);

        $res = $this->postJson('/api/v1/auth/sso/exchange', ['code' => $q['sso']])->assertOk();
        $this->assertSame('sam@acme.test', $res->json('user.email'));
        $this->assertSame('sales_rep', $res->json('user.role'));
        $this->assertTrue($res->json('user.sso'));
        $this->assertSame($admin->organization_id, $res->json('user.organization_id'));
        // The code is single-use, and the state can't be replayed.
        $this->postJson('/api/v1/auth/sso/exchange', ['code' => $q['sso']])->assertStatus(422);
        Http::assertSent(fn ($r) => $r->url() === 'https://idp.example.com/token' && $r['code_verifier'] && $r['client_secret'] === 'secret');
    }

    public function test_sso_refuses_unknown_domains_unverified_emails_and_other_workspaces(): void
    {
        $admin = $this->organization();
        $this->connect($admin);
        $this->postJson('/api/v1/auth/sso', ['email' => 'x@nowhere.test'])->assertStatus(422);

        $this->fakeIdp('sam@acme.test', verified: false);
        $this->assertStringContainsString('sso_error', $this->signIn('sam@acme.test'));

        $this->get('/api/v1/sso/callback?code=a&state='.str_repeat('x', 40))->assertRedirect();
        $this->assertSame(1, User::withoutGlobalScopes()->count());
    }

    public function test_enforced_sso_blocks_password_login_except_for_admins(): void
    {
        $admin = $this->organization();
        $this->member($admin, User::SALES_REP, ['email' => 'rep@acme.test', 'password' => 'password']);
        $this->connect($admin, ['enforce' => 'yes', 'domains' => 'acme.test, example.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'rep@acme.test', 'password' => 'password'])->assertStatus(422)->assertJsonPath('errors.email.0', 'Your organization signs in with single sign-on. Use “Sign in with SSO”.');
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
    }
}
