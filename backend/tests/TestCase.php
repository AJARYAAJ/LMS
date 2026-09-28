<?php

namespace Tests;

use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Tenant::set(null);
        parent::tearDown();
    }

    /**
     * Provision a fresh organization (with default configuration) and return its admin.
     */
    protected function organization(string $name = 'Acme'): User
    {
        return app(OrganizationProvisioner::class)->provision(
            ['name' => $name],
            ['name' => "{$name} Admin", 'email' => strtolower($name).'-admin@example.test', 'password' => 'password'],
        );
    }

    protected function member(User $admin, string $role = User::SALES_REP, array $attributes = []): User
    {
        return Tenant::run($admin->organization_id, fn () => User::create(array_merge([
            'organization_id' => $admin->organization_id,
            'name' => ucfirst($role).' '.uniqid(),
            'email' => uniqid($role).'@example.test',
            'password' => 'password',
            'role' => $role,
        ], $attributes)));
    }

    protected function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /**
     * Run a callback inside the given user's tenant, for direct model access in assertions.
     */
    protected function inTenant(User $user, callable $callback): mixed
    {
        return Tenant::run($user->organization_id, $callback);
    }
}
