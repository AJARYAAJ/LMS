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

        $sources = LeadSource::orderBy('name')->get()->map(function (LeadSource $source) use ($base, $rate) {
            $q = fn () => $base()->where('lead_source_id', $source->id);
            $count = $q()->count();
            $converted = $q()->whereNotNull('converted_at')->count();

            return [
                'id' => $source->id, 'name' => $source->name, 'color' => $source->color,
                'leads' => $count,
                'qualified' => $q()->where(fn ($x) => $x->whereNotNull('qualified_at')->orWhereNotNull('converted_at'))->count(),
                'converted' => $converted,
                'conversion_rate' => $rate($converted, $count),
                'value' => (float) $q()->sum('expected_value'),
            ];
        })->filter(fn ($s) => $s['leads'] > 0)->values();

        $reps = User::where('role', '!=', User::VIEWER)->orderBy('name')->get()
            ->when($user->role === User::SALES_REP, fn ($c) => $c->where('id', $user->id))
            ->map(function (User $rep) use ($base, $from, $to, $rate) {
                $count = $base()->where('owner_id', $rep->id)->count();
                $converted = $base()->where('owner_id', $rep->id)->whereNotNull('converted_at')->count();

                return [
                    'id' => $rep->id, 'name' => $rep->name, 'avatar_color' => $rep->avatar_color,
                    'leads' => $count,
                    'converted' => $converted,
                    'conversion_rate' => $rate($converted, $count),
                    'activities' => Activity::where('user_id', $rep->id)->where('type', '!=', 'system')->whereBetween('occurred_at', [$from, $to])->count(),
                    'won_value' => (float) Deal::where('owner_id', $rep->id)->where('status', 'won')->whereBetween('closed_at', [$from, $to])->sum('amount'),
                ];
            })->values();

        $campaigns = Campaign::orderBy('name')->get()->map(function (Campaign $c) use ($base, $rate) {
            $count = $base()->where('campaign_id', $c->id)->count();
            $converted = $base()->where('campaign_id', $c->id)->whereNotNull('converted_at')->count();
            $cost = (float) ($c->actual_cost ?? $c->budget ?? 0);

            return [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status,
                'leads' => $count, 'converted' => $converted,
                'conversion_rate' => $rate($converted, $count),
                'cost' => $cost,
                'cost_per_lead' => $count && $cost ? round($cost / $count, 2) : null,
            ];
        })->filter(fn ($c) => $c['leads'] > 0 || $c['status'] === 'active')->values();

        $open = Lead::visibleTo($user)->whereNull('converted_at')->pluck('created_at');
        $aging = [
            ['bucket' => '0–7 days', 'count' => $open->filter(fn ($d) => $d->diffInDays(now()) <= 7)->count()],
            ['bucket' => '8–30 days', 'count' => $open->filter(fn ($d) => $d->diffInDays(now()) > 7 && $d->diffInDays(now()) <= 30)->count()],
            ['bucket' => '31–60 days', 'count' => $open->filter(fn ($d) => $d->diffInDays(now()) > 30 && $d->diffInDays(now()) <= 60)->count()],
            ['bucket' => '60+ days', 'count' => $open->filter(fn ($d) => $d->diffInDays(now()) > 60)->count()],
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
