---
name: memory
description: Project memory protocol for Plamx. Use at the START of every task (read global + gotchas + module memory) and at the END (persist durable findings). Prevents re-learning the same pitfalls.
---

# Memory in Plamx

## Files
- `.ai/memory/MEMORY.md` — durable global facts (stack, methodology, testing reality). Short, factual.
- `.ai/memory/gotchas.md` — confirmed pitfalls with file references. Read before implementing.
- `.ai/memory/modules/<module>.md` — per-module findings (audits, fixes, open issues). Copy `_template.md` for a new module.

## At task start
1. Read `MEMORY.md` and `gotchas.md` fully (they are small by design).
2. Read the module file if one exists for the area you touch.
3. If a memory entry conflicts with the real code, the real code wins — flag it for correction.

## At task end
Persist only findings that are **durable** (will matter in 3+ months):
- A confirmed rule, formula, or methodology with file:line reference.
- A pitfall with root cause (goes to `gotchas.md`).
- An audit/fix summary with remaining issues (goes to the module file).
- A skill↔code conflict worth fixing.

Do NOT persist: transient task state, todo lists, conversation summaries, guesses, or anything already covered by a skill.

## Maintenance
- Keep each file short. Move stale entries out; never let `MEMORY.md` become a log.
- Every entry needs either a file:line reference or a date. Entries without either get deleted on sight.
- If unsure whether something is durable, leave it out and mention it in the task Notes instead.
