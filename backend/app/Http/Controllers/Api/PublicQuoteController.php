<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Services\QuoteService;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer-facing quote page: view, accept with a typed signature, or decline. */
class PublicQuoteController extends Controller
{
    public function __construct(private QuoteService $quotes) {}

    private function find(string $token): Quote
    {
        $quote = Quote::withoutGlobalScopes()->with('items', 'deal.organization', 'deal.contact', 'deal.owner')->where('public_token', $token)->firstOrFail();
        abort_if($quote->status === 'draft', 404);

        return $quote;
    }

    public function show(string $token): JsonResponse
    {
        $quote = $this->find($token);
        Tenant::run($quote->organization_id, fn () => $this->quotes->markViewed($quote));
        if ($quote->status === 'sent' && ! $quote->isOpen()) {
            $quote->update(['status' => 'expired']);
        }
        $deal = $quote->deal;

        return response()->json(['data' => [
            ...$quote->only(['number', 'title', 'status', 'currency', 'discount_percent', 'tax_percent', 'subtotal', 'total', 'valid_until', 'notes', 'signed_name', 'responded_at', 'created_at']),
            'items' => $quote->items->map->only(['name', 'description', 'quantity', 'unit_price', 'discount_percent', 'total']),
            'organization' => $deal->organization->only(['name', 'website', 'phone']),
            'prepared_by' => $deal->owner?->only(['name', 'email']),
            'customer' => $deal->contact ? trim($deal->contact->first_name.' '.$deal->contact->last_name) : null,
        ]]);
    }

    public function respond(Request $request, string $token): JsonResponse
    {
        $quote = $this->find($token);
        abort_unless($quote->isOpen(), 422, 'This quote can no longer be accepted. Ask for an updated one.');
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'name' => ['required_if:accept,true', 'nullable', 'string', 'min:2', 'max:120'],
            'agree' => ['required_if:accept,true', 'accepted_if:accept,true'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        Tenant::run($quote->organization_id, fn () => $this->quotes->respond($quote, (bool) $data['accept'], $data['name'] ?? null, $data['reason'] ?? null, $request->ip()));

        return $this->show($token);
    }
}
