<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['organization_id', 'name', 'key_hash', 'prefix', 'last_used_at'])]
class ApiKey extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    protected $hidden = ['key_hash'];
}
