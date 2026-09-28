<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $leads = fn () => Lead::visibleTo($user);
        $monthStart = now()->startOfMonth();
        $lastMonthStart = now()->subMonthNoOverflow()->startOfMonth();

        $categoryCounts = $leads()
            ->join('lead_statuses', 'lead_statuses.id', '=', 'leads.lead_status_id')
            ->selectRaw('lead_statuses.category, count(*) as total')
            ->groupBy('lead_statuses.category')
            ->pluck('total', 'category');

        $total = (int) $categoryCounts->sum();
        $converted = (int) ($categoryCounts['converted'] ?? 0);
        $newThisMonth = $leads()->where('leads.created_at', '>=', $monthStart)->count();
        $newLastMonth = $leads()->whereBetween('leads.created_at', [$lastMonthStart, $monthStart])->count();

        $openLeads = $leads()->whereNull('converted_at')->get(['created_at']);
        $avgAge = $openLeads->isEmpty() ? 0 : round($openLeads->avg(fn ($l) => $l->created_at->diffInDays(now())), 1);

        $deals = Deal::query()->when($user->role === User::SALES_REP, fn ($q) => $q->where('owner_id', $user->id));

        return response()->json(['data' => [
            'kpis' => [
                'total_leads' => $total,
                'new_this_month' => $newThisMonth,
                'new_growth' => $newLastMonth ? round(($newThisMonth - $newLastMonth) / $newLastMonth * 100, 1) : null,
                'open' => (int) ($categoryCounts['open'] ?? 0),
                'qualified' => (int) ($categoryCounts['qualified'] ?? 0),
                'converted' => $converted,
                'lost' => (int) ($categoryCounts['lost'] ?? 0),
                'conversion_rate' => $total ? round($converted / $total * 100, 1) : 0,
                'pipeline_value' => (float) (clone $deals)->where('status', 'open')->sum('amount'),
                'weighted_pipeline' => (float) (clone $deals)->where('status', 'open')->selectRaw('coalesce(sum(amount * probability / 100.0), 0) as w')->value('w'),
                'won_this_month' => (float) (clone $deals)->where('status', 'won')->where('closed_at', '>=', $monthStart)->sum('amount'),
                'avg_lead_age_days' => $avgAge,
                'unassigned' => $user->hasRole(User::ADMIN, User::MANAGER) ? Lead::whereNull('owner_id')->whereNull('converted_at')->count() : null,
            ],
            'my' => [
                'open_leads' => Lead::where('owner_id', $user->id)->whereNull('converted_at')->count(),
                'follow_ups_today' => Lead::where('owner_id', $user->id)->whereBetween('next_follow_up_at', [now()->startOfDay(), now()->endOfDay()])->count(),
                'overdue_follow_ups' => Lead::where('owner_id', $user->id)->whereNull('converted_at')->where('next_follow_up_at', '<', now())->count(),
                'overdue_tasks' => Task::where('assigned_to', $user->id)->whereNull('completed_at')->where('due_at', '<', now())->count(),
                'tasks_today' => Task::where('assigned_to', $user->id)->whereNull('completed_at')->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()])->count(),
            ],
            'by_status' => LeadStatus::orderBy('display_order')->get(['id', 'name', 'color', 'category'])
                ->map(fn ($s) => [...$s->toArray(), 'count' => $leads()->where('lead_status_id', $s->id)->count()]),
            'by_source' => LeadSource::orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn ($s) => [...$s->toArray(), 'count' => $leads()->where('lead_source_id', $s->id)->count()])
                ->filter(fn ($s) => $s['count'] > 0)->values(),
            'by_rating' => collect(Lead::RATINGS)->map(fn ($r) => ['rating' => $r, 'count' => $leads()->where('rating', $r)->whereNull('converted_at')->count()]),
            'trend' => $this->trend($user, 30),
            'heatmap' => $this->heatmap($user),
            'upcoming_tasks' => Task::with('taskable')
                ->where('assigned_to', $user->id)->whereNull('completed_at')
                ->orderByRaw('case when due_at is null then 1 else 0 end')->orderBy('due_at')->limit(6)->get()
                ->map(fn (Task $t) => [...$t->only(['id', 'title', 'type', 'priority', 'due_at']), 'is_overdue' => $t->isOverdue(),
                    'taskable' => $t->taskable ? ['type' => $t->taskable_type, 'id' => $t->taskable->id, 'name' => $t->taskable->full_name ?? $t->taskable->name] : null]),
            'hot_leads' => $leads()->whereNull('converted_at')->with(['status:id,name,color', 'owner:id,name,avatar_color'])
                ->orderByDesc('score')->limit(5)->get(['id', 'first_name', 'last_name', 'company', 'score', 'rating', 'expected_value', 'lead_status_id', 'owner_id']),
        ]]);
    }

    /**
     * Non-system activity counts per day for the last 12 weeks.
     */
    private function heatmap(User $user): array
    {
        $from = now()->subWeeks(12)->startOfWeek();
        $counts = Activity::where('type', '!=', 'system')
            ->where('occurred_at', '>=', $from)
            ->when($user->role === User::SALES_REP, fn ($q) => $q->where('user_id', $user->id))
            ->pluck('occurred_at')
            ->countBy(fn (Carbon $d) => $d->toDateString());

        $days = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $days[] = ['date' => $d->toDateString(), 'count' => $counts[$d->toDateString()] ?? 0];
        }

        return $days;
    }

    private function trend(User $user, int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $created = Lead::visibleTo($user)->where('created_at', '>=', $from)->pluck('created_at')
            ->countBy(fn (Carbon $d) => $d->toDateString());
        $converted = Lead::visibleTo($user)->where('converted_at', '>=', $from)->pluck('converted_at')
            ->countBy(fn (Carbon $d) => $d->toDateString());

        return collect(range(0, $days - 1))->map(function ($i) use ($from, $created, $converted) {
            $date = $from->copy()->addDays($i)->toDateString();

            return ['date' => $date, 'created' => $created[$date] ?? 0, 'converted' => $converted[$date] ?? 0];
        })->all();
    }
}
