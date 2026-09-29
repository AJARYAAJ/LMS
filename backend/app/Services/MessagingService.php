<?php

namespace App\Services;

use App\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * SMS / WhatsApp to leads through the organization's connected vendor:
 * Twilio (SMS + WhatsApp) or Meta WhatsApp Cloud API. Without a vendor the
 * message is only recorded ("log"). Every message lands on the lead timeline.
 */
class MessagingService
{
    public function __construct(
        private ActivityRecorder $activities,
        private EmailComposer $composer,
        private IntegrationManager $integrations,
    ) {}

    /** The provider that would deliver messages for an organization. */
    public function driver(?int $organizationId): string
    {
        $integration = $organizationId ? $this->integrations->active($organizationId, 'messaging') : null;

        return $integration?->provider ?? (config('services.messaging.driver') === 'twilio' ? 'twilio_env' : 'log');
    }

    public function send(Lead $lead, string $channel, string $body, ?User $sender): array
    {
        if (! $lead->phone) {
            throw ValidationException::withMessages(['phone' => 'This lead has no phone number.']);
        }

        $body = $this->composer->render($body, $lead, $sender);
        $to = preg_replace('/[^\d+]/', '', $lead->phone);
        $integration = $this->pick($lead->organization_id, $channel);
        $provider = $integration?->provider ?? (config('services.messaging.driver') === 'twilio' ? 'twilio_env' : 'log');

        [$status, $reference] = match ($provider) {
            'twilio' => $this->twilio($integration->setting('account_sid'), $integration->setting('auth_token'),
                $channel === 'whatsapp' ? $integration->setting('whatsapp_from') : $integration->setting('from'), $to, $channel, $body),
            'twilio_env' => $this->twilio(config('services.messaging.twilio_sid'), config('services.messaging.twilio_token'),
                $channel === 'whatsapp' ? config('services.messaging.twilio_whatsapp_from') : config('services.messaging.twilio_from'), $to, $channel, $body),
            'meta_whatsapp' => $this->metaWhatsapp($integration, $to, $body),
            default => $this->log($channel, $to, $body),
        };

        $this->activities->record($lead, $channel, ($channel === 'whatsapp' ? 'WhatsApp' : 'SMS').' sent', [
            'description' => $body,
            'direction' => 'outbound',
            'outcome' => ucfirst($status),
            'meta' => array_filter(['to' => $to, 'provider' => $provider, 'reference' => $reference]),
        ]);
        $lead->forceFill(['last_contacted_at' => now()])->saveQuietly();

        return ['status' => $status, 'driver' => $provider === 'twilio_env' ? 'twilio' : $provider];
    }

    private function pick(int $organizationId, string $channel): ?Integration
    {
        if ($channel === 'whatsapp' && ($meta = $this->integrations->provider($organizationId, 'meta_whatsapp'))) {
            return $meta;
        }
        $twilio = $this->integrations->provider($organizationId, 'twilio');

        if ($channel === 'sms' && ! $twilio) {
            return null;
        }

        return $twilio ?? $this->integrations->active($organizationId, 'messaging');
    }

    private function twilio(?string $sid, ?string $token, ?string $from, string $to, string $channel, string $body): array
    {
        if (! $from) {
            throw ValidationException::withMessages(['channel' => 'No '.($channel === 'whatsapp' ? 'WhatsApp sender' : 'SMS number').' is configured for Twilio.']);
        }
        $response = Http::asForm()->withBasicAuth((string) $sid, (string) $token)->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $channel === 'whatsapp' ? "whatsapp:{$to}" : $to,
                'From' => $channel === 'whatsapp' && ! str_starts_with($from, 'whatsapp:') ? "whatsapp:{$from}" : $from,
                'Body' => $body,
            ]);
        if ($response->failed()) {
            throw ValidationException::withMessages(['body' => 'Delivery failed: '.($response->json('message') ?? $response->status())]);
        }

        return [$response->json('status', 'queued'), $response->json('sid')];
    }

    private function metaWhatsapp(Integration $integration, string $to, string $body): array
    {
        $response = Http::withToken($integration->setting('access_token'))->timeout(15)
            ->post('https://graph.facebook.com/v21.0/'.$integration->setting('phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'text',
                'text' => ['body' => $body],
            ]);
        if ($response->failed()) {
            throw ValidationException::withMessages(['body' => 'Delivery failed: '.($response->json('error.message') ?? $response->status())]);
        }

        return ['sent', $response->json('messages.0.id')];
    }

    private function log(string $channel, string $to, string $body): array
    {
        Log::info("[{$channel}] to {$to}: {$body}");

        return ['logged', null];
    }
}
