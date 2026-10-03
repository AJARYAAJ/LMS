<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Services\BroadcastService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Email campaigns: audience from a segment, optional A/B test, tracked sends. */
class BroadcastController extends Controller
{
    public function __construct(private BroadcastService $broadcasts) {}

    public function index(): JsonResponse
    {
        $list = Broadcast::with('creator:id,name', 'campaign:id,name')->latest('id')->limit(200)->get();
        $counts = BroadcastRecipient::whereIn('broadcast_id', $list->pluck('id'))
            ->groupBy('broadcast_id')
            ->selectRaw("broadcast_id, count(*) as recipients, sum(case when status = 'sent' then 1 else 0 end) as sent, sum(case when opened_at is not null then 1 else 0 end) as opened, sum(case when clicked_at is not null then 1 else 0 end) as clicked")
            ->get()->keyBy('broadcast_id');

        return response()->json(['data' => $list->map(function (Broadcast $b) use ($counts) {
            $c = $counts->get($b->id);
            $sent = (int) ($c->sent ?? 0);

            return [...$b->toArray(), 'summary' => [
                'recipients' => (int) ($c->recipients ?? 0), 'sent' => $sent,
                'open_rate' => $sent ? round($c->opened / $sent * 100, 1) : 0, 'click_rate' => $sent ? round($c->clicked / $sent * 100, 1) : 0,
            ]];
        })]);
    }

    public function show(int $id): JsonResponse
    {
        $b = Broadcast::with('creator:id,name', 'campaign:id,name')->findOrFail($id);

        return response()->json(['data' => [...$b->toArray(), 'stats' => $this->broadcasts->stats($b)]]);
    }

    public function store(Request $request): JsonResponse
    {
        $b = Broadcast::create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return response()->json(['data' => $b->fresh()], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $b = Broadcast::findOrFail($id);
        abort_unless(in_array($b->status, ['draft', 'scheduled'], true), 422, 'A campaign can only be edited before it is sent.');
        $b->update([...$this->validated($request), 'status' => 'draft', 'scheduled_at' => null]);

        return response()->json(['data' => $b->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $b = Broadcast::findOrFail($id);
        abort_if(in_array($b->status, ['testing', 'sending'], true), 422, 'Cancel the campaign before deleting it.');
        $b->delete();

        return response()->json(null, 204);
    }

    /** Live audience size while the segment is being edited. */
    public function audience(Request $request): JsonResponse
    {
        $data = $request->validate(['conditions' => ['nullable', 'array', 'max:20']]);
        $people = $this->broadcasts->audience(new Broadcast(['conditions' => $data['conditions'] ?? []]));

        return response()->json(['data' => [
            'count' => $people->count(),
            'sample' => $people->take(5)->map(fn ($l) => ['id' => $l->id, 'name' => $l->first_name, 'email' => $l->email])->values(),
        ]]);
    }

    /** Send now, or schedule for later when `scheduled_at` is given. */
    public function launch(Request $request, int $id): JsonResponse
    {
        $b = Broadcast::findOrFail($id);
        $data = $request->validate(['scheduled_at' => ['nullable', 'date', 'after:now']]);
        if (! empty($data['scheduled_at'])) {
            abort_unless($b->status === 'draft' || $b->status === 'scheduled', 422, 'This campaign was already sent.');
            if ($this->broadcasts->audience($b)->isEmpty()) {
                throw ValidationException::withMessages(['conditions' => 'Nobody matches this audience (or everyone opted out of email).']);
            }
            $b->update(['status' => 'scheduled', 'scheduled_at' => $data['scheduled_at']]);

            return response()->json(['data' => $b->fresh()]);
        }

        return response()->json(['data' => $this->broadcasts->launch($b)]);
    }

    public function cancel(int $id): JsonResponse
    {
        $b = Broadcast::findOrFail($id);
        abort_unless(in_array($b->status, ['scheduled', 'testing', 'sending'], true), 422, 'Nothing to cancel.');
        DB::transaction(function () use ($b) {
            $b->recipients()->whereIn('status', ['queued', 'held'])->update(['status' => 'skipped', 'reason' => 'Campaign canceled']);
            $b->update(['status' => $b->status === 'scheduled' ? 'draft' : 'canceled', 'scheduled_at' => null]);
        });

        return response()->json(['data' => $b->fresh()]);
    }

    /** End the A/B test early and send the better variant to the rest. */
    public function pickWinner(int $id): JsonResponse
    {
        $b = Broadcast::findOrFail($id);
        abort_unless($b->status === 'testing', 422, 'This campaign is not testing variants.');
        $this->broadcasts->pickWinner($b);

        return response()->json(['data' => [...$b->fresh()->toArray(), 'stats' => $this->broadcasts->stats($b)]]);
    }

    public function recipients(Request $request, int $id): JsonResponse
    {
        $b = Broadcast::findOrFail($id);
        $page = $b->recipients()->with('lead:id,first_name,last_name,email,company')
            ->when($request->query('filter'), fn ($q, $f) => match ($f) {
                'opened' => $q->whereNotNull('opened_at'),
                'clicked' => $q->whereNotNull('clicked_at'),
                'unopened' => $q->where('status', 'sent')->whereNull('opened_at'),
                'failed' => $q->whereIn('status', ['skipped', 'failed']),
                default => $q,
            })
            ->orderByDesc('clicked_at')->orderByDesc('opened_at')->orderBy('id')
            ->paginate(min(100, (int) $request->query('per_page', 25)));

        return response()->json($page);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'campaign_id' => ['nullable', 'integer', Rules::exists('campaigns')],
            'conditions' => ['nullable', 'array', 'max:20'],
            'conditions.*.field' => ['required', 'string', 'max:60'],
            'conditions.*.operator' => ['required', 'string', 'max:30'],
            'conditions.*.value' => ['nullable'],
            'variants' => ['required', 'array', 'min:1', 'max:2'],
            'variants.*.subject' => ['required', 'string', 'max:190'],
            'variants.*.body' => ['required', 'string', 'max:20000'],
            'test_percent' => ['sometimes', 'integer', 'between:10,100'],
            'winner_metric' => ['sometimes', 'in:open,click'],
            'winner_after_hours' => ['sometimes', 'integer', 'between:1,72'],
        ]);
        $data['variants'] = collect($data['variants'])->values()
            ->map(fn ($v, $i) => ['key' => $i === 0 ? 'A' : 'B', 'subject' => $v['subject'], 'body' => $v['body']])->all();
        $data['conditions'] = array_values($data['conditions'] ?? []);

        return $data;
    }
}
