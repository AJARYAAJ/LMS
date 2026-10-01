<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['organization_id', 'name', 'email', 'password', 'role', 'phone', 'job_title', 'avatar_color', 'is_active', 'last_login_at', 'preferences'])]
#[Hidden(['password', 'remember_token', 'calendar_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasApiTokens, HasFactory, Notifiable;

    public const ADMIN = 'admin';

    public const MANAGER = 'manager';

    public const SALES_REP = 'sales_rep';

    public const VIEWER = 'viewer';

    public const ROLES = [self::ADMIN, self::MANAGER, self::SALES_REP, self::VIEWER];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'preferences' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'owner_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
    }

    public function isManager(): bool
    {
        return $this->role === self::MANAGER;
    }

    public function canWrite(): bool
    {
        return $this->role !== self::VIEWER;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }
}
