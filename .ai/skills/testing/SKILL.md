---
name: testing
description: PHPUnit testing rules for Plamx. Use when adding, fixing or running tests, or before declaring any backend change done.
---

# Testing in Plamx

## Find conventions first
Open 2-3 existing tests of the same module. Copy: auth/permission approach, factories, assertion style, naming.
All tests extend the standard `Tests\TestCase`. There is NO custom TenantTestCase or tenant helper; do not invent one.
Existing tests (e.g. `CouncilAuthorizationTest`) mock permissions directly. Follow that unless the module does otherwise.
Before writing a tenant-specific test, find how an existing test initialises a tenant (`grep -rn "tenancy()\|Tenant::" tests/`). If none does, ask the user.

## What to test (per backend change)
- Happy path (status code + JSON structure matching the existing contract)
- Validation failures (422) for each rule added
- Authorization: unauthenticated (401), unauthorized user (403)
- Tenant isolation: only if the project has real tenant test infrastructure (see "Tenancy reality"). Otherwise test the service/authorization logic with mocks like existing tests do, and mention in Notes that real isolation was not tested.
- Edge cases: null `project_status_id`, empty lists, boundary dates, status transitions
- A regression test for every bug fixed (fails before, passes after)

## Style
- Descriptive names: `test_user_cannot_close_task_from_another_tenant`.
- Arrange / Act / Assert; one behavior per test.
- Use factories, not hand-built rows. No dependence on test order.
- Run the real DB; mock external services (Gemini/Ollama/HTTP/mail).
- Never weaken or delete an existing test to make it pass. If a test is wrong, explain why.

## Commands
```
php artisan test --filter=TestName
php artisan test tests/Feature/<Module>
php artisan test
php -l path/to/file.php
```

## Frontend
Run the project's lint/build/test scripts if present (`npm run lint`, `npm run build`, `npm test`).

## Reporting
List exactly which commands ran and their results. If something could not run (no DB, no network), say so.
Never claim it passed.

## DATABASE SAFETY (read before running ANY test)
`phpunit.xml` has no dedicated test database configured (DB variables are commented out). Tests could therefore hit
the developer's real database.
- Before running tests, check which DB they would use (`phpunit.xml`, `.env.testing`, `config/database.php`).
- Never use `RefreshDatabase`, `DatabaseMigrations`, `migrate:fresh`, `db:wipe` or truncating seeders unless
  the target is clearly a throwaway test DB.
- If unsure, DON'T run the tests; tell the user and ask. Prefer running only mocked unit tests (`--filter`).

## Tenancy reality
There is no tenant test base class or tenant DB setup in `tests/`. Existing tests mock the user/permissions
(e.g. `CouncilAuthorizationTest`). Do not build tenant test infrastructure on your own; propose it to the user first.
