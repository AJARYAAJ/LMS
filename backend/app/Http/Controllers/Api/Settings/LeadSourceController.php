<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\LeadSource;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeadSourceController extends ResourceController
{
    protected string $model = LeadSource::class;

    protected array $withCount = ['leads'];

    protected string $orderBy = 'name';

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:60'],
            'key' => ['sometimes', 'string', 'max:60', 'alpha_dash', Rules::unique('lead_sources', 'key', $record?->id)],
            'color' => Rules::color(),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (! $record && empty($data['key'])) {
            $data['key'] = Str::snake($data['name']);
        }

        return $data;
    }
}
