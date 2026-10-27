# /audit — Read-only audit of an area

> Produces findings, never edits. For `dashboard/management`-style deep audits.

## Steps
0. Memory: read `.ai/memory/MEMORY.md`, `gotchas.md`, relevant `modules/*.md`.
1. Skills: `plamx-architecture` (+ domain skills per area). Restate the request in one sentence.
2. Inspect: core service(s), scope/filter plumbing, models + migrations, central calculation services, Vue/Pinia/API layer, existing tests. Use the `explorer` agent for parallel tracks on large areas.
3. For every metric/number, record: definition, real data source, scope, affecting filters, current vs previous computation, empty/zero/incomplete handling, weight relations, other consumers of the same calculation.
4. Classify: confirmed defects (with root cause + file:line) vs suspected (needs a test to prove) vs open product decisions.
5. Check: filter consistency (single + combined), contract stability, N+1, tenant isolation, translation/RTL.

## Output
- Findings table: issue → root cause → severity (decision-impacting or not) → files.
- Definitions adopted, with numeric examples.
- Explicit list of what was NOT verified.
- No code changes, no recommendations without evidence.
