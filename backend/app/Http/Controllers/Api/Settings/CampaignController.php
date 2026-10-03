<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\Campaign;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CampaignController extends ResourceController
{
    protected string $model = Campaign::class;

    protected array $with = ['source:id,name,color'];

    protected string $orderBy = 'name';

    protected function query(Request $request): Builder
    {
        return parent::query($request)->withCount([
            'leads',
            'leads as converted_count' => fn ($q) => $q->whereNotNull('converted_at'),
        ])->withSum('leads as pipeline_value', 'expected_value');
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:120'],
            'lead_source_id' => ['nullable', Rules::exists('lead_sources')],
            'channel' => ['nullable', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(['planned', 'active', 'paused', 'completed'])],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
