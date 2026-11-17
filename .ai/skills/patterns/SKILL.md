---
name: patterns
description: Approved design and performance patterns for Qady ERP (Laravel 13 + Vue 3). Reference guide for building clean, high-performance modules.
---

# Approved Design & Performance Patterns in Qady ERP

## Backend Patterns (Laravel 13)

| Pattern | Canonical Example | When to Use |
|---|---|---|
| **Unified Service Facade + Internal Collaborators** | `PosSaleService` orchestrating `PosSaleCalculator`, `PosSaleQueryService`, `PosProductCatalogService`, `PosDefaultWarehouseResolver` | Whenever a domain has write transactions, complex math, and read/catalog queries. Controller injects ONLY `PosSaleService`. |
| **Pure Domain Calculator** | `PosSaleCalculator` + `App\Support\Decimal` | Any multi-line invoice, return, purchase, or discount calculation. Takes arrays/collections in memory, performs pure BCMath, and returns computed arrays without touching the DB. |
| **CQRS Read/Write Split (Internal)** | `PosSaleService` (write) + `PosSaleQueryService` & `PosProductCatalogService` (read) | Keeps write transaction code focused and under 350 lines while isolating pagination/filtering queries. |
| **In-Memory Relation Hydration** | `PosSaleService::hydrateSale()` | After creating a record inside a transaction, attach already-loaded models (`setRelation`) instead of running 5–6 extra `SELECT` queries via `$model->load(...)`. |
| **Bulk Load & Bulk Insert** | `PosSaleService::loadProducts()` & `insertSaleItems()` | Load all line products in 1 `whereIn` query; insert all line items in 1 `Model::query()->insert(...)` call. |
| **Single Grouped SQL Aggregation** | `PosShiftService::sumTransactionsByType()` | Aggregate multiple transaction/movement types in 1 `GROUP BY type` query instead of N separate `sum()` queries. |
| **Backed Enum Strategy** | `PaymentMethodType`, `DiscountType`, `InventoryDocumentType` | Replace magic strings (`'نقدي'`, `'فيزا'`) and magic IDs (`1`) with backed Enums, model casts, and `match`. |

## Anti-Patterns (Strictly Forbidden)
- **God Services (> 400 lines)** mixing HTTP/filtering, raw BCMath helpers, catalog search, and transaction writes in one file.
- **Query-per-item loops**: Calling `Product::find()`, `PosSaleItem::create()`, or `InventoryDocumentItem::create()` inside a `foreach` loop.
- **Magic String / Magic ID branching**: Comparing translated names (`$name === 'نقدي'`) or primary keys (`$id === 1`) in business rules.
- **Float Money Math**: Using `(float)` or `round()` for money/quantities instead of `App\Support\Decimal`.
