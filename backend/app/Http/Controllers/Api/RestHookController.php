<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Quote;
use App\Models\Webhook;
use App\Services\WebhookDispatcher;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Zapier / Make style "REST hooks", authenticated with an API key (X-Api-Key):
 * apps subscribe a target URL to an event, get sample payloads, and unsubscribe.
 */
class RestHookController extends Controller
{
    public function me(): JsonResponse
    {
        return response()->json(['data' => ['organization' => Organization::find(Tenant::id())?->only(['id', 'name']), 'events' => WebhookDispatcher::EVENTS]]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(WebhookDispatcher::EVENTS)],
            'target_url' => ['required', 'url:https', 'max:500'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);
        abort_if(Webhook::where('source', 'rest_hook')->count() >= 100, 422, 'Too many subscriptions for this organization.');
        $hook = Webhook::create([
            'name' => $data['name'] ?? 'Zap: '.$data['event'],
            'url' => $data['target_url'],
            'events' => [$data['event']],
            'secret' => Str::random(40),
            'is_active' => true,
            'source' => 'rest_hook',
        ]);

        return response()->json(['id' => $hook->id, 'event' => $data['event']], 201);
    }

    public function unsubscribe(int $id): JsonResponse
    {
        Webhook::where('source', 'rest_hook')->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    /** Recent examples of an event's payload (apps use these to map fields). */
    public function samples(string $event): JsonResponse
    {
        abort_unless(in_array($event, WebhookDispatcher::EVENTS, true), 404);
        $items = match (true) {
            str_starts_with($event, 'lead.') => Lead::with('status:id,name', 'source:id,name', 'owner:id,name,email')->latest('id')->limit(3)->get()->map(fn ($l) => ['lead' => $l->toArray()]),
            $event === 'call.completed' => Call::with('lead:id,first_name,last_name,email,phone')->whereNotNull('outcome')->latest('id')->limit(3)->get()->map->toArray(),
            str_starts_with($event, 'quote.') => Quote::with('deal:id,name,amount')->latest('id')->limit(3)->get()->map(fn ($q) => ['quote' => $q->only(['id', 'number', 'title', 'status', 'currency', 'total', 'signed_name', 'responded_at']), 'deal' => $q->deal?->only(['id', 'name', 'amount'])]),
            default => collect(),
        };

        return response()->json($items->values()->map(fn ($p) => ['event' => $event, 'data' => $p, 'sent_at' => now()->toIso8601String()]));
    }

    /** Polling trigger fallback: leads created since an id or time. */
    public function leads(Request $request): JsonResponse
    {
        $data = $request->validate(['since_id' => ['nullable', 'integer'], 'limit' => ['nullable', 'integer', 'between:1,100']]);

        return response()->json(Lead::with('status:id,name', 'source:id,name', 'owner:id,name,email')
            ->when($data['since_id'] ?? null, fn ($q, $id) => $q->where('id', '>', $id))
            ->latest('id')->limit($data['limit'] ?? 25)->get());
    }
}
