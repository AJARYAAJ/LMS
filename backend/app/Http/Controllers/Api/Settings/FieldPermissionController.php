<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Security\FieldPermissions;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FieldPermissionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $org = $request->user()->organization;

        return response()->json(['data' => [
            'roles' => FieldPermissions::ROLES,
            'levels' => FieldPermissions::LEVELS,
            'fields' => collect(FieldPermissions::ENTITIES)->mapWithKeys(fn ($e) => [$e => FieldPermissions::fields($e, $org->id)]),
            'rules' => $org->settings['field_permissions'] ?? (object) [],
        ]]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $org = $request->user()->organization;
        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*' => ['array'],
            'rules.*.*' => ['array'],
            'rules.*.*.*' => [Rule::in(FieldPermissions::LEVELS)],
        ]);

        // Keep only known entities, fields and roles; drop "edit" (the default).
        $clean = [];
        foreach (FieldPermissions::ENTITIES as $entity) {
            $allowed = FieldPermissions::fields($entity, $org->id);
            foreach ($data['rules'][$entity] ?? [] as $field => $roles) {
                if (! in_array($field, $allowed, true)) {
                    continue;
                }
                foreach ($roles as $role => $level) {
                    if (in_array($role, FieldPermissions::ROLES, true) && $level !== 'edit') {
                        $clean[$entity][$field][$role] = $level;
                    }
                }
            }
        }
        $old = $org->settings['field_permissions'] ?? [];
        $org->update(['settings' => array_merge($org->settings ?? [], ['field_permissions' => $clean])]);
        FieldPermissions::flush();
        $audit->log('organization.field_permissions_updated', $org, ['field_permissions' => $old], ['field_permissions' => $clean]);

        return $this->show($request);
    }
}
