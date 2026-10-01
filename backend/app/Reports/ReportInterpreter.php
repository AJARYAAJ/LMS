<?php

namespace App\Reports;

use Anthropic\Client;
use App\Ai\ReportQuestion;
use App\Integrations\IntegrationManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Turns a plain-English question ("won revenue by rep this quarter") into a report
 * spec. Uses Claude when the organization connected Anthropic, otherwise keyword
 * rules. Either way the spec is validated by the report engine before it runs.
 */
class ReportInterpreter
{
    public function __construct(private IntegrationManager $integrations, private ReportEngine $engine) {}

    /** @return array{spec: array, title: string, interpreter: string} */
    public function interpret(int $organizationId, string $question): array
    {
        $credentials = $this->integrations->anthropic($organizationId);
        if ($credentials) {
            try {
                $result = $this->withClaude($credentials, $question);
                if ($result) {
                    return $result;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->withRules($question);
    }

    private function withClaude(array $credentials, string $question): ?array
    {
        $client = new Client(apiKey: $credentials['key'], requestOptions: ['timeout' => 30, 'maxRetries' => 1]);
        $catalog = json_encode(Entities::catalog(), JSON_UNESCAPED_SLASHES);

        $message = $client->messages->create(
            model: $credentials['model'],
            maxTokens: 1500,
            system: 'You translate questions about a CRM into a report spec. Use only keys from the catalog. Prefer a date grouping for questions about trends or "over time"; use date_field "closed" for won revenue and win rate.',
            messages: [['role' => 'user', 'content' => "Catalog:\n{$catalog}\n\nQuestion: {$question}"]],
            outputConfig: ['format' => ReportQuestion::class, 'effort' => 'low'],
        );

        /** @var ReportQuestion|null $q */
        $q = $message->stopReason === 'refusal' ? null : $message->parsedOutput();
        if (! $q instanceof ReportQuestion) {
            return null;
        }

        $filters = [];
        foreach (array_filter(array_map('trim', explode(';', $q->filters))) as $part) {
            [$key, $values] = array_pad(explode('=', $part, 2), 2, '');
            if (trim($key) !== '' && $values !== '') {
                $filters[trim($key)] = array_map('trim', explode('|', $values));
            }
        }

        try {
            $spec = $this->engine->normalize([
                'entity' => $q->entity, 'metric' => $q->metric, 'dimension' => $q->dimension ?: null, 'split' => $q->split ?: null,
                'date_field' => $q->date_field ?: null, 'filters' => $filters, 'range' => $q->range, 'chart' => $q->chart,
            ]);
        } catch (ValidationException) {
            return null; // fall back to rules rather than show an error
        }

        return ['spec' => $spec, 'title' => Str::limit($q->title ?: Str::ucfirst($question), 60, ''), 'interpreter' => 'claude'];
    }

    /** Keyword rules: good enough for "X by Y over Z" questions. */
    public function withRules(string $question): array
    {
        $q = ' '.Str::lower($question).' ';
        $has = fn (string ...$words) => Str::contains($q, $words);

        $entity = match (true) {
            $has('ai call', 'connect rate', 'agent', 'voice') => 'calls',
            $has('revenue', 'deal', 'pipeline', 'win rate', 'won', 'lost', 'forecast') => 'deals',
            $has('task', 'overdue', 'to-do', 'todo') => 'tasks',
            $has('activit', 'email', 'meeting', 'sms', 'whatsapp', 'message', 'calls made', 'call outcome', 'calls logged') => 'activities',
            default => 'leads',
        };
        $def = Entities::get($entity);

        $metric = match ($entity) {
            'deals' => match (true) {
                $has('win rate') => 'win_rate',
                $has('average deal', 'avg deal', 'deal size') => 'avg_amount',
                $has('weighted') => 'weighted',
                $has('revenue', 'won') => 'won_amount',
                $has('lost') => 'lost_count',
                $has('value', 'amount', 'pipeline') => 'amount',
                default => 'count',
            },
            'leads' => match (true) {
                $has('conversion rate', 'convert rate') => 'conversion_rate',
                $has('converted', 'conversions') => 'converted',
                $has('qualified') => 'qualified',
                $has('score') => 'avg_score',
                $has('value') => 'expected_value',
                default => 'count',
            },
            'tasks' => match (true) {
                $has('overdue') => 'overdue',
                $has('on time') => 'on_time_rate',
                $has('completion rate') => 'completion_rate',
                $has('completed', 'done') => 'completed',
                default => 'count',
            },
            'calls' => match (true) {
                $has('connect rate') => 'connect_rate',
                $has('meeting') => 'meetings',
                $has('talk time', 'duration') => 'avg_duration',
                $has('positive') => 'positive_rate',
                default => 'count',
            },
            default => $has('time spent', 'time logged', 'minutes') ? 'minutes' : 'count',
        };

        // "… by X" / "per X" picks the grouping.
        $synonyms = [
            'owner' => ['rep', 'reps', 'owner', 'person', 'people', 'salesperson', 'user', 'team member', 'assignee', 'agent'],
            'source' => ['source', 'channel'], 'status' => ['status'], 'stage' => ['stage'], 'industry' => ['industry', 'industries'],
            'country' => ['country', 'countries'], 'campaign' => ['campaign'], 'priority' => ['priority'], 'rating' => ['rating', 'temperature'],
            'type' => ['type', 'channel'], 'outcome' => ['outcome', 'result'], 'lost_reason' => ['reason', 'why'], 'sentiment' => ['sentiment', 'mood'],
            'state' => ['state'], 'team' => ['team'],
            'date' => ['day', 'week', 'month', 'time', 'trend', 'over time', 'daily', 'weekly', 'monthly'],
        ];
        $dimension = null;
        if (preg_match('/\b(?:by|per|for each|split by|across)\s+([a-z ]+?)(?:\s+(?:this|last|in|over|during|for|since)\b|[?.!,]|$)/', trim($q), $m)) {
            $phrase = trim($m[1]);
            foreach ($synonyms as $key => $words) {
                if (Str::contains($phrase, $words)) {
                    $dimension = $this->resolveDimension($entity, $key, $def['dimensions']);
                    break;
                }
            }
        }
        if (! $dimension && $has('over time', 'trend', 'per month', 'per week', 'monthly', 'weekly', 'daily')) {
            $dimension = $this->resolveDimension($entity, 'date', $def['dimensions']);
        }
        // No "by …": look for a grouping word anywhere ("outcome mix", "status breakdown").
        if (! $dimension && $has('mix', 'breakdown', 'split', 'share', 'distribution', 'outcomes', 'sources', 'statuses', 'stages', 'industries')) {
            foreach (['outcome', 'source', 'status', 'stage', 'industry', 'sentiment', 'type', 'priority', 'rating'] as $key) {
                if (Str::contains($q, $synonyms[$key]) && ($found = $this->resolveDimension($entity, $key, $def['dimensions']))) {
                    $dimension = $found;
                    break;
                }
            }
        }

        $range = match (true) {
            $has('this quarter', 'quarter') => 'this_quarter',
            $has('last month') => 'last_month',
            $has('this month') => 'this_month',
            $has('this year', 'ytd', 'year to date') => 'this_year',
            $has('12 months', 'last year', 'past year') => 'last_12_months',
            $has('7 days', 'this week', 'last week') => 'last_7',
            $has('90 days', '3 months') => 'last_90',
            default => 'last_30',
        };

        $filters = [];
        if ($entity === 'deals' && $has('why', 'reason') && $has('lost')) {
            [$metric, $dimension, $filters] = ['count', 'lost_reason', ['status' => ['lost']]];
        } elseif ($entity === 'deals' && $has(' lost ') && $metric === 'count') {
            $filters['status'] = ['lost'];
        }
        $dateField = $entity === 'deals' && (in_array($metric, ['won_amount', 'win_rate', 'lost_count'], true) || isset($filters['status'])) ? 'closed' : null;
        if ($dateField && $dimension && ($def['dimensions'][$dimension]['type'] ?? null) === 'date') {
            $dimension = 'closed';
            $dateField = null;
        }

        $isDate = $dimension && $def['dimensions'][$dimension]['type'] === 'date';
        $chart = match (true) {
            ! $dimension => 'number',
            $has('pie', 'donut', 'share', 'mix', 'breakdown') && ! $isDate => 'pie',
            $has('table') => 'table',
            $isDate => $has('bar') ? 'bar' : 'line',
            default => 'bar',
        };

        $spec = $this->engine->normalize(['entity' => $entity, 'metric' => $metric, 'dimension' => $dimension, 'date_field' => $dateField, 'filters' => $filters, 'range' => $range, 'chart' => $chart]);

        return ['spec' => $spec, 'title' => Str::limit(Str::ucfirst(trim($question, ' ?')), 60, ''), 'interpreter' => 'rules'];
    }

    private function resolveDimension(string $entity, string $key, array $dims): ?string
    {
        $candidates = match ($key) {
            'owner' => ['owner', 'user', 'assignee', 'started_by'],
            'date' => ['created', 'date', 'due'],
            'type' => ['type', 'source'],
            default => [$key],
        };
        foreach ($candidates as $c) {
            if (isset($dims[$c])) {
                return $c;
            }
        }

        return null;
    }
}
