<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Notifications\AppNotification;
use App\Services\ActivityRecorder;
use App\Services\CallService;
use App\Support\Phone;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public vendor callbacks. Each URL carries the integration's secret token,
 * which identifies the organization.
 */
class InboundWebhookController extends Controller
{
    public function voice(Request $request, string $provider, string $token): JsonResponse
    {
        $integration = $this->integration($provider, $token);
        $data = CallService::provider($provider)->parse($request->all());

        if (! $data) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        Tenant::run($integration->organization_id, function () use ($data, $provider) {
            $call = Call::where('provider', $provider)
                ->where(fn ($q) => $q->where('provider_call_id', $data['provider_call_id'] ?? '__none__')->orWhere('id', $data['call_id'] ?? 0))
                ->first();
            if ($call) {
                app(CallService::class)->update($call->load(['lead.organization', 'agent']), $data);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** Twilio inbound SMS / WhatsApp: the reply lands on the lead timeline and the owner is notified. */
    public function twilio(Request $request, string $token, ActivityRecorder $activities): Response
    {
        $integration = $this->integration('twilio', $token);
        $from = (string) $request->input('From', '');
        $channel = str_starts_with($from, 'whatsapp:') ? 'whatsapp' : 'sms';
        $digits = preg_replace('/\D+/', '', $from);
        $body = (string) $request->input('Body', '');

        Tenant::run($integration->organization_id, function () use ($digits, $channel, $body, $from, $activities) {
            $lead = strlen($digits) >= 6 ? Phone::whereMatches(Lead::query(), $digits)->latest('id')->first() : null;
            if (! $lead) {
                return;
            }
            $activities->record($lead, $channel, ($channel === 'whatsapp' ? 'WhatsApp' : 'SMS').' received', [
                'user_id' => null,
                'description' => $body,
                'direction' => 'inbound',
                'meta' => ['from' => $from, 'provider' => 'twilio'],
            ]);
            $lead->forceFill(['last_contacted_at' => now()])->saveQuietly();
            $lead->owner?->notify(new AppNotification(
                "{$lead->full_name} replied on ".($channel === 'whatsapp' ? 'WhatsApp' : 'SMS'),
                mb_strimwidth($body, 0, 160, '…'),
                "/leads/{$lead->id}",
                'inbound_message',
            ));
        });

        return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
    }

    private function integration(string $provider, string $token): Integration
    {
        $integration = Integration::withoutGlobalScopes()->where('provider', $provider)->where('inbound_token', $token)->where('is_active', true)->first();
        abort_unless($integration, 404);

        return $integration;
    }
}
