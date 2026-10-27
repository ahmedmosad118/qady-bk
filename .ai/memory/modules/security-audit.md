# Security Audit — PLAMX V5

> Source-only audit run on **2026-10-23** (Cloudflare security-audit methodology, adapted; 9 parallel coverage hunters).
> No target code was executed; findings are source-grounded. Re-check anything marked `needs_validation` against the production runtime before closing.
> Full report (outside repo): `C:\Users\Admin\AppData\Local\Temp\opencode\security-audit-plamx\run-1\REPORT.md` — copy it somewhere durable before it is cleaned.
> Status values: `open` / `fixed` / `deferred` / `rejected`. Update this file when an item is fixed.

## Fix backlog — Critical

| ID | Status | Issue | Evidence |
|----|--------|-------|----------|
| SEC-01 | open | MCP endpoint has no authentication; meeting tools bypass controller authorization (`authorize()` returns `allow()`) and mutate any tenant's meetings. | `routes/ai.php:9-14`, `app/Mcp/Servers/PlamxServer.php:116`, `app/Mcp/Controllers/MeetingController.php:10-14` |
| SEC-02 | open | `OnboardingDataImportService` truncates the whole tenant dataset; any authenticated user can POST the import. | `app/Services/OnboardingDataImportService.php:34,712-738`; `app/Http/Controllers/Tenant/OnboardingController.php` |
| SEC-03 | open | Role update allows arbitrary permission sync + `is_super_admin` toggle for anyone holding `update Role` → vertical escalation past `BasePolicy::before`. | `app/Http/Controllers/Tenant/Settings/RolesController.php:163-184`, `app/Policies/BasePolicy.php:15-18` |
| SEC-04 | open | Path traversal: `business_field` interpolated into `resource_path(.../{field}.json)` with `required\|string` only. | `app/Http/Controllers/Tenant/OnboardingController.php:80-107` |
| SEC-05 | open | Live Admin-Gate key committed as `env()` fallback and duplicated in helpers. | `config/services.php:34`, `app/Http/Helpers/AdminGateHelper.php:19`, `app/Helpers/AdminGateHelper.php:403` |
| SEC-06 | open | Unauth `test` route returns all soft-deleted tasks (all columns). | `routes/tenant.php:203`, `app/Http/Controllers/Tenant/PlatformController.php:141-148` |
| SEC-07 | open | KPI executions/measurement points have no `authorize()`/ownership; values unbounded and `culimative` client-controlled. | `app/Http/Controllers/Tenant/Kpi/KpisExecutionController.php:30-137`, `app/Http/Requests/Kpi/KpiExecutionRequest.php:9-21`, `app/Http/Controllers/Tenant/Kpi/MeasurementPointsController.php` |
| SEC-08 | open | `TaskService::saveTaskData` reads `approval` from request → self-approval bypass. | `app/Services/TaskService.php:51` |
| SEC-09 | open | SaaS-Gate single static key can update/delete/rename any tenant and mirror admin credentials; CSRF exempt. | `app/Middleware/CheckSaasGate.php:26-40`, `app/Http/Controllers/SaasGate/SG_AccountsController.php:169-231`, `bootstrap/app.php:93` |

## Fix backlog — High

| ID | Status | Issue | Evidence |
|----|--------|-------|----------|
| SEC-10 | open | IDOR on file downloads: references, task custom-field files, meeting attachments, support `?path=` proxy — none scope to owner/participant. | `ReferencesController.php:178-207`, `TasksController.php:884-891`, `Meetings/MeetingApiController.php:620-633`, `SupportController.php:337-360` |
| SEC-11 | open | Approval automation `manualTrigger` resolves task by raw ID without task/section access check (generic Automation module does it correctly). | `Approvals/ApprovalAutomationRulesController.php:288`, `app/Http/Requests/Approval/ManualTriggerApprovalRuleRequest.php:9-21`; reference `app/Automation/Http/Controllers/ManualTriggersController.php:112-130` |
| SEC-12 | open | Rate limiting and security middlewares are dead code (registered aliases, zero route usage). | `bootstrap/app.php:103-116`, `routes/api.php:18` |
| SEC-13 | open | Task status changes gated only by global `update Task` permission; no owner/assignee check. | `app/Policies/TaskPolicy.php:40-49`, `Tasks/TasksController.php:915-992` |
| SEC-14 | open | Tenant switch logs into any tenant where an account shares the same email; no invitation/membership proof. | `app/Http/Controllers/Tenant/Auth/TenantSwitchController.php:13-34` |
| SEC-15 | open | Stored XSS in tenant chat (`v-html` on raw body, no sanitizer) and chat search; same pattern on all rich-text fields; login customization `{!! !!}` pre-auth. `InputSanitization` middleware exists but is registered nowhere. | `resources/js/chatApp/components/conversation/MessageBubble.vue:17,109-127`, `chatApp/components/info/InfoPanel.vue:146,641-647`, `resources/views/tenant/auth/login-custom.blade.php:65,116,637` |
| SEC-16 | open | `updateApps` is a state-changing GET with no permission/CSRF protection. | `routes/tenant.php:970`, `Tenant/General/AccountAppsController.php:112-123` |
| SEC-17 | open | Escalation manager writes arbitrary `priority`/`status`/`due_date` from request; bypasses closed-task rules. | `app/Services/EscalationService.php:312-372` |
| SEC-18 | open | Pre-auth email→tenant enumeration on central login + subdomain probing; only IP rate limit. | `app/Http/Controllers/Central/Auth/AuthController.php:141-219` |
| SEC-19 | open | `copy-strategic-plan` unauth GET guarded only by an early `return false;`. | `routes/tenant.php:200`, `Tenant/PlatformController.php:35-38` |

## Fix backlog — Medium (grouped)

- **SEC-20** Shared/state races: `task_executions` has no unique index + check-then-act (no `lockForUpdate`); automation event key has no unique index; NULL-entity dedupe collapses requests. (`TasksController.php:969-979`, `TasksExecutionController.php:41-66`, `ApprovalAutomationEngine.php:147-180`, `ApprovalService.php:132-134`).
- **SEC-21** Unbounded cost inputs: bulk `task_ids` has no max; Excel import has no size/mime rules and loads all rows in memory; Excel reports build all data in memory; notification fan-out sends paid SMS inline without queue; list endpoints allow unlimited `perPage`/dump `->get()`. (`TasksController.php:1322-1326`, `ExcelController.php:200-315`, `TasksReportController.php:1970-2085`, `NotificationSystem.php:84-127`, `ProjectRepository.php:143-160`, `TaskRepository.php:11-32`).
- **SEC-22** Tenant isolation: cache isolation relies on tags while default cache driver is `file`; verify production driver. (`config/cache.php:25`, `config/tenancy.php:31-33,91-93`).
- **SEC-23** Cross-record writes: stakeholder-analysis import updates any `stakeholder_id`; dashboard datasource dispatcher has no authorization; KPI/recommendation controllers overwrite/delete by raw IDs. (`StakeholdersAnalysisImport.php:127-152`, `Dashboards/DashboardDataSourceController.php:20-35`, `Kpi/KpiRecommendationController.php:10-55`, `StrategicAnalysis/StrategicRecommendationController.php:12-41`).
- **SEC-24** Configuration: CORS wildcard `api/*`, CSRF exemption `tasks/update-custom/*`, session cookie `secure` depends on `APP_ENV`, `composer audit` blocking disabled. (`config/cors.php:18-32`, `bootstrap/app.php:66-69,92-94`, `config/session.php:174`, `composer.json`).
- **SEC-25** Shared-record deletes / silent authz errors in bulk delete loops (Meeting delete permission-only; task bulk destroy swallows `AuthorizationException`). (`app/Policies/MeetingPolicy.php`, `TasksController.php:893-913`).
- **SEC-26** Repo hygiene: tenant-1 user image tracked in `public/`, `public/build/` unignored, vendored source maps, `.DS_Store`. (`public/tenant1/...jpg`, `.gitignore:3`).

## Fix sequence (as agreed)

1. **Phase 1 (small changes, highest risk first):** SEC-01, SEC-02 (add ability/permission + confirmation), SEC-03, SEC-04, SEC-05, SEC-06, SEC-08, SEC-09, SEC-07.
2. **Phase 2:** SEC-10..SEC-19 + XSS sanitation + rate limits + ownership policies.
3. **Phase 3:** SEC-20..SEC-26 (indices, bounds, caching driver, config hardening, hygiene).

## Verification notes (do not forget)

- Items confirmed from source can still need a runtime check before marking `fixed`; the audit did not execute code.
- One subagent's raw output was partially corrupted; its extracted findings are recorded above (SEC-20/21/22/24). Re-open that hunter if something looks missing.
- No SQL injection was found: all 94 `DB::raw`/`whereRaw` hits reviewed were whitelist-driven. Do not re-litigate without new evidence.
- No secrets/.env/.sql dumps tracked in git. Keep it that way when cleaning SEC-26.
