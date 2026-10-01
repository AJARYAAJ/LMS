<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['organization_id', 'subject_type', 'subject_id', 'user_id', 'type', 'title', 'description', 'direction', 'outcome', 'duration_minutes', 'occurred_at', 'meta'])]
class Activity extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'meta' => 'array',
            'duration_minutes' => 'integer',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public const TYPES = ['call', 'email', 'meeting', 'sms', 'whatsapp', 'note', 'task', 'system'];

    /** Activity types that count as reaching out to a lead (speed-to-lead). */
    public const TOUCHES = ['call', 'email', 'meeting', 'sms', 'whatsapp'];

    protected static function booted(): void
    {
        // Remember when a lead was first contacted, whichever path logged the touch.
        static::created(function (Activity $activity) {
            if ($activity->subject_type !== 'lead' || ! in_array($activity->type, self::TOUCHES, true) || $activity->direction === 'inbound') {
                return;
            }
            $at = $activity->occurred_at ?? now();
            Lead::withoutGlobalScopes()->withTrashed()->whereKey($activity->subject_id)
                ->where(fn ($q) => $q->whereNull('first_responded_at')->orWhere('first_responded_at', '>', $at))
                ->update(['first_responded_at' => $at]);
        });
    }
}
