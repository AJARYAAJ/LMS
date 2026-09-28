<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['organization_id', 'taskable_type', 'taskable_id', 'assigned_to', 'created_by', 'title', 'description', 'type', 'priority', 'due_at', 'reminder_at', 'reminded_at', 'completed_at'])]
class Task extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'reminder_at' => 'datetime',
            'reminded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public const TYPES = ['call', 'email', 'meeting', 'follow_up', 'todo'];

    public function isOverdue(): bool
    {
        return ! $this->completed_at && $this->due_at && $this->due_at->isPast();
    }
}
