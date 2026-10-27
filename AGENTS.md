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

## 3. BACKEND STANDARDS (Laravel 13 & PHP 8.3+)

- **Controllers:** Maximum 50–100 lines. Inject domain services via constructor. Never execute raw queries or complex queries in controllers.
- **FormRequests:** Separate `StoreXRequest` and `UpdateXRequest`. No `$request->all()`. Always pass `$request->validated()` into the service.
- **Services:** Encapsulate business rules. Any operation touching multiple tables or models must use `DB::transaction`.
- **Repositories / Query Builders:** Keep Eloquent queries out of controllers. Avoid N+1 with eager loading (`with`). Always paginate query results.
- **Responses:** Standard JSON shapes.

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
