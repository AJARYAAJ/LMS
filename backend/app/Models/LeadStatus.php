<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'key', 'category', 'color', 'display_order', 'is_active', 'is_default', 'is_terminal', 'required_fields'])]
class LeadStatus extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_terminal' => 'boolean',
            'required_fields' => 'array',
            'display_order' => 'integer',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public const CATEGORIES = ['open', 'qualified', 'converted', 'lost'];
}
