<?php

namespace App\Voice;

use App\Jobs\SimulateCallResult;
use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;

/**
 * Built-in demo provider: nobody is dialled. A realistic conversation is
 * generated from the agent's questions and the lead's data, weighted by the
 * lead score, and completed a few seconds later through the normal pipeline.
 */
class SimulatorProvider implements VoiceProvider
{
    public function start(Call $call, AiAgent $agent, Lead $lead, Integration $integration, string $webhookUrl): array
    {
        SimulateCallResult::dispatch($call->id)->delay(now()->addSeconds(4));

        return ['provider_call_id' => 'sim_'.$call->id, 'status' => 'in_progress'];
    }

    public function parse(array $payload): ?array
    {
        return null;
    }

    /**
     * @return array{status: string, final: bool, duration_seconds: int, transcript: list<array{role: string, text: string}>, summary: string}
     */
    public static function conversation(Call $call): array
    {
        $lead = $call->lead;
        $agent = $call->agent;
        mt_srand($call->id * 7919 + (int) $lead?->score);
        $roll = mt_rand(1, 100);
        $warm = (int) $lead?->score >= 50;

        $scenario = match (true) {
            $roll <= 10 => 'no_answer',
            $roll <= 18 => 'voicemail',
            $roll <= ($warm ? 70 : 45) => 'interested',
            $roll <= ($warm ? 85 : 65) => 'callback',
            default => 'not_interested',
        };

        if (in_array($scenario, ['no_answer', 'voicemail'], true)) {
            return [
                'status' => $scenario,
                'final' => true,
                'duration_seconds' => $scenario === 'voicemail' ? 38 : 0,
                'transcript' => $scenario === 'voicemail'
                    ? [['role' => 'agent', 'text' => "Hi {$lead->first_name}, this is Ava from {$lead->organization?->name}. Sorry I missed you — I'll try again soon, or feel free to call us back. Have a great day!"]]
                    : [],
                'summary' => $scenario === 'voicemail' ? 'Reached voicemail and left a short message.' : 'No answer.',
            ];
        }

        $t = [['role' => 'agent', 'text' => Prompt::firstMessage($agent, $lead)]];
        $t[] = ['role' => 'lead', 'text' => $scenario === 'not_interested' ? "Hi. I only have a minute, what's this about?" : 'Hi, yes — good timing actually. Go ahead.'];

        if ($scenario === 'not_interested') {
            $t[] = ['role' => 'agent', 'text' => 'Of course. We help teams like '.($lead->company ?: 'yours').' capture and convert more leads. Is improving your sales pipeline a priority right now?'];
            $t[] = ['role' => 'lead', 'text' => "Honestly no — we just renewed with another vendor, so it's not something we'll look at this year."];
            $t[] = ['role' => 'agent', 'text' => "Totally understand. Thanks for your time, {$lead->first_name} — I won't take any more of it. Have a great day."];

            return ['status' => 'completed', 'final' => true, 'duration_seconds' => 64, 'transcript' => $t, 'summary' => "{$lead->full_name} is not interested — recently renewed with another vendor."];
        }

        $answers = [
            'budget' => ['Budget isn’t locked yet, but we’ve set aside something in the range of '.number_format((float) ($lead->budget ?: 15000)).' for this.', true],
            'authority' => ['I lead the evaluation, and our VP signs off on the final decision.', true],
            'need' => [$lead->requirements ?: 'Our reps lose track of follow-ups and we have no visibility into the pipeline.', true],
            'timeline' => ['We’d like something in place '.strtolower($lead->timeline ?: 'this quarter').'.', true],
        ];

        foreach ($agent->questions ?? [] as $q) {
            $t[] = ['role' => 'agent', 'text' => $q['question']];
            $t[] = ['role' => 'lead', 'text' => $answers[$q['key']][0] ?? 'Yes, that makes sense for us.'];
        }

        if ($scenario === 'callback') {
            $t[] = ['role' => 'lead', 'text' => "I'm about to jump into a meeting — can you call me back Thursday afternoon?"];
            $t[] = ['role' => 'agent', 'text' => "Absolutely — I'll put Thursday at 3 PM in the calendar. Thanks, {$lead->first_name}!"];
        } else {
            $t[] = ['role' => 'agent', 'text' => 'That sounds like a great fit. Would you be open to a 30-minute demo with one of our specialists next week?'];
            $t[] = ['role' => 'lead', 'text' => 'Sure, Tuesday morning works for me.'];
            $t[] = ['role' => 'agent', 'text' => "Perfect — I've pencilled in Tuesday at 10 AM and you'll get an invite shortly. Thanks, {$lead->first_name}!"];
        }

        $summary = $scenario === 'callback'
            ? "{$lead->full_name} is interested but busy; asked for a callback Thursday afternoon. Budget, authority, need and timeline were discussed."
            : "{$lead->full_name} confirmed need, budget and timeline and agreed to a demo on Tuesday at 10 AM.";

        return ['status' => 'completed', 'final' => true, 'duration_seconds' => 120 + count($t) * 9, 'transcript' => $t, 'summary' => $summary];
    }
}
