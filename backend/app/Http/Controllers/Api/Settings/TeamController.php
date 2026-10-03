<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\Team;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class TeamController extends ResourceController
{
    protected string $model = Team::class;

    protected array $with = ['members:id,name,email,role,avatar_color', 'manager:id,name'];

    protected array $withCount = ['leads'];

    protected string $orderBy = 'name';

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'manager_id' => ['nullable', Rules::exists('users')],
            'color' => Rules::color(),
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', Rules::exists('users')],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        unset($data['member_ids']);

        return $data;
    }

    protected function afterSave(Model $record, Request $request): void
    {
        if ($request->has('member_ids')) {
            $record->members()->sync($request->input('member_ids', []));
        }
    }
}
