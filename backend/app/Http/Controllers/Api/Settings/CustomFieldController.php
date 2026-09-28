<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\CustomField;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomFieldController extends ResourceController
{
    protected string $model = CustomField::class;

    protected string $orderBy = 'display_order';

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'entity' => ['sometimes', Rule::in(['lead', 'contact', 'account', 'deal'])],
            'label' => [$record ? 'sometimes' : 'required', 'string', 'max:80'],
            'key' => ['sometimes', 'string', 'max:60', 'alpha_dash',
                Rule::unique('custom_fields', 'key')->where('organization_id', Tenant::id())
                    ->where('entity', $request->input('entity', $record?->entity ?? 'lead'))->ignore($record?->id)],
            'type' => ['sometimes', Rule::in(CustomField::TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:80'],
            'is_required' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (! $record && empty($data['key'])) {
            $data['key'] = Str::snake($data['label']);
        }

        return $data;
    }
}
