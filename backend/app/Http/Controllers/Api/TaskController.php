<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\SequenceEnrollment;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\SequenceService;
use App\Support\Rules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    private const SUBJECTS = ['lead', 'deal', 'contact', 'account'];

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->scoped($request)
            ->with(['assignee:id,name,avatar_color', 'taskable'])
            ->when($request->query('taskable_type') && $request->query('taskable_id'), fn ($q) => $q
                ->where('taskable_type', $request->query('taskable_type'))
                ->where('taskable_id', $request->query('taskable_id')))
            ->when($request->query('type'), fn ($q, $v) => $q->where('type', $v))
            ->when($request->query('priority'), fn ($q, $v) => $q->where('priority', $v))
            ->when($request->query('search'), fn ($q, $v) => $q->whereLike('title', "%{$v}%"))
            ->when($request->query('from'), fn ($q, $v) => $q->where('due_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('due_at', '<=', $v))
            ->tap(fn (Builder $q) => $this->applyView($q, $request->query('view', 'open')))
            ->orderByRaw('case when due_at is null then 1 else 0 end')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        $tasks->getCollection()->transform(fn (Task $t) => $this->present($t));

        return response()->json($tasks);
    }

    public function summary(Request $request): JsonResponse
    {
        $base = fn () => $this->scoped($request)->whereNull('completed_at');

        return response()->json(['data' => [
            'overdue' => $base()->where('due_at', '<', now())->count(),
            'today' => $base()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            'upcoming' => $base()->where('due_at', '>', now()->endOfDay())->count(),
            'open' => $base()->count(),
            'completed_this_week' => $this->scoped($request)->where('completed_at', '>=', now()->startOfWeek())->count(),
        ]]);
    }

    public function store(Request $request, ActivityRecorder $activities): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['taskable_type'] = $data['taskable_type'] ?? null;
        $this->assertSubjectVisible($request, $data);

        $task = Task::create([
            ...$data,
            'assigned_to' => $data['assigned_to'] ?? $request->user()->id,
            'created_by' => $request->user()->id,
        ]);

        if ($task->taskable) {
            $activities->record($task->taskable, 'task', "Task created: {$task->title}", ['meta' => ['task_id' => $task->id]]);
            if ($task->taskable instanceof Lead && $task->due_at && (! $task->taskable->next_follow_up_at || $task->due_at->lt($task->taskable->next_follow_up_at))) {
                $task->taskable->forceFill(['next_follow_up_at' => $task->due_at])->saveQuietly();
            }
        }

        return response()->json(['data' => $this->present($task->load(['assignee:id,name,avatar_color', 'taskable']))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $task = $this->scoped($request)->findOrFail($id);
        $data = $request->validate($this->rules(true));
        $task->update($data);

        return response()->json(['data' => $this->present($task->load(['assignee:id,name,avatar_color', 'taskable']))]);
    }

    public function toggle(Request $request, int $id, ActivityRecorder $activities, SequenceService $sequences): JsonResponse
    {
        $task = $this->scoped($request)->findOrFail($id);
        $task->update(['completed_at' => $task->completed_at ? null : now()]);

        if ($task->completed_at && $task->taskable) {
            $activities->record($task->taskable, 'task', "Task completed: {$task->title}", ['meta' => ['task_id' => $task->id]]);
        }
        if ($task->sequence_enrollment_id && ($enrollment = SequenceEnrollment::find($task->sequence_enrollment_id))) {
            $sequences->refresh($enrollment);
        }

        return response()->json(['data' => $this->present($task->load(['assignee:id,name,avatar_color', 'taskable']))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->scoped($request)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    private function scoped(Request $request): Builder
    {
        $user = $request->user();
        $query = Task::query();

        if ($request->query('assignee') === 'me' || $user->role === User::SALES_REP) {
            $query->where(fn ($q) => $q->where('assigned_to', $user->id)->orWhere('created_by', $user->id));
        } elseif ($request->query('assignee')) {
            $query->where('assigned_to', $request->query('assignee'));
        }

        return $query;
    }

    private function applyView(Builder $query, string $view): void
    {
        match ($view) {
            'overdue' => $query->whereNull('completed_at')->where('due_at', '<', now()),
            'today' => $query->whereNull('completed_at')->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]),
            'upcoming' => $query->whereNull('completed_at')->where('due_at', '>', now()->endOfDay()),
            'completed' => $query->whereNotNull('completed_at'),
            'all' => null,
            default => $query->whereNull('completed_at'),
        };
    }

    private function present(Task $task): array
    {
        return [
            ...$task->toArray(),
            'is_overdue' => $task->isOverdue(),
            'taskable' => $task->taskable ? [
                'type' => $task->taskable_type,
                'id' => $task->taskable->id,
                'name' => $task->taskable->full_name ?? $task->taskable->name,
            ] : null,
        ];
    }

    private function assertSubjectVisible(Request $request, array $data): void
    {
        if (($data['taskable_type'] ?? null) === 'lead') {
            Lead::visibleTo($request->user())->findOrFail($data['taskable_id']);
        } elseif ($data['taskable_type'] ?? null) {
            $class = Relation::getMorphedModel($data['taskable_type']);
            $class::findOrFail($data['taskable_id']);
        }
    }

    private function rules(bool $updating = false): array
    {
        return [
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => ['sometimes', Rule::in(Task::TYPES)],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'due_at' => ['nullable', 'date'],
            'reminder_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', Rules::exists('users')],
            'taskable_type' => [$updating ? 'prohibited' : 'nullable', Rule::in(self::SUBJECTS)],
            'taskable_id' => [$updating ? 'prohibited' : 'required_with:taskable_type', 'integer'],
        ];
    }
}
