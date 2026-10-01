<?php

namespace App\Reports;

use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Touchpoint;
use App\Models\User;
use App\Security\FieldPermissions;

/**
 * Multi-touch attribution: which campaigns, sources or channels deserve credit
 * for conversions and won revenue. Every lead created in the range spreads its
 * credit over the touches it had before converting, three ways:
 * first touch (100% to the first), last touch (100% to the last) and linear
 * (equal shares).
 */
class Attribution
{
    public const BY = ['campaign', 'source', 'channel'];

    public const CHANNELS = [
        'capture' => 'Added manually', 'manual' => 'Added manually', 'web_form' => 'Web form', 'booking_page' => 'Booking page',
        'email_click' => 'Email click', 'phone' => 'Phone call', 'api' => 'API', 'import' => 'Import', 'webhook' => 'Integration',
    ];

    public function run(User $user, string $by, ReportRange $range): array
    {
        $by = in_array($by, self::BY, true) ? $by : 'campaign';
        $showRevenue = ! in_array('amount', FieldPermissions::for($user, 'deal')['hidden'], true);
        $rows = [];
        $totals = ['leads' => 0, 'converted' => 0, 'revenue' => 0.0, 'touches' => 0];
        $credit = function (string $key, string $field, float $value) use (&$rows) {
            $rows[$key] ??= ['key' => $key, 'touches' => 0, 'leads' => ['first' => 0.0, 'last' => 0.0, 'linear' => 0.0],
                'conversions' => ['first' => 0.0, 'last' => 0.0, 'linear' => 0.0], 'revenue' => ['first' => 0.0, 'last' => 0.0, 'linear' => 0.0]];
            [$metric, $model] = explode('.', $field);
            $rows[$key][$metric][$model] += $value;
        };

        Lead::visibleTo($user)->whereBetween('created_at', [$range->from, $range->to])
            ->select('id', 'converted_at')
            ->chunkById(1000, function ($leads) use ($by, $credit, &$rows, &$totals) {
                $ids = $leads->pluck('id');
                $touches = Touchpoint::whereIn('lead_id', $ids)->orderBy('occurred_at')->orderBy('id')->get()->groupBy('lead_id');
                $revenue = Deal::whereIn('lead_id', $ids)->where('status', 'won')->groupBy('lead_id')->selectRaw('lead_id, sum(amount) as total')->pluck('total', 'lead_id');

                foreach ($leads as $lead) {
                    $path = ($touches[$lead->id] ?? collect())
                        ->when($lead->converted_at, fn ($c) => $c->filter(fn ($t) => $t->occurred_at <= $lead->converted_at))
                        ->values();
                    if ($path->isEmpty()) {
                        continue;
                    }
                    $keys = $path->map(fn (Touchpoint $t) => (string) (match ($by) {
                        'campaign' => $t->campaign_id,
                        'source' => $t->lead_source_id,
                        default => $t->channel,
                    } ?? ''))->all();
                    $won = (float) ($revenue[$lead->id] ?? 0);
                    $converted = $lead->converted_at ? 1.0 : 0.0;
                    $share = 1 / count($keys);

                    foreach ($keys as $k) {
                        $credit($k, 'leads.linear', $share);
                        $rows[$k]['touches']++;
                        $credit($k, 'conversions.linear', $converted * $share);
                        $credit($k, 'revenue.linear', $won * $share);
                    }
                    foreach (['first' => $keys[0], 'last' => end($keys)] as $model => $k) {
                        $credit($k, "leads.{$model}", 1);
                        $credit($k, "conversions.{$model}", $converted);
                        $credit($k, "revenue.{$model}", $won);
                    }
                    $totals['leads']++;
                    $totals['converted'] += $converted;
                    $totals['revenue'] += $won;
                    $totals['touches'] += count($keys);
                }
            });

        $labels = $this->labels($by, array_keys($rows));
        $out = collect($rows)->map(function ($r) use ($labels, $showRevenue) {
            $round = fn (array $m, int $p) => array_map(fn ($v) => round($v, $p), $m);

            return [
                'key' => $r['key'], 'label' => $labels[$r['key']] ?? $r['key'], 'touches' => $r['touches'],
                'leads' => $round($r['leads'], 1), 'conversions' => $round($r['conversions'], 1),
                'revenue' => $showRevenue ? $round($r['revenue'], 2) : null,
            ];
        })->sortByDesc(fn ($r) => [$r['revenue']['linear'] ?? 0, $r['conversions']['linear'], $r['leads']['linear']])->values();

        return [
            'by' => $by,
            'range' => $range->toArray(),
            'rows' => $out->all(),
            'totals' => [...$totals, 'revenue' => $showRevenue ? round($totals['revenue'], 2) : null],
        ];
    }

    private function labels(string $by, array $keys): array
    {
        $none = ['campaign' => 'No campaign', 'source' => 'No source', 'channel' => 'Other'][$by];
        $ids = array_filter($keys, fn ($k) => $k !== '');
        $names = match ($by) {
            'campaign' => Campaign::whereIn('id', $ids)->pluck('name', 'id')->all(),
            'source' => LeadSource::whereIn('id', $ids)->pluck('name', 'id')->all(),
            default => collect($ids)->mapWithKeys(fn ($k) => [$k => self::CHANNELS[$k] ?? ucfirst(str_replace('_', ' ', $k))])->all(),
        };

        return ['' => $none] + $names;
    }
}
