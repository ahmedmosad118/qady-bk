---
name: code-review
description: Self-review checklist to run on your own diff before declaring any Plamx task done. Catches unrelated changes, API breaks, tenant/security issues, missing tests and dirty code.
---

# Self-review before finishing

Run `git status` and `git diff`, then answer honestly.

## Scope
- [ ] Only files needed for the task changed? Any drive-by rename/format/refactor? Revert it.
- [ ] The user's unrelated uncommitted changes untouched.

## Contracts
- [ ] No endpoint/param/response key/status/prop/emit/event renamed.
- [ ] No new statuses, no Action classes, no new state or UI library.

## Correctness
- [ ] Null cases handled (esp. `project_status_id`).
- [ ] Imports, namespaces, types OK; `php -l` run.
- [ ] Frontend and backend field names match.

## Architecture
- [ ] Controller thin, logic in Service, queries in Repository.
- [ ] Reused existing code instead of duplicating.

## Security & tenancy
- [ ] Authorization + validation present; object-level access checked.
- [ ] Tenant isolation preserved; no secrets or sensitive data logged.

## Performance
- [ ] No N+1, pagination kept, indexes considered.

## Cleanliness
- [ ] No debug code, commented-out blocks, unused imports/vars.
- [ ] Names clear and consistent with surrounding code.

## Tests
- [ ] Tests added/updated and actually run; results reported honestly.

If any box fails, fix it first. Then write the final report: Changed / Implemented / Validation / Notes.
