<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TrackedLink;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** UTM link builder with short, click-counting links. */
class TrackedLinkController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => TrackedLink::with('campaign:id,name')->latest('id')->limit(500)->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'destination' => ['required', 'url', 'regex:/^https?:\/\//i', 'max:2000'],
            'label' => ['nullable', 'string', 'max:120'],
            'campaign_id' => ['nullable', 'integer', Rules::exists('campaigns')],
            'utm_source' => ['required', 'string', 'max:120'],
            'utm_medium' => ['required', 'string', 'max:120'],
            'utm_campaign' => ['required', 'string', 'max:120'],
            'utm_term' => ['nullable', 'string', 'max:120'],
            'utm_content' => ['nullable', 'string', 'max:120'],
        ]);
        do {
            $code = Str::random(7);
        } while (TrackedLink::withoutGlobalScopes()->where('code', $code)->exists());

        $link = TrackedLink::create([...$data, 'code' => $code, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $link->load('campaign:id,name')], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        TrackedLink::findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** Public: count the click and send the visitor on with the UTM tags. */
    public function follow(string $code): RedirectResponse
    {
        $link = TrackedLink::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        $link->increment('clicks', 1, ['last_clicked_at' => now()]);

        return redirect()->away($link->taggedUrl(), 302);
    }
}
