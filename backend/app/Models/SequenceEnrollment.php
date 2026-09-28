<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'sequence_id', 'lead_id', 'enrolled_by', 'status', 'completed_at'])]
class SequenceEnrollment extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
