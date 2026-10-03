<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Services\CallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReceptionistTransferTest extends TestCase
{
    use RefreshDatabase;

    private function vapi(User $admin): string
    {
        Http::fake();
        $this->as($admin)->postJson('/api/v1/settings/integrations', ['provider' => 'vapi', 'config' => ['api_key' => 'k', 'phone_number_id' => 'pn1']]);

        return $this->inTenant($admin, fn () => Integration::where('provider', 'vapi')->first()->inbound_token);
    }

    public function test_vapi_asks_for_the_receptionist_and_gets_a_transfer_to_the_lead_owner(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $rep = $this->member($admin, User::SALES_REP, ['name' => 'Riley Rep', 'phone' => '+15550009999']);
        $token = $this->vapi($admin);
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Kim', 'last_name' => 'Known', 'phone' => '+1 555 222 3333', 'owner_id' => $rep->id]);
        $agent = $this->inTenant($admin, fn () => AiAgent::where('mode', 'inbound')->first());
        $this->assertSame('owner', $agent->transfer_mode);

        $call = ['id' => 'vin-9', 'type' => 'inboundPhoneCall', 'customer' => ['number' => '+15552223333']];
        $res = $this->postJson("/api/v1/webhooks/voice/vapi/{$token}", ['message' => ['type' => 'assistant-request', 'call' => $call]])->assertOk();
        $this->assertStringContainsString('Kim Known', $res->json('assistant.model.messages.0.content'));
        $this->assertSame('transferCall', $res->json('assistant.model.tools.0.type'));
        $this->assertSame('+15550009999', $res->json('assistant.model.tools.0.destinations.0.number'));
        $callId = $res->json('assistant.metadata.leadflow_call_id');

        // The call ends with Vapi forwarding it.
        $this->postJson("/api/v1/webhooks/voice/vapi/{$token}", ['message' => ['type' => 'end-of-call-report', 'endedReason' => 'assistant-forwarded-call', 'call' => $call,
            'artifact' => ['messages' => [['role' => 'user', 'message' => 'Can I talk to Riley? Call me back Thursday otherwise.']]], 'summary' => 'Asked for Riley.']])->assertOk();
        $saved = $this->inTenant($admin, fn () => Call::find($callId));
        $this->assertSame('transferred', $saved->outcome);
        $this->assertSame('Riley Rep', $saved->extracted['transferred_to']);
        $this->assertSame(0, $this->inTenant($admin, fn () => Task::count()), 'a transferred call needs no callback task');
        $this->assertSame(1, $this->inTenant($admin, fn () => Call::count()));
    }

    public function test_transfer_falls_back_to_the_team_number_and_can_be_turned_off(): void
    {
        $admin = $this->organization();
        $agent = $this->inTenant($admin, fn () => AiAgent::where('mode', 'inbound')->first());
        $this->as($admin)->putJson("/api/v1/settings/ai-agents/{$agent->id}", ['transfer_mode' => 'number'])->assertStatus(422);
        $this->as($admin)->putJson("/api/v1/settings/ai-agents/{$agent->id}", ['transfer_mode' => 'owner', 'transfer_number' => '+1 555 000 1111'])->assertOk();
        $leadId = $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Nora', 'phone' => '+15550123456'])->json('data.id'); // owner (admin) has no phone

        $target = $this->inTenant($admin, fn () => app(CallService::class)->transferTarget($agent->fresh(), Lead::find($leadId)));
        $this->assertSame(['name' => 'our team', 'number' => '+1 555 000 1111', 'user_id' => null], $target);

        $this->as($admin)->putJson("/api/v1/settings/ai-agents/{$agent->id}", ['transfer_mode' => 'none'])->assertOk();
        $this->assertNull($this->inTenant($admin, fn () => app(CallService::class)->transferTarget($agent->fresh(), Lead::find($leadId))));
    }

    public function test_simulated_callers_can_be_transferred(): void
    {
        Notification::fake();
        $admin = $this->organization();
        $agent = $this->inTenant($admin, fn () => tap(AiAgent::where('mode', 'inbound')->first())->update(['transfer_number' => '+15550001234']));
        $outcomes = collect(range(1, 12))->map(function ($i) use ($admin, $agent) {
            $id = $this->as($admin)->postJson("/api/v1/ai-agents/{$agent->id}/simulate-inbound", ['phone' => '+1555777'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)])->json('data.id');

            return $this->inTenant($admin, fn () => Call::find($id)->outcome);
        });
        $this->assertContains('transferred', $outcomes->all());
    }
}
