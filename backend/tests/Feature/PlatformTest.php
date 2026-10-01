<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Note;
use App\Models\User;
use App\Models\Webhook;
use App\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_step_login_with_authenticator_and_recovery_codes(): void
    {
        $admin = $this->organization();
        $secret = $this->as($admin)->postJson('/api/v1/auth/two-factor')->assertOk()->assertJsonPath('data.uri', fn ($u) => str_starts_with($u, 'otpauth://totp/'))->json('data.secret');
        $this->as($admin)->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();
        $codes = $this->as($admin)->postJson('/api/v1/auth/two-factor/confirm', ['code' => Totp::code($secret)])->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertStringNotContainsString($secret, (string) \DB::table('users')->where('id', $admin->id)->value('two_factor_secret')); // encrypted at rest

        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk()->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token');
        $challenge = $login->json('challenge');

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => '123456'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => 'garbage', 'code' => Totp::code($secret)])->assertUnprocessable();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => Totp::code($secret)])->assertOk()->assertJsonStructure(['token', 'user'])->assertJsonPath('user.two_factor_enabled', true);

        // A recovery code works exactly once.
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'recovery_code' => strtoupper($codes[0])])->assertOk();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'recovery_code' => $codes[0]])->assertUnprocessable();
        $this->as($admin->fresh())->getJson('/api/v1/auth/two-factor')->assertJsonPath('data.recovery_codes_left', 7);

        $this->as($admin->fresh())->deleteJson('/api/v1/auth/two-factor', ['password' => 'wrong'])->assertUnprocessable();
        $this->as($admin->fresh())->deleteJson('/api/v1/auth/two-factor', ['password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_consent_blocks_channels_and_people_can_unsubscribe(): void
    {
        config(['mail.default' => 'array']);
        $admin = $this->organization();
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ada', 'email' => 'ada@x.test', 'phone' => '+15550009999'])->json('data.id');

        $this->as($admin)->postJson("/api/v1/leads/{$id}/email", ['subject' => 'Hi', 'body' => 'Hello {first_name}'])->assertOk();
        $body = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->all()[0]->getOriginalMessage()->getTextBody();
        $this->assertMatchesRegularExpression("#/unsubscribe/{$id}/([a-f0-9]{32})#", $body);
        preg_match("#/unsubscribe/{$id}/([a-f0-9]{32})#", $body, $m);

        $this->postJson("/api/v1/public/unsubscribe/{$id}/".str_repeat('0', 32))->assertNotFound();
        $this->postJson("/api/v1/public/unsubscribe/{$id}/{$m[1]}")->assertOk()->assertJsonPath('data.email', 'a••@x.test');
        $this->as($admin)->postJson("/api/v1/leads/{$id}/email", ['subject' => 'Again', 'body' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->as($admin)->putJson("/api/v1/leads/{$id}/consent", ['channel' => 'sms', 'status' => 'denied'])->assertOk()->assertJsonPath('data.consent.sms.status', 'denied');
        $this->as($admin)->postJson("/api/v1/leads/{$id}/message", ['channel' => 'sms', 'body' => 'Hi'])->assertUnprocessable();
        $this->as($admin)->postJson("/api/v1/leads/{$id}/message", ['channel' => 'whatsapp', 'body' => 'Hi'])->assertOk();

        $this->as($admin)->putJson("/api/v1/leads/{$id}/consent", ['channel' => 'calls', 'status' => 'denied'])->assertOk();
        $agent = $this->inTenant($admin, fn () => AiAgent::first());
        $this->as($admin)->postJson("/api/v1/leads/{$id}/calls", ['ai_agent_id' => $agent->id])->assertUnprocessable();
    }

    public function test_stop_and_start_replies_change_messaging_consent(): void
    {
        $admin = $this->organization();
        $integration = $this->inTenant($admin, fn () => Integration::create(['category' => 'messaging', 'provider' => 'twilio', 'config' => ['account_sid' => 'AC', 'auth_token' => 't'], 'is_active' => true, 'status' => 'connected']));
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Sam', 'phone' => '+1 555 777 8888'])->json('data.id');

        $this->post("/api/v1/webhooks/messaging/twilio/{$integration->inbound_token}", ['From' => 'whatsapp:+15557778888', 'Body' => 'STOP'])->assertOk();
        $this->assertSame('denied', $this->inTenant($admin, fn () => Lead::find($id)->consentStatus('whatsapp')));
        $this->assertSame('unknown', $this->inTenant($admin, fn () => Lead::find($id)->consentStatus('sms')));
        $this->post("/api/v1/webhooks/messaging/twilio/{$integration->inbound_token}", ['From' => 'whatsapp:+15557778888', 'Body' => 'start'])->assertOk();
        $this->assertSame('granted', $this->inTenant($admin, fn () => Lead::find($id)->consentStatus('whatsapp')));
    }

    public function test_gdpr_export_and_erasure(): void
    {
        $admin = $this->organization();
        $manager = $this->member($admin, User::MANAGER);
        $rep = $this->member($admin);
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@navy.test', 'phone' => '+15550001111', 'company' => 'Navy'])->json('data.id');
        $this->as($admin)->postJson("/api/v1/leads/{$id}/notes", ['body' => 'Private note']);
        $this->as($admin)->postJson("/api/v1/leads/{$id}/call-notes", ['notes' => 'Grace: yes we need this, budget set aside']);

        $this->as($rep)->get("/api/v1/leads/{$id}/export", ['Accept' => 'application/json'])->assertForbidden();
        $export = $this->as($manager)->get("/api/v1/leads/{$id}/export")->assertOk()->streamedContent();
        $json = json_decode($export, true);
        $this->assertSame('grace@navy.test', $json['person']['email']);
        $this->assertNotEmpty($json['calls'][0]['transcript']);
        $this->assertSame('Private note', $json['notes'][0]['body']);

        $this->as($manager)->postJson("/api/v1/leads/{$id}/erase", ['confirm' => 'ERASE'])->assertForbidden();
        $this->as($admin)->postJson("/api/v1/leads/{$id}/erase", ['confirm' => 'Wrong'])->assertUnprocessable();
        $this->as($admin)->postJson("/api/v1/leads/{$id}/erase", ['confirm' => 'Grace Hopper'])->assertOk();

        $lead = $this->inTenant($admin, fn () => Lead::find($id));
        $this->assertNull($lead->email);
        $this->assertNull($lead->phone);
        $this->assertNull($lead->company);
        $this->assertSame('Erased', $lead->first_name);
        $this->assertNotNull($lead->erased_at);
        $this->assertFalse($lead->canContact('email'));
        $this->assertSame(0, $this->inTenant($admin, fn () => Note::where('notable_id', $id)->count()));
        $this->assertNull($this->inTenant($admin, fn () => Call::where('lead_id', $id)->first()->transcript));
        $this->as($admin)->postJson("/api/v1/leads/{$id}/erase", ['confirm' => 'ERASE'])->assertUnprocessable();
    }

    public function test_field_permissions_hide_and_lock_fields_by_role(): void
    {
        $admin = $this->organization();
        $rep = $this->member($admin);
        $id = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Ana', 'budget' => 50000, 'expected_value' => 9000, 'owner_id' => $rep->id])->json('data.id');

        $this->as($admin)->putJson('/api/v1/settings/field-permissions', ['rules' => ['lead' => [
            'budget' => ['sales_rep' => 'hidden', 'manager' => 'edit'],
            'expected_value' => ['sales_rep' => 'read'],
            'not_a_field' => ['sales_rep' => 'hidden'],
        ]]])->assertOk()->assertJsonPath('data.rules.lead.budget.sales_rep', 'hidden')->assertJsonMissingPath('data.rules.lead.budget.manager')->assertJsonMissingPath('data.rules.lead.not_a_field');
        $this->as($rep)->putJson('/api/v1/settings/field-permissions', ['rules' => []])->assertForbidden();

        $this->as($rep)->getJson("/api/v1/leads/{$id}")->assertOk()->assertJsonMissingPath('data.budget')->assertJsonPath('data.expected_value', '9000.00');
        $this->as($rep)->getJson('/api/v1/leads')->assertJsonMissingPath('data.0.budget');
        $this->as($admin)->getJson("/api/v1/leads/{$id}")->assertJsonPath('data.budget', '50000.00');
        $this->as($rep)->getJson('/api/v1/meta')->assertJsonPath('data.field_access.lead.hidden', ['budget'])->assertJsonPath('data.field_access.lead.readonly', ['expected_value']);

        $this->as($rep)->patchJson("/api/v1/leads/{$id}", ['expected_value' => 1])->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->as($rep)->patchJson("/api/v1/leads/{$id}", ['expected_value' => 9000, 'company' => 'Changed'])->assertOk(); // unchanged locked value is fine
        $this->as($rep)->patchJson("/api/v1/leads/{$id}", ['budget' => 1])->assertUnprocessable();
        $this->as($rep)->postJson('/api/v1/leads', ['first_name' => 'New', 'budget' => 10])->assertUnprocessable();
        $this->as($rep)->postJson('/api/v1/leads', ['first_name' => 'New'])->assertCreated();

        $this->as($rep)->postJson('/api/v1/reports/run', ['spec' => ['entity' => 'leads', 'metric' => 'budget']])->assertUnprocessable();
        $kpis = array_column($this->as($rep)->getJson('/api/v1/reports/type/leads')->json('data.kpis'), 'label');
        $this->assertContains('Expected value', $kpis);
    }

    public function test_rest_hooks_subscribe_receive_and_unsubscribe(): void
    {
        Queue::fake();
        $admin = $this->organization();
        $key = $this->as($admin)->postJson('/api/v1/settings/api-keys', ['name' => 'Zapier'])->json('key');
        $headers = ['X-Api-Key' => $key, 'Accept' => 'application/json'];

        $this->getJson('/api/v1/hooks/me')->assertUnauthorized();
        $this->getJson('/api/v1/hooks/me', $headers)->assertOk()->assertJsonPath('data.organization.id', $admin->organization_id);
        $this->postJson('/api/v1/hooks/subscriptions', ['event' => 'lead.created', 'target_url' => 'http://insecure.test'], $headers)->assertUnprocessable();
        $hookId = $this->postJson('/api/v1/hooks/subscriptions', ['event' => 'lead.created', 'target_url' => 'https://hooks.zapier.com/abc'], $headers)->assertCreated()->json('id');

        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Zed']);
        Queue::assertPushed(DeliverWebhook::class, fn ($job) => $job->webhookId === $hookId && $job->event === 'lead.created');

        $this->getJson('/api/v1/hooks/samples/lead.created', $headers)->assertOk()->assertJsonPath('0.data.lead.first_name', 'Zed');
        $this->getJson('/api/v1/hooks/leads?limit=1', $headers)->assertOk()->assertJsonPath('0.first_name', 'Zed');
        $this->deleteJson("/api/v1/hooks/subscriptions/{$hookId}", [], $headers)->assertNoContent();
        $this->assertSame(0, $this->inTenant($admin, fn () => Webhook::where('source', 'rest_hook')->count()));
    }
}
