<?php

namespace App\Notifications\Channels;

use App\Notifications\AppNotification;
use App\Services\OrgMailer;
use Illuminate\Notifications\Notification;
use Throwable;

/** Email copy of a notification, sent through the organization's email vendor. */
class OrgMailChannel
{
    public function __construct(private OrgMailer $mailer) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof AppNotification || ! $notifiable->email) {
            return;
        }

        $link = $notification->url ? rtrim(config('app.frontend_url'), '/').$notification->url : null;
        $text = $notification->body."\n\n".($link ? "Open in LeadFlow: {$link}\n\n" : '').'— LeadFlow · change what you receive under Profile → Notifications';

        try {
            $this->mailer->send($notifiable->organization_id, $notifiable->email, $notifiable->name, $notification->title, $text);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
