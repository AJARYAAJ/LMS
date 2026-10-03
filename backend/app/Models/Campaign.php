<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'lead_source_id', 'channel', 'status', 'budget', 'actual_cost', 'starts_on', 'ends_on', 'description'])]
class Campaign extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'budget' => 'decimal:2',
            'actual_cost' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
