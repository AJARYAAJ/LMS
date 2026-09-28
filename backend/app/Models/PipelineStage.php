<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'probability', 'display_order', 'color', 'is_won', 'is_lost'])]
class PipelineStage extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'probability' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
