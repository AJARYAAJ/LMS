<?php

namespace App\Voice;

use App\Models\AiAgent;
use App\Models\Lead;

/** Builds the task description and variables sent to voice vendors. */
class Prompt
{
    public static function variables(AiAgent $agent, Lead $lead): array
    {
        return [
            'lead_name' => $lead->full_name,
            'first_name' => $lead->first_name,
            'company' => (string) $lead->company,
            'job_title' => (string) $lead->job_title,
            'requirements' => (string) $lead->requirements,
            'organization' => (string) $lead->organization?->name,
            'goal' => $agent->goal,
            'questions' => collect($agent->questions ?? [])->pluck('question')->implode(' | '),
        ];
    }

    public static function task(AiAgent $agent, Lead $lead): string
    {
        $company = $lead->company ? ' at '.$lead->company : '';
        $questions = collect($agent->questions ?? [])->map(fn ($q, $i) => ($i + 1).'. '.$q['question'])->implode("\n");

        return trim(<<<TXT
        You are a friendly, concise sales development representative calling on behalf of {$lead->organization?->name}.
        You are calling {$lead->full_name}{$company}.
        Goal: {$agent->goal}
        Ask these questions naturally, one at a time:
        {$questions}
        If they are busy, offer to call back and agree a time. If they are not interested, thank them and end politely.
        Never invent prices or commitments. Keep the call under {$agent->max_duration_seconds} seconds.
        TXT);
    }

    public static function firstMessage(AiAgent $agent, Lead $lead): string
    {
        return strtr($agent->first_message, [
            '{first_name}' => $lead->first_name,
            '{name}' => $lead->full_name,
            '{company}' => (string) $lead->company,
            '{organization}' => (string) $lead->organization?->name,
        ]);
    }
}
