<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\AutomationRule;
use App\Services\ConditionEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AutomationRuleController extends ResourceController
{
    protected string $model = AutomationRule::class;

    protected function rules(Request $request, ?Model $record = null): array
    {
        $req = $record ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'trigger' => [$req, Rule::in(AutomationRule::TRIGGERS)],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['required', 'string', 'max:60'],
            'conditions.*.operator' => ['required', Rule::in(ConditionEvaluator::OPERATORS)],
            'conditions.*.value' => ['nullable', 'string', 'max:190'],
            'actions' => [$req, 'array', 'min:1'],
            'actions.*.type' => ['required', Rule::in(AutomationRule::ACTIONS)],
            'actions.*.params' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function executions(int $id): JsonResponse
    {
        $rule = AutomationRule::findOrFail($id);

        return response()->json([
            'data' => $rule->executions()->with('lead:id,first_name,last_name')->latest('id')->limit(50)->get(),
        ]);
    }
}
