<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Accounting\AccountTypeCode;
use App\Models\Account;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCategoryService
{
    /**
     * Get the 2-level taxonomy tree.
     */
    public function getTree(): Collection
    {
        return ProductCategory::query()
            ->whereNull('parent_id')
            ->with([
                'stockInputAccount:id,code,name,is_active',
                'children' => fn ($q) => $q
                    ->with('stockInputAccount:id,code,name,is_active')
                    ->orderBy('name')
                    ->withCount(['products' => fn ($pq) => $pq->active()]),
            ])
            ->withCount(['products' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a category.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ProductCategory
    {
        return DB::transaction(function () use ($data): ProductCategory {
            $account = $this->resolveStockInputAccount($data['stock_input_account_id'] ?? null);
            $category = ProductCategory::create($data);

            if ($account !== null) {
                $category->setRelation('stockInputAccount', $account);
            }

            return $category;
        });
    }

    /**
     * Update a category.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(ProductCategory $category, array $data): ProductCategory
    {
        return DB::transaction(function () use ($category, $data): ProductCategory {
            $account = array_key_exists('stock_input_account_id', $data)
                ? $this->resolveStockInputAccount($data['stock_input_account_id'])
                : null;

            $category->update($data);

            $fresh = $category->fresh();
            if ($account !== null) {
                $fresh?->setRelation('stockInputAccount', $account);
            } elseif ($fresh && $fresh->stock_input_account_id !== null) {
                $fresh->load('stockInputAccount:id,code,name,is_active');
            }

            return $fresh ?? $category;
        });
    }

    /**
     * Ensure the given stock input account exists, is active, and has type current_assets.
     */
    private function resolveStockInputAccount(mixed $accountId): ?Account
    {
        if ($accountId === null || $accountId === '') {
            return null;
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find((int) $accountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'stock_input_account_id' => [(string) __('products.stock_input_account_not_found')],
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'stock_input_account_id' => [(string) __('products.stock_input_account_inactive')],
            ]);
        }

        if ($account->accountType?->code !== AccountTypeCode::CurrentAssets->value) {
            throw ValidationException::withMessages([
                'stock_input_account_id' => [(string) __('products.stock_input_account_invalid_type')],
            ]);
        }

        return $account;
    }

    /**
     * Delete categories by IDs, refusing any category that still has subcategories.
     *
     * @param  array<int>  $ids
     */
    public function delete(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $withChildren = ProductCategory::whereIn('id', $ids)->whereHas('children')->exists();

            if ($withChildren) {
                throw ValidationException::withMessages([
                    'category' => [__('products.delete_has_children')],
                ]);
            }

            return ProductCategory::whereIn('id', $ids)->delete();
        });
    }
}
