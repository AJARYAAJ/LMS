<?php

namespace App\Reports;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Measures goals (monthly or quarterly targets for a person or the whole team)
 * with the report engine, and says whether each one is on pace.
 */
class GoalTracker
{
    /** metric => [label, format, report spec, dimension that holds the person] */
    public const METRICS = [
        'revenue_won' => ['label' => 'Revenue won', 'format' => 'money', 'spec' => ['entity' => 'deals', 'metric' => 'won_amount', 'date_field' => 'closed'], 'person' => 'owner'],
        'deals_won' => ['label' => 'Deals won', 'format' => 'number', 'spec' => ['entity' => 'deals', 'metric' => 'won_count', 'date_field' => 'closed'], 'person' => 'owner'],
        'leads_created' => ['label' => 'New leads', 'format' => 'number', 'spec' => ['entity' => 'leads', 'metric' => 'count'], 'person' => 'owner'],
        'leads_converted' => ['label' => 'Leads converted', 'format' => 'number', 'spec' => ['entity' => 'leads', 'metric' => 'count', 'date_field' => 'converted'], 'person' => 'owner'],
        'activities' => ['label' => 'Activities logged', 'format' => 'number', 'spec' => ['entity' => 'activities', 'metric' => 'count'], 'person' => 'user'],
        'calls_logged' => ['label' => 'Calls made', 'format' => 'number', 'spec' => ['entity' => 'activities', 'metric' => 'count', 'filters' => ['type' => ['call']]], 'person' => 'user'],
        'meetings_held' => ['label' => 'Meetings held', 'format' => 'number', 'spec' => ['entity' => 'activities', 'metric' => 'count', 'filters' => ['type' => ['meeting']]], 'person' => 'user'],
        'ai_meetings' => ['label' => 'Meetings booked by AI calls', 'format' => 'number', 'spec' => ['entity' => 'calls', 'metric' => 'meetings'], 'person' => 'started_by'],
        'tasks_completed' => ['label' => 'Tasks completed', 'format' => 'number', 'spec' => ['entity' => 'tasks', 'metric' => 'completed', 'date_field' => 'completed'], 'person' => 'assignee'],
    ];

    public function __construct(private ReportEngine $engine) {}

    public static function catalog(): array
    {
        return collect(self::METRICS)->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'format' => $m['format']])->values()->all();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function period(string $period, ?Carbon $now = null): array
    {
        $now ??= now();

        return $period === 'quarter'
            ? [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()]
            : [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
    }

    public function progress(Goal $goal): array
    {
        $def = self::METRICS[$goal->metric];
        [$start, $end] = self::period($goal->period);
        $range = ReportRange::resolve('custom', $start->toDateString(), $end->toDateString());

        $spec = $def['spec'];
        if ($goal->user_id) {
            $spec['filters'] = [...($spec['filters'] ?? []), $def['person'] => [$goal->user_id]];
        }

        // Goals are measured across the whole organization, whoever is looking.
        $actual = $this->engine->total((new User)->forceFill(['role' => User::ADMIN]), $spec, $range);

        $elapsed = min(1, max(0, $start->diffInSeconds(now()) / max(1, $start->diffInSeconds($end))));
        $percent = $goal->target > 0 ? round($actual / $goal->target * 100, 1) : 0;
        $status = match (true) {
            $actual >= $goal->target => 'achieved',
            $percent >= $elapsed * 100 - 5 => 'on_track',
            default => 'behind',
        };

        return [
            'id' => $goal->id,
            'metric' => $goal->metric,
            'metric_label' => $def['label'],
            'format' => $def['format'],
            'period' => $goal->period,
            'period_label' => $goal->period === 'quarter' ? 'Q'.$start->quarter.' '.$start->year : $start->format('F Y'),
            'user' => $goal->user ? ['id' => $goal->user->id, 'name' => $goal->user->name, 'avatar_color' => $goal->user->avatar_color] : null,
            'user_id' => $goal->user_id,
            'target' => $goal->target,
            'actual' => $actual,
            'percent' => $percent,
            'expected_percent' => round($elapsed * 100, 1),
            'status' => $status,
            'days_left' => max(0, (int) ceil(now()->diffInDays($end, false))),
        ];
    }
}
