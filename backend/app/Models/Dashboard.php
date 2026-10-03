<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'name', 'is_shared', 'tiles'])]
class Dashboard extends Model
{
    use BelongsToOrganization;

    public const TILE_KINDS = ['report', 'spec', 'kpis', 'goals'];

    protected $attributes = ['is_shared' => false, 'tiles' => '[]'];

    protected function casts(): array
    {
        return ['tiles' => 'array', 'is_shared' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
