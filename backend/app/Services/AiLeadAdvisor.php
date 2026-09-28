<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Ai\LeadBrief;
use App\Models\Lead;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Optional AI layer: asks Claude for a lead brief (summary, next best action,
 * talking points, risk). Enabled when ANTHROPIC_API_KEY is configured; the
 * rule-based LeadInsights remain the fallback everywhere.
 */
class AiLeadAdvisor
{
    public function __construct(private LeadInsights $insights) {}

    public function enabled(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * @return array{summary: string, next_action_title: string, next_action_reason: string, talking_points: list<string>, risk: string, model: string, generated_at: string}
     */
    public function brief(Lead $lead, bool $refresh = false): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('AI is not configured. Set ANTHROPIC_API_KEY to enable AI briefs.');
        }

        // Cache per lead version so repeated views don't re-bill; any change to the lead or its timeline invalidates it.
        $version = implode(':', [$lead->id, $lead->updated_at?->timestamp, $lead->activities()->max('id'), $lead->notes()->max('id')]);
        $key = "ai-brief:{$version}";

        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHours(12), fn () => $this->generate($lead));
    }

    private function generate(Lead $lead): array
    {
        $client = new Client(
            apiKey: config('services.anthropic.key'),
            requestOptions: ['timeout' => 45, 'maxRetries' => 1],
        );
        $model = config('services.anthropic.model');

        try {
            $message = $client->messages->create(
                model: $model,
                maxTokens: 4000,
                system: 'You are a sharp B2B sales coach inside a CRM. Base every statement only on the lead record provided; never invent facts, names or numbers. Be concise and practical.',
                messages: [['role' => 'user', 'content' => $this->context($lead)]],
                outputConfig: ['format' => LeadBrief::class, 'effort' => 'low'],
            );
        } catch (APIStatusException $e) {
            report($e);
            $type = $e->type?->value;
            throw new RuntimeException(match ($type) {
                'authentication_error', 'permission_error' => 'The configured ANTHROPIC_API_KEY was rejected. Check the key in the server environment.',
                'rate_limit_error', 'overloaded_error' => 'The AI service is busy right now. Please try again in a minute.',
                default => 'The AI service returned an error ('.($type ?? 'unknown').'). Please try again shortly.',
            });
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException('Could not reach the AI service. Please try again shortly.');
        }

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The AI declined to summarise this lead. The rule-based insights are still available.');
        }

        /** @var LeadBrief|null $brief */
        $brief = $message->parsedOutput();
        if (! $brief instanceof LeadBrief) {
            throw new RuntimeException('The AI returned an unexpected response. Please try again.');
        }

        return [
            'summary' => $brief->summary,
            'next_action_title' => $brief->next_action_title,
            'next_action_reason' => $brief->next_action_reason,
            'talking_points' => array_values(array_filter(array_map('trim', preg_split('/\R/', $brief->talking_points)))),
            'risk' => $brief->risk,
            'model' => $model,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function context(Lead $lead): string
    {
        $lead->loadMissing(['status', 'source', 'owner', 'campaign', 'tags', 'organization']);
        $criteria = collect($lead->organization?->qualificationCriteria() ?? [])
            ->map(fn ($c) => '- '.$c['label'].': '.(! empty($lead->qualification[$c['key']]) ? 'confirmed' : 'not yet'))->implode("\n");
        $activities = $lead->activities()->latest('occurred_at')->limit(15)->get()
            ->map(fn ($a) => "- {$a->occurred_at->toDateString()} [{$a->type}] {$a->title}".($a->outcome ? " (outcome: {$a->outcome})" : '').($a->description ? ' — '.str($a->description)->limit(200) : ''))->implode("\n");
        $notes = $lead->notes()->latest()->limit(5)->pluck('body')->map(fn ($b) => '- '.str($b)->limit(300))->implode("\n");
        $rules = $this->insights->for($lead);

        return <<<TXT
        Write a brief for this lead.

        <lead>
        Name: {$lead->full_name}
        Title: {$lead->job_title}
        Company: {$lead->company} ({$lead->industry}, size {$lead->company_size})
        Location: {$lead->city}, {$lead->country}
        Status: {$lead->status?->name}
        Source: {$lead->source?->name}; Campaign: {$lead->campaign?->name}
        Owner: {$lead->owner?->name}
        Score: {$lead->score}/100 ({$lead->rating}); Priority: {$lead->priority}
        Budget: {$lead->budget}; Expected value: {$lead->expected_value}; Timeline: {$lead->timeline}
        Tags: {$lead->tags->pluck('name')->implode(', ')}
        Created: {$lead->created_at?->toDateString()}; Last contacted: {$lead->last_contacted_at?->toDateString()}; Next follow-up: {$lead->next_follow_up_at?->toDateString()}
        Requirements: {$lead->requirements}
        Lost reason: {$lead->lost_reason}
        Today: {$this->today()}
        </lead>

        <qualification>
        {$criteria}
        </qualification>

        <recent_activity>
        {$activities}
        </recent_activity>

        <notes>
        {$notes}
        </notes>

        <rule_based_signals>
        {$this->signals($rules)}
        </rule_based_signals>
        TXT;
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    private function signals(array $rules): string
    {
        return collect($rules['signals'])->map(fn ($s) => "- {$s['text']}")->implode("\n") ?: '- none';
    }
}
