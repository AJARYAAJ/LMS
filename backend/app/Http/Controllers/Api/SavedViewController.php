<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedViewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => SavedView::where('entity', $request->query('entity', 'lead'))
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('is_shared', true))
            ->with('user:id,name')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'entity' => ['sometimes', 'in:lead,deal,contact,account,task'],
            'filters' => ['required', 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $view = SavedView::create([...$data, 'user_id' => $request->user()->id]);

        return response()->json(['data' => $view], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $view = SavedView::findOrFail($id);
        abort_unless($view->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $view->delete();

        return response()->json(null, 204);
    }
}
