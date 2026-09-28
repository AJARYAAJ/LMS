<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'slug', 'title', 'description', 'fields', 'lead_source_id', 'campaign_id', 'tag_ids',
    'submit_label', 'success_message', 'redirect_url', 'accent_color', 'is_active', 'submissions_count',
])]
class WebForm extends Model
{
    use BelongsToOrganization;

    /** Lead attributes a form field may map to. */
    public const FIELD_KEYS = [
        'first_name', 'last_name', 'name', 'email', 'phone', 'company', 'job_title', 'website',
        'city', 'country', 'budget', 'timeline', 'requirements',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'tag_ids' => 'array',
            'is_active' => 'boolean',
            'submissions_count' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
