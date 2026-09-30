<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedReport;
use App\Reports\ReportEngine;
use App\Reports\ReportMailer;
use App\Reports\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SavedReportController extends Controller
{
    public function __construct(private ReportEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reports = SavedReport::with('user:id,name')
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('is_shared', true))
            ->when($request->boolean('pinned'), fn ($q) => $q->where('user_id', $user->id)->where('pinned', true))
            ->orderByDesc('pinned')->orderBy('name')
            ->get();

        return response()->json(['data' => $reports]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $report = SavedReport::create([...$data, 'user_id' => $request->user()->id]);

        return response()->json(['data' => $report->load('user:id,name')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $report = $this->editable($request, $id);
        $report->update($this->validated($request, $report));

        return response()->json(['data' => $report->fresh('user:id,name')]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->editable($request, $id)->delete();

        return response()->json(null, 204);
    }

    /** Run a saved report for the person looking at it (their data visibility applies). */
    public function run(Request $request, int $id): JsonResponse
    {
        $report = $this->visible($request, $id);
        $range = $request->query('range') || $request->query('from')
            ? ReportRange::resolve($request->query('range'), $request->query('from'), $request->query('to'))
            : null;

        return response()->json(['data' => [...$this->engine->run($request->user(), $report->spec, $range), 'report' => $report->only('id', 'name', 'description')]]);
    }

    public function send(Request $request, int $id, ReportMailer $mailer): JsonResponse
    {
        $report = $this->editable($request, $id);
        $count = $mailer->send($report, $request->user());
        $report->forceFill(['last_sent_at' => now()])->save();

        return response()->json(['message' => "Report emailed to {$count} ".($count === 1 ? 'person' : 'people').'.']);
    }

    private function validated(Request $request, ?SavedReport $report = null): array
    {
        $req = $report ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'spec' => [$req, 'array'],
            'is_shared' => ['sometimes', 'boolean'],
            'pinned' => ['sometimes', 'boolean'],
            'schedule' => ['sometimes', Rule::in(SavedReport::SCHEDULES)],
            'recipients' => ['nullable', 'array', 'max:10'],
            'recipients.*' => ['email'],
        ]);
        if (isset($data['spec'])) {
            $data['spec'] = $this->engine->normalize($data['spec']); // rejects unknown fields before saving
        }

        return $data;
    }

    private function visible(Request $request, int $id): SavedReport
    {
        $report = SavedReport::findOrFail($id);
        abort_unless($report->is_shared || $report->user_id === $request->user()->id, 404);

        return $report;
    }

    private function editable(Request $request, int $id): SavedReport
    {
        $report = $this->visible($request, $id);
        abort_unless($report->user_id === $request->user()->id || $request->user()->isAdmin(), 403, 'Only the owner can change this report.');

        return $report;
    }
}
