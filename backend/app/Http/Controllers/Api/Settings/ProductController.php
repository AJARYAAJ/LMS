<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ResourceController;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends ResourceController
{
    protected string $model = Product::class;

    protected string $orderBy = 'name';

    protected function rules(Request $request, ?Model $record = null): array
    {
        return [
            'name' => [$record ? 'sometimes' : 'required', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit_price' => [$record ? 'sometimes' : 'required', 'numeric', 'min:0', 'max:999999999'],
            'billing' => ['sometimes', Rule::in(Product::BILLING)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
