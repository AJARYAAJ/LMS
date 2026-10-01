<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ChatChannel;
use App\Notifications\Channels\OrgMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A LeadFlow notification. Delivered in-app, by email and/or to the team chat
 * according to the recipient's preferences for this kind of event.
 */
class AppNotification extends Notification
{
    use Queueable;

    /** Event kinds a user can configure, with their default channels. */
    public const KINDS = [
        'assignment' => ['label' => 'A lead is assigned to me', 'in_app' => true, 'email' => true, 'browser' => true],
        'ai_call' => ['label' => 'An AI call finishes on my lead', 'in_app' => true, 'email' => false, 'browser' => true],
        'inbound_message' => ['label' => 'A lead replies by SMS / WhatsApp', 'in_app' => true, 'email' => true, 'browser' => true],
        'reminder' => ['label' => 'A task is due or overdue', 'in_app' => true, 'email' => false, 'browser' => true],
        'automation' => ['label' => 'A workflow notifies me', 'in_app' => true, 'email' => false, 'browser' => false],
        'quote' => ['label' => 'A customer accepts or declines a quote', 'in_app' => true, 'email' => true, 'browser' => true],
        'sla' => ['label' => 'A new lead is waiting past the response target', 'in_app' => true, 'email' => true, 'browser' => true],
    ];

    public function __construct(
        public string $title,
        public string $body = '',
        public ?string $url = null,
        public string $kind = 'info',
        public bool $toChat = true,
    ) {}

    public static function preference(User $user, string $kind, string $channel): bool
    {
        if ($kind === 'test') {
            return true; // a test goes everywhere so every channel can be checked
        }

        $default = self::KINDS[$kind][$channel] ?? ($channel === 'in_app');

        return (bool) ($user->preferences['notifications'][$kind][$channel] ?? $default);
    }

    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        if ($this->kind === 'test') {
            return ['database', OrgMailChannel::class, ChatChannel::class];
        }

        return array_values(array_filter([
            self::preference($notifiable, $this->kind, 'in_app') || self::preference($notifiable, $this->kind, 'browser') ? 'database' : null,
            self::preference($notifiable, $this->kind, 'email') ? OrgMailChannel::class : null,
            $this->toChat ? ChatChannel::class : null,
        ]));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'kind' => $this->kind,
            'browser' => $notifiable instanceof User ? self::preference($notifiable, $this->kind, 'browser') : false,
            'in_app' => $notifiable instanceof User ? self::preference($notifiable, $this->kind, 'in_app') : true,
        ];
    }
}
