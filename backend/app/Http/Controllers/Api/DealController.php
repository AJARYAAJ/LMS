<?php

namespace App\Http\Controllers\Api;

use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v));
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
        $columns = PipelineStage::orderBy('display_order')->get()->map(function (PipelineStage $stage) use ($request) {
            $deals = $this->query($request)->where('pipeline_stage_id', $stage->id)->latest()->limit(100)->get();

            return [
                'stage' => $stage,
                'total' => $deals->count(),
                'value' => (float) $deals->sum('amount'),
                'weighted' => (float) $deals->sum(fn (Deal $d) => $d->amount * $d->probability / 100),
                'deals' => $deals,
            ];
        });

        return response()->json(['data' => $columns]);
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
            'expected_close_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
