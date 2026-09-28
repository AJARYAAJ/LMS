<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\PipelineStage;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class PipelineStageController extends ResourceController
{
    protected string $model = PipelineStage::class;

    protected array $withCount = ['deals'];

    protected string $orderBy = 'display_order';

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:60'],
            'probability' => ['sometimes', 'integer', 'between:0,100'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'color' => Rules::color(),
            'is_won' => ['sometimes', 'boolean'],
            'is_lost' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (! $record && ! isset($data['display_order'])) {
            $data['display_order'] = (int) PipelineStage::max('display_order') + 1;
        }

        return $data;
    }
}
