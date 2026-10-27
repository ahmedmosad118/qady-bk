---
name: solid
description: SOLID principles applied to Plamx (Laravel services/repositories, Vue/Pinia). Use when writing NEW classes/components or when a class you must change is already too big. Never a reason to refactor unrelated code.
---

# SOLID in Plamx

## Scope rule (read first)
Apply SOLID to: new code, and code you are already changing for the task.
Do NOT use SOLID as a reason to rewrite working code, touch extra files, or add layers.
If existing code violates SOLID and the task does not require touching it: leave it, mention it in Notes.

## S - Single Responsibility
- Controller: HTTP only. Service: business rules of ONE domain area. Repository: queries only.
- Signs of a violation: a method with "and" in its name, a service > ~300 lines, a controller doing calculations,
  a Vue component doing API calls + formatting + layout.
- Fix (only for code you are changing): extract a private method first; extract a new class only if it is reused
  or clearly a separate concern (e.g. calculation logic into its own service, like `KpiCalculatorService`).
- Vue: UI in the component, reusable logic in a composable, shared state in the Pinia store.

## O - Open/Closed
- Adding a new variant (new KPI calculation method, new AI provider, new export type) should not mean editing a
  growing `if/elseif/switch` chain in five places.
- Use a map/strategy ONLY when you have 3+ variants or the existing code is already heading there.
  Two variants: a plain `if` is fine.

## L - Liskov Substitution
- If you implement an existing interface/abstraction, every implementation must honor the same input, output
  and exceptions (e.g. all AI providers return the same response shape).
- Don't override a method to throw "not supported".

## I - Interface Segregation
- Keep interfaces small and specific. Don't create one big interface for unrelated methods.
- Project reality: Services/Repositories are plain classes with no interfaces. Do NOT add interfaces for them
  unless there are (or will immediately be) 2+ implementations.

## D - Dependency Inversion
- Inject dependencies through the constructor (Laravel container). No `new SomeService()` inside methods,
  no static calls to other services.
- Depend on the existing abstraction where one exists. Reference example: the AI provider abstraction
  (`GeminiProvider`, Ollama, `UnoRouter`). Never hard-code a provider in business logic.
- Facades like `DB`, `Cache`, `Storage` are fine (project convention).

## Anti-over-engineering (equally important)
- No interface "just in case", no abstract base class with one child, no factory for one class.
- Three similar lines are better than a premature abstraction. Abstract on the third real duplication.
- If SOLID and "existing pattern in the same module" disagree, the existing pattern wins; report it.

## Checklist for new/changed classes
- [ ] One clear reason to change?
- [ ] Dependencies injected, not created inside?
- [ ] No new `switch` chain that will keep growing?
- [ ] No abstraction without 2+ real users?
