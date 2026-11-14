# Module Memory: Point of Sale (POS - نقاط البيع)

> Created: 2026-11-14
> Stack: Laravel 13, PHP 8.3+, MySQL / SQLite

## Architecture & Data Flow
- **Models & Tables**:
  - `PaymentMethod`: `payment_methods` (No `code`). Seeded with: نقدي, فيزا, تحويل بنكي.
  - `PosShift`: `pos_shifts` (No `status`; state derived from `closed_at`). Formula: `Expected Cash = Opening + Cash Sales - Cash Returns - Expenses - Withdrawals`.
  - `PosShiftTransaction`: `pos_shift_transactions` (Types: `cash_sale`, `cash_return`, `expense`, `withdrawal`). Immutable financial records.
  - `PosSale`: `pos_sales` (No `status`, no `SaleStatus` enum). Immutable finalized sales.
  - `PosSaleItem`: `pos_sale_items`. Captures `product_snapshot`, supports price overrides, item discounts, and accurate distribution of invoice discount (`allocated_invoice_discount`) for partial returns. Unique `(sale_id, product_id)`.
  - `PosReturn`: `pos_returns` (No `status`, no `ReturnStatus` enum). Bound to original sale and original payment method.
  - `PosReturnItem`: `pos_return_items`. Pro-rates discounts and verifies requested quantity against remaining returnable quantity.
  - `PosHeldSale` & `PosHeldSaleItem`: Independent tables for held/draft carts (`pos_held_sales`, `pos_held_sale_items`). No inventory impact until resumed and completed.
  - `PosSyncTransaction`: `pos_sync_transactions`. Client UUID idempotency tracking, preserving local payloads upon stock conflict without polluting `pos_sales`.

## Inventory Integration
- When completing a sale (`PosSaleService::createSale`):
  1. Creates `PosSale` & `PosSaleItem` records.
  2. Creates an `approved` `stock_issue` document in `inventory_documents` linked via `referenceable` (`'pos_sale'`).
  3. Uses `InventoryDocumentWorkflowService::post()` to atomically lock warehouse stock rows (`lockForUpdate`), enforce non-negative stock, verify stocktake locks, create immutable `StockMovement` (OUTBOUND), and update `WarehouseStock`.
  4. `InventoryDocumentObserver` triggers on `updated` to `POSTED`, verifies `stockMovements()->exists()`, and safely no-ops to ensure single execution.
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
