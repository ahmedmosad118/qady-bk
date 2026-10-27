# Module memory: report generator (`reports/generator`)

> Last reviewed: 2026-10-12 (role-based sharing added).

## What it is
Custom report builder (5-step wizard) + viewer + Excel export.
Backend: `ReportGeneratorController` → `CustomReport` (+ `ReportDefinitionRegistry`, `ReportEngine`).
Frontend: `resources/views/tenant/reports/generator/builder.blade.php` (Vue 2 + select2 directive, NOT a Vite app — follow it locally).
Gate: `view Report Generator` permission for index; show/export use `CustomReport::isVisibleTo()`.

## Confirmed rules
- Sharing = roles only, required when enabled. Toggle ON reveals a roles checkbox list; empty selection is rejected client-side AND server-side (`shared_role_ids` + `Rule::requiredIf(is_shared)` → 422).
- Pivot `custom_report_role` (migration `2026_10_12_000001`, tenant path, composite PK, cascade deletes). Synced on store/update; detached when sharing is off.
- Visibility: owner OR super-admin (sees all) OR member of a shared role.
  Regular users see only owned + role-shared (verified live on tenant_4:
  user_6 sees 7/12, role-less users see 0). Edit/delete keep the existing
  super-admin allowance (direct URL).
- `scopeForUserOrShared($userId)` signature unchanged — reports-center (`ReportsController:188`) inherits role filtering automatically.
- Roles list source: `Role::where('is_super_admin', false)` (same as report-permissions matrix). New UI strings use `__terms` + `ar.json` entries.
- Tests: `tests/Feature/Reports/CustomReportSharingTest.php` (pure unit via `setRelation`, no DB).

## Open issues
- Migration ran 2026-10-12 on tenants 1 and 4 (`tenants:migrate`, DONE both).
- `show` authorization line stays commented (pre-existing); `isVisibleTo` is the enforced check.
- Index badges still show generic Shared/Mine (no per-role display) — acceptable, revisit if requested.

## Fixed employee reports
- `employee_performance` stores its fixed scope and per-field editability in `CustomReport.configuration`; no new table is needed. The controller validates plan linkage and that the selected employee belongs to the plan's department (`ReportGeneratorController.php:569`).
- `EmployeePerformanceReportService.php:18` combines employee-owned `StrategicStructure`/`OperationalStructure` rows, accessible tasks assigned to or created by the employee and tied to the chosen operational plan, and accessible related KPIs. Task structure links exist both as `operational_structure` and as the `operationalStructure` pivot relation; child tasks may inherit the plan link through `parentTask`.
- Preserve both `Task::accessible()` and `Kpi::accessible()` in report queries. Deduplicate aggregated rows by primary key before calculating totals.
- Static report display and export use employee-specific components; print customization reuses `tenant.reports.styles.custom_print` from `show.blade.php:263` and its existing browser-local print settings.
- The builder's direct preview-refresh action must validate fixed scope independently of tab-navigation validation; Vue 2 should initialize each fixed-scope property up front so `v-model` updates are observed (`builder.blade.php`).
- Builder scope selectors must cascade from the real relations: operational plans expose linked strategic plan IDs and their section ID; employee options follow the selected section. Keep server-side scope consistency checks authoritative (`ReportGeneratorController.php`, `builder.blade.php`).
- Focused tests in `tests/Unit/Reports/EmployeePerformanceReportServiceTest.php` are isolated from Laravel's database bootstrap. `phpunit.xml:24` still defaults to MySQL and has no dedicated test database, so avoid running DB-backed tests without an isolated test environment.


## Operational Reports & Terms (2026-10-27)
- Standard report design system: uses `tenant.reports.styles.report_styles` with classes `reports_table_header_color` (structure headers with building icon and performance %) and `reports_table_entries_color` (tasks table header).
- Dynamic Terms: `$operationaLevel = "Operational Structure Level " . $levelNumber;` -> `__terms($operationaLevel . ' Report')` for report title, `__terms($operationaLevel)` for level term, `__terms('Task')`, `__terms('Sub Task')`, `__terms('Sub Sub Task')`, `__terms('Task Name')`.
- Page Title: `@section('title', config('app.name', 'Plamx') . ' - ' . $reportTitle)` ensures `Plamx` / `APP_NAME` is displayed in browser tabs alongside the dynamic report title.
- Print Engine: uses `@include('tenant.reports.styles.print_reports', ['reportId' => 'reportBody'])` hooked to `#btnPrint`.
- Reports Center & Favorites Titles: `display_title` is assigned on each report item in `ReportsController::allReports()`, resolving `Operational Structure Progress Report` and `Detailed Operational Report` based on `app('sharedData')->operational_structure_level`. `reports_center.blade.php` and `dashboards_center.blade.php` render `$displayTitle` across favorites, grouped report cards, `data-title`, `data-translated-title`, and JS `addFavoriteToUI` dynamically so favorites card titles stay identical to the section card titles.

- [REMINDER / TODO for Tomorrow]: `Operational Structure Report` currently uses the existing permission `view Operational Plan Report` in `apps.json`, `reports_permissions.php`, and `OperationalReportController.php`. Need to review together tomorrow and update it to a dedicated permission (e.g. `view Operational Structure Report`) if desired.
