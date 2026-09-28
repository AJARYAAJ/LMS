<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\WebForm;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebFormController extends ResourceController
{
    protected string $model = WebForm::class;

    protected array $with = ['source:id,name,color', 'campaign:id,name'];

    protected function rules(Request $request, ?Model $record = null): array
    {
        $req = $record ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'slug' => ['sometimes', 'string', 'max:80', 'alpha_dash', Rule::unique('web_forms', 'slug')->ignore($record?->id)],
            'title' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'fields' => [$req, 'array', 'min:1', 'max:15'],
            'fields.*.key' => ['required', Rule::in(WebForm::FIELD_KEYS)],
            'fields.*.label' => ['required', 'string', 'max:80'],
            'fields.*.type' => ['required', Rule::in(['text', 'email', 'tel', 'number', 'textarea'])],
            'fields.*.required' => ['sometimes', 'boolean'],
            'lead_source_id' => ['nullable', Rules::exists('lead_sources')],
            'campaign_id' => ['nullable', Rules::exists('campaigns')],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer', Rules::exists('tags')],
            'submit_label' => ['sometimes', 'string', 'max:40'],
            'success_message' => ['sometimes', 'string', 'max:255'],
            'redirect_url' => ['nullable', 'url', 'max:255'],
            'accent_color' => Rules::color(),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (! $record && empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(5));
        }

        $keys = collect($data['fields'] ?? [])->pluck('key');
        if (isset($data['fields']) && ! $keys->contains('email') && ! $keys->contains('phone')) {
            abort(422, 'A form needs an email or phone field.');
        }

        return $data;
    }
}
