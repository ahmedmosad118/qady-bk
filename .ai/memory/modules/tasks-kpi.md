# Module memory: Tasks / KPIs

> Last reviewed: 2026-10-19.

## What it is
Task execution and KPI measurement-point behavior lives in `app/Http/Controllers/Tenant/Tasks/KpiMeasurementPointsController.php` and `resources/js/tasksApp/components/items/TaskDetails/`.

## Confirmed rules
- The task KPI measurement-point reset is a separate operation from the existing direct-child weighted pull. It rebuilds every descendant parent bottom-up using child KPI measurement points and relative weights. Before rebuilding, terminal-task series are repaired to reach 100; missing/incomplete points get a correcting point on the task's due date, falling back to the nearest ancestor end date (`KpiMeasurementPointsController::resetFromSubTasks`).

## Fixes applied
- 2026-10-19: Added a recursive, bottom-up measurement-point reset action while preserving the existing one-level weighted pull; removed the due-date generation button, endpoint, and calculation test.
- 2026-10-19: Recursive reset now repairs terminal KPI schedules that have no points or do not reach 100, then rebuilds every ancestor so the generated series also ends at 100. Regression coverage includes underweighted level-2 leaves and empty level-3 leaves.
- 2026-10-19: Weighted daily gains can total 100 while independently rounding to 99.99. `accumulateWeightedGains` now applies the cent-level residual to the final measurement point so the cumulative endpoint preserves the weighted total; covered by the three-share 33.3333/33.3333/33.3334 regression.

## Open issues
- None.
