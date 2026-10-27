# PLAMX V5 — Project Memory

> Durable global facts only. Full protocols live in `.ai/skills/`. If anything here
> conflicts with the real code, the code wins — then fix this file.
> Last reviewed: 2026-10-12.

## Stack (verified)
- Backend: Laravel 13, PHP 8.3+, MySQL, Stancl Tenancy (`DomainTenantResolver`), `spatie/laravel-permission`.
- API responses: direct `response()->json([...])`. `app/Http/Resources` does NOT exist — do not create it.
- Frontend: Vue 3 + Vite + Pinia + PrimeVue + Bootstrap 5 + KeenIcons/Metronic, `<script setup>`, RTL/Arabic-first, dynamic `__terms`.
- Tests: standard `Tests\TestCase` only. No `TenantTestCase`, no tenant DB setup in `tests/`.

## Methodology (verified against code)
- Plan impact: `AllocationRatioService::calculateAllocationRatio(parent, weight)` → `parent * weight / 100`, stored on pivots/entities (`allocation_ratio`, `achievement_allocation_ratio`).
- Dashboard computed performance (`PlanImpactCalculationService::overallPerformance`): unattributed scope → `actual_contribution` (absolute plan share); attributed scope (dept/employee) → `execution_rate` (% within scope). A single selected-plan card and its task details use the same filtered task `actual_contribution`; detailed filters show target and achievement metrics (2026-10-15).
- Task progress: latest `TaskExecution` through `defaultKpi` via `UpdateTaskProgress::calculateProgress`; parent tasks with `dynamic_execution` aggregate subtasks weighted by `tasks.relative_weight`.
- KPI: `progress` = absolute actual, `verification_value` = achievement % (`KpiCalculatorService`). Polarity-aware; negative uses `target/actual`.
- KPI hierarchy tree shows KPI `achievement` as Effectiveness / الفاعلية; `progress` remains the absolute current value (`KpiHierarchyController`, `kpi_hierarchy_tree.blade.php`, 2026-10-26).
- Task statuses: Active, Done, Closed, Late. `Late` is derived from `due_date` vs today (today itself is NOT overdue); null `due_date` is never overdue.

## Testing reality
- `phpunit.xml` has NO dedicated test DB (DB vars commented out). Never run `RefreshDatabase` / `DatabaseMigrations` / `migrate:fresh` / `db:wipe` unless the target is a verified throwaway DB.
- Tenant isolation has no automated coverage; existing tests mock user/permissions. Do not build tenant test infra unasked.
- Dashboard changes are covered by `tests/Feature/Dashboards/` + `tests/Unit/Dashboard/` (pure unit style, no DB).

## Dashboard API (as of 2026-10-12)
- `GET /dashboard/management/filter-options`, `POST /dashboard/management/data`, `GET /dashboard/management/employee-tasks/{id}`, `POST /dashboard/management/send-reminders`.
- Response carries `executive_summary`, `attention_required`, `trends`, `departments`, `operational_objectives`, `employees`, plus `plan_linkage` and `data_quality` (weights/targets/executions completeness + plan-mismatch warnings).
- Frontend store must forward the full filter set (incl. `project_ids`, `task_status_ids`, `kpi_status_ids`, `priority`) to data, drilldown AND reminders identically.
