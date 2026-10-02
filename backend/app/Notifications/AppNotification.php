<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Channels\ChatChannel;
use App\Notifications\Channels\OrgMailChannel;
use App\Notifications\Channels\PushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A LeadFlow notification. It always lands in the bell; by default it also
 * reaches people outside the website (push to their phone / desktop), and by
 * email and team chat according to their preferences for this kind of event.
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
        'automation' => ['label' => 'A workflow notifies me', 'in_app' => true, 'email' => false, 'browser' => true],
        'booking' => ['label' => 'Someone books a meeting with me', 'in_app' => true, 'email' => true, 'browser' => true],
        'quote' => ['label' => 'A customer accepts or declines a quote', 'in_app' => true, 'email' => true, 'browser' => true],
        'sla' => ['label' => 'A new lead is waiting past the response target', 'in_app' => true, 'email' => true, 'browser' => true],
        'new_lead' => ['label' => 'A new lead arrives with nobody to own it (managers)', 'in_app' => true, 'email' => false, 'browser' => true],
        'lead_update' => ['label' => 'My lead converts, heats up or opts out', 'in_app' => true, 'email' => false, 'browser' => true],
        'deal' => ['label' => 'A deal is won, lost or assigned to me', 'in_app' => true, 'email' => true, 'browser' => true],
        'task' => ['label' => 'Someone gives me a task', 'in_app' => true, 'email' => false, 'browser' => true],
        'mention' => ['label' => 'Someone @mentions me in a note', 'in_app' => true, 'email' => true, 'browser' => true],
        'activity' => ['label' => 'A teammate adds a note, call or meeting to my record', 'in_app' => true, 'email' => false, 'browser' => true],
        'goal' => ['label' => 'A goal is reached', 'in_app' => true, 'email' => false, 'browser' => true],
        'campaign' => ['label' => 'An email campaign finishes or needs attention', 'in_app' => true, 'email' => false, 'browser' => true],
        'security' => ['label' => 'Sign-ins and security changes on my account', 'in_app' => true, 'email' => true, 'browser' => true],
        'system' => ['label' => 'Account and connection alerts', 'in_app' => true, 'email' => true, 'browser' => true],
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

        if ($channel === 'in_app') {
            return true; // the bell always shows everything
        }
        $default = self::KINDS[$kind][$channel] ?? ($channel === 'browser');

        return (bool) ($user->preferences['notifications'][$kind][$channel] ?? $default);
    }

    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return ['database'];
        }

        if ($this->kind === 'test') {
            return ['database', OrgMailChannel::class, ChatChannel::class, PushChannel::class];
        }

        return array_values(array_filter([
            'database',
            self::preference($notifiable, $this->kind, 'email') ? OrgMailChannel::class : null,
            self::preference($notifiable, $this->kind, 'browser') ? PushChannel::class : null,
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
