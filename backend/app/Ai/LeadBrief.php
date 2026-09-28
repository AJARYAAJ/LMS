<?php

namespace App\Ai;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/**
 * Structured output returned by Claude for a lead brief.
 */
class LeadBrief implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'Two or three sentences a sales rep can read in 10 seconds: who the lead is, what they need, and where the deal stands.')]
    public string $summary;

    #[Constrained(description: 'The single most valuable next step, as a short imperative phrase (max 8 words).')]
    public string $next_action_title;

    #[Constrained(description: 'One sentence explaining why this is the right next step, grounded in the lead data.')]
    public string $next_action_reason;

    #[Constrained(description: 'Up to three short talking points for the next conversation, separated by newline characters.')]
    public string $talking_points;

    #[Constrained(description: 'The main risk to winning this lead in one short sentence, or an empty string if none is evident.')]
    public string $risk;
}
