# Module memory: management dashboard (`dashboard/management`)

> Current checkout reviewed 2026-10-14. Some dated history below may no longer match
> the source; the current code and the verification note at the end are authoritative.

## What it is
Executive dashboard over strategic/operational plans, tasks (3 levels), KPIs, departments, employees.
Backend: `ManagementDashboardController` → `ManagementDashboardService` (+ `DashboardScope`, `PeriodHelper`, `PlanImpactCalculationService`, `KpiCalculatorService`, `AllocationRatioService`).
Frontend: `resources/js/managementDashboardApp` (Pinia store + ApexCharts sections).

## Confirmed rules
- A single selected-plan card and its task-details modal use the same filtered main-task `actual_contribution` when only a plan is selected; detailed filters show computed actual, target, and achievement metrics. This dashboard value is read-only and does not rewrite stored plan `progress` (2026-10-15).
- Computed performance is allocation-weighted task contribution, using the shared `PlanImpactCalculationService` calculation.
- Allocation-based impact values stay at stored/calculated precision throughout dashboard calculations; dashboard display truncates these shares to three decimal places without rounding. Never sum the displayed/truncated task rows; the total is computed from their full-precision values (`managementDashboardApp/utils/formatPlanImpact.js`, `PlanImpactCalculationService::summarizeMainTasks`, 2026-10-18).
- The active `reports/strategic/review-weight/progress-weight` screen sends the selected plan ID and recalculates its allocation ratios on save; allocation ratios persist separately at six decimals and the dashboard multiplies the stored pivot ratio by execution. The legacy `reports/strategic/weights-review` endpoint does not recalculate these ratios and can leave them stale. The active screen now exposes six-decimal precision rather than rounding each hierarchy step to two decimals (`ProgressWeightReviewService`, `AllocationRatioService`, 2026-10-18).
- `UpdateDataSeeder` is deployed via `tenants:seed` across existing tenant databases and recalculates each strategic plan's allocation ratios from stored relative weights. Strategic recalculation includes root operational structures linked by either strategic-structure ID or the plan pivot, matching dashboard scope. A screen-code deployment alone does not backfill saved ratios; an active-screen save recalculates only its selected plan (2026-10-18).
- Selected-period target shares use the last measurement point in-range; actual shares use the last execution in-range. Actual contributions remain included even when that task has no in-range target measurement (`ManagementDashboardService::evaluateTasks`, `resolveTaskExecutionProgress`, `PlanImpactCalculationService::summarizeMainTasks`, 2026-10-15).
- Unfiltered dashboard progress for all tasks, including `dynamic_execution`, uses stored `Task.progress`. A selected range that fully covers a task's start-to-due schedule also uses `Task.progress`, preserving the full-plan initiative total; narrower ranges calculate from the latest main-task execution inside both the selected range and task schedule. Out-of-schedule executions do not count and no eligible dynamic-task execution means 0% (`ManagementDashboardService::resolveTaskExecutionProgress`, 2026-10-19).
- Department and employee tables always display target, actual contribution, and achievement for both performance and effectiveness, regardless of the selected filters; preserve their existing calculations.
- With a detailed scope, the main plan card presents actual contribution, target, and achievement in that order as three equal side-by-side metrics without a progress bar; values remain backend-driven.
- The effectiveness card uses the same actual, target, achievement order and three-metric layout; it does not show a progress bar.
- All lower dashboard tables with performance/effectiveness metrics reuse the same three-column display ordered actual contribution, target, and achievement, with no progress bars inside those metric cells.
- Partial-period progress uses the latest execution inside the selected interval; when the interval covers a task's full start-to-due schedule, progress remains its stored `Task.progress`. Period target uses the latest measurement point inside that interval. A task without an in-period target contributes no target scope, but its in-period actual is still included (`ManagementDashboardService::resolveTaskExecutionProgress`, `TaskPeriodProgressCalculator::targetProgressAt`, 2026-10-19).
- A planned target of zero is distinct from no target: retain the allocation-weighted actual contribution, but do not include zero in target scope. Achievement rate remains zero when the total comparison target is zero (`PlanImpactCalculationService.php:20`).
- The operational-plan hero preserves its plan performance percentage and also displays the scoped allocation-weighted actual contribution, target scope, and achievement rate from `task_completion`; use this scope summary rather than inventing a second calculation.
- Employee task drilldown shows execution through the selected period (otherwise current execution), allocation ratio as plan-impact percentage, and weighted task contribution as plan-achievement percentage (`ManagementDashboardService::getEmployeeTasks`, `EmployeeDrilldownModal.vue`).
- Stored strategic/operational plan progress currently uses root-structure weighted averages in the plan-progress jobs; this can differ from the dashboard's flat main-task allocation total. Dashboard reads must not update stored plan values as a side effect.
- Recalculation of existing stored values is a separate deployment step. `dashboard:recalculate-plan-progress` refuses to run outside tenant context; use `tenants:run` for the target tenant and pass `--option=all=1` to recalculate all strategic and operational plans there. Do not run it against a live tenant without an explicit operational decision.
- The plan-task details modal is available only with a selected strategic/operational plan. It lists main tasks, dates, allocated target/actual contribution and execution; totals use the same allocated task calculation as the dashboard, and filtered execution is evaluated through the effective period date. Operational-objective rows must remain scoped to the selected operational plan.
- Period-scoped task lists include only scheduled intervals overlapping the selected range; overdue/incomplete tasks that ended before the period are not carried forward into it. Child and grandchild task counts follow the same overlap rule.
- Operational-plan and strategic-structure structural rollups use root structures only; operational children inherit the same plan/strategic-structure IDs and otherwise get counted alongside their parent (`UpdateOperationalPlanProgress.php`, `UpdateStrategicStructureProgress.php`).
- Existing stored plan values are not refreshed merely by deploying the jobs; use tenant-scoped `dashboard:recalculate-plan-progress` with one plan ID or `--all` after deployment.

## Fixes applied
- 2026-10-19: Fixed the period-plan report runtime error caused by calling an undefined `$term` variable; the Blade now uses the shared `__terms()` helper for configurable terminology (`period_plan_report.blade.php`).
- 2026-10-19: The period-plan report keeps its original report body and tables visible, with dashboard plan-performance cards added above them. Main-task target/actual/achievement and structure rollups use the matching `plan_task_details` rows by task ID; allocation shares display with the dashboard's three-decimal truncation (`period_plan_report.blade.php`). Historical comparison requests skip the extra dashboard payload. Compact team/objective boards use fixed-width name columns and reveal full names by wrapping on click (`TasksReportController::getPeriodPlanReportData`, `TeamBoardSection.vue`, `ObjectivesBoardSection.vue`).
- 2026-10-19: Confirmed a period covering the complete task schedule must preserve stored `Task.progress` and the initiative's full-plan contribution; narrower periods remain execution-based and task-date bounded. Added regression coverage for a full schedule returning 47 while a partial period uses its parent execution.
- 2026-10-19: The plan details modal now shows the raw percentage target within the selected period separately from `target_contribution` (allocation ratio × period target), so the two figures can be compared without changing dashboard calculations. Task names link directly to `/tasks?task_id=<id>` in a new tab. Coverage: `ManagementDashboardTest::test_plan_task_details_expose_period_target_separately_from_allocated_target`.
- 2026-10-12: removed stored-progress override; as-of task progress; unified dept formula; plan-linkage intersection + warning; KPI project filter; frontend filter propagation (data/drilldown/reminders); `data_quality` block. Tests: `tests/Feature/Dashboards/ManagementDashboardCorrectionsTest.php` (6 tests). All 39 dashboard tests green.

## Open issues
- No period selection resolves to all available data, while the UI placeholder reads “Active / Default Period.” The user confirmed on 2026-10-14 to keep all-data behavior.
- 2026-10-12 (filters, same version): kept 4 scope filters in one row
  (strategic → operational → department → team) + period + as-of-today;
  REMOVED priority and custom from/to dates from UI payloads and controller
  allowlist. Buttons moved below filters; active-count badge added next to the
  collapse chevron. Verified with `npm run build` (success, 1m11s).
  Backend still accepts project/status arrays for future UI.
- 2026-10-13 (objective rule, same version): delayed ⟺ overdue main-task
  count only (no dates/execution); 0 linked tasks → not_started; else
  on_track. Signature changed to (overdueCount, linkedCount); 3 tests updated.
- 2026-10-13 (new-design merge, same version): ExecutiveSituationCards
  rewritten in mockup style (hero gauge + 4 stat cards + 3 stacked task-level
  bars) bound ONLY to existing executive_summary keys; dynamic __terms kept
  for task levels. No build run (user on `npm run dev`).
- 2026-10-13 (dept table): الأداء cell now shows the triple — نسبة %
  + المستهدف (target_scope) + المتحقق (actual_contribution); الفاعلية stays
  single % (backend defines KPI actual ≡ achievement). Verified compiled
  via dev server.
- 2026-10-13 (dept shares): departments now aggregate weighted plan shares
  (target_scope=Σ allocations, e.g. 0.42–52.08, never averaged percentages);
  verified live on tenant_4 (scopes sum within overall 83.34; 33 tests green).
- 2026-10-13 (KPI shares): departments aggregate real KPI plan shares via
  new summarizeKpiShares (achievement_allocation_ratio → allocation_ratio,
  legacy weight-mean fallback); verified live (e.g. 50.19/9.23=18.4%, never
  flat 100); 2 regression tests; 35 green.
- 2026-10-13 (dept counts): المهام الرئيسية and الأنشطة cells now show
  total + overdue sub-line (red when >0), sourced from dept.overdue and
  task_levels.sub.overdue. Verified compiled via dev server.
- 2026-10-13 (objective overdue clarity): متأخر box now reads
  "X / Y" (overdue of total linked main tasks). Verified compiled via
  dev server.
- 2026-10-13 (team overdue clarity): member overdue line now reads
  "X / Y" (overdue of member's total tasks). Verified compiled via
  dev server.
- 2026-10-13 (attribution card removed): user cancelled it as unneeded;
  frontend block + computed removed (backend still returns the unused key).
  Verified compiled via dev server (zero attribution references).
- 2026-10-13 (plan cards): selected-plan stored progress is displayed only
  without detailed filters; department, employee, period, or as-of-today
  switches the primary card to computed scope contribution + target +
  achievement rate (user-confirmed). The second card remains its separate
  selected/linked plan value.
- 2026-10-13 (translation + task terms): added 11 missing ar.json keys;
  fixed case-sensitive keys to existing ones ('Current Period',
  'Previous Period', 'Overdue'); task levels now use trans('Tasks' /
  'Sub Tasks' / 'Sub Sub Tasks') which ar.json maps to :*_term placeholders
  substituted from tenant terms_list (verified values: المهام الرئيسية /
  الأنشطة / الإجراءات). Verified compiled via dev server.
- 2026-10-13 (objectives grid): 4 per row, status chips + filter pills +
  delay_reason line removed (no objective statuses); cards show symbol,
  name, plan, achievement bar, weight, task/overdue/in-progress counts.
  Verified compiled via dev server.
- 2026-10-13 (team photos): employees[] rows gained `photo` from
  `WorkTeamMember.image` (user_logo media or default.png); TeamBoardSection
  renders the photo circle with initial-letter fallback on error. Verified
  live value + compiled via dev server.
- 2026-10-13 (effectiveness card): headline now explicitly labelled
  Achievement Rate with Target/Actual-from-plan rows (KPIs), distinct from
  task-based performance. Verified compiled via dev server.
- 2026-10-13 (kpi-numbers redesign, display-only): ExecutiveSituationCards top
  section rebuilt as KPI numbers grid (right zone 960px: strategic /
  operational / effectiveness + delayed / objectives; left zone: 3 compact
  task-level rows, same content). Bullet bars replace all gauges
  (ApexCharts removed from this component; DualScopeGauges.vue +
  utils/gauge.js deleted). All cards fixed 96px; <1200px stacks to 2 cols,
  mobile 1 col.   detailed vs wide-scope primary-card display is driven by the backend
  `is_stored` flag; `showPlanScope` selects the operational-plan second card
  for department/employee scopes; `deptScope` removed.
- 2026-10-13 (plan-card scope correction): selected-plan cards use stored
  `progress` only with no detailed filter. A department, employee, period, or
  as-of-today filter keeps the plan context but switches the main value to
  computed scope contribution and shows target/achievement details. The
  operational second card remains its own selected/linked plan value.
- KPI display contract (user-locked 2026-10-13 and updated 2026-10-14,
  ExecutiveSituationCards.vue): detailed scope → main = actual_contribution,
  metrics = actual_contribution → target_scope → achievement(actual÷target,
  '—' if target≤0), no progress bar. Wide scope → main = heroMain/secondHero
  legacy value. Scope label hidden when empty/'All Available Data'
  (any case)/Arabic equivalent. No operational context → '—', no minis/bar.
  No KPIs → '—' + note, no bar. Bars show actual fill only, never a target
  marker. Numbers are always backend-computed per scope — same metric in
  different scopes legitimately differs; never hardcode, never "fix" by
  editing values.
- Executive redesign around decision questions (levels 1–5) not done — only data-quality exposure + filter wiring.
- `is_combined` card retired (always false, compat fields kept); needs product call on separate strat/op computation vs single card.
- Non-cumulative KPI period summation unverified per-indicator; `UpdateTaskProgress` polarity gap (gotcha #9).
- No Vite build run; no load testing with large task volumes.

## Session checkpoint 2026-10-13 (user: "continue later")
Decisions locked: same-version work (no V2); filters = period + 4 in one row
(strat→op→dept→team) + as-of-today, NO priority/dates; objective delayed ⟺
overdue count only; super-admin sees all custom reports; dept TASK shares and
KPI shares are weighted sums (never 100/averages); overdue always shown as
"X / Y"; task-level nouns via trans()+ar.json :*_term placeholders.
New-design sections live: hero+stat cards, stacked task bars, action center
(All/Urgent/Warnings), departments board (Performance+Effectiveness+triples),
objectives board (4-col, no statuses), team board (photos+rings), trends kept.
Pending / open threads:
- Team photo circle look ("الدائرة مش تمام") — root cause found: member
  media file 6565.jpeg missing on disk (orphaned media row); tenant disk
  root storage/app/public/tenant. Needs decision: re-upload vs hide.
- Effectiveness "45.7%"-style line in mockup has no API source — skipped.
- Old unused section components still on disk (4 files).
- Dashboard Phase A/B corrections were reverted externally before this
  session; only the items above were re-applied by explicit request.
- 2026-10-14 (actions-table redesign, display-only): departments +
  action center share one row (col-md-8 + col-md-4, departments right in
  RTL), both cards fixed 460px white / 1px #E1E9F4 / 16px radius.
  DepartmentsBoardSection.vue keeps all rows/data, only container restyle
  (scroll body + sticky thead + thin scrollbar). ActionCenterSection.vue
  rebuilt as table (التنبيه/العدد/الإجراء): sticky header, internal
  scroll, 8px scrollbar, severity side bars, dept sub-rows under the
  declining-departments alert (overdue/tasks_count red bar + فتح button
  wired to existing store.focusDepartment), footer with scroll hint +
  visible counts; empty states keep card height. Tabs kept as current
  3-way filter (الكل/حرج/تحذيرات) — backend exposes only
  critical/warning severities, so a 4th عاجل tab needs a backend urgency
  rule (offered, user didn't answer; do NOT invent). Verified via SFC
  template compile + script parse (no backend change, no phpunit).
- 2026-10-14 (action column removed + dept popup): ActionCenterSection
  table dropped the الإجراء column; row click
  still emits the same 'action' (navigation/modals unchanged). Dept
  sub-rows replaced the فتح filter button with a details popup button
  (DepartmentDetailsModal.vue, local state in the section): tasks summary
  (counts/colors/performance bar/shares) + KPI summary from the existing
  row object — summary only, NO item lists (no backend endpoint exists for
  dept task/KPI lists; building one needs backend work + approval).
  Verified via SFC template compile + script parse.
- 2026-10-14 (action-center layout): heading/count and severity tabs align left.
  Alert rows/descriptions and department subrows are compacted; the card
  no longer uses an internal scrollbar or allows horizontal overflow.
- 2026-10-14 (action-center readability): alert counts now sit beside their
  titles instead of in a narrow, far-edge table column; alert text uses the
  full available row width.
- 2026-10-14 (objectives 6+6, trend section removed): ObjectivesBoardSection
  grid changed to 2 per row (col-xl-6); PerformanceTrendSection.vue deleted
  and its section removed from ManagementDashboardApp.vue (store/API still
  return trends payload, untouched). Verified via SFC template compile +
  script parse (no backend change, no phpunit).
- 2026-10-14 (objectives as table): ObjectivesBoardSection cards replaced
  with a departments-style table (fixed 460px, sticky header, scroll):
  الهدف (badge + name + plan) / المهام (total + overdue red/gray) /
  قيد الإنجاز / الإنجاز (20px colored + weight line); same data, no new
  behavior. Verified via SFC template compile + script parse.
- 2026-10-14 (objectives + work team unified Performance/Effectiveness columns):
  Both `ObjectivesBoardSection` and `TeamBoardSection` now mirror
  `DepartmentsBoardSection` exactly:
  1. Performance (الأداء): main big number = `actual_contribution%` (المتحقق من الخطة),
     sub-line = `Target from plan X% · Achievement Rate Y%`.
  2. Effectiveness (الفاعلية): main big number = `kpi_actual_contribution%` (المتحقق من الخطة للمؤشرات),
     sub-line = `Target from plan X% · Achievement Rate Y%`.
  3. Objective code/symbol badge removed (name + plan only).
  4. Work team member photo/avatar circle removed (name + department only).
  5. Backend: `buildEmployeePerformance` now computes `kpi_target_scope`,
     `kpi_actual_contribution`, and `kpi_achievement` using `summarizeKpiShares`,
     identical to `buildDepartmentPerformance`.
- 2026-10-14 (deterministic primary attribution & hero consistency):
  1. Primary Department Attribution:
     - Eager loaded `responsibles` and `sections` relations in `buildScopedTasksQuery`
       are now explicitly sorted (`orderBy('work_team_members.id')` and `orderBy('sections.id')`).
     - `resolvePrimaryDepartmentId` and `resolvePrimaryKpiDepartmentId` now consistently sort
       relations by `id` prior to picking the primary department.
     - Fixes issue where filtering by Section 2 dropped tasks from 58 to 5 and collapsed
       actual contribution to 0.3% due to MySQL query plan join-order differences.
  2. Plan Hero Performance vs Department Actuals:
     - `ExecutiveSituationCards.vue` now displays `actual_contribution` consistently for both wide
       and detailed scopes (e.g. Strategic Plan shows actual 45.61%, target 83.34%, achievement 54.7%),
       resolving the discrepancy where department contribution (21.2%) exceeded stale stored plan progress (20.05%).
  3. Effectiveness Top Card:
     - Backend now returns `target_scope` and `actual_contribution` in `executive_summary.kpi_achievement`
       via `summarizeKpiShares`.
     - `ExecutiveSituationCards.vue` now dynamically updates the Effectiveness card to reflect the
       filtered scope's actual contribution, target scope, and achievement rate (e.g. 28.4% actual, 93.8% target, 30.3% achievement),
       matching the departments table.

## Verification note 2026-10-14
- Executive cards now present actual contribution first, target second, and achievement third for both strategic performance (detailed scope) and effectiveness; the effectiveness card reuses the three-metric layout without its former progress bar. The objectives table uses a fixed smaller name style and fixed column widths to keep figures readable.
- Date handling fix: current and drilldown task execution progress now uses the latest execution through the effective period end (initial KPI value if none); KPI target/actual calculations no longer fall back to all-time values when a filtered window has no observations; as-of-today clips task scope and trend periods. `buildEmployeePerformance` now scopes its roster to the selected `current_section_id`. Regression tests live in `tests/Feature/Dashboards/ManagementDashboardTest.php`.
- Current checkout's `ManagementDashboardService` response does not contain `data_quality` or `plan_linkage`, despite earlier entries in this file.
- `ManagementDashboardCalculationsTest::test_kpi_negative_polarity_calculation` previously encoded a handwritten 33.3% formula that differed from production `KpiCalculatorService::calculateAchievementFromActual` (60% for target 3000 / actual 5000; 120% for target 3000 / actual 2500). The test now exercises the production calculator. No production calculation was changed.
- `PlanImpactCalculationServiceTest` now fixes a 20% actual-contribution example as a regression expectation.
- `ManagementDashboardService::calculateKpiPerformance` delegates to `KpiPerformanceCalculator`; the legacy public method and its response keys/rounding remain unchanged. Period target/actual calculation, polarity, and acceptable-deviation behavior are covered by focused regression tests.
- 2026-10-14: Removed inline Arabic text from the management dashboard Vue components and `ManagementDashboardService`; keep user-facing Arabic in `resources/lang/ar.json` and English in `resources/lang/en.json`. The dashboard source should use translation keys, not Arabic `$t` fallbacks (the second `$t` argument is ignored).
- 2026-10-14: Employee drilldown modal follows `task-modal-redesign.png`: employee identity, task/KPI summary cards, task/KPI tabs, all/overdue task filter, overdue-vs-other task groups, compact task rows, with existing print/reminder events retained. Its plan actual label is “Actual from plan”; plan values display truncated to two decimals with trailing zeros removed (zero displays as `0`).
- 2026-10-14: Employee task-row labels map `task.level` values 1/2/3 to dynamic `Task` / `Sub Task` / `Sub Sub Task` terms. Use task-term placeholders and configured term slugs across dashboard labels; numeric depth is internal and must not appear as “Level 1/2/3”.
- 2026-10-14: Employee drilldown summary shows separate counts for task depths 1/2/3 followed by indicators; its tabs show all tasks, overdue tasks separately for each depth, and indicators. Keep task terminology dynamic and the toolbar wrapped without horizontal scrolling. Objective and employee overview tables omit a separate in-progress column; show total tasks with overdue directly beneath. Department breakdowns retain their existing status details.
- 2026-10-14: Employee task lists must not introduce a nested scroll region; long content scrolls with the modal overlay instead.
- 2026-10-14: Management dashboard has a separate quick-overview tab and detailed-tables tab. Quick charts consume the same filtered dashboard response; they do not add formulas or API requests.
- 2026-10-14: Action Center header separates its title/count from the severity filters so the narrow dashboard column does not compress them into one row.
- 2026-10-14: In declining-department subrows, keep the overdue-count badge beside the department name instead of against the card's left edge.
- 2026-10-14: Department details use the matching modal layout, with summary cards and performance/effectiveness details; print is available and no reminder action is shown.
- 2026-10-14: Dashboard table headers use a section icon beside the title and a pill badge for the section count across departments, objectives, and work team.
- 2026-10-14: Keep management-dashboard filters compact by pairing period and as-of-today controls on one row; render detailed/quick-view tabs in the filter card footer, available even while the filter fields are collapsed.
- 2026-10-14: Quick-overview department, objective, and employee charts keep achievement rate in the gauge and display the existing plan actual contribution and target scope as separate percentage values.
- 2026-10-14: Department quick-overview cards show main-task total beside overdue count in the footer; do not show the activities/subtasks count there or duplicate the task total above.
