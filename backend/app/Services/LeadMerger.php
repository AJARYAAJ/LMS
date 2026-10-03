<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Note;
use App\Models\SequenceEnrollment;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Merge a duplicate lead into a primary one. Empty fields on the primary
 * are filled from the duplicate; related records move over; the duplicate
 * is soft-deleted (restorable from the recycle bin).
 */
class LeadMerger
{
    private const MERGEABLE = [
        'last_name', 'email', 'phone', 'company', 'job_title', 'website', 'industry', 'company_size', 'city',
        'state', 'country', 'lead_source_id', 'campaign_id', 'owner_id', 'team_id', 'budget', 'expected_value',
        'timeline', 'requirements', 'next_follow_up_at', 'last_contacted_at',
    ];

    public function __construct(
        private ActivityRecorder $activities,
        private AuditLogger $audit,
        private ScoringEngine $scoring,
    ) {}

    public function merge(Lead $primary, Lead $duplicate): Lead
    {
        if ($primary->is($duplicate)) {
            throw ValidationException::withMessages(['duplicate_id' => 'A lead cannot be merged into itself.']);
        }
        if ($duplicate->isConverted()) {
            throw ValidationException::withMessages(['duplicate_id' => 'Converted leads cannot be merged away.']);
        }

        DB::transaction(function () use ($primary, $duplicate) {
            $filled = [];
            foreach (self::MERGEABLE as $field) {
                if (blank($primary->{$field}) && filled($duplicate->{$field})) {
                    $filled[$field] = $duplicate->{$field};
                }
            }
            $primary->forceFill(array_merge($filled, [
                'custom_fields' => array_merge($duplicate->custom_fields ?? [], $primary->custom_fields ?? []) ?: null,
                'qualification' => array_merge($duplicate->qualification ?? [], $primary->qualification ?? []) ?: null,
            ]))->save();

            $moved = ['subject_type' => 'lead', 'subject_id' => $duplicate->id];
            Activity::where($moved)->update(['subject_id' => $primary->id]);
            Note::where(['notable_type' => 'lead', 'notable_id' => $duplicate->id])->update(['notable_id' => $primary->id]);
            Task::where(['taskable_type' => 'lead', 'taskable_id' => $duplicate->id])->update(['taskable_id' => $primary->id]);
            SequenceEnrollment::where('lead_id', $duplicate->id)->update(['lead_id' => $primary->id]);
            $primary->tags()->syncWithoutDetaching($duplicate->tags()->pluck('tags.id'));

            $this->activities->record($primary, 'system', "Merged with {$duplicate->full_name}", [
                'description' => $filled ? 'Filled: '.implode(', ', array_keys($filled)) : 'No empty fields to fill',
                'meta' => ['merged_lead_id' => $duplicate->id],
            ]);
            $this->audit->log('lead.merged', $primary, ['merged_lead_id' => $duplicate->id], $filled);

            $duplicate->delete();
            $this->scoring->recalculate($primary);
        });

        return $primary->fresh();
    }
}
