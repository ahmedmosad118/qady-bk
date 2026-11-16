<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseInvoiceFromOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('invoice_number')) {
            $trimmed = is_string($this->input('invoice_number')) ? trim($this->input('invoice_number')) : $this->input('invoice_number');
            $this->merge([
                'invoice_number' => ($trimmed === '' || $trimmed === null) ? null : $trimmed,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoice_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('purchase_invoices', 'invoice_number'),
            ],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount_type' => ['sometimes', 'nullable', 'string', Rule::in(DiscountType::values())],
            'discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            'items' => ['sometimes', 'array', 'min:1'],
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
}
