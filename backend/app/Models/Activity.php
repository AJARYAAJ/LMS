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
}
