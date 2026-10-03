<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeadStatusController extends ResourceController
{
    protected string $model = LeadStatus::class;

    protected array $withCount = ['leads'];

    protected string $orderBy = 'display_order';

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:60'],
            'key' => ['sometimes', 'string', 'max:60', 'alpha_dash', Rules::unique('lead_statuses', 'key', $record?->id)],
            'category' => ['sometimes', Rule::in(LeadStatus::CATEGORIES)],
            'color' => Rules::color(),
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'is_terminal' => ['sometimes', 'boolean'],
            'required_fields' => ['nullable', 'array'],
            'required_fields.*' => [Rule::in([...Lead::CONDITION_FIELDS, 'first_name', 'last_name', 'lost_reason', 'next_follow_up_at'])],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (! $record && empty($data['key'])) {
            $data['key'] = Str::snake($data['name']);
        }
        if (! $record && ! isset($data['display_order'])) {
            $data['display_order'] = (int) LeadStatus::max('display_order') + 1;
        }

        return $data;
    }

    protected function afterSave(Model $record, Request $request): void
    {
        if ($record->is_default) {
            LeadStatus::whereKeyNot($record->id)->update(['is_default' => false]);
        }
    }

    protected function beforeDelete(Model $record): void
    {
        if (Lead::withTrashed()->where('lead_status_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['status' => 'This status is in use. Move its leads or deactivate it instead.']);
        }
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        foreach ($ids as $order => $id) {
            LeadStatus::whereKey($id)->update(['display_order' => $order]);
        }

        return $this->index($request);
    }
}
