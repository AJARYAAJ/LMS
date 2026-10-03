<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->organization]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $organization = $request->user()->organization;
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'timezone' => ['sometimes', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'settings' => ['nullable', 'array'],
        ]);

        $original = $organization->getOriginal();
        if (array_key_exists('settings', $data)) {
            // Merge so saving one settings area (e.g. qualification) never wipes another (e.g. layouts).
            $data['settings'] = array_merge($organization->settings ?? [], $data['settings'] ?? []);
        }
        $organization->update($data);
        $audit->logChanges('organization.updated', $organization, $original);

        return response()->json(['data' => $organization->fresh()]);
    }
}
