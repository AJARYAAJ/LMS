<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\SavedReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsAndGoalsTest extends TestCase
{
    use RefreshDatabase;

    private function sentMail(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all();
    }

    public function test_catalog_lists_entities_groupings_and_measures(): void
    {
        $admin = $this->organization();

        $this->as($admin)->getJson('/api/v1/reports/catalog')->assertOk()
            ->assertJsonPath('data.entities.0.key', 'leads')
            ->assertJsonFragment(['key' => 'won_amount', 'label' => 'Won revenue', 'format' => 'money'])
            ->assertJsonFragment(['key' => 'revenue_won'])
            ->assertJsonCount(6, 'data.types');
    }

    public function test_run_groups_by_source_within_the_tenant_and_respects_rep_visibility(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $source = $this->inTenant($admin, fn () => LeadSource::first());
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'A', 'lead_source_id' => $source->id, 'owner_id' => $rep->id]);
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'B', 'lead_source_id' => $source->id]);
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'C']);

        $other = $this->organization('Globex');
        $this->as($other)->postJson('/api/v1/leads', ['first_name' => 'Elsewhere']);

        $spec = ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'source', 'range' => 'last_7'];
        $res = $this->as($admin)->postJson('/api/v1/reports/run', ['spec' => $spec])->assertOk();
        $this->assertSame(3.0, (float) $res->json('data.total'));
        $this->assertSame($source->name, $res->json('data.rows.0.label'));
        $this->assertSame(2.0, (float) $res->json('data.rows.0.value'));
        $this->assertSame('No source', $res->json('data.rows.1.label'));

        $this->assertSame(1.0, (float) $this->as($rep)->postJson('/api/v1/reports/run', ['spec' => $spec])->json('data.total'));
    }

    public function test_dates_are_bucketed_and_filled_and_tails_fold_into_other(): void
    {
        $admin = $this->organization();
        foreach (['Fintech', 'Retail', 'Health', 'Energy'] as $i => $industry) {
            foreach (range(0, $i) as $n) {
                $this->as($admin)->postJson('/api/v1/leads', ['first_name' => "{$industry}{$n}", 'industry' => $industry]);
            }
        }

        $daily = $this->as($admin)->postJson('/api/v1/reports/run', ['spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'created', 'range' => 'last_7']])->assertOk();
        $this->assertCount(7, $daily->json('data.rows'));
        $this->assertSame('day', $daily->json('data.granularity'));
        $this->assertSame(10.0, (float) collect($daily->json('data.rows'))->last()['value']);

        $monthly = $this->as($admin)->postJson('/api/v1/reports/run', ['spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'created', 'range' => 'last_12_months']]);
        $this->assertSame('month', $monthly->json('data.granularity'));
        $this->assertCount(12, $monthly->json('data.rows'));

        $top = $this->as($admin)->postJson('/api/v1/reports/run', ['spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'industry', 'limit' => 3]]);
        $this->assertSame(['Energy', 'Health', 'Other'], array_column($top->json('data.rows'), 'label'));
        $this->assertSame(3.0, (float) $top->json('data.rows.2.value')); // Retail 2 + Fintech 1
    }

    public function test_split_series_and_rates_stay_correct(): void
    {
        $admin = $this->organization();
        $ids = [];
        foreach (['Fintech', 'Fintech', 'Retail', 'Retail'] as $i => $industry) {
            $ids[] = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => "L{$i}", 'industry' => $industry, 'priority' => $i % 2 ? 'high' : 'low'])->json('data.id');
        }
        $this->inTenant($admin, fn () => Lead::whereKey($ids[0])->update(['converted_at' => now()]));

        $res = $this->as($admin)->postJson('/api/v1/reports/run', ['spec' => ['entity' => 'leads', 'metric' => 'conversion_rate', 'dimension' => 'industry', 'split' => 'priority']])->assertOk();
        $this->assertSame(25.0, (float) $res->json('data.total'));
        $hot = collect($res->json('data.rows'))->firstWhere('key', 'Fintech');
        $this->assertSame(50.0, (float) $hot['value']);
        $this->assertSame(100.0, (float) $hot['values']['low']);
        $this->assertSame(['low', 'high'], array_column($res->json('data.series'), 'key'));
    }

    public function test_invalid_specs_are_rejected(): void
    {
        $admin = $this->organization();
        $run = fn (array $spec) => $this->as($admin)->postJson('/api/v1/reports/run', ['spec' => $spec]);

        $run(['entity' => 'users'])->assertUnprocessable()->assertJsonValidationErrors('spec.entity');
        $run(['entity' => 'leads', 'dimension' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('spec.dimension');
        $run(['entity' => 'leads', 'metric' => 'sum(1); drop table leads'])->assertUnprocessable();
        $run(['entity' => 'leads', 'dimension' => 'source', 'split' => 'created'])->assertUnprocessable()->assertJsonValidationErrors('spec.split');
        $run(['entity' => 'tasks', 'filters' => ['state' => ['done']]])->assertUnprocessable()->assertJsonValidationErrors('spec.filters');
    }

    public function test_every_type_report_builds(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ada', 'phone' => '+1 555 0100']);

        foreach (['leads', 'pipeline', 'activities', 'tasks', 'calls', 'messaging'] as $type) {
            $this->as($admin)->getJson("/api/v1/reports/type/{$type}?range=this_month")->assertOk()
                ->assertJsonPath('data.type', $type)
                ->assertJsonStructure(['data' => ['kpis' => [['label', 'value', 'delta', 'format']], 'widgets' => [['title', 'result' => ['rows', 'total', 'format']]]]]);
        }
        $this->as($admin)->getJson('/api/v1/reports/type/payroll')->assertNotFound();
        $this->as($admin)->getJson('/api/v1/reports/type/leads?range=this_month')->assertJsonPath('data.kpis.0.value', 1);
    }

    public function test_saved_reports_share_pin_edit_rights_and_email(): void
    {
        config(['mail.default' => 'array']);
        $admin = $this->organization();
        $manager = $this->member($admin, User::MANAGER);
        $rep = $this->member($admin);

        $id = $this->as($manager)->postJson('/api/v1/saved-reports', [
            'name' => 'Won by owner', 'spec' => ['entity' => 'deals', 'metric' => 'won_amount', 'dimension' => 'owner', 'bogus' => 1],
            'pinned' => true, 'recipients' => ['boss@example.test'],
        ])->assertCreated()->assertJsonPath('data.spec.chart', 'bar')->assertJsonMissingPath('data.spec.bogus')->json('data.id');
        $this->as($manager)->postJson('/api/v1/saved-reports', ['name' => 'Bad', 'spec' => ['entity' => 'leads', 'metric' => 'nope']])->assertUnprocessable();

        $this->as($rep)->getJson('/api/v1/saved-reports')->assertJsonCount(0, 'data');
        $this->as($rep)->getJson("/api/v1/saved-reports/{$id}/run")->assertNotFound();
        $this->as($manager)->patchJson("/api/v1/saved-reports/{$id}", ['is_shared' => true])->assertOk();
        $this->as($rep)->getJson('/api/v1/saved-reports')->assertJsonCount(1, 'data');
        $this->as($rep)->getJson("/api/v1/saved-reports/{$id}/run?range=last_7")->assertOk()->assertJsonPath('data.range.preset', 'last_7');
        $this->as($rep)->patchJson("/api/v1/saved-reports/{$id}", ['name' => 'Mine now'])->assertForbidden();
        $this->as($manager)->getJson('/api/v1/saved-reports?pinned=1')->assertJsonCount(1, 'data');

        $this->as($manager)->postJson("/api/v1/saved-reports/{$id}/send")->assertOk()->assertJsonPath('message', 'Report emailed to 1 person.');
        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        $this->assertStringContainsString('Report: Won by owner', $mail[0]->getOriginalMessage()->getSubject());
        $this->assertStringContainsString('TOTAL:', $mail[0]->getOriginalMessage()->getTextBody());

        $this->as($admin)->deleteJson("/api/v1/saved-reports/{$id}")->assertNoContent();
    }

    public function test_scheduled_reports_go_out_once_on_their_day(): void
    {
        config(['mail.default' => 'array']);
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/saved-reports', ['name' => 'Weekly leads', 'spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'source'], 'schedule' => 'weekly']);
        $this->as($admin)->postJson('/api/v1/saved-reports', ['name' => 'Monthly deals', 'spec' => ['entity' => 'deals', 'metric' => 'count'], 'schedule' => 'monthly']);

        Carbon::setTestNow(Carbon::parse('next tuesday 07:00'));
        $this->artisan('reports:send-scheduled')->expectsOutput('Sent 0 scheduled reports.');

        Carbon::setTestNow(Carbon::parse('next monday 07:00'));
        $this->artisan('reports:send-scheduled')->expectsOutput('Sent 1 scheduled reports.');
        $this->artisan('reports:send-scheduled')->expectsOutput('Sent 0 scheduled reports.');
        $this->assertSame($admin->email, $this->sentMail()[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertNotNull($this->inTenant($admin, fn () => SavedReport::where('name', 'Weekly leads')->value('last_sent_at')));
        Carbon::setTestNow();
    }

    public function test_goals_track_progress_and_only_managers_set_them(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $other = $this->member($admin);

        $this->as($rep)->postJson('/api/v1/goals', ['metric' => 'revenue_won', 'target' => 1000])->assertForbidden();
        $this->as($admin)->postJson('/api/v1/goals', ['metric' => 'payroll', 'target' => 5])->assertUnprocessable();

        $this->as($admin)->postJson('/api/v1/goals', ['metric' => 'revenue_won', 'target' => 10000])->assertCreated()->assertJsonPath('data.user', null);
        $mine = $this->as($admin)->postJson('/api/v1/goals', ['metric' => 'revenue_won', 'target' => 4000, 'user_id' => $rep->id])->json('data.id');
        $this->as($admin)->postJson('/api/v1/goals', ['metric' => 'calls_logged', 'target' => 5, 'user_id' => $other->id, 'period' => 'quarter'])->assertCreated();

        $this->inTenant($admin, function () use ($rep, $other) {
            Deal::create(['name' => 'Big', 'owner_id' => $rep->id, 'amount' => 5000, 'status' => 'won', 'closed_at' => now()]);
            Deal::create(['name' => 'Other', 'owner_id' => $other->id, 'amount' => 2000, 'status' => 'won', 'closed_at' => now()]);
            Deal::create(['name' => 'Old', 'owner_id' => $rep->id, 'amount' => 9000, 'status' => 'won', 'closed_at' => now()->subMonths(4)]);
        });

        // The rep sees the team goal and their own, measured across everyone they can't see too.
        $goals = collect($this->as($rep)->getJson('/api/v1/goals')->assertOk()->json('data'));
        $this->assertCount(2, $goals);
        $team = $goals->firstWhere('user', null);
        $this->assertSame(7000.0, (float) $team['actual']);
        $this->assertSame(70.0, (float) $team['percent']);
        $own = $goals->firstWhere('id', $mine);
        $this->assertSame(5000.0, (float) $own['actual']);
        $this->assertSame('achieved', $own['status']);

        $this->assertCount(3, $this->as($admin)->getJson('/api/v1/goals')->json('data'));
        $this->as($admin)->patchJson("/api/v1/goals/{$mine}", ['target' => 20000])->assertOk()->assertJsonPath('data.percent', 25);
        $this->as($admin)->deleteJson("/api/v1/goals/{$mine}")->assertNoContent();
    }
}
