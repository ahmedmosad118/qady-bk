<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Repositories\ChartOfAccountRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Unified Chart of Accounts domain service facade.
 */
class ChartOfAccountService
{
    public function __construct(
        protected ChartOfAccountRepository $repository,
        protected AccountCodeGenerator $codeGenerator,
        protected AccountingSettingService $accountingSettingService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getTree(array $filters = []): array
    {
        return $this->repository->getTree($filters);
    }

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
     * Create a root or child account, validating tree integrity and generating code if omitted.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($data): ChartOfAccount {
            $parentId = isset($data['parent_id']) && $data['parent_id'] !== null && $data['parent_id'] !== ''
                ? (int) $data['parent_id']
                : null;

            $accountType = isset($data['account_type']) && $data['account_type'] !== ''
                ? (string) $data['account_type']
                : null;

            $parent = null;
            if ($parentId !== null) {
                /** @var ChartOfAccount|null $parent */
                $parent = ChartOfAccount::query()->lockForUpdate()->find($parentId);

                if (! $parent) {
                    throw ValidationException::withMessages([
                        'parent_id' => [(string) __('accounting.errors.parent_not_found')],
                    ]);
                }

                $this->assertParentCanAcceptChildren($parent);

                if ($accountType !== null && $accountType !== $parent->account_type->value) {
                    throw ValidationException::withMessages([
                        'account_type' => [(string) __('accounting.errors.parent_type_mismatch', [
                            'type' => $parent->account_type->label(),
                        ])],
                    ]);
                }

                $accountType = $parent->account_type->value;
            }

            $manualCode = isset($data['code']) ? trim((string) $data['code']) : '';
            if ($manualCode !== '') {
                if (ChartOfAccount::query()->where('code', $manualCode)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages([
                        'code' => [(string) __('accounting.errors.code_duplicate')],
                    ]);
                }
                $code = $manualCode;
            } else {
                $code = $this->codeGenerator->generateNextCode($parentId);
            }

            $account = ChartOfAccount::query()->create([
                'parent_id' => $parentId,
                'code' => $code,
                'name' => trim((string) $data['name']),
                'account_type' => $accountType,
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
            ]);

            $account->setRelation('parent', $parent);
            $account->setAttribute('children_count', 0);

            return $account;
        });
    }

    /**
     * Update an account while preserving tree consistency, hierarchy type matching,
     * and code uniqueness (including proportional descendant code updates).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ChartOfAccount $account, array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($account, $data): ChartOfAccount {
            /** @var ChartOfAccount $locked */
            $locked = ChartOfAccount::query()
                ->withCount('children')
                ->lockForUpdate()
                ->findOrFail($account->id);

            $parentChanged = array_key_exists('parent_id', $data)
                && $this->normalizeParentId($data['parent_id']) !== $locked->parent_id;

            $targetParentId = array_key_exists('parent_id', $data)
                ? $this->normalizeParentId($data['parent_id'])
                : $locked->parent_id;

            $targetTypeValue = array_key_exists('account_type', $data) && $data['account_type'] !== null
                ? (string) $data['account_type']
                : $locked->account_type->value;

            $targetParent = null;
            if ($targetParentId !== null) {
                if ($targetParentId === $locked->id) {
                    throw ValidationException::withMessages([
                        'parent_id' => [(string) __('accounting.errors.self_parent')],
                    ]);
                }

                /** @var ChartOfAccount|null $targetParent */
                $targetParent = ChartOfAccount::query()->lockForUpdate()->find($targetParentId);
                if (! $targetParent) {
                    throw ValidationException::withMessages([
                        'parent_id' => [(string) __('accounting.errors.parent_not_found')],
                    ]);
                }

                if ($parentChanged) {
                    $this->assertNoCircularReference($locked->id, $targetParent);
                    $this->assertParentCanAcceptChildren($targetParent);
                }

                if ($targetTypeValue !== $targetParent->account_type->value) {
                    throw ValidationException::withMessages([
                        'account_type' => [(string) __('accounting.errors.parent_type_mismatch', [
                            'type' => $targetParent->account_type->label(),
                        ])],
                    ]);
                }
            }

            if ($targetTypeValue !== $locked->account_type->value) {
                if ($locked->isGroup()) {
                    throw ValidationException::withMessages([
                        'account_type' => [(string) __('accounting.errors.children_type_mismatch')],
                    ]);
                }

                if ($this->isAccountUsedInDetailOnlyPosition($locked->id) || $this->accountingSettingService->isAccountLinkedToAnySetting($locked->id)) {
                    throw ValidationException::withMessages([
                        'account_type' => [(string) __('accounting.errors.setting_account_type_mismatch', [
                            'label' => $locked->name,
                            'type' => $locked->account_type->label(),
                        ])],
                    ]);
                }
            }

            $oldCode = $locked->code;
            $hasExplicitCode = array_key_exists('code', $data) && trim((string) $data['code']) !== '';
            if ($hasExplicitCode) {
                $newCode = trim((string) $data['code']);
                if ($newCode !== $oldCode) {
                    $duplicate = ChartOfAccount::query()
                        ->where('code', $newCode)
                        ->where('id', '!=', $locked->id)
                        ->lockForUpdate()
                        ->exists();

                    if ($duplicate) {
                        throw ValidationException::withMessages([
                            'code' => [(string) __('accounting.errors.code_duplicate')],
                        ]);
                    }
                }
            } elseif ($parentChanged) {
                $newCode = $this->codeGenerator->generateNextCode($targetParentId);
            } else {
                $newCode = $oldCode;
            }

            if ($newCode !== $oldCode) {
                $this->codeGenerator->cascadeDescendantCodes($locked, $oldCode, $newCode);
            }

            $locked->update([
                'parent_id' => $targetParentId,
                'code' => $newCode,
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $locked->name,
                'account_type' => AccountType::from($targetTypeValue)->value,
                'description' => array_key_exists('description', $data)
                    ? ($data['description'] !== null && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null)
                    : $locked->description,
            ]);

            return $locked->fresh(['parent:id,code,name'])->loadCount('children');
        });
    }

    /**
     * Delete an account only if it has no children, no settings links, and no contact links.
     */
    public function delete(ChartOfAccount $account): bool
    {
        return DB::transaction(function () use ($account): bool {
            /** @var ChartOfAccount $locked */
            $locked = ChartOfAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($locked->children()->exists()) {
                throw ValidationException::withMessages([
                    'account' => [(string) __('accounting.errors.delete_has_children')],
                ]);
            }

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

            return (bool) $locked->delete();
        });
    }

    /**
     * Block adding child accounts under any account currently bound to a detail-only position.
     */
    private function assertParentCanAcceptChildren(ChartOfAccount $parent): void
    {
        if ($this->isAccountUsedInDetailOnlyPosition($parent->id)) {
            throw ValidationException::withMessages([
                'parent_id' => [(string) __('accounting.errors.cannot_attach_child_to_used_detail_account')],
            ]);
        }
    }

    private function isAccountUsedInDetailOnlyPosition(int $accountId): bool
    {
        $usedByContact = Contact::withTrashed()
            ->where('receivable_account_id', $accountId)
            ->orWhere('payable_account_id', $accountId)
            ->exists();

        if ($usedByContact) {
            return true;
        }

        return $this->accountingSettingService->isAccountUsedInPostingSetting($accountId);
    }

    /**
     * Ensure $candidateParent is neither $accountId nor any descendant of $accountId.
     */
    private function assertNoCircularReference(int $accountId, ChartOfAccount $candidateParent): void
    {
        $cursorId = $candidateParent->parent_id;
        $visited = [$candidateParent->id => true];
        $parentMap = $cursorId !== null
            ? ChartOfAccount::query()->pluck('parent_id', 'id')->all()
            : [];

        while ($cursorId !== null) {
            if ($cursorId === $accountId) {
                throw ValidationException::withMessages([
                    'parent_id' => [(string) __('accounting.errors.circular_reference')],
                ]);
            }

            if (isset($visited[$cursorId])) {
                break;
            }
            $visited[$cursorId] = true;

            $next = $parentMap[$cursorId] ?? null;
            $cursorId = $next !== null ? (int) $next : null;
        }
    }

    private function normalizeParentId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
