<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\PageLayouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LayoutController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organization = $request->user()->organization;

        return response()->json(['data' => collect(PageLayouts::ENTITIES)->mapWithKeys(fn ($e) => [$e => [
            ...PageLayouts::resolve($organization, $e),
            'catalog' => PageLayouts::catalog($e, $organization->id),
            'required' => PageLayouts::REQUIRED[$e],
        ]])]);
    }

    public function update(Request $request, string $entity, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($entity, PageLayouts::ENTITIES, true), 404);
        $organization = $request->user()->organization;
        $catalog = PageLayouts::catalog($entity, $organization->id);

        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1', 'max:12'],
            'sections.*.title' => ['required', 'string', 'max:60'],
            'sections.*.fields' => ['present', 'array'],
            'sections.*.fields.*' => ['string', Rule::in($catalog)],
            'hidden' => ['present', 'array'],
            'hidden.*' => ['string', Rule::in($catalog)],
        ]);

        $placed = collect($data['sections'])->pluck('fields')->flatten();
        if ($placed->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['sections' => 'A field can only appear once in a layout.']);
        }
        $missing = array_diff(PageLayouts::REQUIRED[$entity], $placed->all());
        if ($missing) {
            throw ValidationException::withMessages(['sections' => 'These fields are required and must stay on the layout: '.implode(', ', $missing).'.']);
        }

        $settings = $organization->settings ?? [];
        $old = $settings['layouts'][$entity] ?? null;
        $settings['layouts'][$entity] = ['sections' => $data['sections'], 'hidden' => array_values(array_diff($data['hidden'], $placed->all()))];
        $organization->update(['settings' => $settings]);
        $audit->log("layout.{$entity}.updated", null, ['layout' => $old], ['layout' => $settings['layouts'][$entity]]);

        return response()->json(['data' => PageLayouts::resolve($organization->fresh(), $entity)]);
    }

    public function destroy(Request $request, string $entity, AuditLogger $audit): JsonResponse
    {
        abort_unless(in_array($entity, PageLayouts::ENTITIES, true), 404);
        $organization = $request->user()->organization;
        $settings = $organization->settings ?? [];
        unset($settings['layouts'][$entity]);
        $organization->update(['settings' => $settings]);
        $audit->log("layout.{$entity}.reset");

        return response()->json(['data' => PageLayouts::resolve($organization->fresh(), $entity)]);
    }
}
