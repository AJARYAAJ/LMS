<?php

namespace Tests\Feature;

use App\Models\LeadStatus;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_provisions_an_organization_with_defaults(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Globex',
            'name' => 'Hank Scorpio',
            'email' => 'hank@globex.test',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', User::ADMIN)
            ->assertJsonPath('user.organization.name', 'Globex')
            ->assertJsonPath('user.permissions.manage_settings', true)
            ->assertJsonStructure(['token']);

        $orgId = $response->json('user.organization_id');
        $this->assertSame(8, Tenant::run($orgId, fn () => LeadStatus::count()));
    }

    public function test_login_and_me(): void
    {
        $admin = $this->organization();

        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('email', $admin->email);
    }

    public function test_login_rejects_bad_credentials_and_inactive_users(): void
    {
        $admin = $this->organization();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'wrong'])->assertUnprocessable();

        $rep = $this->member($admin, User::SALES_REP, ['is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => $rep->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_protected_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/leads')->assertUnauthorized();
    }
}
