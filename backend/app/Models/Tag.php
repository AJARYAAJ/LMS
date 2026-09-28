<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['organization_id', 'name', 'color'])]
class Tag extends Model
{
    use BelongsToOrganization;

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class);
    }
}
