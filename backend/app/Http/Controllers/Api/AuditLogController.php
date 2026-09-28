<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::with('user:id,name,avatar_color')
            ->when($request->query('event'), fn ($q, $v) => $q->whereLike('event', "{$v}%"))
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('auditable_type'), fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($request->query('auditable_id'), fn ($q, $v) => $q->where('auditable_id', $v))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return response()->json($logs);
    }
}
