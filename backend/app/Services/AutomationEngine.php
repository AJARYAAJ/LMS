<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Generic WHEN (trigger) / IF (conditions) / THEN (actions) workflow engine.
 */
class AutomationEngine
{
    private const MAX_DEPTH = 3;

    private const UPDATABLE_FIELDS = ['priority', 'rating', 'timeline', 'industry', 'lost_reason'];

    private int $depth = 0;

    public function __construct(
        private ConditionEvaluator $evaluator,
        private ActivityRecorder $activities,
    ) {}

    public function fire(string $trigger, Lead $lead): void
    {
        // Actions such as change_status re-enter the engine; cap the depth so
        // two rules cannot ping-pong forever.
        if ($this->depth >= self::MAX_DEPTH) {
            return;
        }

        $rules = AutomationRule::where('organization_id', $lead->organization_id)
            ->where('trigger', $trigger)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $this->depth++;

        try {
            foreach ($rules as $rule) {
                $lead->refresh();

                if (! $this->evaluator->matchesAll($rule->conditions, $lead)) {
                    continue;
                }

                $this->execute($rule, $lead);
            }
        } finally {
            $this->depth--;
        }
    }

    private function execute(AutomationRule $rule, Lead $lead): void
    {
        $performed = [];

        try {
            foreach ($rule->actions as $action) {
                $performed[] = $this->runAction($action['type'] ?? '', $action['params'] ?? [], $lead, $rule);
            }

            $rule->executions()->create([
                'lead_id' => $lead->id,
                'status' => 'success',
                'message' => implode('; ', array_filter($performed)),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $rule->executions()->create([
                'lead_id' => $lead->id,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'created_at' => now(),
            ]);
        }

        $rule->forceFill(['run_count' => $rule->run_count + 1, 'last_run_at' => now()])->saveQuietly();
    }

    private function runAction(string $type, array $params, Lead $lead, AutomationRule $rule): ?string
    {
        $render = fn (?string $text) => $this->render($text ?? '', $lead);

        switch ($type) {
            case 'create_task':
                $assignee = ($params['assign_to'] ?? 'owner') === 'owner'
                    ? $lead->owner_id
                    : (int) $params['assign_to'];
                Task::create([
                    'organization_id' => $lead->organization_id,
                    'taskable_type' => $lead->getMorphClass(),
                    'taskable_id' => $lead->id,
                    'assigned_to' => $assignee,
                    'title' => $render($params['title'] ?? 'Follow up with {name}'),
                    'type' => $params['task_type'] ?? 'follow_up',
                    'priority' => $params['priority'] ?? 'medium',
                    'due_at' => now()->addHours((int) ($params['due_in_hours'] ?? 24)),
                ]);

                return 'task created';

            case 'notify_owner':
            case 'notify_user':
                $userId = $type === 'notify_owner' ? $lead->owner_id : ($params['user_id'] ?? null);
                $user = $userId ? User::withoutGlobalScopes()->where('organization_id', $lead->organization_id)->find($userId) : null;
                $user?->notify(new AppNotification(
                    $render($params['title'] ?? $rule->name),
                    $render($params['message'] ?? ''),
                    "/leads/{$lead->id}",
                    'automation',
                ));

                return $user ? "notified {$user->name}" : 'no recipient';

            case 'update_field':
                $field = $params['field'] ?? '';
                if (! in_array($field, self::UPDATABLE_FIELDS, true)) {
                    throw new \InvalidArgumentException("Field [{$field}] cannot be updated by automations.");
                }
                $lead->forceFill([$field => $params['value'] ?? null])->saveQuietly();

                return "{$field} updated";

            case 'add_tag':
                $tag = Tag::firstOrCreate(
                    ['organization_id' => $lead->organization_id, 'name' => $params['tag'] ?? 'automated'],
                );
                $lead->tags()->syncWithoutDetaching([$tag->id]);

                return "tagged {$tag->name}";

            case 'change_status':
                $status = LeadStatus::where('organization_id', $lead->organization_id)
                    ->where('key', $params['status_key'] ?? '')
                    ->first();
                if ($status && $status->id !== $lead->lead_status_id) {
                    app(LeadService::class)->changeStatus($lead, $status->id, null, "Automation: {$rule->name}");
                }

                return $status ? "status → {$status->name}" : 'status not found';

            case 'assign_user':
                if (! empty($params['user_id']) && (int) $params['user_id'] !== $lead->owner_id) {
                    app(LeadService::class)->assign($lead, (int) $params['user_id'], null);
                }

                return 'assigned';

            case 'add_note':
                $lead->notes()->create([
                    'organization_id' => $lead->organization_id,
                    'body' => $render($params['body'] ?? ''),
                ]);

                return 'note added';
        }

        throw new \InvalidArgumentException("Unknown automation action [{$type}].");
    }

    /**
     * Replace {placeholders} with lead values, e.g. "Call {name} at {company}".
     */
    private function render(string $text, Lead $lead): string
    {
        $values = array_merge(Arr::dot($this->evaluator->leadData($lead)), ['name' => $lead->full_name]);

        return preg_replace_callback('/\{([a-z_.]+)\}/', fn ($m) => (string) ($values[$m[1]] ?? ''), $text);
    }
}
