# Module Memory: Point of Sale (POS - نقاط البيع)

> Created: 2026-11-14
> Stack: Laravel 13, PHP 8.3+, MySQL / SQLite

## Architecture & Data Flow
- **Models & Tables**:
  - `PaymentMethod`: `payment_methods` (`type` cast to `App\Enums\PaymentMethodType`: `cash`, `visa`, `bank_transfer`). Seeded with: نقدي (`cash`), فيزا (`visa`), تحويل بنكي (`bank_transfer`).
  - `PosShift`: `pos_shifts` (No `status`; state derived from `closed_at`). Formula: `Expected Cash = Opening + Cash Sales - Cash Returns - Expenses - Withdrawals`.
  - `PosShiftTransaction`: `pos_shift_transactions` (Types: `cash_sale`, `cash_return`, `expense`, `withdrawal`). Immutable financial records.
  - `PosSale`: `pos_sales` (No `status`, no `SaleStatus` enum). Immutable finalized sales.
  - `PosSaleItem`: `pos_sale_items`. Captures `product_snapshot`, supports price overrides, item discounts, and accurate distribution of invoice discount (`allocated_invoice_discount`) for partial returns. Unique `(sale_id, product_id)`.
  - `PosReturn`: `pos_returns` (No `status`, no `ReturnStatus` enum). Bound to original sale and original payment method.
  - `PosReturnItem`: `pos_return_items`. Pro-rates discounts and verifies requested quantity against remaining returnable quantity.
  - `PosHeldSale` (Hold / Resume): Stored STRICTLY in browser Local Storage. No database tables, no models, and no backend endpoints exist. Does not reserve or deduct inventory.
  - `PosSyncTransaction`: `pos_sync_transactions`. Client UUID idempotency tracking, preserving local payloads upon stock conflict without polluting `pos_sales`.

## Service Layer Architecture (Reference Pattern)
- **`App\Support\Decimal`**: Pure BCMath helpers (`MONEY_SCALE = 2`, `QUANTITY_SCALE = 3`, `CALCULATION_SCALE = 8`) with zero float arithmetic.
- **`PosSaleCalculator`**: Pure domain math (no DB, no side effects) — item consolidation, line totals, item/invoice discounts, and remainder-absorbing invoice discount allocation.
- **`PosDefaultWarehouseResolver`**: Enforces single-default-warehouse resolution (`Warehouse::default()`), ignoring client-supplied `warehouse_id`.
- **`PosProductCatalogService`**: Read-only POS catalog pagination (single subquery for parent+child categories) and exact barcode lookup.
- **`PosSaleQueryService`**: Read-only POS sales pagination, invoice-number lookup, and full detail loading.
- **`PosSaleService`**: Write-only atomic sale creation (`createSale`), bulk `whereIn` product loading, bulk `insert()` for `PosSaleItem` and `InventoryDocumentItem`, and in-memory relation hydration (`hydrateSale`) to avoid redundant post-commit `SELECT` queries.
- **`PosDashboardService`**: Computes daily POS stats (`invoice_count`, `gross_sales`, `cash_sales`, `visa_sales`, `bank_transfer_sales`, `returns_count`, `total_returns`, `net_sales`) in **2 constant SQL aggregate queries** using index-friendly `whereBetween` day boundaries (`sold_at`, `returned_at`) and `PaymentMethodType` conditional aggregation (`app/Services/Pos/PosDashboardService.php`).
- **`PosShiftService`**: Aggregates shift transaction totals and total count in a single `GROUP BY type` SQL query (`summarizeTransactions`) and shift sales totals/counts in a single SQL conditional aggregate query (`aggregateShiftSales`). `getShiftSummary()` intentionally does not hydrate `sales` or `transactions` into memory; detailed shift transactions (`/pos/shifts/{id}/transactions`) and sales (`/pos/shifts/{id}/sales`) are served paginated (note `pos_returns` column is `total_returned`, not `refund_amount`).
- **`StockMovementService` & `InventoryDocumentObserver`**:
  - `InventoryDocumentWorkflowService::post()` and `reverse()` are the single authoritative entry points for protected lifecycle transitions (`POSTED` / `REVERSED`), guarded via a scoped transition token (`isAuthoritativeTransitionActive`) inside `DB::transaction`.
  - `InventoryDocumentObserver` (`creating` / `updating`) is strictly a stateless validation guard with a per-document re-entrancy guard: blocks direct creation in `POSTED`/`REVERSED`, blocks direct or out-of-workflow transitions to `POSTED`/`REVERSED`, blocks leaving `POSTED`/`REVERSED`, and never uses `relationLoaded()`.
  - `StockMovementService::applyMovements()` prevents InnoDB gap-lock deadlocks on missing stock initialization by locking missing parent `Product` rows (`orderBy('id')->lockForUpdate()`) before `insertOrIgnore()`, then locking `WarehouseStock` rows in deterministic `(warehouse_id ASC, product_id ASC)` order before validating non-negative balances and updating stock (`app/Services/Inventory/StockMovementService.php`).
  - Both POS and Inventory/Warehouse/Stocktake flows ignore obsolete `is_active` flags and use strict `Warehouse::default()` resolution without arbitrary first-warehouse fallbacks.
- **`PosSaleService::authorizeSalePayload`**: Uses `Gate::forUser($user)->allows(...)` against the bulk-loaded `$products` map so authorization works identically in HTTP requests, offline sync, and CLI/service invocations (`app/Services/Pos/PosSaleService.php`).
- When processing a return (`PosReturnService::processReturn`):
  1. Creates `PosReturn` & bulk-inserts `PosReturnItem` records, aggregating prior returns in 1 `GROUP BY pos_sale_item_id` query (`sumReturnedQuantitiesBySaleItemIds`).
  2. Creates an `approved` `GOODS_RECEIPT` document in `inventory_documents` linked via `referenceable` (`'pos_return'`).
  3. Uses `InventoryDocumentWorkflowService::post($invDoc, $user, false)` to create `StockMovement` (INBOUND) and increment `WarehouseStock`.
  4. Records `cash_return` transaction in current shift if original payment was cash. Visa and bank transfer do not affect shift cash.

## Authorization Integration Points
- `pos.override_price`: Price override at point of sale.
- `pos.apply_item_discount`: Item-level discounts.
- `pos.apply_invoice_discount`: Invoice-level discounts.
- `pos.manage_shifts`: Opening and closing shifts.
- `pos.manage_shift_cash`: Recording expenses and withdrawals.
- `pos.process_returns`: Processing returns.
