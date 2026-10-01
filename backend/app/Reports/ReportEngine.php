<?php

namespace App\Reports;

use App\Models\Organization;
use App\Models\User;
use App\Support\Sql;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Runs a report spec — "measure X of <entity>, grouped by Y (and split by Z)" — as a
 * single grouped SQL query, then buckets dates, folds long tails into "Other" and
 * resolves labels and colours in PHP.
 *
 * Spec keys: entity, metric, dimension?, split?, filters? {dimension: [values]},
 * date_field?, range?, from?, to?, granularity?, chart?, limit?
 */
class ReportEngine
{
    public const CHARTS = ['bar', 'stacked', 'line', 'area', 'pie', 'table', 'number'];

    /** A chart never shows more than eight coloured series; the rest fold into "Other". */
    public const MAX_SERIES = 8;

    private const NONE = '__none';

    private const OTHER = '__other';

    public function run(User $user, array $spec, ?ReportRange $range = null): array
    {
        $spec = $this->normalize($spec);
        $def = Entities::get($spec['entity']);
        $metric = $def['metrics'][$spec['metric']];
        $dim = $spec['dimension'] ? $def['dimensions'][$spec['dimension']] : null;
        $split = $spec['split'] ? $def['dimensions'][$spec['split']] : null;
        $range ??= ReportRange::resolve($spec['range'], $spec['from'], $spec['to']);
        $granularity = $range->granularity($spec['granularity']);

        $cells = $this->aggregate($user, $spec, $def, $range, $granularity);
        $format = $metric['format'];

        // Split series: keep the biggest ones, fold the rest into "Other".
        $series = [];
        if ($split) {
            $totals = [];
            foreach ($cells as $cell) {
                foreach ($cell['s'] as $sk => $aliases) {
                    $totals[$sk] = $this->add($totals[$sk] ?? [], $aliases);
                }
            }
            $keys = $this->rankKeys($totals, $metric, $split);
            if (count($keys) > self::MAX_SERIES) {
                $keep = array_slice($keys, 0, self::MAX_SERIES - 1);
                foreach ($cells as &$cell) {
                    foreach ($cell['s'] as $sk => $aliases) {
                        if (! in_array($sk, $keep, true)) {
                            $cell['s'][self::OTHER] = $this->add($cell['s'][self::OTHER] ?? [], $aliases);
                            unset($cell['s'][$sk]);
                        }
                    }
                }
                unset($cell);
                $keys = [...$keep, self::OTHER];
            }
            $labels = $this->labels($split, $keys);
            $series = array_map(fn ($k) => ['key' => $k, ...$labels[$k]], $keys);
        }

        // Rows
        if ($dim && $dim['type'] === 'date') {
            $cells = $this->fillDates($cells, $range, $granularity);
            $keys = array_keys($cells);
        } elseif ($dim) {
            $keys = $this->rankKeys(array_map(fn ($c) => $c['m'], $cells), $metric, $dim);
            if (count($keys) > $spec['limit']) {
                $keep = array_slice($keys, 0, $spec['limit'] - 1);
                $other = ['m' => [], 's' => []];
                foreach (array_diff($keys, $keep) as $k) {
                    $other['m'] = $this->add($other['m'], $cells[$k]['m']);
                    foreach ($cells[$k]['s'] as $sk => $a) {
                        $other['s'][$sk] = $this->add($other['s'][$sk] ?? [], $a);
                    }
                    unset($cells[$k]);
                }
                $cells[self::OTHER] = $other;
                $keys = [...$keep, self::OTHER];
            }
        } else {
            $keys = array_keys($cells);
        }

        $labels = $dim ? $this->labels($dim, $keys, $granularity) : [];
        $rows = [];
        $all = [];
        foreach ($keys as $k) {
            $cell = $cells[$k] ?? ['m' => [], 's' => []];
            $all = $this->add($all, $cell['m']);
            $row = ['key' => (string) $k, ...($labels[$k] ?? ['label' => 'Total', 'color' => null]), 'value' => $this->value($metric, $cell['m'])];
            if ($split) {
                $row['values'] = [];
                foreach ($series as $s) {
                    $row['values'][$s['key']] = isset($cell['s'][$s['key']]) ? $this->value($metric, $cell['s'][$s['key']]) : 0;
                }
            }
            $rows[] = $row;
        }

        return [
            'spec' => $spec,
            'entity_label' => $def['label'],
            'metric_label' => $metric['label'],
            'dimension_label' => $dim['label'] ?? null,
            'split_label' => $split['label'] ?? null,
            'dimension_type' => $dim['type'] ?? null,
            'format' => $format,
            'granularity' => $dim && $dim['type'] === 'date' ? $granularity : null,
            'range' => $range->toArray(),
            'total' => $this->value($metric, $all),
            'rows' => $rows,
            'series' => $series,
        ];
    }

    /** A single number (no grouping) — used by KPI tiles and goals. */
    public function total(User $user, array $spec, ReportRange $range): float
    {
        return $this->run($user, [...$spec, 'dimension' => null, 'split' => null], $range)['total'];
    }

    /** The organization's speed-to-lead target in hours (Settings → Organization). */
    public static function responseTargetHours(): float
    {
        $hours = Tenant::check() ? (Organization::find(Tenant::id())?->settings['response_sla_hours'] ?? null) : null;

        return max(0.05, (float) ($hours ?: 1));
    }

    public function normalize(array $spec): array
    {
        $entity = $spec['entity'] ?? null;
        if (! in_array($entity, Entities::KEYS, true)) {
            throw ValidationException::withMessages(['spec.entity' => 'Choose what to report on.']);
        }
        $def = Entities::get($entity);
        $dims = $def['dimensions'];

        $metric = $spec['metric'] ?? 'count';
        if (! isset($def['metrics'][$metric])) {
            throw ValidationException::withMessages(['spec.metric' => "Unknown measure for {$def['label']}."]);
        }
        $dimension = $spec['dimension'] ?? null;
        if ($dimension !== null && ! isset($dims[$dimension])) {
            throw ValidationException::withMessages(['spec.dimension' => "Unknown grouping for {$def['label']}."]);
        }
        $split = $spec['split'] ?? null;
        if ($split !== null && (! isset($dims[$split]) || $dims[$split]['type'] === 'date' || $split === $dimension)) {
            throw ValidationException::withMessages(['spec.split' => 'Split by a different, non-date field.']);
        }
        $dateField = $spec['date_field'] ?? null;
        if ($dateField !== null && ($dims[$dateField]['type'] ?? null) !== 'date') {
            throw ValidationException::withMessages(['spec.date_field' => 'Pick one of the date fields.']);
        }

        $filters = [];
        foreach ((array) ($spec['filters'] ?? []) as $key => $values) {
            $d = $dims[$key] ?? null;
            if (! $d || $d['type'] === 'date' || ($d['filterable'] ?? true) === false) {
                throw ValidationException::withMessages(['spec.filters' => "Can't filter on [{$key}]."]);
            }
            $values = array_values(array_filter((array) $values, fn ($v) => $v === null || is_scalar($v)));
            if ($values) {
                $filters[$key] = array_slice($values, 0, 50);
            }
        }

        $chart = $spec['chart'] ?? ($dimension === null ? 'number' : (($dims[$dimension]['type'] ?? null) === 'date' ? 'line' : 'bar'));

        return [
            'entity' => $entity,
            'metric' => $metric,
            'dimension' => $dimension,
            'split' => $split,
            'date_field' => $dateField,
            'filters' => $filters,
            'chart' => in_array($chart, self::CHARTS, true) ? $chart : 'bar',
            'range' => array_key_exists((string) ($spec['range'] ?? ''), ReportRange::PRESETS) ? $spec['range'] : 'last_30',
            'from' => $spec['from'] ?? null,
            'to' => $spec['to'] ?? null,
            'granularity' => in_array($spec['granularity'] ?? null, ['day', 'week', 'month'], true) ? $spec['granularity'] : null,
            'limit' => max(2, min(50, (int) ($spec['limit'] ?? 12))),
        ];
    }

    /** One grouped query → [key => ['m' => aliases, 's' => [splitKey => aliases]]]. */
    private function aggregate(User $user, array $spec, array $def, ReportRange $range, string $granularity): array
    {
        $dims = $def['dimensions'];
        $dim = $spec['dimension'] ? $dims[$spec['dimension']] : null;
        $split = $spec['split'] ? $dims[$spec['split']] : null;
        $metric = $def['metrics'][$spec['metric']];

        $dateDim = match (true) {
            $dim !== null && $dim['type'] === 'date' => $dim,
            $spec['date_field'] !== null => $dims[$spec['date_field']],
            default => ['column' => $def['date']],
        };
        $bounds = ($dateDim['date_only'] ?? false)
            ? [$range->from->toDateString(), $range->to->toDateString()]
            : [$range->from, $range->to];

        $query = $def['query']($user)->whereBetween($dateDim['column'], $bounds);

        foreach ($spec['filters'] as $key => $values) {
            $column = DB::raw($dims[$key]['column']);
            $present = array_values(array_filter($values, fn ($v) => $v !== null && $v !== self::NONE));
            $withNull = count($present) !== count($values);
            $query->where(function ($q) use ($column, $present, $withNull) {
                if ($present) {
                    $q->whereIn($column, $present);
                }
                if ($withNull) {
                    $q->orWhereNull($column);
                }
            });
        }

        $select = [];
        $group = [];
        if ($dim) {
            $expr = $dim['type'] === 'date' ? Sql::date($dim['column']) : $dim['column'];
            $select[] = "{$expr} as k";
            $group[] = $expr;
        }
        if ($split) {
            $select[] = "{$split['column']} as s";
            $group[] = $split['column'];
        }
        foreach ($metric['select'] as $alias => $sql) {
            $sql = str_replace('__SLA__', (string) self::responseTargetHours(), $sql);
            $select[] = "{$sql} as m_{$alias}";
        }

        $query->selectRaw(implode(', ', $select));
        foreach ($group as $expr) {
            $query->groupByRaw($expr);
        }

        $cells = [];
        foreach ($query->toBase()->get() as $r) {
            $r = (array) $r;
            $k = $dim ? $this->bucket($r['k'], $dim, $granularity) : '__total';
            $aliases = [];
            foreach (array_keys($metric['select']) as $alias) {
                $aliases[$alias] = (float) ($r["m_{$alias}"] ?? 0);
            }
            $cells[$k] ??= ['m' => [], 's' => []];
            $cells[$k]['m'] = $this->add($cells[$k]['m'], $aliases);
            if ($split) {
                $sk = $r['s'] === null || $r['s'] === '' ? self::NONE : (string) $r['s'];
                $cells[$k]['s'][$sk] = $this->add($cells[$k]['s'][$sk] ?? [], $aliases);
            }
        }

        if (! $dim && ! $cells) {
            $cells['__total'] = ['m' => array_fill_keys(array_keys($metric['select']), 0.0), 's' => []];
        }

        return $cells;
    }

    private function bucket(mixed $value, array $dim, string $granularity): string
    {
        if ($value === null || $value === '') {
            return self::NONE;
        }
        if ($dim['type'] !== 'date') {
            return (string) $value;
        }
        $day = substr((string) $value, 0, 10);

        return match ($granularity) {
            'week' => Carbon::parse($day)->startOfWeek()->toDateString(),
            'month' => substr($day, 0, 7),
            default => $day,
        };
    }

    private function fillDates(array $cells, ReportRange $range, string $granularity): array
    {
        $filled = [];
        $cursor = $range->from->copy()->startOfDay();
        $dim = ['type' => 'date'];
        while ($cursor->lessThanOrEqualTo($range->to)) {
            $k = $this->bucket($cursor->toDateString(), $dim, $granularity);
            $filled[$k] = $cells[$k] ?? ['m' => [], 's' => []];
            $cursor = match ($granularity) {
                'week' => $cursor->addWeek()->startOfWeek(),
                'month' => $cursor->addMonthNoOverflow()->startOfMonth(),
                default => $cursor->addDay(),
            };
        }

        return $filled;
    }

    /** Order keys: enums keep their natural order, everything else by value (largest first). */
    private function rankKeys(array $aliasesByKey, array $metric, array $dim): array
    {
        $keys = array_map('strval', array_keys($aliasesByKey));
        if ($dim['type'] === 'enum') {
            $order = array_flip(array_map('strval', $dim['values']));
            usort($keys, fn ($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

            return $keys;
        }
        usort($keys, fn ($a, $b) => $this->value($metric, $aliasesByKey[$b]) <=> $this->value($metric, $aliasesByKey[$a]) ?: strcmp($a, $b));

        return $keys;
    }

    /** @return array<string, array{label: string, color: ?string}> */
    private function labels(array $dim, array $keys, string $granularity = 'day'): array
    {
        $labels = [];
        $found = collect();
        $ids = array_filter($keys, fn ($k) => ! in_array($k, [self::NONE, self::OTHER], true));

        if ($dim['type'] === 'model' && $ids) {
            $found = $dim['model']::query()->whereIn('id', $ids)->get(array_filter(['id', 'name', $dim['color'] ?? null]))->keyBy('id');
        }

        foreach ($keys as $k) {
            $labels[$k] = match (true) {
                $k === self::OTHER => ['label' => 'Other', 'color' => null],
                $k === self::NONE => ['label' => $dim['empty'] ?? 'None', 'color' => null],
                $dim['type'] === 'date' => ['label' => $this->dateLabel($k, $granularity), 'color' => null],
                $dim['type'] === 'model' => [
                    'label' => $found[$k]->name ?? 'Deleted',
                    'color' => isset($dim['color']) ? ($found[$k]->{$dim['color']} ?? null) : null,
                ],
                $dim['type'] === 'enum' => ['label' => Entities::valueLabel($dim, (string) $k), 'color' => null],
                default => ['label' => (string) $k, 'color' => null],
            };
        }

        return $labels;
    }

    private function dateLabel(string $key, string $granularity): string
    {
        return match ($granularity) {
            'month' => Carbon::parse($key.'-01')->format('M Y'),
            'week' => Carbon::parse($key)->format('M j'),
            default => Carbon::parse($key)->format('M j'),
        };
    }

    private function add(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = ($a[$k] ?? 0) + $v;
        }

        return $a;
    }

    private function value(array $metric, array $aliases): float
    {
        return round((float) $metric['value']($aliases + array_fill_keys(array_keys($metric['select']), 0.0)), 2);
    }
}
