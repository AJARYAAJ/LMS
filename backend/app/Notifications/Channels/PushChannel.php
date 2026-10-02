<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Push\Fcm;
use App\Push\WebPush;
use Illuminate\Notifications\Notification;
use Throwable;

/** Push to every device the person turned push on for (phone, desktop), even with the app closed. */
class PushChannel
{
    public function __construct(private WebPush $webPush, private Fcm $fcm) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notification instanceof AppNotification) {
            return;
        }
        $url = $notification->url ?? '/notifications';
        // The tag is the notification's id, so an open tab and the push show it once, not twice.
        $payload = json_encode(['title' => $notification->title, 'body' => $notification->body, 'url' => $url, 'tag' => $notification->id ?? $notification->kind]);

        PushSubscription::where('user_id', $notifiable->id)->get()->each(function (PushSubscription $s) use ($notification, $payload, $url) {
            try {
                $response = $s->kind === 'fcm'
                    ? $this->fcm->send($s->endpoint, $notification->title, $notification->body, $url)
                    : $this->webPush->send($s->endpoint, (string) $s->p256dh, (string) $s->auth, $payload);
            } catch (Throwable $e) {
                report($e);

                return;
            }
            // The device unsubscribed or the app was uninstalled.
            if (in_array($response->status(), [404, 410], true) || ($s->kind === 'fcm' && str_contains((string) $response->body(), 'UNREGISTERED'))) {
                $s->delete();
            } elseif ($response->successful()) {
                $s->forceFill(['last_used_at' => now()])->saveQuietly();
            }
        });
    }
}
