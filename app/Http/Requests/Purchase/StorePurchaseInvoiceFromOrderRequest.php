<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Enums\Accounting\JournalType;
use App\Enums\DiscountType;
use App\Http\Requests\Purchase\Concerns\ValidatesPurchaseDiscounts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseInvoiceFromOrderRequest extends FormRequest
{
    use ValidatesPurchaseDiscounts;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeEmptyDiscountTypes();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'journal_id' => [
                'required',
                'integer',
                Rule::exists('journals', 'id')->where(
                    fn ($q) => $q->where('type', JournalType::Purchase->value)->where('is_active', true)
                ),
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
        ], $this->purchaseExpensesRules(), $this->purchaseAttachmentRules());
    }
}
