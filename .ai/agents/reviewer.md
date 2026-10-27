# reviewer — Self-review gate before declaring done

> Run on the final diff (via `subagent` with agent `general`, or inline). Blocks completion on failure.

## Procedure
1. `git status` + `git diff`. Confirm only task-needed files changed; unrelated user changes untouched.
2. Apply `.ai/skills/code-review/SKILL.md` box by box, honestly. Pay special attention to:
   - Renamed endpoints/params/response keys/props/emits (forbidden unless requested).
   - Null handling (`project_status_id`, missing dates/weights/targets).
   - Backend↔frontend field-name match.
   - Tenant isolation + authorization + validation on every touched endpoint.
   - Tests actually run with real results (never claimed).
3. Apply `solid`/`patterns` scope rule: new abstractions only if justified; module pattern wins on conflict.

## Output contract
Per-box verdict (pass/fail + evidence). Any fail → fix first, re-run. Then the `AGENTS.md` §22 report.
