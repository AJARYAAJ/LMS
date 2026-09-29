<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\Integration;
use App\Services\CallService;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class StartCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $callId) {}

    public function handle(): void
    {
        $call = Call::withoutGlobalScopes()->with(['lead.organization', 'agent'])->find($this->callId);
        if (! $call || $call->status !== 'queued') {
            return;
        }

        Tenant::run($call->organization_id, function () use ($call) {
            $service = app(CallService::class);
            try {
                $integration = $call->agent ? $service->integrationFor($call->agent) : null;
                if (! $integration) {
                    throw new \RuntimeException('No voice provider connected.');
                }
                $webhook = url("/api/v1/webhooks/voice/{$integration->provider}/".Integration::withoutGlobalScopes()->whereKey($integration->id)->value('inbound_token'));
                $result = CallService::provider($integration->provider)->start($call, $call->agent, $call->lead, $integration, $webhook);
                // The vendor may already have reported back (the simulator can finish instantly): never overwrite a final status.
                $call->refresh();
                $call->provider_call_id ??= $result['provider_call_id'];
                if ($call->status === 'queued') {
                    $call->fill(['status' => $result['status'], 'started_at' => now()]);
                }
                $call->save();
            } catch (Throwable $e) {
                report($e);
                $call->update(['status' => 'failed', 'error' => $e->getMessage(), 'ended_at' => now()]);
            }
        });
    }
}
