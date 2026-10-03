<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'category', 'provider', 'config', 'inbound_token', 'is_active', 'status', 'last_error', 'last_tested_at'])]
#[Hidden(['config', 'inbound_token'])]
class Integration extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (Integration $i) => $i->inbound_token ??= Str::random(40));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
