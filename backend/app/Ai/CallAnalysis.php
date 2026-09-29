<?php

namespace App\Ai;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/** Structured result Claude extracts from a sales-call transcript. */
class CallAnalysis implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'One of: interested, not_interested, callback, meeting_booked, wrong_number, voicemail, no_answer')]
    public string $outcome;

    #[Constrained(description: 'One of: positive, neutral, negative')]
    public string $sentiment;

    #[Constrained(description: 'Two sentences summarising the call for the sales rep.')]
    public string $summary;

    #[Constrained(description: 'Comma separated qualification keys the lead clearly confirmed on the call, chosen only from the allowed keys given in the prompt. Empty string if none.')]
    public string $confirmed_keys;

    #[Constrained(description: 'If a callback or meeting time was agreed, an ISO 8601 date-time for it relative to the given current date; otherwise an empty string.')]
    public string $follow_up_at;

    #[Constrained(description: 'The agreed next step in a short phrase, or an empty string.')]
    public string $next_step;
}
