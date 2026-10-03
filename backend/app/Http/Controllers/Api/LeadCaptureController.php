<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\LeadSource;
use App\Services\DuplicateDetector;
use App\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public lead capture for web forms, landing pages and external systems.
 * Authenticated with an organization API key (X-Api-Key).
 */
class LeadCaptureController extends Controller
{
    public function __invoke(Request $request, LeadService $leads, DuplicateDetector $duplicates): JsonResponse
    {
        // Honeypot: bots fill hidden fields; pretend success.
        if ($request->filled('_hp')) {
            return response()->json(['message' => 'Thank you!'], 202);
        }

        $data = $request->validate([
            'first_name' => ['required_without:name', 'nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:200'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:190'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:190'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:190'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'source' => ['nullable', 'string', 'max:60'],
            'campaign' => ['nullable', 'string', 'max:120'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:120'],
        ]);

        if (empty($data['first_name']) && ! empty($data['name'])) {
            [$data['first_name'], $data['last_name']] = array_pad(preg_split('/\s+/', trim($data['name']), 2), 2, null);
        }

        $sourceKey = $data['source'] ?? $data['utm_source'] ?? 'website';
        $source = LeadSource::where('key', $sourceKey)->orWhere('name', $sourceKey)->first()
            ?? LeadSource::where('key', 'website')->first();
        $campaignName = $data['campaign'] ?? $data['utm_campaign'] ?? null;
        $campaign = $campaignName ? Campaign::where('name', $campaignName)->first() : null;

        $existing = $duplicates->find($data['email'] ?? null, $data['phone'] ?? null)->first();

        $lead = $leads->create(array_filter([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'company' => $data['company'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'website' => $data['website'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'budget' => $data['budget'] ?? null,
            'lead_source_id' => $source?->id,
            'campaign_id' => $campaign?->id,
            'custom_fields' => array_filter([
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'possible_duplicate_of' => $existing?->id,
            ]) ?: null,
        ], fn ($v) => $v !== null), null, 'web_form');

        return response()->json(['message' => 'Thank you!', 'id' => $lead->id], 201);
    }
}
