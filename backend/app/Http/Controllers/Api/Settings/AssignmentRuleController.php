<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\AssignmentRule;
use App\Services\ConditionEvaluator;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssignmentRuleController extends ResourceController
{
    protected string $model = AssignmentRule::class;

    protected array $with = ['team:id,name'];

    protected string $orderBy = 'priority';

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:120'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'conditions' => ['nullable', 'array'],
            'conditions.*.field' => ['required', 'string', 'max:60'],
            'conditions.*.operator' => ['required', Rule::in(ConditionEvaluator::OPERATORS)],
            'conditions.*.value' => ['nullable', 'string', 'max:190'],
            'strategy' => ['sometimes', Rule::in(AssignmentRule::STRATEGIES)],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', Rules::exists('users')],
            'team_id' => ['nullable', Rules::exists('teams')],
        ];
    }
}
