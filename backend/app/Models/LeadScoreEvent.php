<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'scoring_rule_id', 'points', 'reason'])]
class LeadScoreEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(ScoringRule::class, 'scoring_rule_id');
    }
}
