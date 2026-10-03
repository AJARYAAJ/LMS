<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['entity', 'key', 'label', 'type', 'options', 'is_required', 'display_order'])]
class CustomField extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'boolean'];
}
