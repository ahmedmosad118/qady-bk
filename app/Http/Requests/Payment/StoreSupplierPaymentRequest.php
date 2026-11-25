<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Enums\Payment\PaymentRelatedType;
use App\Enums\Payment\PaymentType;
use App\Models\PaymentMethod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        if (! $this->has('payment_type')) {
            $merged['payment_type'] = PaymentType::SEND->value;
        }

        if (! $this->has('related_type')) {
            $merged['related_type'] = PaymentRelatedType::SUPPLIER->value;
        }

        if (! $this->has('allocations') || $this->input('allocations') === null) {
            $merged['allocations'] = [];
        }

        if ($this->has('reference') && is_string($this->input('reference'))) {
            $merged['reference'] = trim($this->input('reference'));
        }

        if ($this->has('description') && is_string($this->input('description'))) {
            $merged['description'] = trim($this->input('description'));
        }

        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_type' => ['required', 'string', Rule::in([PaymentType::SEND->value])],
            'related_type' => ['required', 'string', Rule::in([PaymentRelatedType::SUPPLIER->value])],
            'account_id' => [
                'nullable',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value !== null && $value !== '') {
                        $fail((string) __('payments.errors.account_id_must_be_null'));
                    }
                },
            ],
            'contact_id' => [
                'required',
                'integer',
                Rule::exists('contacts', 'id')->where(
                    static fn ($q) => $q->where('is_supplier', true)->where('is_active', true)->whereNull('deleted_at')
                ),
            ],
            'payment_method_id' => [
                'required',
                'integer',
                Rule::exists('payment_methods', 'id')->where(
                    static fn ($q) => $q->where('is_active', true)
                ),
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_numeric($value)) {
                        return;
                    }

                    $method = PaymentMethod::query()
                        ->with('account.accountType:id,code')
                        ->find((int) $value);

                    if (! $method || ! $method->is_active || ! $method->account || ! $method->account->is_active || $method->account->accountType?->code !== AccountTypeCode::BankCash->value) {
                        $fail((string) __('payments.errors.payment_method_account_invalid'));
                    }
                },
            ],
            'journal_id' => [
                'required',
                'integer',
                Rule::exists('journals', 'id')->where(
                    static fn ($q) => $q->where('is_active', true)->whereIn('type', [JournalType::Cash->value, JournalType::Bank->value])
                ),
            ],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['present', 'array'],
            'allocations.*.purchase_invoice_id' => ['required', 'integer', 'exists:purchase_invoices,id'],
            'allocations.*.allocated_amount' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
