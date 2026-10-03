<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['created_by', 'campaign_id', 'web_form_id', 'name', 'slug', 'is_published', 'blocks', 'accent_color', 'seo_title', 'seo_description'])]
class LandingPage extends Model
{
    use BelongsToOrganization;

    /** Building blocks and the text fields each one has. */
    public const BLOCKS = [
        'hero' => ['heading', 'subheading', 'button_label', 'image_url'],
        'text' => ['heading', 'body'],
        'features' => ['heading', 'items'],
        'testimonial' => ['quote', 'author', 'role'],
        'form' => ['heading', 'body'],
        'cta' => ['heading', 'body', 'button_label'],
    ];

    protected $attributes = ['is_published' => false, 'accent_color' => '#7c3aed'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'is_published' => 'boolean', 'views' => 'integer', 'submissions' => 'integer'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(WebForm::class, 'web_form_id');
    }

    public function url(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/p/'.$this->slug;
    }
}
