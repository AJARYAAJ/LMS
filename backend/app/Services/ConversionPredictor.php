<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadSource;
use App\Support\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Predicts how likely an open lead is to convert, learned from this organization's
 * own history (converted vs lost leads) with a smoothed naive Bayes model. Every
 * prediction comes with the factors that moved it, so reps can see why.
 */
class ConversionPredictor
{
    /** A model needs at least this many known outcomes of each kind. */
    public const MIN_OUTCOMES = 8;

    private const FEATURES = [
        'source' => 'Source', 'industry' => 'Industry', 'rating' => 'Rating', 'priority' => 'Priority',
        'company_size' => 'Company size', 'score_band' => 'Score', 'contacted_fast' => 'First response', 'has_phone' => 'Phone number',
    ];

    /** @return array{won: int, lost: int, counts: array, base: float}|null */
    public function model(): ?array
    {
        return Cache::remember('conversion-model:'.Tenant::id(), now()->addHour(), function () {
            $won = ['n' => 0, 'f' => []];
            $lost = ['n' => 0, 'f' => []];
            Lead::query()->with('status:id,category')
                ->where(fn ($q) => $q->whereNotNull('converted_at')->orWhereHas('status', fn ($s) => $s->where('category', 'lost')))
                ->select(['id', 'lead_status_id', 'lead_source_id', 'industry', 'rating', 'priority', 'company_size', 'score', 'phone', 'created_at', 'first_responded_at', 'converted_at'])
                ->chunk(500, function ($leads) use (&$won, &$lost) {
                    foreach ($leads as $lead) {
                        $bucket = $lead->converted_at ? 'won' : 'lost';
                        ${$bucket}['n']++;
                        foreach ($this->features($lead) as $f => $v) {
                            ${$bucket}['f'][$f][$v] = (${$bucket}['f'][$f][$v] ?? 0) + 1;
                        }
                    }
                });

            if ($won['n'] < self::MIN_OUTCOMES || $lost['n'] < self::MIN_OUTCOMES) {
                return null;
            }

            return ['won' => $won, 'lost' => $lost, 'base' => $won['n'] / ($won['n'] + $lost['n'])];
        });
    }

    public function forget(): void
    {
        Cache::forget('conversion-model:'.Tenant::id());
    }

    /** @return array{likelihood: int, base: int, factors: list<array{label: string, effect: int}>, trained_on: int}|null */
    public function predict(Lead $lead): ?array
    {
        $model = $this->model();
        if (! $model || $lead->converted_at) {
            return null;
        }

        $logOdds = log($model['base'] / (1 - $model['base']));
        $factors = [];
        foreach ($this->features($lead) as $f => $v) {
            $values = count(array_unique([...array_keys($model['won']['f'][$f] ?? []), ...array_keys($model['lost']['f'][$f] ?? [])])) + 1;
            // Laplace-smoothed likelihood ratio for this value.
            $pWon = (($model['won']['f'][$f][$v] ?? 0) + 1) / ($model['won']['n'] + $values);
            $pLost = (($model['lost']['f'][$f][$v] ?? 0) + 1) / ($model['lost']['n'] + $values);
            $llr = log($pWon / $pLost);
            $logOdds += $llr;
            $factors[] = ['feature' => $f, 'value' => $v, 'llr' => $llr];
        }

        $p = 1 / (1 + exp(-$logOdds));
        $likelihood = (int) round(max(0.01, min(0.99, $p)) * 100);

        // Effect of each factor in percentage points, holding the others fixed.
        $named = collect($factors)->map(function ($fct) use ($logOdds) {
            $without = 1 / (1 + exp(-($logOdds - $fct['llr'])));
            $with = 1 / (1 + exp(-$logOdds));

            return ['label' => $this->describe($fct['feature'], $fct['value']), 'effect' => (int) round(($with - $without) * 100)];
        })->filter(fn ($f) => abs($f['effect']) >= 2)->sortByDesc(fn ($f) => abs($f['effect']))->take(4)->values()->all();

        return [
            'likelihood' => $likelihood,
            'base' => (int) round($model['base'] * 100),
            'factors' => $named,
            'trained_on' => $model['won']['n'] + $model['lost']['n'],
        ];
    }

    /** Store the prediction on every open lead (for lists, sorting and reports). */
    public function refresh(): int
    {
        $this->forget();
        if (! $this->model()) {
            Lead::whereNotNull('conversion_likelihood')->update(['conversion_likelihood' => null]);

            return 0;
        }
        $n = 0;
        Lead::whereHas('status', fn ($q) => $q->where('category', 'lost'))->whereNotNull('conversion_likelihood')->update(['conversion_likelihood' => null]);
        Lead::whereNull('converted_at')->whereDoesntHave('status', fn ($q) => $q->where('category', 'lost'))->chunkById(500, function ($leads) use (&$n) {
            foreach ($leads as $lead) {
                $lead->forceFill(['conversion_likelihood' => $this->predict($lead)['likelihood'] ?? null])->saveQuietly();
                $n++;
            }
        });

        return $n;
    }

    private function features(Lead $lead): array
    {
        $score = (int) $lead->score;

        return [
            'source' => (string) ($lead->lead_source_id ?? 'none'),
            'industry' => Str::lower(trim((string) $lead->industry)) ?: 'unknown',
            'rating' => (string) ($lead->rating ?: 'unknown'),
            'priority' => (string) ($lead->priority ?: 'unknown'),
            'company_size' => (string) ($lead->company_size ?: 'unknown'),
            'score_band' => $score >= 75 ? '75+' : ($score >= 50 ? '50-74' : ($score >= 25 ? '25-49' : '0-24')),
            'contacted_fast' => $lead->first_responded_at && $lead->created_at && $lead->created_at->diffInHours($lead->first_responded_at) <= 24 ? 'yes' : 'no',
            'has_phone' => $lead->phone ? 'yes' : 'no',
        ];
    }

    private function describe(string $feature, string $value): string
    {
        return match ($feature) {
            'source' => 'Source: '.($value === 'none' ? 'none' : (LeadSource::find((int) $value)?->name ?? 'deleted')),
            'contacted_fast' => $value === 'yes' ? 'Contacted within a day' : 'Not contacted within a day',
            'has_phone' => $value === 'yes' ? 'Has a phone number' : 'No phone number',
            'score_band' => "Score {$value}",
            default => self::FEATURES[$feature].': '.Str::ucfirst(str_replace('_', ' ', $value)),
        };
    }
}
