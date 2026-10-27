---
name: patterns
description: Design patterns approved for NEW code in Plamx (backend Laravel + frontend Vue 3), with when to use and when NOT to. Use when designing a new class/feature or when the same decision (which pattern?) comes up.
---

# Approved patterns for Plamx

## Ground rules
1. Patterns solve a PROBLEM. Start from the problem, not the pattern. If a plain method works, use it.
2. Applies to new code and code you must change anyway. Never refactor existing working code to "introduce a pattern".
3. First check if the module already solves it a certain way. Existing pattern > this list.
4. Max ONE new pattern per task. Mention it in Notes with the reason.
5. Forbidden by project rules: Action classes (unless the modified module already uses them), second state-management library, second tenancy mechanism.

## Backend (Laravel)

### Already the project standard (always use)
- **Service layer**: business logic. **Repository**: queries. **Thin controller**: validate, call service, return JSON.
- **Dependency Injection** via constructor.

### Use when the problem appears
| Pattern | Use when | Do NOT use when |
|---|---|---|
| **Strategy** | 3+ interchangeable algorithms chosen at runtime (e.g. KPI calculation by `calculation_method`, export formats, AI providers) | 2 variants; a simple `if` is clearer |
| **Pipeline** (`Illuminate\Pipeline\Pipeline`) | A request/data passes through ordered steps (filters, transforms, validations) | Steps are not independent or order is trivial |
| **DTO / value object** | The same group of 4+ values travels across layers, or arrays with magic keys cause bugs | Simple CRUD where `validated()` array is passed once |
| **Query scope / filter objects** | The same filter logic is repeated in 2+ repository methods | A single usage |
| **Observer / Events + Listeners** | A side effect must happen after a change (notification, log, recalculation) and should not bloat the service | Core business flow that must be visible in the service; or tenant context unclear (check `tenancy` skill: queued listeners must be tenant-aware) |
| **Adapter** | Wrapping an external API/provider behind the project's existing abstraction | Provider abstraction already exists: reuse it |
| **Factory** (class) | Object creation has branching logic and the creator is used in 2+ places | `new` / Eloquent factory is enough |
| **Enum-backed behavior** | Statuses/types need labels, colors, or transitions | Statuses already live in DB tables/slugs: follow existing way, don't invent new statuses |

### Avoid
- Command-style "Action" classes (unless the modified module already uses them), Singleton (use the container), God services, generic base Repository/Service
  classes (project has none), static helper classes with business logic.

## Frontend (Vue 3 + Pinia)

### Project standard (always use)
- `<script setup>` + Composition API, Pinia for shared state, props down / emits up, PrimeVue components.

### Use when the problem appears
| Pattern | Use when | Do NOT use when |
|---|---|---|
| **Composable** (`useXxx`) | Stateful logic reused by 2+ components (pagination, filters, form handling) | Used by one component only |
| **Pinia store (per domain)** | State shared across components of the same app | State local to one component; or an existing store already owns it |
| **Container / presentational split** | A component mixes API calls with large markup | Small components |
| **Provide/inject** | Passing data through 3+ component levels in one feature | 1-2 levels: use props |
| **Renderless/slot-based component** | Same behavior, different markup in several places | One usage |
| **API service module** | Repeated endpoints calls across components (use the app's existing API layer) | The app already has a layer: reuse |

### Avoid
- A second state library, mixins, global event bus, new generic components "just in case",
  deep prop drilling beyond 3 levels.

## Decision procedure (run before choosing)
1. Search the module for how a similar problem was solved. Use that.
2. If none: describe the problem in one sentence. Which row above matches? If none matches, use plain code.
3. Write down in your plan: "Pattern: X, because Y, example in repo: Z (or none)".
4. After implementing: is the code simpler or more extensible than the plain version? If not, revert to plain.

## Output note
In the final report, Notes must include: which pattern was used (or "none"), why, and any existing code that
violated these guidelines but was intentionally left untouched.
