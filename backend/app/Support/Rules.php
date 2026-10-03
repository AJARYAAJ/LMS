<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Tenant-aware validation rules. Foreign keys must always be validated
 * against the caller's organization, never globally.
 */
class Rules
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('organization_id', Tenant::id());
    }

    public static function unique(string $table, string $column, mixed $ignore = null): Unique
    {
        return Rule::unique($table, $column)->where('organization_id', Tenant::id())->ignore($ignore);
    }

    public static function color(): array
    {
        return ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
    }
}
