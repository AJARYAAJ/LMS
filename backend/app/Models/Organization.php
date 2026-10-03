<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'industry', 'website', 'phone', 'timezone', 'currency', 'settings'])]
class Organization extends Model
{
    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public const DEFAULT_QUALIFICATION = [
        ['key' => 'budget', 'label' => 'Budget confirmed'],
        ['key' => 'authority', 'label' => 'Decision maker identified'],
        ['key' => 'need', 'label' => 'Clear business need'],
        ['key' => 'timeline', 'label' => 'Purchase timeline agreed'],
    ];

    /**
     * Qualification checklist (defaults to BANT), configurable per organization.
     *
     * @return list<array{key: string, label: string}>
     */
    public function qualificationCriteria(): array
    {
        return $this->settings['qualification_criteria'] ?? self::DEFAULT_QUALIFICATION;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
