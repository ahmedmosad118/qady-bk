# QADY PROJECT (دار القاضي) — AI AGENT DEVELOPMENT RULES

## 0. SKILLS (READ FIRST)

Before any task, load the skills that apply from `.ai/skills/`:

| Task touches...                         | Read                                    |
|-----------------------------------------|-----------------------------------------|
| Any task                                | `memory` (read at start, update at end) |
| Any non-trivial task                    | `code-review`                           |
| PHP / Laravel backend                   | `laravel`                               |
| Vue 3 / Pinia / Tailwind frontend       | `vue`                                   |
| Migrations / models / queries           | `database`                              |
| Writing or running tests                | `testing`                               |
| Auth, permissions, input, secrets       | `security`                              |
| Designing new classes/features          | `solid`, `patterns`                     |

Also read `ARCHITECTURE.md` before making architectural or structural decisions.

---

## 1. PURPOSE & CORE VALUES

You are working on **دار القاضي (Al-Qadi Bookshop / Publishing House)** ERP & Management System.
The goal is **Production-Ready Enterprise Software (Production Grade)**, NOT an MVP.

Key Rules:
1. **SOLID Principles First:** Every class has a single responsibility. No fat controllers.
2. **Clean Code & Strong Typing:** Strict types, constructor injection, explicit return types.
3. **No Hidden Logic:** Multi-step writes wrapped in `DB::transaction`.
4. **Zero Hacks:** No client-side pagination loops loading thousands of records into memory. Server-side pagination and filtering is mandatory.
5. **No Broken Contracts:** Consistent API response envelope: `{ success: bool, message?: string, data?: mixed, meta?: array, errors?: array }`.
6. **Tests are Mandatory:** Every feature, bug fix, or rule change must be backed by automated feature/unit tests that pass (`php artisan test`).

---

## 2. ARCHITECTURAL LAYERING

The project strictly follows the clean 5-tier architecture:

```
[HTTP Route] 
     ↓
[Middleware] (Auth Sanctum, Roles/Permissions, Rate Limiting)
     ↓
[FormRequest] (Strict Authorization + Validation rules)
     ↓
[Controller] (Ultra-thin: orchestrates request, calls Service, returns JsonResponse)
     ↓
[Service] (Pure domain business logic, DB::transaction, event dispatching)
     ↓
[Repository / Model Scopes] (Query building, eager loading, filtering, pagination)
     ↓
[Database / Eloquent Model]
```

### ❌ The Anti-Pattern: Fat `CRUDController`
- Do NOT use monolithic CRUD base controllers that mix HTTP, database queries, transactions, and UI schema generation in a single class.
- Such controllers violate Single Responsibility (SRP), Open/Closed (OCP), and Dependency Inversion (DIP).
- If dynamic UI schemas or form configurations are needed, isolate them into dedicated **Schema Providers / Form Definitions**, completely decoupled from controller query execution.

---

## 3. BACKEND STANDARDS & REFERENCE ARCHITECTURE (Laravel 13 & PHP 8.3+)

> **Canonical Reference Implementation:** Always inspect `app/Support/Decimal.php` and `app/Services/Pos/` (`PosSaleService`, `PosSaleCalculator`, `PosSaleQueryService`, `PosProductCatalogService`, `PosDefaultWarehouseResolver`, `PosShiftService`) before writing or refactoring any complex module.

### 3.1 Size Limits & Decomposition (Unified Service Facade Pattern)
- **Controllers:** Maximum 50–140 lines. Inject **one** primary domain service via constructor (e.g., `PosSaleService`). Never execute queries or business math in controllers.
- **Services:** Maximum **350–400 lines**. NEVER write 800–1000+ line monolithic "God Services".
- **Internal Sub-Service Orchestration:** When a domain has complex math, read queries, and write transactions, keep the main service (`PosSaleService`) as the **Unified Facade** for controllers/callers, and decompose its internals into focused collaborators injected into `PosSaleService`:
  1. **Pure Calculator (`PosSaleCalculator`):** 100% pure domain math using `App\Support\Decimal` (BCMath). Zero DB queries, zero side effects.
  2. **Read Query Service (`PosSaleQueryService` / `PosProductCatalogService`):** Paginated listings, search filters, barcode lookups, and detail eager-loading.
  3. **Domain Resolvers (`PosDefaultWarehouseResolver`):** Single-purpose invariant resolvers.
  4. **Write Orchestrator (`PosSaleService`):** Coordinates locking, validation, persistence, and `DB::transaction`.

### 3.2 Zero Magic Strings — Enum-First Domain Logic
- **NEVER** branch on Arabic/English display names (e.g., ❌ `if ($method->name === 'نقدي')`) or hardcoded database IDs (❌ `if ($paymentMethodId !== 1)`).
- **ALWAYS** define a backed Enum in `app/Enums/` (e.g., `PaymentMethodType`, `DiscountType`, `InventoryDocumentType`), cast it on the Eloquent model, and use `match ($model->type)` or model helper methods (`$paymentMethod->isCash()`).

### 3.3 High-Performance Database & BCMath Standards
1. **Pure BCMath via `App\Support\Decimal`:**
   - Never cast money or quantities to `float` during calculation. Use `Decimal::MONEY_SCALE` (2), `Decimal::QUANTITY_SCALE` (3), and `Decimal::CALCULATION_SCALE` (8).
2. **Bulk Loading & Bulk Inserts (Zero N+1):**
   - Load all referenced products/entities in **1** `whereIn('id', $ids)` query before loops.
   - Insert child line items (`PosSaleItem`, `InventoryDocumentItem`, etc.) using a single bulk `Model::query()->insert(...)` query — never call `Model::create()` inside a `foreach` loop.
3. **In-Memory Relation Hydration (`setRelation`):**
   - Inside write transactions, reuse models already fetched in memory (`$sale->setRelation('shift', $shift)->setRelation('paymentMethod', $paymentMethod)...`) instead of firing 5–6 redundant `SELECT` queries via `$sale->load(...)` at the end of the transaction.
4. **Single Grouped SQL Aggregations:**
   - Never run multiple sequential `->where('type', X)->sum('amount')` queries on the same table. Use a single `SELECT type, COALESCE(SUM(amount), 0) as total ... GROUP BY type` query (see `PosShiftService::sumTransactionsByType`).
5. **Subqueries Over Multi-Step `pluck()`:**
   - Combine parent/child lookups into a single SQL subquery (`->orWhereIn('category_id', ProductCategory::select('id')->where('parent_id', $id))`) instead of executing an extra round-trip `pluck('id')` query.
6. **FormRequests:** Separate `StoreXRequest` and `UpdateXRequest`. No `$request->all()`. Always pass `$request->validated()` into the service.
7. **Responses:** Standard JSON envelope `{ success: bool, message?: string, data?: mixed, meta?: array, errors?: array }`.

---

## 4. FRONTEND STANDARDS (Vue 3 + Vite + Tailwind CSS)

- Vue 3 Composition API with `<script setup>`.
- Pinia for state management.
- Proper Server-side Pagination & Filtering:
  - Frontend components must request paginated data from the API (`page`, `per_page`, `search`, `filters`).
  - NEVER fetch all pages in a client-side `while`/`for` loop to filter in browser memory.
- Internationalization: Support Arabic (RTL) and English (LTR) cleanly using `vue-i18n`.
- Components: Reusable, modular UI components in `resources/js/components/`.

---

## 5. QUALITY GATE & VERIFICATION

Before marking any task as complete:
1. Run `php artisan test` — all tests must pass.
2. Run `npm run build` — frontend must build without syntax errors or broken imports.
3. Run `git status` — ensure only intended files were modified.
