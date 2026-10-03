<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['created_by', 'campaign_id', 'code', 'label', 'destination', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'])]
class TrackedLink extends Model
{
    use BelongsToOrganization;

    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    protected function casts(): array
    {
        return ['last_clicked_at' => 'datetime', 'clicks' => 'integer'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** The destination with the UTM tags added (existing query parameters are kept). */
    public function taggedUrl(): string
    {
        $parts = parse_url($this->destination);
        parse_str($parts['query'] ?? '', $query);
        $query = [...$query, ...array_filter($this->only(self::UTM))];
        $base = strtok($this->destination, '?#');
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $base.($query ? '?'.http_build_query($query) : '').$fragment;
    }

    public function shortUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/l/'.$this->code;
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'short_url' => $this->shortUrl(), 'tagged_url' => $this->taggedUrl()];
    }
}
