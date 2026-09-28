<?php

namespace App\Services;

use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Picks an owner for a new lead using the first matching active rule
 * (ordered by priority), falling back to the unassigned queue.
 */
class AssignmentEngine
{
    public function __construct(private ConditionEvaluator $evaluator) {}

    /**
     * @return array{user: ?User, rule: ?AssignmentRule}
     */
    public function resolve(Lead $lead): array
    {
        $rules = AssignmentRule::where('organization_id', $lead->organization_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->evaluator->matchesAll($rule->conditions, $lead)) {
                continue;
            }

            if ($user = $this->pickUser($rule, $lead)) {
                return ['user' => $user, 'rule' => $rule];
            }
        }

        return ['user' => null, 'rule' => null];
    }

    public function pickUser(AssignmentRule $rule, Lead $lead): ?User
    {
        $pool = $this->candidatePool($rule, $lead->organization_id);

        if ($pool->isEmpty()) {
            return null;
        }

        return match ($rule->strategy) {
            'specific_user' => $pool->first(),
            'least_loaded' => $this->leastLoaded($pool),
            default => $this->roundRobin($rule, $pool),
        };
    }

    private function candidatePool(AssignmentRule $rule, int $organizationId): Collection
    {
        $ids = collect($rule->user_ids ?? []);

        if ($ids->isEmpty() && $rule->team_id) {
            $ids = Team::find($rule->team_id)?->members()->pluck('users.id') ?? collect();
        }

        return User::where('organization_id', $organizationId)
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->where('role', '!=', User::VIEWER)
            ->orderBy('id')
            ->get();
    }

    private function roundRobin(AssignmentRule $rule, Collection $pool): User
    {
        return DB::transaction(function () use ($rule, $pool) {
            $locked = AssignmentRule::whereKey($rule->id)->lockForUpdate()->first();
            $user = $pool->values()[$locked->cursor % $pool->count()];
            $locked->increment('cursor');
            $rule->cursor = $locked->cursor;

            return $user;
        });
    }

    private function leastLoaded(Collection $pool): User
    {
        $loads = Lead::whereIn('owner_id', $pool->pluck('id'))
            ->whereNull('converted_at')
            ->selectRaw('owner_id, count(*) as open_count')
            ->groupBy('owner_id')
            ->pluck('open_count', 'owner_id');

        return $pool->sortBy(fn (User $u) => [(int) ($loads[$u->id] ?? 0), $u->id])->first();
    }
}
