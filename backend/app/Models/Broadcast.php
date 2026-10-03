<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['created_by', 'campaign_id', 'name', 'conditions', 'variants', 'test_percent', 'winner_metric', 'winner_after_hours', 'winner_key', 'status', 'scheduled_at', 'started_at', 'winner_at', 'sent_at'])]
class Broadcast extends Model
{
    use BelongsToOrganization;

    protected $attributes = ['status' => 'draft', 'test_percent' => 100, 'winner_metric' => 'open', 'winner_after_hours' => 4];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'variants' => 'array',
            'test_percent' => 'integer',
            'winner_after_hours' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'winner_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function variant(string $key): ?array
    {
        return collect($this->variants)->firstWhere('key', $key);
    }

    public function isAbTest(): bool
    {
        return count($this->variants ?? []) > 1;
    }
}
