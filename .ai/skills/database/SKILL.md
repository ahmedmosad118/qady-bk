---
name: database
description: Migration, model, and high-performance query rules for Qady ERP (MySQL & SQLite test runner).
---

# Database & Query Performance in Qady ERP

## Migrations
1. Check `database/migrations/` history before creating a migration.
2. Never edit an already-run migration; add a new migration with a reversible `down()`.
3. Guard schema changes with `Schema::hasColumn` / `Schema::hasTable` where appropriate, and backfill existing rows when adding new `enum` or non-nullable columns (see `2026_11_17_000001_add_type_to_payment_methods_table.php`).
4. Always use `EnumClass::values()` for `$table->enum(...)` columns and add indexes on foreign keys, status/type enums, and date columns used in filters.

## Models
- Use PHP 8 attributes `#[Fillable([...])]` and `casts(): array` method.
- Cast every status/type column to its backed Enum in `app/Enums/` (e.g. `'type' => PaymentMethodType::class`).
- Provide expressive domain helpers on the model (`$paymentMethod->isCash()`, `$shift->isOpen()`).

## High-Performance Queries (Mandatory)
1. **Zero N+1 Queries**:
   - Pre-load collections before loops using `whereIn('id', $ids)->get()->keyBy('id')`.
   - Eager load relations with explicit column lists (`'paymentMethod:id,name,type'`, `'customer:id,name,phone'`).
2. **Bulk Inserts**:
   - Use `Model::query()->insert($rows)` to persist line items in a single SQL statement.
3. **In-Memory Relation Hydration**:
   - Reuse models already loaded during a transaction via `$model->setRelation(...)` instead of re-querying them with `$model->load(...)`.
4. **Single `GROUP BY` Aggregations**:
   - Combine multi-type `SUM()` queries into one `selectRaw('type, COALESCE(SUM(amount), 0) as total')->groupBy('type')->pluck('total', 'type')` query and normalize with `Decimal::fromNumeric()`.
5. **Subqueries over Multi-Query `pluck()`**:
   - Filter parent + child categories in a single query using `->orWhereIn('category_id', ProductCategory::query()->select('id')->where('parent_id', $categoryId))`.
