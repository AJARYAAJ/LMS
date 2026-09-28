<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebForm;
use App\Services\LeadService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hosted / embeddable web-to-lead forms. Public, identified by slug.
 */
class PublicFormController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $form = $this->find($slug);

        return response()->json(['data' => $form->only([
            'name', 'slug', 'title', 'description', 'fields', 'submit_label', 'success_message', 'redirect_url', 'accent_color',
        ]) + ['organization' => $form->organization->name]]);
    }

    public function submit(Request $request, string $slug, LeadService $leads): JsonResponse
    {
        $form = $this->find($slug);

        if ($request->filled('_hp')) {
            return response()->json(['message' => $form->success_message], 202);
        }

        $rules = [];
        foreach ($form->fields as $field) {
            $rules[$field['key']] = array_filter([
                ! empty($field['required']) ? 'required' : 'nullable',
                match ($field['type'] ?? 'text') {
                    'email' => 'email',
                    'number' => 'numeric',
                    default => 'string',
                },
                ($field['type'] ?? 'text') === 'textarea' ? 'max:5000' : 'max:190',
            ]);
        }
        $data = $request->validate($rules);

        if (empty($data['first_name']) && ! empty($data['name'])) {
            [$data['first_name'], $data['last_name']] = array_pad(preg_split('/\s+/', trim($data['name']), 2), 2, null);
        }
        unset($data['name']);
        $data['first_name'] ??= $data['email'] ?? 'Web visitor';

        $lead = Tenant::run($form->organization_id, function () use ($form, $data, $leads) {
            $lead = $leads->create(array_filter([
                ...array_intersect_key($data, array_flip(WebForm::FIELD_KEYS)),
                'lead_source_id' => $form->lead_source_id,
                'campaign_id' => $form->campaign_id,
                'tag_ids' => $form->tag_ids ?: null,
                'custom_fields' => ['web_form' => $form->name],
            ], fn ($v) => $v !== null && $v !== ''), null, 'web_form');

            $form->increment('submissions_count');

            return $lead;
        });

        return response()->json(['message' => $form->success_message, 'redirect_url' => $form->redirect_url, 'id' => $lead->id], 201);
    }

    private function find(string $slug): WebForm
    {
        return WebForm::withoutGlobalScopes()->with('organization:id,name')->where('slug', $slug)->where('is_active', true)->firstOrFail();
    }
}
