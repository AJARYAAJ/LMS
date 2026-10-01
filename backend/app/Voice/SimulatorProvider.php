<?php

namespace App\Voice;

use App\Jobs\SimulateCallResult;
use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Integration;
use App\Models\Lead;
use App\Services\CallService;

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
        if ($call->direction === 'inbound') {
            return self::inbound($call);
        }
        $lead = $call->lead;
        $agent = $call->agent;
        mt_srand($call->id * 7919 + (int) $lead?->score);
        $roll = mt_rand(1, 100);
        $warm = (int) $lead?->score >= 50;

        $scenario = match (true) {
            $roll <= 10 => 'no_answer',
            $roll <= 18 => 'voicemail',
            $roll <= ($warm ? 50 : 33) => 'meeting',
            $roll <= ($warm ? 65 : 48) => 'interested',
            $roll <= ($warm ? 80 : 65) => 'callback',
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
        $t[] = ['role' => 'lead', 'text' => $scenario === 'not_interested' ? "Hi. I only have a minute, what's this about?" : 'Hi — good timing actually. Go ahead.'];

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
            $t[] = ['role' => 'lead', 'text' => $answers[$q['key']][0] ?? 'That makes sense for us.'];
        }

        if ($scenario === 'callback') {
            $t[] = ['role' => 'lead', 'text' => "I'm about to jump into a meeting — can you call me back Thursday afternoon?"];
            $t[] = ['role' => 'agent', 'text' => "Absolutely — I'll put Thursday at 3 PM in the calendar. Thanks, {$lead->first_name}!"];
        } elseif ($scenario === 'interested') {
            $t[] = ['role' => 'agent', 'text' => 'Would a short walkthrough with one of our specialists be useful?'];
            $t[] = ['role' => 'lead', 'text' => 'Could you email me an overview and pricing first? I want to share it with my team before we go further.'];
            $t[] = ['role' => 'agent', 'text' => "Of course — I'll send that over today. Thanks, {$lead->first_name}!"];
        } else {
            $t[] = ['role' => 'agent', 'text' => 'That sounds like a great fit. Would you be open to a 30-minute demo with one of our specialists next week?'];
            $t[] = ['role' => 'lead', 'text' => 'Sure, Tuesday morning works for me.'];
            $t[] = ['role' => 'agent', 'text' => "Perfect — I've pencilled in Tuesday at 10 AM and you'll get an invite shortly. Thanks, {$lead->first_name}!"];
        }

        $summary = match ($scenario) {
            'callback' => "{$lead->full_name} is interested but busy; asked for a callback Thursday afternoon. Budget, authority, need and timeline were discussed.",
            'interested' => "{$lead->full_name} is interested and asked for an overview and pricing by email to share with their team.",
            default => "{$lead->full_name} confirmed need, budget and timeline and agreed to a demo on Tuesday at 10 AM.",
        };

        return ['status' => 'completed', 'final' => true, 'duration_seconds' => 120 + count($t) * 9, 'transcript' => $t, 'summary' => $summary];
    }

    /** Someone rings the AI receptionist: an enquiry, a pricing question, a callback request or a wrong number. */
    private static function inbound(Call $call): array
    {
        $lead = $call->lead;
        $org = (string) $lead?->organization?->name;
        mt_srand($call->id * 104729);
        $unknown = $lead->first_name === 'Caller';
        $people = [['Priya', 'Nair', 'Northwind Logistics'], ['Daniel', 'Okafor', 'Brightline Dental'], ['Sofia', 'Marquez', 'Peak Fitness'], ['Liam', 'Chen', 'Harbor Realty'], ['Emma', 'Novak', 'Atlas Builders']];
        [$first, $last, $company] = $unknown ? $people[mt_rand(0, count($people) - 1)] : [$lead->first_name, $lead->last_name, $lead->company ?: 'my company'];
        $roll = mt_rand(1, 100);
        $transfer = $call->agent ? app(CallService::class)->transferTarget($call->agent, $lead) : null;
        $scenario = match (true) {
            $transfer && $roll <= 30 => 'transfer',
            $roll <= 45 => 'meeting',
            $roll <= 70 => 'interested',
            $roll <= 90 => 'callback',
            default => 'not_interested',
        };

        $t = [['role' => 'agent', 'text' => strtr((string) $call->agent?->first_message ?: 'Thanks for calling {organization}. How can I help?', ['{organization}' => $org, '{first_name}' => $first, '{name}' => "{$first} {$last}", '{company}' => $company])]];
        if ($scenario === 'not_interested') {
            $t[] = ['role' => 'lead', 'text' => 'Oh — sorry, I think I dialled the wrong number. I was trying to reach a pharmacy.'];
            $t[] = ['role' => 'agent', 'text' => 'No problem at all. Have a lovely day!'];

            return ['status' => 'completed', 'final' => true, 'duration_seconds' => 24, 'transcript' => $t, 'summary' => 'Wrong number — the caller was looking for a different business. Not interested.'];
        }
        $t[] = ['role' => 'lead', 'text' => "Hi, this is {$first} {$last} from {$company}."];
        $t[] = ['role' => 'agent', 'text' => "Nice to meet you, {$first}. What can we help you with today?"];
        $t[] = ['role' => 'lead', 'text' => 'We keep losing track of enquiries and follow-ups, and I heard you could help with that.'];
        $t[] = ['role' => 'agent', 'text' => 'We can. When are you hoping to have something in place?'];
        $t[] = ['role' => 'lead', 'text' => 'Ideally within the next month or so.'];
        $email = strtolower($first).'@'.preg_replace('/[^a-z]/', '', strtolower($company)).'.com';

        if ($scenario === 'transfer') {
            $t[] = ['role' => 'lead', 'text' => 'Honestly I’d rather talk it through with someone on your team — is anyone available?'];
            $t[] = ['role' => 'agent', 'text' => "Of course. I'm connecting you to {$transfer['name']} now — one moment, {$first}."];

            return [
                'status' => 'completed', 'final' => true, 'duration_seconds' => 70, 'transcript' => $t, 'transferred' => true,
                'summary' => "{$first} {$last} ({$company}) called about managing enquiries, wanted to speak to a person and was transferred to {$transfer['name']}.",
                'caller' => ['first_name' => $first, 'last_name' => $last, 'company' => $company === 'my company' ? null : $company],
            ];
        }

        if ($scenario === 'callback') {
            $t[] = ['role' => 'lead', 'text' => "I'm driving right now though — could someone call me back Thursday afternoon?"];
            $t[] = ['role' => 'agent', 'text' => "Of course. I'll have one of the team call you back Thursday at 3 PM. Thanks, {$first}!"];
            $summary = "{$first} {$last} ({$company}) called in about managing enquiries and follow-ups; wants a callback Thursday afternoon. Timeline: within a month.";
        } elseif ($scenario === 'interested') {
            $t[] = ['role' => 'lead', 'text' => "Could you email me pricing first? It's {$email}."];
            $t[] = ['role' => 'agent', 'text' => "Absolutely — I'll send an overview and pricing to {$email} today. Thanks for calling, {$first}!"];
            $summary = "{$first} {$last} ({$company}) called in and is interested; asked for an overview and pricing by email. Timeline: within a month.";
        } else {
            $t[] = ['role' => 'agent', 'text' => 'Would a 30-minute demo with one of our specialists help? I have Tuesday at 10 AM free.'];
            $t[] = ['role' => 'lead', 'text' => 'Tuesday at 10 works for me.'];
            $t[] = ['role' => 'agent', 'text' => "Perfect — you're booked for Tuesday at 10 AM. You'll get an invite shortly. Thanks, {$first}!"];
            $summary = "{$first} {$last} ({$company}) called in about losing track of enquiries, needs a solution within a month and booked a demo for Tuesday at 10 AM.";
        }

        return [
            'status' => 'completed', 'final' => true, 'duration_seconds' => 90 + count($t) * 8, 'transcript' => $t, 'summary' => $summary,
            'caller' => ['first_name' => $first, 'last_name' => $last, 'company' => $company === 'my company' ? null : $company, 'email' => $scenario === 'interested' ? $email : null],
        ];
    }
}
