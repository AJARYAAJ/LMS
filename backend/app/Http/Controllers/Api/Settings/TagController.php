<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\Tag;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class TagController extends ResourceController
{
    protected string $model = Tag::class;

    protected array $withCount = ['leads'];

    protected string $orderBy = 'name';

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:40', Rules::unique('tags', 'name', $record?->id)],
            'color' => Rules::color(),
        ];
    }
}
