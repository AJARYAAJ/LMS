<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function __construct(private QuoteService $quotes) {}

    private function deal(Request $request, int $dealId): Deal
    {
        $user = $request->user();

        return Deal::query()->when($user->role === User::SALES_REP, fn ($q) => $q->where('owner_id', $user->id))->findOrFail($dealId);
    }

    private function quote(Request $request, int $id): Quote
    {
        $quote = Quote::with('items', 'deal')->findOrFail($id);
        $this->deal($request, $quote->deal_id); // same visibility as the deal

        return $quote;
    }

    public function index(Request $request, int $dealId): JsonResponse
    {
        return response()->json(['data' => $this->deal($request, $dealId)->quotes()->with('items', 'creator:id,name')->get()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $quote = $this->quote($request, $id)->load('creator:id,name');

        return response()->json(['data' => [...$quote->toArray(), 'public_url' => $quote->publicUrl()]]);
    }

    public function store(Request $request, int $dealId): JsonResponse
    {
        $quote = $this->quotes->save($this->deal($request, $dealId), $this->validated($request), $request->user());

        return response()->json(['data' => $quote], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $quote = $this->quote($request, $id);
        abort_unless(in_array($quote->status, ['draft', 'sent'], true), 422, 'Accepted or declined quotes can’t be changed. Duplicate it instead.');
        $quote = $this->quotes->save($quote->deal, $this->validated($request, true), $request->user(), $quote);
        $quote->update(['status' => 'draft']); // edited quotes need sending again

        return response()->json(['data' => $quote->fresh('items')]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $quote = $this->quote($request, $id);
        abort_if($quote->status === 'accepted', 422, 'Accepted quotes are kept as a record.');
        $quote->delete();

        return response()->json(null, 204);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $quote = $this->quote($request, $id);
        abort_unless($quote->isOpen(), 422, 'Only open quotes can be sent.');
        abort_unless($quote->items()->exists(), 422, 'Add at least one line item first.');
        $data = $request->validate(['to' => ['nullable', 'email']]);
        $to = $data['to'] ?? $quote->deal->contact?->email ?? $quote->deal->lead?->email;
        abort_unless($to, 422, 'This deal has no contact email. Enter one to send the quote.');
        $this->quotes->send($quote, $to, $request->user());

        return response()->json(['message' => "Quote sent to {$to}.", 'data' => $quote->fresh('items')]);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $quote = $this->quote($request, $id);
        $copy = $this->quotes->save($quote->deal, [
            ...$quote->only(['title', 'discount_percent', 'tax_percent', 'notes']),
            'items' => $quote->items->map->only(['product_id', 'name', 'description', 'quantity', 'unit_price', 'discount_percent'])->all(),
        ], $request->user());

        return response()->json(['data' => $copy], 201);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'title' => [$partial ? 'sometimes' : 'nullable', 'string', 'max:160'],
            'valid_until' => ['nullable', 'date'],
            'discount_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'tax_percent' => ['sometimes', 'numeric', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => [$partial ? 'sometimes' : 'required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['nullable', Rules::exists('products')],
            'items.*.name' => ['required', 'string', 'max:160'],
            'items.*.description' => ['nullable', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'items.*.discount_percent' => ['sometimes', 'numeric', 'between:0,100'],
        ]);
    }
}
