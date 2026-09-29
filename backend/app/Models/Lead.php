<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'website', 'industry',
    'company_size', 'city', 'state', 'country', 'lead_status_id', 'lead_source_id', 'campaign_id',
    'owner_id', 'team_id', 'created_by', 'priority', 'score', 'rating', 'budget', 'expected_value',
    'timeline', 'requirements', 'qualification', 'custom_fields', 'next_follow_up_at',
    'last_contacted_at', 'assigned_at', 'qualified_at', 'converted_at', 'converted_contact_id',
    'converted_account_id', 'converted_deal_id', 'lost_reason',
])]
class Lead extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public const RATINGS = ['cold', 'warm', 'hot', 'very_high'];

    /** Fields that may be referenced by scoring / assignment / automation conditions. */
    public const CONDITION_FIELDS = [
        'email', 'phone', 'company', 'job_title', 'website', 'industry', 'company_size', 'city',
        'state', 'country', 'lead_status_id', 'lead_source_id', 'campaign_id', 'owner_id', 'team_id',
        'priority', 'score', 'rating', 'budget', 'expected_value', 'timeline', 'requirements',
    ];

    protected $appends = ['full_name'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'budget' => 'decimal:2',
            'expected_value' => 'decimal:2',
            'qualification' => 'array',
            'custom_fields' => 'array',
            'next_follow_up_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'assigned_at' => 'datetime',
            'qualified_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'lead_status_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class, 'lead_source_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(LeadStatusHistory::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class);
    }

    public function scoreEvents(): HasMany
    {
        return $this->hasMany(LeadScoreEvent::class);
    }

    public function convertedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'converted_contact_id');
    }

    public function convertedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'converted_account_id');
    }

    public function convertedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'converted_deal_id');
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    /**
     * Record-level visibility: admins and viewers see every lead in the
     * organization, managers see their teams' leads, their own and the
     * unassigned queue, and sales reps see only the leads they own.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole(User::ADMIN, User::VIEWER)) {
            return $query;
        }

        if ($user->isManager()) {
            $teamIds = $user->teams()->pluck('teams.id');

            return $query->where(function (Builder $q) use ($user, $teamIds) {
                $q->where('owner_id', $user->id)
                    ->orWhereNull('owner_id')
                    ->orWhereIn('team_id', $teamIds)
                    ->orWhereIn('owner_id', function ($sub) use ($teamIds) {
                        $sub->select('user_id')->from('team_user')->whereIn('team_id', $teamIds);
                    });
            });
        }

        return $query->where('owner_id', $user->id);
    }

    public static function ratingForScore(int $score): string
    {
        return match (true) {
            $score > 80 => 'very_high',
            $score > 60 => 'hot',
            $score > 30 => 'warm',
            default => 'cold',
        };
    }
}
