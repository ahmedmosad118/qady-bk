---
name: laravel
description: Laravel 13 / PHP 8.3 backend rules and reference architecture for Qady ERP (دار القاضي). Use for controllers, services, calculators, query services, requests, and routes.
---

# Laravel 13 & PHP 8.3 in Qady ERP

## Canonical Reference (Read Before Coding)
Before writing or refactoring any service, inspect the POS reference implementation:
- `app/Support/Decimal.php` — Pure BCMath money/quantity arithmetic (`MONEY_SCALE = 2`, `QUANTITY_SCALE = 3`, `CALCULATION_SCALE = 8`).
- `app/Services/Pos/PosSaleService.php` — Unified domain service (~400 lines max) that orchestrates write transactions and delegates to focused internal services.
- `app/Services/Pos/PosSaleCalculator.php` — Pure domain math with zero DB calls.
- `app/Services/Pos/PosSaleQueryService.php` & `PosProductCatalogService.php` — Read-only query & catalog services.
- `app/Services/Pos/PosShiftService.php` — Single-query `GROUP BY` aggregations (`sumTransactionsByType`).

## Architectural Layers & Size Ceilings
- **Controller (`<= 140 lines`)**: Validate via `FormRequest`, inject ONE unified domain service (e.g. `PosSaleService`), call one method, return `response()->json([...])`.
- **Unified Domain Service (`<= 400 lines`)**: Never write 800–1000+ line God classes. Keep the main service as the single facade for the controller, and split heavy responsibilities into injected collaborators:
  - `*Calculator`: Pure BCMath calculations (no DB).
  - `*QueryService` / `*CatalogService`: Read-only pagination, filtering, and detail loading.
  - `*Resolver`: Focused invariant resolution (e.g. `PosDefaultWarehouseResolver`).
- **FormRequest**: Strict authorization (`Gate::allows(...)`) + validation rules. Batch any DB checks in `authorize()` into a single `whereIn` query.
- **Resources**: `app/Http/Resources` is not used; return consistent JSON envelopes directly.

## Code Quality & Performance Rules
- `declare(strict_types=1);` at the top of every PHP file.
- **Namespaces**: ALWAYS import classes via `use` statements at the top of the file. NEVER use inline fully qualified class names.
- **Enums over Magic Strings**: Never compare display names (`$name === 'نقدي'`) or hardcoded IDs (`!== 1`). Always use backed Enums in `app/Enums/` (e.g. `PaymentMethodType`, `DiscountType`, `InventoryDocumentType`) and `match ($model->type)` or model helper methods (`$paymentMethod->isCash()`).
- **Zero Float Math**: Always use `App\Support\Decimal` for monetary and quantity calculations.
- **Bulk Reads & Bulk Writes**:
  - Load multiple records once via `whereIn('id', $ids)->get()->keyBy('id')`.
  - Insert line items in 1 query via `Model::query()->insert(array_map(...))` — never `create()` in a loop.
- **In-Memory Relation Hydration**:
  - After inserting a record inside a transaction, attach already-loaded relations (`setRelation('shift', $shift)`, etc.) instead of re-querying them via `$model->load(...)`.
- **Single Grouped Aggregations**:
  - Use `selectRaw('type, COALESCE(SUM(amount), 0) as total')->groupBy('type')->pluck('total', 'type')` instead of multiple `->where('type', ...)->sum('amount')` queries.

## Checklist Before Finishing
- [ ] No service exceeds ~400 lines; math and read queries are cleanly separated
- [ ] Zero magic display strings or hardcoded IDs; backed Enums used everywhere
- [ ] Zero `create()` or `find()` inside loops; bulk `whereIn` and bulk `insert()` used
- [ ] All money/quantity math uses `App\Support\Decimal` (BCMath)
- [ ] `php artisan test` passes
