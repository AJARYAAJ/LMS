<?php

namespace App\Reports;

use App\Models\Activity;
use App\Models\AiAgent;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * What each record type can be grouped by (dimensions) and measured with (metrics).
 *
 * Only these whitelisted expressions ever reach SQL. Every metric is built from
 * additive aggregates (sums and counts), so buckets can be merged (days into weeks,
 * long tails into "Other") and rates stay correct.
 */
class Entities
{
    public const KEYS = ['leads', 'deals', 'activities', 'tasks', 'calls'];

    public static function get(string $entity): array
    {
        return match ($entity) {
            'leads' => self::leads(),
            'deals' => self::deals(),
            'activities' => self::activities(),
            'tasks' => self::tasks(),
            'calls' => self::calls(),
            default => throw new InvalidArgumentException("Unknown report entity [{$entity}]."),
        };
    }

    /** Public description for the report studio (no SQL). */
    public static function catalog(): array
    {
        return collect(self::KEYS)->map(function (string $key) {
            $def = self::get($key);

            return [
                'key' => $key,
                'label' => $def['label'],
                'dimensions' => collect($def['dimensions'])->map(fn ($d, $k) => [
                    'key' => $k,
                    'label' => $d['label'],
                    'type' => $d['type'],
                    'filterable' => $d['type'] === 'enum' && ($d['filterable'] ?? true),
                    'values' => $d['type'] === 'enum' ? array_map(fn ($v) => ['value' => $v, 'label' => self::valueLabel($d, $v)], $d['values']) : null,
                ])->values(),
                'metrics' => collect($def['metrics'])->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label'], 'format' => $m['format']])->values(),
            ];
        })->all();
    }

    /** Display label for a fixed-choice value ("meeting_booked" → "Meeting booked"). */
    public static function valueLabel(array $dimension, string $value): string
    {
        return $dimension['labels'][$value] ?? Str::ucfirst(str_replace('_', ' ', $value));
    }

    private static function rate(string $part, string $whole): \Closure
    {
        return fn (array $r) => ($r[$whole] ?? 0) > 0 ? round($r[$part] / $r[$whole] * 100, 1) : 0.0;
    }

    private static function ratio(string $sum, string $count, int $precision = 1): \Closure
    {
        return fn (array $r) => ($r[$count] ?? 0) > 0 ? round($r[$sum] / $r[$count], $precision) : 0.0;
    }

    private static function plain(string $alias = 'v'): \Closure
    {
        return fn (array $r) => (float) ($r[$alias] ?? 0);
    }

    private static function owner(string $column, string $label = 'Owner'): array
    {
        return ['label' => $label, 'type' => 'model', 'column' => $column, 'model' => User::class, 'color' => 'avatar_color', 'empty' => 'Unassigned'];
    }

    private static function leads(): array
    {
        return [
            'label' => 'Leads',
            'date' => 'leads.created_at',
            'query' => fn (User $user): Builder => Lead::visibleTo($user),
            'dimensions' => [
                'created' => ['label' => 'Created date', 'type' => 'date', 'column' => 'leads.created_at'],
                'converted' => ['label' => 'Converted date', 'type' => 'date', 'column' => 'leads.converted_at'],
                'status' => ['label' => 'Status', 'type' => 'model', 'column' => 'lead_status_id', 'model' => LeadStatus::class, 'color' => 'color'],
                'source' => ['label' => 'Source', 'type' => 'model', 'column' => 'lead_source_id', 'model' => LeadSource::class, 'color' => 'color', 'empty' => 'No source'],
                'owner' => self::owner('owner_id'),
                'team' => ['label' => 'Team', 'type' => 'model', 'column' => 'team_id', 'model' => Team::class, 'color' => 'color', 'empty' => 'No team'],
                'campaign' => ['label' => 'Campaign', 'type' => 'model', 'column' => 'campaign_id', 'model' => Campaign::class, 'empty' => 'No campaign'],
                'priority' => ['label' => 'Priority', 'type' => 'enum', 'column' => 'priority', 'values' => Lead::PRIORITIES],
                'rating' => ['label' => 'Rating', 'type' => 'enum', 'column' => 'rating', 'values' => Lead::RATINGS],
                'industry' => ['label' => 'Industry', 'type' => 'text', 'column' => 'industry', 'empty' => 'Unknown'],
                'country' => ['label' => 'Country', 'type' => 'text', 'column' => 'country', 'empty' => 'Unknown'],
                'company_size' => ['label' => 'Company size', 'type' => 'text', 'column' => 'company_size', 'empty' => 'Unknown'],
            ],
            'metrics' => [
                'count' => ['label' => 'Leads', 'format' => 'number', 'select' => ['v' => 'count(*)'], 'value' => self::plain()],
                'converted' => ['label' => 'Converted leads', 'format' => 'number', 'select' => ['v' => 'sum(case when converted_at is not null then 1 else 0 end)'], 'value' => self::plain()],
                'qualified' => ['label' => 'Qualified leads', 'format' => 'number', 'select' => ['v' => 'sum(case when qualified_at is not null or converted_at is not null then 1 else 0 end)'], 'value' => self::plain()],
                'conversion_rate' => ['label' => 'Conversion rate', 'format' => 'percent', 'select' => ['p' => 'sum(case when converted_at is not null then 1 else 0 end)', 'n' => 'count(*)'], 'value' => self::rate('p', 'n')],
                'avg_score' => ['label' => 'Average score', 'format' => 'decimal', 'select' => ['s' => 'coalesce(sum(score), 0)', 'n' => 'count(*)'], 'value' => self::ratio('s', 'n')],
                'expected_value' => ['requires' => 'expected_value', 'label' => 'Expected value', 'format' => 'money', 'select' => ['v' => 'coalesce(sum(expected_value), 0)'], 'value' => self::plain()],
                'budget' => ['requires' => 'budget', 'label' => 'Stated budget', 'format' => 'money', 'select' => ['v' => 'coalesce(sum(budget), 0)'], 'value' => self::plain()],
                'response_hours' => ['label' => 'Average first response (hours)', 'format' => 'hours', 'select' => ['s' => 'coalesce(sum('.Sql::hoursBetween('leads.created_at', 'leads.first_responded_at').'), 0)', 'n' => 'count(first_responded_at)'], 'value' => self::ratio('s', 'n')],
                'responded_rate' => ['label' => 'Leads contacted', 'format' => 'percent', 'select' => ['p' => 'count(first_responded_at)', 'n' => 'count(*)'], 'value' => self::rate('p', 'n')],
                'within_sla_rate' => ['label' => 'Contacted within target', 'format' => 'percent', 'select' => ['p' => 'sum(case when first_responded_at is not null and '.Sql::hoursBetween('leads.created_at', 'leads.first_responded_at').' <= __SLA__ then 1 else 0 end)', 'n' => 'count(*)'], 'value' => self::rate('p', 'n')],
                'likelihood' => ['label' => 'Average predicted conversion', 'format' => 'percent', 'select' => ['s' => 'coalesce(sum(conversion_likelihood), 0)', 'n' => 'count(conversion_likelihood)'], 'value' => self::ratio('s', 'n')],
                'days_to_convert' => ['label' => 'Average days to convert', 'format' => 'decimal', 'select' => ['s' => 'coalesce(sum('.Sql::hoursBetween('leads.created_at', 'leads.converted_at').' / 24.0), 0)', 'n' => 'count(converted_at)'], 'value' => self::ratio('s', 'n')],
            ],
        ];
    }

    private static function deals(): array
    {
        return [
            'label' => 'Deals',
            'date' => 'deals.created_at',
            'query' => fn (User $user): Builder => Deal::query()->when($user->role === User::SALES_REP, fn ($q) => $q->where('owner_id', $user->id)),
            'dimensions' => [
                'created' => ['label' => 'Created date', 'type' => 'date', 'column' => 'deals.created_at'],
                'closed' => ['label' => 'Closed date', 'type' => 'date', 'column' => 'deals.closed_at'],
                'expected_close' => ['label' => 'Expected close', 'type' => 'date', 'column' => 'deals.expected_close_date', 'date_only' => true],
                'stage' => ['label' => 'Stage', 'type' => 'model', 'column' => 'pipeline_stage_id', 'model' => PipelineStage::class, 'color' => 'color'],
                'status' => ['label' => 'Status', 'type' => 'enum', 'column' => 'status', 'values' => ['open', 'won', 'lost']],
                'pipeline' => ['label' => 'Pipeline', 'type' => 'model', 'column' => 'pipeline_id', 'model' => Pipeline::class],
                'forecast' => ['label' => 'Forecast category', 'type' => 'enum', 'column' => 'forecast_category', 'values' => Deal::FORECAST, 'labels' => ['best_case' => 'Best case']],
                'owner' => self::owner('owner_id'),
                'lost_reason' => ['label' => 'Lost reason', 'type' => 'text', 'column' => 'lost_reason', 'empty' => 'Not given'],
            ],
            'metrics' => [
                'count' => ['label' => 'Deals', 'format' => 'number', 'select' => ['v' => 'count(*)'], 'value' => self::plain()],
                'amount' => ['requires' => 'amount', 'label' => 'Deal value', 'format' => 'money', 'select' => ['v' => 'coalesce(sum(amount), 0)'], 'value' => self::plain()],
                'weighted' => ['requires' => 'amount', 'label' => 'Weighted value', 'format' => 'money', 'select' => ['v' => 'coalesce(sum(amount * probability / 100.0), 0)'], 'value' => self::plain()],
                'won_amount' => ['requires' => 'amount', 'label' => 'Won revenue', 'format' => 'money', 'select' => ['v' => "coalesce(sum(case when status = 'won' then amount else 0 end), 0)"], 'value' => self::plain()],
                'won_count' => ['label' => 'Deals won', 'format' => 'number', 'select' => ['v' => "sum(case when status = 'won' then 1 else 0 end)"], 'value' => self::plain()],
                'lost_count' => ['label' => 'Deals lost', 'format' => 'number', 'select' => ['v' => "sum(case when status = 'lost' then 1 else 0 end)"], 'value' => self::plain()],
                'win_rate' => ['label' => 'Win rate', 'format' => 'percent', 'select' => ['w' => "sum(case when status = 'won' then 1 else 0 end)", 'c' => "sum(case when status in ('won', 'lost') then 1 else 0 end)"], 'value' => self::rate('w', 'c')],
                'avg_amount' => ['requires' => 'amount', 'label' => 'Average deal size', 'format' => 'money', 'select' => ['s' => 'coalesce(sum(amount), 0)', 'n' => 'count(*)'], 'value' => self::ratio('s', 'n', 0)],
            ],
        ];
    }

    private static function activities(): array
    {
        return [
            'label' => 'Activities',
            'date' => 'activities.occurred_at',
            'query' => fn (User $user): Builder => Activity::query()->where('type', '!=', 'system')
                ->when($user->role === User::SALES_REP, fn ($q) => $q->where('user_id', $user->id)),
            'dimensions' => [
                'date' => ['label' => 'Date', 'type' => 'date', 'column' => 'activities.occurred_at'],
                'type' => ['label' => 'Type', 'type' => 'enum', 'column' => 'type', 'values' => ['call', 'email', 'meeting', 'sms', 'whatsapp', 'note', 'task'], 'labels' => ['sms' => 'SMS', 'whatsapp' => 'WhatsApp']],
                'user' => self::owner('user_id', 'Rep'),
                'direction' => ['label' => 'Direction', 'type' => 'enum', 'column' => 'direction', 'values' => ['outbound', 'inbound'], 'empty' => 'Not set'],
                'outcome' => ['label' => 'Outcome', 'type' => 'text', 'column' => 'outcome', 'empty' => 'No outcome'],
                'record' => ['label' => 'Record type', 'type' => 'enum', 'column' => 'subject_type', 'values' => ['lead', 'deal', 'contact', 'account']],
            ],
            'metrics' => [
                'count' => ['label' => 'Activities', 'format' => 'number', 'select' => ['v' => 'count(*)'], 'value' => self::plain()],
                'minutes' => ['label' => 'Time spent (min)', 'format' => 'number', 'select' => ['v' => 'coalesce(sum(duration_minutes), 0)'], 'value' => self::plain()],
                'avg_minutes' => ['label' => 'Average length (min)', 'format' => 'decimal', 'select' => ['s' => 'coalesce(sum(duration_minutes), 0)', 'n' => 'count(duration_minutes)'], 'value' => self::ratio('s', 'n')],
            ],
        ];
    }

    private static function tasks(): array
    {
        $state = "case when completed_at is not null then 'done' when due_at is not null and due_at < CURRENT_TIMESTAMP then 'overdue' else 'open' end";

        return [
            'label' => 'Tasks',
            'date' => 'tasks.created_at',
            'query' => fn (User $user): Builder => Task::query()->when($user->role === User::SALES_REP, fn ($q) => $q->where('assigned_to', $user->id)),
            'dimensions' => [
                'created' => ['label' => 'Created date', 'type' => 'date', 'column' => 'tasks.created_at'],
                'due' => ['label' => 'Due date', 'type' => 'date', 'column' => 'tasks.due_at'],
                'completed' => ['label' => 'Completed date', 'type' => 'date', 'column' => 'tasks.completed_at'],
                'state' => ['label' => 'State', 'type' => 'enum', 'column' => $state, 'values' => ['open', 'overdue', 'done'], 'filterable' => false],
                'type' => ['label' => 'Type', 'type' => 'enum', 'column' => 'type', 'values' => Task::TYPES],
                'priority' => ['label' => 'Priority', 'type' => 'enum', 'column' => 'priority', 'values' => Lead::PRIORITIES],
                'assignee' => self::owner('assigned_to', 'Assignee'),
            ],
            'metrics' => [
                'count' => ['label' => 'Tasks', 'format' => 'number', 'select' => ['v' => 'count(*)'], 'value' => self::plain()],
                'completed' => ['label' => 'Completed', 'format' => 'number', 'select' => ['v' => 'sum(case when completed_at is not null then 1 else 0 end)'], 'value' => self::plain()],
                'overdue' => ['label' => 'Overdue', 'format' => 'number', 'select' => ['v' => 'sum(case when completed_at is null and due_at is not null and due_at < CURRENT_TIMESTAMP then 1 else 0 end)'], 'value' => self::plain()],
                'completion_rate' => ['label' => 'Completion rate', 'format' => 'percent', 'select' => ['p' => 'sum(case when completed_at is not null then 1 else 0 end)', 'n' => 'count(*)'], 'value' => self::rate('p', 'n')],
                'on_time_rate' => ['label' => 'Done on time', 'format' => 'percent', 'select' => ['p' => 'sum(case when completed_at is not null and (due_at is null or completed_at <= due_at) then 1 else 0 end)', 'n' => 'sum(case when completed_at is not null then 1 else 0 end)'], 'value' => self::rate('p', 'n')],
            ],
        ];
    }

    private static function calls(): array
    {
        return [
            'label' => 'AI calls',
            'date' => 'calls.created_at',
            'query' => fn (User $user): Builder => Call::query()
                ->when($user->role === User::SALES_REP, fn ($q) => $q->whereIn('lead_id', Lead::visibleTo($user)->select('id'))),
            'dimensions' => [
                'date' => ['label' => 'Date', 'type' => 'date', 'column' => 'calls.created_at'],
                'outcome' => ['label' => 'Outcome', 'type' => 'enum', 'column' => 'outcome', 'values' => Call::OUTCOMES, 'empty' => 'Pending'],
                'status' => ['label' => 'Status', 'type' => 'enum', 'column' => 'status', 'values' => ['queued', 'ringing', 'in_progress', 'completed', 'no_answer', 'voicemail', 'failed', 'canceled']],
                'agent' => ['label' => 'Agent', 'type' => 'model', 'column' => 'ai_agent_id', 'model' => AiAgent::class, 'empty' => 'Deleted agent'],
                'started_by' => [...self::owner('user_id', 'Started by'), 'empty' => 'Automation or campaign'],
                'sentiment' => ['label' => 'Sentiment', 'type' => 'enum', 'column' => 'sentiment', 'values' => ['positive', 'neutral', 'negative'], 'empty' => 'Unknown'],
                'provider' => ['label' => 'Provider', 'type' => 'text', 'column' => 'provider'],
                'campaign' => ['label' => 'Campaign run', 'type' => 'text', 'column' => 'campaign_key', 'empty' => 'Single calls'],
            ],
            'metrics' => [
                'count' => ['label' => 'Calls', 'format' => 'number', 'select' => ['v' => 'count(*)'], 'value' => self::plain()],
                'connected' => ['label' => 'Conversations', 'format' => 'number', 'select' => ['v' => "sum(case when status = 'completed' then 1 else 0 end)"], 'value' => self::plain()],
                'connect_rate' => ['label' => 'Connect rate', 'format' => 'percent', 'select' => ['p' => "sum(case when status = 'completed' then 1 else 0 end)", 'n' => 'count(*)'], 'value' => self::rate('p', 'n')],
                'meetings' => ['label' => 'Meetings booked', 'format' => 'number', 'select' => ['v' => "sum(case when outcome = 'meeting_booked' then 1 else 0 end)"], 'value' => self::plain()],
                'positive_rate' => ['label' => 'Positive outcome rate', 'format' => 'percent', 'select' => ['p' => "sum(case when outcome in ('meeting_booked', 'interested', 'callback') then 1 else 0 end)", 'n' => "sum(case when status = 'completed' then 1 else 0 end)"], 'value' => self::rate('p', 'n')],
                'avg_duration' => ['label' => 'Average talk time', 'format' => 'duration', 'select' => ['s' => "coalesce(sum(case when status = 'completed' then duration_seconds else 0 end), 0)", 'n' => "sum(case when status = 'completed' then 1 else 0 end)"], 'value' => self::ratio('s', 'n', 0)],
                'talk_minutes' => ['label' => 'Talk time (min)', 'format' => 'number', 'select' => ['v' => 'coalesce(sum(duration_seconds), 0) / 60.0'], 'value' => fn (array $r) => round($r['v'] ?? 0, 1)],
            ],
        ];
    }
}
