<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use App\Services\CallService;
use App\Services\LeadSegment;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallController extends Controller
{
    public function __construct(private CallService $calls) {}

    private function scoped(Request $request): Builder
    {
        $user = $request->user();

        return Call::query()
            ->when($user->role === User::SALES_REP, fn ($q) => $q->whereIn('lead_id', Lead::visibleTo($user)->select('id')));
    }

    public function index(Request $request): JsonResponse
    {
        $calls = $this->scoped($request)
            ->with(['lead:id,first_name,last_name,company,phone', 'agent:id,name', 'user:id,name'])
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->query('outcome'), fn ($q, $v) => $q->whereIn('outcome', explode(',', $v)))
            ->when($request->query('ai_agent_id'), fn ($q, $v) => $q->where('ai_agent_id', $v))
            ->when($request->query('lead_id'), fn ($q, $v) => $q->where('lead_id', $v))
            ->when($request->query('campaign_key'), fn ($q, $v) => $q->where('campaign_key', $v))
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        $calls->getCollection()->transform(fn (Call $c) => collect($c->toArray())->except('transcript')->all());

        return response()->json($calls);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->scoped($request)->with(['lead:id,first_name,last_name,company,phone,owner_id', 'agent:id,name,questions', 'user:id,name'])->findOrFail($id)]);
    }

    public function stats(Request $request): JsonResponse
    {
        $base = fn () => $this->scoped($request)->where('created_at', '>=', now()->subDays((int) $request->query('days', 30)));
        $total = $base()->count();
        $connected = $base()->where('status', 'completed')->count();
        $outcomes = $base()->whereNotNull('outcome')->selectRaw('outcome, count(*) as c')->groupBy('outcome')->pluck('c', 'outcome');

        return response()->json(['data' => [
            'total' => $total,
            'active' => $this->scoped($request)->whereNotIn('status', Call::FINAL)->count(),
            'connected' => $connected,
            'connect_rate' => $total ? round($connected / $total * 100, 1) : 0,
            'meetings' => (int) ($outcomes['meeting_booked'] ?? 0),
            'interested' => (int) ($outcomes['interested'] ?? 0) + (int) ($outcomes['meeting_booked'] ?? 0) + (int) ($outcomes['callback'] ?? 0),
            'avg_duration' => (int) round((float) $base()->where('status', 'completed')->avg('duration_seconds')),
            'outcomes' => $outcomes,
        ]]);
    }

    public function callLead(Request $request, int $leadId): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($leadId);
        $data = $request->validate(['ai_agent_id' => ['required', Rules::exists('ai_agents')]]);

        $call = $this->calls->start($lead, AiAgent::findOrFail($data['ai_agent_id']), $request->user());

        return response()->json(['data' => $call->fresh(['agent:id,name'])], 201);
    }

    public function campaign(Request $request, int $agentId, LeadSegment $segment): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ADMIN, User::MANAGER), 403, 'Only admins and managers can launch call campaigns.');
        $agent = AiAgent::findOrFail($agentId);
        $data = $request->validate([
            'lead_ids' => ['nullable', 'array', 'max:500'],
            'lead_ids.*' => ['integer'],
            'conditions' => ['nullable', 'array'],
            'limit' => ['sometimes', 'integer', 'between:1,200'],
        ]);

        // Leave out leads already called in the last day so they don't use up the campaign's slots.
        $leads = Lead::visibleTo($request->user())->whereNull('converted_at')->whereNotNull('phone')
            ->whereDoesntHave('calls', fn ($q) => $q->where('created_at', '>=', now()->subDay()))
            ->when($data['lead_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($data['conditions'] ?? null, fn ($q, $c) => $segment->apply($q, $c))
            ->orderByDesc('score')
            ->limit($data['limit'] ?? 25)
            ->get();

        return response()->json(['data' => $this->calls->campaign($agent, $leads, $request->user(), $data['limit'] ?? 25)], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $call = $this->scoped($request)->findOrFail($id);
        abort_if($call->isFinal(), 422, 'This call has already finished.');
        $call->update(['status' => 'canceled', 'ended_at' => now()]);

        return response()->json(['data' => $call]);
    }
}
