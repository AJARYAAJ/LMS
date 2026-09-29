<?php

namespace App\Jobs;

use App\Models\Call;
use App\Services\CallService;
use App\Support\Tenant;
use App\Voice\SimulatorProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Completes a simulated call through the same pipeline a vendor webhook uses. */
class SimulateCallResult implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $callId) {}

    public function handle(): void
    {
        $call = Call::withoutGlobalScopes()->with(['lead.organization', 'agent'])->find($this->callId);
        if (! $call || $call->isFinal() || ! $call->lead || ! $call->agent) {
            return;
        }

        Tenant::run($call->organization_id, fn () => app(CallService::class)->update($call, SimulatorProvider::conversation($call)));
    }
}
