<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\WebForm;
use App\Support\Tenant;
use Illuminate\Http\Request;

/** Turns a web form submission (hosted form, embed or landing page) into a lead. */
class WebFormSubmitter
{
    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    public function __construct(private LeadService $leads) {}

    /**
     * @param  array{campaign_id?: ?int, custom_fields?: array, channel?: string}  $context
     */
    public function submit(WebForm $form, Request $request, array $context = []): Lead
    {
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
        foreach (self::UTM as $utm) {
            $rules[$utm] = ['nullable', 'string', 'max:120'];
        }
        $data = $request->validate($rules);
        $utm = array_filter(array_intersect_key($data, array_flip(self::UTM)));

        if (empty($data['first_name']) && ! empty($data['name'])) {
            [$data['first_name'], $data['last_name']] = array_pad(preg_split('/\s+/', trim($data['name']), 2), 2, null);
        }
        unset($data['name']);
        $data['first_name'] ??= $data['email'] ?? 'Web visitor';

        return Tenant::run($form->organization_id, function () use ($form, $data, $utm, $context) {
            $lead = $this->leads->create(array_filter([
                ...array_intersect_key($data, array_flip(WebForm::FIELD_KEYS)),
                'lead_source_id' => $form->lead_source_id,
                'campaign_id' => $context['campaign_id'] ?? $form->campaign_id,
                'tag_ids' => $form->tag_ids ?: null,
                'custom_fields' => ['web_form' => $form->name, ...$utm, ...($context['custom_fields'] ?? [])],
            ], fn ($v) => $v !== null && $v !== ''), null, $context['channel'] ?? 'web_form');

            $form->increment('submissions_count');

            return $lead;
        });
    }
}
