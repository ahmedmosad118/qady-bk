<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('invoice_number')) {
            $trimmed = is_string($this->input('invoice_number')) ? trim($this->input('invoice_number')) : $this->input('invoice_number');
            $merge['invoice_number'] = ($trimmed === '' || $trimmed === null) ? null : $trimmed;
        }

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
            'invoice_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('purchase_invoices', 'invoice_number'),
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
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
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
            $discType = $this->input('discount_type');
            $discVal = $this->input('discount_value');

            if ($discType === DiscountType::PERCENTAGE->value && $discVal !== null && (float) $discVal > 100) {
                $v->errors()->add('discount_value', 'Invoice discount percentage cannot exceed 100%.');
            }

            if ($discType === null && $discVal !== null && $discVal !== '' && (float) $discVal > 0) {
                $v->errors()->add('discount_type', 'Invoice discount type is required when discount value is provided.');
            }

            $items = $this->input('items', []);
            if (is_array($items)) {
                foreach ($items as $idx => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $itemType = $item['discount_type'] ?? null;
                    $itemVal = $item['discount_value'] ?? null;

                    if ($itemType === DiscountType::PERCENTAGE->value && $itemVal !== null && (float) $itemVal > 100) {
                        $v->errors()->add("items.{$idx}.discount_value", 'Item discount percentage cannot exceed 100%.');
                    }

                    if ($itemType === null && $itemVal !== null && $itemVal !== '' && (float) $itemVal > 0) {
                        $v->errors()->add("items.{$idx}.discount_type", 'Item discount type is required when discount value is provided.');
                    }
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'purchase_order_id' => 'purchase order',
            'invoice_number' => 'invoice number',
            'supplier_id' => 'supplier',
            'invoice_date' => 'invoice date',
            'due_date' => 'due date',
            'notes' => 'notes',
            'discount_type' => 'discount type',
            'discount_value' => 'discount value',
            'items' => 'items',
            'items.*.product_id' => 'product',
            'items.*.quantity' => 'quantity',
            'items.*.unit_price' => 'unit price',
            'items.*.discount_type' => 'item discount type',
            'items.*.discount_value' => 'item discount value',
            'expenses' => 'additional expenses',
            'expenses.*.name' => 'expense name',
            'expenses.*.amount' => 'expense amount',
            'expenses.*.notes' => 'expense notes',
        ];
    }
}
