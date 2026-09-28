<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * SMS / WhatsApp to leads. Driver "log" records without sending (default,
 * safe for development); driver "twilio" delivers through Twilio's REST API.
 * Every message is written to the lead timeline either way.
 */
class MessagingService
{
    public function __construct(private ActivityRecorder $activities, private EmailComposer $composer) {}

    public function driver(): string
    {
        return config('services.messaging.driver', 'log');
    }

    public function send(Lead $lead, string $channel, string $body, ?User $sender): array
    {
        if (! $lead->phone) {
            throw ValidationException::withMessages(['phone' => 'This lead has no phone number.']);
        }

        $body = $this->composer->render($body, $lead, $sender);
        $to = preg_replace('/[^\d+]/', '', $lead->phone);
        $status = 'logged';
        $reference = null;

        if ($this->driver() === 'twilio') {
            $from = $channel === 'whatsapp' ? config('services.messaging.twilio_whatsapp_from') : config('services.messaging.twilio_from');
            $response = Http::asForm()
                ->withBasicAuth(config('services.messaging.twilio_sid'), config('services.messaging.twilio_token'))
                ->timeout(15)
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.config('services.messaging.twilio_sid').'/Messages.json', [
                    'To' => $channel === 'whatsapp' ? "whatsapp:{$to}" : $to,
                    'From' => $from,
                    'Body' => $body,
                ]);

            if ($response->failed()) {
                throw ValidationException::withMessages(['body' => 'Delivery failed: '.($response->json('message') ?? $response->status())]);
            }
            $status = $response->json('status', 'queued');
            $reference = $response->json('sid');
        } else {
            Log::info("[{$channel}] to {$to}: {$body}");
        }

        $this->activities->record($lead, $channel, ($channel === 'whatsapp' ? 'WhatsApp' : 'SMS').' sent', [
            'description' => $body,
            'direction' => 'outbound',
            'outcome' => ucfirst($status),
            'meta' => array_filter(['to' => $to, 'provider' => $this->driver(), 'reference' => $reference]),
        ]);
        $lead->forceFill(['last_contacted_at' => now()])->saveQuietly();

        return ['status' => $status, 'driver' => $this->driver()];
    }
}
