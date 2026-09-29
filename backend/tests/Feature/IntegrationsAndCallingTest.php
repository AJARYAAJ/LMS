<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\Channels\OrgMailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IntegrationsAndCallingTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrations_hub_stores_encrypted_write_only_secrets(): void
    {
        $admin = $this->organization();

        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'twilio', 'config' => ['account_sid' => 'AC123']])
            ->assertUnprocessable()->assertJsonValidationErrors('config.auth_token');

        $res = $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'twilio', 'config' => ['account_sid' => 'AC123', 'auth_token' => 'secret-token', 'from' => '+15550001111']])
            ->assertCreated()
            ->assertJsonPath('data.values.account_sid', 'AC123')
            ->assertJsonPath('data.values.auth_token', null)
            ->assertJsonPath('data.secrets_set.auth_token', true);
        $this->assertStringContainsString('/api/v1/webhooks/messaging/twilio/', $res->json('data.inbound_url'));

        $raw = \DB::table('integrations')->where('provider', 'twilio')->value('config');
        $this->assertStringNotContainsString('secret-token', $raw); // encrypted at rest

        // Re-saving without the secret keeps it
        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'twilio', 'config' => ['account_sid' => 'AC999']])->assertOk();
        $this->assertSame('secret-token', $this->inTenant($admin, fn () => Integration::where('provider', 'twilio')->first()->setting('auth_token')));

        $this->as($admin)->getJson('/api/v1/settings/integrations')->assertOk()->assertJsonFragment(['key' => 'vapi']);
        $manager = $this->member($admin, User::MANAGER);
        $this->as($manager)->getJson('/api/v1/settings/integrations')->assertForbidden();
    }

    public function test_messages_go_through_the_connected_twilio_account(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'])]);
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'twilio', 'config' => ['account_sid' => 'AC1', 'auth_token' => 't', 'from' => '+15550001111', 'whatsapp_from' => '+14155238886']]);
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ravi', 'phone' => '+91 98765 43210'])->json('data.id');

        $this->as($admin)->postJson("/api/v1/leads/{$id}/message", ['channel' => 'whatsapp', 'body' => 'Hi {first_name}'])
            ->assertOk()->assertJsonPath('data.driver', 'twilio');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'Accounts/AC1/Messages.json') && $r['To'] === 'whatsapp:+919876543210' && $r['From'] === 'whatsapp:+14155238886' && $r['Body'] === 'Hi Ravi');
    }

    public function test_inbound_whatsapp_reply_lands_on_the_lead_and_notifies_the_owner(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $rep = $this->member($admin);
        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'twilio', 'config' => ['account_sid' => 'AC1', 'auth_token' => 't']]);
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ravi', 'phone' => '+91 98765 43210', 'owner_id' => $rep->id])->json('data.id');
        $token = $this->inTenant($admin, fn () => Integration::where('provider', 'twilio')->first()->inbound_token);

        $this->post("/api/v1/webhooks/messaging/twilio/{$token}", ['From' => 'whatsapp:+919876543210', 'Body' => 'Yes, send me the pricing'])->assertOk();
        $this->post('/api/v1/webhooks/messaging/twilio/wrong-token', ['From' => '+1', 'Body' => 'x'])->assertNotFound();

        $this->as($admin)->getJson("/api/v1/leads/{$id}/activities?type=whatsapp")
            ->assertJsonPath('data.0.direction', 'inbound')->assertJsonPath('data.0.description', 'Yes, send me the pricing');
        Notification::assertSentTo($rep, AppNotification::class, fn ($n) => $n->kind === 'inbound_message');
    }

    public function test_ai_call_with_simulator_updates_lead_task_and_owner(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $rep = $this->member($admin);
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Hot', 'email' => 'hot@bigco.io', 'company' => 'BigCo', 'phone' => '+1 555 010 2030', 'budget' => 50000, 'owner_id' => $rep->id])->json('data.id');
        $agent = $this->inTenant($admin, fn () => AiAgent::first());

        $callId = $this->as($admin)->postJson("/api/v1/leads/{$id}/calls", ['ai_agent_id' => $agent->id])->assertCreated()->json('data.id');
        // queue is sync in tests: the simulator has already finished the call
        $call = $this->as($admin)->getJson("/api/v1/calls/{$callId}")->assertOk()->json('data');

        $this->assertContains($call['status'], ['completed', 'no_answer', 'voicemail']);
        $this->assertNotEmpty($call['outcome']);
        $this->as($admin)->getJson("/api/v1/leads/{$id}/activities?type=call")->assertJsonPath('data.0.meta.call_id', $callId);

        if (in_array($call['outcome'], ['meeting_booked', 'callback'], true)) {
            $this->assertSame(1, $this->inTenant($admin, fn () => Task::where('taskable_id', $id)->where('description', 'like', 'Agreed on AI call%')->count()));
            $this->assertNotEmpty($this->inTenant($admin, fn () => Lead::find($id)->qualification));
        }
        if ($call['outcome'] !== 'no_answer') {
            Notification::assertSentTo($rep, AppNotification::class, fn ($n) => $n->kind === 'ai_call');
        }

        $this->as($admin)->getJson('/api/v1/calls/stats')->assertOk()->assertJsonPath('data.total', 1);
        $this->as($admin)->postJson("/api/v1/leads/{$id}/calls", ['ai_agent_id' => $agent->id])->assertCreated(); // finished calls don't block a new one
    }

    public function test_vapi_webhook_completes_call_once(): void
    {
        Http::fake(['api.vapi.ai/*' => Http::response(['id' => 'vapi-call-1'])]);
        Notification::fake();
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'vapi', 'config' => ['api_key' => 'k', 'phone_number_id' => 'pn1']]);
        [$agent, $token] = $this->inTenant($admin, function () {
            $vapi = Integration::where('provider', 'vapi')->first();
            $agent = AiAgent::first();
            $agent->update(['integration_id' => $vapi->id]);

            return [$agent, $vapi->inbound_token];
        });
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Vee', 'phone' => '+1 555 999 0000'])->json('data.id');

        $callId = $this->as($admin)->postJson("/api/v1/leads/{$id}/calls", ['ai_agent_id' => $agent->id])->json('data.id');
        $this->assertSame('vapi-call-1', $this->inTenant($admin, fn () => Call::find($callId)->provider_call_id));
        Http::assertSent(fn ($r) => $r->url() === 'https://api.vapi.ai/call' && $r['phoneNumberId'] === 'pn1' && $r['customer']['number'] === '+15559990000');

        $report = ['message' => [
            'type' => 'end-of-call-report', 'endedReason' => 'customer-ended-call', 'durationSeconds' => 95,
            'call' => ['id' => 'vapi-call-1'], 'recordingUrl' => 'https://rec.example/1.mp3',
            'artifact' => ['messages' => [
                ['role' => 'bot', 'message' => 'Hi Vee, do you have two minutes?'],
                ['role' => 'user', 'message' => "I'm in a meeting, can you call me back Thursday at 2 pm?"],
            ]],
            'analysis' => ['summary' => 'Asked for a callback on Thursday.'],
        ]];
        $this->postJson("/api/v1/webhooks/voice/vapi/{$token}", $report)->assertOk();
        $this->postJson("/api/v1/webhooks/voice/vapi/{$token}", $report)->assertOk(); // retries are idempotent

        $call = $this->inTenant($admin, fn () => Call::find($callId));
        $this->assertSame('completed', $call->status);
        $this->assertSame('callback', $call->outcome);
        $this->assertSame(95, $call->duration_seconds);
        $this->assertSame('https://rec.example/1.mp3', $call->recording_url);
        $this->assertSame(1, $this->inTenant($admin, fn () => Activity::where('subject_id', $id)->where('type', 'call')->count()));
    }

    public function test_campaign_calls_matching_leads_and_requires_manager(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        foreach (['A', 'B', 'C'] as $i => $n) {
            $this->as($admin)->postJson('/api/v1/leads', ['first_name' => $n, 'phone' => $i < 2 ? "+1 555 000 11{$i}1" : null, 'priority' => 'high']);
        }
        $agent = $this->inTenant($admin, fn () => AiAgent::first());

        $this->as($rep)->postJson("/api/v1/ai-agents/{$agent->id}/campaign", [])->assertForbidden();
        $this->as($admin)->postJson("/api/v1/ai-agents/{$agent->id}/campaign", ['conditions' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'high']]])
            ->assertCreated()->assertJsonPath('data.queued', 2);
        $this->assertSame(2, $this->inTenant($admin, fn () => Call::whereNotNull('campaign_key')->count()));
    }

    public function test_campaign_limit_skips_past_recently_called_leads(): void
    {
        $admin = $this->organization();
        foreach (range(1, 4) as $i) {
            $this->as($admin)->postJson('/api/v1/leads', ['first_name' => "L{$i}", 'phone' => "+1 555 010 00{$i}0"]);
        }
        $agent = $this->inTenant($admin, fn () => AiAgent::first());

        $this->as($admin)->postJson("/api/v1/ai-agents/{$agent->id}/campaign", ['limit' => 2])->assertCreated()->assertJsonPath('data.queued', 2);
        // The first two were just called, so the next launch reaches the other two instead of skipping.
        $this->as($admin)->postJson("/api/v1/ai-agents/{$agent->id}/campaign", ['limit' => 2])->assertCreated()->assertJsonPath('data.queued', 2);
        $this->assertSame(4, $this->inTenant($admin, fn () => Call::distinct()->count('lead_id')));
    }

    public function test_notification_preferences_control_channels(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);

        $this->as($rep)->getJson('/api/v1/auth/notification-preferences')->assertOk()->assertJsonPath('data.kinds.0.kind', 'assignment');
        $this->as($rep)->putJson('/api/v1/auth/notification-preferences', ['notifications' => ['assignment' => ['email' => false, 'in_app' => true]], 'digest' => false])
            ->assertOk()->assertJsonPath('data.kinds.0.email', false)->assertJsonPath('data.digest', false);
        $this->as($rep)->putJson('/api/v1/auth/notification-preferences', ['chat_alert_kinds' => ['assignment']])->assertForbidden();

        $channels = (new AppNotification('x', kind: 'assignment'))->via($rep->fresh());
        $this->assertNotContains(OrgMailChannel::class, $channels);
        $this->assertContains('database', $channels);

        Notification::fake();
        $this->as($rep)->postJson('/api/v1/notifications/test')->assertOk();
        Notification::assertSentTo($rep, AppNotification::class, fn ($n) => $n->kind === 'test');
    }

    public function test_desktop_only_alerts_do_not_count_as_unread(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $this->as($rep)->putJson('/api/v1/auth/notification-preferences', ['notifications' => ['ai_call' => ['in_app' => false, 'browser' => true]]])->assertOk();

        $rep->fresh()->notify(new AppNotification('Call done', kind: 'ai_call'));
        $rep->fresh()->notify(new AppNotification('Lead assigned', kind: 'assignment'));

        $this->as($rep)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['title' => 'Call done', 'in_app' => false, 'browser' => true]);
    }

    public function test_meta_lists_connected_voice_providers(): void
    {
        $admin = $this->organization();
        $this->as($admin)->getJson('/api/v1/meta')->assertOk()
            ->assertJsonPath('data.features.voice', 'simulator')
            ->assertJsonPath('data.features.voice_providers.0.provider', 'simulator');
    }

    public function test_slack_alerts_follow_admin_routing(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok')]);
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'slack', 'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/X']]);
        $this->as($admin)->putJson('/api/v1/auth/notification-preferences', ['chat_alert_kinds' => ['assignment']])->assertOk();

        $admin->fresh()->notify(new AppNotification('Lead assigned', 'Ada', '/leads/1', 'assignment'));
        $admin->fresh()->notify(new AppNotification('Reminder', 'x', null, 'reminder'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'], 'Lead assigned'));
    }
}
