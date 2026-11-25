<?php

declare(strict_types=1);

namespace App\Http\Requests\Purchase;

use App\Enums\Accounting\JournalType;
use App\Enums\DiscountType;
use App\Http\Requests\Purchase\Concerns\ValidatesPurchaseDiscounts;
use App\Models\PurchaseInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePurchaseInvoiceRequest extends FormRequest
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
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')->where(
                    fn ($q) => $q->where('is_supplier', true)->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'journal_id' => [
                'required',
                'integer',
                Rule::exists('journals', 'id')->where(
                    fn ($q) => $q->where('type', JournalType::Purchase->value)->where('is_active', true)
                ),
            ],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'discount_type' => ['nullable', 'string', Rule::in(DiscountType::values())],
            'discount_value' => ['nullable', 'numeric', 'min:0'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
            ],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
            'items.*.discount_type' => ['nullable', 'string', Rule::in(DiscountType::values())],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
        ], $this->purchaseExpensesRules(), $this->purchaseAttachmentRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var PurchaseInvoice|null $invoice */
            $invoice = $this->route('purchase_invoice');

            if ($invoice && $invoice->isLocked()) {
                $v->errors()->add('status', (string) __('purchases.errors.invoice_update_locked'));

                return;
            }

            $this->validatePurchaseDiscounts($v, true);
        });
    }
}
