<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'priority', 'is_active', 'conditions', 'strategy', 'user_ids', 'team_id', 'cursor'])]
class AssignmentRule extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'conditions' => 'array',
            'user_ids' => 'array',
            'priority' => 'integer',
            'cursor' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public const STRATEGIES = ['round_robin', 'least_loaded', 'specific_user'];
}
