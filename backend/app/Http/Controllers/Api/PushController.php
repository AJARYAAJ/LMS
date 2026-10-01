<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Push\Fcm;
use App\Push\WebPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Turn push notifications on or off for this device. */
class PushController extends Controller
{
    public function config(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'vapid_public_key' => WebPush::keys()['public'],
            'fcm' => Fcm::enabled(),
            'devices' => PushSubscription::where('user_id', $request->user()->id)->latest()->get(['id', 'kind', 'device', 'last_used_at', 'created_at']),
        ]]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required_without:fcm_token', 'nullable', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required_with:endpoint', 'nullable', 'string', 'max:200'],
            'keys.auth' => ['required_with:endpoint', 'nullable', 'string', 'max:100'],
            'fcm_token' => ['required_without:endpoint', 'nullable', 'string', 'max:4096'],
            'device' => ['nullable', 'string', 'max:120'],
        ]);
        $endpoint = $data['endpoint'] ?? $data['fcm_token'];
        if (! empty($data['endpoint']) && (strlen(WebPush::unb64($data['keys']['p256dh'])) !== 65 || strlen(WebPush::unb64($data['keys']['auth'])) !== 16)) {
            abort(422, 'The browser sent invalid push keys.');
        }

        $sub = PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $endpoint)], [
            'user_id' => $request->user()->id,
            'kind' => isset($data['endpoint']) ? 'webpush' : 'fcm',
            'endpoint' => $endpoint,
            'p256dh' => $data['keys']['p256dh'] ?? null,
            'auth' => $data['keys']['auth'] ?? null,
            'device' => $data['device'] ?? mb_substr((string) $request->userAgent(), 0, 120),
        ]);

        return response()->json(['data' => $sub->only(['id', 'kind', 'device'])], 201);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['nullable', 'string'], 'fcm_token' => ['nullable', 'string'], 'id' => ['nullable', 'integer']]);
        PushSubscription::where('user_id', $request->user()->id)
            ->when($data['id'] ?? null, fn ($q, $id) => $q->whereKey($id), fn ($q) => $q->where('endpoint_hash', hash('sha256', (string) ($data['endpoint'] ?? $data['fcm_token'] ?? ''))))
            ->delete();

        return response()->json(null, 204);
    }
}
