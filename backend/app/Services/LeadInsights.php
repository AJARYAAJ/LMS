<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Deterministic "smart insights" and next-best-action for a lead.
 * This is the AI-ready seam: an LLM-backed implementation can replace
 * or augment these heuristics without changing the API contract.
 */
class LeadInsights
{
    /**
     * @return array{summary: string, next_action: array{title: string, type: string, reason: string}, signals: list<array{tone: string, text: string}>, qualification: array{percent: int, missing: list<string>}}
     */
    public function for(Lead $lead): array
    {
        $lead->loadMissing(['status', 'source', 'owner']);
        $signals = [];
        $now = now();

        $lastTouch = $lead->last_contacted_at;
        $daysSinceTouch = $lastTouch ? (int) $lastTouch->diffInDays($now) : null;
        $age = (int) $lead->created_at->diffInDays($now);
        $openTasks = $lead->tasks()->whereNull('completed_at')->count();
        $overdueTasks = $lead->tasks()->whereNull('completed_at')->where('due_at', '<', $now)->count();
        $activityCount = $lead->activities()->where('type', '!=', 'system')->count();
        $qualification = $this->qualification($lead);

        if ($lead->score > 60) {
            $signals[] = ['tone' => 'positive', 'text' => "High intent — score {$lead->score}/100."];
        }
        if (! $lead->owner_id) {
            $signals[] = ['tone' => 'warning', 'text' => 'No owner yet — this lead is waiting in the queue.'];
        }
        if ($daysSinceTouch === null && $age >= 1) {
            $signals[] = ['tone' => 'warning', 'text' => "Never contacted since it arrived {$age} day(s) ago."];
        } elseif ($daysSinceTouch !== null && $daysSinceTouch > 14 && ! $lead->isConverted()) {
            $signals[] = ['tone' => 'negative', 'text' => "Going cold — last touch {$daysSinceTouch} days ago."];
        }
        if ($lead->next_follow_up_at && $lead->next_follow_up_at->isPast() && ! $lead->isConverted()) {
            $signals[] = ['tone' => 'negative', 'text' => 'Follow-up is overdue.'];
        }
        if ($overdueTasks) {
            $signals[] = ['tone' => 'negative', 'text' => "{$overdueTasks} overdue task(s)."];
        }
        if ($lead->budget && (float) $lead->budget >= 10000) {
            $signals[] = ['tone' => 'positive', 'text' => 'Budget confirmed above 10k.'];
        }
        if ($qualification['percent'] === 100 && $lead->status?->category === 'open') {
            $signals[] = ['tone' => 'positive', 'text' => 'Qualification checklist complete — ready to qualify.'];
        }

        return [
            'summary' => $this->summary($lead, $age, $activityCount, $daysSinceTouch),
            'next_action' => $this->nextAction($lead, $daysSinceTouch, $openTasks, $overdueTasks, $qualification),
            'signals' => $signals,
            'qualification' => $qualification,
            'prediction' => app(ConversionPredictor::class)->predict($lead),
        ];
    }

    public function qualification(Lead $lead): array
    {
        $criteria = $lead->organization?->qualificationCriteria() ?? [];
        $answers = $lead->qualification ?? [];
        $missing = collect($criteria)->reject(fn ($c) => ! empty($answers[$c['key']]))->pluck('label')->values()->all();

        return [
            'percent' => $criteria ? (int) round((count($criteria) - count($missing)) / count($criteria) * 100) : 0,
            'missing' => $missing,
        ];
    }

    private function summary(Lead $lead, int $age, int $activityCount, ?int $daysSinceTouch): string
    {
        $who = $lead->job_title ? "{$lead->full_name}, {$lead->job_title}" : $lead->full_name;
        $where = $lead->company ? " at {$lead->company}" : '';
        $source = $lead->source ? " came in via {$lead->source->name}" : ' was added';
        $status = $lead->status ? " and is currently <{$lead->status->name}>" : '';
        $touch = $activityCount
            ? " There have been {$activityCount} interaction(s)".($daysSinceTouch !== null ? ", the latest {$daysSinceTouch} day(s) ago." : '.')
            : ' No interactions have been logged yet.';
        $value = $lead->expected_value ? ' Expected value: '.number_format((float) $lead->expected_value).'.' : '';

        return str_replace(['<', '>'], '', "{$who}{$where}{$source} {$age} day(s) ago{$status}.{$touch}{$value}");
    }

    private function nextAction(Lead $lead, ?int $daysSinceTouch, int $openTasks, int $overdueTasks, array $qualification): array
    {
        if ($lead->isConverted()) {
            return ['title' => 'Work the deal', 'type' => 'deal', 'reason' => 'This lead has been converted — continue in the pipeline.'];
        }
        if ($lead->status?->category === 'lost') {
            return ['title' => 'Add to a nurture sequence', 'type' => 'sequence', 'reason' => 'Lost leads often come back — keep them warm.'];
        }
        if (! $lead->owner_id) {
            return ['title' => 'Assign an owner', 'type' => 'assign', 'reason' => 'Unassigned leads convert far less often.'];
        }
        if ($overdueTasks) {
            return ['title' => 'Clear overdue tasks', 'type' => 'task', 'reason' => 'Promised follow-ups are late.'];
        }
        if ($daysSinceTouch === null) {
            return ['title' => $lead->phone ? 'Make the first call' : 'Send an intro email', 'type' => $lead->phone ? 'call' : 'email', 'reason' => 'Speed-to-lead: first contact within an hour lifts conversion.'];
        }
        if ($qualification['missing'] && $lead->status?->category === 'open') {
            return ['title' => 'Confirm '.strtolower($qualification['missing'][0]), 'type' => 'qualify', 'reason' => 'Complete the qualification checklist before qualifying.'];
        }
        if ($lead->status?->category === 'qualified') {
            return ['title' => 'Convert to a deal', 'type' => 'convert', 'reason' => 'Qualified leads should move into the pipeline.'];
        }
        if ($daysSinceTouch > 7 && ! $openTasks) {
            return ['title' => 'Re-engage with a check-in', 'type' => 'email', 'reason' => "No contact for {$daysSinceTouch} days and nothing scheduled."];
        }

        return ['title' => 'Schedule the next follow-up', 'type' => 'task', 'reason' => 'Keep momentum with a clear next step.'];
    }
}
