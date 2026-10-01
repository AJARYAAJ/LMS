<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\Lead;
use App\Notifications\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InsightsPlusTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_english_questions_become_reports_without_ai(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ada', 'industry' => 'Fintech']);

        $res = $this->as($admin)->postJson('/api/v1/reports/ask', ['question' => 'How many leads by industry this quarter?'])->assertOk()
            ->assertJsonPath('data.interpreter', 'rules')
            ->assertJsonPath('data.result.spec.entity', 'leads')
            ->assertJsonPath('data.result.spec.dimension', 'industry')
            ->assertJsonPath('data.result.spec.range', 'this_quarter')
            ->assertJsonPath('data.result.rows.0.label', 'Fintech');
        $this->assertSame(1.0, (float) $res->json('data.result.total'));

        $this->as($admin)->postJson('/api/v1/reports/ask', ['question' => 'won revenue by rep this year'])
            ->assertJsonPath('data.result.spec.entity', 'deals')
            ->assertJsonPath('data.result.spec.metric', 'won_amount')
            ->assertJsonPath('data.result.spec.dimension', 'owner')
            ->assertJsonPath('data.result.spec.date_field', 'closed');
        $this->as($admin)->postJson('/api/v1/reports/ask', ['question' => 'why are deals lost'])
            ->assertJsonPath('data.result.spec.dimension', 'lost_reason')
            ->assertJsonPath('data.result.spec.filters.status.0', 'lost');
        $this->as($admin)->postJson('/api/v1/reports/ask', ['question' => 'x'])->assertUnprocessable();
    }

    public function test_dashboards_validate_tiles_and_respect_sharing(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $mine = $this->as($admin)->postJson('/api/v1/saved-reports', ['name' => 'Private', 'spec' => ['entity' => 'leads', 'metric' => 'count']])->json('data.id');

        $id = $this->as($admin)->postJson('/api/v1/dashboards', ['name' => 'Board', 'tiles' => [
            ['kind' => 'kpis', 'type' => 'pipeline', 'span' => 2],
            ['kind' => 'report', 'report_id' => $mine],
            ['kind' => 'spec', 'spec' => ['entity' => 'deals', 'metric' => 'won_amount', 'dimension' => 'owner']],
            ['kind' => 'goals'],
        ]])->assertCreated()->assertJsonCount(4, 'data.tiles')->assertJsonPath('data.tiles.2.spec.chart', 'bar')->json('data.id');

        $this->as($admin)->postJson('/api/v1/dashboards', ['name' => 'Bad', 'tiles' => [['kind' => 'spec', 'spec' => ['entity' => 'payroll']]]])->assertUnprocessable();
        $this->as($admin)->postJson('/api/v1/dashboards', ['name' => 'Bad', 'tiles' => [['kind' => 'kpis', 'type' => 'secrets']]])->assertUnprocessable();

        // A rep can't put someone else's private report on their dashboard, or see a private dashboard.
        $this->as($rep)->postJson('/api/v1/dashboards', ['name' => 'Rep', 'tiles' => [['kind' => 'report', 'report_id' => $mine]]])->assertUnprocessable();
        $this->as($rep)->getJson("/api/v1/dashboards/{$id}")->assertNotFound();
        $this->as($admin)->patchJson("/api/v1/dashboards/{$id}", ['is_shared' => true])->assertOk();
        $this->as($rep)->getJson('/api/v1/dashboards')->assertJsonCount(1, 'data');
        $this->as($rep)->patchJson("/api/v1/dashboards/{$id}", ['name' => 'Mine'])->assertForbidden();
        $this->as($admin)->deleteJson("/api/v1/dashboards/{$id}")->assertNoContent();
    }

    public function test_first_response_is_tracked_and_reported(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Fast'])->json('data.id');
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Waiting']);

        // Inbound messages and notes don't count as a response.
        $this->inTenant($admin, function () use ($id) {
            $lead = Lead::find($id);
            Activity::create(['subject_type' => 'lead', 'subject_id' => $id, 'type' => 'sms', 'title' => 'Reply', 'direction' => 'inbound', 'occurred_at' => $lead->created_at->copy()->addMinutes(10)]);
            Activity::create(['subject_type' => 'lead', 'subject_id' => $id, 'type' => 'note', 'title' => 'Note', 'occurred_at' => $lead->created_at->copy()->addMinutes(20)]);
            $this->assertNull($lead->fresh()->first_responded_at);
            Activity::create(['subject_type' => 'lead', 'subject_id' => $id, 'type' => 'call', 'title' => 'Call', 'direction' => 'outbound', 'occurred_at' => $lead->created_at->copy()->addMinutes(30)]);
            $this->assertEquals($lead->created_at->copy()->addMinutes(30)->toDateTimeString(), $lead->fresh()->first_responded_at->toDateTimeString());
        });

        $run = fn (string $metric) => (float) $this->as($admin)->postJson('/api/v1/reports/run', ['spec' => ['entity' => 'leads', 'metric' => $metric]])->json('data.total');
        $this->assertSame(0.5, $run('response_hours'));
        $this->assertSame(50.0, $run('responded_rate'));
        $this->assertSame(50.0, $run('within_sla_rate')); // default target 1 hour
        $this->as($admin)->patchJson('/api/v1/settings/organization', ['settings' => ['response_sla_hours' => 0.25]])->assertOk();
        $this->assertSame(0.0, $run('within_sla_rate'));
    }

    public function test_sla_alerts_owner_and_managers_once(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $rep = $this->member($admin);
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Slow', 'owner_id' => $rep->id])->json('data.id');
        $this->inTenant($admin, fn () => Lead::whereKey($id)->update(['created_at' => now()->subHours(2)]));

        $this->artisan('leads:sla')->expectsOutput('Raised 1 speed-to-lead alerts.');
        $this->artisan('leads:sla')->expectsOutput('Raised 0 speed-to-lead alerts.');
        Notification::assertSentTo($rep, AppNotification::class, fn ($n) => $n->kind === 'sla' && str_contains($n->title, 'Slow'));
        Notification::assertSentTo($admin, AppNotification::class, fn ($n) => $n->kind === 'sla');
    }

    public function test_saved_report_can_post_to_slack(): void
    {
        config(['mail.default' => 'array']);
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $admin = $this->organization();
        $this->inTenant($admin, fn () => Integration::create(['category' => 'chat', 'provider' => 'slack', 'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X'], 'is_active' => true, 'status' => 'connected']));
        $id = $this->as($admin)->postJson('/api/v1/saved-reports', ['name' => 'To Slack', 'spec' => ['entity' => 'leads', 'metric' => 'count'], 'post_to_chat' => true])->json('data.id');

        $this->as($admin)->postJson("/api/v1/saved-reports/{$id}/send")->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'hooks.slack.com') && str_contains($r['text'], 'Report: To Slack'));
    }
}
