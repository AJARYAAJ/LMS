<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sends a LeadFlow notification (bell + push, plus email / chat per preferences)
 * to the right people, never to the person who caused the event.
 */
class Notifier
{
    public static function user(?User $user, string $title, string $body, ?string $url, string $kind, ?User $actor = null, bool $toChat = false): void
    {
        $actor ??= auth()->user();
        if (! $user || ! $user->is_active || ($actor && $actor->id === $user->id)) {
            return;
        }
        $user->notify(new AppNotification($title, $body, $url, $kind, $toChat));
    }

    /** Admins and managers of an organization. */
    public static function managers(int $organizationId, string $title, string $body, ?string $url, string $kind, ?User $actor = null, bool $adminsOnly = false): void
    {
        self::many(
            User::withoutGlobalScopes()->where('organization_id', $organizationId)->where('is_active', true)
                ->whereIn('role', $adminsOnly ? [User::ADMIN] : [User::ADMIN, User::MANAGER])->get(),
            $title, $body, $url, $kind, $actor,
        );
    }

    /** @param  Collection<int, User>|array<int, ?User>  $users */
    public static function many(Collection|array $users, string $title, string $body, ?string $url, string $kind, ?User $actor = null): void
    {
        collect($users)->filter()->unique('id')->each(fn (User $u) => self::user($u, $title, $body, $url, $kind, $actor));
    }
}
