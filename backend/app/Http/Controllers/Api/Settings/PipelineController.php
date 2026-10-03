<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PipelineController extends ResourceController
{
    protected string $model = Pipeline::class;

    protected array $withCount = ['deals', 'stages'];

    protected string $orderBy = 'display_order';

    /** Stages a new pipeline starts with. */
    private const STARTER_STAGES = [['Qualification', 10, '#64748b'], ['Proposal', 40, '#8b5cf6'], ['Negotiation', 70, '#f59e0b'], ['Won', 100, '#10b981', true], ['Lost', 0, '#ef4444', false, true]];

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:60'],
            'is_default' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (! $record && ! isset($data['display_order'])) {
            $data['display_order'] = (int) Pipeline::max('display_order') + 1;
        }

        return $data;
    }

    protected function afterSave(Model $record, Request $request): void
    {
        if ($record->is_default) {
            Pipeline::whereKeyNot($record->id)->update(['is_default' => false]);
        }
        if ($record->wasRecentlyCreated) {
            foreach (self::STARTER_STAGES as $i => $s) {
                PipelineStage::create(['pipeline_id' => $record->id, 'name' => $s[0], 'probability' => $s[1], 'color' => $s[2], 'display_order' => $i, 'is_won' => $s[3] ?? false, 'is_lost' => $s[4] ?? false]);
            }
        }
    }

    protected function beforeDelete(Model $record): void
    {
        if ($record->is_default) {
            throw ValidationException::withMessages(['pipeline' => 'Make another pipeline the default before deleting this one.']);
        }
        if (Deal::withTrashed()->where('pipeline_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['pipeline' => 'This pipeline still has deals. Move them first.']);
        }
    }
}
