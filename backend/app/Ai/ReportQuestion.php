<?php

namespace App\Ai;

use Anthropic\Lib\Attributes\Constrained;
use Anthropic\Lib\Concerns\StructuredOutputModelTrait;
use Anthropic\Lib\Contracts\StructuredOutputModel;

/** A report spec Claude derives from a plain-English question. */
class ReportQuestion implements StructuredOutputModel
{
    use StructuredOutputModelTrait;

    #[Constrained(description: 'Record type key from the catalog: leads, deals, activities, tasks or calls.')]
    public string $entity;

    #[Constrained(description: 'Measure key from that record type in the catalog.')]
    public string $metric;

    #[Constrained(description: 'Grouping key from that record type, or an empty string for a single number.')]
    public string $dimension;

    #[Constrained(description: 'Optional second grouping key (not a date) to split each group into coloured series, or an empty string.')]
    public string $split;

    #[Constrained(description: 'Optional date grouping key that decides which date the range applies to (e.g. closed for won revenue), or an empty string.')]
    public string $date_field;

    #[Constrained(description: 'Filters as "key=value1|value2; key2=value" using only fixed-choice grouping keys and their listed values, or an empty string.')]
    public string $filters;

    #[Constrained(description: 'One of: last_7, last_30, last_90, this_month, last_month, this_quarter, this_year, last_12_months.')]
    public string $range;

    #[Constrained(description: 'One of: bar, stacked, line, area, pie, table, number.')]
    public string $chart;

    #[Constrained(description: 'A short title for the report, under 60 characters.')]
    public string $title;
}
