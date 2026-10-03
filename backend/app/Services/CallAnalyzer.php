<?php

namespace App\Services;

use Anthropic\Client;
use App\Ai\CallAnalysis;
use App\Integrations\IntegrationManager;
use App\Models\Call;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a call transcript into an outcome, sentiment, summary, confirmed
 * qualification criteria and an agreed follow-up time. Uses Claude when the
 * organization connected Anthropic; otherwise transparent keyword rules.
 */
class CallAnalyzer
{
    public function __construct(private IntegrationManager $integrations) {}

    /**
     * @return array{outcome: string, sentiment: string, summary: ?string, confirmed: list<string>, follow_up_at: ?string, next_step: ?string, analyzer: string}
     */
    public function analyze(Call $call, string $status, array $transcript, ?string $vendorSummary): array
    {
        if ($status === 'no_answer' || $status === 'voicemail') {
            return ['outcome' => $status, 'sentiment' => 'neutral', 'summary' => $vendorSummary ?? ($status === 'voicemail' ? 'Reached voicemail.' : 'No answer.'), 'confirmed' => [], 'follow_up_at' => null, 'next_step' => 'Try again later', 'analyzer' => 'rules'];
        }

        $criteria = collect($call->lead?->organization?->qualificationCriteria() ?? [])->pluck('key')->all();
        $credentials = $this->integrations->anthropic($call->organization_id);

        if ($credentials && $transcript) {
            try {
                return $this->withClaude($credentials, $transcript, $vendorSummary, $criteria);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $this->withRules($transcript, $vendorSummary, $criteria);
    }

    private function withClaude(array $credentials, array $transcript, ?string $vendorSummary, array $criteria): array
    {
        $client = new Client(apiKey: $credentials['key'], requestOptions: ['timeout' => 45, 'maxRetries' => 1]);
        $lines = collect($transcript)->map(fn ($t) => strtoupper($t['role']).': '.$t['text'])->implode("\n");
        $allowed = implode(', ', $criteria) ?: 'none';

        $message = $client->messages->create(
            model: $credentials['model'],
            maxTokens: 2000,
            system: 'You analyse outbound sales calls for a CRM. Use only what is in the transcript.',
            messages: [['role' => 'user', 'content' => 'Current date-time: '.now()->toIso8601String()."\nAllowed qualification keys: {$allowed}\nVendor summary: ".($vendorSummary ?? 'none')."\n\n<transcript>\n{$lines}\n</transcript>"]],
            outputConfig: ['format' => CallAnalysis::class, 'effort' => 'low'],
        );

        /** @var CallAnalysis|null $a */
        $a = $message->stopReason === 'refusal' ? null : $message->parsedOutput();
        if (! $a instanceof CallAnalysis) {
            return $this->withRules($transcript, $vendorSummary, $criteria);
        }

        return [
            'outcome' => in_array($a->outcome, Call::OUTCOMES, true) ? $a->outcome : 'interested',
            'sentiment' => in_array($a->sentiment, ['positive', 'neutral', 'negative'], true) ? $a->sentiment : 'neutral',
            'summary' => $a->summary,
            'confirmed' => array_values(array_intersect($criteria, array_map('trim', explode(',', $a->confirmed_keys)))),
            'follow_up_at' => $this->parseDate($a->follow_up_at),
            'next_step' => $a->next_step ?: null,
            'analyzer' => 'claude',
        ];
    }

    private function withRules(array $transcript, ?string $vendorSummary, array $criteria): array
    {
        $leadText = Str::lower(collect($transcript)->where('role', 'lead')->pluck('text')->implode(' '));
        $all = Str::lower(collect($transcript)->pluck('text')->implode(' '));

        $outcome = match (true) {
            $leadText === '' => 'no_answer',
            Str::contains($leadText, ['wrong number', 'no one by that name']) => 'wrong_number',
            Str::contains($leadText, ['not interested', 'no thanks', "it's not something", 'not something we', 'remove me', 'do not call']) => 'not_interested',
            Str::contains($leadText, ['call me back', 'call back', 'another time', 'busy right now', 'in a meeting', 'jump into a meeting']) => 'callback',
            Str::contains($all, ['demo', 'meeting']) && Str::contains($leadText, ['works for me', 'sounds good', 'sure', 'yes']) => 'meeting_booked',
            default => 'interested',
        };

        $sentiment = match ($outcome) {
            'meeting_booked', 'interested' => 'positive',
            'not_interested', 'wrong_number' => 'negative',
            default => 'neutral',
        };

        $keywords = [
            'budget' => ['budget', 'set aside', 'spend'],
            'authority' => ['decision', 'sign off', 'signs off', 'i lead', 'approve'],
            'need' => ['we need', 'lose track', 'problem', 'struggle', 'visibility', 'looking for'],
            'timeline' => ['this quarter', 'this month', 'next quarter', 'by ', 'in place'],
        ];
        $confirmed = in_array($outcome, ['interested', 'meeting_booked', 'callback'], true)
            ? array_values(array_filter($criteria, fn ($k) => isset($keywords[$k]) && Str::contains($leadText, $keywords[$k])))
            : [];

        return [
            'outcome' => $outcome,
            'sentiment' => $sentiment,
            'summary' => $vendorSummary,
            'confirmed' => $confirmed,
            'follow_up_at' => $this->guessFollowUp($all, $outcome),
            'next_step' => match ($outcome) {
                'meeting_booked' => 'Demo booked', 'callback' => 'Call back as agreed', 'interested' => 'Send follow-up', default => null
            },
            'analyzer' => 'rules',
        ];
    }

    private function guessFollowUp(string $text, string $outcome): ?string
    {
        if (! in_array($outcome, ['meeting_booked', 'callback', 'interested'], true)) {
            return null;
        }
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $day) {
            if (str_contains($text, $day)) {
                $hour = preg_match('/(\d{1,2})\s?(am|pm)/', $text, $m) ? ((int) $m[1] % 12) + ($m[2] === 'pm' ? 12 : 0) : (str_contains($text, 'afternoon') ? 15 : 10);

                return Carbon::parse("next {$day}")->setTime($hour, 0)->toIso8601String();
            }
        }

        return now()->addDays(2)->setTime(10, 0)->toIso8601String();
    }

    private function parseDate(string $value): ?string
    {
        try {
            return $value ? Carbon::parse($value)->toIso8601String() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
