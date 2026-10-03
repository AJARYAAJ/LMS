<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Services\BroadcastService;
use App\Support\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Sends every queued recipient of an email campaign. */
class SendBroadcast implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(public int $broadcastId) {}

    public function handle(): void
    {
        $broadcast = Broadcast::withoutGlobalScopes()->find($this->broadcastId);
        if (! $broadcast || in_array($broadcast->status, ['draft', 'scheduled', 'canceled'], true)) {
            return;
        }

        Tenant::run($broadcast->organization_id, fn () => app(BroadcastService::class)->sendQueued($broadcast));
    }
}
