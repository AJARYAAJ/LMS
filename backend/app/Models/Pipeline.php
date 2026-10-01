<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_default', 'display_order'])]
class Pipeline extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['is_default' => false, 'display_order' => 0];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'display_order' => 'integer'];
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class)->orderBy('display_order');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** The organization's default pipeline (created on demand for older data). */
    public static function default(): self
    {
        return static::where('is_default', true)->first()
            ?? static::orderBy('display_order')->first()
            ?? static::create(['name' => 'Sales', 'is_default' => true]);
    }
}
