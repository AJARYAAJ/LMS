<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\ScoringRule;
use Illuminate\Support\Facades\DB;

/**
 * Rule-based lead scoring. Each matched rule is stored as a score event so
 * the resulting score stays explainable and auditable.
 */
class ScoringEngine
{
    public function __construct(private ConditionEvaluator $evaluator) {}

    public function recalculate(Lead $lead): Lead
    {
        $rules = ScoringRule::where('organization_id', $lead->organization_id)
            ->where('is_active', true)
            ->get();

        $data = $this->evaluator->leadData($lead);

        DB::transaction(function () use ($lead, $rules, $data) {
            $lead->scoreEvents()->whereNotNull('scoring_rule_id')->delete();

            foreach ($rules as $rule) {
                $condition = ['field' => $rule->field, 'operator' => $rule->operator, 'value' => $rule->value];

                if ($this->evaluator->matches($condition, $data)) {
                    $lead->scoreEvents()->create([
                        'scoring_rule_id' => $rule->id,
                        'points' => $rule->points,
                        'reason' => $rule->name,
                        'created_at' => now(),
                    ]);
                }
            }

            $score = max(0, min(100, (int) $lead->scoreEvents()->sum('points')));

            $lead->forceFill([
                'score' => $score,
                'rating' => Lead::ratingForScore($score),
            ])->saveQuietly();
        });

        return $lead;
    }

    /**
     * Manual adjustment (e.g. "Requested demo" logged by a rep).
     */
    public function adjust(Lead $lead, int $points, string $reason): Lead
    {
        $lead->scoreEvents()->create([
            'points' => $points,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        return $this->recalculate($lead);
    }
}
