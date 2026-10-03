<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Organization;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Posts alerts to the organization's Slack / Microsoft Teams channel for the
 * notification kinds the admin routed there.
 */
class ChatAlerts
{
    public const DEFAULT_KINDS = ['ai_call', 'inbound_message'];

    /** $force skips the kind routing (used when a person chose chat delivery, e.g. a saved report). */
    public function post(int $organizationId, string $kind, string $title, string $body = '', ?string $url = null, bool $force = false): int
    {
        $organization = Organization::find($organizationId);
        $kinds = $organization?->settings['chat_alert_kinds'] ?? self::DEFAULT_KINDS;
        if (! $force && $kind !== 'test' && ! in_array($kind, $kinds, true)) {
            return 0;
        }

        $link = $url ? rtrim(config('app.frontend_url'), '/').$url : null;
        $sent = 0;

        Integration::withoutGlobalScopes()->where('organization_id', $organizationId)->where('category', 'chat')->where('is_active', true)->get()
            ->each(function (Integration $i) use ($title, $body, $link, &$sent) {
                $text = "*{$title}*".($body ? "\n{$body}" : '').($link ? "\n<{$link}|Open in LeadFlow>" : '');
                $payload = $i->provider === 'teams'
                    ? ['text' => "**{$title}**".($body ? "  \n{$body}" : '').($link ? "  \n[Open in LeadFlow]({$link})" : '')]
                    : ['text' => $text];
                try {
                    Http::timeout(8)->post($i->setting('webhook_url'), $payload)->throw();
                    $sent++;
                } catch (Throwable $e) {
                    $i->forceFill(['status' => 'error', 'last_error' => $e->getMessage()])->saveQuietly();
                }
            });

        return $sent;
    }
}
