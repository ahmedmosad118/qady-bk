---
name: plamx-architecture
description: Core Plamx workflow. Use for ANY non-trivial task: how to inspect the repo, find existing patterns, plan, make minimal changes and report.
---

# Plamx Architecture & Workflow

## Golden loop
1. **Understand** the request. Restate it in one sentence.
2. **Inspect**: find the closest existing feature (controller, service, repository, model, request, resource,
   Vue component, store, migration, test). Open and read them fully, not just the names.
3. **Pattern**: decide which existing files you will mirror.
4. **Impact**: list files that must change. Anything not on the list stays untouched.
5. **Plan**: 3-7 bullet plan before editing.
6. **Implement** the smallest correct change.
7. **Validate**: run tests/lint/syntax.
8. **Review** your own diff with the `code-review` skill.

## Layering rules
- Controller thin -> FormRequest/validation -> Service (logic) -> Repository (queries) -> Model.
- No Action classes unless the modified module already uses them. No logic in Blade. No queries in controllers if a repository exists for that model.
- New code lives in the same folder/namespace conventions as sibling code.

## Search recipes
```
grep -rn "TaskService" app/ tests/
grep -rln "project_status" app/ database/ resources/ tests/
php artisan route:list --path=tasks
```

## Hard stops (ask the user first)
Breaking API change, DB column type/nullability change, new package, new status value, touching tenancy
bootstrappers, anything in .env/deploy config.

## Anti-patterns (never)
- "Cleaner" rewrite of working code.
- Duplicate endpoint/relationship/store/component.
- Guessing names of methods/columns/routes without opening the file.
- Declaring done without running anything.

## Final report format
Changed / Implemented / Validation (only what actually ran) / Notes.
