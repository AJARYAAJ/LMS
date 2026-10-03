<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\SavedReport;
use App\Reports\ReportEngine;
use App\Reports\TypeReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Named dashboards: a grid of saved reports, ad-hoc charts, KPI rows and goals. */
class DashboardBuilderController extends Controller
{
    public function __construct(private ReportEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => Dashboard::with('user:id,name')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('is_shared', true))
            ->orderBy('name')->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->visible($request, $id)->load('user:id,name')]);
    }

    public function store(Request $request): JsonResponse
    {
        $dashboard = Dashboard::create([...$this->validated($request), 'user_id' => $request->user()->id]);

        return response()->json(['data' => $dashboard->load('user:id,name')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $dashboard = $this->editable($request, $id);
        $dashboard->update($this->validated($request, true));

        return response()->json(['data' => $dashboard->fresh('user:id,name')]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->editable($request, $id)->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'is_shared' => ['sometimes', 'boolean'],
            'tiles' => ['sometimes', 'array', 'max:24'],
            'tiles.*.kind' => ['required', Rule::in(Dashboard::TILE_KINDS)],
            'tiles.*.span' => ['sometimes', 'integer', 'in:1,2'],
            'tiles.*.title' => ['nullable', 'string', 'max:80'],
            'tiles.*.report_id' => ['nullable', 'integer'],
            'tiles.*.type' => ['nullable', Rule::in(array_keys(TypeReports::TYPES))],
            'tiles.*.spec' => ['nullable', 'array'],
        ]);

        if (isset($data['tiles'])) {
            $visible = SavedReport::where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhere('is_shared', true))->pluck('id')->all();
            $data['tiles'] = collect($data['tiles'])->map(function (array $t, int $i) use ($visible) {
                $tile = ['id' => $t['id'] ?? Str::lower(Str::random(8)), 'kind' => $t['kind'], 'span' => (int) ($t['span'] ?? 1), 'title' => $t['title'] ?? null];
                match ($t['kind']) {
                    'report' => in_array($t['report_id'] ?? null, $visible, true)
                        ? $tile['report_id'] = $t['report_id']
                        : throw ValidationException::withMessages(["tiles.{$i}.report_id" => 'Pick one of your saved or shared reports.']),
                    'spec' => $tile['spec'] = $this->engine->normalize($t['spec'] ?? []),
                    'kpis' => $tile['type'] = $t['type'] ?? throw ValidationException::withMessages(["tiles.{$i}.type" => 'Pick an area.']),
                    default => null,
                };

                return $tile;
            })->values()->all();
        }

        return $data;
    }

    private function visible(Request $request, int $id): Dashboard
    {
        $d = Dashboard::findOrFail($id);
        abort_unless($d->is_shared || $d->user_id === $request->user()->id, 404);

        return $d;
    }

    private function editable(Request $request, int $id): Dashboard
    {
        $d = $this->visible($request, $id);
        abort_unless($d->user_id === $request->user()->id || $request->user()->isAdmin(), 403, 'Only the owner can change this dashboard.');

        return $d;
    }
}
