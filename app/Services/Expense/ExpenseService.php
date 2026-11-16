<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Enums\ExpenseStatus;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\ExpenseItemAttachment;
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
    ) {}

    /**
     * Validate items and calculate subtotal and total authoritatively on the backend.
     *
     * @param array<int, array<string, mixed>> $rawItems
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     subtotal: string,
     *     total: string
     * }
     */
    public function calculateTotals(array $rawItems): array
    {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => [__('expenses.errors.at_least_one_item')],
            ]);
        }

        $processedItems = [];
        $subtotal = '0.00';

        foreach ($rawItems as $index => $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages([
                    "items.{$index}.name" => ['Item name is required.'],
                ]);
            }

            $rawAmount = $item['amount'] ?? null;
            if ($rawAmount === null || $rawAmount === '' || ! is_numeric($rawAmount)) {
                throw ValidationException::withMessages([
                    "items.{$index}.amount" => [__('expenses.errors.item_amount_positive')],
                ]);
            }

            $amountStr = number_format((float) $rawAmount, 2, '.', '');
            if (bccomp($amountStr, '0.00', 2) <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.amount" => [__('expenses.errors.item_amount_positive')],
                ]);
            }

            $description = isset($item['description']) && trim((string) $item['description']) !== ''
                ? trim((string) $item['description'])
                : null;

            $subtotal = bcadd($subtotal, $amountStr, 2);

            $processedItems[] = [
                'id' => isset($item['id']) && $item['id'] !== '' ? (int) $item['id'] : null,
                'name' => $name,
                'description' => $description,
                'amount' => $amountStr,
                'attachments' => is_array($item['attachments'] ?? null) ? $item['attachments'] : [],
                'remove_attachment_ids' => is_array($item['remove_attachment_ids'] ?? null)
                    ? array_values(array_unique(array_map('intval', $item['remove_attachment_ids'])))
                    : [],
            ];
        }

        if (bccomp($subtotal, '0.00', 2) <= 0) {
            throw ValidationException::withMessages([
                'total' => [__('expenses.errors.total_positive')],
            ]);
        }

        return [
            'items' => $processedItems,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];
    }

    /**
     * Create a new Draft expense with its items and optional item attachments atomically.
     *
     * @param array<string, mixed> $data
     */
    public function createExpense(array $data, User $user): Expense
    {
        $trackedFiles = [];

        try {
            return DB::transaction(function () use ($data, $user, &$trackedFiles): Expense {
                $category = $this->resolveCategory((int) ($data['expense_category_id'] ?? 0));
                $contactId = $this->resolveOptionalContactId($data['contact_id'] ?? null);
                $expenseDate = Carbon::parse((string) $data['expense_date']);

                $calc = $this->calculateTotals($data['items'] ?? []);
                $expenseNumber = $this->numberService->generateExpenseNumber($expenseDate);

                /** @var Expense $expense */
                $expense = Expense::query()->create([
                    'expense_number' => $expenseNumber,
                    'expense_category_id' => $category->id,
                    'contact_id' => $contactId,
                    'expense_date' => $expenseDate->toDateString(),
                    'subtotal' => $calc['subtotal'],
                    'total' => $calc['total'],
                    'status' => ExpenseStatus::DRAFT,
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                        ? trim((string) $data['notes'])
                        : null,
                    'created_by' => $user->id,
                ]);

                foreach ($calc['items'] as $itemRow) {
                    /** @var ExpenseItem $createdItem */
                    $createdItem = $expense->items()->create([
                        'name' => $itemRow['name'],
                        'description' => $itemRow['description'],
                        'amount' => $itemRow['amount'],
                    ]);

                    if (! empty($itemRow['attachments'])) {
                        $this->attachmentService->storeFiles(
                            $expense,
                            $createdItem,
                            $itemRow['attachments'],
                            $user,
                            $trackedFiles
                        );
                    }
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
     * @param array<string, mixed> $data
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

                $category = $this->resolveCategory((int) ($data['expense_category_id'] ?? 0));
                $contactId = $this->resolveOptionalContactId($data['contact_id'] ?? null);
                $expenseDate = Carbon::parse((string) $data['expense_date']);

                $calc = $this->calculateTotals($data['items'] ?? []);

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

                // Validate submitted item IDs and per-item remove_attachment_ids before mutating anything.
                $keptItemIds = [];
                foreach ($calc['items'] as $idx => $itemRow) {
                    $itemId = $itemRow['id'];

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

                        foreach ($itemRow['remove_attachment_ids'] as $attId) {
                            if (! $existingItemAttachments->has($attId)) {
                                throw ValidationException::withMessages([
                                    "items.{$idx}.remove_attachment_ids" => [__('expenses.errors.invalid_attachment_id')],
                                ]);
                            }
                        }
                    } elseif (! empty($itemRow['remove_attachment_ids'])) {
                        throw ValidationException::withMessages([
                            "items.{$idx}.remove_attachment_ids" => [__('expenses.errors.invalid_attachment_id')],
                        ]);
                    }
                }

                // Update header (expense_number and status remain immutable here).
                $lockedExpense->update([
                    'expense_category_id' => $category->id,
                    'contact_id' => $contactId,
                    'expense_date' => $expenseDate->toDateString(),
                    'subtotal' => $calc['subtotal'],
                    'total' => $calc['total'],
                    'notes' => isset($data['notes']) && trim((string) $data['notes']) !== ''
                        ? trim((string) $data['notes'])
                        : null,
                ]);

                /** @var Collection<int, ExpenseItemAttachment> $attachmentsToDelete */
                $attachmentsToDelete = collect();

                // Delete items that were removed from the form, along with their attachments.
                $itemsToRemove = $existingItems->except($keptItemIds);
                foreach ($itemsToRemove as $removedItem) {
                    foreach ($removedItem->attachments as $att) {
                        $attachmentsToDelete->push($att);
                    }
                    $removedItem->attachments()->delete();
                    $removedItem->delete();
                }

                // Synchronize kept items and create new items.
                foreach ($calc['items'] as $itemRow) {
                    if ($itemRow['id'] !== null) {
                        /** @var ExpenseItem $existingItem */
                        $existingItem = $existingItems->get($itemRow['id']);
                        $existingItem->update([
                            'name' => $itemRow['name'],
                            'description' => $itemRow['description'],
                            'amount' => $itemRow['amount'],
                        ]);

                        $removeIds = array_values(array_unique(array_merge(
                            $itemRow['remove_attachment_ids'],
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
                            'name' => $itemRow['name'],
                            'description' => $itemRow['description'],
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

            foreach ($lockedExpense->items as $item) {
                $item->attachments()->delete();
            }
            $lockedExpense->items()->delete();

            return (bool) $lockedExpense->delete();
        });
    }

    /**
     * Upload additional attachments directly to an item of a Draft expense.
     *
     * @param array<int, UploadedFile> $files
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

    protected function resolveCategory(int $categoryId): ExpenseCategory
    {
        /** @var ExpenseCategory|null $category */
        $category = ExpenseCategory::query()->find($categoryId);

        if (! $category) {
            throw ValidationException::withMessages([
                'expense_category_id' => ['Selected expense category does not exist.'],
            ]);
        }

        return $category;
    }

    protected function resolveOptionalContactId(mixed $rawContactId): ?int
    {
        if ($rawContactId === null || $rawContactId === '') {
            return null;
        }

        $contactId = (int) $rawContactId;
        $exists = Contact::query()->whereKey($contactId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'contact_id' => ['Selected contact does not exist.'],
            ]);
        }

        return $contactId;
    }
}
