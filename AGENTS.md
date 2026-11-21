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
- Internationalization (Zero Hardcoded UI Strings Rule):
  - NEVER hardcode Arabic (or untranslated English) user-facing strings in `.vue` templates/scripts, Pinia stores, utilities, or services.
  - All UI labels, placeholders, error fallbacks, confirmation prompts, toast notifications, and status labels MUST use `vue-i18n` (`$t(...)` / `t(...)` / `i18n.global.t(...)`) with matching keys in `resources/js/locales/ar.js` and `resources/js/locales/en.js`.
- Dynamic RTL / LTR Layout Rule:
  - NEVER hardcode `dir="rtl"` on views, modals, drawers, or print templates. Rely on the root `<html>` `dir` attribute or bind dynamically to `locale` (`:dir="locale === 'ar' ? 'rtl' : 'ltr'"`).
  - Use CSS logical Tailwind utilities (`text-start`, `text-end`, `ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `border-s`, `border-e`) instead of physical ones (`text-left`, `text-right`, `ml-*`, `mr-*`, `pl-*`, `pr-*`, `left-*`, `right-*`), except inside explicit `dir="ltr"` containers (phone numbers, emails, barcodes, invoice/document codes).
  - Never hardcode `'ar-EG'` in date/number formatters; use `resources/js/utils/date.js` or resolve dynamically from `locale`.
- Document Numbers Contrast: Render clickable invoice, order, shift, expense, and document numbers in high-contrast `text-[var(--text)] hover:text-[var(--crimson)]` (never plain red `text-[var(--crimson)]`).
- Components: Reusable, modular UI components in `resources/js/components/`.

---

## 5. QUALITY GATE & VERIFICATION

Before marking any task as complete:
1. Run `php artisan test` — all tests must pass.
2. Run `npm run build` — frontend must build without syntax errors or broken imports.
3. Run `git status` — ensure only intended files were modified.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
