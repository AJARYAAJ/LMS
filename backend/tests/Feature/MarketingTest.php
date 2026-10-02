<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Touchpoint;
use App\Models\WebForm;
use App\Services\BroadcastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    private int $made = 0;

    private function leads($admin, int $n, array $extra = []): array
    {
        return collect(range(1, $n))->map(fn () => $this->as($admin)->postJson('/api/v1/leads', [
            'first_name' => 'Person'.(++$this->made), 'email' => "p{$this->made}@example.org", ...$extra,
        ])->assertCreated()->json('data.id'))->all();
    }

    public function test_email_campaign_sends_tracked_mail_and_skips_opted_out_people(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $ids = $this->leads($admin, 3, ['industry' => 'Retail']);
        $this->leads($admin, 1, ['industry' => 'Banking']);
        $this->inTenant($admin, fn () => Lead::find($ids[2])->setConsent('email', 'denied', 'test'));
        $conditions = [['field' => 'industry', 'operator' => 'equals', 'value' => 'retail']];

        $this->as($admin)->postJson('/api/v1/broadcasts/audience', ['conditions' => $conditions])->assertOk()->assertJsonPath('data.count', 2);
        $this->as($rep)->getJson('/api/v1/broadcasts')->assertForbidden();

        $id = $this->as($admin)->postJson('/api/v1/broadcasts', [
            'name' => 'Spring offer', 'conditions' => $conditions,
            'variants' => [['subject' => 'Hi {first_name}', 'body' => "Our spring offer is live: https://example.org/offer?x=1\nThanks"]],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->as($admin)->postJson("/api/v1/broadcasts/{$id}/launch")->assertOk();
        $this->as($admin)->getJson("/api/v1/broadcasts/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'sent')->assertJsonPath('data.stats.sent', 2)->assertJsonPath('data.stats.recipients', 2);
        $this->as($admin)->postJson("/api/v1/broadcasts/{$id}/launch")->assertStatus(422);

        // The email went out on the lead's timeline, and tracking works.
        $this->as($admin)->getJson("/api/v1/leads/{$ids[0]}/activities?type=email")->assertJsonPath('data.0.title', 'Hi Person1');
        $r = BroadcastRecipient::where('lead_id', $ids[0])->first();
        $this->get("/api/v1/t/o/{$r->token}.gif")->assertOk()->assertHeader('Content-Type', 'image/gif');
        $url = 'https://example.org/offer?x=1';
        $sig = BroadcastService::linkSignature($r->token, $url);
        $this->get("/api/v1/t/c/{$r->token}/{$sig}?u=".rawurlencode($url))->assertRedirect($url);
        $this->get("/api/v1/t/c/{$r->token}/{$sig}?u=".rawurlencode('https://evil.test'))->assertNotFound();

        $stats = $this->as($admin)->getJson("/api/v1/broadcasts/{$id}")->json('data.stats');
        $this->assertSame(1, $stats['opened']);
        $this->assertSame(1, $stats['clicked']);
        $this->assertEquals(50, $stats['click_rate']);
        $this->assertTrue($this->inTenant($admin, fn () => Touchpoint::where('lead_id', $ids[0])->where('channel', 'email_click')->exists()));
        $this->as($admin)->getJson("/api/v1/broadcasts/{$id}/recipients?filter=clicked")->assertJsonCount(1, 'data');
        $this->as($admin)->getJson('/api/v1/broadcasts')->assertJsonPath('data.0.summary.sent', 2);
        // The sender hears it went out.
        $this->as($admin)->getJson('/api/v1/notifications')->assertJsonFragment(['title' => '“Spring offer” was sent', 'kind' => 'campaign']);
    }

    public function test_ab_test_holds_back_audience_and_sends_the_winner(): void
    {
        $admin = $this->organization();
        $this->leads($admin, 10);
        $id = $this->as($admin)->postJson('/api/v1/broadcasts', [
            'name' => 'Subject test', 'test_percent' => 40, 'winner_metric' => 'open', 'winner_after_hours' => 2,
            'variants' => [['subject' => 'Plain subject', 'body' => 'Hello'], ['subject' => 'Curious subject?', 'body' => 'Hello']],
        ])->json('data.id');

        $this->as($admin)->postJson("/api/v1/broadcasts/{$id}/launch")->assertOk()->assertJsonPath('data.status', 'testing');
        $stats = $this->as($admin)->getJson("/api/v1/broadcasts/{$id}")->json('data.stats');
        $this->assertSame(4, $stats['sent']);
        $this->assertSame(6, $stats['held']);

        // Variant B gets the opens.
        BroadcastRecipient::where('broadcast_id', $id)->where('variant', 'B')->get()->each(fn ($r) => $this->get("/api/v1/t/o/{$r->token}.gif"));

        $this->travel(3)->hours();
        Artisan::call('broadcasts:run');
        $b = $this->as($admin)->getJson("/api/v1/broadcasts/{$id}")->json('data');
        $this->assertSame('B', $b['winner_key']);
        $this->assertSame('sent', $b['status']);
        $this->assertSame(8, collect($b['stats']['variants'])->firstWhere('key', 'B')['sent']);
    }

    public function test_scheduled_campaign_launches_on_time_and_can_be_canceled(): void
    {
        $admin = $this->organization();
        $this->leads($admin, 2);
        $id = $this->as($admin)->postJson('/api/v1/broadcasts', ['name' => 'Later', 'variants' => [['subject' => 'S', 'body' => 'B']]])->json('data.id');
        $this->as($admin)->postJson("/api/v1/broadcasts/{$id}/launch", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertJsonPath('data.status', 'scheduled');
        Artisan::call('broadcasts:run');
        $this->assertSame('scheduled', Broadcast::withoutGlobalScopes()->find($id)->status);
        $this->travel(2)->hours();
        Artisan::call('broadcasts:run');
        $this->assertSame('sent', Broadcast::withoutGlobalScopes()->find($id)->status);

        $other = $this->as($admin)->postJson('/api/v1/broadcasts', ['name' => 'Nope', 'variants' => [['subject' => 'S', 'body' => 'B']]])->json('data.id');
        $this->as($admin)->postJson("/api/v1/broadcasts/{$other}/launch", ['scheduled_at' => now()->addDay()->toIso8601String()]);
        $this->as($admin)->postJson("/api/v1/broadcasts/{$other}/cancel")->assertJsonPath('data.status', 'draft');
        $this->as($admin)->deleteJson("/api/v1/broadcasts/{$other}")->assertNoContent();
    }

    public function test_attribution_credits_first_last_and_linear_touches(): void
    {
        $admin = $this->organization();
        [$webinar, $ads] = $this->inTenant($admin, fn () => [Campaign::create(['name' => 'Webinar']), Campaign::create(['name' => 'Ads'])]);
        $a = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ann', 'campaign_id' => $webinar->id])->json('data.id');
        $b = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Bo', 'campaign_id' => $ads->id])->json('data.id');
        $this->inTenant($admin, function () use ($a, $ads) {
            $lead = Lead::find($a);
            $this->travel(1)->minutes();
            Touchpoint::record($lead, 'email_click', $ads->id);
            $lead->forceFill(['converted_at' => now()->addMinute()])->save();
            Deal::create(['name' => 'Ann deal', 'lead_id' => $lead->id, 'amount' => 1000, 'status' => 'won']);
        });

        $rows = collect($this->as($admin)->getJson('/api/v1/reports/attribution?by=campaign')->assertOk()->assertJsonPath('data.totals.leads', 2)->json('data.rows'))->keyBy('label');
        $this->assertEquals(1, $rows['Webinar']['conversions']['first']);
        $this->assertEquals(0, $rows['Webinar']['conversions']['last']);
        $this->assertEquals(500, $rows['Webinar']['revenue']['linear']);
        $this->assertEquals(1000, $rows['Ads']['revenue']['last']);
        $this->assertEquals(1.5, $rows['Ads']['leads']['linear']);

        $channels = collect($this->as($admin)->getJson('/api/v1/reports/attribution?by=channel')->json('data.rows'))->pluck('label');
        $this->assertContains('Email click', $channels);
    }

    public function test_web_form_and_booking_touches_are_recorded(): void
    {
        $admin = $this->organization();
        $slug = $this->inTenant($admin, fn () => WebForm::first()->slug);
        $this->postJson("/api/v1/forms/{$slug}", ['name' => 'Fern Gully', 'email' => 'fern@example.org'])->assertCreated();
        $this->assertTrue($this->inTenant($admin, fn () => Touchpoint::where('channel', 'web_form')->exists()));
    }

    public function test_ai_receptionist_answers_and_creates_or_matches_the_caller(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $agent = $this->inTenant($admin, fn () => AiAgent::where('mode', 'inbound')->firstOrFail());
        $outbound = $this->inTenant($admin, fn () => AiAgent::where('mode', 'outbound')->firstOrFail());

        // A new caller becomes a lead, named from the conversation.
        $call = $this->as($admin)->postJson("/api/v1/ai-agents/{$agent->id}/simulate-inbound", ['phone' => '+1 555 777 1234'])->assertCreated()->json('data');
        $this->assertSame('inbound', $call['direction']);
        $done = $this->as($admin)->getJson("/api/v1/calls/{$call['id']}")->assertOk()->json('data');
        $this->assertSame('completed', $done['status']);
        $lead = $this->inTenant($admin, fn () => Lead::find($call['lead_id']));
        $this->assertNotSame('Caller', $lead->first_name);
        $this->as($admin)->getJson("/api/v1/leads/{$lead->id}/activities?type=call")->assertJsonPath('data.0.direction', 'inbound');

        // A known number is matched to the existing lead.
        $known = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Kim', 'phone' => '+1 555 222 3333'])->json('data.id');
        $this->as($admin)->postJson("/api/v1/ai-agents/{$agent->id}/simulate-inbound", ['phone' => '5552223333'])->assertJsonPath('data.lead_id', $known);
        $this->as($admin)->getJson('/api/v1/calls?direction=inbound')->assertJsonCount(2, 'data');

        // Receptionists don't dial out; outbound agents don't answer.
        $this->as($admin)->postJson("/api/v1/leads/{$known}/calls", ['ai_agent_id' => $agent->id])->assertStatus(422);
        $this->as($admin)->postJson("/api/v1/ai-agents/{$outbound->id}/simulate-inbound")->assertStatus(422);
    }

    public function test_vapi_inbound_call_webhook_opens_a_call_for_the_caller(): void
    {
        Notification::fake();
        Http::fake();
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'vapi', 'config' => ['api_key' => 'k', 'phone_number_id' => 'pn1']]);
        $token = $this->inTenant($admin, fn () => Integration::where('provider', 'vapi')->first()->inbound_token);
        $call = ['id' => 'vin-1', 'type' => 'inboundPhoneCall', 'customer' => ['number' => '+15550001111']];

        $this->postJson("/api/v1/webhooks/voice/vapi/{$token}", ['message' => ['type' => 'status-update', 'status' => 'in-progress', 'call' => $call]])->assertOk();
        $this->postJson("/api/v1/webhooks/voice/vapi/{$token}", ['message' => ['type' => 'end-of-call-report', 'endedReason' => 'customer-ended-call', 'call' => $call,
            'artifact' => ['messages' => [['role' => 'assistant', 'message' => 'Thanks for calling'], ['role' => 'user', 'message' => 'Please call me back tomorrow']]], 'summary' => 'Asked for a callback.']])->assertOk();

        $saved = $this->inTenant($admin, fn () => Call::where('provider_call_id', 'vin-1')->with('lead')->first());
        $this->assertSame('inbound', $saved->direction);
        $this->assertSame('completed', $saved->status);
        $this->assertSame('+15550001111', $saved->lead->phone);
        $this->assertSame(1, $this->inTenant($admin, fn () => Call::count()));
    }
}
