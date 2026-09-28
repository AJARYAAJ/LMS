<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class EmailTemplateController extends ResourceController
{
    protected string $model = EmailTemplate::class;

    protected array $with = ['creator:id,name'];

    protected string $orderBy = 'name';

    protected function rules(Request $request, ?Model $record = null): array
    {
        $req = $record ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'max:40'],
            'subject' => [$req, 'string', 'max:190'],
            'body' => [$req, 'string', 'max:20000'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (! $record) {
            $data['created_by'] = $request->user()->id;
        }

        return $data;
    }
}
