<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['user_id', 'name', 'description', 'spec', 'is_shared', 'pinned', 'schedule', 'recipients', 'post_to_chat', 'last_sent_at'])]
class SavedReport extends Model
{
    use BelongsToOrganization;

    public const SCHEDULES = ['none', 'weekly', 'monthly'];

    protected $attributes = ['schedule' => 'none', 'is_shared' => false, 'pinned' => false];

    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'recipients' => 'array',
            'is_shared' => 'boolean',
            'pinned' => 'boolean',
            'post_to_chat' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Is a scheduled send due at this moment (weekly on Mondays, monthly on the 1st)? */
    public function isDue(?\DateTimeInterface $now = null): bool
    {
        $now = Carbon::instance($now ?? now());
        $due = match ($this->schedule) {
            'weekly' => $now->isMonday(),
            'monthly' => $now->day === 1,
            default => false,
        };

        return $due && (! $this->last_sent_at || ! $this->last_sent_at->isSameDay($now));
    }
}
