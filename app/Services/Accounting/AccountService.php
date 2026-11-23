<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountTypeCode;
use App\Models\Account;
use App\Models\AccountClass;
use App\Models\AccountType;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Repositories\AccountRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function __construct(
        protected AccountRepository $repository,
    ) {}

    /**
     * @return Collection<int, AccountClass>
     */
    public function getTree(): Collection
    {
        return $this->repository->getTree();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(Account $account): array
    {
        return $this->repository->getDetails($account);
    }

    /**
     * Create a new account under a valid AccountType.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data): Account {
            $code = trim((string) $data['code']);

            if (Account::query()->where('code', $code)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'code' => [(string) __('accounting.errors.code_duplicate')],
                ]);
            }

            $accountType = AccountType::query()
                ->with('accountClass:id,code,name')
                ->findOrFail((int) $data['account_type_id']);

            if (! $accountType->is_active) {
                throw ValidationException::withMessages([
                    'account_type_id' => [(string) __('accounting.errors.account_type_inactive')],
                ]);
            }

            $account = Account::query()->create([
                'account_type_id' => $accountType->id,
                'code' => $code,
                'name' => trim((string) $data['name']),
                'normal_balance_override' => $data['normal_balance_override'] ?? null,
                'reconcile' => (bool) ($data['reconcile'] ?? false),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'is_system' => false,
                'system_key' => null,
            ]);

            $account->setRelation('accountType', $accountType);

            return $account;
        });
    }

    /**
     * Update an existing account while protecting system properties and linked entities.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        return DB::transaction(function () use ($account, $data): Account {
            /** @var Account $locked */
            $locked = Account::query()->lockForUpdate()->findOrFail($account->id);

            $targetTypeId = array_key_exists('account_type_id', $data)
                ? (int) $data['account_type_id']
                : $locked->account_type_id;

            $targetCode = array_key_exists('code', $data)
                ? trim((string) $data['code'])
                : $locked->code;

            $targetIsActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $locked->is_active;

            if ($locked->is_system) {
                if ($targetTypeId !== $locked->account_type_id) {
                    throw ValidationException::withMessages([
                        'account_type_id' => [(string) __('accounting.errors.system_account_type_locked')],
                    ]);
                }

                if ($targetCode !== $locked->code) {
                    throw ValidationException::withMessages([
                        'code' => [(string) __('accounting.errors.system_account_code_locked')],
                    ]);
                }

                if (! $targetIsActive) {
                    throw ValidationException::withMessages([
                        'is_active' => [(string) __('accounting.errors.system_account_cannot_deactivate')],
                    ]);
                }
            }

            if ($targetCode !== $locked->code) {
                $duplicate = Account::query()
                    ->where('code', $targetCode)
                    ->where('id', '!=', $locked->id)
                    ->lockForUpdate()
                    ->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages([
                        'code' => [(string) __('accounting.errors.code_duplicate')],
                    ]);
                }
            }

            $accountType = AccountType::query()
                ->with('accountClass:id,code,name')
                ->findOrFail($targetTypeId);

            if ($targetTypeId !== $locked->account_type_id) {
                if (! $accountType->is_active) {
                    throw ValidationException::withMessages([
                        'account_type_id' => [(string) __('accounting.errors.account_type_inactive')],
                    ]);
                }

                $linkedToPaymentMethod = PaymentMethod::query()
                    ->where('account_id', $locked->id)
                    ->exists();

                if ($linkedToPaymentMethod && $accountType->code !== AccountTypeCode::BankCash->value) {
                    throw ValidationException::withMessages([
                        'account_type_id' => [(string) __('accounting.errors.payment_method_account_must_be_bank_cash')],
                    ]);
                }
            }

            $locked->update([
                'account_type_id' => $accountType->id,
                'code' => $targetCode,
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $locked->name,
                'normal_balance_override' => array_key_exists('normal_balance_override', $data)
                    ? $data['normal_balance_override']
                    : $locked->normal_balance_override,
                'reconcile' => array_key_exists('reconcile', $data)
                    ? (bool) $data['reconcile']
                    : (bool) $locked->reconcile,
                'is_active' => $targetIsActive,
            ]);

            $locked->setRelation('accountType', $accountType);

            return $locked;
        });
    }

    /**
     * Delete an unused non-system account.
     */
    public function delete(Account $account): bool
    {
        return DB::transaction(function () use ($account): bool {
            /** @var Account $locked */
            $locked = Account::query()->lockForUpdate()->findOrFail($account->id);

            if ($locked->is_system) {
                throw ValidationException::withMessages([
                    'account' => [(string) __('accounting.errors.system_account_cannot_delete')],
                ]);
            }

            if (PaymentMethod::query()->where('account_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'account' => [(string) __('accounting.errors.delete_linked_to_payment_method')],
                ]);
            }

            $linkedToContact = Contact::query()
                ->where('receivable_account_id', $locked->id)
                ->orWhere('payable_account_id', $locked->id)
                ->exists();

            if ($linkedToContact) {
                throw ValidationException::withMessages([
                    'account' => [(string) __('accounting.errors.delete_linked_to_contact')],
                ]);
            }

            return (bool) $locked->delete();
        });
    }
}
