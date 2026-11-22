<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Repositories\ChartOfAccountRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unified Chart of Accounts domain service facade.
 */
class ChartOfAccountService
{
    private const AUTO_CODE_MAX_RETRIES = 5;

    public function __construct(
        protected ChartOfAccountRepository $repository,
        protected AccountCodeGenerator $codeGenerator,
        protected AccountingSettingService $accountingSettingService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return $this->repository->stats();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(ChartOfAccount $account): array
    {
        return $this->repository->getDetails($account);
    }

    /**
     * Create an independent postable account, generating a unique global code if omitted.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ChartOfAccount
    {
        $accountType = AccountType::from((string) $data['account_type']);
        $accountPurpose = isset($data['account_purpose']) && $data['account_purpose'] !== ''
            ? AccountPurpose::from((string) $data['account_purpose'])
            : AccountPurpose::GENERAL;

        $this->assertPurposeCompatibleWithType($accountType, $accountPurpose);

        $manualCode = isset($data['code']) ? trim((string) $data['code']) : '';
        if ($manualCode !== '') {
            return DB::transaction(function () use ($data, $accountType, $accountPurpose, $manualCode): ChartOfAccount {
                if (ChartOfAccount::query()->where('code', $manualCode)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages([
                        'code' => [(string) __('accounting.errors.code_duplicate')],
                    ]);
                }

                return $this->insertAccount($data, $manualCode, $accountType, $accountPurpose);
            });
        }

        // Retry auto-code generation safely if a concurrent writer grabs the candidate first.
        for ($attempt = 1; $attempt <= self::AUTO_CODE_MAX_RETRIES; $attempt++) {
            try {
                return DB::transaction(function () use ($data, $accountType, $accountPurpose): ChartOfAccount {
                    $code = $this->codeGenerator->generateNextCode();

                    return $this->insertAccount($data, $code, $accountType, $accountPurpose);
                });
            } catch (QueryException $e) {
                if (! $this->isDuplicateKeyException($e) || $attempt === self::AUTO_CODE_MAX_RETRIES) {
                    throw $e;
                }
            }
        }

        throw ValidationException::withMessages([
            'code' => [(string) __('accounting.errors.code_duplicate')],
        ]);
    }

    /**
     * Update an existing account while preserving linked settings, contacts, and payment methods integrity.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($account, $data): ChartOfAccount {
            /** @var ChartOfAccount $locked */
            $locked = ChartOfAccount::query()->lockForUpdate()->findOrFail($account->id);

            $targetType = array_key_exists('account_type', $data) && $data['account_type'] !== null
                ? AccountType::from((string) $data['account_type'])
                : $locked->account_type;

            $currentPurpose = $locked->account_purpose ?? AccountPurpose::GENERAL;
            $targetPurpose = array_key_exists('account_purpose', $data) && $data['account_purpose'] !== null && $data['account_purpose'] !== ''
                ? AccountPurpose::from((string) $data['account_purpose'])
                : $currentPurpose;

            $this->assertPurposeCompatibleWithType($targetType, $targetPurpose);

            if ($targetType !== $locked->account_type || $targetPurpose !== $currentPurpose) {
                $this->assertTypeAndPurposeUpdateAllowed($locked, $targetType, $targetPurpose);
            }

            $newCode = $locked->code;
            if (array_key_exists('code', $data) && trim((string) $data['code']) !== '') {
                $candidateCode = trim((string) $data['code']);
                if ($candidateCode !== $locked->code) {
                    $duplicate = ChartOfAccount::query()
                        ->where('code', $candidateCode)
                        ->where('id', '!=', $locked->id)
                        ->lockForUpdate()
                        ->exists();

                    if ($duplicate) {
                        throw ValidationException::withMessages([
                            'code' => [(string) __('accounting.errors.code_duplicate')],
                        ]);
                    }
                    $newCode = $candidateCode;
                }
            }

            $locked->update([
                'code' => $newCode,
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $locked->name,
                'account_type' => $targetType->value,
                'account_purpose' => $targetPurpose->value,
                'description' => array_key_exists('description', $data)
                    ? ($data['description'] !== null && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null)
                    : $locked->description,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Delete an account only if it is not linked to settings, contacts, or payment methods.
     */
    public function delete(ChartOfAccount $account): bool
    {
        return DB::transaction(function () use ($account): bool {
            /** @var ChartOfAccount $locked */
            $locked = ChartOfAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($this->accountingSettingService->isAccountLinkedToAnySetting($locked->id)) {
                throw ValidationException::withMessages([
                    'account' => [(string) __('accounting.errors.delete_linked_to_settings')],
                ]);
            }

            $linkedToContact = Contact::withTrashed()
                ->where('receivable_account_id', $locked->id)
                ->orWhere('payable_account_id', $locked->id)
                ->exists();

            if ($linkedToContact) {
                throw ValidationException::withMessages([
                    'account' => [(string) __('accounting.errors.delete_linked_to_contact')],
                ]);
            }

            if (PaymentMethod::query()->where('chart_of_account_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'account' => [(string) __('accounting.errors.delete_linked_to_payment_method')],
                ]);
            }

            return (bool) $locked->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function insertAccount(
        array $data,
        string $code,
        AccountType $accountType,
        AccountPurpose $accountPurpose
    ): ChartOfAccount {
        return ChartOfAccount::query()->create([
            'code' => $code,
            'name' => trim((string) $data['name']),
            'account_type' => $accountType->value,
            'account_purpose' => $accountPurpose->value,
            'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                ? trim((string) $data['description'])
                : null,
        ]);
    }

    private function assertPurposeCompatibleWithType(AccountType $type, AccountPurpose $purpose): void
    {
        if ($type !== AccountType::ASSET && $purpose !== AccountPurpose::GENERAL) {
            throw ValidationException::withMessages([
                'account_purpose' => [(string) __('accounting.errors.purpose_requires_asset_type')],
            ]);
        }
    }

    private function assertTypeAndPurposeUpdateAllowed(
        ChartOfAccount $locked,
        AccountType $targetType,
        AccountPurpose $targetPurpose
    ): void {
        if ($this->accountingSettingService->isAccountLinkedToAnySetting($locked->id)) {
            if ($targetType !== $locked->account_type) {
                throw ValidationException::withMessages([
                    'account_type' => [(string) __('accounting.errors.setting_account_type_mismatch', [
                        'label' => $locked->name,
                        'type' => $locked->account_type->label(),
                    ])],
                ]);
            }

            if ($targetPurpose !== AccountPurpose::GENERAL) {
                throw ValidationException::withMessages([
                    'account_purpose' => [(string) __('accounting.errors.setting_account_purpose_locked')],
                ]);
            }
        }

        $linkedContact = Contact::withTrashed()
            ->where('receivable_account_id', $locked->id)
            ->orWhere('payable_account_id', $locked->id)
            ->first(['id', 'receivable_account_id', 'payable_account_id']);

        if ($linkedContact) {
            if ($targetType !== $locked->account_type) {
                throw ValidationException::withMessages([
                    'account_type' => [(string) __('accounting.errors.contact_account_type_locked')],
                ]);
            }

            if ($targetPurpose !== AccountPurpose::GENERAL) {
                throw ValidationException::withMessages([
                    'account_purpose' => [(string) __('accounting.errors.contact_account_purpose_locked')],
                ]);
            }
        }

        $linkedMethods = PaymentMethod::query()
            ->where('chart_of_account_id', $locked->id)
            ->get(['id', 'type']);

        foreach ($linkedMethods as $method) {
            if ($targetType !== AccountType::ASSET) {
                throw ValidationException::withMessages([
                    'account_type' => [(string) __('accounting.errors.payment_method_account_type_locked')],
                ]);
            }

            if ($targetPurpose !== $method->type->requiredAccountPurpose()) {
                throw ValidationException::withMessages([
                    'account_purpose' => [(string) __('accounting.errors.payment_method_account_purpose_locked')],
                ]);
            }
        }
    }

    private function isDuplicateKeyException(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = mb_strtolower($e->getMessage());

        return $sqlState === '23000'
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
