<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\DB;

/** Builds quotes from line items, sends them and records the customer's answer. */
class QuoteService
{
    public function __construct(
        private ActivityRecorder $activities,
        private OrgMailer $mailer,
        private WebhookDispatcher $webhooks,
    ) {}

    public function nextNumber(): string
    {
        $year = now()->year;
        $last = Quote::where('number', 'like', "Q-{$year}-%")->orderByDesc('id')->value('number');
        $n = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('Q-%d-%04d', $year, $n);
    }

    /** @param  array{title?: string, valid_until?: ?string, discount_percent?: float, tax_percent?: float, notes?: ?string, items?: list<array>}  $data */
    public function save(Deal $deal, array $data, ?User $actor, ?Quote $quote = null): Quote
    {
        return DB::transaction(function () use ($deal, $data, $actor, $quote) {
            $fields = array_intersect_key($data, array_flip(['title', 'valid_until', 'discount_percent', 'tax_percent', 'notes']));
            if ($quote) {
                $quote->update($fields);
            } else {
                $quote = Quote::create([
                    'deal_id' => $deal->id,
                    'created_by' => $actor?->id,
                    'number' => $this->nextNumber(),
                    'title' => $fields['title'] ?? $deal->name,
                    'currency' => $deal->currency ?: ($deal->organization?->currency ?? 'USD'),
                    'valid_until' => $fields['valid_until'] ?? now()->addDays(30)->toDateString(),
                    ...array_diff_key($fields, array_flip(['title', 'valid_until'])),
                ]);
                $this->activities->record($deal, 'system', "Quote {$quote->number} created", ['user_id' => $actor?->id]);
            }

            if (array_key_exists('items', $data)) {
                $quote->items()->delete();
                foreach (array_values($data['items']) as $i => $item) {
                    $quote->items()->create([
                        'product_id' => $item['product_id'] ?? null,
                        'name' => $item['name'],
                        'description' => $item['description'] ?? null,
                        'quantity' => $item['quantity'] ?? 1,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'discount_percent' => $item['discount_percent'] ?? 0,
                        'position' => $i,
                    ]);
                }
            }
            $quote->recalculate();

            return $quote->fresh('items');
        });
    }

    public function send(Quote $quote, string $to, ?User $actor): void
    {
        $deal = $quote->deal;
        $org = $deal->organization;
        $lines = [
            'Hello,',
            '',
            "{$org->name} has sent you quote {$quote->number}: {$quote->title}.",
            'Total: '.$quote->currency.' '.number_format($quote->total, 2).($quote->valid_until ? ' · valid until '.$quote->valid_until->format('M j, Y') : ''),
            '',
            "Review and accept it here: {$quote->publicUrl()}",
            '',
            $actor ? "— {$actor->name}" : "— {$org->name}",
        ];
        $this->mailer->send($quote->organization_id, $to, null, "Quote {$quote->number} from {$org->name}", implode("\n", $lines), $actor ? [$actor->email, $actor->name] : null);
        $quote->update(['status' => 'sent', 'sent_at' => now()]);
        $this->activities->record($deal, 'email', "Sent quote {$quote->number}", ['user_id' => $actor?->id, 'direction' => 'outbound', 'description' => "To {$to} · {$quote->currency} ".number_format($quote->total, 2), 'meta' => ['quote_id' => $quote->id]]);
    }

    public function markViewed(Quote $quote): void
    {
        if ($quote->viewed_at) {
            return;
        }
        $quote->update(['viewed_at' => now()]);
        $this->activities->record($quote->deal, 'system', "Quote {$quote->number} opened by the customer");
    }

    public function respond(Quote $quote, bool $accepted, ?string $name, ?string $reason, ?string $ip): void
    {
        $deal = $quote->deal;
        $quote->update([
            'status' => $accepted ? 'accepted' : 'declined',
            'responded_at' => now(),
            'signed_name' => $accepted ? $name : null,
            'signed_ip' => $accepted ? $ip : null,
            'decline_reason' => $accepted ? null : $reason,
        ]);
        if ($accepted) {
            // The accepted quote is what the deal is worth now.
            $deal->update(['amount' => $quote->total]);
        }

        $title = $accepted ? "Quote {$quote->number} accepted by {$name}" : "Quote {$quote->number} declined";
        $this->activities->record($deal, 'system', $title, ['description' => $accepted ? 'Signed electronically.' : ($reason ?: null), 'meta' => ['quote_id' => $quote->id]]);
        $deal->owner?->notify(new AppNotification($title, "{$deal->name} · {$quote->currency} ".number_format($quote->total, 2), "/deals/{$deal->id}", 'quote'));
        $this->webhooks->dispatch($quote->organization_id, $accepted ? 'quote.accepted' : 'quote.declined', [
            'quote' => $quote->only(['id', 'number', 'title', 'status', 'currency', 'total', 'signed_name', 'responded_at']),
            'deal' => $deal->only(['id', 'name', 'amount']),
        ]);
    }
}
