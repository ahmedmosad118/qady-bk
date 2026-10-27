---
name: tenancy
description: Multi-tenancy rules for Plamx (Stancl Tenancy, DomainTenantResolver). Use whenever code touches DB, cache, files, sessions, queues, jobs, commands or tests that involve tenants.
---

# Tenancy in Plamx

## Mental model
Every request on a tenant domain runs inside a tenant context: tenant DB connection, tenant cache prefix,
tenant filesystem root, tenant session. Code must be correct inside that context and never leak across it.

## Read before changing tenant-related code
- `App\Models\Tenant`
- `config/tenancy.php` (bootstrappers, central domains, features)
- Tenancy middleware and `DomainTenantResolver`
- Central vs tenant route files
- How queued jobs/commands initialise tenancy

## Rules
1. Never add a second tenancy mechanism or manual DB switching.
2. Never use the central connection for tenant data (or vice versa). Check each model's connection/trait.
3. Tenant routes live in tenant route files with tenancy middleware; central routes stay central.
4. Cache keys, storage paths and session data go through tenant-aware facades (Cache, Storage, session);
   no hard-coded global paths/keys.
5. Queued jobs must be tenant-aware (use the project's existing approach).
6. Artisan commands touching tenant data iterate tenants the way existing commands do (copy one).
7. No cross-tenant queries; no `withoutGlobalScopes` that removes tenant constraints; no raw SQL ignoring the current connection.
8. Never trust a `tenant_id` from request input.

## Migrations
Tenant migrations MUST go in the `database/migrations/tenant` path, and central ones in the central path. Never mix them. Check where sibling tables live.

## Tests
Use the project's tenant test setup. Always test (a) it works in tenant A, (b) tenant B cannot see or modify tenant A data.

## Red flags: stop and ask
Changing bootstrappers, resolver, Tenant model hooks, session/cache/filesystem tenancy config, or domain logic.
