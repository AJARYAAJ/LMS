<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'sku', 'description', 'unit_price', 'billing', 'is_active'])]
class Product extends Model
{
    use BelongsToOrganization;

    public const BILLING = ['one_time', 'monthly', 'yearly'];

    protected $attributes = ['billing' => 'one_time', 'is_active' => true];

    protected function casts(): array
    {
        return ['unit_price' => 'float', 'is_active' => 'boolean'];
    }
}
