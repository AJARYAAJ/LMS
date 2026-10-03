<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\Sequence;
use App\Models\Task;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SequenceController extends ResourceController
{
    protected string $model = Sequence::class;

    protected string $orderBy = 'name';

    protected function query(Request $request): Builder
    {
        return parent::query($request)->withCount([
            'enrollments as active_enrollments_count' => fn ($q) => $q->where('status', 'active'),
            'enrollments as completed_enrollments_count' => fn ($q) => $q->where('status', 'completed'),
        ]);
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        $req = $record ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => [$req, 'array', 'min:1', 'max:20'],
            'steps.*.day_offset' => ['required', 'integer', 'between:0,365'],
            'steps.*.type' => ['required', Rule::in(Task::TYPES)],
            'steps.*.title' => ['required', 'string', 'max:120'],
            'steps.*.email_template_id' => ['nullable', Rules::exists('email_templates')],
        ];
    }
}
