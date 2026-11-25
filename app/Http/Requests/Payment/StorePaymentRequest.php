<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Enums\Payment\PaymentRelatedType;
use App\Enums\Payment\PaymentType;
use App\Models\Account;
use App\Models\PaymentMethod;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];

        $paymentType = (string) $this->input('payment_type', PaymentType::SEND->value);
        if (! $this->has('payment_type')) {
            $merged['payment_type'] = $paymentType;
        }

        if (! $this->has('related_type')) {
            $merged['related_type'] = match ($paymentType) {
                PaymentType::SEND->value => PaymentRelatedType::SUPPLIER->value,
                PaymentType::RECEIVE->value => PaymentRelatedType::CUSTOMER->value,
                default => null,
            };
        } elseif ($this->input('related_type') === '') {
            $merged['related_type'] = null;
        }

        if ($paymentType === PaymentType::TRANSFER->value) {
            $merged['related_type'] = null;
            $merged['contact_id'] = null;
            $merged['allocations'] = [];
        } else {
            $merged['account_id'] = null;
            if (! $this->has('allocations') || $this->input('allocations') === null) {
                $merged['allocations'] = [];
            }
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
        $paymentType = (string) $this->input('payment_type', '');

        $rules = [
            'payment_type' => [
                'required',
                'string',
                Rule::in([
                    PaymentType::SEND->value,
                    PaymentType::RECEIVE->value,
                    PaymentType::TRANSFER->value,
                ]),
            ],
            'related_type' => $paymentType === PaymentType::TRANSFER->value
                ? ['nullable', Rule::in([null])]
                : [
                    'required',
                    'string',
                    Rule::in([
                        $paymentType === PaymentType::SEND->value
                            ? PaymentRelatedType::SUPPLIER->value
                            : PaymentRelatedType::CUSTOMER->value,
                    ]),
                ],
            'contact_id' => $paymentType === PaymentType::TRANSFER->value
                ? ['nullable']
                : [
                    'required',
                    'integer',
                    Rule::exists('contacts', 'id')->where(function ($q) use ($paymentType): void {
                        $q->where('is_active', true)->whereNull('deleted_at');
                        if ($paymentType === PaymentType::SEND->value) {
                            $q->where('is_supplier', true);
                        } else {
                            $q->where('is_customer', true);
                        }
                    }),
                ],
            'account_id' => $paymentType === PaymentType::TRANSFER->value
                ? [
                    'required',
                    'integer',
                    static function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_numeric($value)) {
                            return;
                        }

                        $account = Account::query()
                            ->with('accountType:id,code')
                            ->find((int) $value);

                        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::BankCash->value) {
                            $fail((string) __('payments.errors.transfer_account_invalid'));
                        }
                    },
                ]
                : ['nullable'],
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
            'allocations' => ['nullable', 'array'],
        ];

        if ($paymentType === PaymentType::SEND->value) {
            $rules['allocations.*.purchase_invoice_id'] = ['required', 'integer', 'distinct', 'exists:purchase_invoices,id'];
            $rules['allocations.*.allocated_amount'] = ['required', 'numeric', 'gt:0'];
        } else {
            $rules['allocations'][] = static function (string $attribute, mixed $value, Closure $fail) use ($paymentType): void {
                if (is_array($value) && $value !== []) {
                    $key = $paymentType === PaymentType::RECEIVE->value
                        ? 'payments.errors.customer_allocations_not_supported'
                        : 'payments.errors.transfer_allocations_not_supported';
                    $fail((string) __($key));
                }
            };
        }

        return $rules;
    }
}
