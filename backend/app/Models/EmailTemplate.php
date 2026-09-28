<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'category', 'subject', 'body', 'created_by', 'usage_count'])]
class EmailTemplate extends Model
{
    use BelongsToOrganization;

    protected function casts(): array
    {
        return ['usage_count' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
