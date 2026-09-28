<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Generic in-app notification rendered by the SPA's notification centre.
 */
class AppNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body = '',
        public ?string $url = null,
        public string $kind = 'info',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'kind' => $this->kind,
        ];
    }
}
