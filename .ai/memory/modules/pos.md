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
- **`PosShiftService`**: Aggregates shift transaction totals in a single `GROUP BY type` SQL query (`sumTransactionsByType`).
- When processing a return (`PosReturnService::processReturn`):
  1. Creates `PosReturn` & `PosReturnItem` records.
  2. Creates an `approved` `inventory_return` document with `return_direction = INBOUND` in `inventory_documents` linked via `referenceable` (`'pos_return'`).
  3. Uses `InventoryDocumentWorkflowService::post()` to create `StockMovement` (INBOUND) and increment `WarehouseStock`.
  4. Records `cash_return` transaction in current shift if original payment was cash. Visa and bank transfer do not affect shift cash.

## Authorization Integration Points
- `pos.override_price`: Price override at point of sale.
- `pos.apply_item_discount`: Item-level discounts.
- `pos.apply_invoice_discount`: Invoice-level discounts.
- `pos.manage_shifts`: Opening and closing shifts.
- `pos.manage_shift_cash`: Recording expenses and withdrawals.
- `pos.process_returns`: Processing returns.
