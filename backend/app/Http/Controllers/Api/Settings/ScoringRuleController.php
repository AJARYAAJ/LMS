<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\Lead;
use App\Models\ScoringRule;
use App\Services\ConditionEvaluator;
use App\Services\ScoringEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScoringRuleController extends ResourceController
{
    protected string $model = ScoringRule::class;

    protected function rules(Request $request, ?Model $record = null): array
    {
        $req = $record ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'field' => [$req, 'string', 'max:60'],
            'operator' => [$req, Rule::in(ConditionEvaluator::OPERATORS)],
            'value' => ['nullable', 'string', 'max:190'],
            'points' => [$req, 'integer', 'between:-100,100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Re-score every open lead after the rules changed.
     */
    public function recalculate(ScoringEngine $scoring): JsonResponse
    {
        $count = 0;
        Lead::whereNull('converted_at')->chunkById(200, function ($leads) use ($scoring, &$count) {
            foreach ($leads as $lead) {
                $scoring->recalculate($lead);
                $count++;
            }
        });

        return response()->json(['message' => "Re-scored {$count} leads.", 'count' => $count]);
    }
}
