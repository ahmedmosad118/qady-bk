<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SyncPosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        // 1. Check invoice discount authorization
        $invDiscVal = $this->input('invoice_discount_value');
        if ($invDiscVal !== null && (float) $invDiscVal > 0) {
            if (! Gate::allows('pos.apply_invoice_discount', $user)) {
                return false;
            }
        }

        // 2. Check item-level price overrides and discounts
        $items = $this->input('items', []);
        if (is_array($items)) {
            foreach ($items as $item) {
                // Check discount authorization
                if (! empty($item['discount_value']) && (float) $item['discount_value'] > 0) {
                    if (! Gate::allows('pos.apply_item_discount', $user)) {
                        return false;
                    }
                }

                // Check price override authorization
                if (isset($item['unit_price']) && ! empty($item['product_id'])) {
                    $product = Product::query()->find($item['product_id']);
                    if ($product && bccomp((string) $item['unit_price'], (string) $product->selling_price, 2) !== 0) {
                        if (! Gate::allows('pos.override_price', $user)) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'string', 'max:36'],
            'shift_id' => ['nullable', 'integer', 'exists:pos_shifts,id'],
            'customer_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'invoice_discount_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'invoice_discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
