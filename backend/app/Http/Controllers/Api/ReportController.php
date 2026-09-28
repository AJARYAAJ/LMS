<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function leads(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $user = $request->user();
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(89)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();

        $base = fn (): Builder => Lead::visibleTo($user)->whereBetween('leads.created_at', [$from, $to]);
        $total = $base()->count();

        $funnel = [
            ['stage' => 'Leads', 'count' => $total],
            ['stage' => 'Contacted', 'count' => $base()->where(fn ($q) => $q->whereNotNull('last_contacted_at')->orWhereHas('status', fn ($s) => $s->where('key', '!=', 'new')))->count()],
            ['stage' => 'Qualified', 'count' => $base()->where(fn ($q) => $q->whereNotNull('qualified_at')->orWhereNotNull('converted_at'))->count()],
            ['stage' => 'Converted', 'count' => $base()->whereNotNull('converted_at')->count()],
            ['stage' => 'Won', 'count' => Deal::whereIn('lead_id', $base()->select('leads.id'))->where('status', 'won')->count()],
        ];

        $rate = fn (int $part, int $whole) => $whole ? round($part / $whole * 100, 1) : 0;

        // One grouped query per dimension instead of one query per row.
        $grouped = fn (string $column) => $base()
            ->selectRaw("{$column} as k, count(*) as leads")
            ->selectRaw('sum(case when qualified_at is not null or converted_at is not null then 1 else 0 end) as qualified')
            ->selectRaw('sum(case when converted_at is not null then 1 else 0 end) as converted')
            ->selectRaw('coalesce(sum(expected_value), 0) as value')
            ->groupBy($column)
            ->get()
            ->keyBy('k');

        $bySource = $grouped('lead_source_id');
        $sources = LeadSource::orderBy('name')->get()->map(function (LeadSource $source) use ($bySource, $rate) {
            $row = $bySource[$source->id] ?? null;

            return [
                'id' => $source->id, 'name' => $source->name, 'color' => $source->color,
                'leads' => (int) ($row->leads ?? 0),
                'qualified' => (int) ($row->qualified ?? 0),
                'converted' => (int) ($row->converted ?? 0),
                'conversion_rate' => $rate((int) ($row->converted ?? 0), (int) ($row->leads ?? 0)),
                'value' => (float) ($row->value ?? 0),
            ];
        })->filter(fn ($s) => $s['leads'] > 0)->values();

        $byOwner = $grouped('owner_id');
        $activities = Activity::where('type', '!=', 'system')->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('user_id, count(*) as c')->groupBy('user_id')->pluck('c', 'user_id');
        $won = Deal::where('status', 'won')->whereBetween('closed_at', [$from, $to])
            ->selectRaw('owner_id, coalesce(sum(amount), 0) as v')->groupBy('owner_id')->pluck('v', 'owner_id');

        $reps = User::where('role', '!=', User::VIEWER)->orderBy('name')->get()
            ->when($user->role === User::SALES_REP, fn ($c) => $c->where('id', $user->id))
            ->map(function (User $rep) use ($byOwner, $activities, $won, $rate) {
                $row = $byOwner[$rep->id] ?? null;

                return [
                    'id' => $rep->id, 'name' => $rep->name, 'avatar_color' => $rep->avatar_color,
                    'leads' => (int) ($row->leads ?? 0),
                    'converted' => (int) ($row->converted ?? 0),
                    'conversion_rate' => $rate((int) ($row->converted ?? 0), (int) ($row->leads ?? 0)),
                    'activities' => (int) ($activities[$rep->id] ?? 0),
                    'won_value' => (float) ($won[$rep->id] ?? 0),
                ];
            })->values();

        $byCampaign = $grouped('campaign_id');
        $campaigns = Campaign::orderBy('name')->get()->map(function (Campaign $c) use ($byCampaign, $rate) {
            $row = $byCampaign[$c->id] ?? null;
            $count = (int) ($row->leads ?? 0);
            $converted = (int) ($row->converted ?? 0);
            $cost = (float) ($c->actual_cost ?? $c->budget ?? 0);

            return [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status,
                'leads' => $count, 'converted' => $converted,
                'conversion_rate' => $rate($converted, $count),
                'cost' => $cost,
                'cost_per_lead' => $count && $cost ? round($cost / $count, 2) : null,
            ];
        })->filter(fn ($c) => $c['leads'] > 0 || $c['status'] === 'active')->values();

        $row = Lead::visibleTo($user)->whereNull('converted_at')
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as a', [now()->subDays(7)])
            ->selectRaw('sum(case when created_at < ? and created_at >= ? then 1 else 0 end) as b', [now()->subDays(7), now()->subDays(30)])
            ->selectRaw('sum(case when created_at < ? and created_at >= ? then 1 else 0 end) as c', [now()->subDays(30), now()->subDays(60)])
            ->selectRaw('sum(case when created_at < ? then 1 else 0 end) as d', [now()->subDays(60)])
            ->first();
        $aging = [
            ['bucket' => '0–7 days', 'count' => (int) $row->a],
            ['bucket' => '8–30 days', 'count' => (int) $row->b],
            ['bucket' => '31–60 days', 'count' => (int) $row->c],
            ['bucket' => '60+ days', 'count' => (int) $row->d],
        ];

        return response()->json(['data' => [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'funnel' => $funnel,
            'sources' => $sources,
            'reps' => $reps,
            'campaigns' => $campaigns,
            'aging' => $aging,
        ]]);
    }
}
