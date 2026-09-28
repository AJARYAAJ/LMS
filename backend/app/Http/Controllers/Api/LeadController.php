<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use App\Services\DuplicateDetector;
use App\Services\LeadService;
use App\Services\ScoringEngine;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeadController extends Controller
{
    private const SORTABLE = ['created_at', 'updated_at', 'first_name', 'company', 'score', 'expected_value', 'next_follow_up_at', 'last_contacted_at', 'priority'];

    private const LIST_RELATIONS = ['status:id,name,color,category', 'source:id,name,color', 'owner:id,name,avatar_color', 'tags:id,name,color'];

    public function __construct(private LeadService $leads) {}

    public function index(Request $request): JsonResponse
    {
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $page = $this->filtered($request)
            ->with(self::LIST_RELATIONS)
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return response()->json($page);
    }

    /**
     * Leads grouped by status for the kanban board.
     */
    public function board(Request $request): JsonResponse
    {
        $statuses = LeadStatus::where('is_active', true)->orderBy('display_order')->get();
        $limit = min((int) $request->query('limit', 50), 200);

        $columns = $statuses->map(function (LeadStatus $status) use ($request, $limit) {
            $query = $this->filtered($request)->where('lead_status_id', $status->id);

            return [
                'status' => $status,
                'total' => (clone $query)->count(),
                'value' => (float) (clone $query)->sum('expected_value'),
                'leads' => $query->with(self::LIST_RELATIONS)->orderByDesc('score')->orderByDesc('id')->limit($limit)->get(),
            ];
        });

        return response()->json(['data' => $columns]);
    }

    public function store(Request $request, DuplicateDetector $duplicates): JsonResponse
    {
        $data = $request->validate($this->rules());

        if (! $request->boolean('allow_duplicate')) {
            $matches = $duplicates->find($data['email'] ?? null, $data['phone'] ?? null);
            if ($matches->isNotEmpty()) {
                return response()->json([
                    'message' => 'Possible duplicate lead found.',
                    'duplicates' => $matches,
                ], 409);
            }
        }

        $this->restrictOwnership($request->user(), $data, true);
        $lead = $this->leads->create($data, $request->user());

        return response()->json(['data' => $this->detailed($lead)], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->detailed($this->find($request, $id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $lead = $this->find($request, $id);
        $data = $request->validate($this->rules(true));
        $this->restrictOwnership($request->user(), $data);

        $lead = $this->leads->update($lead, $data, $request->user());

        return response()->json(['data' => $this->detailed($lead)]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ADMIN, User::MANAGER), 403, 'Only admins and managers can delete leads.');
        $this->leads->delete($this->find($request, $id));

        return response()->json(null, 204);
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $lead = $this->find($request, $id);
        $data = $request->validate([
            'lead_status_id' => ['required', Rules::exists('lead_statuses')],
            'note' => ['nullable', 'string', 'max:500'],
            'lost_reason' => ['nullable', 'string', 'max:190'],
        ]);

        $this->leads->changeStatus($lead, $data['lead_status_id'], $request->user(), $data['note'] ?? null, $data['lost_reason'] ?? null);

        return response()->json(['data' => $this->detailed($lead)]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ADMIN, User::MANAGER), 403, 'Only admins and managers can reassign leads.');
        $lead = $this->find($request, $id);
        $data = $request->validate([
            'owner_id' => ['nullable', Rules::exists('users')],
            'auto' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($request->boolean('auto')) {
            $lead->forceFill(['owner_id' => null])->saveQuietly();
            $this->leads->autoAssign($lead);
        } else {
            $this->leads->assign($lead, $data['owner_id'] ?? null, $request->user(), $data['reason'] ?? null);
        }

        return response()->json(['data' => $this->detailed($lead->refresh())]);
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        $lead = $this->find($request, $id);
        $options = $request->validate([
            'create_account' => ['sometimes', 'boolean'],
            'account_id' => ['nullable', Rules::exists('accounts')],
            'create_deal' => ['sometimes', 'boolean'],
            'deal_name' => ['nullable', 'string', 'max:190'],
            'deal_amount' => ['nullable', 'numeric', 'min:0'],
            'pipeline_stage_id' => ['nullable', Rules::exists('pipeline_stages')],
            'expected_close_date' => ['nullable', 'date'],
        ]);

        $lead = $this->leads->convert($lead, $options, $request->user());

        return response()->json(['data' => $this->detailed($lead)]);
    }

    public function score(Request $request, int $id): JsonResponse
    {
        $lead = $this->find($request, $id);

        return response()->json([
            'data' => [
                'score' => $lead->score,
                'rating' => $lead->rating,
                'events' => $lead->scoreEvents()->latest('id')->get(),
            ],
        ]);
    }

    public function adjustScore(Request $request, int $id, ScoringEngine $scoring): JsonResponse
    {
        $lead = $this->find($request, $id);
        $data = $request->validate([
            'points' => ['required', 'integer', 'between:-100,100', 'not_in:0'],
            'reason' => ['required', 'string', 'max:190'],
        ]);

        $scoring->adjust($lead, $data['points'], $data['reason']);

        return $this->score($request, $id);
    }

    public function history(Request $request, int $id): JsonResponse
    {
        $lead = $this->find($request, $id);

        return response()->json([
            'data' => $lead->statusHistory()->with(['fromStatus:id,name,color', 'toStatus:id,name,color', 'user:id,name'])->latest('id')->get(),
        ]);
    }

    public function duplicates(Request $request, DuplicateDetector $duplicates): JsonResponse
    {
        $data = $request->validate([
            'email' => ['nullable', 'string'],
            'phone' => ['nullable', 'string'],
            'exclude_id' => ['nullable', 'integer'],
        ]);

        return response()->json(['data' => $duplicates->find($data['email'] ?? null, $data['phone'] ?? null, $data['exclude_id'] ?? null)]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['assign', 'status', 'tag', 'untag', 'delete', 'priority'])],
            'owner_id' => ['nullable', Rules::exists('users')],
            'lead_status_id' => ['required_if:action,status', Rules::exists('lead_statuses')],
            'tag_id' => ['required_if:action,tag,untag', Rules::exists('tags')],
            'priority' => ['required_if:action,priority', Rule::in(Lead::PRIORITIES)],
        ]);

        $user = $request->user();
        if (in_array($data['action'], ['assign', 'delete'], true)) {
            abort_unless($user->hasRole(User::ADMIN, User::MANAGER), 403, 'Only admins and managers can do that.');
        }

        $leads = Lead::visibleTo($user)->whereIn('id', $data['ids'])->get();

        foreach ($leads as $lead) {
            match ($data['action']) {
                'assign' => $this->leads->assign($lead, $data['owner_id'] ?? null, $user),
                'status' => $this->leads->changeStatus($lead, $data['lead_status_id'], $user, 'Bulk update'),
                'tag' => $lead->tags()->syncWithoutDetaching([$data['tag_id']]),
                'untag' => $lead->tags()->detach($data['tag_id']),
                'priority' => $this->leads->update($lead, ['priority' => $data['priority']], $user),
                'delete' => $this->leads->delete($lead),
            };
        }

        return response()->json(['message' => "Updated {$leads->count()} leads.", 'count' => $leads->count()]);
    }

    public function export(Request $request): StreamedResponse
    {
        $columns = ['id', 'first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'website', 'industry',
            'city', 'country', 'status', 'source', 'owner', 'priority', 'score', 'rating', 'budget', 'expected_value',
            'next_follow_up_at', 'tags', 'created_at'];

        $query = $this->filtered($request)->with(self::LIST_RELATIONS)->orderBy('id');

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns);
            $query->chunk(500, function ($leads) use ($out) {
                foreach ($leads as $lead) {
                    fputcsv($out, array_map([$this, 'csvSafe'], [
                        $lead->id, $lead->first_name, $lead->last_name, $lead->email, $lead->phone, $lead->company,
                        $lead->job_title, $lead->website, $lead->industry, $lead->city, $lead->country,
                        $lead->status?->name, $lead->source?->name, $lead->owner?->name, $lead->priority, $lead->score,
                        $lead->rating, $lead->budget, $lead->expected_value, $lead->next_follow_up_at?->toDateTimeString(),
                        $lead->tags->pluck('name')->implode('|'), $lead->created_at?->toDateTimeString(),
                    ]));
                }
            });
            fclose($out);
        }, 'leads-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Neutralise spreadsheet formula injection in exported cells.
     */
    public function csvSafe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    public function filtered(Request $request): Builder
    {
        $user = $request->user();

        return Lead::visibleTo($user)
            ->when($request->query('search'), function (Builder $q, string $search) {
                $q->where(function (Builder $q) use ($search) {
                    $like = '%'.$search.'%';
                    $q->whereLike('first_name', $like)
                        ->orWhereLike('last_name', $like)
                        ->orWhereLike('email', $like)
                        ->orWhereLike('phone', $like)
                        ->orWhereLike('company', $like);
                });
            })
            ->when($request->query('status_id'), fn ($q, $v) => $q->whereIn('lead_status_id', (array) explode(',', $v)))
            ->when($request->query('status_category'), fn ($q, $v) => $q->whereHas('status', fn ($s) => $s->whereIn('category', explode(',', $v))))
            ->when($request->query('source_id'), fn ($q, $v) => $q->whereIn('lead_source_id', explode(',', $v)))
            ->when($request->query('campaign_id'), fn ($q, $v) => $q->where('campaign_id', $v))
            ->when($request->query('team_id'), fn ($q, $v) => $q->where('team_id', $v))
            ->when($request->query('priority'), fn ($q, $v) => $q->whereIn('priority', explode(',', $v)))
            ->when($request->query('rating'), fn ($q, $v) => $q->whereIn('rating', explode(',', $v)))
            ->when($request->query('tag_id'), fn ($q, $v) => $q->whereHas('tags', fn ($t) => $t->whereIn('tags.id', explode(',', $v))))
            ->when($request->query('owner_id'), function ($q, $v) use ($user) {
                match ($v) {
                    'me' => $q->where('owner_id', $user->id),
                    'unassigned' => $q->whereNull('owner_id'),
                    default => $q->whereIn('owner_id', explode(',', $v)),
                };
            })
            ->when($request->query('converted') !== null, fn ($q) => $request->boolean('converted') ? $q->whereNotNull('converted_at') : $q->whereNull('converted_at'))
            ->when($request->query('min_score'), fn ($q, $v) => $q->where('score', '>=', (int) $v))
            ->when($request->query('created_from'), fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->query('created_to'), fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($request->query('follow_up'), function ($q, $v) {
                match ($v) {
                    'overdue' => $q->where('next_follow_up_at', '<', now()),
                    'today' => $q->whereBetween('next_follow_up_at', [now()->startOfDay(), now()->endOfDay()]),
                    'upcoming' => $q->where('next_follow_up_at', '>', now()),
                    'none' => $q->whereNull('next_follow_up_at'),
                    default => null,
                };
            });
    }

    private function find(Request $request, int $id): Lead
    {
        return Lead::visibleTo($request->user())->findOrFail($id);
    }

    private function detailed(Lead $lead): Lead
    {
        return $lead->load([
            'status', 'source', 'campaign:id,name', 'team:id,name,color', 'tags:id,name,color',
            'owner:id,name,email,avatar_color', 'creator:id,name',
            'convertedContact:id,first_name,last_name', 'convertedAccount:id,name', 'convertedDeal:id,name,amount',
        ])->loadCount(['notes', 'activities', 'tasks' => fn ($q) => $q->whereNull('completed_at')]);
    }

    /**
     * Sales reps may only create or keep leads for themselves.
     */
    private function restrictOwnership(User $user, array &$data, bool $creating = false): void
    {
        if ($user->role !== User::SALES_REP) {
            return;
        }

        if (array_key_exists('owner_id', $data) && (int) $data['owner_id'] !== $user->id) {
            abort(403, 'Sales reps can only own their own leads.');
        }

        if ($creating) {
            $data['owner_id'] = $user->id;
        }
    }

    private function rules(bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        return [
            'first_name' => [$req, 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['nullable', 'string', 'max:190'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'string', 'max:190'],
            'industry' => ['nullable', 'string', 'max:120'],
            'company_size' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'lead_status_id' => ['nullable', Rules::exists('lead_statuses')],
            'lead_source_id' => ['nullable', Rules::exists('lead_sources')],
            'campaign_id' => ['nullable', Rules::exists('campaigns')],
            'owner_id' => ['nullable', Rules::exists('users')],
            'team_id' => ['nullable', Rules::exists('teams')],
            'priority' => ['sometimes', Rule::in(Lead::PRIORITIES)],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'expected_value' => ['nullable', 'numeric', 'min:0'],
            'timeline' => ['nullable', 'string', 'max:120'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'qualification' => ['nullable', 'array'],
            'custom_fields' => ['nullable', 'array'],
            'next_follow_up_at' => ['nullable', 'date'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', Rules::exists('tags')],
        ];
    }
}
