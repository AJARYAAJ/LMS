<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'name', 'description', 'quantity', 'unit_price', 'discount_percent', 'total', 'position'])]
class QuoteItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'float', 'discount_percent' => 'float', 'total' => 'float'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
