<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\AuditLogger;
use App\Services\EmailComposer;
use App\Services\LeadInsights;
use App\Services\LeadMerger;
use App\Services\LeadService;
use App\Services\ScoringEngine;
use App\Services\SequenceService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lead workspace actions beyond CRUD: queue claiming, merging, recycle bin,
 * qualification checklist, insights, email and sequences.
 */
class LeadWorkspaceController extends Controller
{
    public function __construct(private LeadService $leads) {}

    public function claim(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        // Claimable = unowned; reps cannot otherwise see these leads.
        $lead = Lead::whereNull('converted_at')->findOrFail($id);

        if ($lead->owner_id) {
            throw ValidationException::withMessages(['lead' => 'This lead has already been claimed.']);
        }

        $this->leads->assign($lead, $user->id, $user, 'Claimed from queue');

        return response()->json(['data' => $lead->fresh(['owner:id,name,avatar_color'])]);
    }

    /**
     * Unassigned queue a rep may claim from (reps cannot otherwise see unowned leads).
     */
    public function queue(Request $request): JsonResponse
    {
        $leads = Lead::whereNull('owner_id')->whereNull('converted_at')
            ->with(['status:id,name,color', 'source:id,name,color'])
            ->when($request->user()->teams()->exists() && $request->user()->role === User::SALES_REP, function ($q) use ($request) {
                $q->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', $request->user()->teams()->pluck('teams.id')));
            })
            ->orderByDesc('score')->orderBy('created_at')
            ->limit(50)
            ->get(['id', 'first_name', 'last_name', 'company', 'email', 'score', 'rating', 'priority', 'lead_status_id', 'lead_source_id', 'created_at']);

        return response()->json(['data' => $leads]);
    }

    public function merge(Request $request, int $id, LeadMerger $merger): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ADMIN, User::MANAGER), 403, 'Only admins and managers can merge leads.');
        $primary = Lead::visibleTo($request->user())->findOrFail($id);
        $data = $request->validate(['duplicate_id' => ['required', 'integer']]);
        $duplicate = Lead::visibleTo($request->user())->findOrFail($data['duplicate_id']);

        return response()->json(['data' => $merger->merge($primary, $duplicate)]);
    }

    public function trash(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ADMIN, User::MANAGER), 403);

        return response()->json(Lead::onlyTrashed()
            ->with(['status:id,name,color', 'owner:id,name,avatar_color'])
            ->latest('deleted_at')
            ->paginate(25));
    }

    public function restore(Request $request, int $id, AuditLogger $audit, ActivityRecorder $activities): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ADMIN, User::MANAGER), 403);
        $lead = Lead::onlyTrashed()->findOrFail($id);
        $lead->restore();
        $activities->record($lead, 'system', 'Lead restored from recycle bin');
        $audit->log('lead.restored', $lead);

        return response()->json(['data' => $lead]);
    }

    public function qualification(Request $request, int $id, ScoringEngine $scoring, ActivityRecorder $activities, LeadInsights $insights): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($id);
        $keys = collect($request->user()->organization->qualificationCriteria())->pluck('key')->all();
        $data = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['boolean'],
        ]);

        $answers = array_intersect_key($data['answers'], array_flip($keys));
        $lead->forceFill(['qualification' => array_merge($lead->qualification ?? [], $answers)])->save();
        $scoring->recalculate($lead);

        $progress = $insights->qualification($lead);
        $activities->record($lead, 'system', "Qualification checklist updated ({$progress['percent']}%)");

        return response()->json(['data' => ['qualification' => $lead->qualification, 'progress' => $progress, 'score' => $lead->score]]);
    }

    public function insights(Request $request, int $id, LeadInsights $insights): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($id);

        return response()->json(['data' => $insights->for($lead)]);
    }

    public function sendEmail(Request $request, int $id, EmailComposer $composer): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($id);
        $data = $request->validate([
            'email_template_id' => ['nullable', Rules::exists('email_templates')],
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        $template = isset($data['email_template_id']) ? EmailTemplate::find($data['email_template_id']) : null;
        $composer->send($lead, $data['subject'], $data['body'], $request->user(), $template);

        return response()->json(['message' => "Email sent to {$lead->email}."]);
    }

    public function previewEmail(Request $request, int $id, EmailComposer $composer): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($id);
        $data = $request->validate(['subject' => ['nullable', 'string'], 'body' => ['nullable', 'string']]);

        return response()->json(['data' => [
            'subject' => $composer->render($data['subject'] ?? '', $lead, $request->user()),
            'body' => $composer->render($data['body'] ?? '', $lead, $request->user()),
        ]]);
    }

    public function enrollments(Request $request, int $id): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($id);

        return response()->json(['data' => $lead->enrollments()
            ->with('sequence:id,name,steps')
            ->withCount(['tasks', 'tasks as completed_tasks_count' => fn ($q) => $q->whereNotNull('completed_at')])
            ->latest()->get()]);
    }

    public function enroll(Request $request, int $id, SequenceService $sequences): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($id);
        $data = $request->validate(['sequence_id' => ['required', Rules::exists('sequences')]]);

        $enrollment = $sequences->enroll(Sequence::findOrFail($data['sequence_id']), $lead, $request->user());

        return response()->json(['data' => $enrollment->load('sequence:id,name')], 201);
    }

    public function stopEnrollment(Request $request, int $enrollmentId, SequenceService $sequences): JsonResponse
    {
        $enrollment = SequenceEnrollment::findOrFail($enrollmentId);
        Lead::visibleTo($request->user())->findOrFail($enrollment->lead_id);
        $sequences->stop($enrollment);

        return response()->json(null, 204);
    }
}
