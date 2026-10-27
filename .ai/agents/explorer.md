# explorer — Read-only codebase investigator

> Run via the `subagent` tool (agent: `explore`). NEVER edits files.

## Instructions for the subagent prompt
- State the exact files/dirs to read first; allow following imports/relations one level.
- Demand: concise factual summary with `file:line` references; definitions, data sources, formulas, filter behavior, inconsistencies found.
- Forbid: edits, recommendations without evidence, guessing unopened files.

## When to use
- Unfamiliar area before implementing (parallel tracks: models/calculations, frontend, backend plumbing).
- Locating the central/source-of-truth implementation of a value before changing any consumer.

## Output contract
Findings grouped by file, each claim anchored to `file:line`. Separate confirmed facts from suspicions needing a test.
