<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Services\AiLeadAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PendingFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_segments_filter_leads_by_conditions(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Big', 'email' => 'big@corp.io', 'budget' => 50000, 'country' => 'India']);
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Small', 'email' => 'small@gmail.com', 'budget' => 500, 'country' => 'India']);
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Other', 'email' => 'x@corp2.io', 'budget' => 90000, 'country' => 'Germany']);

        $segment = json_encode([
            ['field' => 'country', 'operator' => 'equals', 'value' => 'india'],
            ['field' => 'budget', 'operator' => 'greater_than', 'value' => '10000'],
        ]);
        $this->as($admin)->getJson('/api/v1/leads?conditions='.urlencode($segment))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.first_name', 'Big');

        $business = json_encode([['field' => 'email', 'operator' => 'business_email']]);
        $this->as($admin)->getJson('/api/v1/leads?conditions='.urlencode($business))->assertJsonPath('total', 2);

        $status = json_encode([['field' => 'status_key', 'operator' => 'equals', 'value' => 'new']]);
        $this->as($admin)->getJson('/api/v1/leads?conditions='.urlencode($status))->assertJsonPath('total', 3);
    }

    public function test_sms_and_whatsapp_are_logged_on_the_timeline(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ravi', 'phone' => '+91 98765 43210'])->json('data.id');

        $this->as($admin)->postJson("/api/v1/leads/{$id}/message", ['channel' => 'whatsapp', 'body' => 'Hi {first_name}!'])
            ->assertOk()->assertJsonPath('data.driver', 'log');

        $this->as($admin)->getJson("/api/v1/leads/{$id}/activities?type=whatsapp")
            ->assertJsonPath('data.0.description', 'Hi Ravi!')
            ->assertJsonPath('data.0.meta.to', '+919876543210');

        $noPhone = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'NoPhone'])->json('data.id');
        $this->as($admin)->postJson("/api/v1/leads/{$noPhone}/message", ['channel' => 'sms', 'body' => 'x'])->assertUnprocessable();
    }

    public function test_ai_brief_reports_when_disabled_and_returns_brief_when_enabled(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ada'])->json('data.id');

        config(['services.anthropic.key' => null]);
        $this->as($admin)->postJson("/api/v1/leads/{$id}/ai-brief")->assertUnprocessable()->assertJsonPath('enabled', false);
        $this->as($admin)->getJson('/api/v1/meta')->assertJsonPath('data.features.ai', false);

        $advisor = Mockery::mock(AiLeadAdvisor::class);
        $advisor->shouldReceive('enabled')->andReturn(true);
        $advisor->shouldReceive('brief')->once()->withArgs(fn (Lead $l, bool $refresh) => $l->id === $id && $refresh)
            ->andReturn(['summary' => 'Ada is new.', 'next_action_title' => 'Call Ada', 'next_action_reason' => 'Never contacted.', 'talking_points' => ['Intro'], 'risk' => '', 'model' => 'claude-opus-5', 'generated_at' => now()->toIso8601String()]);
        $this->app->instance(AiLeadAdvisor::class, $advisor);

        $this->as($admin)->postJson("/api/v1/leads/{$id}/ai-brief?refresh=1")->assertOk()->assertJsonPath('data.next_action_title', 'Call Ada');
    }

    public function test_contacts_accounts_and_deals_store_custom_fields(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/accounts', ['name' => 'Acme', 'custom_fields' => ['tier' => 'Gold']])
            ->assertCreated()->assertJsonPath('data.custom_fields.tier', 'Gold');
        $this->as($admin)->postJson('/api/v1/contacts', ['first_name' => 'Jo', 'custom_fields' => ['linkedin' => 'jo']])
            ->assertCreated()->assertJsonPath('data.custom_fields.linkedin', 'jo');
        $this->as($admin)->postJson('/api/v1/deals', ['name' => 'Big deal', 'custom_fields' => ['seats' => 50]])
            ->assertCreated()->assertJsonPath('data.custom_fields.seats', 50);
    }

    public function test_notes_and_tasks_work_on_deals_contacts_and_accounts(): void
    {
        $admin = $this->organization();
        $deal = $this->as($admin)->postJson('/api/v1/deals', ['name' => 'D'])->json('data.id');

        $this->as($admin)->postJson("/api/v1/deals/{$deal}/notes", ['body' => 'Legal review next week'])->assertCreated();
        $this->as($admin)->getJson("/api/v1/deals/{$deal}/notes")->assertJsonCount(1, 'data');
        $this->as($admin)->postJson('/api/v1/tasks', ['title' => 'Send contract', 'taskable_type' => 'deal', 'taskable_id' => $deal])->assertCreated();
        $this->as($admin)->getJson("/api/v1/tasks?taskable_type=deal&taskable_id={$deal}&view=all")->assertJsonPath('total', 1);
    }

    public function test_duplicate_detection_ignores_phone_formatting(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'A', 'phone' => '+91 98765 43210'])->assertCreated();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'B', 'phone' => '9876543210'])->assertStatus(409);
    }
}
