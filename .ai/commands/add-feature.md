# /add-feature — Smallest safe feature following existing patterns

## Steps
0. Memory: read `.ai/memory/MEMORY.md`, `gotchas.md`, relevant `modules/*.md`. Skills: `plamx-architecture` (+ `solid`/`patterns` for new classes, `laravel`/`vue`/`database`/`tenancy`/`security` as touched).
1. Understand: restate the request in one sentence. Ask before implementing if ambiguity could cause a breaking change.
2. Inspect: closest existing feature (controller, service, repository, model, request, Vue component, store, migration, test). Mirror it.
3. Impact: list files that must change; everything else stays untouched. Plan: 3–7 bullets.
4. Implement: thin controller → service → repository → model; FormRequest validation; permission checks; tenant-safe; direct `response()->json` in the module's existing shape; no Action classes, no new statuses, no new libraries unless genuinely required.
5. Tests per `testing` skill (happy path, 422s, 401/403, edge cases incl. null `project_status_id`).
6. Validate + review via `reviewer` agent; persist durable findings per `memory` skill.

## Output
Changed / Implemented / Validation (only what ran) / Notes — per `AGENTS.md` §22.
