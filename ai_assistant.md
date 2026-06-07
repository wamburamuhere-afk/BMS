# BMS — AI Assistant & Smart Insights — Implementation Plan

> **Goal:** make BMS an **AI-powered ERP** — the headline "intelligent" feature that wins demos
> and sells more. Two visible capabilities:
> 1. **✨ Generate with AI** — draft text (invoice/quote/expense descriptions, email/SMS templates,
>    product blurbs) anywhere a free-text field exists.
> 2. **AI Insights (Ask BMS)** — a chat that answers business questions in plain language
>    ("What was my profit last quarter? Who are my top 5 debtors?") from the company's own data,
>    plus an auto-generated **monthly business summary**.
>
> **Benchmarked against:** WorkDo `AIAssistant` (`AIService.generateContent` → provider/model/
> apiKey from company settings; `PromptBuilder`; `AIPrompt`).
>
> **Hard rules (same discipline as `account.md`):**
> - **Additive only** — never break a host page. If AI is unconfigured or the API fails, the page
>   still works; the AI button just shows "AI not configured / unavailable".
> - Every endpoint follows `.claude/security.md` §9 (auth → permission → method → CSRF → validate →
>   logic → log) and `.claude/ui-constants.md` (Bootstrap, Select2, SweetAlert2, `bi-*`).
> - Each phase ships behind its own **migration + CLI test** (`tests/test_ai_*_cli.php`), committed
>   on a feature branch off `develop`, PR into `develop`.
> - **Data safety first** (see §Safety): the model NEVER gets raw DB dumps or writes SQL. Insights
>   use a curated set of read-only aggregate functions.

---

## 0. Design at a glance

```
                 ┌─────────────────────────────────────────────┐
   Admin ───────►│ AI Settings (provider, model, API key,      │
                 │ enable, monthly cost cap)  → system_settings │
                 └───────────────┬─────────────────────────────┘
                                 │  (key encrypted at rest)
        ┌────────────────────────▼─────────────────────────────┐
        │ core/ai_service.php   aiComplete($messages,$opts)     │
        │  - picks provider (OpenAI / Anthropic / Gemini /      │
        │    OpenRouter), builds request, cURL, parses, returns │
        │  - logs tokens+cost to ai_usage_log, enforces cap     │
        └───────┬───────────────────────────────┬───────────────┘
                │                               │
   ┌────────────▼───────────┐      ┌────────────▼─────────────────────────┐
   │ A. Generate-with-AI    │      │ B. AI Insights ("Ask BMS")           │
   │  api/ai/generate.php   │      │  api/ai/ask.php                      │
   │  ✨ button → drafts     │      │  curated insight functions (read-    │
   │  text into a field      │      │  only aggregates) → model phrases    │
   └────────────────────────┘      │  answer; monthly summary generator   │
                                    └──────────────────────────────────────┘
```

**Provider abstraction:** one internal message format (`[{role, content}]`) mapped to each
provider's API. Default provider **OpenAI-compatible** (works for OpenAI + OpenRouter + most local
gateways); Anthropic and Gemini are adapters. Admin chooses provider + model + key.

---

## 1. Impact map (what is touched — all NEW or additive)

| # | File | Action |
|---|------|--------|
| 1 | `migrations/2026_..._ai_foundation.php` | NEW — `ai_usage_log` table; seed `system_settings` keys; seed `ai_assistant` permission |
| 2 | `core/crypto.php` | NEW — `encryptSecret()/decryptSecret()` (AES-256-GCM) for the API key at rest |
| 3 | `core/ai_service.php` | NEW — provider-agnostic `aiComplete()`, cost logging, cap enforcement |
| 4 | `core/ai_insights.php` | NEW — registry of curated read-only "insight functions" (revenue, top debtors, profit, low stock, …) |
| 5 | `app/constant/settings/ai_settings.php` | NEW — admin config page (provider/model/key/enable/cap) |
| 6 | `api/ai/save_ai_settings.php` | NEW — persist config (encrypts key) |
| 7 | `api/ai/test_ai_config.php` | NEW — "Test connection" (mirrors `test_email_config.php`) |
| 8 | `api/ai/generate.php` | NEW — Generate-with-AI text endpoint |
| 9 | `api/ai/ask.php` | NEW — AI Insights Q&A endpoint |
| 10 | `api/ai/monthly_summary.php` | NEW — generate plain-language monthly business summary |
| 11 | `app/constant/communication/ai_assistant.php` | NEW — "Ask BMS" chat page |
| 12 | `app/includes/ai_button.php` (or a JS helper) | NEW — reusable ✨ button partial dropped next to text fields |
| 13 | `roots.php` | add routes for the new pages/endpoints |
| 14 | `header.php` (sidebar) | add "AI Assistant" menu item under Communication (permission-gated) |
| 15 | 2–3 existing forms (invoice, quotation, expense) | add the ✨ button next to the description field (opt-in, additive) |

**Untouched / unaffected:** all accounting, inventory, HR, reports, permissions engine. AI is a
side-car; removing the menu item + endpoints fully disables it with zero data impact.

---

## 2. Configuration model (`system_settings` keys)

| key | group | meaning |
|---|---|---|
| `ai_enabled` | `ai` | `0/1` master switch |
| `ai_provider` | `ai` | `openai` \| `anthropic` \| `gemini` \| `openrouter` |
| `ai_model` | `ai` | e.g. `gpt-4o-mini`, `claude-haiku-4-5`, `gemini-2.0-flash` |
| `ai_api_key_enc` | `ai` | **encrypted** API key (never stored or returned in plaintext) |
| `ai_base_url` | `ai` | optional override (OpenRouter / self-host gateway) |
| `ai_monthly_cost_cap` | `ai` | hard ceiling (USD or token count); 0 = unlimited |
| `ai_temperature` | `ai` | default creativity for generation |

The key is written via `save_ai_settings.php` (encrypted) and only ever read inside
`ai_service.php`. The settings page shows a masked `••••••••` and a "Replace key" action.

---

## 3. Safety, privacy & cost (non-negotiable)

- **No raw data / no SQL from the model.** Insights work via a **curated function registry**
  (`core/ai_insights.php`): a fixed set of parameterized, read-only aggregate functions
  (e.g. `revenue(from,to)`, `top_debtors(n)`, `profit(period)`, `low_stock()`, `cash_position()`).
  The model only chooses which function + args; BMS runs it and passes the **small numeric result**
  back for the model to phrase. The model can never read arbitrary rows or write anything.
- **PII minimisation** — insight functions return aggregates / limited fields; no full customer
  dumps. A `redact()` pass strips emails/phones before any text leaves the server when not needed.
- **Project-scope respected** — insight functions apply the same `scopeFilterSqlNullable()` rules,
  so a scoped user's AI answers only cover their data.
- **Permission-gated** — page key `ai_assistant`: `canView` to use, admin to configure. Endpoints
  use the §9 template (auth + CSRF + permission).
- **Cost control** — every call logs `prompt_tokens/completion_tokens/est_cost` to `ai_usage_log`;
  `ai_monthly_cost_cap` blocks calls past the ceiling with a friendly message; per-user rate limit.
- **Key at rest** — AES-256-GCM via `core/crypto.php`; the encryption key comes from an app secret
  (env / a non-web-readable config), not the DB.
- **Fail-safe** — every entry point try/catches; on any failure the host page is unaffected and the
  UI shows "AI unavailable".
- **Auditability** — `logActivity` + `logAudit` on config changes; `ai_usage_log` is the usage trail.

---

# IMPLEMENTATION PHASES

> Order = fastest path to a visible, sellable win. Each sub-phase: one edit + one check. Commit per
> phase. `php -l` after every file; a `tests/test_ai_*_cli.php` gate per phase.

## PHASE 1 — Foundation (settings, crypto, service, connectivity)
- [ ] **1.A** Migration: create `ai_usage_log` (id, user_id, feature, provider, model, prompt_tokens,
      completion_tokens, est_cost, status, created_at); seed the `ai_*` `system_settings` keys
      (defaults: disabled); seed `ai_assistant` permission row. Idempotent.
- [ ] **1.B** `core/crypto.php` — `encryptSecret($plain)`, `decryptSecret($enc)` (AES-256-GCM,
      app-secret keyed); never logs the plaintext.
- [ ] **1.C** `core/ai_service.php` — `aiConfigured()`, `aiComplete(array $messages, array $opts=[])`:
      reads settings, decrypts key, builds the provider request (OpenAI-compatible default + Anthropic
      / Gemini adapters), cURL with timeout, parses, returns `['ok'=>bool,'text'=>..,'usage'=>..]`;
      writes `ai_usage_log`; enforces `ai_monthly_cost_cap`. Never throws to the caller.
- [ ] **1.D** `app/constant/settings/ai_settings.php` (admin) + `api/ai/save_ai_settings.php`
      (encrypts key) + `api/ai/test_ai_config.php` ("Test connection" → a 1-token ping).
- [ ] **1.E** Route + sidebar entry (permission-gated).

**✅ check:** admin saves provider/model/key, clicks Test → "Connected ✓"; key stored encrypted
(plaintext never in DB or responses). CLI: `test_ai_foundation_cli.php` (table/keys/permission +
crypto round-trip + `aiConfigured()` logic).

## PHASE 2 — ✨ Generate with AI (first visible win)
- [ ] **2.A** `api/ai/generate.php` — input `{context, field_type, tone}` → builds a prompt (a
      `PromptBuilder`-style helper) → `aiComplete()` → returns drafted text. §9 gated.
- [ ] **2.B** Reusable ✨ button partial / JS helper: sits next to a text field; on click → modal
      ("describe what you want", tone select) → calls `generate.php` → inserts result; SweetAlert on
      error; hidden entirely when `ai_enabled=0` or no permission.
- [ ] **2.C** Wire it into **3 fields** as the launch set: invoice description, quotation notes,
      expense description (additive — the field still works without AI).

**✅ check:** click ✨ on a draft invoice → a sensible description appears, editable. With AI disabled,
the button is absent and the form is unchanged. CLI: `test_ai_generate_cli.php` (endpoint wiring,
disabled-state, prompt builder).

## PHASE 3 — AI Insights ("Ask BMS") — the headline
- [ ] **3.A** `core/ai_insights.php` — registry of read-only insight functions, each with a name,
      JSON-schema args, and a PHP implementation that returns a small aggregate (reusing existing
      report SQL where possible): `revenue`, `expenses`, `profit`, `top_debtors`, `top_customers`,
      `cash_position`, `ar_aging_summary`, `low_stock`, `sales_trend`. All scope-aware.
- [ ] **3.B** `api/ai/ask.php` — function-calling loop: send the question + the function catalog →
      model picks a function+args → BMS executes it → result returned to the model → model phrases a
      plain-language answer (with the numbers). Caps tool calls per question; logs usage.
- [ ] **3.C** `app/constant/communication/ai_assistant.php` — a clean chat UI (question box,
      streamed/loading answer, suggested prompts, "based on: <function>" provenance chip).

**✅ check:** "What was my revenue last month and who are my top 3 debtors?" → correct numbers phrased
in words, with the source function shown; a question with no matching function → graceful "I can't
answer that yet". CLI: `test_ai_insights_cli.php` (each insight function returns correct aggregates
vs a direct SQL check; scope respected; no write path exists).

## PHASE 4 — Monthly business summary / owner digest
- [ ] **4.A** `api/ai/monthly_summary.php` — gather the month's KPIs via the insight registry →
      `aiComplete()` → a plain-language summary ("Revenue TZS X (+12% MoM), 3 invoices overdue
      totalling Y, cash position Z, top customer …, watch: …").
- [ ] **4.B** Surface it on the dashboard ("This month, in words") + make it reusable by the future
      Smart-Alerts engine (owner daily/period digest).

**✅ check:** summary reflects the same numbers the reports show; safe when a month has no data. CLI:
`test_ai_summary_cli.php`.

## PHASE 5 — Hardening (cost, rate-limit, audit, polish)
- [ ] **5.A** Enforce `ai_monthly_cost_cap` + per-user/min rate limit; clear "limit reached" UX.
- [ ] **5.B** `ai_usage_log` viewer for admin (spend, calls, by feature/user) on the AI settings page.
- [ ] **5.C** Prompt-injection guardrails for Insights (the model only ever gets function results,
      never raw user-controlled SQL); strip/escape user text in prompts.
- [ ] **5.D** Graceful degradation everywhere; consistent "AI unavailable" copy.

**✅ check:** exceeding the cap blocks further calls with a friendly message; usage log totals match
the per-call logs. CLI: `test_ai_hardening_cli.php`.

## PHASE 6 — Test sweep + docs + sell-sheet
- [ ] **6.A** Full `tests/test_ai_*_cli.php` suite green; existing suites unaffected (pre-push hook).
- [ ] **6.B** Short admin guide ("Connect your AI provider in 2 minutes") + a one-page feature
      sell-sheet ("AI-powered BMS") for demos.

---

# TESTING MASTER CHECKLIST
- [ ] T1 — migration idempotent; `ai_assistant` permission seeded; `ai_usage_log` exists.
- [ ] T2 — crypto round-trips; key never appears in DB/responses/logs.
- [ ] T3 — `aiConfigured()` false by default; true after valid config; Test-connection works.
- [ ] T4 — Generate endpoint returns text; **AI disabled → button absent, host form unchanged**.
- [ ] T5 — each Insight function's aggregate == a direct SQL check; **project-scope respected**.
- [ ] T6 — Insights answer uses the right function + correct numbers; unknown question degrades nicely.
- [ ] T7 — monthly summary matches report figures.
- [ ] T8 — cost cap blocks past ceiling; rate-limit works; usage log accurate.
- [ ] T9 — **no write path** anywhere in the AI layer (read-only insight functions only).
- [ ] T10 — every existing CLI suite still green (no regressions).

---

# ROLLBACK / KILL-SWITCH
- `ai_enabled = 0` instantly hides every AI surface (buttons, chat, summary) — zero effect on the
  rest of BMS.
- The whole feature is new files + a few opt-in buttons; reverting the branch removes it cleanly.
- Migration is additive (one new table + settings/permission rows); a down-migration would drop the
  table and the `ai_*` settings.

# COMMERCIAL FRAMING (why this sells)
- Headline: **"AI-powered ERP"** — closes demos; justifies a higher price tier or an add-on fee.
- Generate-with-AI = visible everyday delight; Insights = "ask your business anything"; Monthly
  summary = the owner's favourite. All run on the customer's own API key (you carry no token cost).
- Sets up Smart-Alerts (next feature) to reuse the summary as a daily digest.

# WORKFLOW
One feature branch off `develop` (e.g. `feat/ai-assistant`); commit per phase; log each to
`changelog.md`; PR into `develop`; pre-push hook runs all CLI suites.
