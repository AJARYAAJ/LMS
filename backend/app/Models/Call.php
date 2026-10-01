<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id', 'lead_id', 'ai_agent_id', 'user_id', 'campaign_key', 'direction', 'provider', 'provider_call_id', 'to_number',
    'status', 'started_at', 'ended_at', 'duration_seconds', 'recording_url', 'transcript', 'summary', 'outcome', 'sentiment',
    'extracted', 'cost', 'error',
])]
class Call extends Model
{
    use BelongsToOrganization;

    public const FINAL = ['completed', 'no_answer', 'voicemail', 'failed', 'canceled'];

    public const OUTCOMES = ['interested', 'not_interested', 'callback', 'meeting_booked', 'wrong_number', 'voicemail', 'no_answer', 'transferred'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'transcript' => 'array',
            'extracted' => 'array',
            'duration_seconds' => 'integer',
            'cost' => 'decimal:4',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }
}
