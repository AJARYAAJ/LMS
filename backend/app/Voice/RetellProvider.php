<?php

namespace App\Voice;

use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Retell AI — POST /v2/create-phone-call, call_ended / call_analyzed webhooks. */
class RetellProvider implements VoiceProvider
{
    public function start(Call $call, AiAgent $agent, Lead $lead, Integration $integration, string $webhookUrl): array
    {
        $response = Http::withToken($integration->setting('api_key'))->timeout(20)->post('https://api.retellai.com/v2/create-phone-call', [
            'from_number' => $integration->setting('from_number'),
            'to_number' => $call->to_number,
            'override_agent_id' => $integration->setting('agent_id'),
            'retell_llm_dynamic_variables' => array_merge(Prompt::variables($agent, $lead), ['first_message' => Prompt::firstMessage($agent, $lead)]),
            'metadata' => ['leadflow_call_id' => $call->id],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Retell rejected the call: '.($response->json('message') ?? $response->status()));
        }

        return ['provider_call_id' => $response->json('call_id'), 'status' => 'ringing'];
    }

    public function parse(array $payload): ?array
    {
        $event = $payload['event'] ?? null;
        $c = $payload['call'] ?? [];
        $base = [
            'provider_call_id' => $c['call_id'] ?? null, 'call_id' => $c['metadata']['leadflow_call_id'] ?? null,
            'direction' => ($c['direction'] ?? 'outbound') === 'inbound' ? 'inbound' : 'outbound',
            'from_number' => $c['from_number'] ?? null,
        ];

        if ($event === 'call_started') {
            return $base + ['status' => 'in_progress', 'final' => false];
        }
        if (! in_array($event, ['call_ended', 'call_analyzed'], true)) {
            return null;
        }

        $reason = (string) ($c['disconnection_reason'] ?? '');

        return $base + [
            'status' => match (true) {
                in_array($reason, ['dial_no_answer', 'dial_busy'], true) => 'no_answer',
                $reason === 'voicemail_reached' => 'voicemail',
                str_starts_with($reason, 'error') || $reason === 'dial_failed' => 'failed',
                default => 'completed',
            },
            // call_ended arrives before analysis; wait for call_analyzed when it carries the summary
            'final' => $event === 'call_analyzed' || empty($c['call_analysis']),
            'duration_seconds' => isset($c['duration_ms']) ? intdiv((int) $c['duration_ms'], 1000) : null,
            'recording_url' => $c['recording_url'] ?? null,
            'transcript' => collect($c['transcript_object'] ?? [])->map(fn ($t) => ['role' => ($t['role'] ?? '') === 'agent' ? 'agent' : 'lead', 'text' => (string) ($t['content'] ?? '')])->all(),
            'summary' => $c['call_analysis']['call_summary'] ?? null,
            'transferred' => $reason === 'call_transfer',
            'extracted' => array_filter(['sentiment' => isset($c['call_analysis']['user_sentiment']) ? strtolower($c['call_analysis']['user_sentiment']) : null]),
        ];
    }
}
