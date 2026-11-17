---
name: solid
description: SOLID principles applied to Qady ERP (Laravel 13 services/calculators/queries, Vue 3/Pinia). Use when designing new classes or refactoring bloated services.
---

# SOLID Principles in Qady ERP

## S — Single Responsibility (The 400-Line Rule)
- **Controller**: HTTP orchestration only (`<= 140 lines`). Injects ONE unified domain service.
- **Unified Domain Service** (e.g. `PosSaleService`, `<= 400 lines`): Public entry point for the module and owner of the write transaction. Delegates specialized work to internal collaborators:
  - **Calculator** (`PosSaleCalculator`): Pure domain math (BCMath via `App\Support\Decimal`), zero DB queries.
  - **Query / Catalog Service** (`PosSaleQueryService`, `PosProductCatalogService`): Read-only Eloquent queries, filters, subqueries, and pagination.
  - **Resolver** (`PosDefaultWarehouseResolver`): Single domain invariant lookup.
- Signs of a violation: a service > 400 lines, a method mixing SQL queries with multi-step BCMath discount allocation, or a controller injecting 4 different services for one resource.

## O — Open/Closed (Enum-Driven Behavior)
- Never write `if ($methodName === 'نقدي') elseif ($methodName === 'فيزا')` or `if ($paymentMethodId !== 1)`.
- Use PHP 8.3 backed Enums (`App\Enums\PaymentMethodType`, `DiscountType`, `InventoryDocumentType`) with `label()` and `match ($enum)` so adding or renaming display labels never breaks business logic.

## L — Liskov Substitution
- Every implementation of a contract or shared helper must honor the exact input/output types and scale invariants (e.g. `Decimal` always returns canonical numeric strings formatted to the requested scale).

## I — Interface Segregation
- Services and collaborators are concrete classes injected via Laravel's container. Do NOT create single-implementation interfaces (`PosSaleServiceInterface`) unless 2+ real implementations exist.

## D — Dependency Inversion
- Always inject collaborators via constructor (`protected PosSaleCalculator $calculator`, `protected PosSaleQueryService $queryService`).
- Pure calculators depend on `App\Support\Decimal` and Eloquent models/collections passed in memory — never on `DB` or `Request`.

## Checklist
- [ ] Is every class under 400 lines with one clear responsibility?
- [ ] Does the controller inject a single unified service facade while sub-services are handled inside that service?
- [ ] Are all status/type branches using backed Enums (`match`) instead of Arabic/English strings or magic IDs?
