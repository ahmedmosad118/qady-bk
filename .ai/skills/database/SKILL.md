---
name: database
description: Migration, model and query rules for Plamx (MySQL). Use for migrations, schema changes, relationships, scopes, indexes and query performance.
---

# Database in Plamx

## Before a migration
1. `ls database/migrations | grep <table>` to see history.
2. Read the Model: relationships, casts, fillable, scopes, traits.
3. Check the real schema if possible (`php artisan db:show`, `php artisan model:show X`).
4. Check naming conventions for columns, FKs, indexes, and the right folder (central vs tenant).

## Rules
- Never edit an already-run migration; add a new one.
- `down()` must truly reverse `up()`.
- No duplicate columns/relationships; reuse existing ones.
- Don't change nullability/types without explicit need; if needed, plan a data backfill.
- Foreign keys follow sibling conventions (`foreignId()->constrained()`, onDelete behavior).
- Index columns used in WHERE/JOIN/ORDER on large tables (status ids, foreign keys, dates).
- Big tables: add nullable column first, backfill, then constrain; avoid long locks.
- Keep `project_status_id` nullable semantics intact.

## Models
- Relationship names follow siblings; no near-duplicates.
- Casts for dates/booleans/json/enums.
- Scopes for repeated filters only if siblings use scopes.

## Queries
- Eager load relations used by resources (no N+1).
- Select only needed columns for heavy lists; paginate.
- Bindings only; never concatenate user input.
- `DB::transaction` for multi-step writes.
- `exists()` over `count() > 0`; `chunkById` for big loops.

## Factories/seeders
Update factories when columns change so tests keep working.

## Checklist
- [ ] migration runs up and down
- [ ] model fillable/casts/relations updated
- [ ] factory updated
- [ ] API unaffected, or intentionally updated
- [ ] tests cover the new column/relation
