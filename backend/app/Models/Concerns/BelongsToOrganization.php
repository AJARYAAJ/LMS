<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enforces the tenant boundary: every query is scoped to the current
 * organization and new records are stamped with it automatically.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            if (Tenant::check()) {
                $builder->where($builder->qualifyColumn('organization_id'), Tenant::id());
            }
        });

        static::creating(function ($model) {
            if (! $model->organization_id && Tenant::check()) {
                $model->organization_id = Tenant::id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
