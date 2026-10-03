<?php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * Named date ranges ("last 30 days", "this quarter"…) resolved to concrete bounds,
 * plus the equally long period just before them for comparisons.
 */
class ReportRange
{
    public const PRESETS = [
        'last_7' => 'Last 7 days',
        'last_30' => 'Last 30 days',
        'last_90' => 'Last 90 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_quarter' => 'This quarter',
        'this_year' => 'This year',
        'last_12_months' => 'Last 12 months',
        'custom' => 'Custom',
    ];

    public function __construct(public Carbon $from, public Carbon $to, public string $preset, public string $label) {}

    public static function resolve(?string $preset = null, ?string $from = null, ?string $to = null): self
    {
        $now = now();
        if ($from || $to) {
            $preset = 'custom';
        }
        $preset = array_key_exists((string) $preset, self::PRESETS) ? $preset : 'last_30';

        [$start, $end] = match ($preset) {
            'last_7' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'last_90' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
            'this_month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfDay()],
            'this_year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'last_12_months' => [$now->copy()->subMonthsNoOverflow(11)->startOfMonth(), $now->copy()->endOfDay()],
            'custom' => [
                ($from ? Carbon::parse($from) : $now->copy()->subDays(29))->startOfDay(),
                ($to ? Carbon::parse($to) : $now->copy())->endOfDay(),
            ],
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $label = $preset === 'custom' ? $start->format('M j, Y').' – '.$end->format('M j, Y') : self::PRESETS[$preset];

        return new self($start, $end, $preset, $label);
    }

    /** The equally long period that ends right before this one. */
    public function previous(): self
    {
        $days = (int) $this->from->diffInDays($this->to) + 1;
        $to = $this->from->copy()->subDay()->endOfDay();

        return new self($to->copy()->subDays($days - 1)->startOfDay(), $to, 'custom', 'Previous period');
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function granularity(?string $requested = null): string
    {
        if (in_array($requested, ['day', 'week', 'month'], true)) {
            return $requested;
        }

        return $this->days() <= 31 ? 'day' : ($this->days() <= 183 ? 'week' : 'month');
    }

    public function toArray(): array
    {
        return ['preset' => $this->preset, 'label' => $this->label, 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
