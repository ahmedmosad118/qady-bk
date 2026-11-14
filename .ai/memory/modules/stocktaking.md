# Module Memory: Stocktaking (الجرد المخزني)

> Created: 2026-11-14
> Stack: Laravel 13, Vue 3, Pinia, Tailwind CSS

## Architecture & Data Flow
- **Models & Tables**: `stocktakes`, `stocktake_items`, `stocktake_item_histories`.
- **Enums**:
  - `StocktakeScope` (`full`, `selected`).
  - `StocktakeStatus` (`draft`, `in_progress`, `pending_approval`, `approved`, `rejected`, `cancelled`, `completed`).
  - `StocktakeItemStatus` (`pending`, `counting`, `counted`).
- **Blind Count Protection**:
  - `system_quantity` and `variance` are stripped to `null` in API responses (`StocktakeController::show`) for any uncounted item, and for any standard counter user.
  - Only exposed to authorized reviewers after physical count is submitted.
- **Row-level Concurrency & Movement Lock**:
  - Calling `startCounting` locks the specific `(warehouse_id, product_id)` combination in `WarehouseStock` with `lockForUpdate()`, captures the balance snapshot, and marks the item `counting`.
  - `StockMovementService::applyMovements` intercepts attempts to move any product currently locked in `counting` in an active stocktake for that warehouse and halts with a descriptive exception (`stocktake_locked`). Other products in the warehouse and the same product in other warehouses remain unlocked.
  - Recording the physical count (`recordCount`) saves the count, sets `counted`, and releases the lock atomically upon transaction commit.
- **Settlement & Auto-Completion**:
  - Approving a stocktake calculates variances (`counted - system`).
  - Positive variances create draft `stock_adjustment` (increase) documents with `referenceable_type = 'stocktake'`.
  - Negative variances create draft `stock_adjustment` (decrease) documents with `referenceable_type = 'stocktake'`.
  - Zero variances immediately complete the stocktake.
  - Posting all referenceable adjustment documents in `InventoryDocumentWorkflowService::post` automatically transitions the stocktake to `completed`.
