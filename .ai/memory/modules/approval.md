# Module memory: generic approvals

> One file per module/area. Keep short; link to skills, don't repeat them.
> Last reviewed: 2026-10-20.

## What it is
Reusable approval request lifecycle for tasks, KPIs and future entities. Tables:
`approval_requests`, `approval_request_data` (versioned JSON), `approval_decisions`.
Models live in `app/Models/Approval*.php`; lifecycle logic lives in
`app/Services/Approval/ApprovalService.php`.

## Confirmed rules
- A decision is bound to the request and the exact data version by the composite FK
  `(approval_request_id, approval_request_data_id)` → `(approval_request_id, id)`
  (`database/migrations/tenant/2026_10_20_000003_create_approval_decisions_table.php:28`).
- `ApprovalService::create()` always writes version 1 and returns an existing active
  request for the same non-null `(entity_type, entity_id, action)` when status is
  `pending` or `returned`. `wasRecentlyCreated` distinguishes a new request from a reused one.
- Requests with `entity_id = NULL` (creation requests) are never deduplicated.
- Active-request creation uses a tenant-database-scoped MySQL/MariaDB `GET_LOCK`
  advisory lock around the transaction, plus `lockForUpdate()`. The lock is acquired
  and released on the same write PDO, and is released after commit/rollback, including
  when `create()` runs inside an outer transaction. Acquisition failure throws
  `RuntimeException`; it never continues silently.
  The lock key uses the actual `approval_requests.entity_type`/`action` column collation
  and `WEIGHT_STRING`, so case/accent-equivalent values share one lock, matching the
  duplicate query. There is still no DB unique constraint; writers that bypass the
  service can duplicate. True parallel creation is covered by `ApprovalConcurrencyTest`.
- Partial savepoint rollback is handled by `afterRollBack` on the deepest pending
  transaction: rolling back before the create releases the lock, while rolling back a
  later savepoint keeps it until the outer transaction ends. Reentrant creates on the
  same connection are reference-counted by MariaDB; each acquisition has one matching
  release after the owning transaction ends.
- Eligibility is enforced in the service, not in policies, because `BasePolicy::before`
  lets Super Admin bypass policy checks (`app/Providers/AuthServiceProvider.php`). A
  Super Admin must still match the target/assignment. `canDecide()` and `decide()` share
  the same `isEligible()` rule.
- Role targets use live membership at decision time. A concrete
  `assigned_approver_id` narrows a role target to that member.
- Decisions record the latest data version, actor, notes and time; they never apply the
  proposed entity change.
- Only the requester can resubmit a `returned` request; resubmission adds the next data
  version, sets status `pending` and clears `decided_at`. The request row is locked and
  the unique `(approval_request_id, version)` constraint protects version conflicts.
- Only the requester can cancel, and only `pending` or `returned` requests. A non-empty
  reason is required, stored in `metadata.cancellation_reason` while preserving other
  metadata keys. Cancellation sets `status=cancelled`, `cancelled_by`, `cancelled_at`,
  creates no decision row, and is terminal for decide/resubmit. A cancelled request no
  longer blocks a new request for the same entity/action.
- Decisions, cancellations and resubmissions are serialized by `lockForUpdate()` and
  rechecked after locking; parallel decision/resubmission races are covered by
  `ApprovalConcurrencyTest` and its support workers.
- Approval tests use an isolated MariaDB database created/dropped per test
  (`tests/Feature/Approval/ApprovalDatabaseTestCase.php`); never use the app DB.

## API (2026-10-20)
- Permissions: category `Approval Management`, module `Approval Requests` with actions
  `view` and `approval_action` in `resources/permissions.php`. The module `model` is the literal
  string `Approval Requests`, so the seeder generates the exact abilities without a policy.
  There is no `store`, `create`, or `edit` permission in this module.
- Routes in `routes/tenant.php` under the authenticated tenant group follow the Meetings pattern:
  `ApprovalRequestController` extends `CRUDController` for the web interface (Metronic Datatables via `ApprovalRequestRepository::getDatatableQuery`, and show view via `tenant.approvals.show`). Resource is scoped strictly to `['index', 'show']` (no add/store/edit).
  `ApprovalRequestApiController` extends thin `Controller` (like `MeetingApiController`) for REST/JSON endpoints (`mine`, `{approvalRequest}/detail`, `decision`, `resubmit`, `cancel`).
- `ApprovalRequestRepository` owns read scoping: inbox uses the incoming rule (assignment
  null/actor + user/dynamic target actor or current role membership); mine uses
  `requested_by`; show/detail uses requester OR incoming. It also provides `getDatatableQuery()`
  for server-side Datatables. All operations stay on the tenant default connection.
- `view Approval Requests` gates read/list/datatable/show/mine/resubmit/cancel; `approval_action Approval Requests`
  gates decisions (approve/reject/return). Lifecycle rules remain in `ApprovalService`.
- Duplicate creation reuses the service result: visible duplicates return 200 with the
  existing request; invisible duplicates return a generic 409 without `data`.
- Permission tests extend `ApprovalDatabaseTestCase` and add Spatie permission tables
  locally (`tests/Feature/Approval/ApprovalApiTestCase.php`). HTTP tests use
  `URL::forceRootUrl('http://localhost')`; without it the project subdirectory makes the
  test URL `.../plamx-v-5/public/approval-requests` and every request 404s.

## Fixes applied
- 2026-10-20: Phase 0 schema hardening + Phase 1 models + Phase 2 approval service.
  Cancellation was added with requester-only, active-state and reason rules. Collation-
  aware lock keys and partial-rollback coverage were added in the review pass.
  Covered by `tests/Feature/Approval/ApprovalSchemaTest.php`,
  `ApprovalModelsTest.php`, `ApprovalServiceTest.php`, and
  `ApprovalConcurrencyTest.php` (60 tests, 310 assertions).
- 2026-10-20: Phase 3 tenant JSON API + permissions + repository scoping. Covered by
  `tests/Feature/Approval/ApprovalApiTest.php` (18 tests, 113 assertions); full approval
  suite is 78 tests / 423 assertions.
- 2026-10-20: Phase 4 Approval Automation System & UI:
  Dedicated tenant tables: `approval_automation_rules` and `approval_automation_runs`.
  Core engine: `App\Services\Approval\Automation\ApprovalAutomationEngine` evaluating triggers,
  conditions (via `TriggerEvaluator`, `ConditionEvaluator`, `TaskSectionResolver`), and executing
  approval request creation via `ApprovalService::create(...)` without mutating the entity at trigger time.
  Observer: `TaskApprovalAutomationObserver` listening to task created/status/priority/due date events.
  Web & API: `ApprovalAutomationRulesController` & `ApprovalAutomationRunsController` under
  `/approval-automations` in `routes/tenant.php`.
  Permissions: Secured by existing `view Approval Requests` and `approval_action Approval Requests`
  without adding or modifying `resources/permissions.php`.
  Frontend: Vue 3 + PrimeVue + Aura + Pinia app in `resources/js/approvalAutomationsApp/`
  with active/inactive tabs, rule CRUD dialog with trigger & conditions builder, execution history tab
  with run inspect modal and manual trigger test dialog.
- 2026-10-21: Phase 1-4 Architecture cleanup, bug fixes & comprehensive test coverage:
  - Restricted `ApprovalAutomationRulesController` strictly to approval rules (throws 404 ModelNotFoundException if attempting to view/edit/delete/trigger generic automation rules).
  - Linked `automation_runs.approval_request_id` with nullable index and safe migration (`2026_10_21_000001_add_approval_request_id_to_automation_runs_table.php`), with reciprocal Eloquent relationships on `AutomationRun` and `ApprovalRequest`.
  - Unified deduplication event key generation: added status and priority transition discriminators to prevent false deduplication on subsequent valid task updates while preventing duplicate processing between engines.
  - Form Requests created under `app/Http/Requests/Approval/` for thin controllers, validating scopes, pagination, status, trigger/condition payloads, and decisions.
  - Created shared scope trait `HasApprovalAutomationScope` for permission checking and department/section isolation.
  - Comprehensive unit test suite in `tests/Unit/Approval/` (36 tests, 98 assertions):
    - `ApprovalAutomationRuleScopingTest`: 8 tests for approval rule isolation, 404 on generic rules, section isolation (403), view/action permissions.
    - `ApprovalLifecycleAndRelationsTest`: 7 tests for dynamic targets eligibility, return notes validation (422), model relations (ApprovalRequest, AutomationRun, ApprovalDecision, ApprovalRequestData).
    - `ApprovalDeduplicationAndEngineUnificationTest`: 4 tests for event key discriminators, rule scope separation, run request foreign key.
    - `ApprovalRequestRepositoryVisibilityTest`: 5 tests for repository datatable query scoping (`mine`, `inbox`, `all`) and status/action filtering under tenant context.
    - `ApprovalAutomationEngineUnitTest`, `ApprovalServiceTest`, `TaskApprovalAutomationObserverTest`.
  - All 21 approval routes verified via `route:list`, all modified files passed PHP lint and Pint formatting without errors.
  - Task approval lifecycle on creation & decisions:
    - Default on creation: task is created with `approval = true` if no matching approval automation rules exist.
    - If a matching approval automation rule exists: task is created with `approval = false` from the start (`TaskApprovalAutomationObserver::creating`).
    - In `TaskApprovalAutomationObserver::created`: if active approval request was created (`$hasActiveApproval`), task remains `approval = false`. If candidate rules existed in creating but none created an approval request (conditions not met), task reverts to `approval = true`.
    - On decision: when approval request for a Task is approved (`ApprovalService::decide` with outcome `approved`), `ApprovalService::applyApprovedState` automatically sets `$task->approval = true` and saves quietly. Rejection, return, or cancellation leaves `$task->approval` as `false`.
- 2026-10-21: Phase 5 Evidence Upload (الشواهد) Approval Cycle:
  - Table: `results`, column `approval` (boolean, default true).
  - Migration: `2026_10_21_000002_add_approval_to_results_table.php` safely sets all pre-existing evidences to `approval = true`.
  - Observer: `ResultApprovalAutomationObserver` listening to `creating` and `created` on `Result` model.
    - `creating`: if attached to Task and candidate rules exist for `task.evidence.added` (or aliases), sets `approval = false`; otherwise `approval = true`.
    - `created`: runs `ApprovalAutomationEngine::processEvent('task.evidence.added', $result, ...)`.
      - If active request exists or new request created: remains `approval = false`.
      - If request creation fails (`error`): remains `approval = false` (never falsely approves).
      - If rule conditions/triggers not met: reverts to `approval = true`.
  - Decisions: `ApprovalService::applyApprovedState` automatically transitions `Result::approval = true` when outcome is `approved`. Rejection, return, or cancellation keeps it `false`.
  - Engine: handles `Result` entity type, resolves parent `Task`, evaluates task conditions, builds title with terms and evidence/task names, and records snapshots.
- 2026-10-23: Phase 6 Task Status Change Approval, Quality Pass (`quality`), and Final Closure (`closed`):
  - Quality source of truth: `Task.quality` (boolean in DB). `isQualityPassed()` and `is_quality` (virtual accessor) act as readable contract without duplicate columns or migrations.
  - Strict Decoupling: Status transitions, progress completion (100%), and automation runs never automatically set `quality = true` or `closed = true`.
  - Approval application fix: `ApprovalService::applyApprovedState` removed early return on matching proposed status, ensuring `quality = true` (on quality actions) and `closed = true` (on closure actions) are applied properly even on retries or pre-applied states.
  - Model and Route Hardening:
    - Direct updates setting `closed = true` or altering closed tasks (`closed = true`) are blocked via `Task::booted()` throwing `ValidationException` unless inside scoped `Task::$isApplyingApprovalState = true`.
    - Deletion of closed tasks is strictly blocked.
    - Tenant HTTP endpoints (`/tasks/update-status`, `/tasks/bulk-actions`, `/tasks/update-custom/{id}`, `/tasks/sub-tasks/{id}`, `/tasks/executions`, `/results`, `/tasks/{id}/dependencies`, `/tasks/responsibles/add-responsible`) return 422 for closed tasks, while read access (`view`, policy) remains allowed.
  - Test Suite: `TaskStatusChangeApprovalTest` (16 tests, 74 assertions), bringing full `tests/Unit/Approval` suite to 70 tests / 225 assertions passing cleanly.

## 2026-10-24 verification (current worktree) — corrections

The entries above are **not all true in the current tree**. Re-verified by reading code and running the suite:

- **No dedicated approval tables.** Code uses generic `automation_rules` / `automation_runs`, filtered by `AutomationRule::approvalRules()`. There is no `ApprovalAutomationRule` model or `approval_automation_*` table.
- **No approval tests are tracked in git.** `git ls-files tests` has zero Approval files. Only an untracked `tests/Feature/Approval/ApprovalPhase3RepairTest.php` exists (15 tests) and it currently **fails 15/15 with 0 assertions**: `SQLSTATE[HY000]: no such table: account_details` — `AppServiceProvider::register()` resolves the `sharedData` singleton (`AccountDetail::first()`), which the test schema never creates. The `tests/Unit/Approval/*`, `ApprovalDatabaseTestCase`, `ApprovalApiTestCase` and `ApprovalConcurrencyTest` referenced above do not exist here.
- **`approval_request_id` column now exists** (`database/migrations/tenant/2026_10_21_000001_add_approval_request_id_to_automation_runs_table.php`), and `ApprovalAutomationRunsController::getRuns()` reads `$run->approval_request_id ?? execution_context[...]`. Earlier "column missing" claims are stale. Still verify the migration was applied to every tenant DB at runtime.
- **`$canDecide` is permission-gated** (`ApprovalRequestController:261-263`: `can('approval_action Approval Requests')` + `ApprovalService::canDecide`).
- **`show.blade.php` handles HTTP errors** (`handleResponse` throws on `!res.ok`).
- **Runs list is section-scoped** (`ApprovalAutomationRunsController:25-40`, `availableSectionIds()` + approval rules), with explicit `whereNull('section_id')` inclusion.

### Still open (approval module)
- `resubmit` **drops notes**: UI sends `notes`, but `ResubmitApprovalRequestRequest::rules()` omits it and `ApprovalService::resubmit()` has no notes parameter/column write.
- `ApprovalAutomationRulesController::manualTrigger` (`:288`) resolves the task by raw ID with no task/section access check (same class as SEC-11 in `security-audit.md`).
- Null-section approval rules are visible/operable tenant-wide by any `approval_action` holder (`HasApprovalAutomationScope:61-71`, `availableSectionIds()` includes null).
- One permission (`approval_action Approval Requests`) covers both deciding requests and managing automation rules — still an open product decision.
- `automation_runs.event_key` unique index includes nullable `subject_task_id`; MySQL permits duplicate NULLs, so runs without a task are not fully protected.
- Test coverage must be restored before any further approval refactor: fix the test bootstrap (create/mock `account_details` or bind a fake `sharedData`) and re-add the observer/service tests.

## 2026-10-24 critical blocker repairs

Verified against code and covered by tracked tests:

- **Dedupe is transition-aware.** `ApprovalService::create()` reuses an active request only when
  the snapshot fields that the action actually applies are equal (`status_id` for
  `status_change`/`task_approval`, `approval`/`quality`/`closed` for the others). Different
  proposed transitions may coexist as active requests; same operation still dedupes.
  The engine dedupe window also compares the transition signature in `execution_context`, so a
  different status transition is not swallowed by a recent run of the same rule.
- **Closure approval enforces dependencies.** `validateCanApplyApprovedTaskState()` keeps
  `validateClosurePrerequisites()` and additionally calls
  `TaskStatusValidationService::validateStatusChange($task, Closed)`; no parallel logic.
- **Evidence fail-open fixed.** Only `SKIP_TRIGGER_NOT_MET` / `SKIP_CONDITIONS_NOT_MET` may
  set `Result.approval = true`. Disabled config, cascade, dedupe, active-request and unknown
  skips stay unapproved. `creating()` now defaults to `false` and only flips to `true` after
  confirming no matching rule; exceptions stay `false`.
- **Section matching uses `TaskSectionResolver::sectionIdsForTask()`.** `hasMatchingRules()`
  accepts `int|array|null`; engine section resolution merges `task.section_id` and all
  `tasks_sections` pivot rows for tasks and evidence.
- **Global rules (section_id = null) are manageable only by unrestricted scopes**
  (super admin or `organizational_scope = 'all'`). Read/list/show remains unchanged and still
  includes global rules. `manualTrigger` remains outside this restriction (see open list).
- **Rule edit contract fixed.** `ApprovalRuleForm.vue` preserves `status_change`/`task_approval`
  (only the current value is added to the dropdown when editing) and round-trips
  `conditions_config` instead of sending `[]`.
- Tests: `tests/Feature/Approval/ApprovalWorkflowTestCase.php` (shared SQLite bootstrap),
  `ApprovalPhase3RepairTest.php` (24 tests) and `ApprovalBlockerRepairsTest.php` (18 tests);
  42 passed / 102 assertions. Full suite: 126 passed, 2 pre-existing failures
  (`ExampleTest` expected 302/404, `ScheduleTriggerTest` class namespace).
- `npm run build` still fails on a pre-existing duplicate closing tag in
  `resources/js/tasksApp/components/items/TaskDetails/References.vue`; the changed
  `ApprovalRuleForm.vue` compiles with `@vue/compiler-sfc`.

### Still open after 2026-10-24
- Direct `approval` writes outside the approval flow (normal update, bulk, MCP) are deferred to
  the Execution Automation phase.
- `manualTrigger` still resolves tasks by raw ID without a task/section access check.
- Global rules remain readable by limited-scope users (management is now restricted).
- `resubmit` still drops notes; one permission still covers deciding and rule management.

## 2026-10-26 Task Progress & Execution Approval Automation
- **`task.progress.changed` wired across engine, normalizer, and evaluator:**
  - Added `task.progress.changed` to `AutomationCapabilities`, `AutomationRuleConfigNormalizer` (aliases: `task_progress_changed`, `progress_changed`), and `TriggerEvaluator`.
  - `evaluateTaskProgressChanged()` supports range matching `[from_progress, to_progress]`, lower-bound `from_progress`, and target-reach `to_progress`.
- **`TaskApprovalAutomationObserver::handleProgressUpdating()` implemented:**
  - Intercepts progress changes when dirty in `updating` hook (invoked when `UpdateTaskProgress` saves the task upon recording or modifying a `TaskExecution`).
  - Enriches context with latest execution data (`execution_id`, `execution_value`, `progress_value`, `execution_date`, `work_team_member_id`).
  - Sets `$task->approval = false` and `$task->has_pending_approval = true` when an approval rule matches and creates an approval request.
  - Snapshot extraction records both `original_data.progress` and `proposed_data.progress`, plus execution metadata.
  - Approving the request through `ApprovalService::decide()` restores `$task->approval = true`.
- **Requester Resolution Fallback:**
  - `ApprovalAutomationEngine::resolveRequester()` now falls back gracefully to `context.work_team_member_id` user mapping, task assignees, or tenant fallback user when running headless/CLI/queue.
- **Automated Tests:**
  - `tests/Feature/Approval/TaskProgressApprovalAutomationTest.php` covers matching progress range, non-matching range, and approval lifecycle (3 tests, all passing).


