<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_layouts_are_served_to_every_user(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin, User::SALES_REP);

        $this->as($rep)->getJson('/api/v1/meta')
            ->assertOk()
            ->assertJsonPath('data.layouts.lead.sections.0.title', 'Contact')
            ->assertJsonPath('data.layouts.lead.customized', false)
            ->assertJsonPath('data.layouts.deal.sections.0.fields.0', 'name');
    }

    public function test_admin_can_rearrange_hide_and_reset_a_layout(): void
    {
        $admin = $this->organization();

        $this->as($admin)->putJson('/api/v1/settings/layouts/lead', [
            'sections' => [
                ['title' => 'Essentials', 'fields' => ['first_name', 'company', 'email', 'lead_status_id']],
                ['title' => 'Money', 'fields' => ['budget', 'expected_value']],
            ],
            'hidden' => ['state', 'company_size', 'team_id'],
        ])->assertOk()
            ->assertJsonPath('data.sections.0.title', 'Essentials')
            ->assertJsonPath('data.sections.1.fields', ['budget', 'expected_value'])
            // everything not placed or hidden is still reachable
            ->assertJsonPath('data.sections.2.title', 'Additional fields')
            ->assertJsonPath('data.hidden', ['state', 'company_size', 'team_id']);

        $this->as($admin)->deleteJson('/api/v1/settings/layouts/lead')->assertOk()->assertJsonPath('data.customized', false);
    }

    public function test_layout_validation(): void
    {
        $admin = $this->organization();
        $manager = $this->member($admin, User::MANAGER);

        $this->as($admin)->putJson('/api/v1/settings/layouts/lead', ['sections' => [['title' => 'X', 'fields' => ['email']]], 'hidden' => ['first_name']])
            ->assertUnprocessable()->assertJsonValidationErrors('sections'); // required field removed
        $this->as($admin)->putJson('/api/v1/settings/layouts/lead', ['sections' => [['title' => 'X', 'fields' => ['first_name', 'first_name']]], 'hidden' => []])
            ->assertUnprocessable();
        $this->as($admin)->putJson('/api/v1/settings/layouts/lead', ['sections' => [['title' => 'X', 'fields' => ['first_name', 'password']]], 'hidden' => []])
            ->assertUnprocessable();
        $this->as($admin)->putJson('/api/v1/settings/layouts/widgets', ['sections' => [], 'hidden' => []])->assertNotFound();
        $this->as($manager)->putJson('/api/v1/settings/layouts/lead', ['sections' => [['title' => 'X', 'fields' => ['first_name']]], 'hidden' => []])->assertForbidden();
    }

    public function test_custom_fields_can_be_placed_and_new_ones_appear_automatically(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/settings/custom-fields', ['label' => 'Seats', 'entity' => 'deal', 'type' => 'number']);

        $this->as($admin)->putJson('/api/v1/settings/layouts/deal', [
            'sections' => [['title' => 'Deal', 'fields' => ['name', 'custom.seats', 'amount']]],
            'hidden' => [],
        ])->assertOk()->assertJsonPath('data.sections.0.fields.1', 'custom.seats');

        $this->as($admin)->postJson('/api/v1/settings/custom-fields', ['label' => 'Region', 'entity' => 'deal']);
        $this->as($admin)->getJson('/api/v1/meta')->assertJsonPath('data.layouts.deal.sections.1.fields', [
            'pipeline_stage_id', 'account_id', 'contact_id', 'owner_id', 'expected_close_date', 'description', 'custom.region',
        ]);
    }

    public function test_saving_other_settings_keeps_layouts(): void
    {
        $admin = $this->organization();
        $this->as($admin)->putJson('/api/v1/settings/layouts/contact', ['sections' => [['title' => 'Person', 'fields' => ['first_name', 'email']]], 'hidden' => []]);
        $this->as($admin)->patchJson('/api/v1/settings/organization', ['settings' => ['qualification_criteria' => [['key' => 'budget', 'label' => 'Budget']]]])->assertOk();

        $this->as($admin)->getJson('/api/v1/meta')
            ->assertJsonPath('data.layouts.contact.sections.0.title', 'Person')
            ->assertJsonPath('data.qualification_criteria.0.label', 'Budget');
    }
}
