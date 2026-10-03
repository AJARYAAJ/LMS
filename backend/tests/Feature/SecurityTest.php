<?php

namespace Tests\Feature;

use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_are_isolated(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');

        $leadId = $this->as($acme)->postJson('/api/v1/leads', ['first_name' => 'Secret'])->json('data.id');

        $this->as($globex)->getJson("/api/v1/leads/{$leadId}")->assertNotFound();
        $this->as($globex)->getJson('/api/v1/leads')->assertJsonPath('total', 0);
        $this->as($globex)->getJson('/api/v1/search?q=secret')->assertJsonCount(0, 'data');
    }

    public function test_foreign_keys_from_other_organizations_are_rejected(): void
    {
        $acme = $this->organization('Acme');
        $globex = $this->organization('Globex');
        $foreignStatus = $this->inTenant($globex, fn () => LeadStatus::first());

        $this->as($acme)->postJson('/api/v1/leads', ['first_name' => 'X', 'lead_status_id' => $foreignStatus->id, 'owner_id' => $globex->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lead_status_id', 'owner_id']);
    }

    public function test_sales_reps_only_see_their_own_leads(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $other = $this->member($admin);

        $mine = $this->as($rep)->postJson('/api/v1/leads', ['first_name' => 'Mine'])->assertCreated();
        $this->assertSame($rep->id, $mine->json('data.owner_id'));
        $theirs = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Theirs', 'owner_id' => $other->id])->json('data.id');

        $this->as($rep)->getJson('/api/v1/leads')->assertJsonPath('total', 1);
        $this->as($rep)->getJson("/api/v1/leads/{$theirs}")->assertNotFound();
        $this->as($rep)->postJson('/api/v1/leads', ['first_name' => 'Sneaky', 'owner_id' => $other->id])->assertForbidden();
        $this->as($rep)->deleteJson("/api/v1/leads/{$mine->json('data.id')}")->assertForbidden();
    }

    public function test_viewers_are_read_only_and_settings_are_admin_only(): void
    {
        $admin = $this->organization();
        $viewer = $this->member($admin, User::VIEWER);
        $manager = $this->member($admin, User::MANAGER);

        $this->as($viewer)->getJson('/api/v1/leads')->assertOk();
        $this->as($viewer)->postJson('/api/v1/leads', ['first_name' => 'Nope'])->assertForbidden();
        $this->as($manager)->postJson('/api/v1/settings/lead-statuses', ['name' => 'Demo'])->assertForbidden();
        $this->as($admin)->postJson('/api/v1/settings/lead-statuses', ['name' => 'Demo Booked'])
            ->assertCreated()->assertJsonPath('data.key', 'demo_booked');
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $admin = $this->organization();

        $this->as($admin)->patchJson("/api/v1/settings/users/{$admin->id}", ['role' => User::SALES_REP])
            ->assertUnprocessable();
    }

    public function test_public_capture_requires_a_valid_api_key(): void
    {
        $admin = $this->organization();
        $key = $this->as($admin)->postJson('/api/v1/settings/api-keys', ['name' => 'Website'])->assertCreated()->json('key');

        $this->postJson('/api/v1/capture/leads', ['name' => 'Web Visitor', 'email' => 'v@corp.io'])->assertUnauthorized();

        $this->withHeader('X-Api-Key', $key)
            ->postJson('/api/v1/capture/leads', ['name' => 'Web Visitor', 'email' => 'v@corp.io', 'utm_source' => 'referral'])
            ->assertCreated();

        $this->as($admin)->getJson('/api/v1/leads')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.last_name', 'Visitor')
            ->assertJsonPath('data.0.source.name', 'Referral');
    }

    public function test_csv_export_neutralises_formulas(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => '=HYPERLINK("x")']);

        $csv = $this->as($admin)->get('/api/v1/leads/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
