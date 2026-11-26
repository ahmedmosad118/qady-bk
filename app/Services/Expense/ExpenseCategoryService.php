<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Models\ExpenseCategory;
use App\Repositories\ExpenseCategoryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryService
{
    public function __construct(
        protected ExpenseCategoryRepository $repository,
    ) {}

    /**
     * Create a new expense category.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ExpenseCategory
    {
        $category = DB::transaction(fn (): ExpenseCategory => ExpenseCategory::query()->create([
            'name' => trim((string) $data['name']),
            'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                ? trim((string) $data['description'])
                : null,
        ]));

        return $this->repository->findWithCounts($category->id);
    }

    /**
     * Update an existing expense category.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ExpenseCategory $category, array $data): ExpenseCategory
    {
        DB::transaction(function () use ($category, $data): void {
            /** @var ExpenseCategory $locked */
            $locked = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);

            $locked->update([
                'name' => trim((string) $data['name']),
                'description' => isset($data['description']) && trim((string) $data['description']) !== ''
                    ? trim((string) $data['description'])
                    : null,
            ]);
        });

        return $this->repository->findWithCounts($category->id);
    }

    /**
     * Delete an unused expense category.
     */
    public function delete(ExpenseCategory $category): bool
    {
        return DB::transaction(function () use ($category): bool {
            /** @var ExpenseCategory $locked */
            $locked = ExpenseCategory::query()->lockForUpdate()->findOrFail($category->id);

            if ($locked->items()->exists()) {
                throw ValidationException::withMessages([
                    'category' => [__('expenses.errors.cannot_delete_used_category')],
                ]);
            }

            return (bool) $locked->delete();
        });
    }
}
