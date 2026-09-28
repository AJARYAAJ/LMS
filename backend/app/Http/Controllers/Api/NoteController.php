<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesSubject;
use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    use ResolvesSubject;

    public function index(Request $request, string $type, int $id): JsonResponse
    {
        $subject = $this->subject($request, $type, $id);

        return response()->json([
            'data' => $subject->notes()->with('user:id,name,avatar_color')->orderByDesc('is_pinned')->latest()->get(),
        ]);
    }

    public function store(Request $request, string $type, int $id, ActivityRecorder $activities): JsonResponse
    {
        $subject = $this->subject($request, $type, $id);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $note = $subject->notes()->create([...$data, 'organization_id' => $subject->organization_id, 'user_id' => $request->user()->id]);
        $activities->record($subject, 'note', 'Note added', ['description' => str($data['body'])->limit(140)->toString(), 'meta' => ['note_id' => $note->id]]);

        return response()->json(['data' => $note->load('user:id,name,avatar_color')], 201);
    }

    public function update(Request $request, int $noteId): JsonResponse
    {
        $note = $this->ownedNote($request, $noteId);
        $note->update($request->validate([
            'body' => ['sometimes', 'string', 'max:10000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['data' => $note->load('user:id,name,avatar_color')]);
    }

    public function destroy(Request $request, int $noteId): JsonResponse
    {
        $this->ownedNote($request, $noteId)->delete();

        return response()->json(null, 204);
    }

    private function ownedNote(Request $request, int $id): Note
    {
        $note = Note::findOrFail($id);
        $user = $request->user();
        abort_unless($note->user_id === $user->id || $user->hasRole(User::ADMIN, User::MANAGER), 403, 'You can only edit your own notes.');

        return $note;
    }
}
