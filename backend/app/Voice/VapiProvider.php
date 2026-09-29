<?php

namespace App\Voice;

use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Vapi (vapi.ai) — POST /call, end-of-call-report webhooks. */
class VapiProvider implements VoiceProvider
{
    public function start(Call $call, AiAgent $agent, Lead $lead, Integration $integration, string $webhookUrl): array
    {
        $assistant = $integration->setting('assistant_id')
            ? ['assistantId' => $integration->setting('assistant_id'), 'assistantOverrides' => [
                'firstMessage' => Prompt::firstMessage($agent, $lead),
                'variableValues' => Prompt::variables($agent, $lead),
                'server' => ['url' => $webhookUrl],
            ]]
            : ['assistant' => [
                'firstMessage' => Prompt::firstMessage($agent, $lead),
                'model' => ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'messages' => [['role' => 'system', 'content' => Prompt::task($agent, $lead)]]],
                'voice' => ['provider' => 'vapi', 'voiceId' => $agent->voice],
                'maxDurationSeconds' => $agent->max_duration_seconds,
                'server' => ['url' => $webhookUrl],
                'serverMessages' => ['status-update', 'end-of-call-report'],
            ]];

        $response = Http::withToken($integration->setting('api_key'))->timeout(20)->post('https://api.vapi.ai/call', [
            'phoneNumberId' => $integration->setting('phone_number_id'),
            'customer' => ['number' => $call->to_number, 'name' => $lead->full_name],
            ...$assistant,
            'metadata' => ['leadflow_call_id' => $call->id],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Vapi rejected the call: '.($response->json('message.0') ?? $response->json('message') ?? $response->status()));
        }

        return ['provider_call_id' => $response->json('id'), 'status' => 'ringing'];
    }

    public function parse(array $payload): ?array
    {
        $message = $payload['message'] ?? $payload;
        $type = $message['type'] ?? null;
        $vapiCall = $message['call'] ?? [];
        $base = ['provider_call_id' => $vapiCall['id'] ?? null, 'call_id' => $vapiCall['metadata']['leadflow_call_id'] ?? null];

        if ($type === 'status-update') {
            return $base + ['status' => match ($message['status'] ?? '') {
                'ringing', 'queued' => 'ringing',
                'in-progress' => 'in_progress',
                default => 'in_progress',
            }, 'final' => false];
        }

        if ($type !== 'end-of-call-report') {
            return null;
        }

        $reason = (string) ($message['endedReason'] ?? '');
        $transcript = collect($message['artifact']['messages'] ?? $message['messages'] ?? [])
            ->filter(fn ($m) => in_array($m['role'] ?? '', ['assistant', 'bot', 'user'], true))
            ->map(fn ($m) => ['role' => ($m['role'] ?? '') === 'user' ? 'lead' : 'agent', 'text' => (string) ($m['message'] ?? $m['content'] ?? '')])
            ->values()->all();

        return $base + [
            'status' => match (true) {
                str_contains($reason, 'no-answer'), str_contains($reason, 'busy') => 'no_answer',
                str_contains($reason, 'voicemail') => 'voicemail',
                str_contains($reason, 'error'), str_contains($reason, 'failed') => 'failed',
                default => 'completed',
            },
            'final' => true,
            'duration_seconds' => isset($message['durationSeconds']) ? (int) $message['durationSeconds'] : null,
            'recording_url' => $message['recordingUrl'] ?? $message['artifact']['recordingUrl'] ?? null,
            'transcript' => $transcript,
            'summary' => $message['analysis']['summary'] ?? $message['summary'] ?? null,
            'cost' => isset($message['cost']) ? (float) $message['cost'] : null,
        ];
    }
}
