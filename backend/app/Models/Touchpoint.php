<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One marketing interaction with a lead, used for first / last / linear attribution. */
#[Fillable(['organization_id', 'lead_id', 'campaign_id', 'lead_source_id', 'channel', 'detail', 'occurred_at'])]
class Touchpoint extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public static function record(Lead $lead, string $channel, ?int $campaignId = null, ?int $sourceId = null, ?string $detail = null): self
    {
        return static::create([
            'organization_id' => $lead->organization_id, 'lead_id' => $lead->id,
            'campaign_id' => $campaignId, 'lead_source_id' => $sourceId,
            'channel' => $channel, 'detail' => $detail ? mb_substr($detail, 0, 190) : null, 'occurred_at' => now(),
        ]);
    }
}
