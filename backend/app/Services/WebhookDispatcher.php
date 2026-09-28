<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;

class WebhookDispatcher
{
    public const EVENTS = [
        'lead.created', 'lead.updated', 'lead.status_changed', 'lead.assigned', 'lead.converted', 'lead.deleted',
    ];

    public function dispatch(int $organizationId, string $event, array $payload): void
    {
        Webhook::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Webhook $w) => in_array($event, $w->events ?? [], true) || in_array('*', $w->events ?? [], true))
            ->each(fn (Webhook $w) => DeliverWebhook::dispatch($w->id, $event, $payload)->afterCommit());
    }
}
