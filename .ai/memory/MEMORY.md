# QADY ERP (دار القاضي) — Project Memory

> Durable global facts and reference patterns. Full protocols live in `.ai/skills/` and `AGENTS.md`.
> Last reviewed: 2026-11-17.

## Stack (Verified)
- **Backend**: Laravel 13, PHP 8.3+, MySQL (`kady` in local `.env`), SQLite `:memory:` in `phpunit.xml`, Sanctum SPA token auth, `spatie/laravel-permission`, `spatie/laravel-activitylog`.
- **API Responses**: Direct `response()->json(['success' => true, 'data' => ..., 'meta' => ...])`. `app/Http/Resources` does NOT exist.
- **Frontend**: Vue 3 (`<script setup>`) + Vite + Pinia + Tailwind CSS + Lucide icons, RTL/Arabic-first (`lang/ar/`, `lang/en/`).

## Reference Service Architecture (Gold Standard)
- **Unified Domain Service Facade**: Controllers inject ONE service (`PosSaleService`), which internally orchestrates focused collaborators:
  - `App\Support\Decimal` (`90 lines`): Pure BCMath helpers (`MONEY_SCALE = 2`, `QUANTITY_SCALE = 3`, `CALCULATION_SCALE = 8`). Never use `float` for money/quantity calculations.
  - `App\Services\Pos\PosSaleCalculator` (`334 lines`): Pure domain math (item consolidation, line totals, discounts, remainder-absorbing invoice discount allocation) with zero DB queries.
  - `App\Services\Pos\PosSaleQueryService` (`75 lines`) & `PosProductCatalogService` (`68 lines`): Read-only pagination, single-subquery category filtering, and barcode lookups.
  - `App\Services\Pos\PosDefaultWarehouseResolver` (`29 lines`): Single default warehouse resolution (`Warehouse::default()`).
  - `App\Services\Pos\PosSaleService` (`411 lines`): Atomic write orchestration, bulk `whereIn` product loading, bulk `insert()` for sale & inventory items, and in-memory relation hydration (`hydrateSale`).
  - `App\Services\Pos\PosShiftService`: Single `GROUP BY type` SQL query (`sumTransactionsByType`) for shift cash calculations.
- **Strict Backed Enums**: All domain types/statuses use backed Enums in `app/Enums/` (`PaymentMethodType`, `DiscountType`, `InventoryDocumentType`, `InventoryDocumentStatus`, etc.). Never branch on Arabic display names (`'نقدي'`, `'فيزا'`) or hardcoded IDs (`1`).
