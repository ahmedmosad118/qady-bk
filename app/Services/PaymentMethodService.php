<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Enums\PaymentMethodType;
use App\Models\ChartOfAccount;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentMethodService
{
    /**
     * List all payment methods with their linked Chart of Accounts info.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function listAll(): Collection
    {
        return PaymentMethod::query()
            ->with('chartOfAccount:id,code,name,account_type,account_purpose')
            ->orderBy('id', 'asc')
            ->get(['id', 'name', 'type', 'chart_of_account_id', 'is_active']);
    }

    /**
     * Return compatible Asset accounts grouped by payment method type.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getCompatibleAccountsByType(): array
    {
        $accounts = ChartOfAccount::query()
            ->where('account_type', AccountType::ASSET->value)
            ->whereIn('account_purpose', [
                AccountPurpose::CASH->value,
                AccountPurpose::BANK->value,
            ])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'account_purpose']);

        $byPurpose = [
            AccountPurpose::CASH->value => [],
            AccountPurpose::BANK->value => [],
        ];

        foreach ($accounts as $account) {
            $purpose = ($account->account_purpose ?? AccountPurpose::GENERAL)->value;
            if (! isset($byPurpose[$purpose])) {
                continue;
            }

            $byPurpose[$purpose][] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'account_purpose' => $purpose,
            ];
        }

        $grouped = [];
        foreach (PaymentMethodType::cases() as $type) {
            $grouped[$type->value] = $byPurpose[$type->requiredAccountPurpose()->value];
        }

        return $grouped;
    }

    /**
     * Create a payment method linked to a compatible Asset account.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): PaymentMethod
    {
        return DB::transaction(function () use ($data): PaymentMethod {
            $type = PaymentMethodType::from((string) $data['type']);
            $accountId = isset($data['chart_of_account_id']) && is_numeric($data['chart_of_account_id'])
                ? (int) $data['chart_of_account_id']
                : 0;

            $account = $this->validateCompatibleAccount($type, $accountId);

            $paymentMethod = PaymentMethod::query()->create([
                'name' => trim((string) $data['name']),
                'type' => $type->value,
                'chart_of_account_id' => $account->id,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
            ]);

            $paymentMethod->setRelation('chartOfAccount', $account);

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

            $targetAccountId = array_key_exists('chart_of_account_id', $data)
                ? (is_numeric($data['chart_of_account_id']) ? (int) $data['chart_of_account_id'] : 0)
                : (int) ($locked->chart_of_account_id ?? 0);

            $account = $this->validateCompatibleAccount($targetType, $targetAccountId);

            $locked->update([
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $locked->name,
                'type' => $targetType->value,
                'chart_of_account_id' => $account->id,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $locked->is_active,
            ]);

            $locked->setRelation('chartOfAccount', $account);

            return $locked;
        });
    }

    public function validateCompatibleAccount(PaymentMethodType $type, int $accountId): ChartOfAccount
    {
        if ($accountId <= 0) {
            throw ValidationException::withMessages([
                'chart_of_account_id' => [(string) __('accounting.errors.payment_method_account_required')],
            ]);
        }

        /** @var ChartOfAccount|null $account */
        $account = ChartOfAccount::query()->lockForUpdate()->find($accountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'chart_of_account_id' => [(string) __('accounting.errors.payment_method_account_not_found')],
            ]);
        }

        if ($account->account_type !== AccountType::ASSET) {
            throw ValidationException::withMessages([
                'chart_of_account_id' => [(string) __('accounting.errors.payment_method_account_must_be_asset')],
            ]);
        }

        $requiredPurpose = $type->requiredAccountPurpose();
        $actualPurpose = $account->account_purpose ?? AccountPurpose::GENERAL;

        if ($actualPurpose !== $requiredPurpose) {
            throw ValidationException::withMessages([
                'chart_of_account_id' => [(string) __('accounting.errors.payment_method_account_purpose_mismatch', [
                    'purpose' => $requiredPurpose->label(),
                ])],
            ]);
        }

        return $account;
    }
}
