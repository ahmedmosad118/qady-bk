<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Enums\ExpenseStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseItemAttachment;
use App\Models\Journal;
use App\Models\User;
use App\Repositories\ExpenseRepository;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        protected ExpenseRepository $repository,
        protected ExpenseNumberService $numberService,
        protected ExpenseAttachmentService $attachmentService,
        protected ExpenseCalculator $calculator,
    ) {}

    /**
     * Validate items and calculate subtotal and total authoritatively on the backend.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     subtotal: string,
     *     total: string
     * }
     */
    public function calculateTotals(array $rawItems): array
    {
        return $this->calculator->calculateTotals($rawItems);
    }

    /**
     * Create a new Draft expense with its items and optional item attachments atomically.
     *
     * @param  array<string, mixed>  $data
     */
    public function createExpense(array $data, User $user): Expense
    {
        $trackedFiles = [];

        try {
            return DB::transaction(function () use ($data, $user, &$trackedFiles): Expense {
                $contactId = $this->resolveOptionalActiveSupplierId($data['contact_id'] ?? null);
                $journal = $this->resolveActiveExpenseJournal((int) ($data['journal_id'] ?? 0));
                $expenseDate = Carbon::parse((string) $data['expense_date']);

                $calc = $this->calculateTotals($data['items'] ?? []);
                $this->resolveExpenseAccounts($calc['items']);
                $this->resolveExpenseCategories($calc['items']);

                /** @var Expense $expense */
                $expense = Expense::query()->create([
                    'contact_id' => $contactId,
                    'journal_id' => $journal->id,
                    'expense_date' => $expenseDate->toDateString(),
                    'subtotal' => $calc['subtotal'],
                    'total' => $calc['total'],
                    'status' => ExpenseStatus::DRAFT,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                        ? trim((string) $data['notes'])
                        : null,
                    'created_by' => $user->id,
                ]);

                $now = Carbon::now();
                $plainItemRows = [];

                foreach ($calc['items'] as $itemRow) {
                    $referenceNumber = isset($itemRow['reference_number']) && trim((string) $itemRow['reference_number']) !== ''
                        ? trim((string) $itemRow['reference_number'])
                        : null;
                    $description = isset($itemRow['description']) && trim((string) $itemRow['description']) !== ''
                        ? trim((string) $itemRow['description'])
                        : null;

                    if (empty($itemRow['attachments'])) {
                        $plainItemRows[] = [
                            'expense_id' => $expense->id,
                            'account_id' => (int) $itemRow['account_id'],
                            'expense_category_id' => (int) $itemRow['expense_category_id'],
                            'reference_number' => $referenceNumber,
                            'description' => $description,
                            'amount' => $itemRow['amount'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        continue;
                    }

                    /** @var ExpenseItem $createdItem */
                    $createdItem = $expense->items()->create([
                        'account_id' => (int) $itemRow['account_id'],
                        'expense_category_id' => (int) $itemRow['expense_category_id'],
                        'reference_number' => $referenceNumber,
                        'description' => $description,
                        'amount' => $itemRow['amount'],
                    ]);

                    $this->attachmentService->storeFiles(
                        $expense,
                        $createdItem,
                        $itemRow['attachments'],
                        $user,
                        $trackedFiles
                    );
                }

                if ($plainItemRows !== []) {
                    ExpenseItem::query()->insert($plainItemRows);
                }

                return $this->repository->findWithRelations($expense->id);
            });
        } catch (\Throwable $e) {
            $this->attachmentService->deleteStoredFiles($trackedFiles);
            throw $e;
        }
    }

    /**
     * Update a Draft expense, synchronizing items and attachments without losing existing files.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateExpense(Expense $expense, array $data, User $user): Expense
    {
        $trackedFiles = [];

        try {
            return DB::transaction(function () use ($expense, $data, $user, &$trackedFiles): Expense {
                /** @var Expense $lockedExpense */
                $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

                if (! $lockedExpense->isDraft()) {
                    throw ValidationException::withMessages([
                        'status' => [__('expenses.errors.cannot_modify_posted_expense')],
                    ]);
                }

                $contactId = $this->resolveOptionalActiveSupplierId($data['contact_id'] ?? null);
                $journal = $this->resolveActiveExpenseJournal((int) ($data['journal_id'] ?? 0));
                $expenseDate = Carbon::parse((string) $data['expense_date']);

                $calc = $this->calculateTotals($data['items'] ?? []);
                $this->resolveExpenseAccounts($calc['items']);
                $this->resolveExpenseCategories($calc['items']);

                /** @var Collection<int, ExpenseItem> $existingItems */
                $existingItems = $lockedExpense->items()
                    ->with('attachments')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $allExpenseAttachments = $existingItems->flatMap(fn (ExpenseItem $item): Collection => $item->attachments)->keyBy('id');

                $topLevelRemovedIds = is_array($data['removed_attachment_ids'] ?? null)
                    ? array_values(array_unique(array_map('intval', $data['removed_attachment_ids'])))
                    : [];

                foreach ($topLevelRemovedIds as $attId) {
                    if (! $allExpenseAttachments->has($attId)) {
                        throw ValidationException::withMessages([
                            'removed_attachment_ids' => [__('expenses.errors.invalid_attachment_id')],
                        ]);
                    }
                }

                $keptItemIds = [];
                foreach ($calc['items'] as $idx => $itemRow) {
                    $itemId = isset($itemRow['id']) && $itemRow['id'] !== '' ? (int) $itemRow['id'] : null;
                    $removeAttachmentIds = is_array($itemRow['remove_attachment_ids'] ?? null)
                        ? array_values(array_unique(array_map('intval', $itemRow['remove_attachment_ids'])))
                        : [];

                    if ($itemId !== null) {
                        if (! $existingItems->has($itemId)) {
                            throw ValidationException::withMessages([
                                "items.{$idx}.id" => [__('expenses.errors.invalid_item_id')],
                            ]);
                        }

                        if (in_array($itemId, $keptItemIds, true)) {
                            throw ValidationException::withMessages([
                                "items.{$idx}.id" => [__('expenses.errors.duplicate_item_id')],
                            ]);
                        }

                        $keptItemIds[] = $itemId;
                        $existingItemAttachments = $existingItems->get($itemId)->attachments->keyBy('id');

                        foreach ($removeAttachmentIds as $attId) {
                            if (! $existingItemAttachments->has($attId)) {
                                throw ValidationException::withMessages([
                                    "items.{$idx}.remove_attachment_ids" => [__('expenses.errors.invalid_attachment_id')],
                                ]);
                            }
                        }
                    } elseif ($removeAttachmentIds !== []) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.remove_attachment_ids" => [__('expenses.errors.invalid_attachment_id')],
                        ]);
                    }
                }

                $lockedExpense->update([
                    'contact_id' => $contactId,
                    'journal_id' => $journal->id,
                    'expense_date' => $expenseDate->toDateString(),
                    'subtotal' => $calc['subtotal'],
                    'total' => $calc['total'],
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                        ? trim((string) $data['notes'])
                        : null,
                ]);

                /** @var Collection<int, ExpenseItemAttachment> $attachmentsToDelete */
                $attachmentsToDelete = collect();

                $itemsToRemove = $existingItems->except($keptItemIds);
                if ($itemsToRemove->isNotEmpty()) {
                    foreach ($itemsToRemove as $removedItem) {
                        foreach ($removedItem->attachments as $att) {
                            $attachmentsToDelete->push($att);
                        }
                    }
                    $removedItemIds = $itemsToRemove->pluck('id')->all();
                    ExpenseItemAttachment::query()->whereIn('expense_item_id', $removedItemIds)->delete();
                    ExpenseItem::query()->whereIn('id', $removedItemIds)->delete();
                }

                foreach ($calc['items'] as $itemRow) {
                    $itemId = isset($itemRow['id']) && $itemRow['id'] !== '' ? (int) $itemRow['id'] : null;
                    $referenceNumber = isset($itemRow['reference_number']) && trim((string) $itemRow['reference_number']) !== ''
                        ? trim((string) $itemRow['reference_number'])
                        : null;
                    $description = isset($itemRow['description']) && trim((string) $itemRow['description']) !== ''
                        ? trim((string) $itemRow['description'])
                        : null;
                    $removeAttachmentIds = is_array($itemRow['remove_attachment_ids'] ?? null)
                        ? array_values(array_unique(array_map('intval', $itemRow['remove_attachment_ids'])))
                        : [];

                    if ($itemId !== null) {
                        /** @var ExpenseItem $existingItem */
                        $existingItem = $existingItems->get($itemId);
                        $existingItem->update([
                            'account_id' => (int) $itemRow['account_id'],
                            'expense_category_id' => (int) $itemRow['expense_category_id'],
                            'reference_number' => $referenceNumber,
                            'description' => $description,
                            'amount' => $itemRow['amount'],
                        ]);

                        $removeIds = array_values(array_unique(array_merge(
                            $removeAttachmentIds,
                            $existingItem->attachments
                                ->whereIn('id', $topLevelRemovedIds)
                                ->pluck('id')
                                ->all()
                        )));

                        if ($removeIds !== []) {
                            $removing = $existingItem->attachments->whereIn('id', $removeIds);
                            foreach ($removing as $att) {
                                $attachmentsToDelete->push($att);
                            }
                            $existingItem->attachments()->whereIn('id', $removeIds)->delete();
                        }

                        if (! empty($itemRow['attachments'])) {
                            $this->attachmentService->storeFiles(
                                $lockedExpense,
                                $existingItem,
                                $itemRow['attachments'],
                                $user,
                                $trackedFiles
                            );
                        }
                    } else {
                        /** @var ExpenseItem $createdItem */
                        $createdItem = $lockedExpense->items()->create([
                            'account_id' => (int) $itemRow['account_id'],
                            'expense_category_id' => (int) $itemRow['expense_category_id'],
                            'reference_number' => $referenceNumber,
                            'description' => $description,
                            'amount' => $itemRow['amount'],
                        ]);

                        if (! empty($itemRow['attachments'])) {
                            $this->attachmentService->storeFiles(
                                $lockedExpense,
                                $createdItem,
                                $itemRow['attachments'],
                                $user,
                                $trackedFiles
                            );
                        }
                    }
                }

                $this->attachmentService->schedulePhysicalDeletion($attachmentsToDelete);

                return $this->repository->findWithRelations($lockedExpense->id);
            });
        } catch (\Throwable $e) {
            $this->attachmentService->deleteStoredFiles($trackedFiles);
            throw $e;
        }
    }

    /**
     * Delete a Draft expense along with all its items and physical attachment files.
     */
    public function deleteExpense(Expense $expense): bool
    {
        return DB::transaction(function () use ($expense): bool {
            /** @var Expense $lockedExpense */
            $lockedExpense = Expense::query()
                ->with('items.attachments')
                ->lockForUpdate()
                ->findOrFail($expense->id);

            if (! $lockedExpense->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => [__('expenses.errors.cannot_delete_posted_expense')],
                ]);
            }

            $attachments = $lockedExpense->items->flatMap(fn (ExpenseItem $item): Collection => $item->attachments);
            $this->attachmentService->schedulePhysicalDeletion($attachments);

            $itemIds = $lockedExpense->items->pluck('id')->all();
            if ($itemIds !== []) {
                ExpenseItemAttachment::query()->whereIn('expense_item_id', $itemIds)->delete();
                ExpenseItem::query()->whereIn('id', $itemIds)->delete();
            }

            return (bool) $lockedExpense->delete();
        });
    }

    /**
     * Upload additional attachments directly to an item of a Draft expense.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, ExpenseItemAttachment>
     */
    public function uploadItemAttachments(Expense $expense, ExpenseItem $item, array $files, User $user): array
    {
        $trackedFiles = [];

        try {
            return DB::transaction(function () use ($expense, $item, $files, $user, &$trackedFiles): array {
                /** @var Expense $lockedExpense */
                $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

                if (! $lockedExpense->isDraft()) {
                    throw ValidationException::withMessages([
                        'status' => [__('expenses.errors.cannot_modify_posted_expense')],
                    ]);
                }

                if ((int) $item->expense_id !== (int) $lockedExpense->id) {
                    throw ValidationException::withMessages([
                        'item' => [__('expenses.errors.invalid_item_id')],
                    ]);
                }

                return $this->attachmentService->storeFiles(
                    $lockedExpense,
                    $item,
                    $files,
                    $user,
                    $trackedFiles
                );
            });
        } catch (\Throwable $e) {
            $this->attachmentService->deleteStoredFiles($trackedFiles);
            throw $e;
        }
    }

    /**
     * Delete a single attachment from a Draft expense item and remove its physical file after commit.
     */
    public function deleteItemAttachment(Expense $expense, ExpenseItem $item, ExpenseItemAttachment $attachment): bool
    {
        return DB::transaction(function () use ($expense, $item, $attachment): bool {
            /** @var Expense $lockedExpense */
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if (! $lockedExpense->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => [__('expenses.errors.cannot_modify_posted_expense')],
                ]);
            }

            if ((int) $item->expense_id !== (int) $lockedExpense->id) {
                throw ValidationException::withMessages([
                    'item' => [__('expenses.errors.invalid_item_id')],
                ]);
            }

            if ((int) $attachment->expense_item_id !== (int) $item->id) {
                throw ValidationException::withMessages([
                    'attachment' => [__('expenses.errors.invalid_attachment_id')],
                ]);
            }

            $this->attachmentService->schedulePhysicalDeletion([$attachment]);

            return (bool) $attachment->delete();
        });
    }

    protected function resolveOptionalActiveSupplierId(mixed $rawContactId): ?int
    {
        if ($rawContactId === null || $rawContactId === '') {
            return null;
        }

        $contactId = (int) $rawContactId;
        $exists = Contact::query()
            ->whereKey($contactId)
            ->where('is_active', true)
            ->where('is_supplier', true)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'contact_id' => ['Selected contact must be an active supplier.'],
            ]);
        }

        return $contactId;
    }

    protected function resolveActiveExpenseJournal(int $journalId): Journal
    {
        /** @var Journal|null $journal */
        $journal = Journal::query()
            ->whereKey($journalId)
            ->where('is_active', true)
            ->where('type', JournalType::Expense->value)
            ->first();

        if (! $journal) {
            throw ValidationException::withMessages([
                'journal_id' => ['Selected journal must be an active expense journal.'],
            ]);
        }

        return $journal;
    }

    /**
     * Validate all item account IDs in bulk (active + AccountTypeCode::Expenses).
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function resolveExpenseAccounts(array $items): void
    {
        $accountIds = collect($items)
            ->pluck('account_id')
            ->unique()
            ->values()
            ->all();

        $accounts = Account::query()
            ->with('accountType:id,code')
            ->whereIn('id', $accountIds)
            ->get()
            ->keyBy('id');

        foreach ($items as $idx => $itemRow) {
            /** @var Account|null $account */
            $account = $accounts->get($itemRow['account_id']);

            if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::Expenses->value) {
                throw ValidationException::withMessages([
                    "items.{$idx}.account_id" => ['Selected account must be an active expense account.'],
                ]);
            }
        }
    }

    /**
     * Validate all item category IDs in bulk.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function resolveExpenseCategories(array $items): void
    {
        $categoryIds = collect($items)
            ->pluck('expense_category_id')
            ->unique()
            ->values()
            ->all();

        $existingIds = ExpenseCategory::query()
            ->whereIn('id', $categoryIds)
            ->pluck('id')
            ->all();

        foreach ($items as $idx => $itemRow) {
            if (! in_array($itemRow['expense_category_id'], $existingIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$idx}.expense_category_id" => ['Selected expense category does not exist.'],
                ]);
            }
        }
    }
}
