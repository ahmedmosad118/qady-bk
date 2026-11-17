<?php

declare(strict_types=1);

namespace App\Http\Requests\Pos;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePosSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! Gate::allows('pos.sell', $user)) {
            return false;
        }

        $invDiscVal = $this->input('invoice_discount_value');
        if ($invDiscVal !== null && is_numeric($invDiscVal) && bccomp((string) $invDiscVal, '0', 4) > 0) {
            if (! Gate::allows('pos.apply_invoice_discount', $user)) {
                return false;
            }
        }

        $items = $this->input('items', []);
        if (is_array($items) && $items !== []) {
            $itemsWithPriceOverrideCheck = [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                if (
                    isset($item['discount_value'])
                    && is_numeric($item['discount_value'])
                    && bccomp((string) $item['discount_value'], '0', 4) > 0
                ) {
                    if (! Gate::allows('pos.apply_item_discount', $user)) {
                        return false;
                    }
                }

                if (isset($item['unit_price']) && is_numeric($item['unit_price']) && ! empty($item['product_id'])) {
                    $itemsWithPriceOverrideCheck[] = $item;
                }
            }

            if ($itemsWithPriceOverrideCheck !== [] && ! Gate::allows('pos.override_price', $user)) {
                $productIds = array_values(array_unique(array_map(
                    static fn (array $item): int => (int) $item['product_id'],
                    $itemsWithPriceOverrideCheck
                )));

                $sellingPrices = Product::query()
                    ->whereIn('id', $productIds)
                    ->pluck('selling_price', 'id');

                foreach ($itemsWithPriceOverrideCheck as $item) {
                    $productId = (int) $item['product_id'];
                    if ($sellingPrices->has($productId)) {
                        $sellingPrice = (string) $sellingPrices->get($productId);
                        if (bccomp((string) $item['unit_price'], $sellingPrice, 2) !== 0) {
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
            'shift_id' => ['required', 'integer', 'exists:pos_shifts,id'],
            'customer_id' => ['nullable', 'integer', 'exists:contacts,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'invoice_discount_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'invoice_discount_value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_uuid' => ['nullable', 'string', 'max:36'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_type' => ['nullable', 'string', 'in:fixed,percentage'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
