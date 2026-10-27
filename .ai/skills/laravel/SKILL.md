---
name: laravel
description: Laravel 13 / PHP 8.3 backend rules for Plamx. Use for controllers, services, repositories, requests, resources, policies, jobs, routes.
---

# Laravel in Plamx

## Before coding
Open a sibling module (Tasks, Projects, KPIs) and copy its structure: route file, controller, request,
service, repository, resource, policy, tests.

## Layers
- **Route**: grouped under the same middleware as siblings (auth, tenant, permission). Use existing files/prefixes.
- **Controller**: validate (FormRequest), call ONE service method, return `response()->json([...])` in the same shape as sibling controllers of the same module (keys like `message` plus `entity` or `data` vary: copy the sibling, never invent a new key).
- **FormRequest**: rules + `authorize()` consistent with siblings. Never return true blindly unless siblings
  do and permission is enforced elsewhere.
- **Service**: business logic, `DB::transaction` for multi-write operations, domain exceptions the project already uses.
- **Repository**: query building, eager loading, filters, pagination. No business rules.
- **Resources**: `app/Http/Resources` does NOT exist in this project. Do not create API Resource classes; return JSON directly like existing controllers. Never change existing response keys.

## Code quality
- `declare(strict_types=1)` only if sibling files use it.
- **Namespaces:** ALWAYS import classes via `use` statements at the top of the file. NEVER use inline fully qualified namespaces (e.g., `\App\Models\ProjectTemplate`).
- Type-hint params and return types. Constructor injection.
- Use enums/constants already in the project for statuses; no magic strings.
- Early returns over deep nesting. One responsibility per method.
- Eloquent relationships over raw joins; eager load (`with`) to avoid N+1; paginate lists.
- Mass assignment: respect `$fillable`/`$guarded`; never `$request->all()` into create/update, use `validated()`.
- Never `DB::raw` with user input. Use bindings.
- No `dd`, `dump`, `var_dump`, `console.log` left behind.

## Errors
- Never catch-and-ignore. Log via the project's pattern, rethrow or return a consistent API error.
- Proper HTTP codes (422 validation, 403 forbidden, 404 not found).

## Checklist before finishing
- [ ] `php -l` on every changed PHP file
- [ ] imports/namespaces correct
- [ ] route visible in `php artisan route:list`
- [ ] validation + authorization present
- [ ] tenant context safe (see `tenancy`)
- [ ] tests added/updated (see `testing`)
- [ ] `./vendor/bin/pint --test` (or the project formatter) on changed files, if configured
