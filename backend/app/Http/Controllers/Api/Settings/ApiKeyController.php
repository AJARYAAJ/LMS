<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\AuditLogger;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ApiKey::latest()->get()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $plain = 'lms_'.Str::random(40);

        $key = ApiKey::create([
            'organization_id' => Tenant::id(),
            'name' => $data['name'],
            'key_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 10),
        ]);
        $audit->log('api_key.created', $key, [], ['name' => $key->name]);

        // The plain key is only revealed once.
        return response()->json(['data' => $key, 'key' => $plain], 201);
    }

    public function destroy(int $id, AuditLogger $audit): JsonResponse
    {
        $key = ApiKey::findOrFail($id);
        $key->delete();
        $audit->log('api_key.revoked', $key, ['name' => $key->name]);

        return response()->json(null, 204);
    }
}
