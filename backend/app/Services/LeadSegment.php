<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Advanced segmentation: turns the same [{field, operator, value}] conditions
 * used by rules into an SQL query over leads, so segments scale to large data.
 */
class LeadSegment
{
    public function apply(Builder $query, array $conditions): Builder
    {
        foreach ($conditions as $c) {
            $field = $c['field'] ?? '';
            $op = $c['operator'] ?? 'equals';
            $value = (string) ($c['value'] ?? '');

            match (true) {
                $field === 'status_key' => $query->whereHas('status', fn ($q) => $this->compare($q, 'key', $op, $value)),
                $field === 'status_category' => $query->whereHas('status', fn ($q) => $this->compare($q, 'category', $op, $value)),
                $field === 'source_key' => $query->whereHas('source', fn ($q) => $this->compare($q, 'key', $op, $value)),
                $field === 'tags' => in_array($op, ['not_equals', 'not_contains', 'is_empty'], true)
                    ? $query->whereDoesntHave('tags', $op === 'is_empty' ? null : fn ($q) => $q->whereRaw('lower(tags.name) = ?', [Str::lower($value)]))
                    : $query->whereHas('tags', $op === 'is_not_empty' ? null : fn ($q) => $q->whereRaw('lower(tags.name) = ?', [Str::lower($value)])),
                in_array($field, Lead::CONDITION_FIELDS, true) => $this->compare($query, "leads.{$field}", $op, $value),
                default => null,
            };
        }

        return $query;
    }

    private function compare(Builder $q, string $column, string $op, string $value): Builder
    {
        $lower = Str::lower($value);

        return match ($op) {
            'equals' => $q->whereRaw("lower(cast({$column} as varchar(255))) = ?", [$lower]),
            'not_equals' => $q->where(fn ($w) => $w->whereNull($column)->orWhereRaw("lower(cast({$column} as varchar(255))) <> ?", [$lower])),
            'contains' => $q->whereLike($column, "%{$value}%"),
            'not_contains' => $q->where(fn ($w) => $w->whereNull($column)->orWhereNotLike($column, "%{$value}%")),
            'starts_with' => $q->whereLike($column, "{$value}%"),
            'ends_with' => $q->whereLike($column, "%{$value}"),
            'greater_than' => is_numeric($value) ? $q->where($column, '>', (float) $value) : $q,
            'less_than' => is_numeric($value) ? $q->where($column, '<', (float) $value) : $q,
            'is_empty' => $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, '')),
            'is_not_empty' => $q->whereNotNull($column)->where($column, '!=', ''),
            'in' => $q->whereIn($column, array_map('trim', explode(',', $value))),
            'business_email' => $q->whereNotNull($column)->where(function ($w) use ($column) {
                foreach (ConditionEvaluator::FREE_EMAIL_DOMAINS as $d) {
                    $w->whereNotLike($column, "%@{$d}");
                }
            }),
            default => $q,
        };
    }
}
