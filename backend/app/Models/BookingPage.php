<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Services\Mailbox;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['user_id', 'slug', 'title', 'description', 'duration_minutes', 'buffer_minutes', 'notice_hours', 'days_ahead', 'weekdays', 'start_time', 'end_time', 'timezone', 'is_active'])]
class BookingPage extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['weekdays' => '[1,2,3,4,5]', 'is_active' => true];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'is_active' => 'boolean', 'duration_minutes' => 'integer', 'buffer_minutes' => 'integer', 'notice_hours' => 'integer', 'days_ahead' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Free start times (UTC ISO strings) grouped by local date, skipping the owner's
     * existing meetings/calls (plus buffer) and anything inside the notice period.
     *
     * @return array<string, list<string>>
     */
    public function availableSlots(?Carbon $now = null): array
    {
        $now ??= now();
        $tz = $this->timezone ?: 'UTC';
        $earliest = $now->copy()->addHours($this->notice_hours);
        $length = $this->duration_minutes;
        $buffer = $this->buffer_minutes;

        $busy = Task::withoutGlobalScopes()->where('assigned_to', $this->user_id)->whereNull('completed_at')
            ->whereIn('type', ['meeting', 'call'])
            ->whereBetween('due_at', [$now->copy()->subDay(), $now->copy()->addDays($this->days_ahead + 1)])
            ->get(['due_at', 'description'])
            ->map(fn (Task $t) => [$t->due_at->copy()->subMinutes($buffer), $t->due_at->copy()->addMinutes(($this->durationOf($t) ?? $length) + $buffer)]);
        // Busy times from the host's connected Google / Outlook calendar.
        if ($account = ConnectedAccount::calendarFor($this->user_id)) {
            $mailbox = app(Mailbox::class);
            $external = $mailbox->safely($account, fn () => $mailbox->busy($account, $now->copy()->startOfDay(), $now->copy()->addDays($this->days_ahead + 1))) ?? [];
            $busy = $busy->concat(array_map(fn ($b) => [$b[0]->copy()->subMinutes($buffer), $b[1]->copy()->addMinutes($buffer)], $external));
        }

        $slots = [];
        $day = $now->copy()->setTimezone($tz)->startOfDay();
        for ($i = 0; $i <= $this->days_ahead; $i++, $day->addDay()) {
            if (! in_array($day->isoWeekday(), $this->weekdays ?? [], true)) {
                continue;
            }
            [$sh, $sm] = array_map('intval', explode(':', $this->start_time));
            [$eh, $em] = array_map('intval', explode(':', $this->end_time));
            $cursor = $day->copy()->setTime($sh, $sm);
            $end = $day->copy()->setTime($eh, $em);
            while ($cursor->copy()->addMinutes($length)->lessThanOrEqualTo($end)) {
                $slotStart = $cursor->copy()->utc();
                $slotEnd = $slotStart->copy()->addMinutes($length);
                $clash = $busy->contains(fn ($b) => $slotStart->lessThan($b[1]) && $slotEnd->greaterThan($b[0]));
                if ($slotStart->greaterThanOrEqualTo($earliest) && ! $clash) {
                    $slots[$day->toDateString()][] = $slotStart->toIso8601String();
                }
                $cursor->addMinutes($length);
            }
        }

        return $slots;
    }

    private function durationOf(Task $task): ?int
    {
        return preg_match('/\((\d+) min\)/', (string) $task->description, $m) ? (int) $m[1] : null;
    }
}
