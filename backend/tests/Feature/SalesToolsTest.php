<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\Quote;
use App\Notifications\AppNotification;
use App\Services\ConversionPredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SalesToolsTest extends TestCase
{
    use RefreshDatabase;

    private function sentMail(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all();
    }

    public function test_pipelines_have_their_own_stages_and_boards(): void
    {
        $admin = $this->organization();
        $default = $this->inTenant($admin, fn () => Pipeline::default());
        $this->assertSame(6, $this->inTenant($admin, fn () => $default->stages()->count()));

        $pid = $this->as($admin)->postJson('/api/v1/settings/pipelines', ['name' => 'Renewals'])->assertCreated()->assertJsonPath('data.stages_count', 5)->json('data.id');
        $stage = $this->inTenant($admin, fn () => PipelineStage::where('pipeline_id', $pid)->where('name', 'Proposal')->first());

        $deal = $this->as($admin)->postJson('/api/v1/deals', ['name' => 'Renewal', 'pipeline_stage_id' => $stage->id, 'amount' => 500])->assertCreated()->assertJsonPath('data.pipeline_id', $pid)->json('data.id');
        $defaultDeal = $this->as($admin)->postJson('/api/v1/deals', ['name' => 'New biz', 'amount' => 900])->assertCreated()->assertJsonPath('data.pipeline_id', $default->id)->json('data.id');

        $board = $this->as($admin)->getJson("/api/v1/deals/board?pipeline_id={$pid}")->assertOk()->assertJsonPath('pipeline_id', $pid);
        $this->assertSame(['Qualification', 'Proposal', 'Negotiation', 'Won', 'Lost'], array_column(array_column($board->json('data'), 'stage'), 'name'));
        $this->assertSame([$deal], collect($board->json('data'))->flatMap(fn ($c) => array_column($c['deals'], 'id'))->all());
        $this->assertContains($defaultDeal, collect($this->as($admin)->getJson('/api/v1/deals/board')->json('data'))->flatMap(fn ($c) => array_column($c['deals'], 'id'))->all());

        $this->as($admin)->postJson('/api/v1/settings/pipeline-stages', ['name' => 'Legal', 'pipeline_id' => $pid])->assertCreated()->assertJsonPath('data.display_order', 5);
        $this->as($admin)->deleteJson("/api/v1/settings/pipelines/{$pid}")->assertUnprocessable();
        $this->as($admin)->deleteJson("/api/v1/settings/pipelines/{$default->id}")->assertUnprocessable();
        $this->as($admin)->getJson('/api/v1/meta')->assertJsonCount(2, 'data.pipelines');
    }

    public function test_forecast_categories_follow_probability_until_overridden(): void
    {
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/deals', ['name' => 'D', 'amount' => 1000, 'probability' => 20])->json('data.id');
        $this->as($admin)->getJson("/api/v1/deals/{$id}")->assertJsonPath('data.forecast_category', 'pipeline');
        $this->as($admin)->patchJson("/api/v1/deals/{$id}", ['probability' => 75])->assertJsonPath('data.forecast_category', 'commit');
        $this->as($admin)->patchJson("/api/v1/deals/{$id}", ['forecast_category' => 'best_case'])->assertJsonPath('data.forecast_category', 'best_case')->assertJsonPath('data.forecast_override', true);
        $this->as($admin)->patchJson("/api/v1/deals/{$id}", ['probability' => 90])->assertJsonPath('data.forecast_category', 'best_case');
        $this->as($admin)->patchJson("/api/v1/deals/{$id}", ['forecast_category' => 'auto'])->assertJsonPath('data.forecast_category', 'commit')->assertJsonPath('data.forecast_override', false);

        $won = $this->inTenant($admin, fn () => PipelineStage::where('is_won', true)->first());
        $this->as($admin)->postJson("/api/v1/deals/{$id}/move", ['pipeline_stage_id' => $won->id])->assertJsonPath('data.forecast_category', 'closed');
        $this->as($admin)->patchJson("/api/v1/deals/{$id}", ['forecast_category' => 'nonsense'])->assertUnprocessable();
    }

    public function test_forecast_rolls_up_by_rep_against_quota(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $this->inTenant($admin, function () use ($rep, $admin) {
            Deal::create(['name' => 'Won', 'owner_id' => $rep->id, 'amount' => 3000, 'status' => 'won', 'closed_at' => now()]);
            Deal::create(['name' => 'Commit', 'owner_id' => $rep->id, 'amount' => 2000, 'probability' => 80, 'status' => 'open', 'expected_close_date' => now()->endOfQuarter()->subDay()]);
            Deal::create(['name' => 'Best', 'owner_id' => $admin->id, 'amount' => 1000, 'probability' => 50, 'status' => 'open', 'expected_close_date' => now()->addDay()]);
            Deal::create(['name' => 'Next year', 'owner_id' => $rep->id, 'amount' => 9999, 'probability' => 80, 'status' => 'open', 'expected_close_date' => now()->addYear()]);
        });
        $this->as($admin)->postJson('/api/v1/goals', ['metric' => 'revenue_won', 'period' => 'quarter', 'target' => 6000, 'user_id' => $rep->id]);

        $res = $this->as($admin)->getJson('/api/v1/deals/forecast?period=this_quarter')->assertOk()
            ->assertJsonPath('data.total.closed', 3000)
            ->assertJsonPath('data.total.commit', 2000)
            ->assertJsonPath('data.total.best_case', 1000)
            ->assertJsonPath('data.total.projected', 5000)
            ->assertJsonPath('data.total.deals', 3);
        $repRow = collect($res->json('data.reps'))->firstWhere('owner.id', $rep->id);
        $this->assertEquals(6000, $repRow['quota']);
        $this->assertEquals(50, $repRow['attainment']);

        $this->as($rep)->getJson('/api/v1/deals/forecast')->assertJsonPath('data.total.deals', 2)->assertJsonCount(1, 'data.reps');
    }

    public function test_quotes_calculate_send_and_get_signed_by_the_customer(): void
    {
        config(['mail.default' => 'array']);
        Notification::fake();
        $admin = $this->organization();
        $product = $this->inTenant($admin, fn () => Product::create(['name' => 'Seat', 'unit_price' => 100]));
        $deal = $this->as($admin)->postJson('/api/v1/deals', ['name' => 'Big deal', 'amount' => 1])->json('data.id');

        $id = $this->as($admin)->postJson("/api/v1/deals/{$deal}/quotes", [
            'discount_percent' => 10, 'tax_percent' => 20,
            'items' => [
                ['product_id' => $product->id, 'name' => 'Seat', 'quantity' => 10, 'unit_price' => 100, 'discount_percent' => 5],
                ['name' => 'Setup', 'quantity' => 1, 'unit_price' => 500],
            ],
        ])->assertCreated()->assertJsonPath('data.number', 'Q-'.now()->year.'-0001')->assertJsonPath('data.subtotal', 1450)->json('data.id');
        // (950 + 500) × 0.9 × 1.2
        $this->as($admin)->getJson("/api/v1/quotes/{$id}")->assertJsonPath('data.total', 1566);
        $token = $this->inTenant($admin, fn () => Quote::find($id)->public_token);

        $this->getJson("/api/v1/public/quotes/{$token}")->assertNotFound(); // drafts aren't public
        $this->as($admin)->postJson("/api/v1/quotes/{$id}/send")->assertUnprocessable(); // no contact email
        $this->as($admin)->postJson("/api/v1/quotes/{$id}/send", ['to' => 'buyer@client.test'])->assertOk()->assertJsonPath('data.status', 'sent');
        $this->assertStringContainsString("/q/{$token}", $this->sentMail()[0]->getOriginalMessage()->getTextBody());

        $this->getJson("/api/v1/public/quotes/{$token}")->assertOk()->assertJsonPath('data.total', 1566)->assertJsonCount(2, 'data.items')->assertJsonMissingPath('data.public_token');
        $this->assertNotNull($this->inTenant($admin, fn () => Quote::find($id)->viewed_at));

        $this->postJson("/api/v1/public/quotes/{$token}/respond", ['accept' => true])->assertUnprocessable();
        $this->postJson("/api/v1/public/quotes/{$token}/respond", ['accept' => true, 'name' => 'Jo Buyer', 'agree' => true])->assertOk()
            ->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.signed_name', 'Jo Buyer');
        $this->as($admin)->getJson("/api/v1/deals/{$deal}")->assertJsonPath('data.amount', '1566.00');
        Notification::assertSentTo($admin, AppNotification::class, fn ($n) => $n->kind === 'quote');

        $this->postJson("/api/v1/public/quotes/{$token}/respond", ['accept' => false])->assertUnprocessable(); // already answered
        $this->as($admin)->putJson("/api/v1/quotes/{$id}", ['title' => 'Changed'])->assertUnprocessable();
        $this->as($admin)->deleteJson("/api/v1/quotes/{$id}")->assertUnprocessable();
        $copy = $this->as($admin)->postJson("/api/v1/quotes/{$id}/duplicate")->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $this->assertSame(1566.0, (float) $copy['total']);
    }

    public function test_quotes_follow_deal_visibility_and_can_be_declined(): void
    {
        config(['mail.default' => 'array']);
        $admin = $this->organization();
        $rep = $this->member($admin);
        $deal = $this->as($admin)->postJson('/api/v1/deals', ['name' => 'Admin deal'])->json('data.id');
        $id = $this->as($admin)->postJson("/api/v1/deals/{$deal}/quotes", ['items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 10]]])->json('data.id');

        $this->as($rep)->getJson("/api/v1/deals/{$deal}/quotes")->assertNotFound();
        $this->as($rep)->getJson("/api/v1/quotes/{$id}")->assertNotFound();

        $this->as($admin)->postJson("/api/v1/quotes/{$id}/send", ['to' => 'x@y.test']);
        $token = $this->inTenant($admin, fn () => Quote::find($id)->public_token);
        $this->postJson("/api/v1/public/quotes/{$token}/respond", ['accept' => false, 'reason' => 'Too expensive'])->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertSame('Too expensive', $this->inTenant($admin, fn () => Quote::find($id)->decline_reason));

        // Expired quotes can't be accepted.
        $old = $this->as($admin)->postJson("/api/v1/deals/{$deal}/quotes", ['valid_until' => now()->subDay()->toDateString(), 'items' => [['name' => 'X', 'quantity' => 1, 'unit_price' => 10]]])->json('data.id');
        $this->inTenant($admin, fn () => Quote::whereKey($old)->update(['status' => 'sent']));
        $oldToken = $this->inTenant($admin, fn () => Quote::find($old)->public_token);
        $this->getJson("/api/v1/public/quotes/{$oldToken}")->assertJsonPath('data.status', 'expired');
        $this->postJson("/api/v1/public/quotes/{$oldToken}/respond", ['accept' => true, 'name' => 'Late', 'agree' => true])->assertUnprocessable();
    }

    public function test_conversion_prediction_learns_from_history(): void
    {
        $admin = $this->organization();
        $prediction = $this->inTenant($admin, function () {
            $referral = LeadSource::where('key', 'referral')->first();
            $cold = LeadSource::where('key', 'cold_call')->first();
            $converted = LeadStatus::where('category', 'converted')->first();
            $lost = LeadStatus::where('category', 'lost')->first();
            $predictor = app(ConversionPredictor::class);
            $this->assertNull($predictor->model()); // not enough history yet

            foreach (range(1, 12) as $i) {
                Lead::create(['first_name' => "R{$i}", 'lead_source_id' => $referral->id, 'lead_status_id' => $i <= 9 ? $converted->id : $lost->id, 'converted_at' => $i <= 9 ? now() : null]);
                Lead::create(['first_name' => "C{$i}", 'lead_source_id' => $cold->id, 'lead_status_id' => $i <= 2 ? $converted->id : $lost->id, 'converted_at' => $i <= 2 ? now() : null]);
            }
            $predictor->forget();
            $hot = Lead::create(['first_name' => 'New referral', 'lead_source_id' => $referral->id]);
            $coldLead = Lead::create(['first_name' => 'New cold', 'lead_source_id' => $cold->id]);

            $a = $predictor->predict($hot->fresh());
            $b = $predictor->predict($coldLead->fresh());
            $this->assertGreaterThan($b['likelihood'] + 30, $a['likelihood']);
            $this->assertSame(24, $a['trained_on']);
            $this->assertSame('Source: Referral', $a['factors'][0]['label']);
            $this->assertGreaterThan(0, $a['factors'][0]['effect']);

            $this->assertSame(2, $predictor->refresh());
            $this->assertSame($a['likelihood'], $hot->fresh()->conversion_likelihood);

            return $a;
        });

        $lead = $this->inTenant($admin, fn () => Lead::where('first_name', 'New referral')->first());
        $this->as($admin)->getJson("/api/v1/leads/{$lead->id}/insights")->assertOk()->assertJsonPath('data.prediction.likelihood', $prediction['likelihood']);
    }
}
