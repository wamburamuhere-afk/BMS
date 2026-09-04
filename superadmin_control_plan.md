> **Superseded 2026-09-03.** This research is now folded into `ternant.md`
> **Phase 11 — Tenant Feature / Module Entitlements**, after re-verifying every
> finding against the code as it stood that day (one, #3, was found stale —
> `pos.php` already enforces its own permission), and extending the registry
> with `tenders`, `warehouses`, `procurement` and `sales` as independently
> grantable features per an explicit follow-up request. Track and update
> `ternant.md` going forward — not this file, so the plan doesn't fork in two
> directions. Kept here for the original investigation's detail and reasoning.

# BMS — Superadmin Control Plane: Implementation Plan (2026-09-02)

**Goal.** Give the platform superadmin real operational control, not partial:

1. **Manage their own credentials** — change email, name and password from inside the panel. *(Today: impossible. The only way to change a superadmin password is `scripts/create_superadmin.php` or raw SQL.)*
2. **Turn features on and off per tenant** — and remove a feature platform-wide so no tenant sees it at all.
3. **Register a company from the panel** — without going through the public self-registration form.

Priority features to control: **Projects**, **POS**, **HR** (the whole *Operations* menu except *Assets & Maintenance*), **AI Assistant**, **E-Signatures** — with the architecture generalised so **any** service can be switched per tenant.

Companion documents: `docs/MULTI_TENANCY.md` (architecture as built), `docs/MULTI_TENANCY_CONVENTIONS.md` (binding contract), `ternant.md` (the tenancy plan).

---

## 1. What the codebase actually does today

Findings from reading the code, not assumptions. **Each one changes the design.**

| # | Finding | Consequence |
|---|---|---|
| **1** | `canView()` (`core/permissions.php:74`) returns `true` immediately when `isAdmin()` | **A feature flag can never be implemented as permission rows.** The tenant's own admin bypasses `role_permissions` entirely. The gate must sit *above* the permission system and short-circuit **before** every admin bypass. |
| **2** | `enforcePageOrAdmin()` (`core/security_helpers.php:89`) has the same admin bypass on its first line | Second place needing the gate ahead of the bypass. |
| **3** | **`app/bms/pos/pos.php` performs no permission check at all** — it only `require`s `header.php` | Hiding a nav item is **not** access control. Any signed-in user can reach POS by URL today. Enforcement must live somewhere a page cannot forget. |
| **4** | 230 files call an enforcement helper, but coverage is uneven (see #3) | Confirms #3: per-page enforcement cannot be the gate. |
| **5** | `handleRoute()` (`roots.php:1927`) is the single dispatch point for every clean URL, and since the clean-URL change every GET for a `.php` page also passes through it | The natural belt-and-braces gate for page access. |
| **6** | `api/`, `ajax/` and `actions/` are **deliberately excluded** from the router, and bootstrap inconsistently — some load `roots.php`, some `includes/config.php`, some neither | A router-only gate leaves every AJAX/API endpoint of a "disabled" module wide open. This is the single biggest correctness trap in the whole feature. |
| **7** | `header.php` alone contains **129 `canView()` calls** driving the nav | Fixing `canView()` fixes the entire menu in one edit — no 129-site sweep. |
| **8** | The `modules` table exists but is **empty (0 rows)**; `permissions.module_id` is NULL for every row; `module_name` is inconsistent (`Inventory` vs `Inventory & Products`, `Settings` vs `System Settings`) and has no Projects / POS / E-Signature grouping | **Do not derive features from `permissions.module_name`.** The registry must be explicit and curated in code. |
| **9** | The *Operations* menu is in fact the HR module (Workforce, Org Structure, Performance & Growth, Payroll, Attendance & Leave, Meetings/Trips) with an *Assets & Maintenance* section appended | Confirms your description exactly. "HR" = Operations minus Assets. Assets becomes its own separately-switchable feature. |
| **10** | E-Signature has a **public, unauthenticated** entry point (`sign_document.php`, reached by emailed single-use token) | Disabling e-signature must also close that public door, which no session-based gate would catch. |
| **11** | Tenant credentials/status already load once per request in `bmsConnectPdo()` and sit in `$GLOBALS['__bms_tenant']` | Feature flags can ride along on that same row — **zero additional queries per request**. |
| **12** | The control DB is reachable from the app but tenants have **no** access to it (proven in Phase 9) | Correct and safe home for the flags. |

### The decisive one

Findings #1, #3 and #6 together mean the obvious implementations are all wrong:

- ❌ Permission rows → tenant admin bypasses them (#1)
- ❌ Hiding nav items → direct URL still works (#3)
- ❌ Router-only gate → AJAX/API endpoints still work (#6)

**The gate must be a request-level guard that runs inside the shared bootstrap every entry point loads, keyed on the request path, and evaluated before any admin bypass.**

---

## 2. Guiding decisions

| Decision | Choice | Why |
|---|---|---|
| Where flags live | **Control database only** (`bms_control`), never the tenant DB | The tenant's own admin bypasses the permission system (#1) and can reach their own database. A flag they can flip is not a flag. Phase 9 proved tenants cannot read the control DB. |
| Levels of control | **Two**: platform-level `is_available`, tenant-level `is_enabled`. Effective = `available AND enabled` | Gives you both asks: "totally remove it" (platform) and "add to a specific tenant" (per-tenant). |
| Default for a new tenant | Each feature carries a **`default_enabled`** flag in the registry | Lets you ship POS off-by-default while HR is on-by-default, without editing every new tenant. |
| Feature registry source | **Explicit, curated, in code** (`core/feature_registry.php`) | `permissions.module_name` is inconsistent and `modules` is empty (#8). A registry in code is reviewable, diffable and testable. |
| Enforcement point | A **path-keyed guard in `core/tenant_bootstrap.php`**, plus short-circuits in `canView()` and `enforcePageOrAdmin()`, plus a `handleRoute()` check | Covers pages, AJAX, API and actions (#5, #6). Defence in depth: the nav, the router and the endpoint each refuse independently. |
| Behaviour when disabled | **404, not 403** | A 403 confirms the feature exists and is merely switched off for you. A 404 says nothing. Matches how `assertSuperadminHost()` already behaves. |
| Single-tenant / legacy safety | With `TENANT_MODE` off, or no resolved tenant, **every feature is ON** | The change must be inert on any environment that has not opted in — the same discipline Phase 3 used. |
| Superadmin password rules | Reuse the existing hash + lockout machinery in `core/superadmin_auth.php` | It already has `password_hash`, `failed_attempts`, `locked_until` and a non-enumerating login. Do not invent a second scheme. |
| Panel-initiated registration | **Reuse `provisionTenant()` unchanged** | It already handles reservation-first ordering, rollback, credential encryption and post-grant verification. A second provisioning path would drift. |

---

## 3. Architecture

### 3.1 Data model (control DB)

```sql
-- The catalogue: one row per switchable feature, platform-wide.
CREATE TABLE features (
    feature_key      VARCHAR(64)  PRIMARY KEY,   -- 'pos', 'hr', 'projects', …
    label            VARCHAR(100) NOT NULL,
    description      VARCHAR(255) NULL,
    is_available     TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = removed platform-wide
    default_enabled  TINYINT(1)   NOT NULL DEFAULT 1,  -- for newly provisioned tenants
    sort_order       INT          NOT NULL DEFAULT 0
);

-- The per-tenant override. Absence of a row means "use default_enabled".
CREATE TABLE tenant_features (
    tenant_id    INT         NOT NULL,
    feature_key  VARCHAR(64) NOT NULL,
    is_enabled   TINYINT(1)  NOT NULL,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by   INT         NULL,          -- superadmins.id, no FK (audit outlives accounts)
    PRIMARY KEY (tenant_id, feature_key)
);
```

Effective state, in one sentence: **a feature is on for a tenant when `features.is_available = 1` AND (`tenant_features.is_enabled`, or `features.default_enabled` when no row exists).**

Both tables are created by `scripts/setup_control_db.php` — **never** by a `migrations/` file (conventions §10: platform infrastructure must never be able to veto a release).

### 3.2 Resolution — zero extra queries

`bmsConnectPdo()` already fetches the tenant row. It gains one joined lookup at the same moment, cached in `$GLOBALS['__bms_features']` as `['pos' => false, 'hr' => true, …]`. Every later check is an array read.

### 3.3 The registry (`core/feature_registry.php`)

Each feature declares everything it owns:

```php
'pos' => [
    'label'      => 'Point of Sale',
    'page_keys'  => ['pos', 'pos_config_settings'],
    'routes'     => ['pos', 'pos/dashboard', 'pos_config_settings'],
    'paths'      => ['app/bms/pos/', 'api/pos_', 'ajax/pos_'],
    'default'    => true,
],
```

- `page_keys` → consumed by `canView()` / `enforcePageOrAdmin()` (kills nav + page checks)
- `routes` → consumed by `handleRoute()`
- `paths` → consumed by the bootstrap guard (covers AJAX/API/actions — finding #6)

### 3.4 Enforcement layers

| Layer | File | Catches |
|---|---|---|
| 1. Nav + permission checks | `core/permissions.php` — before the `isAdmin()` bypass | 129 menu items, and every page that calls `canView()` |
| 2. Page enforcement | `core/security_helpers.php` — before its admin bypass | Pages using `enforcePageOrAdmin()` |
| 3. Router | `roots.php::handleRoute()` | Every clean URL, including pages that enforce nothing (POS — finding #3) |
| 4. Request path guard | `core/tenant_bootstrap.php` | `api/`, `ajax/`, `actions/`, direct file POSTs — finding #6 |
| 5. Public endpoints | `sign_document.php` explicitly | The tokenised e-signature door — finding #10 |

---

## 4. Initial feature registry

| Key | Label | Covers | Default |
|---|---|---|---|
| `projects` | Projects | `projects`, `user_projects` + the Projects menu | on |
| `pos` | Point of Sale | `pos`, `pos_config_settings`, `app/bms/pos/` | on |
| `hr` | Human Resources | The entire *Operations* menu **except** Assets & Maintenance — Workforce, Org Structure, Performance & Growth, Payroll, Attendance & Leave, Meetings/Trips (28 `Human Resources` page_keys) | on |
| `assets` | Assets & Maintenance | `assets`, `asset_report` — split out so it survives when HR is off | on |
| `ai_assistant` | AI Assistant | `ai_assistant` + the "Ask BMS" entry in Comms | on |
| `esignature` | E-Signatures | `e_signatures`, workflow signature includes, **and `sign_document.php`** | on |

Extending later is a registry entry plus a `features` row — no new enforcement code.

---

## 5. Phases

Each phase: own branch off `develop`, live-tested, PR'd into `develop`, changelog entry, single-`revert` rollback.

### Phase A — Superadmin self-service credentials 🟢 Low
**Branch:** `feat/sa-01-credentials`

Ships `app/superadmin/profile.php`: change name, change email (uniqueness-checked), change password (**current password required**, confirmation field, same strength rule as tenant signup). Reuses `password_hash` and the existing session; re-authenticates the session after a password change and logs the event to `tenant_admin_log` with a new `action = 'superadmin_credential_change'`. Adds a nav entry to the panel.

*Independent of everything else — deliberately first, because it is the thing you cannot do today.*

**Gate:** change password → sign out → old password refused, new password accepted, `last_login` stamped, lockout counter untouched. Email change → login works with the new address. Wrong current password → refused, nothing written.

### Phase B — Feature registry + control-DB schema 🟢 Low
**Branch:** `feat/sa-02-feature-registry`

`core/feature_registry.php`, the two control tables in `scripts/setup_control_db.php` (idempotent), feature resolution loaded alongside the tenant row in `bmsConnectPdo()`, and the public API: `tenantFeatureEnabled(string $key): bool`, `tenantFeatures(): array`, `featureForPath(string $path): ?string`, `featureForPageKey(string $key): ?string`.

**Ships enforcing nothing.** Pure data + resolution, exactly as Phase 3 of the tenancy work shipped switched off.

**Gate:** unit tests over the resolution matrix (available×enabled×default×no-row); with `TENANT_MODE` off every feature reports enabled; zero extra queries per request (asserted by counting).

### Phase C — Enforcement 🔴 High
**Branch:** `feat/sa-03-feature-enforcement`

The five layers of §3.4. This is the phase that can break the application for every tenant, so it is separated from everything else and carries the heaviest tests.

**Gate — a dedicated `tests/test_feature_gating_cli.php` proving, for a tenant with POS disabled:**
- the POS nav item is absent
- `canView('pos')` is **false even for that tenant's admin** (finding #1 — the assertion that matters most)
- `/pos` returns 404 through the router
- `app/bms/pos/pos.php` reached **directly** returns 404 (finding #3)
- a POS `api/`/`ajax/` endpoint returns 404 (finding #6)
- **every other feature is completely unaffected**, and a second tenant with POS enabled is completely unaffected — the same one-tenant-at-a-time invariant Phases 6 and 9 enforce
- with `TENANT_MODE` off, everything is on

### Phase D — Superadmin feature control UI 🟠 Medium
**Branch:** `feat/sa-04-feature-panel`

Per-tenant toggles on `tenant_view.php` (grouped, with an "effective state" column showing *why* something is off — platform-removed vs tenant-disabled vs default). A new `app/superadmin/features.php` for platform-wide `is_available` and `default_enabled`, with an explicit confirmation when removing a feature that tenants currently use, stating how many are affected. Every change written to `tenant_admin_log`.

**Gate:** toggling POS off for tenant A takes effect on A's next request and leaves B untouched; platform-removing a feature overrides a tenant's own "enabled"; the audit log records actor, tenant, feature and direction.

### Phase E — Register a company from the panel 🟠 Medium
**Branch:** `feat/sa-05-panel-registration`

`app/superadmin/tenant_new.php` — company name, subdomain (live availability check, reserved-label validation), owner name/email, and an initial feature selection. Calls `provisionTenant()` unchanged; **bypasses the public throttle and honeypot** (an authenticated operator is not a bot) while keeping every validation rule. Logs to `tenant_admin_log` and `tenant_provisioning_log` with the operator's identity.

**Gate:** a tenant created from the panel is indistinguishable from a self-registered one — same 310 tables, same seeded defaults, owner can sign in, isolation suite still green with it present. Failure rolls back database, user and registry row.

### Phase F — Tests, docs, regression 🟢 Low
**Branch:** `feat/sa-06-docs-tests`

Extends `tests/test_tenant_module_smoke_cli.php` to run once with all features on and once with a mixed set. Adds a "Feature control" section to `docs/MULTI_TENANCY.md`. Consolidated changelog entry.

---

## 6. Risks, and how each is handled

| Risk | Handling |
|---|---|
| **A tenant admin re-enables a disabled feature** | Flags live in the control DB, which tenants cannot read or write (proven in Phase 9). The gate runs before the `isAdmin()` bypass. Both are explicit test assertions. |
| **A disabled module stays reachable via AJAX/API** | Finding #6 — the path guard in the bootstrap exists specifically for this, and the acceptance gate tests it directly. |
| **Enforcement breaks a working tenant** | Phase C ships behind the same discipline as Phase 3: with no resolved tenant or `TENANT_MODE` off, everything is on. Rollback is one `revert` — no data migration to unwind. |
| **A feature is disabled while data already exists** | Disabling hides the surface; it never deletes data. Re-enabling restores access unchanged. Stated explicitly in the panel UI. |
| **Per-request cost** | Flags ride the tenant row already being fetched. Asserted by query counting, not assumed. |
| **Pre-existing: POS has no permission check** | Fixed incidentally by Phase C layer 3, but flagged separately — it is a real access-control gap today, independent of this feature. |

---

## 7. Decisions I need from you before Phase B

1. **Default for new tenants** — should a newly registered company get *everything* on, or a minimal set with POS/AI/e-signature off until you enable them? (Affects `default_enabled` and Phase E's UI.)
2. **Assets & Maintenance** — I have split it out as its own toggle so it can stay when HR is off. Confirm that is what you want, rather than it disappearing with HR.
3. **Disabled-feature behaviour** — 404 (recommended, reveals nothing) versus a branded "This module is not enabled for your organisation — contact your administrator" page, which is friendlier but confirms the feature exists.
4. **Billing intent** — are these flags eventually plan/subscription-driven? If so I will keep `features` plan-aware from the start rather than retrofitting it.

None of these block Phase A, which I can start immediately.

---

## Phase tracker

| Phase | Status | Branch |
|---|---|---|
| A — Superadmin self-service credentials | ⏳ pending | `feat/sa-01-credentials` |
| B — Feature registry + control-DB schema | ⏳ pending | `feat/sa-02-feature-registry` |
| C — Enforcement (5 layers) | ⏳ pending | `feat/sa-03-feature-enforcement` |
| D — Superadmin feature control UI | ⏳ pending | `feat/sa-04-feature-panel` |
| E — Register a company from the panel | ⏳ pending | `feat/sa-05-panel-registration` |
| F — Tests, docs, regression | ⏳ pending | `feat/sa-06-docs-tests` |
