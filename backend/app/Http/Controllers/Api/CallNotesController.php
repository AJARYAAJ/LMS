<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\CallService;
use App\Services\Transcriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Conversation intelligence for calls people make themselves: paste notes or a
 * transcript, or upload a recording; the call is analysed like an AI call
 * (outcome, sentiment, summary, qualification, follow-up task, score).
 */
class CallNotesController extends Controller
{
    public function store(Request $request, int $leadId, CallService $calls, Transcriber $transcriber): JsonResponse
    {
        $lead = Lead::visibleTo($request->user())->findOrFail($leadId);
        $data = $request->validate([
            'notes' => ['required_without:audio', 'nullable', 'string', 'max:20000'],
            'audio' => ['required_without:notes', 'nullable', 'file', 'max:25600', 'mimes:mp3,mpga,wav,m4a,mp4,webm,ogg,oga,aac,flac'],
            'duration_minutes' => ['nullable', 'integer', 'between:1,600'],
        ]);

        if ($request->hasFile('audio')) {
            try {
                $transcript = $transcriber->transcribe($lead->organization_id, $request->file('audio'));
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['audio' => $e->getMessage()]);
            }
        } else {
            $transcript = Transcriber::fromText($data['notes'], $request->user()->name);
        }
        if (! $transcript) {
            throw ValidationException::withMessages(['notes' => 'Nothing to analyse — the notes or recording were empty.']);
        }

        $call = $calls->logHumanCall($lead, $request->user(), $transcript, $data['duration_minutes'] ?? null);

        return response()->json(['data' => $call->fresh(['agent:id,name'])], 201);
    }
}
