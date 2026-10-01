<?php

namespace App\Services;

use App\Integrations\IntegrationManager;
use App\Jobs\StartCall;
use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Voice\BlandProvider;
use App\Voice\RetellProvider;
use App\Voice\SimulatorProvider;
use App\Voice\VapiProvider;
use App\Voice\VoiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * AI calling: queue calls with the organization's voice vendor, receive the
 * results and apply them to the lead (timeline, qualification, follow-up,
 * owner notification, workflows).
 */
class CallService
{
    public const PROVIDERS = [
        'vapi' => VapiProvider::class,
        'retell' => RetellProvider::class,
        'bland' => BlandProvider::class,
        'simulator' => SimulatorProvider::class,
    ];

    public function __construct(
        private IntegrationManager $integrations,
        private CallAnalyzer $analyzer,
        private ActivityRecorder $activities,
        private AutomationEngine $automation,
        private WebhookDispatcher $webhooks,
    ) {}

    public static function provider(string $key): VoiceProvider
    {
        return app(self::PROVIDERS[$key] ?? SimulatorProvider::class);
    }

    public function integrationFor(AiAgent $agent): Integration
    {
        $integration = $agent->integration_id
            ? Integration::where('is_active', true)->find($agent->integration_id)
            : $this->integrations->active($agent->organization_id, 'voice');

        if (! $integration || ! isset(self::PROVIDERS[$integration->provider])) {
            throw ValidationException::withMessages(['agent' => 'Connect an AI voice provider (or the call simulator) in Settings → Integrations first.']);
        }

        return $integration;
    }

    public function start(Lead $lead, AiAgent $agent, ?User $actor, ?string $campaignKey = null): Call
    {
        if (! $agent->is_active) {
            throw ValidationException::withMessages(['agent' => 'This AI agent is paused.']);
        }
        if (! $lead->phone) {
            throw ValidationException::withMessages(['phone' => "{$lead->full_name} has no phone number."]);
        }
        if (Call::where('lead_id', $lead->id)->whereNotIn('status', Call::FINAL)->exists()) {
            throw ValidationException::withMessages(['lead' => 'A call to this lead is already in progress.']);
        }

        $integration = $this->integrationFor($agent);
        $call = Call::create([
            'organization_id' => $lead->organization_id,
            'lead_id' => $lead->id,
            'ai_agent_id' => $agent->id,
            'user_id' => $actor?->id,
            'campaign_key' => $campaignKey,
            'provider' => $integration->provider,
            'to_number' => preg_replace('/[^\d+]/', '', $lead->phone),
            'status' => 'queued',
        ]);

        StartCall::dispatch($call->id)->afterCommit();

        return $call;
    }

    /**
     * Call every matching lead (with a phone, not called in the last day), up to $limit.
     *
     * @return array{campaign_key: string, queued: int, skipped: int}
     */
    public function campaign(AiAgent $agent, Collection $leads, ?User $actor, int $limit = 50): array
    {
        $this->integrationFor($agent);
        $key = 'cmp_'.Str::lower(Str::random(10));
        $queued = 0;
        $skipped = 0;

        foreach ($leads->take($limit) as $lead) {
            $recent = Call::where('lead_id', $lead->id)->where('created_at', '>=', now()->subDay())->exists();
            if (! $lead->phone || $recent || $lead->converted_at) {
                $skipped++;

                continue;
            }
            try {
                $this->start($lead, $agent, $actor, $key);
                $queued++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['campaign_key' => $key, 'queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * A call a person made themselves (pasted notes / transcript or an uploaded recording):
     * stored as a call and analysed exactly like an AI call.
     *
     * @param  list<array{role: string, text: string}>  $transcript
     */
    public function logHumanCall(Lead $lead, User $actor, array $transcript, ?int $durationMinutes = null, ?string $recordingUrl = null): Call
    {
        $call = Call::create([
            'organization_id' => $lead->organization_id,
            'lead_id' => $lead->id,
            'user_id' => $actor->id,
            'provider' => 'manual',
            'direction' => 'outbound',
            'to_number' => preg_replace('/[^\d+]/', '', (string) $lead->phone),
            'status' => 'in_progress',
            'started_at' => now()->subMinutes($durationMinutes ?? 0),
        ]);

        return $this->update($call, [
            'status' => 'completed',
            'final' => true,
            'transcript' => $transcript,
            'duration_seconds' => $durationMinutes ? $durationMinutes * 60 : null,
            'recording_url' => $recordingUrl,
        ]);
    }

    /** Apply a normalised vendor update (from a webhook or the simulator). */
    public function update(Call $call, array $data): Call
    {
        if ($call->isFinal()) {
            return $call; // vendors retry webhooks; results are applied once
        }

        $call->fill(array_filter([
            'provider_call_id' => $call->provider_call_id ?? ($data['provider_call_id'] ?? null),
            'status' => $data['status'],
            'started_at' => $call->started_at ?? ($data['status'] !== 'ringing' ? now() : null),
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'recording_url' => $data['recording_url'] ?? null,
            'transcript' => $data['transcript'] ?? null,
            'summary' => $data['summary'] ?? null,
            'cost' => $data['cost'] ?? null,
        ], fn ($v) => $v !== null));

        if (! ($data['final'] ?? false)) {
            $call->save();

            return $call;
        }

        return DB::transaction(fn () => $this->finalize($call, $data));
    }

    private function finalize(Call $call, array $data): Call
    {
        $analysis = $this->analyzer->analyze($call, $data['status'], $call->transcript ?? [], $data['summary'] ?? null);
        $followUp = $analysis['follow_up_at'] ? Carbon::parse($analysis['follow_up_at']) : null;

        $call->fill([
            'ended_at' => now(),
            'outcome' => $analysis['outcome'],
            'sentiment' => $data['extracted']['sentiment'] ?? $analysis['sentiment'],
            'summary' => $analysis['summary'] ?? $call->summary,
            'extracted' => [
                'confirmed' => $analysis['confirmed'],
                'follow_up_at' => $followUp?->toIso8601String(),
                'next_step' => $analysis['next_step'],
                'analyzer' => $analysis['analyzer'],
            ],
        ])->save();

        $lead = $call->lead;
        if (! $lead) {
            return $call;
        }

        $label = Str::headline($analysis['outcome']);
        $manual = $call->provider === 'manual';
        if (! $call->summary) {
            $call->forceFill(['summary' => "{$label}.".($analysis['next_step'] ? " Next step: {$analysis['next_step']}." : '')])->save();
        }
        $this->activities->record($lead, 'call', ($manual ? 'Call' : 'AI call')." — {$label}", [
            'user_id' => $call->user_id,
            'description' => $call->summary,
            'direction' => 'outbound',
            'outcome' => $label,
            'duration_minutes' => $call->duration_seconds ? (int) ceil($call->duration_seconds / 60) : null,
            'meta' => ['call_id' => $call->id, 'agent' => $call->agent?->name, 'provider' => $call->provider],
        ]);

        $updates = ['last_contacted_at' => in_array($call->status, ['completed', 'voicemail'], true) ? now() : $lead->last_contacted_at];
        if ($analysis['confirmed']) {
            $updates['qualification'] = array_merge($lead->qualification ?? [], array_fill_keys($analysis['confirmed'], true));
        }
        if ($followUp && $followUp->isFuture()) {
            $updates['next_follow_up_at'] = $followUp;
            Task::create([
                'organization_id' => $lead->organization_id,
                'taskable_type' => 'lead',
                'taskable_id' => $lead->id,
                'assigned_to' => $lead->owner_id ?? $call->user_id,
                'title' => ($analysis['outcome'] === 'meeting_booked' ? 'Demo' : 'Call back').' — '.$lead->full_name,
                'type' => $analysis['outcome'] === 'meeting_booked' ? 'meeting' : 'call',
                'priority' => 'high',
                'due_at' => $followUp,
                'description' => "Agreed on AI call #{$call->id}. ".($analysis['next_step'] ?? ''),
            ]);
        }
        $lead->forceFill($updates)->save();
        app(ScoringEngine::class)->recalculate($lead);

        $recipient = $lead->owner ?? $call->user;
        // People who logged the call themselves don't need a notification about it.
        if ($recipient && ! in_array($analysis['outcome'], ['no_answer'], true) && ! ($manual && $recipient->id === $call->user_id)) {
            $recipient->notify(new AppNotification(
                ($manual ? 'Call' : 'AI call').": {$label} — {$lead->full_name}",
                (string) $call->summary,
                "/leads/{$lead->id}",
                'ai_call',
            ));
        }

        $this->automation->fire('lead.call_completed', $lead->fresh());
        $this->webhooks->dispatch($lead->organization_id, 'call.completed', $call->fresh()->load('lead:id,first_name,last_name,email,phone')->toArray());

        return $call;
    }
}
