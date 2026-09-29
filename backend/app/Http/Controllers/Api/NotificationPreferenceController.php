<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Notifications\AppNotification;
use App\Services\ChatAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'kinds' => collect(AppNotification::KINDS)->map(fn ($def, $kind) => [
                'kind' => $kind,
                'label' => $def['label'],
                'in_app' => AppNotification::preference($user, $kind, 'in_app'),
                'email' => AppNotification::preference($user, $kind, 'email'),
                'browser' => AppNotification::preference($user, $kind, 'browser'),
            ])->values(),
            'digest' => (bool) ($user->preferences['digest'] ?? true),
            'chat_alert_kinds' => $user->organization->settings['chat_alert_kinds'] ?? ChatAlerts::DEFAULT_KINDS,
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $kinds = array_keys(AppNotification::KINDS);
        $data = $request->validate([
            'notifications' => ['sometimes', 'array'],
            'notifications.*.in_app' => ['sometimes', 'boolean'],
            'notifications.*.email' => ['sometimes', 'boolean'],
            'notifications.*.browser' => ['sometimes', 'boolean'],
            'digest' => ['sometimes', 'boolean'],
            'chat_alert_kinds' => ['sometimes', 'array'],
            'chat_alert_kinds.*' => [Rule::in($kinds)],
        ]);

        $prefs = $user->preferences ?? [];
        if (isset($data['notifications'])) {
            $prefs['notifications'] = array_intersect_key(array_replace_recursive($prefs['notifications'] ?? [], $data['notifications']), array_flip($kinds));
        }
        if (array_key_exists('digest', $data)) {
            $prefs['digest'] = $data['digest'];
        }
        $user->update(['preferences' => $prefs]);

        if (array_key_exists('chat_alert_kinds', $data)) {
            abort_unless($user->isAdmin(), 403, 'Only admins can change team chat alerts.');
            $organization = $user->organization;
            $organization->update(['settings' => array_merge($organization->settings ?? [], ['chat_alert_kinds' => $data['chat_alert_kinds']])]);
        }

        return $this->show($request);
    }

    public function test(Request $request): JsonResponse
    {
        $request->user()->notify(new AppNotification('Test notification', 'If you can read this, notifications are working.', '/notifications', 'test'));

        return response()->json(['message' => 'Test notification sent to every channel you have set up.']);
    }
}
