<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\PaymentMethodType;
use App\Models\Account;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodService
{
    /**
     * List all payment methods with their linked account info.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function listAll(): Collection
    {
        return PaymentMethod::query()
            ->with([
                'account:id,code,name,account_type_id,is_active',
                'account.accountType:id,code',
            ])
            ->orderBy('id', 'asc')
            ->get(['id', 'name', 'type', 'account_id', 'is_active']);
    }

    /**
     * Return active bank_cash accounts available for payment method linking.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCompatibleAccounts(): array
    {
        return Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static function ($query): void {
                $query->where('code', AccountTypeCode::BankCash->value);
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(static fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->all();
    }

    /**
     * Return compatible accounts grouped by payment method type for response compatibility.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getCompatibleAccountsByType(): array
    {
        $accounts = $this->getCompatibleAccounts();

        $grouped = [];
        foreach (PaymentMethodType::cases() as $type) {
            $grouped[$type->value] = $accounts;
        }

        return $grouped;
    }

    /**
     * Create a payment method linked to an active bank_cash account.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PaymentMethod
    {
        return DB::transaction(function () use ($data): PaymentMethod {
            $type = PaymentMethodType::from((string) $data['type']);
            $accountId = isset($data['account_id']) && is_numeric($data['account_id'])
                ? (int) $data['account_id']
                : 0;

            $account = $this->validateCompatibleAccount($accountId);

            $paymentMethod = PaymentMethod::query()->create([
                'name' => trim((string) $data['name']),
                'type' => $type->value,
                'account_id' => $account->id,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $paymentMethod->setRelation('account', $account);

            return $paymentMethod;
        });
    }

    /**
     * Update an existing payment method while preserving ID and validating account compatibility.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PaymentMethod $paymentMethod, array $data): PaymentMethod
    {
        return DB::transaction(function () use ($paymentMethod, $data): PaymentMethod {
            /** @var PaymentMethod $locked */
            $locked = PaymentMethod::query()->lockForUpdate()->findOrFail($paymentMethod->id);

            $targetType = array_key_exists('type', $data) && $data['type'] !== null
                ? PaymentMethodType::from((string) $data['type'])
                : $locked->type;

            $targetAccountId = array_key_exists('account_id', $data)
                ? (is_numeric($data['account_id']) ? (int) $data['account_id'] : 0)
                : (int) ($locked->account_id ?? 0);

            $account = $this->validateCompatibleAccount($targetAccountId);

            $locked->update([
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $locked->name,
                'type' => $targetType->value,
                'account_id' => $account->id,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $locked->is_active,
            ]);

            $locked->setRelation('account', $account);

            return $locked;
        });
    }

    public function validateCompatibleAccount(int $accountId): Account
    {
        if ($accountId <= 0) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('accounting.errors.payment_method_account_required')],
            ]);
        }

        /** @var Account|null $account */
        $account = Account::query()
            ->with('accountType:id,code,name')
            ->lockForUpdate()
            ->find($accountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('accounting.errors.payment_method_account_not_found')],
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('accounting.errors.payment_method_account_inactive')],
            ]);
        }

        if ($account->accountType?->code !== AccountTypeCode::BankCash->value) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('accounting.errors.payment_method_account_must_be_bank_cash')],
            ]);
        }

        return $account;
    }
}
