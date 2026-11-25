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

        if (! $this->has('payment_type')) {
            $paymentType = PaymentType::SEND->value;
            $merged['payment_type'] = $paymentType;
        } else {
            $paymentType = (string) $this->input('payment_type');
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

        if ($this->has('contact_id') && $this->input('contact_id') === '') {
            $merged['contact_id'] = null;
        }

        if ($this->has('account_id') && $this->input('account_id') === '') {
            $merged['account_id'] = null;
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
        $paymentType = (string) $this->input('payment_type', '');
        $isTransfer = $paymentType === PaymentType::TRANSFER->value;
        $isSend = $paymentType === PaymentType::SEND->value;

        $mustBeNullClosure = static function (string $errorKey): Closure {
            return static function (string $attribute, mixed $value, Closure $fail) use ($errorKey): void {
                if ($value !== null && $value !== '') {
                    $fail((string) __($errorKey));
                }
            };
        };

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
            'related_type' => $isTransfer
                ? ['nullable', $mustBeNullClosure('payments.errors.transfer_related_must_be_null')]
                : [
                    'required',
                    'string',
                    Rule::in([
                        $isSend
                            ? PaymentRelatedType::SUPPLIER->value
                            : PaymentRelatedType::CUSTOMER->value,
                    ]),
                ],
            'contact_id' => $isTransfer
                ? ['nullable', $mustBeNullClosure('payments.errors.transfer_related_must_be_null')]
                : [
                    'required',
                    'integer',
                    Rule::exists('contacts', 'id')->where(function ($q) use ($isSend): void {
                        $q->where('is_active', true)->whereNull('deleted_at');
                        if ($isSend) {
                            $q->where('is_supplier', true);
                        } else {
                            $q->where('is_customer', true);
                        }
                    }),
                ],
            'account_id' => $isTransfer
                ? [
                    'required',
                    'integer',
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_numeric($value)) {
                            return;
                        }

                        $account = Account::query()
                            ->with('accountType:id,code')
                            ->find((int) $value);

                        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::BankCash->value) {
                            $fail((string) __('payments.errors.transfer_account_invalid'));

                            return;
                        }

                        $paymentMethodId = $this->input('payment_method_id');
                        if (is_numeric($paymentMethodId)) {
                            $method = PaymentMethod::query()->find((int) $paymentMethodId);
                            if ($method && (int) $method->account_id === (int) $value) {
                                $fail((string) __('payments.errors.transfer_same_account'));
                            }
                        }
                    },
                ]
                : ['nullable', $mustBeNullClosure('payments.errors.account_id_must_be_null')],
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

        if ($isSend) {
            $rules['allocations.*.purchase_invoice_id'] = ['required', 'integer', 'distinct', 'exists:purchase_invoices,id'];
            $rules['allocations.*.allocated_amount'] = ['required', 'numeric', 'gt:0'];
        } else {
            $rules['allocations'][] = static function (string $attribute, mixed $value, Closure $fail) use ($isTransfer): void {
                if (is_array($value) && $value !== []) {
                    $key = $isTransfer
                        ? 'payments.errors.transfer_allocations_not_supported'
                        : 'payments.errors.customer_allocations_not_supported';
                    $fail((string) __($key));
                }
            };
        }

        return $rules;
    }
}
