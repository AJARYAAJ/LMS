<?php

namespace App\Notifications\Channels;

use App\Notifications\AppNotification;
use App\Services\ChatAlerts;
use Illuminate\Notifications\Notification;

/** Mirrors routed notification kinds to the team's Slack / Teams channel. */
class ChatChannel
{
    public function __construct(private ChatAlerts $alerts) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if ($notification instanceof AppNotification) {
            $this->alerts->post($notifiable->organization_id, $notification->kind, $notification->title, $notification->body, $notification->url);
        }
    }
}
