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
        $base = [
            'provider_call_id' => $vapiCall['id'] ?? null, 'call_id' => $vapiCall['metadata']['leadflow_call_id'] ?? null,
            'direction' => ($vapiCall['type'] ?? '') === 'inboundPhoneCall' ? 'inbound' : 'outbound',
            'from_number' => $vapiCall['customer']['number'] ?? null,
        ];

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
                str_contains($reason, 'error') && ! str_contains($reason, 'forward'), str_contains($reason, 'failed') => 'failed',
                default => 'completed',
            },
            'final' => true,
            'duration_seconds' => isset($message['durationSeconds']) ? (int) $message['durationSeconds'] : null,
            'recording_url' => $message['recordingUrl'] ?? $message['artifact']['recordingUrl'] ?? null,
            'transcript' => $transcript,
            'summary' => $message['analysis']['summary'] ?? $message['summary'] ?? null,
            'cost' => isset($message['cost']) ? (float) $message['cost'] : null,
            'transferred' => str_contains($reason, 'forwarded') || str_contains($reason, 'transfer'),
        ];
    }

    /**
     * Vapi asks which assistant should answer an incoming call ("assistant-request").
     * The receptionist gets the caller's context and, when set up, a transfer tool
     * that hands the call to a person.
     */
    public static function inboundAssistant(AiAgent $agent, Lead $lead, Call $call, ?array $transfer, string $webhookUrl): array
    {
        $known = $lead->first_name !== 'Caller';
        $context = $known ? "The caller is probably {$lead->full_name}".($lead->company ? " from {$lead->company}" : '').'.' : 'The caller is not in the CRM yet: ask for their name, company and email.';
        $handoff = $transfer
            ? "If the caller asks for a person, is an existing customer with an issue, or is ready to buy, say you're connecting them to {$transfer['name']} and use the transferCall tool."
            : 'If the caller asks for a person, take a message and promise a callback.';

        return ['assistant' => array_filter([
            'firstMessage' => strtr($agent->first_message, ['{organization}' => (string) $lead->organization?->name, '{first_name}' => $known ? $lead->first_name : 'there', '{name}' => $known ? $lead->full_name : 'there', '{company}' => (string) $lead->company]),
            'model' => array_filter([
                'provider' => 'anthropic', 'model' => 'claude-opus-5',
                'messages' => [['role' => 'system', 'content' => trim("You are the friendly receptionist for {$lead->organization?->name}.\nGoal: {$agent->goal}\n{$context}\n{$handoff}\nNever invent prices or commitments.")]],
                'tools' => $transfer ? [[
                    'type' => 'transferCall',
                    'destinations' => [['type' => 'number', 'number' => $transfer['number'], 'message' => "Connecting you to {$transfer['name']} now — one moment.", 'description' => "Hand the caller to {$transfer['name']}"]],
                ]] : null,
            ]),
            'voice' => ['provider' => 'vapi', 'voiceId' => $agent->voice],
            'maxDurationSeconds' => $agent->max_duration_seconds,
            'server' => ['url' => $webhookUrl],
            'serverMessages' => ['status-update', 'end-of-call-report'],
            'metadata' => ['leadflow_call_id' => $call->id],
        ])];
    }
}
