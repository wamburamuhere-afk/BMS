# Platform AI — one BJP key for every tenant (plan)

Status: **DRAFT, waiting for approval** · Branch `feat/platform-ai` (worktree `bms-ai`, off `origin/develop`) · 2026-10-25

## 0. What exists today (verified in code)

| Piece | Where | Fact |
|---|---|---|
| AI engine | `core/ai_service.php` | `aiSettings()` reads **tenant** `system_settings` (`ai_enabled`, `ai_provider`, `ai_model`, `ai_api_key_enc`, `ai_base_url`, `ai_monthly_cost_cap`, `ai_temperature`). `aiComplete()` checks the cap, calls the provider and logs to tenant `ai_usage_log`. It never throws. |
| Tenant config page | `app/constant/settings/ai_settings.php` + `api/ai/save_ai_settings.php` + `api/ai/test_ai_config.php` | Admin only. The key is encrypted with `core/crypto.php`. |
| Superadmin "AI Assistant" | `core/feature_registry.php` → feature `ai_assistant` | **On/off entitlement only**: it has no key, provider or model. Plans can tick it. |
| Callers (9) | `api/ai/ask.php`, `generate.php`, `monthly_summary.php`, `test_ai_config.php`, `api/ai_audit_analysis.php`, `app/activity_log.php`, `ai_assistant.php`, `app/includes/ai_generate.php`, `core/notify.php` (digest), `header.php`, `includes/help_panel.php`, `core/help_nav.php` | All of them go through `aiConfigured()` / `aiComplete()` / `aiCapInfo()`. |
| Platform settings store | `core/platform_settings.php` | `bms_control.platform_settings`. The SMTP password is **already** stored encrypted there with the same `crypto.php`, so storing a platform key the same way is safe. |
| Encryption secret | `includes/ai_app_secret.php` (gitignored, one per server) | All tenants on one server share it. **Risk:** if the file can't be written, the fallback key is derived from `DB_NAME`, which **differs per tenant**, so a platform key encrypted under it would not decrypt for other tenants. |

**Gaps found**
- G1. Every tenant needs its own paid key, so "Ask BMS" doesn't work for most tenants.
- G2. `aiConfigured()` ignores module entitlement. A tenant whose AI module is restricted in superadmin still gets AI (e.g. the notification digest in `core/notify.php`) if a key is present.
- G3. `ai_base_url` is accepted with no checks (SSRF: an admin could point it at an internal address).
- G4. Superadmin has no way to see AI usage or cost across tenants.

## 1. Decisions

- **D1 Key source per tenant**, resolved in this order:
  1. Superadmin policy row for the tenant (`platform` | `own` | `off`).
  2. No row → if the tenant already has its own enabled key, use `own` (so BJP Tenant #1 and early adopters keep working untouched); otherwise use `platform`.

  The rule is based on criteria only: no hard-coded tenant ids.
- **D2 Entitlement first.** AI is available only if `tenantFeatureEnabled('ai_assistant')` **and** the resolved source is configured. This fixes G2 everywhere at once, because every caller already goes through `aiConfigured()`/`aiComplete()`.
- **D3 Platform config** lives in `platform_settings`: `ai_platform_enabled`, `ai_platform_provider`, `ai_platform_model`, `ai_platform_api_key_enc`, `ai_platform_base_url`, `ai_platform_temperature`, `ai_platform_tenant_cap` (default USD per tenant per month), `ai_platform_global_cap` (USD per month across all tenants; 0 = none).
- **D4 Metering in the control DB.** Every call made with the platform key is also logged to `bms_control.platform_ai_usage` (tenant_id, user_id, feature, provider, model, tokens, est_cost, status, created_at). The per-tenant cap and the global cap are checked **from that table** before calling out, so a tenant can't reset its own counter. The tenant `ai_usage_log` is still written so the tenant admin keeps their own usage view.
- **D5 Per-tenant cap override.** `tenant_ai_policy.monthly_cap` (NULL = platform default, 0 = unlimited) is set by superadmin only.
- **D6 Tenant page.** In `platform` mode, AI Settings becomes read-only: "AI provided by your service provider", this month's usage against the limit, and the recent usage table. No provider, model or key is shown. In `own` mode it's the current form. In `off` mode it says AI isn't included in the plan. The save and test endpoints refuse writes unless the mode is `own`.
- **D7 Wording.** Tenant-facing text says "service provider", never a hard-coded name or URL (same rule as Support).

## 2. Security rules (apply to every phase)

1. Platform key: encrypted with `crypto.php`. **Saving is refused unless `includes/ai_app_secret.php` exists** (no `DB_NAME` fallback for platform secrets). The key is never sent back to the browser or written to a log or audit entry. The form only shows "key set" plus a "Replace key" action.
2. Provider error text is sanitised before it's logged or shown: the key and any `Authorization` header are stripped, and the text is truncated.
3. Base URL (platform **and** tenant own-key, fixes G3): only `https://`, the host must resolve to a public IP (no loopback, private, link-local or metadata ranges), and redirects are not followed.
4. Superadmin actions: `requireSuperadmin()` + CSRF + audit entry in superadmin activity (what changed; never the key).
5. Fail closed: if the control DB is unreachable, a `platform` tenant gets "AI unavailable". The call is never made unmetered.
6. Tenant isolation: the call runs in the tenant's own request with the tenant's own data. Nothing is cached or shared across tenants. The usage row is tagged with `bmsCurrentTenantId()` from the server, never with request input.
7. Caps are soft: they're checked before the call, so concurrent calls can overshoot by at most a few cents. The per-user rate limit (12/min) stays, and a per-tenant limit (60/min) is added.
8. **Tests never call a real AI provider.** A test hook (`AI_TEST_FAKE_PROVIDER`) returns canned responses and usage, the same discipline as `SUPPORT_MAIL_CAPTURE_FILE` for email.

## 3. Phases (commit after each fully-tested phase; one push + PR at the end)

### Phase 1 — Foundation (control DB + core helper) ✅ (81 checks)
- `core/ai_platform.php`: `aiPlatformSettings()`, `aiPlatformSaveSettings()` (validation per §2), `aiTenantPolicy($tenantId)`, `aiResolveSource($tenantId)`, `aiPlatformMonthSpend($tenantId|null)`, `aiPlatformCapInfo($tenantId)`, `aiPlatformLogUsage()`, `aiSafeBaseUrl()`, `aiSanitizeError()`.
- `aiPlatformSchema()` adds `platform_ai_usage` (indexes on tenant_id+created_at and created_at) and `tenant_ai_policy` (tenant_id PK, mode, monthly_cap, updated_by, updated_at). It's called from `scripts/setup_control_db.php`, so it runs on every deploy and is idempotent.
- Test: `tests/test_platform_ai_phase1_cli.php` covers the resolver matrix (no row / own key / platform / off), cap maths, base-URL guard, error sanitising, and refusing to save without the secret file.

### Phase 2 — Engine switch (`core/ai_service.php`) ✅ (51 checks)
- `aiSettings()` returns the resolved config (`source` = platform|own|off). `aiConfigured()` = entitlement + configured source. `aiCapInfo()` reports the right cap for the source.
- `aiComplete()`: per-tenant cap + global cap (platform) or tenant cap (own) → call → log to the tenant table and, for platform calls, to the control table. Errors are sanitised. Base-URL guard is applied.
- Fake-provider test hook added to `_aiHttp`.
- No caller changes needed. Each of the 9 callers is re-checked by test so all of them behave correctly in each mode.
- Test: `test_platform_ai_phase2_cli.php` covers a platform tenant getting an answer, a restricted module getting nothing (including the notify digest), cap reached → blocked, control DB down → refused, an own-key tenant unchanged, and the existing `test_ai_*` suites still passing.

### Phase 3 — Superadmin → AI → Settings ✅ (40 checks)
- `app/superadmin/ai.php` (route `ai`, nav item "AI") → **Settings** tab: enable, provider, model, base URL, temperature, default tenant cap, global cap, key (replace only), **Test connection** (metered as `test`, tenant NULL).
- `actions/superadmin_ai.php` (actions save_settings, test).
- Test: auth/CSRF refusal, validation, key never echoed, audit row written.

### Phase 4 — Superadmin → AI → Companies & Usage ✅ (40 checks)
- **Tenants** tab: every tenant with module on/off, key source (Auto/BJP key/Own key/Off), cap (default/custom/unlimited), this month's spend against the cap, and calls. Superadmin can edit the source and cap.
- **Usage** tab: this month vs last month totals, by tenant, by feature, and the last 50 errors (sanitised).
- Actions: `set_policy` (validated mode and cap; the tenant must exist).
- Test: policy changes take effect immediately in the resolver; non-numeric or negative cap rejected; unknown tenant rejected.

### Phase 5 — Tenant side ✅ (37 checks)
- `ai_settings.php` shows per mode as in D6; `save_ai_settings.php` / `test_ai_config.php` return 403 unless the mode is `own`.
- "Ask BMS" in the header and help panel and "Generate with AI" appear automatically for platform tenants. A friendly message is shown when the limit is reached ("This month's AI limit has been reached. Contact support.").
- `lang/sw.php` strings for all new text.
- Test: render of each mode (admin and staff), endpoints refuse in platform/off mode, Swahili keys present with no duplicates added.

### Phase 5b — "Ask BMS" chat widget ✅ (46 checks) (approved 2026-10-25, modelled on the Ajira Portal helpdesk)
- A floating round **Ask** button at the bottom-right. **It is rendered only when the company's AI Assistant module is allowed** (`tenantFeatureEnabled('ai_assistant')`) **and** the user may use it (`canView('ai_assistant')`). Otherwise there's no button and no script.
- As built: an Ajira-style chat popup (`includes/ask_widget.php`), not a tab inside the help panel. It adds **no new endpoint**: it reuses `api/support_help.php` (chips + search), `api/ai/ask.php` (AI answers, already limited to what the user may open) and the help panel's Contact form. One help system, two doors. It contains:
  - greeting by name (EN/SW);
  - **popular-question chips** taken from the most-viewed published articles, filtered by product and role;
  - free text → (1) article search first (free, works with no AI), (2) otherwise an AI answer grounded in the articles and only the pages the user can open, with links to the page (Shop/Warehouse wording);
  - **"Talk to a person"** opens the existing Contact support form pre-filled with the chat (staff → admin, admin → BJP);
  - shows the real support hours (no fake "online" claim).
- AI off, unconfigured or limit reached → chat still works with articles + "Talk to a person".
- Phone: sits above the bottom bar; hidden on the POS Sell screen.
- Privacy: chat history is session-only (not stored); each AI answer is metered like any other call.
- Security: POST + CSRF; question length capped; output escaped and links built server-side only from known page keys; per-user rate limit; no raw HTML from the model.

### Phase 6 — End-to-end + security sweep
- Full flow on two local tenants with the fake provider: superadmin saves the key → tenant A (platform) asks → usage appears in superadmin → cap lowered → blocked; tenant B (own key) unaffected; tenant C module restricted → no AI anywhere.
- Re-scout for missed callers, run every `test_ai_*` and support suite, `php -l` all touched files, changelog entry, then **one push + PR into develop**.

## 4. After deploy (what you do)
1. Superadmin → AI → Settings: choose provider + model, paste the BJP key, set the default tenant cap and global cap, click **Test connection**.
2. Superadmin → AI → Tenants: check each tenant's source. BJP (Tenant #1) shows "Own key" automatically if its key is set. Switch it to "BJP key" if you want one bill.
3. Make sure the AI module is ticked in the plans that should include AI.

## 5. Out of scope (later, if wanted)
- Per-plan AI caps (cap taken from the plan instead of per tenant).
- Billing tenants for AI usage.
- Streaming responses.
