<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadences: enrolling a lead materialises every step of the sequence as a
 * dated task for the lead owner. Stopping removes the steps not yet done.
 */
class SequenceService
{
    public function __construct(private ActivityRecorder $activities) {}

    public function enroll(Sequence $sequence, Lead $lead, ?User $actor): SequenceEnrollment
    {
        if (! $sequence->is_active) {
            throw ValidationException::withMessages(['sequence_id' => 'This sequence is inactive.']);
        }

        if ($lead->enrollments()->where('sequence_id', $sequence->id)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['sequence_id' => 'The lead is already enrolled in this sequence.']);
        }

        return DB::transaction(function () use ($sequence, $lead, $actor) {
            $enrollment = SequenceEnrollment::create([
                'organization_id' => $lead->organization_id,
                'sequence_id' => $sequence->id,
                'lead_id' => $lead->id,
                'enrolled_by' => $actor?->id,
            ]);

            foreach ($sequence->steps as $i => $step) {
                Task::create([
                    'organization_id' => $lead->organization_id,
                    'taskable_type' => $lead->getMorphClass(),
                    'taskable_id' => $lead->id,
                    'sequence_enrollment_id' => $enrollment->id,
                    'email_template_id' => $step['email_template_id'] ?? null,
                    'assigned_to' => $lead->owner_id ?? $actor?->id,
                    'created_by' => $actor?->id,
                    'title' => ($i + 1).'. '.($step['title'] ?? ucfirst($step['type'] ?? 'Follow up')),
                    'type' => in_array($step['type'] ?? '', Task::TYPES, true) ? $step['type'] : 'follow_up',
                    'priority' => 'medium',
                    'due_at' => now()->addDays((int) ($step['day_offset'] ?? 0))->setTime(10, 0),
                    'description' => 'Step '.($i + 1)." of sequence \"{$sequence->name}\"",
                ]);
            }

            $first = $enrollment->tasks()->orderBy('due_at')->first();
            if ($first && (! $lead->next_follow_up_at || $first->due_at->lt($lead->next_follow_up_at))) {
                $lead->forceFill(['next_follow_up_at' => $first->due_at])->saveQuietly();
            }

            $this->activities->record($lead, 'system', "Enrolled in sequence \"{$sequence->name}\"", [
                'description' => count($sequence->steps).' steps scheduled',
            ]);

            return $enrollment;
        });
    }

    public function stop(SequenceEnrollment $enrollment, string $reason = 'Stopped manually'): void
    {
        DB::transaction(function () use ($enrollment, $reason) {
            $enrollment->tasks()->whereNull('completed_at')->delete();
            $enrollment->update(['status' => 'stopped', 'completed_at' => now()]);
            $this->activities->record($enrollment->lead, 'system', "Sequence \"{$enrollment->sequence->name}\" stopped", ['description' => $reason]);
        });
    }

    /**
     * Mark enrollments whose steps are all done as completed.
     */
    public function refresh(SequenceEnrollment $enrollment): void
    {
        if ($enrollment->status === 'active' && ! $enrollment->tasks()->whereNull('completed_at')->exists()) {
            $enrollment->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }
}
