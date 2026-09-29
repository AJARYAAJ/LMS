<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->limit(min((int) request()->query('limit', 30), 200))->get()->map(fn ($n) => [
                'id' => $n->id,
                ...$n->data,
                'read_at' => $n->read_at,
                'created_at' => $n->created_at,
            ]),
            // Browser-only alerts (in-app turned off) are delivered but never count as unread.
            'unread' => $user->unreadNotifications()->where('data', 'not like', '%"in_app":false%')->count(),
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return $this->index($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->index($request);
    }
}
