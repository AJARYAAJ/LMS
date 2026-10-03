<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search across CRM entities (powers the ⌘K command palette).
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q'));

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $like = '%'.$term.'%';
        $user = $request->user();

        $leads = Lead::visibleTo($user)
            ->where(fn ($q) => $q->whereLike('first_name', $like)->orWhereLike('last_name', $like)
                ->orWhereLike('email', $like)->orWhereLike('company', $like)->orWhereLike('phone', $like))
            ->with('status:id,name,color')->limit(6)->get()
            ->map(fn (Lead $l) => ['type' => 'lead', 'id' => $l->id, 'title' => $l->full_name, 'subtitle' => $l->company ?? $l->email, 'badge' => $l->status?->name, 'color' => $l->status?->color, 'url' => "/leads/{$l->id}"]);

        $contacts = Contact::where(fn ($q) => $q->whereLike('first_name', $like)->orWhereLike('last_name', $like)->orWhereLike('email', $like))
            ->limit(4)->get()
            ->map(fn (Contact $c) => ['type' => 'contact', 'id' => $c->id, 'title' => $c->full_name, 'subtitle' => $c->email, 'url' => "/contacts/{$c->id}"]);

        $accounts = Account::whereLike('name', $like)->limit(4)->get()
            ->map(fn (Account $a) => ['type' => 'account', 'id' => $a->id, 'title' => $a->name, 'subtitle' => $a->industry, 'url' => "/accounts/{$a->id}"]);

        $deals = Deal::whereLike('name', $like)
            ->when($user->role === User::SALES_REP, fn ($q) => $q->where('owner_id', $user->id))
            ->limit(4)->get()
            ->map(fn (Deal $d) => ['type' => 'deal', 'id' => $d->id, 'title' => $d->name, 'subtitle' => number_format((float) $d->amount, 2).' '.$d->currency, 'url' => "/deals/{$d->id}"]);

        return response()->json(['data' => $leads->concat($contacts)->concat($accounts)->concat($deals)->values()]);
    }
}
