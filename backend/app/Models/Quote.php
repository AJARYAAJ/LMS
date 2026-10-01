<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['deal_id', 'created_by', 'number', 'title', 'status', 'currency', 'discount_percent', 'tax_percent', 'subtotal', 'total', 'valid_until', 'notes', 'sent_at', 'viewed_at', 'responded_at', 'signed_name', 'signed_ip', 'decline_reason'])]
#[Hidden(['signed_ip'])]
class Quote extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['draft', 'sent', 'accepted', 'declined', 'expired'];

    protected $attributes = ['status' => 'draft', 'discount_percent' => 0, 'tax_percent' => 0];

    protected static function booted(): void
    {
        static::creating(function (Quote $quote) {
            $quote->public_token ??= Str::random(40);
        });
    }

    protected function casts(): array
    {
        return [
            'discount_percent' => 'float',
            'tax_percent' => 'float',
            'subtotal' => 'float',
            'total' => 'float',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'sent'], true) && (! $this->valid_until || ! $this->valid_until->endOfDay()->isPast());
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/q/'.$this->public_token;
    }

    /** Recalculate line totals, subtotal, discount and tax (money rounded to cents). */
    public function recalculate(): void
    {
        $subtotal = 0.0;
        foreach ($this->items()->get() as $item) {
            $line = round($item->quantity * $item->unit_price * (1 - $item->discount_percent / 100), 2);
            $item->forceFill(['total' => $line])->saveQuietly();
            $subtotal += $line;
        }
        $afterDiscount = $subtotal * (1 - $this->discount_percent / 100);
        $this->forceFill([
            'subtotal' => round($subtotal, 2),
            'total' => round($afterDiscount * (1 + $this->tax_percent / 100), 2),
        ])->save();
    }
}
