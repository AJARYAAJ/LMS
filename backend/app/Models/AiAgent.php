<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['mode', 'name', 'integration_id', 'goal', 'first_message', 'voice', 'language', 'questions', 'max_duration_seconds', 'is_active'])]
class AiAgent extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return ['questions' => 'array', 'is_active' => 'boolean', 'max_duration_seconds' => 'integer'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }
}
