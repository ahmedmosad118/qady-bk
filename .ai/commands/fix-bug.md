# /fix-bug — Reproduce, pin with a failing test, then fix minimally

## Steps
0. Memory: read `.ai/memory/MEMORY.md`, `gotchas.md`, relevant `modules/*.md`. Skills: `plamx-architecture` + area skills.
1. **Reproduce first.** Prove the bug with a test or query before touching source. No reproduction → no fix.
2. **Regression test (fails before, passes after).** Follow `testing` skill; prefer pure-unit style like `tests/Feature/Dashboards/*` (no DB) unless the module has real DB-backed conventions. Never weaken existing tests.
3. **Fix minimally.** Smallest change per `AGENTS.md` §13. Reuse the central calculation/service; do not duplicate formulas. No unrelated refactors.
4. **Validate.** `php -l` on changed files; run the new test + neighbors (`php artisan test <paths>`); frontend lint/build if touched. Respect `testing` DATABASE SAFETY.
5. **Review.** Run the `reviewer` agent (`code-review` skill) on the diff; fix failures.
6. **Persist.** Update memory per `memory` skill (gotcha and/or module file).

## Output
Changed / Implemented / Validation (only what ran) / Notes — per `AGENTS.md` §22.
