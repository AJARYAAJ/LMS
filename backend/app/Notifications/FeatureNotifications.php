<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Notifications raised by everyday changes across the app (deals, tasks, notes,
 * activities, leads), so the right person hears about them in the bell and on
 * their phone / desktop without each screen having to remember to notify.
 */
class FeatureNotifications
{
    public static function register(): void
    {
        // Deals: won / lost, and a new owner.
        Deal::updated(function (Deal $deal) {
            $url = "/deals/{$deal->id}";
            $deal->unsetRelation('owner'); // the owner may just have changed
            if ($deal->wasChanged('status') && in_array($deal->status, ['won', 'lost'], true)) {
                $amount = number_format((float) $deal->amount).' '.($deal->currency ?? '');
                $title = $deal->status === 'won' ? "Deal won: {$deal->name}" : "Deal lost: {$deal->name}";
                $body = $deal->status === 'won' ? trim($amount).' closed'.(auth()->user() ? ' by '.auth()->user()->name : '').'.' : ($deal->lost_reason ? "Reason: {$deal->lost_reason}" : 'Marked as lost.');
                Notifier::user($deal->owner, $title, $body, $url, 'deal');
                Notifier::managers($deal->organization_id, $title, ($deal->owner ? "{$deal->owner->name}’s deal. " : '').$body, $url, 'deal');
            }
            if ($deal->wasChanged('owner_id') && $deal->owner_id) {
                Notifier::user($deal->owner, 'Deal assigned to you', "{$deal->name}".($deal->amount ? ' · '.number_format((float) $deal->amount) : ''), $url, 'deal');
            }
        });

        // Tasks: someone else gave you one.
        $taskAssigned = function (Task $task) {
            if (! $task->assigned_to || ($task->exists && ! $task->wasRecentlyCreated && ! $task->wasChanged('assigned_to'))) {
                return;
            }
            $by = auth()->user();
            if (! $by || $by->id === $task->assigned_to || $task->sequence_enrollment_id) {
                return; // own tasks, automation and sequence steps aren't news
            }
            $assignee = User::withoutGlobalScopes()->find($task->assigned_to);
            Notifier::user($assignee, "{$by->name} gave you a task", $task->title.($task->due_at ? ' · due '.$task->due_at->format('M j, H:i') : ''), self::taskUrl($task), 'task', $by);
        };
        Task::created($taskAssigned);
        Task::updated($taskAssigned);

        // Notes: @mentions, and teammates' notes on your records.
        Note::created(function (Note $note) {
            $author = User::withoutGlobalScopes()->find($note->user_id);
            $record = $note->notable;
            if (! $author || ! $record) {
                return;
            }
            $name = self::recordName($record);
            $url = self::recordUrl($note->notable_type, $note->notable_id);
            $mentioned = self::mentions($note->organization_id, (string) $note->body);
            Notifier::many($mentioned, "{$author->name} mentioned you", "On {$name}: ".Str::limit(strip_tags($note->body), 140), $url, 'mention', $author);
            $owner = ($record->owner_id ?? null) ? User::withoutGlobalScopes()->find($record->owner_id) : null;
            if ($owner && ! $mentioned->contains('id', $owner->id)) {
                Notifier::user($owner, "{$author->name} added a note", "On {$name}: ".Str::limit(strip_tags($note->body), 140), $url, 'activity', $author);
            }
        });

        // Calls and meetings a teammate logs on your lead.
        Activity::created(function (Activity $a) {
            if (! in_array($a->type, ['call', 'meeting'], true) || ! $a->user_id || $a->direction === 'inbound' || isset($a->meta['call_id']) || $a->subject_type !== 'lead') {
                return;
            }
            $lead = Lead::withoutGlobalScopes()->find($a->subject_id);
            $author = User::withoutGlobalScopes()->find($a->user_id);
            if ($lead?->owner && $author) {
                Notifier::user($lead->owner, "{$author->name} logged a {$a->type}", "{$lead->full_name}: {$a->title}", "/leads/{$lead->id}", 'activity', $author);
            }
        });

        // Leads: converted by someone else, heating up, opting out.
        Lead::updated(function (Lead $lead) {
            $url = "/leads/{$lead->id}";
            if ($lead->wasChanged('converted_at') && $lead->converted_at) {
                Notifier::user($lead->owner, "{$lead->full_name} was converted", (auth()->user() ? auth()->user()->name.' converted your lead' : 'Your lead was converted').($lead->company ? " ({$lead->company})" : '').'.', $url, 'lead_update');
            }
            $hot = ['hot', 'very_high'];
            if ($lead->wasChanged('rating') && in_array($lead->rating, $hot, true) && ! in_array($lead->getOriginal('rating'), $hot, true)
                && $lead->created_at?->lt(now()->subMinutes(5))) {
                Notifier::user($lead->owner, "{$lead->full_name} is heating up", 'Rating is now '.str_replace('_', ' ', $lead->rating).' (score '.$lead->score.'). A good moment to reach out.', $url, 'lead_update', null);
            }
        });
    }

    /** People @mentioned by first name or full name ("@Riley" or "@Riley Rep"). */
    private static function mentions(int $organizationId, string $body)
    {
        if (! str_contains($body, '@')) {
            return collect();
        }
        $text = Str::lower($body);

        return User::withoutGlobalScopes()->where('organization_id', $organizationId)->where('is_active', true)->get()
            ->filter(fn (User $u) => str_contains($text, '@'.Str::lower($u->name)) || preg_match('/@'.preg_quote(Str::lower(Str::before($u->name, ' ')), '/').'\b/u', $text));
    }

    private static function recordName($record): string
    {
        return $record->full_name ?? $record->name ?? 'a record';
    }

    private static function recordUrl(string $type, int $id): string
    {
        return match ($type) {
            'lead' => "/leads/{$id}", 'deal' => "/deals/{$id}", 'contact' => "/contacts/{$id}", 'account' => "/accounts/{$id}", default => '/notifications',
        };
    }

    private static function taskUrl(Task $task): string
    {
        return $task->taskable_type && $task->taskable_id ? self::recordUrl($task->taskable_type, $task->taskable_id) : '/tasks';
    }
}
