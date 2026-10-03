<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Security\FieldPermissions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'account_id', 'contact_id', 'lead_id', 'pipeline_stage_id', 'pipeline_id', 'owner_id', 'amount', 'currency', 'probability', 'forecast_category', 'forecast_override', 'expected_close_date', 'status', 'closed_at', 'lost_reason', 'description', 'custom_fields'])]
class Deal extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const FORECAST = ['pipeline', 'best_case', 'commit', 'closed', 'omitted'];

    protected static function booted(): void
    {
        static::saving(function (Deal $deal) {
            // The stage decides the pipeline.
            if ($deal->isDirty('pipeline_stage_id') && $deal->pipeline_stage_id) {
                $deal->pipeline_id = PipelineStage::whereKey($deal->pipeline_stage_id)->value('pipeline_id') ?? $deal->pipeline_id;
            }
            // Forecast category follows status and probability unless someone set it by hand.
            if (in_array($deal->status, ['won', 'lost'], true)) {
                $deal->forecast_category = $deal->status === 'won' ? 'closed' : 'omitted';
                $deal->forecast_override = false;
            } elseif (! $deal->forecast_override) {
                $deal->forecast_category = self::categoryFor((int) $deal->probability);
            }
        });
    }

    /** Hidden fields never leave the server for roles that can't see them. */
    public function toArray(): array
    {
        return FieldPermissions::strip('deal', parent::toArray());
    }

    public static function categoryFor(int $probability): string
    {
        return $probability >= 70 ? 'commit' : ($probability >= 40 ? 'best_case' : 'pipeline');
    }

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'amount' => 'decimal:2',
            'probability' => 'integer',
            'expected_close_date' => 'date',
            'closed_at' => 'datetime',
            'forecast_override' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->latest();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }
}
