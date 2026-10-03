<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Notifications\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $webhookId, public string $event, public array $payload) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /** Out of retries: tell the admins once a day that this webhook is failing. */
    public function failed(?Throwable $e = null): void
    {
        $webhook = Webhook::withoutGlobalScopes()->find($this->webhookId);
        if ($webhook && Cache::add("webhook_failing:{$webhook->id}", true, now()->addDay())) {
            Notifier::managers($webhook->organization_id, 'A webhook keeps failing', "Deliveries of “{$this->event}” to {$webhook->url} failed after {$this->tries} tries (last status ".($webhook->last_status ?: 'no response').').', '/settings/integrations', 'system', adminsOnly: true);
        }
    }

    public function handle(): void
    {
        $webhook = Webhook::withoutGlobalScopes()->find($this->webhookId);

        if (! $webhook || ! $webhook->is_active) {
            return;
        }

        $body = json_encode([
            'event' => $this->event,
            'occurred_at' => now()->toIso8601String(),
            'data' => $this->payload,
        ]);

        $status = 0;

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-LMS-Event' => $this->event,
                    'X-LMS-Signature' => 'sha256='.hash_hmac('sha256', $body, $webhook->secret),
                ])
                ->withBody($body, 'application/json')
                ->post($webhook->url);
            $status = $response->status();
        } catch (Throwable) {
            $status = 0;
        }

        $webhook->forceFill(['last_triggered_at' => now(), 'last_status' => $status])->saveQuietly();

        if ($status < 200 || $status >= 300) {
            $this->release($this->backoff()[$this->attempts() - 1] ?? 300);
        }
    }
}
