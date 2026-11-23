<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Enums\DiscountType;
use App\Http\Requests\Purchase\Concerns\ValidatesPurchaseDiscounts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseInvoiceRequest extends FormRequest
{
    use ValidatesPurchaseDiscounts;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('discount_type') && $this->input('discount_type') === '') {
            $merge['discount_type'] = null;
        }

        if (is_array($this->input('items'))) {
            $items = $this->input('items');
            foreach ($items as $idx => $item) {
                if (is_array($item) && array_key_exists('discount_type', $item) && $item['discount_type'] === '') {
                    $items[$idx]['discount_type'] = null;
                }
            }
            $merge['items'] = $items;
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purchase_order_id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_orders', 'id')->whereNull('deleted_at'),
            ],
            'supplier_id' => [
                'required_without:purchase_order_id',
                'nullable',
                'integer',
                Rule::exists('contacts', 'id')->where(
                    fn ($q) => $q->where('is_supplier', true)->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount_type' => ['nullable', 'string', Rule::in(DiscountType::values())],
            'discount_value' => ['nullable', 'numeric', 'min:0'],

            'items' => ['required_without:purchase_order_id', 'nullable', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'items.*.discount_type' => ['nullable', 'string', Rule::in(DiscountType::values())],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],

            'expenses' => ['nullable', 'array'],
            'expenses.*.name' => ['required', 'string', 'max:255'],
            'expenses.*.amount' => ['required', 'numeric', 'min:0'],
            'expenses.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->validatePurchaseDiscounts($v, true);
        });
    }
}
