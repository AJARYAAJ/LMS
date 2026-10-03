<?php

namespace App\Voice;

use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Bland AI — POST /v1/calls, post-call webhook. */
class BlandProvider implements VoiceProvider
{
    public function start(Call $call, AiAgent $agent, Lead $lead, Integration $integration, string $webhookUrl): array
    {
        $response = Http::withHeaders(['authorization' => $integration->setting('api_key')])->timeout(20)->post('https://api.bland.ai/v1/calls', array_filter([
            'phone_number' => $call->to_number,
            'from' => $integration->setting('from'),
            'task' => Prompt::task($agent, $lead),
            'first_sentence' => Prompt::firstMessage($agent, $lead),
            'voice' => $agent->voice,
            'language' => $agent->language,
            'max_duration' => max(1, (int) ceil($agent->max_duration_seconds / 60)),
            'record' => true,
            'webhook' => $webhookUrl,
            'metadata' => ['leadflow_call_id' => $call->id],
        ]));

        if ($response->failed() || $response->json('status') === 'error') {
            throw new RuntimeException('Bland rejected the call: '.($response->json('message') ?? $response->status()));
        }

        return ['provider_call_id' => $response->json('call_id'), 'status' => 'ringing'];
    }

    public function parse(array $payload): ?array
    {
        if (! isset($payload['call_id'])) {
            return null;
        }
        $status = (string) ($payload['status'] ?? '');
        $answeredBy = (string) ($payload['answered_by'] ?? '');

        return [
            'provider_call_id' => $payload['call_id'],
            'call_id' => $payload['metadata']['leadflow_call_id'] ?? null,
            'status' => match (true) {
                $answeredBy === 'voicemail' => 'voicemail',
                in_array($status, ['no-answer', 'busy'], true) => 'no_answer',
                $status === 'failed' => 'failed',
                default => 'completed',
            },
            'final' => (bool) ($payload['completed'] ?? true),
            'duration_seconds' => isset($payload['call_length']) ? (int) round(((float) $payload['call_length']) * 60) : null,
            'recording_url' => $payload['recording_url'] ?? null,
            'transcript' => collect($payload['transcripts'] ?? [])->map(fn ($t) => ['role' => ($t['user'] ?? '') === 'user' ? 'lead' : 'agent', 'text' => (string) ($t['text'] ?? '')])->all(),
            'summary' => $payload['summary'] ?? null,
            'cost' => isset($payload['price']) ? (float) $payload['price'] : null,
        ];
    }
}
