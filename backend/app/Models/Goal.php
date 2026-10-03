<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'created_by', 'metric', 'period', 'target', 'achieved_for'])]
class Goal extends Model
{
    use BelongsToOrganization;

    public const PERIODS = ['month', 'quarter'];

    protected $attributes = ['period' => 'month'];

    protected function casts(): array
    {
        return ['target' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
