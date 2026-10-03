<?php

namespace App\Reports;

use App\Models\User;
use App\Security\FieldPermissions;
use InvalidArgumentException;

/**
 * Ready-made report pages, one per area of the app. Each is a row of KPI tiles
 * (with change against the previous period) and a set of charts, all run
 * through the same ReportEngine as the report studio.
 */
class TypeReports
{
    public const TYPES = [
        'leads' => 'Leads',
        'pipeline' => 'Pipeline',
        'activities' => 'Activities',
        'tasks' => 'Tasks',
        'calls' => 'AI calls',
        'messaging' => 'Messaging',
    ];

    public function __construct(private ReportEngine $engine) {}

    public function build(User $user, string $type, ReportRange $range): array
    {
        $def = $this->definition($type);
        $previous = $range->previous();

        $kpis = array_map(function (array $kpi) use ($user, $range, $previous) {
            if (! $this->allowed($user, $kpi)) {
                return null;
            }
            $spec = ['entity' => $kpi['entity'], 'metric' => $kpi['metric'], 'filters' => $kpi['filters'] ?? [], 'date_field' => $kpi['date_field'] ?? null];
            $now = $this->engine->total($user, $spec, $range);
            $before = $this->engine->total($user, $spec, $previous);
            $format = Entities::get($kpi['entity'])['metrics'][$kpi['metric']]['format'];
            // Rates change in points, counts and money in percent.
            // No comparison when the previous period had nothing to compare with.
            $delta = $before <= 0 ? null : ($format === 'percent' ? round($now - $before, 1) : round(($now - $before) / $before * 100, 1));

            return [
                'label' => $kpi['label'],
                'value' => $now,
                'previous' => $before,
                'delta' => $delta,
                'delta_unit' => $format === 'percent' ? 'pts' : '%',
                'format' => $format,
                'better' => $kpi['better'] ?? 'up',
                'spec' => $spec,
            ];
        }, $def['kpis']);
        $kpis = array_values(array_filter($kpis));

        $widgets = array_map(fn (array $w) => ! $this->allowed($user, $w['spec']) ? null : [
            'title' => $w['title'],
            'subtitle' => $w['subtitle'] ?? null,
            'span' => $w['span'] ?? 1,
            'result' => $this->engine->run($user, [...$w['spec'], 'range' => 'custom'], $range),
        ], $def['widgets']);
        $widgets = array_values(array_filter($widgets));

        return ['type' => $type, 'title' => self::TYPES[$type], 'range' => $range->toArray(), 'kpis' => $kpis, 'widgets' => $widgets];
    }

    /** Leave out tiles built on fields this person's role can't see. */
    private function allowed(User $user, array $spec): bool
    {
        $metric = Entities::get($spec['entity'])['metrics'][$spec['metric']] ?? [];
        $entity = ['leads' => 'lead', 'deals' => 'deal'][$spec['entity']] ?? null;

        return ! ($entity && isset($metric['requires']) && in_array($metric['requires'], FieldPermissions::for($user, $entity)['hidden'], true));
    }

    private function definition(string $type): array
    {
        $messaging = ['type' => ['email', 'sms', 'whatsapp']];

        return match ($type) {
            'leads' => [
                'kpis' => [
                    ['label' => 'New leads', 'entity' => 'leads', 'metric' => 'count'],
                    ['label' => 'Qualified', 'entity' => 'leads', 'metric' => 'qualified'],
                    ['label' => 'Conversion rate', 'entity' => 'leads', 'metric' => 'conversion_rate'],
                    ['label' => 'Average score', 'entity' => 'leads', 'metric' => 'avg_score'],
                    ['label' => 'Expected value', 'entity' => 'leads', 'metric' => 'expected_value'],
                    ['label' => 'Average first response', 'entity' => 'leads', 'metric' => 'response_hours', 'better' => 'down'],
                    ['label' => 'Contacted within target', 'entity' => 'leads', 'metric' => 'within_sla_rate'],
                    ['label' => 'Leads contacted', 'entity' => 'leads', 'metric' => 'responded_rate'],
                    ['label' => 'Days to convert', 'entity' => 'leads', 'metric' => 'days_to_convert', 'better' => 'down'],
                    ['label' => 'Converted', 'entity' => 'leads', 'metric' => 'converted', 'date_field' => 'converted'],
                ],
                'widgets' => [
                    ['title' => 'New leads by source', 'subtitle' => 'Where each day’s leads came from', 'span' => 2, 'spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'created', 'split' => 'source', 'chart' => 'stacked']],
                    ['title' => 'Leads by status', 'spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'status', 'chart' => 'pie']],
                    ['title' => 'Conversion rate by source', 'spec' => ['entity' => 'leads', 'metric' => 'conversion_rate', 'dimension' => 'source', 'chart' => 'bar']],
                    ['title' => 'Leads by rating', 'subtitle' => 'Split by priority', 'spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'rating', 'split' => 'priority', 'chart' => 'stacked']],
                    ['title' => 'Leads by owner', 'spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'owner', 'chart' => 'bar']],
                    ['title' => 'Top industries', 'spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'industry', 'chart' => 'bar', 'limit' => 8]],
                    ['title' => 'Conversions over time', 'spec' => ['entity' => 'leads', 'metric' => 'count', 'dimension' => 'converted', 'chart' => 'area']],
                    ['title' => 'Speed to lead by owner', 'subtitle' => 'Average hours from capture to first call, email or message', 'spec' => ['entity' => 'leads', 'metric' => 'response_hours', 'dimension' => 'owner', 'chart' => 'bar']],
                    ['title' => 'Contacted within target, by source', 'spec' => ['entity' => 'leads', 'metric' => 'within_sla_rate', 'dimension' => 'source', 'chart' => 'bar']],
                    ['title' => 'Days to convert by source', 'spec' => ['entity' => 'leads', 'metric' => 'days_to_convert', 'dimension' => 'source', 'date_field' => 'converted', 'chart' => 'bar']],
                ],
            ],
            'pipeline' => [
                'kpis' => [
                    ['label' => 'New deals', 'entity' => 'deals', 'metric' => 'count'],
                    ['label' => 'Pipeline added', 'entity' => 'deals', 'metric' => 'amount'],
                    ['label' => 'Won revenue', 'entity' => 'deals', 'metric' => 'won_amount', 'date_field' => 'closed'],
                    ['label' => 'Win rate', 'entity' => 'deals', 'metric' => 'win_rate', 'date_field' => 'closed'],
                    ['label' => 'Average deal size', 'entity' => 'deals', 'metric' => 'avg_amount'],
                ],
                'widgets' => [
                    ['title' => 'Won revenue over time', 'span' => 2, 'spec' => ['entity' => 'deals', 'metric' => 'won_amount', 'dimension' => 'closed', 'chart' => 'bar']],
                    ['title' => 'Pipeline value by stage', 'spec' => ['entity' => 'deals', 'metric' => 'amount', 'dimension' => 'stage', 'chart' => 'bar']],
                    ['title' => 'Deals by status', 'spec' => ['entity' => 'deals', 'metric' => 'count', 'dimension' => 'status', 'chart' => 'pie']],
                    ['title' => 'Won revenue by owner', 'spec' => ['entity' => 'deals', 'metric' => 'won_amount', 'dimension' => 'owner', 'date_field' => 'closed', 'chart' => 'bar']],
                    ['title' => 'Win rate by owner', 'spec' => ['entity' => 'deals', 'metric' => 'win_rate', 'dimension' => 'owner', 'date_field' => 'closed', 'chart' => 'bar']],
                    ['title' => 'Why deals are lost', 'spec' => ['entity' => 'deals', 'metric' => 'count', 'dimension' => 'lost_reason', 'filters' => ['status' => ['lost']], 'date_field' => 'closed', 'chart' => 'bar']],
                    ['title' => 'Open pipeline, weighted', 'subtitle' => 'Deals created in the period, by stage', 'spec' => ['entity' => 'deals', 'metric' => 'weighted', 'dimension' => 'stage', 'filters' => ['status' => ['open']], 'chart' => 'table']],
                ],
            ],
            'activities' => [
                'kpis' => [
                    ['label' => 'Activities', 'entity' => 'activities', 'metric' => 'count'],
                    ['label' => 'Calls', 'entity' => 'activities', 'metric' => 'count', 'filters' => ['type' => ['call']]],
                    ['label' => 'Meetings', 'entity' => 'activities', 'metric' => 'count', 'filters' => ['type' => ['meeting']]],
                    ['label' => 'Emails', 'entity' => 'activities', 'metric' => 'count', 'filters' => ['type' => ['email']]],
                    ['label' => 'Time logged (min)', 'entity' => 'activities', 'metric' => 'minutes'],
                ],
                'widgets' => [
                    ['title' => 'Activity by type', 'subtitle' => 'Everything the team logged', 'span' => 2, 'spec' => ['entity' => 'activities', 'metric' => 'count', 'dimension' => 'date', 'split' => 'type', 'chart' => 'stacked']],
                    ['title' => 'Mix by type', 'spec' => ['entity' => 'activities', 'metric' => 'count', 'dimension' => 'type', 'chart' => 'pie']],
                    ['title' => 'Activity by rep', 'spec' => ['entity' => 'activities', 'metric' => 'count', 'dimension' => 'user', 'split' => 'type', 'chart' => 'stacked']],
                    ['title' => 'Call outcomes', 'spec' => ['entity' => 'activities', 'metric' => 'count', 'dimension' => 'outcome', 'filters' => ['type' => ['call']], 'chart' => 'bar', 'limit' => 8]],
                    ['title' => 'Time spent by rep (min)', 'spec' => ['entity' => 'activities', 'metric' => 'minutes', 'dimension' => 'user', 'chart' => 'bar']],
                ],
            ],
            'tasks' => [
                'kpis' => [
                    ['label' => 'Tasks created', 'entity' => 'tasks', 'metric' => 'count'],
                    ['label' => 'Completed', 'entity' => 'tasks', 'metric' => 'completed', 'date_field' => 'completed'],
                    ['label' => 'Completion rate', 'entity' => 'tasks', 'metric' => 'completion_rate'],
                    ['label' => 'Done on time', 'entity' => 'tasks', 'metric' => 'on_time_rate', 'date_field' => 'completed'],
                    ['label' => 'Overdue now', 'entity' => 'tasks', 'metric' => 'overdue', 'better' => 'down'],
                ],
                'widgets' => [
                    ['title' => 'Tasks by due date', 'subtitle' => 'Open, overdue and done', 'span' => 2, 'spec' => ['entity' => 'tasks', 'metric' => 'count', 'dimension' => 'due', 'split' => 'state', 'chart' => 'stacked']],
                    ['title' => 'Task state', 'spec' => ['entity' => 'tasks', 'metric' => 'count', 'dimension' => 'state', 'chart' => 'pie']],
                    ['title' => 'Workload by assignee', 'spec' => ['entity' => 'tasks', 'metric' => 'count', 'dimension' => 'assignee', 'split' => 'state', 'chart' => 'stacked']],
                    ['title' => 'Completion rate by assignee', 'spec' => ['entity' => 'tasks', 'metric' => 'completion_rate', 'dimension' => 'assignee', 'chart' => 'bar']],
                    ['title' => 'Tasks by type', 'spec' => ['entity' => 'tasks', 'metric' => 'count', 'dimension' => 'type', 'chart' => 'bar']],
                ],
            ],
            'calls' => [
                'kpis' => [
                    ['label' => 'AI calls', 'entity' => 'calls', 'metric' => 'count'],
                    ['label' => 'Connect rate', 'entity' => 'calls', 'metric' => 'connect_rate'],
                    ['label' => 'Meetings booked', 'entity' => 'calls', 'metric' => 'meetings'],
                    ['label' => 'Positive outcome rate', 'entity' => 'calls', 'metric' => 'positive_rate'],
                    ['label' => 'Average talk time', 'entity' => 'calls', 'metric' => 'avg_duration'],
                ],
                'widgets' => [
                    ['title' => 'Call outcomes over time', 'span' => 2, 'spec' => ['entity' => 'calls', 'metric' => 'count', 'dimension' => 'date', 'split' => 'outcome', 'chart' => 'stacked']],
                    ['title' => 'Outcome mix', 'spec' => ['entity' => 'calls', 'metric' => 'count', 'dimension' => 'outcome', 'chart' => 'pie']],
                    ['title' => 'Meetings booked by agent', 'spec' => ['entity' => 'calls', 'metric' => 'meetings', 'dimension' => 'agent', 'chart' => 'bar']],
                    ['title' => 'Connect rate by agent', 'spec' => ['entity' => 'calls', 'metric' => 'connect_rate', 'dimension' => 'agent', 'chart' => 'bar']],
                    ['title' => 'Sentiment', 'spec' => ['entity' => 'calls', 'metric' => 'count', 'dimension' => 'sentiment', 'filters' => ['status' => ['completed']], 'chart' => 'pie']],
                    ['title' => 'Campaign runs', 'spec' => ['entity' => 'calls', 'metric' => 'count', 'dimension' => 'campaign', 'split' => 'outcome', 'chart' => 'table']],
                ],
            ],
            'messaging' => [
                'kpis' => [
                    ['label' => 'Emails', 'entity' => 'activities', 'metric' => 'count', 'filters' => ['type' => ['email']]],
                    ['label' => 'SMS', 'entity' => 'activities', 'metric' => 'count', 'filters' => ['type' => ['sms']]],
                    ['label' => 'WhatsApp', 'entity' => 'activities', 'metric' => 'count', 'filters' => ['type' => ['whatsapp']]],
                    ['label' => 'Replies received', 'entity' => 'activities', 'metric' => 'count', 'filters' => [...$messaging, 'direction' => ['inbound']]],
                ],
                'widgets' => [
                    ['title' => 'Messages by channel', 'span' => 2, 'spec' => ['entity' => 'activities', 'metric' => 'count', 'dimension' => 'date', 'split' => 'type', 'filters' => $messaging, 'chart' => 'stacked']],
                    ['title' => 'Channel mix', 'spec' => ['entity' => 'activities', 'metric' => 'count', 'dimension' => 'type', 'filters' => $messaging, 'chart' => 'pie']],
                    ['title' => 'Sent vs received', 'spec' => ['entity' => 'activities', 'metric' => 'count', 'dimension' => 'type', 'split' => 'direction', 'filters' => $messaging, 'chart' => 'stacked']],
                    ['title' => 'Messages by rep', 'spec' => ['entity' => 'activities', 'metric' => 'count', 'dimension' => 'user', 'split' => 'type', 'filters' => $messaging, 'chart' => 'stacked']],
                ],
            ],
            default => throw new InvalidArgumentException("Unknown report type [{$type}]."),
        };
    }
}
