# MM Agent-Scoped Access — Strategic Plan

**Status:** APPROVED 2026-09-30 (all §3 recommendations accepted) — in progress on `feat/mm-agent-scope`
**Date:** 2026-09-30
**Scope:** Mobile Money module (15 pages in `app/bms/mobile_money/`, 15 APIs in `api/mobile_money/`, `header.php` MM nav, `core/permissions.php`)

---

## 1. The rule (what the user asked for)

When a tenant has **only the Mobile Money module** enabled:

| User | What they can see / do |
|---|---|
| **Admin** (`isAdmin()`) | Everything, all agents — unchanged. |
| **Non-admin WITH agent grant(s)** in `mm_user_agent_grants` | Shifts, open/close shift, record transactions, float, reports, reconciliation — **only for the agents/tills they are granted**, and only the actions their grant flags allow. |
| **Non-admin with NO grant** | Lands on **MM Dashboard** (empty-state: "You are not assigned to any agent — contact your administrator"), their **own profile**, and personal settings (language, password, logout) **as now**. **Nothing else** — no nav links, no pages, no APIs — **regardless of what `user_roles.php` grants the role.** |

Effective access for a non-admin = **role permission AND agent grant**. The grant is the ceiling; the role can only narrow it further, never widen it.

---

## 2. What the scout found (current state, 2026-09-30)

### 2.1 Grant enforcement is almost entirely missing
Every MM file has the header comment *"MM tables are agent-scoped via mm_user_agent_grants"* — but only these actually use the grants table:

| File | Uses grants? |
|---|---|
| `core/mm_float_service.php` → `mmUserCanOnTill()` | ✅ helper exists |
| `api/.../open_shift.php`, `close_shift.php`, `batch_open_shifts.php`, `batch_close_shifts.php` | ✅ call `mmUserCanOnTill()` |
| `app/.../mm_shifts.php` | ✅ till list joined to grants |
| `app/.../mm_dashboard.php` | ⚠️ only the "can open more shifts" check; **all KPIs, charts, Top-5 agents, network volume are company-wide** |
| **Every other page and API (11 APIs, 12 pages)** | ❌ **no grant check at all** |

### 2.2 Concrete gaps (bugs today)
1. **`save_transaction.php`** — any non-admin with `mm_transactions` create permission can post a transaction on **any till of any agent**.
2. **`void_transaction.php`, `save_float_movement.php`, `get_till_float.php`, `save_reconciliation.php`, `update_reconciliation.php`** — same: no till/agent ownership check.
3. **`mm_transactions.php`, `mm_float.php`, `mm_reports.php` (7 reports), `mm_reconciliation.php`, `mm_compliance.php`, `mm_commissions.php`, `mm_shift_report.php`** — list **all agents' data** to any role with view permission.
4. **`mm_transaction_view.php?id=`, `mm_recon_view.php?id=`** — IDOR: any id can be opened.
5. **`mm_dashboard.php`** — company-wide money figures shown to every user with `mm_dashboard` view.
6. **MM-only nav** (`header.php:955-1037`) — driven purely by `canView()`, so an ungranted user with a generous role sees Transactions, Float, Reports, Expenses, Shifts, Commissions, Reconciliation, Compliance.
7. **Denied page → `unauthorized`**, not back to the MM Dashboard as required.

### 2.3 Scoping columns available (no schema change needed for filtering)
| Table | Filter via |
|---|---|
| `mm_tills` | `agent_id`, `till_id` |
| `mm_transactions` | `agent_id`, `till_id` |
| `mm_shifts`, `mm_float_movements`, `mm_float_snapshots`, `mm_reconciliations` | `till_id` |
| `mm_kyc_records` | `txn_id` → `mm_transactions.till_id` |
| `mm_commissions_received` | `network_id` only — **company-level**, not agent-scopable |
| `mm_commission_rates`, `mm_networks` | reference data, not agent-scopable |

`mm_user_agent_grants`: `user_id, agent_id, till_id (NULL = all tills of the agent), can_open_shift, can_record_transactions, can_close_shift, can_reconcile`.

### 2.4 No other surface
No `api/mobile/**` or `api/pos/**` endpoint touches MM tables — the attack surface is exactly the 15 pages + 15 APIs + header nav.

---

## 3. Decisions to confirm before coding

| # | Question | Recommendation |
|---|---|---|
| D1 | Apply agent **data scoping** only in MM-only tenants, or in every tenant that has MM? | **Data scoping everywhere** (it's a security fix — a multi-module tenant must not leak other agents' money either). The **lock-down to Dashboard/Profile/Settings** for ungranted users applies **only in MM-only tenants**, as requested. |
| D2 | Float top-up/withdrawal has no grant flag. | **Reuse `can_record_transactions`** — no schema change. |
| D3 | Company-level pages for granted non-admins: *Commissions received from networks* (`mm_commissions_received`), *Commission Rates*. | Commissions **received** → **admin only** (company money). Commission **earned** on the user's own agents' transactions → shown scoped. Commission **Rates** → read-only for granted users (reference table), edit admin-only. |
| D4 | Agent status. | **Closed** agent → grant ignored (no access). **Suspended** → can view history, cannot open shift / record / float. |
| D5 | Grant revoked while a shift is open. | User may still **close their own open shift** (prevents orphaned shifts); nothing else. Admin can always batch-close. |
| D6 | "Settings as now" for ungranted users. | Keep the **account sheet** (profile, language, change password, logout) and **Admin** link for admins. MM operational items under Settings (Teller Shifts, Commissions, Reconciliation, Compliance) **hide** because they're agent data. |

---

## 4. Architecture — one engine, enforced in one place

### 4.1 New `core/mm_scope.php` (the only place grant logic lives)
```
mmScopeAll(): bool                       // true for admin → no filter
mmGrantedAgentIds(): int[]               // active/suspended agents only (D4), per-request static cache
mmGrantedTillIds(?string $ability=null): int[]  // expands till_id NULL → all active tills of that agent
mmHasAnyGrant(): bool                    // admin → true
mmTillInScope(int $tillId, ?string $ability=null): bool
mmScopeSql(string $col, string $kind='till'|'agent'): string
     // admin → ''    |   ids → " AND $col IN (1,2,3)" (int-cast)   |   none → ' AND 1=0'
mmRequireTill(int $tillId, string $ability): void   // API guard → JSON 403 + exit
mmRequireGrantOrDashboard(): void                  // page guard → redirect mm_dashboard + flash
```
- Per-**request** cache (static), **not** session — a grant change takes effect on the next click, no re-login.
- `mmUserCanOnTill()` in `mm_float_service.php` becomes a thin wrapper around `mmTillInScope()` so the 4 shift APIs keep working unchanged.
- `mmScopeSql()` returns `AND 1=0` for an empty list — never an empty `IN ()` (SQL error) and never "no filter" (leak).

### 4.2 Central gate in `core/permissions.php`
Mirror the existing `tenantModuleAllowsPage()` line. In `canView/canCreate/canEdit/canDelete` (+ workflow `canX`), **after** the admin bypass:
```
if (!mmGrantAllowsPage($pageKey)) return false;
```
`mmGrantAllowsPage()` (in `mm_scope.php`):
- Not MM-only tenant → `true` for non-MM keys (D1: lock-down is MM-only); for `mm_*` keys → `mmHasAnyGrant()` except `mm_dashboard`.
- MM-only tenant, no grant → only a **whitelist** is true: `mm_dashboard` + personal/profile keys (exact list pinned in Phase 0). Everything else (`mm_*`, `expenses`, …) → `false`.
- Admin-only MM keys (`mm_agents`, `mm_networks`, `mm_user_agent_grants`, `mm_commission_rates` edit, `mm_commissions_received`) stay admin-gated as now.

Because the nav, `autoEnforcePermission()`, and the APIs all call `canX()`, **this one hook closes the nav + page-gate + API-permission gaps at once**. Row-level filtering (which agent's rows) is then done per page/API with `mmScopeSql()`.

### 4.3 Redirect target
`requireViewPermission()` → when the denial is caused by `mmGrantAllowsPage()` (not by the role), redirect to **`mm_dashboard`** with a flash message instead of `unauthorized`.

---

## 5. Phases

Each phase: re-scout first (memory rule) → build → lint → phase test (static + live rolled-back) → commit on the feature branch.
Branch: `feat/mm-agent-scope` off `develop`. PR → `develop`, then `develop` → `main`.

### Phase 0 — Re-scout & baseline (no code change)
- [ ] List every page key reachable from: MM-only desktop nav, mobile MM bottom bar, More sheet, Settings sheet, account sheet (`bmsAccountSheet`), MM dashboard quick actions.
- [ ] Pin the **whitelist** keys for ungranted users (profile / change password / notifications / language) — verify real keys in `getPagePermissionMapping()`.
- [ ] Grep every `FROM mm_` / `JOIN mm_` query in the 15 pages + 15 APIs → a checklist table (file, line, needs till/agent filter?). This table becomes the Phase 4/5 tick-list.
- [ ] Run the 3 existing MM suites (109 assertions) → baseline green.

### Phase 1 — Scope engine
- [ ] Create `core/mm_scope.php` (§4.1); load it from `roots.php`.
- [ ] Rewire `mmUserCanOnTill()` to use it.
- [ ] Test `tests/test_mm_scope_engine_cli.php`: admin = all; full-agent grant; till-only grant; closed agent ignored; suspended agent view-only; ability flags; empty list → `AND 1=0`; cache per request.

### Phase 2 — Central gate + nav + redirect
- [ ] Add `mmGrantAllowsPage()` line to every `canX()` in `core/permissions.php`.
- [ ] `requireViewPermission()` → redirect to `mm_dashboard` + flash for grant-denials.
- [ ] Verify **all 6 nav contexts** in `header.php` hide correctly for ungranted user (desktop MM-only nav incl. **Expenses** and Settings dropdown; mobile bottom bar; More sheet + its button; Settings sheet; multi-module MM dropdown).
- [ ] Dashboard quick-action buttons (Open Shift / Record Transaction / Float) hidden for ungranted and per-ability for granted.
- [ ] Test: ungranted user with a role that has **every** MM permission → every `canView('mm_*')` except dashboard is false; every MM page URL redirects to dashboard.

### Phase 3 — MM Dashboard
- [ ] **Ungranted:** empty-state card only (no KPIs, charts, Top-5, network volume) + profile shortcut.
- [ ] **Granted:** every KPI / chart / Top-agents / network query filtered by `mmScopeSql()`; tills/agents/open-shift counters scoped.
- [ ] New **"My Agents"** section: each granted agent → name, tills, open shifts, float, status; "View" → `mm_agent_view`.
- [ ] Open `mm_agent_view.php` to granted users **for their agents only** (read-only; edit/close stay admin).
- [ ] Test: admin sees company totals; granted user's totals = sum of own agents only; ungranted page has no money figures.

### Phase 4 — Pages: row-level scoping (per Phase-0 checklist)
- [ ] `mm_shifts.php`, `mm_shift_report.php`
- [ ] `mm_transactions.php` + filter dropdowns (only in-scope agents/tills/networks)
- [ ] `mm_transaction_view.php` — IDOR guard (txn's till in scope, else → dashboard)
- [ ] `mm_float.php` (movements, balances, till selector)
- [ ] `mm_reconciliation.php`, `mm_recon_view.php` (IDOR guard; edit/cancel need `can_reconcile`)
- [ ] `mm_compliance.php` (KYC via `txn_id` → till)
- [ ] `mm_commissions.php` (earned = scoped; received = admin-only section, D3)
- [ ] `mm_reports.php` — all 7 reports + their filters: txn_summary, float_position, commission, agent_perf, shift_summary, void_suspicious, network_comparison
- [ ] Every modal till/agent `<select>` lists only in-scope tills (a user can't even *pick* another agent's till).
- [ ] Excel export: DataTables exports rendered rows → automatically scoped; verify.
- [ ] Test per page: granted-user row count == rows of own tills; foreign-id view URL redirects.

### Phase 5 — APIs: server-side enforcement (never trust the UI)
| API | Guard to add |
|---|---|
| `save_transaction.php` | `mmRequireTill($till, 'can_record_transactions')`; agent not suspended/closed |
| `void_transaction.php` | txn's till in scope + role `canVoid` |
| `save_float_movement.php` | `mmRequireTill($till, 'can_record_transactions')` (D2) |
| `get_till_float.php` | till in scope (read) |
| `save_reconciliation.php` (create/EDIT/DELETE), `update_reconciliation.php` | `mmRequireTill($till, 'can_reconcile')` |
| `open_shift`, `close_shift`, `batch_*` | already call helper — verify closed/suspended (D4) and revoked-mid-shift close (D5) |
| `save_agent`, `save_till`, `save_network`, `save_commission_rate`, `save_commission_received` | stay **admin-only** — verify each has `isAdmin()` at top |
- [ ] Test: forged POST by a granted user against a **foreign till** → rejected on every API; by an ungranted user → rejected on every API.

### Phase 6 — Edge cases sweep
- [ ] Till-level grant (`till_id` set) must not leak the agent's other tills.
- [ ] User with grants on 2 agents sees both, combined correctly.
- [ ] Grant deleted mid-session → next request loses access (no cache in session).
- [ ] Agent closed / till deactivated after grant.
- [ ] Revoked mid-shift → can close own shift only (D5).
- [ ] Admin behaviour byte-for-byte unchanged (regression).
- [ ] Multi-module tenant: MM rows still scoped (D1), non-MM modules unaffected.
- [ ] Print / PDF views and any `?id=` link reachable from notifications.

### Phase 7 — Full test suite + end-to-end
- [ ] `tests/test_mm_agent_scope_cli.php` — matrix of 5 personas × every page key × every API:
  admin · granted-full · granted-till-only · granted-no-reconcile · **ungranted-with-full-role**.
- [ ] Real **create/save E2E** (rolled back): granted teller opens shift → records cash-in → float top-up → closes shift → reconciles; same flow on a foreign till is rejected at each step.
- [ ] Re-run the existing 109 MM assertions + permission suites — no regressions.
- [ ] Local browser run (WAMP) as each persona: nav, dashboard, every page, mobile view.

### Phase 8 — Ship
- [ ] `changelog.md` entry (date, files, description).
- [ ] Commit → push `feat/mm-agent-scope` → PR into `develop` → PR `develop` → `main` → confirm deploy green → spot-check live as a non-admin test user.

---

## 6. Files expected to change
`core/mm_scope.php` (new) · `roots.php` · `core/permissions.php` · `core/mm_float_service.php` · `header.php` · 12 pages in `app/bms/mobile_money/` · 7 APIs in `api/mobile_money/` · 2 new test files · `changelog.md`.
**No schema/migration needed.**

## 6a. Phase 0 results (2026-09-30)

**Baseline:** 5 MM suites, 153 assertions, all green.

**Whitelist for ungranted users (MM-only tenant):** `mm_dashboard`, `profile`, `my_settings`, `my_hr`, `help`.
Top header bar (lines < 905) has no `canView()` calls — nothing to hide there. Account dropdown/sheet links: `my_hr`, `my_settings`, `help`, `logout`.

**Test fixtures:** local DB has 0 agents/tills/grants → tests create marked fixtures (`ZZSCOPE`) and delete them in `finally`. `isAdmin()` reads `roles.is_admin` / `$_SESSION['is_admin']` (not role_id=1).
**Cache caveat:** `test_mm_user_agent_grants_cli.php` edits a grant then re-checks in one process → engine cache must be keyed by user and resettable (`mmScopeReset()`); `mmUserCanOnTill()` keeps a direct query.

**Row-scoping checklist** (✔ = done in Phase 3/4/5):

| File | Queries needing a till/agent filter |
|---|---|
| mm_dashboard.php | 17, 28 (KPIs), 42-44 (counters), 47-67 (my shifts), 71-87 (can open more), 92, 102, 112, 123 (charts/top-5/network) |
| mm_agent_view.php | 14 (agent must be granted), 24 (tills of agent → granted tills only), 32 (sub-agents → granted only) |
| mm_shifts.php | 15, 30, 42, 57, 85, 115 |
| mm_shift_report.php | 18 (shift must be in scope), 39, 48 |
| mm_transactions.php | 17 (my shifts), 34 (till filter), 46 (list) |
| mm_transaction_view.php | 16 (IDOR) |
| mm_float.php | 16, 33, 66 |
| mm_reconciliation.php | 15 (stats), 23 (list), 33 (till select) |
| mm_recon_view.php | 17 (IDOR), 34, 43 |
| mm_compliance.php | 12, 19, 28, 41 |
| mm_commissions.php | 13 (earned), 20+41 (received → admin only), 31 (by network) |
| mm_reports.php | 39, 50, 67-71, 86, 104, 122, 143, 155 (agent filter list) |
| API save_transaction | till 57 → `can_record_transactions` |
| API void_transaction | txn 18 → till in scope |
| API save_float_movement | till 30 → `can_record_transactions` |
| API get_till_float | till in scope |
| API save_reconciliation | 20, 44 (EDIT/DELETE), 71 (create) → `can_reconcile` |
| API update_reconciliation | 20 → `can_reconcile` |
| API open/close/batch shifts | already `mmUserCanOnTill` — add D4/D5 |

## 7. Risks
| Risk | Mitigation |
|---|---|
| Hook in `canX()` accidentally hides non-MM pages in multi-module tenants | Lock-down branch runs only when `tenantOnlyHasModule('mobile_money')`; Phase 7 regression on a multi-module tenant. |
| Extra query per `canView()` call (nav calls it ~40×) | Grants loaded once per request into a static cache. |
| Admin loses access | Admin bypass stays **before** the grant hook; admin regression test. |
| Empty `IN ()` SQL error / missing filter = leak | `mmScopeSql()` always returns `AND 1=0` for empty; checklist from Phase 0 ticks every query. |
