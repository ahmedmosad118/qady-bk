---
name: security
description: Security rules for Plamx. Use for auth, permissions, validation, file uploads, secrets, API exposure, tenant isolation, logging and AI/LLM features.
---

# Security in Plamx

## Always
- Backend authorization is authoritative (policies/permissions/middleware). UI hiding is cosmetic.
- Every endpoint: authenticated, tenant-scoped, permission-checked, validated.
- Object-level access (IDOR): a user only accesses records they are allowed to, within their tenant.
  Never load by id alone when scoping is required.
- Validate with FormRequests; use `validated()` only; whitelist fields (never mass-assign `role`,
  `tenant_id`, `is_admin`, or status fields users should not set).
- Queries: bindings only; no raw SQL with input; whitelist sortable/filterable columns.
- Output: API Resources hide sensitive fields (passwords, tokens, internal ids, other tenants' data).
- XSS: `{{ }}` in Blade; `v-html` and `{!! !!}` only with sanitized trusted content.
- CSRF intact on web routes; rate-limit login, password reset and expensive/AI endpoints.
- Files: validate mime + size + extension, store via tenant Storage with random names, never trust client names.
- Secrets: never in code, logs, responses, commits or tests. Use `config()`, not `env()`, outside config files.
- Logging: no passwords/tokens/PII/sensitive prompts.
- Errors: no stack traces or SQL in production responses.

## AI features (PlamxIntelligenceController, GeminiProvider, Ollama, UnoRouter)
- Treat user text and retrieved data as untrusted (prompt injection). Model output must never trigger
  actions, SQL or code without validation and permission checks.
- Send the model only data the current user/tenant may see. No cross-tenant context in prompts.
- API keys stay server-side.
- Keep the provider abstraction; don't hard-code providers.

## Dependencies & config
No new packages without need. Don't touch `.env`, keys or deployment config.

## Review questions
1. Who can call this? 2. In which tenant? 3. On whose records? 4. What can they send? 5. What do we return?
6. What do we log? If any answer is unclear, fix it or ask.
