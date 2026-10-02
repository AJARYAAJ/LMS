<?php

namespace Tests\Feature;

use App\Models\AssignmentRule;
use App\Models\Deal;
use App\Models\Goal;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every feature lands in the bell (and pushes) for the right people, never for whoever did it. */
class FeatureNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function titles(User $user, ?string $kind = null): array
    {
        return $user->fresh()->notifications()->get()
            ->filter(fn ($n) => ! $kind || $n->data['kind'] === $kind)->map(fn ($n) => $n->data['title'])->values()->all();
    }

    public function test_deals_won_and_reassigned_notify_owner_and_managers_but_not_the_actor(): void
    {
        $admin = $this->organization();
        $manager = $this->member($admin, User::MANAGER);
        $rep = $this->member($admin, User::SALES_REP, ['name' => 'Riley Rep']);
        $deal = $this->inTenant($admin, fn () => Deal::create(['name' => 'Globex rollout', 'amount' => 12000, 'owner_id' => $admin->id, 'status' => 'open']));

        $this->as($admin)->putJson("/api/v1/deals/{$deal->id}", ['owner_id' => $rep->id])->assertOk();
        $this->assertContains('Deal assigned to you', $this->titles($rep, 'deal'));

        $this->actingAs($rep);
        $this->inTenant($admin, fn () => $deal->fresh()->update(['status' => 'won']));
        $this->assertContains('Deal won: Globex rollout', $this->titles($manager, 'deal'));
        $this->assertContains('Deal won: Globex rollout', $this->titles($admin, 'deal'));
        $this->assertNotContains('Deal won: Globex rollout', $this->titles($rep, 'deal')); // they did it
    }

    public function test_tasks_mentions_notes_and_logged_calls_reach_teammates(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin, User::SALES_REP, ['name' => 'Riley Rep']);
        $lead = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Dana', 'owner_id' => $rep->id])->json('data.id');
        $this->assertContains('New lead assigned', $this->titles($rep, 'assignment'));

        $this->as($admin)->postJson('/api/v1/tasks', ['title' => 'Send pricing', 'assigned_to' => $rep->id, 'taskable_type' => 'lead', 'taskable_id' => $lead])->assertCreated();
        $this->assertContains("{$admin->name} gave you a task", $this->titles($rep, 'task'));
        $this->as($rep)->postJson('/api/v1/tasks', ['title' => 'My own', 'assigned_to' => $rep->id])->assertCreated();
        $this->assertCount(1, $this->titles($rep, 'task'));

        $this->as($admin)->postJson("/api/v1/leads/{$lead}/notes", ['body' => 'Hey @Riley can you call her today?'])->assertCreated();
        $this->assertContains("{$admin->name} mentioned you", $this->titles($rep, 'mention'));
        $this->assertEmpty($this->titles($rep, 'activity')); // a mention isn't also a "note added"

        $this->as($admin)->postJson("/api/v1/leads/{$lead}/notes", ['body' => 'Budget confirmed.'])->assertCreated();
        $this->assertContains("{$admin->name} added a note", $this->titles($rep, 'activity'));

        $this->as($admin)->postJson("/api/v1/leads/{$lead}/activities", ['type' => 'call', 'title' => 'Discovery call', 'outcome' => 'Interested'])->assertCreated();
        $this->assertContains("{$admin->name} logged a call", $this->titles($rep, 'activity'));
    }

    public function test_lead_updates_and_unowned_leads(): void
    {
        $admin = $this->organization();
        $manager = $this->member($admin, User::MANAGER);
        $rep = $this->member($admin, User::SALES_REP);
        $leadId = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ola', 'email' => 'ola@example.org', 'owner_id' => $rep->id])->json('data.id');

        // Opt-out by the person themselves (no actor) reaches the owner.
        $this->inTenant($admin, fn () => Lead::find($leadId)->setConsent('email', 'denied', 'unsubscribe_link'));
        $this->assertContains('Ola opted out of email', $this->titles($rep, 'lead_update'));

        $this->as($admin)->postJson("/api/v1/leads/{$leadId}/convert", ['create_deal' => false])->assertOk();
        $this->assertContains('Ola was converted', $this->titles($rep, 'lead_update'));

        // A web-form lead nobody can own goes to the managers.
        $this->inTenant($admin, fn () => AssignmentRule::query()->update(['is_active' => false]));
        $slug = $this->inTenant($admin, fn () => WebForm::first()->slug);
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/forms/{$slug}", ['name' => 'Nina Nobody', 'email' => 'nina@example.org'])->assertCreated();
        $this->assertContains('New lead waiting for an owner', $this->titles($manager, 'new_lead'));
        $this->assertEmpty($this->titles($rep, 'new_lead'));
    }

    public function test_security_alerts_for_new_devices_and_two_step_changes(): void
    {
        $admin = $this->organization();
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/130')->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/130')->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->assertEmpty($this->titles($admin, 'security')); // first device and repeat sign-ins are quiet
        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18) Safari/604')->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk();
        $this->assertSame(['New sign-in to your account'], $this->titles($admin, 'security'));
        $this->assertStringContainsString('Safari on iPhone', $admin->fresh()->notifications()->first()->data['body']);
    }

    public function test_reached_goals_are_celebrated_once_per_period(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $this->inTenant($admin, fn () => Goal::create(['metric' => 'leads_created', 'period' => 'month', 'target' => 1, 'created_by' => $admin->id]));
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Gina']);

        $this->artisan('goals:check')->assertSuccessful();
        $this->artisan('goals:check')->assertSuccessful();
        $this->assertCount(1, $this->titles($rep, 'goal'));
        $this->assertCount(1, $this->titles($admin, 'goal'));
    }
}
