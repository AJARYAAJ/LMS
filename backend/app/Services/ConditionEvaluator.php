<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * Evaluates [{field, operator, value}] condition lists against a lead.
 * Shared by the scoring, assignment and automation engines.
 */
class ConditionEvaluator
{
    public const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with',
        'greater_than', 'less_than', 'is_empty', 'is_not_empty', 'in', 'business_email',
    ];

    public const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'aol.com',
        'icloud.com', 'protonmail.com', 'mail.com', 'gmx.com', 'yandex.com', 'zoho.com',
    ];

    /**
     * All conditions must match (logical AND). An empty list always matches.
     */
    public function matchesAll(?array $conditions, Lead $lead): bool
    {
        $data = $this->leadData($lead);

        foreach ($conditions ?? [] as $condition) {
            if (! $this->matches($condition, $data)) {
                return false;
            }
        }

        return true;
    }

    public function matches(array $condition, array $data): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $expected = $condition['value'] ?? null;
        $actual = $field ? data_get($data, $field) : null;

        if (is_array($actual)) {
            return match ($operator) {
                'contains', 'equals', 'in' => collect($actual)->contains(fn ($v) => strcasecmp((string) $v, (string) $expected) === 0),
                'not_contains', 'not_equals' => ! collect($actual)->contains(fn ($v) => strcasecmp((string) $v, (string) $expected) === 0),
                'is_empty' => $actual === [],
                'is_not_empty' => $actual !== [],
                default => false,
            };
        }

        $a = Str::lower(trim((string) $actual));
        $e = Str::lower(trim((string) $expected));

        return match ($operator) {
            'equals' => $a === $e,
            'not_equals' => $a !== $e,
            'contains' => $e !== '' && str_contains($a, $e),
            'not_contains' => $e === '' || ! str_contains($a, $e),
            'starts_with' => $e !== '' && str_starts_with($a, $e),
            'ends_with' => $e !== '' && str_ends_with($a, $e),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'is_empty' => $a === '',
            'is_not_empty' => $a !== '',
            'in' => in_array($a, array_map(fn ($v) => Str::lower(trim($v)), explode(',', $e)), true),
            'business_email' => $a !== '' && str_contains($a, '@')
                && ! in_array(Str::after($a, '@'), self::FREE_EMAIL_DOMAINS, true),
            default => false,
        };
    }

    /**
     * Flatten the lead into the shape conditions refer to, including a few
     * convenience keys (status_key, source_key, tags).
     */
    public function leadData(Lead $lead): array
    {
        $lead->load(['status', 'source', 'tags']);

        return array_merge($lead->attributesToArray(), [
            'status_key' => $lead->status?->key,
            'status_category' => $lead->status?->category,
            'source_key' => $lead->source?->key,
            'tags' => $lead->tags->pluck('name')->all(),
            'qualification_percent' => $this->qualificationPercent($lead),
            'last_call_outcome' => Call::where('lead_id', $lead->id)->whereNotNull('outcome')->latest('id')->value('outcome'),
        ]);
    }

    private function qualificationPercent(Lead $lead): int
    {
        $criteria = $lead->organization?->qualificationCriteria() ?? [];
        if (! $criteria) {
            return 0;
        }
        $answers = $lead->qualification ?? [];
        $done = collect($criteria)->filter(fn ($c) => ! empty($answers[$c['key']]))->count();

        return (int) round($done / count($criteria) * 100);
    }
}
