<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesSubject;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityController extends Controller
{
    use ResolvesSubject;

    /**
     * Timeline for a single record.
     */
    public function index(Request $request, string $type, int $id): JsonResponse
    {
        $subject = $this->subject($request, $type, $id);

        $activities = $subject->activities()
            ->with('user:id,name,avatar_color')
            ->when($request->query('type'), fn ($q, $t) => $q->whereIn('type', explode(',', $t)))
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 30), 100));

        return response()->json($activities);
    }

    public function store(Request $request, string $type, int $id, ActivityRecorder $recorder): JsonResponse
    {
        $subject = $this->subject($request, $type, $id);
        $data = $request->validate([
            'type' => ['required', Rule::in(['call', 'email', 'meeting', 'sms', 'whatsapp'])],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])],
            'outcome' => ['nullable', 'string', 'max:120'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'occurred_at' => ['nullable', 'date'],
            'next_follow_up_at' => ['nullable', 'date', 'after:now'],
        ]);

        $followUp = $data['next_follow_up_at'] ?? null;
        unset($data['next_follow_up_at']);

        $activity = $recorder->record($subject, $data['type'], $data['title'], [
            ...$data,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]);

        if ($subject instanceof Lead) {
            $subject->forceFill(array_filter([
                'last_contacted_at' => $activity->occurred_at,
                'next_follow_up_at' => $followUp,
            ]))->save();
        }

        return response()->json(['data' => $activity->load('user:id,name,avatar_color')], 201);
    }

    /**
     * Organization-wide activity feed (dashboard "recent activity").
     */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();

        $activities = Activity::with(['user:id,name,avatar_color', 'subject'])
            ->when(! $user->hasRole(User::ADMIN, User::VIEWER, User::MANAGER), fn ($q) => $q->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHasMorph('subject', [Lead::class], fn ($l) => $l->where('owner_id', $user->id));
            }))
            ->latest('occurred_at')
            ->latest('id')
            ->limit(min((int) $request->query('limit', 20), 100))
            ->get()
            ->map(fn (Activity $a) => [
                ...$a->toArray(),
                'subject' => $a->subject ? [
                    'type' => $a->subject_type,
                    'id' => $a->subject->id,
                    'name' => $a->subject->full_name ?? $a->subject->name,
                ] : null,
            ]);

        return response()->json(['data' => $activities]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $activity = Activity::findOrFail($id);
        $user = $request->user();
        abort_if($activity->type === 'system', 422, 'System activities cannot be deleted.');
        abort_unless($activity->user_id === $user->id || $user->hasRole(User::ADMIN, User::MANAGER), 403);
        $activity->delete();

        return response()->json(null, 204);
    }
}
