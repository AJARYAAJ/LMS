<?php

namespace Tests\Feature;

use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Task;
use App\Models\Team;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LeadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_lead_sets_default_status_scores_it_and_logs_history(): void
    {
        $admin = $this->organization();

        $response = $this->as($admin)->postJson('/api/v1/leads', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@analytical.io',
            'phone' => '+44 20 7946 0000',
            'company' => 'Analytical Engines',
            'budget' => 25000,
        ])->assertCreated();

        // business email 20 + phone 10 + company 10 + budget 15
        $response->assertJsonPath('data.status.key', 'new')
            ->assertJsonPath('data.score', 55)
            ->assertJsonPath('data.rating', 'warm')
            ->assertJsonPath('data.full_name', 'Ada Lovelace');

        $id = $response->json('data.id');
        $this->as($admin)->getJson("/api/v1/leads/{$id}/score")->assertOk()->assertJsonCount(4, 'data.events');
        $titles = $this->as($admin)->getJson("/api/v1/leads/{$id}/activities")->assertOk()->json('data.*.title');
        $this->assertContains('Lead created', $titles);
        $this->assertContains('Lead enriched', $titles); // website derived from the business email domain
        $this->as($admin)->getJson("/api/v1/leads/{$id}/history")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_duplicate_detection_blocks_until_confirmed(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'A', 'email' => 'dup@example.com'])->assertCreated();

        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'B', 'email' => 'DUP@example.com'])
            ->assertStatus(409)
            ->assertJsonCount(1, 'duplicates');

        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'B', 'email' => 'dup@example.com', 'allow_duplicate' => true])
            ->assertCreated();
    }

    public function test_round_robin_assignment_rotates_across_team_members(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $a = $this->member($admin);
        $b = $this->member($admin);

        $this->inTenant($admin, function () use ($a, $b) {
            $team = Team::create(['name' => 'Inbound']);
            $team->members()->sync([$a->id, $b->id]);
            AssignmentRule::create(['name' => 'RR', 'strategy' => 'round_robin', 'team_id' => $team->id]);
        });

        $owners = collect(range(1, 4))->map(fn ($i) => $this->as($admin)
            ->postJson('/api/v1/leads', ['first_name' => "Lead {$i}"])
            ->assertCreated()->json('data.owner_id'));

        $this->assertSame([$a->id, $b->id, $a->id, $b->id], $owners->all());
        Notification::assertSentTo($a, AppNotification::class);
    }

    public function test_assignment_rule_conditions_route_matching_leads_only(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $enterprise = $this->member($admin);

        $this->inTenant($admin, fn () => AssignmentRule::create([
            'name' => 'Big budgets',
            'strategy' => 'specific_user',
            'user_ids' => [$enterprise->id],
            'conditions' => [['field' => 'budget', 'operator' => 'greater_than', 'value' => '50000']],
        ]));

        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Small', 'budget' => 100])
            ->assertJsonPath('data.owner_id', null);
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Big', 'budget' => 90000])
            ->assertJsonPath('data.owner_id', $enterprise->id);
    }

    public function test_status_change_fires_default_automation(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $rep = $this->member($admin);

        $lead = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Grace', 'email' => 'grace@navy.mil', 'company' => 'Navy', 'owner_id' => $rep->id])->json('data');
        $qualified = $this->inTenant($admin, fn () => LeadStatus::where('key', 'qualified')->first());

        $this->as($admin)->postJson("/api/v1/leads/{$lead['id']}/status", ['lead_status_id' => $qualified->id, 'note' => 'Budget confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status.key', 'qualified')
            ->assertJsonPath('data.score', 50); // business email 20 + company 10 + qualified 20

        $task = $this->inTenant($admin, fn () => Task::first());
        $this->assertSame('Schedule discovery call with Grace', $task->title);
        $this->assertSame($rep->id, $task->assigned_to);
        Notification::assertSentTo($rep, AppNotification::class, fn ($n) => $n->title === 'Lead qualified');
    }

    public function test_conversion_creates_contact_account_and_deal(): void
    {
        $admin = $this->organization();
        $lead = $this->as($admin)->postJson('/api/v1/leads', [
            'first_name' => 'Linus', 'last_name' => 'T', 'company' => 'Kernel Ltd', 'expected_value' => 42000,
        ])->json('data');

        $this->as($admin)->postJson("/api/v1/leads/{$lead['id']}/convert")
            ->assertOk()
            ->assertJsonPath('data.status.key', 'converted')
            ->assertJsonPath('data.converted_account.name', 'Kernel Ltd')
            ->assertJsonPath('data.converted_deal.amount', '42000.00');

        $this->as($admin)->postJson("/api/v1/leads/{$lead['id']}/convert")->assertUnprocessable();
        $this->as($admin)->getJson('/api/v1/deals')->assertJsonPath('total', 1);
        $this->as($admin)->getJson('/api/v1/contacts')->assertJsonPath('data.0.first_name', 'Linus');
    }

    public function test_bulk_actions_and_filters(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $ids = collect(['One', 'Two', 'Three'])->map(fn ($n) => $this->as($admin)->postJson('/api/v1/leads', ['first_name' => $n])->json('data.id'));

        $this->as($admin)->postJson('/api/v1/leads/bulk', ['ids' => $ids->take(2)->all(), 'action' => 'assign', 'owner_id' => $rep->id])
            ->assertOk()->assertJsonPath('count', 2);
        $this->as($admin)->postJson('/api/v1/leads/bulk', ['ids' => $ids->all(), 'action' => 'priority', 'priority' => 'urgent'])->assertOk();

        $this->as($admin)->getJson("/api/v1/leads?owner_id={$rep->id}")->assertJsonPath('total', 2);
        $this->as($admin)->getJson('/api/v1/leads?owner_id=unassigned')->assertJsonPath('total', 1);
        $this->as($admin)->getJson('/api/v1/leads?priority=urgent&search=thr')->assertJsonPath('total', 1);
        $this->as($admin)->getJson('/api/v1/leads/board')->assertOk()->assertJsonPath('data.0.total', 3);
    }

    public function test_activity_logging_updates_contact_dates(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Call me'])->json('data.id');
        $followUp = now()->addDays(2)->startOfMinute();

        $this->as($admin)->postJson("/api/v1/leads/{$id}/activities", [
            'type' => 'call', 'title' => 'Intro call', 'outcome' => 'Connected', 'duration_minutes' => 15,
            'next_follow_up_at' => $followUp->toIso8601String(),
        ])->assertCreated();

        $lead = $this->inTenant($admin, fn () => Lead::find($id));
        $this->assertNotNull($lead->last_contacted_at);
        $this->assertTrue($lead->next_follow_up_at->equalTo($followUp));
    }

    public function test_dashboard_and_reports_respond(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Metrics']);

        $this->as($admin)->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.kpis.total_leads', 1);
        $this->as($admin)->getJson('/api/v1/reports/leads')->assertOk()->assertJsonPath('data.funnel.0.count', 1);
        $this->as($admin)->getJson('/api/v1/search?q=metr')->assertOk()->assertJsonPath('data.0.type', 'lead');
    }
}
