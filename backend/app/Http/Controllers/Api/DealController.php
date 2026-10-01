<?php

namespace App\Http\Controllers\Api;

use App\Models\Deal;
use App\Models\Goal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Reports\GoalTracker;
use App\Services\ActivityRecorder;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends ResourceController
{
    protected string $model = Deal::class;

    protected array $with = ['account:id,name', 'contact:id,first_name,last_name', 'owner:id,name,avatar_color', 'stage:id,name,color,probability'];

    protected function query(Request $request): Builder
    {
        $user = $request->user();

        return parent::query($request)
            ->when($user->role === User::SALES_REP, fn ($q) => $q->where('owner_id', $user->id))
            ->when($request->query('search'), fn ($q, $s) => $q->whereLike('name', "%{$s}%"))
            ->when($request->query('owner_id'), fn ($q, $v) => $q->where('owner_id', $v === 'me' ? $user->id : $v))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('pipeline_id'), fn ($q, $v) => $q->where('pipeline_id', $v))
            ->when($request->query('forecast_category'), fn ($q, $v) => $q->where('forecast_category', $v));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->query($request)->latest()->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->query($request)->with('lead:id,first_name,last_name')->findOrFail($id)]);
    }

    public function board(Request $request): JsonResponse
    {
        $pipelineId = (int) $request->query('pipeline_id') ?: Pipeline::default()->id;
        $columns = PipelineStage::where('pipeline_id', $pipelineId)->orderBy('display_order')->get()->map(function (PipelineStage $stage) use ($request) {
            $deals = $this->query($request)->where('pipeline_stage_id', $stage->id)->latest()->limit(100)->get();

            return [
                'stage' => $stage,
                'total' => $deals->count(),
                'value' => (float) $deals->sum('amount'),
                'weighted' => (float) $deals->sum(fn (Deal $d) => $d->amount * $d->probability / 100),
                'deals' => $deals,
            ];
        });

        return response()->json(['data' => $columns, 'pipeline_id' => $pipelineId]);
    }

    /**
     * Forecast roll-up for a period: closed-won plus open deals expected to close in it,
     * by forecast category, per rep and in total, against revenue goals as quota.
     */
    public function forecast(Request $request, GoalTracker $goals): JsonResponse
    {
        $data = $request->validate([
            'period' => ['sometimes', Rule::in(['this_month', 'next_month', 'this_quarter', 'next_quarter'])],
            'pipeline_id' => ['nullable', Rules::exists('pipelines')],
        ]);
        $period = $data['period'] ?? 'this_quarter';
        $base = match ($period) {
            'next_month' => now()->addMonthNoOverflow(),
            'next_quarter' => now()->addQuarterNoOverflow(),
            default => now(),
        };
        $kind = str_contains($period, 'quarter') ? 'quarter' : 'month';
        [$start, $end] = GoalTracker::period($kind, $base);

        $deals = $this->query($request)
            ->when($data['pipeline_id'] ?? null, fn ($q, $v) => $q->where('pipeline_id', $v))
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('status', 'won')->whereBetween('closed_at', [$start, $end]))
                ->orWhere(fn ($w) => $w->where('status', 'open')->whereBetween('expected_close_date', [$start->toDateString(), $end->toDateString()])))
            ->orderByDesc('amount')
            ->get();

        $sum = fn ($set, string $cat) => (float) $set->where('forecast_category', $cat)->sum(fn (Deal $d) => (float) $d->amount);
        // Quota from revenue goals; monthly and quarterly goals convert into the period shown.
        $quota = function (?int $userId) use ($kind) {
            $goals = Goal::where('metric', 'revenue_won')
                ->when($userId, fn ($q) => $q->where('user_id', $userId), fn ($q) => $q->whereNull('user_id'))->get(['period', 'target']);

            return (float) $goals->sum(fn (Goal $g) => match (true) {
                $g->period === $kind => $g->target,
                $kind === 'quarter' => $g->target * 3,
                default => $g->target / 3,
            });
        };
        $row = function ($set, ?int $userId) use ($sum, $quota) {
            $closed = $sum($set, 'closed');
            $commit = $sum($set, 'commit');
            $best = $sum($set, 'best_case');
            $target = $quota($userId);

            return [
                'closed' => $closed, 'commit' => $commit, 'best_case' => $best, 'pipeline' => $sum($set, 'pipeline'),
                'omitted' => $sum($set, 'omitted'),
                'projected' => $closed + $commit,
                'best_projection' => $closed + $commit + $best,
                'weighted' => round((float) $set->whereNotIn('forecast_category', ['closed', 'omitted'])->sum(fn (Deal $d) => $d->amount * $d->probability / 100), 2),
                'quota' => $target ?: null,
                'attainment' => $target ? round($closed / $target * 100, 1) : null,
                'deals' => $set->count(),
            ];
        };

        $reps = $deals->groupBy('owner_id')->map(fn ($set, $ownerId) => [
            'owner' => $set->first()->owner,
            ...$row($set, (int) $ownerId ?: null),
        ])->sortByDesc('projected')->values();

        return response()->json(['data' => [
            'period' => $period,
            'period_label' => $kind === 'quarter' ? 'Q'.$start->quarter.' '.$start->year : $start->format('F Y'),
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'total' => $row($deals, null),
            'reps' => $reps,
            'deals' => $deals->map(fn (Deal $d) => $d->only(['id', 'name', 'amount', 'currency', 'probability', 'status', 'forecast_category', 'forecast_override', 'expected_close_date', 'closed_at', 'owner_id']) + ['owner' => $d->owner, 'stage' => $d->stage]),
        ]]);
    }

    public function move(Request $request, int $id, ActivityRecorder $activities): JsonResponse
    {
        $deal = $this->query($request)->findOrFail($id);
        $data = $request->validate([
            'pipeline_stage_id' => ['required', Rules::exists('pipeline_stages')],
            'lost_reason' => ['nullable', 'string', 'max:190'],
        ]);
        $stage = PipelineStage::findOrFail($data['pipeline_stage_id']);

        $deal->update([
            'pipeline_stage_id' => $stage->id,
            'probability' => $stage->probability,
            'status' => $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open'),
            'closed_at' => ($stage->is_won || $stage->is_lost) ? now() : null,
            'lost_reason' => $stage->is_lost ? ($data['lost_reason'] ?? $deal->lost_reason) : null,
        ]);
        $activities->record($deal, 'system', "Moved to {$stage->name}");

        return response()->json(['data' => $this->query($request)->find($deal->id)]);
    }

    protected function prepare(array $data, Request $request, ?Model $record = null): array
    {
        if (! $record) {
            $data['owner_id'] ??= $request->user()->id;
            $data['currency'] ??= $request->user()->organization->currency;
        }
        if (array_key_exists('forecast_category', $data)) {
            // 'auto' hands the category back to the stage probability.
            $manual = ! in_array($data['forecast_category'], [null, 'auto'], true);
            $data['forecast_override'] = $manual;
            if (! $manual) {
                unset($data['forecast_category']);
            }
        }
        if (empty($data['pipeline_stage_id']) && ! $record) {
            $data['pipeline_stage_id'] = PipelineStage::where('pipeline_id', $request->input('pipeline_id') ?: Pipeline::default()->id)
                ->where('is_won', false)->where('is_lost', false)->orderBy('display_order')->value('id');
        }
        if (! empty($data['pipeline_stage_id']) && ! isset($data['probability'])) {
            $data['probability'] = PipelineStage::find($data['pipeline_stage_id'])?->probability ?? 10;
        }

        return $data;
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:190'],
            'account_id' => ['nullable', Rules::exists('accounts')],
            'contact_id' => ['nullable', Rules::exists('contacts')],
            'pipeline_stage_id' => ['nullable', Rules::exists('pipeline_stages')],
            'owner_id' => ['nullable', Rules::exists('users')],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'probability' => ['sometimes', 'integer', 'between:0,100'],
            'forecast_category' => ['sometimes', 'nullable', Rule::in([...Deal::FORECAST, 'auto'])],
            'expected_close_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'custom_fields' => ['nullable', 'array'],
        ];
    }
}
