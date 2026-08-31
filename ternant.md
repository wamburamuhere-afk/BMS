# BMS — Multi-Tenant (Database-per-Tenant) Implementation Plan (2026-08-31)

**Goal.** Convert BMS from a single-tenant system into a multi-tenant
platform where any company can self-register, gets its **own physically
separate MySQL database** with **its own dedicated MySQL credentials**,
and can be individually activated/suspended by a superadmin without
affecting any other tenant.

**Guiding decisions (confirmed in conversation):**

| Decision | Choice | Why |
|---|---|---|
| Isolation model | **Database-per-tenant** (not shared-DB `tenant_id`) | Physical isolation — a bug can't leak one company's ledger into another's. Matches BMS's existing financial-integrity posture (`reporting-source.md`, `assertLedgerBalanced`). |
| Tenant DB credentials | **Option B — dedicated MySQL username + password per tenant DB**, encrypted at rest | Chosen over a shared app-wide DB user. A leaked credential only ever exposes one tenant. |
| Tenant onboarding | **Self-registration**, fully automated provisioning (no manual DB creation per signup) | Required capability — anyone can register their own company. |
| Tenant lifecycle | Superadmin can activate/suspend/delete **one tenant at a time**, independent of all others | Confirmed: suspending one tenant must never affect others. |
| Schema source for new tenants | A **clean schema-only snapshot**, not a replay of the 305 legacy migration scripts | Verified: `migrations/` contains 305 ad-hoc, non-idempotent one-off scripts, not a clean replayable chain. Replaying them against a fresh DB is not safe or guaranteed correct. |
| Existing production DB | Becomes **Tenant #1**, registered as-is (kept as database `bms`, not renamed) | Avoids a risky rename of a live production database. |
| Tenant resolution | **Subdomain-based** (e.g. `kampuniA.bms.co.tz`) | Standard SaaS pattern; required for a clean self-registration UX (no login-time "which company" typing). Needs wildcard DNS + wildcard vhost — see Phase 0. |
| DB connection scope | Centralized — verified only `includes/config.php` opens the app's PDO connection | Means most of the ~2,358 PHP files need **zero changes**; only the connection-resolution layer changes. |
| Branch workflow | Every phase forks its own branch off `develop`, live-tested, PR'd into `develop` (not `main`) | Matches this repo's established workflow. |
| Approval cadence | One phase at a time in code, but **this document is pre-approved for all phases** — execute phase by phase without re-asking, pause only for a genuine direction-changing fork | Matches how multi-phase work (HR lifecycle, project-scope) has run in this repo. |

> **Phase 0 is complete (2026-08-31).** The naming, secret-handling and
> infrastructure contract every later phase codes against now lives in
> **`docs/MULTI_TENANCY_CONVENTIONS.md`** — read it before starting any phase.
> It also records two corrections to this plan that Phase 0 uncovered (§6):
> `includes/config.php` is untracked, and `*.sql` was gitignored.

---

## Phase overview

| # | Phase | What | Risk |
|---|---|---|---|
| **0** | Pre-flight & conventions | Naming rules, encryption key, DNS/vhost prerequisite, full backup | 🟢 Low |
| **1** | Control database | `bms_control` DB: tenant registry + superadmin accounts | 🟢 Low |
| **2** | Schema template + provisioning engine | Schema snapshot, `TenantProvisioner`, credential generation/encryption | 🟠 Medium |
| **3** | Connection routing layer | Tenant resolution from subdomain, dynamic PDO in `config.php` | 🔴 High (touches every request) |
| **4** | Authentication rework | Tenant-aware login, separate superadmin login | 🟠 Medium |
| **5** | Self-registration flow | Public signup → auto-provision → auto-login | 🟠 Medium |
| **6** | Superadmin tenant panel | List/activate/suspend/delete tenants | 🟢 Low |
| **7** | Migrate existing data to Tenant #1 | Register current `bms` DB + its own credentials in control DB | 🟠 Medium |
| **8** | Multi-tenant migration runner + deploy pipeline | Tracked migrations applied to every tenant DB; `deploy.yml` update | 🟠 Medium |
| **9** | Security hardening + isolation testing | Least-privilege DB user, cross-tenant isolation tests, ledger checks per tenant | 🔴 High (must not skip) |
| **10** | Full regression + go-live checklist | Every module smoke-tested under the new routing layer | 🟠 Medium |

**Total: ~11 phases, ~16–24 working days**, matching the earlier estimate — this document is the "no gap" breakdown behind that number.

---

## Master safety net (every phase)

1. Fork from `develop`. No stacking unless a phase explicitly says so.
   *Phase 1 stacks on Phase 0* — a justified exception: Phase 1 consumes
   `TENANT_CRED_KEY`, and Phase 0's `.gitignore` rule is what stops that key file
   from being committable. Branching Phase 1 off plain `develop` was tried and
   showed `includes/tenant_cred_key.php` as an untracked, addable file. Later
   phases fork from `develop` again once Phase 0/1 merge.
2. `php -l` clean on every changed/new file before commit.
3. **Smoke test live** (not just static checks) before opening a PR — this repo's standing rule: lint-green ≠ works.
4. Every phase that touches login/connection logic must be tested with **two simultaneous tenants** (prove isolation, not just "it works for one").
5. **Rollback line** in every PR body — a single `git revert <sha>` must be enough to undo it.
6. Update the **Phase tracker** (bottom of this file) the moment a phase merges, so any session/terminal can see exactly what's done.
7. Changelog entry per merged phase, per this repo's standing rule.
8. Never touch `main` directly — PRs land in `develop`.

---

## Phase 0 — Pre-flight & Conventions

**Branch:** `feat/tenant-00-preflight`
**Risk:** 🟢 Low (no runtime change)

### What ships

| Item | Detail |
|---|---|
| Naming convention (documented, not code) | Tenant DB: `bms_t{tenant_id}` (numeric, immune to slug collisions). Tenant MySQL user: `bms_u{tenant_id}`. Control DB: `bms_control`. |
| Encryption key | Generate a 32-byte app-level key, store in an environment variable (e.g. `TENANT_CRED_KEY`) — **never committed to git**. Used to encrypt tenant DB passwords at rest in `bms_control`. |
| DNS/vhost prerequisite | Wildcard DNS (`*.bms.<domain>`) + wildcard Apache/WAMP vhost pointing every subdomain at the same webroot. Documented as an infra to-do — confirm with hosting provider before Phase 3. |
| Full backup | `mysqldump` of the current production `bms` database, stored outside the repo, before any phase touches connection logic. |
| Schema-only snapshot | `mysqldump --no-data bms > schema/tenant_schema_template.sql` — this becomes the template every new tenant DB is built from (Phase 2). |

### Acceptance gate

- Backup file exists and restores cleanly to a throwaway DB (verified once, manually).
- `schema/tenant_schema_template.sql` exists, contains `CREATE TABLE` for every current table, zero `INSERT` rows.
- `TENANT_CRED_KEY` present in local `.env`/environment, documented in deploy secrets (not in repo).

### Rollback

Nothing runtime changes in this phase — revert is just deleting the new files.

---

## Phase 1 — Control Database & Tenant Registry

**Branch:** `feat/tenant-01-control-db`
**Risk:** 🟢 Low (new, isolated database — nothing existing reads it yet)

### What ships

| File | Purpose |
|---|---|
| `migrations/2026_08_31_control_db_foundation.php` (new) | Creates the `bms_control` database and its tables (idempotent — `CREATE DATABASE IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`). |
| Table: `tenants` | `id, company_name, subdomain (unique), db_host, db_name, db_username, db_password_encrypted, status ENUM('active','suspended','trial','deleted'), plan, owner_email, created_at, activated_at, suspended_at` |
| Table: `superadmins` | `id, name, email (unique), password_hash, created_at` — completely separate from any tenant's `users` table. |
| Table: `tenant_provisioning_log` | `id, tenant_id, step, status, message, created_at` — audit trail of each provisioning attempt (for debugging failed signups). |
| `core/control_db.php` (new) | `getControlPdo()` — the **only** function in the codebase allowed to hold a hardcoded connection (to `bms_control` itself, using its own low-privilege user). |
| `core/tenant_crypto.php` (new) | `encryptTenantSecret($plain)` / `decryptTenantSecret($cipher)` using `TENANT_CRED_KEY` (libsodium or openssl AES-256-GCM). |

### Acceptance gate

```bash
php migrations/2026_08_31_control_db_foundation.php   # bms_control + 3 tables created
php migrations/2026_08_31_control_db_foundation.php   # second run is a no-op (idempotent)
php -r 'require "core/tenant_crypto.php";
        $c = encryptTenantSecret("test123");
        var_dump(decryptTenantSecret($c) === "test123");'   # must print true
```

### Rollback

`git revert <sha>` + `DROP DATABASE bms_control` (safe — nothing else depends on it yet).

---

## Phase 2 — Schema Template & Provisioning Engine

**Branch:** `feat/tenant-02-provisioning-engine`
**Risk:** 🟠 Medium (creates real databases/users — must be idempotent and fully rollback-safe on partial failure)

### What ships

| File | Purpose |
|---|---|
| `core/tenant_provisioner.php` (new) | `provisionTenant($companyName, $subdomain, $ownerEmail, $ownerPassword)`: <br>1. Validate `subdomain` uniqueness against `tenants`.<br>2. `CREATE DATABASE bms_t{id}`.<br>3. Generate random 24-char password; `CREATE USER 'bms_u{id}'@'%' IDENTIFIED BY '...'`; `GRANT ALL ON bms_t{id}.* TO 'bms_u{id}'@'%'`.<br>4. Apply `schema/tenant_schema_template.sql` to the new DB.<br>5. Seed defaults (default chart of accounts, default roles/permissions — reuse existing seed logic from `migrations/2026_05_13_expense_seeding.php`-style seeders where applicable).<br>6. Create the owner's first user row inside the new tenant DB.<br>7. Insert the `tenants` row with `db_password_encrypted`.<br>8. Log every step to `tenant_provisioning_log`. |
| Rollback-on-failure logic | If any step 2–6 fails: `DROP DATABASE IF EXISTS`, `DROP USER IF EXISTS`, delete the partial `tenants` row — provisioning must never leave an orphaned half-created tenant. |
| `scratch/test_provision_tenant.php` (new, throwaway CLI) | Provisions a real test tenant end-to-end, prints each step's result, then optionally tears it down. |

### Acceptance gate

```bash
php scratch/test_provision_tenant.php --company="Test Co" --subdomain=testco --email=owner@test.com
# Expect: DB bms_t{N} exists, user bms_u{N} can connect ONLY to bms_t{N},
# schema matches template, one user row exists, tenants row present with encrypted password.

# Negative test — force a failure mid-provisioning (e.g. duplicate subdomain), confirm:
# - no orphaned database left behind
# - no orphaned MySQL user left behind
# - no partial tenants row left behind
```

### Rollback

`git revert <sha>`. Any test tenant DBs created during testing are dropped manually as part of the test script's teardown — this phase doesn't touch production data.

---

## Phase 3 — Connection Routing Layer

**Branch:** `feat/tenant-03-connection-routing`
**Risk:** 🔴 High — this is the phase that changes how every single request connects to a database. Test exhaustively before merging.

### What ships

| File | Change |
|---|---|
| `core/tenant_resolver.php` (new) | `resolveTenantFromRequest()`: reads the subdomain from `$_SERVER['HTTP_HOST']`, looks it up in `bms_control.tenants` (via `getControlPdo()`), returns the tenant row or `null` (no subdomain / unknown subdomain / superadmin/marketing host). |
| `core/tenant_bootstrap.php` (new, **tracked**) | ⚠️ **Revised in Phase 0.** This plan originally said "rewrite `includes/config.php`" — but that file is **gitignored and has never been tracked**, so a rewrite would never reach production through the git deploy. All the routing logic therefore ships here, in a tracked file. `includes/config.php` stays a thin per-environment file that `require`s this one (a one-time manual edit per environment). See `docs/MULTI_TENANCY_CONVENTIONS.md` §6.1. |
| ↳ what the bootstrap does | Instead of hardcoded `DB_NAME`/`DB_USERNAME`/`DB_PASSWORD`: call `resolveTenantFromRequest()`. If a tenant is resolved **and** `status = 'active'`: decrypt its credentials, connect `$pdo` to `bms_t{id}` as `bms_u{id}`. If `status = 'suspended'`: stop and show a "service suspended" page — **only for that tenant**, nobody else is affected. If no tenant resolved (root domain, superadmin subdomain): `$pdo` stays unset/null for that request — pages under `app/superadmin/` use `getControlPdo()` instead. |
| `header.php` (guard added) | Confirm `$_SESSION['tenant_id']` (set at login, Phase 4) matches the subdomain being visited on every request — prevents a stale/tampered session from reading a different tenant's data if a cookie is somehow replayed against another subdomain. |

### Acceptance gate

- Two test tenants (from Phase 2) both resolve correctly to their own DB when visited by subdomain.
- Visiting Tenant A's subdomain while a session cookie says Tenant B → session invalidated, forced re-login (isolation guard working).
- Suspending Tenant A (flip `status` in `tenants` table) → Tenant A's subdomain shows "service suspended"; Tenant B's subdomain is completely unaffected — **this is the exact scenario confirmed as a requirement**.
- Root domain / no subdomain → no tenant DB connection opened; doesn't crash.

### Rollback

`git revert <sha>` restores the old hardcoded single-DB `config.php` — since Tenant #1 isn't registered as the *only* path yet until Phase 7, this revert is safe up through Phase 6.

---

## Phase 4 — Authentication Rework

**Branch:** `feat/tenant-04-auth-rework`
**Risk:** 🟠 Medium

### What ships

| File | Change |
|---|---|
| `actions/login.php` | Now authenticates against whichever `$pdo` Phase 3 resolved (the visiting tenant's own DB). On success, sets `$_SESSION['tenant_id']` = the resolved tenant's id (for the header.php guard). |
| `app/superadmin/login.php` (new) | Separate login screen, authenticates against `bms_control.superadmins` only — never touches any tenant DB. |
| `app/superadmin/*` route guard | Every superadmin page checks `$_SESSION['superadmin_id']` (a completely separate session namespace from tenant sessions) and uses `getControlPdo()`. |

### Acceptance gate

- Login on Tenant A's subdomain only ever authenticates Tenant A's users table.
- Superadmin login is unreachable from a tenant subdomain and vice versa.
- A tenant user session and a superadmin session can be open in two different browser profiles simultaneously without cross-contamination.

### Rollback

`git revert <sha>`.

---

## Phase 5 — Self-Registration Flow

**Branch:** `feat/tenant-05-self-registration`
**Risk:** 🟠 Medium

### What ships

| File | Purpose |
|---|---|
| `register.php` (new, public, no auth) | Form: company name, desired subdomain (live-checked for availability via AJAX), owner name/email/password. |
| `ajax/check_subdomain_availability.php` (new) | Quick uniqueness check against `bms_control.tenants`. |
| `actions/register_tenant.php` (new) | Validates input, calls `provisionTenant()` (Phase 2), shows a short "Setting up your account…" state (provisioning takes a few seconds), then redirects to `https://{subdomain}.bms.<domain>/login` with a success message. On provisioning failure: friendly error, nothing left dangling (Phase 2's rollback-on-failure guarantees this). |

### Acceptance gate

- End-to-end: fill the form → new tenant DB exists → redirected → can log in immediately as the owner.
- Duplicate subdomain submission → rejected with a clear message, no partial tenant created.
- Provisioning failure (simulate by breaking a step) → user sees an error, no orphaned DB/user left behind.

### Rollback

`git revert <sha>` — `register.php` disappears; existing tenants unaffected.

---

## Phase 6 — Superadmin Tenant Lifecycle Panel

**Branch:** `feat/tenant-06-superadmin-panel`
**Risk:** 🟢 Low

### What ships

| File | Purpose |
|---|---|
| `app/superadmin/tenants.php` (new) | List all tenants: company name, subdomain, status, created date, plan. |
| `app/superadmin/tenant_view.php` (new) | Detail view + actions: **Activate**, **Suspend**, **Delete**. |
| `actions/superadmin_tenant_action.php` (new) | `suspend`: flips `status` to `suspended` (Phase 3 already enforces the block). `activate`: flips back to `active`. `delete`: drops the tenant's database, drops its MySQL user, marks `tenants.status = 'deleted'` (row kept for audit, not hard-deleted) — requires a typed confirmation (company name) before executing, same pattern as any other destructive action in this codebase. |

### Acceptance gate

- Suspend Tenant A → confirmed via Phase 3's test (A blocked, B unaffected).
- Delete a test tenant → its database and MySQL user are actually gone (`SHOW DATABASES`, `SELECT user FROM mysql.user` confirm), other tenants untouched.
- Every action requires superadmin auth (Phase 4) — unreachable by tenant users.

### Rollback

`git revert <sha>`. Delete actions taken before a revert are **not** undone by the revert (data is genuinely gone) — this is why delete requires typed confirmation.

---

## Phase 7 — Migrate Existing Production Data to Tenant #1

**Branch:** `feat/tenant-07-migrate-tenant-one`
**Risk:** 🟠 Medium (touches real production data — do during a maintenance window)

### What ships

| Step | Detail |
|---|---|
| Register Tenant #1 | Insert a `tenants` row: `subdomain = <current production subdomain>`, `db_name = 'bms'` (kept as-is, not renamed), `db_host = localhost`. |
| Dedicated credentials for Tenant #1 | Per the Option-B decision, create `bms_u1` scoped only to `bms`, `GRANT ALL ON bms.* TO 'bms_u1'@'%'`, store encrypted in `tenants.db_password_encrypted`. Retire the `root`/blank-password connection from `config.php` at the same time (see Phase 9 — bundled here since it's the same touch point). |
| Verify | Every existing user can still log in, through the new tenant-aware `actions/login.php`, against the unchanged `bms` database. |

### Acceptance gate

- Full regression smoke test of core modules (Accounting, HR, POS, Sales, Purchasing) against Tenant #1 post-migration — nothing should look different to end users.
- `assertLedgerBalanced($pdo, today)` passes for Tenant #1 exactly as before.

### Rollback

Revert `config.php`'s tenant-resolution short-circuit back to hardcoded `bms`/root temporarily if something breaks mid-window — full backup from Phase 0 is the final safety net.

---

## Phase 8 — Multi-Tenant Migration Runner & Deploy Pipeline

**Branch:** `feat/tenant-08-migration-runner`
**Risk:** 🟠 Medium

### What ships

| File | Purpose |
|---|---|
| Table `schema_migrations` (new, created inside **every** tenant DB by the schema template) | `migration_name, applied_at` — tracks what's been run per tenant, going forward. |
| `core/tenant_migration_runner.php` (new) | Loops every **active** tenant in `bms_control.tenants`, connects with its own credentials, applies any migration file under `migrations/tenant/` not yet recorded in that tenant's `schema_migrations`, stops on first failure for that tenant and logs it (mirrors `script_stop: true` semantics from `deploy.yml`, per tenant). |
| `migrations/tenant/` (new folder) | **From this point forward**, all new schema changes go here as clean, idempotent, per-tenant migrations — replacing the old ad-hoc `migrations/` pattern for anything that must apply to every tenant. |
| `.github/workflows/deploy.yml` (updated) | After code deploy, run `php core/tenant_migration_runner.php` — applies pending migrations across **all** tenant databases, chained with `&&` so a failure halts the deploy per this repo's standing rule. |

### Acceptance gate

- A new dummy migration dropped into `migrations/tenant/` gets applied to every existing tenant DB (Tenant #1 + any test tenants) on the next runner execution, and only once (idempotent).
- A deliberately broken migration halts the runner and is clearly logged with which tenant failed — deploy pipeline stops, doesn't silently continue to other tenants in an inconsistent state per repo convention (fail loud, not partial-silent).

### Rollback

`git revert <sha>` for the runner code; already-applied tenant migrations are handled per-migration (each migration file should carry its own down/undo path where practical, same discipline as any migration in this repo).

---

## Phase 9 — Security Hardening & Isolation Testing

**Branch:** `feat/tenant-09-isolation-hardening`
**Risk:** 🔴 High — this phase is the actual proof that the isolation promise holds. Do not skip or shortcut.

### What ships

| Item | Detail |
|---|---|
| `tests/test_tenant_isolation_cli.php` (new) | Provisions two throwaway tenants, creates distinct data in each (e.g. an invoice), then asserts: Tenant A's session/connection can under no code path read Tenant B's row — by id-guessing, by direct object reference on any known page pattern, or by session/cookie tampering across subdomains. |
| Per-tenant ledger check | Extend the isolation test to run `assertLedgerBalanced()` independently against each tenant DB. |
| Control DB user hardening | `getControlPdo()` uses its own least-privilege MySQL user (only the 3 control tables), never `root`. |
| Credential audit | Confirm no tenant DB password is ever logged in plaintext (check `tenant_provisioning_log`, error logs, activity logs). |

### Acceptance gate

`php tests/test_tenant_isolation_cli.php` — every isolation assertion passes. This test becomes part of the permanent suite (like `tests/test_project_scope_cli.php`) and should be re-run before every future release.

### Rollback

N/A — this phase only adds tests and tightens permissions; nothing to roll back functionally.

---

## Phase 10 — Full Regression + Go-Live Checklist

**Branch:** `feat/tenant-10-go-live`
**Risk:** 🟠 Medium

### What ships

- Full manual + automated regression across every major module (Accounting/GL, HR, POS, CRM, Sales, Purchasing, Payroll, Reports) run against Tenant #1 **and** a fresh self-registered test tenant side by side.
- `docs/MULTI_TENANCY.md` (new) — architecture reference for future sessions: control DB schema, provisioning flow, how tenant resolution works, how to add a new per-tenant migration.
- Changelog entries for all 10 phases consolidated.
- Go-live checklist:
  - [ ] DNS wildcard live and verified.
  - [ ] `TENANT_CRED_KEY` present in production environment (not in repo).
  - [ ] Tenant #1 credentials rotated off `root`.
  - [ ] Superadmin account created in `bms_control.superadmins`.
  - [ ] Isolation test suite green.
  - [ ] Deploy pipeline's tenant migration runner tested against a staging tenant.

### Rollback

Standard `git revert`; this phase is documentation + verification, not new risk surface.

---

## Decisions to revisit in v2

| Decision | v1 choice | When to revisit |
|---|---|---|
| Billing/subscription enforcement tied to `status` | OUT (status is manual superadmin action only) | When you need automatic suspension on non-payment — wire a billing webhook to the same `suspend` action from Phase 6. |
| Tenant resolution via login-time company code (no subdomain) | OUT (subdomain chosen) | Only if wildcard DNS turns out to be unavailable on your hosting — company-code-at-login is the fallback, reusing the same `resolveTenant()` contract with a different lookup source. |
| Per-tenant custom domains (e.g. `erp.kampuniA.co.tz`) | OUT | When a tenant wants their own branded domain instead of a subdomain — needs a `custom_domain` column on `tenants` and an extra lookup path in `resolveTenantFromRequest()`. |
| Cross-tenant superadmin reporting/analytics | OUT | If you ever want a "total revenue across all tenants" dashboard — requires either querying each tenant DB in a loop, or a separate read-only aggregation pipeline. |

---

## Phase tracker

Update this table the moment each phase merges — this is what lets any session pick up exactly where the last one left off.

| Phase | Status | Branch |
|---|---|---|
| 0 — Pre-flight & Conventions | ✅ done (2026-08-31) | `feat/tenant-00-preflight` |
| 1 — Control Database | ✅ done (2026-08-31) | `feat/tenant-01-control-db` (stacked on Phase 0) |
| 2 — Schema Template + Provisioning Engine | ⏳ pending | `feat/tenant-02-provisioning-engine` |
| 3 — Connection Routing Layer | ⏳ pending | `feat/tenant-03-connection-routing` |
| 4 — Authentication Rework | ⏳ pending | `feat/tenant-04-auth-rework` |
| 5 — Self-Registration Flow | ⏳ pending | `feat/tenant-05-self-registration` |
| 6 — Superadmin Tenant Panel | ⏳ pending | `feat/tenant-06-superadmin-panel` |
| 7 — Migrate Existing Data to Tenant #1 | ⏳ pending | `feat/tenant-07-migrate-tenant-one` |
| 8 — Migration Runner + Deploy Pipeline | ⏳ pending | `feat/tenant-08-migration-runner` |
| 9 — Security Hardening + Isolation Testing | ⏳ pending | `feat/tenant-09-isolation-hardening` |
| 10 — Full Regression + Go-Live | ⏳ pending | `feat/tenant-10-go-live` |
