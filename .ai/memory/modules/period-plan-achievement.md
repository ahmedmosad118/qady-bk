# Module memory: strategic period-plan achievement

## Confirmed rules
- Include tasks whose scheduled date range overlaps the selected period under their existing operational-structure/sector relationship (`StrategicReportController::loadTasksWithRelations`).
- Achievement uses the latest task execution and measurement point inside the selected period, inclusive of both boundary dates (`PeriodPlanAchievementUseCase`, `TaskPeriodProgressCalculator`).
- If a period measurement point exists but no execution does, the task contributes its target with zero actual. If no measurement point exists inside the period, keep the task visible but exclude it from achievement aggregation.
- Keep the full-precision target-progress ratio until achievement is calculated; rounding a small intermediate percentage to two decimals can turn a valid matching execution into 0% (`TaskPeriodProgressCalculator::clampProgress`).
- A scheduled target of exactly zero does not erase actual execution contribution. Preserve the actual plan share; the target-attainment ratio is undefined at zero target, so the task-level achievement field is null while the measured task progress remains available.
- The task period-plan report's optional operational-plan filter scopes nested operational objectives and their tasks before calculation, and period-history requests must carry the same selected operational plan (`TasksReportController::getPeriodPlanReportData`, `QuarterlyTasksReportUseCase`, 2026-10-15).

## Tasks plan reports
- Keep the period report's dashboard scope bounded to its selected dates (`as_of_today: false`). There is NO separate "through today" report (user rejected duplicating the report, 2026-10-20). Instead the period filter of the same report has an "Until Today" option (`period_index = 'until_today'`): the frontend sends plan `start_date` (or today if the plan has not begun) → today to the same `reports.tasks.period-plan-report.data` endpoint; no comparison periods for that option (`period_plan_report.blade.php`, `resolveUntilTodayDates`).
- Period comparison choices are limited to periods before the selected period. Default to all earlier periods, fetch only selected comparisons, and append the selected period as the final chart value (`resources/views/tenant/reports/tasks/period_plan_report.blade.php:2171,2378`).
