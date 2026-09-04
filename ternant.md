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
| Tenant resolution | **Subdomain-based** (e.g. `kampuniA.bms.bjptechnologies.co.tz`) | Standard SaaS pattern; required for a clean self-registration UX (no login-time "which company" typing). Needs wildcard DNS + wildcard vhost — see Phase 0. |
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
| **11** | Tenant feature / module entitlements | Superadmin grants/revokes whole feature areas (POS, Projects, Tenders, Warehouses, Procurement, Sales, HR…) per tenant — a plan-level gate, separate from and enforced ahead of each tenant's own role permissions | 🔴 High (Phase 11.B touches every request) |

**Total: ~12 phases, ~19–28 working days**, matching the earlier estimate — this document is the "no gap" breakdown behind that number.

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
| `scripts/setup_control_db.php` (new, CLI) | Creates the `bms_control` database and its tables, idempotently. ⚠️ **Revised after a real production failure (2026-08-31):** this was a deploy migration, and production's app user lacks `CREATE DATABASE`, so `script_stop: true` halted the ENTIRE deploy over an optional, not-yet-enabled subsystem. The control DB is platform infrastructure, not tenant schema — it is now an operator step alongside the other §9 steps. |
| Table: `tenants` | `id, company_name, subdomain (unique), db_host, db_name, db_username, db_password_encrypted, status ENUM('active','suspended','trial','deleted'), plan, owner_email, created_at, activated_at, suspended_at` |
| Table: `superadmins` | `id, name, email (unique), password_hash, created_at` — completely separate from any tenant's `users` table. |
| Table: `tenant_provisioning_log` | `id, tenant_id, step, status, message, created_at` — audit trail of each provisioning attempt (for debugging failed signups). |
| `core/control_db.php` (new) | `getControlPdo()` — the **only** function in the codebase allowed to hold a hardcoded connection (to `bms_control` itself, using its own low-privilege user). |
| `core/tenant_crypto.php` (new) | `encryptTenantSecret($plain)` / `decryptTenantSecret($cipher)` using `TENANT_CRED_KEY` (libsodium or openssl AES-256-GCM). |

### Acceptance gate

```bash
php scripts/setup_control_db.php     # bms_control + 3 tables created
php scripts/setup_control_db.php     # second run is a no-op (idempotent)
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

> ⚠️ **Revised same-day, after §10's production incident** (a deploy migration
> for the optional, not-yet-live control database halted the entire deploy —
> see `docs/MULTI_TENANCY_CONVENTIONS.md` §10 and the 2026-08-31 hotfix in
> `changelog.md`). Wiring this phase's runner into `deploy.yml` that day, with
> **zero real tenants**, would have repeated that exact mistake for no benefit,
> so it was deferred.
>
> ✅ **Wiring completed 2026-09-02**, now that real tenants exist — under the
> rule stated then: its failure must never abort the main application's deploy.
> It runs last per host, guarded with `|| echo`. A failure in the app's own
> `migrations/runner.php` still aborts the deploy, unchanged. Note this shipped
> **independently of the rest of Phase 7** — registering Tenant #1 is still
> pending. Full reasoning and the verification performed in conventions §11.

### What ships

| File | Purpose |
|---|---|
| Table `schema_migrations` (in **every** tenant DB, via the schema template) | `migration_name, applied_at` — tracks what's been run per tenant. |
| `core/tenant_migration_bootstrap.php` (new) | What `roots.php` is to an app migration: connects `$pdo` to whichever tenant the runner is currently processing, via `TENANT_MIGRATION_DB_*` env vars (never argv — a password on the command line is visible to anyone who can `ps` the host). |
| `core/tenant_migration_runner.php` (new) | Loops every tenant with a live database (`status != 'deleted'`) in `bms_control.tenants`, applies any migration file under `migrations/tenant/` not yet recorded in that tenant's `schema_migrations`. ⚠️ **Revised:** a broken migration stops **only that tenant's** remaining migrations for the run — every other tenant still receives its migrations normally. See conventions §11 for why this reads differently from the acceptance gate below. |
| `migrations/tenant/` (new folder, + `README.md`) | **From this point forward**, all new schema changes that must reach every tenant go here. |
| `.github/workflows/deploy.yml` (updated) | ✅ **Wired 2026-09-02.** Runs last per host, guarded with `\|\| echo` so a tenant-side failure warns loudly but never aborts the release on either host. The same commit extended the CI migration lint to `migrations/tenant/*.php`, which the non-recursive `migrations/*.php` glob had never covered. |

### Acceptance gate

- A new dummy migration dropped into `migrations/tenant/` gets applied to every existing tenant DB on the next runner execution, and only once (idempotent). ✅ Executed for real in `tests/test_tenant_migration_runner_cli.php`.
- A deliberately broken migration is clearly logged with which tenant failed and fails loud (console, `migrations/tenant_deploy.log`, `tenant_migration_log`) — ~~deploy pipeline stops, doesn't silently continue to other tenants~~ **revised:** stops only that tenant's sequence; every unaffected tenant still receives the same migrations in the same run, proven with a genuine divergence (the identical file fails against one tenant's pre-existing state and succeeds against another's).

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
| Control DB user hardening | `getControlPdo()` uses its own least-privilege MySQL user (only the 3 control tables), never `root`. ⚠️ **Partially done — the code capability ships, the production user is an OPERATOR step.** Which MySQL user an environment uses is not a property of the code, and a hard "never root" assertion would fail every developer's machine (the same mistake the old "superadmins ships empty" check made). The suite therefore asserts the capability — a dedicated user can be supplied purely by `CONTROL_DB_USER`/`CONTROL_DB_PASS`, no file edit — and *reports* the live posture, printing the exact `CREATE USER`/`GRANT` SQL when it sees a privileged account. Creating `bms_control_app` on demo and bms is still outstanding; see conventions §12. |
| Credential audit | Confirm no tenant DB password is ever logged in plaintext (check `tenant_provisioning_log`, error logs, activity logs). |

### Acceptance gate

`php tests/test_tenant_isolation_cli.php` — every isolation assertion passes. This test becomes part of the permanent suite (like `tests/test_project_scope_cli.php`) and should be re-run before every future release.

✅ **Met 2026-09-02 — 48 assertions, 0 failures.** The suite provisions two real
tenants, proves the isolation claim from an attacker's position, and removes them
(verified: no orphaned registry rows, databases or MySQL users).

**Two anti-vacuity guards are load-bearing — do not remove them.** Every "refused"
assertion would also pass if the connection were simply broken, so section 2 carries
a **positive control** proving the same connection can freely create, write and read
inside its *own* database. Separately, the `refused()` helper was validated against a
deliberately over-privileged user granted `` `bms_t%`.* ``: it reported the leak on
every path, so a real regression fails loudly rather than slipping through green.

### Rollback

N/A — this phase only adds tests and tightens permissions; nothing to roll back functionally.

---

## Phase 10 — Full Regression + Go-Live Checklist

**Branch:** `feat/tenant-10-go-live`
**Risk:** 🟠 Medium

### What ships

- ✅ **Automated regression across every major module, against a genuinely fresh
  tenant** — `tests/test_tenant_module_smoke_cli.php` (43 assertions). Covers the
  one risk no other suite touches: a table or column present in the application
  database but missing from `schema/tenant_schema_template.sql` would break that
  module for **every new customer** while working perfectly for the original
  company. It asserts full table parity, column parity on every module table,
  usable seeded defaults (105 accounts, 8 roles, 156 permissions, 600 mappings),
  that the owner created at signup can actually authenticate, that all four
  statutory reports run and reconcile, that all ten module groups read cleanly,
  and a real GL round-trip (post → Trial Balance → Balance Sheet still balances).

  > **Deviation from the plan, stated plainly:** this was specified as running
  > against Tenant #1 **and** a fresh tenant side by side. Tenant #1 does not
  > exist — Phase 7 is still pending — so the comparison is made against the
  > **application database** instead. That is the same comparison in substance:
  > the established schema versus what a new customer is actually given. The
  > "manual regression across every module" half remains genuinely outstanding;
  > this automates the part that is worth automating and re-runs forever.

- ✅ `docs/MULTI_TENANCY.md` — architecture reference for future sessions: the
  model, the control DB schema, what happens on every request, the provisioning
  flow, how to add a per-tenant migration, environment variables, what each test
  suite is for, known gaps, and a troubleshooting section.
- ✅ Changelog entries recorded per phase as each merged (rather than
  consolidated retrospectively, which would have duplicated existing history).
- Go-live checklist — **live status:**
  - [x] DNS wildcard live and verified — `*.bms.` and `*.demo.`, real wildcard
        certs on the app server, verified end-to-end with `openssl s_client`.
  - [x] `TENANT_CRED_KEY` present in production (vhost `SetEnv` +
        `includes/tenant_cred_key.php`), never in the repo. Independently
        generated per host.
  - [ ] **Tenant #1 credentials rotated** — blocked on Phase 7. Note the original
        premise ("off `root`") never applied: production has always used
        `user_bjp`, not root.
  - [x] Superadmin accounts created on both demo and bms.
  - [x] Isolation test suite green — 48 assertions (Phase 9).
  - [x] Tenant migration runner wired into `deploy.yml`, guarded so it can never
        abort a release; both no-op paths verified. **Not yet exercised against a
        real tenant on a server** — `migrations/tenant/` currently holds no
        migration files, so the first real run will be the first true test.
  - [ ] **`bms_control_app` least-privilege control user** created on demo and bms
        (conventions §12) — capability ships, operator step outstanding.
  - [ ] **Wildcard certificate renewal** — `certbot --manual`, no auto-renew,
        **expires 2026-11-30 on both hosts.** Needs a calendar reminder.

### Rollback

Standard `git revert`; this phase is documentation + verification, not new risk surface.

---

## Phase 11 — Tenant Feature / Module Entitlements

**Goal.** Give the superadmin the ability to grant or revoke whole feature
areas per tenant — e.g. one company gets only POS, another gets only
Warehouses + Sales + Procurement, another gets everything except Tenders —
independent of and enforced **ahead of** that tenant's own role-permission
system. **Confirmed requirement: if a module is off, VIEW, CREATE, EDIT,
DELETE and every workflow action on it (`canSubmit`/`canApprove`/etc., §11.1
of `.claude/templates.md`) are all off — no partial state. Not even that
tenant's own Admin can see or bypass it.**

> **This is not a from-scratch design.** A prior deep-dive,
> `superadmin_control_plan.md` (2026-09-02), already investigated this exact
> problem and is the primary source for the architecture below. Every one of
> its load-bearing findings was **re-verified against the code on
> 2026-09-03** before being trusted here — re-verification below.
> `superadmin_control_plan.md` is now superseded by this section; keep this
> one updated going forward, not that file, so the plan doesn't fork in two
> directions.

### What was re-verified, corrected, or extended from that prior plan

| # | Prior plan said | Re-verified 2026-09-03 | Outcome |
|---|---|---|---|
| 1 | `canView()` (`core/permissions.php:74`) returns `true` immediately on `isAdmin()`, before any permission-row check | Confirmed — line 77-79, unchanged | **Holds.** The gate must sit above this and run before it. |
| 2 | `enforcePageOrAdmin()` (`core/security_helpers.php:89`) has the same admin bypass on its very first line | Confirmed — line 89, unchanged. Currently unused by any live page (only referenced in planning docs), but its own docblock invites new pages to adopt it | **Holds**, and is a latent trap for future pages even though nothing calls it live today. |
| 3 | `app/bms/pos/pos.php` performs **no** permission check at all | **Contradicted.** Line 9 today reads `autoEnforcePermission('pos');` before `header.php` is even included | **Stale — corrected.** This specific gap is already closed. Downgraded from "fix" to "regression-test assertion" in 11.B's acceptance gate, and folded into a general audit (11.B) for any *other* page that skipped enforcement the same way this one apparently once did. |
| 4 | `permissions.module_id` is NULL on every row; `module_name` is inconsistent (`Inventory` vs `Inventory & Products`, `Settings` vs `System Settings`) and has no POS/Projects/Tenders/Warehouses split | Confirmed by direct query of `schema/tenant_seed_defaults.sql` — 16 distinct `module_name` values, `module_id` NULL throughout, and a genuine dead `modules` table (`module_id, module_name, description`) already sitting in `schema/tenant_schema_template.sql` with **zero seed rows** | **Holds, and is stronger than stated.** The empty `modules` table is not a TODO to finish — it's a second, structurally different concept (UI *label* grouping for `user_roles.php`) that must **not** be reused as the security boundary. A `module_name` rename for cosmetic reasons must never silently change what's gated. Build a separate, curated registry (11.A). |
| 5 | `header.php` alone contains 129 `canView()` calls driving the whole nav | Confirmed by direct count: exactly 129 | **Holds.** Fixing `canView()` fixes the entire menu in one edit. |
| 6 | `handleRoute()` (`roots.php`) is the single dispatch point for every clean URL | Confirmed — function exists, single definition | **Holds.** |
| 7 | `sign_document.php` is a public, unauthenticated entry point (external signer, single-use emailed token, no session) | Confirmed by reading the file's own docblock and bootstrap | **Holds.** Disabling e-signature must close this door too — no session-based gate reaches it. |
| 8 | `api/`, `ajax/`, `actions/` are excluded from the router and bootstrap inconsistently | Not yet independently re-verified file-by-file (large surface) | **Carried forward as an explicit 11.A/11.B task**, not assumed — §11.B's acceptance gate requires proving it with a real API/AJAX call per gated feature, not inferring it. |
| 9 | The *Operations* nav menu is in substance the HR module (Workforce, Org Structure, Performance & Growth, Payroll, Attendance & Leave, Meetings/Trips) with *Assets & Maintenance* appended | Consistent with `header.php` (Operations dropdown gated by `canView('employees') \|\| canView('assets')`, Workforce section first) | **Holds.** `hr` and `assets` stay two separate feature keys, so Assets survives when HR is switched off. |
| — | *(new — not in the prior plan)* | You explicitly asked for **Tenders, Warehouses, Procurement and Sales** as independently grantable, not bundled with their parent nav groups | **Extended.** The prior plan's initial registry (`projects`, `pos`, `hr`, `assets`, `ai_assistant`, `esignature`) is missing exactly the four you led with. Added below. |
| — | *(new)* | Prior plan's finding #1 and its Phase C acceptance gate name `canView()` specifically; your instruction is that create/edit/delete/workflow must be equally blocked | **Extended.** 11.B patches `canCreate()`, `canEdit()`, `canDelete()` identically to `canView()` (all four already funnel through the same file), and the acceptance gate asserts all four explicitly, not just view. |
| — | *(found during 11.A)* | Prior plan proposed gating POS by the path `app/bms/pos/` | **Corrected before it shipped.** That directory holds **47 files, of which only 5 are POS** — the rest is the entire HR module (payroll, employees, leaves, org chart, recruitment, meetings…). `app/bms/pos/` as the POS path would have switched **HR off whenever POS was switched off**. `app/bms/operations/` mixes Projects with Assets, and `app/bms/stock/` mixes Warehouses with always-on inventory pages, the same way. Rule now stated in `core/feature_registry.php`: **where a directory is mixed, list files, never the directory** — with a test asserting `app/bms/pos/payroll.php` is *not* owned by `pos`. |
| — | *(deviation, stated plainly)* | Prior plan claimed entitlements ride the tenant row for **"zero additional queries per request"** | **Not achievable as described, and not faked.** `resolveTenantFromRequest()` returns one tenant row; joining ten feature rows onto it would change that function's return shape for every caller. 11.A instead performs **one** small indexed read of a ten-row table, on the control connection already open, once per tenant request — then every later check is an array lookup. The acceptance gate below says "one", not "zero". |

### Guiding decisions

| Decision | Choice | Why |
|---|---|---|
| Where entitlement data lives | **Control database only** (`bms_control`), never any tenant DB | A tenant's own Admin bypasses tenant-DB permission rows via `isAdmin()` (finding #1) and can reach their own database outright. A flag the tenant can read or write is not a platform-level flag. Phase 9 already proved tenants cannot reach the control DB. |
| Levels of control | **Two**: platform-wide `is_available` (removed for everyone) and per-tenant `is_enabled` (granted/revoked for one company). Effective = `available AND enabled` | Answers both real asks: "this feature isn't ready for anyone yet" and "this one customer doesn't get it." |
| Default for a new tenant | Each registry entry carries `default_enabled` | Lets a feature ship off-by-default (e.g. a new experimental module) without editing every tenant row. |
| Registry source | **Explicit, curated, in code** (`core/feature_registry.php`), never derived from `permissions.module_name` | Finding #4 — that column is inconsistent and was never meant to be a security boundary. A code registry is reviewable in a PR diff, the same discipline as `getPagePermissionMapping()` already sets. |
| A page_key may belong to more than one feature | Yes — e.g. the shared `dn` (Delivery Note) page_key is used by both the Sales-outbound flow and the Procurement-inbound flow; access requires **at least one** of its owning features to be enabled | Mirrors the OR-of-`canView()` pattern `header.php` already uses for dropdown visibility — not a new idea, just applied one level up. |
| Enforcement point | **One choke point**: `canView()`/`canCreate()`/`canEdit()`/`canDelete()` in `core/permissions.php`, checked *before* the `isAdmin()` line — plus `enforcePageOrAdmin()`, plus `handleRoute()`, plus a request-path guard in `core/tenant_bootstrap.php` for `api/`/`ajax/`/`actions/`, plus an explicit check in `sign_document.php` | Defence in depth across every surface a request can take (findings #1, #2, #6, #7, #8) — not just the menu, which finding #3's history shows is not enough on its own. |
| Behaviour when disabled | **404, not 403** | A 403 confirms to a curious tenant user that the feature exists but is switched off for them. A 404 says nothing — matches this codebase's existing `assertSuperadminHost()` convention. |
| Single-tenant / legacy safety | With `TENANT_MODE` off, or no tenant resolved for the request, **every feature reports enabled** | The same discipline Phase 3 used — this must be inert everywhere multi-tenancy itself hasn't been switched on. |
| Resolution cost | Rides the **same** control-DB row lookup `bmsConnectPdo()` already performs once per request — zero additional queries | `$GLOBALS['__bms_tenant']` already exists (Phase 3); entitlements are fetched alongside it and cached the same way. |

### Data model (control DB — new, via `scripts/setup_control_db.php`, never a `migrations/` file)

```sql
-- The catalogue: one row per switchable feature, platform-wide.
CREATE TABLE IF NOT EXISTS `features` (
    `feature_key`     VARCHAR(64)  PRIMARY KEY,   -- 'pos','projects','tenders','warehouses','procurement','sales','hr','assets','ai_assistant','esignature'
    `label`           VARCHAR(100) NOT NULL,
    `description`     VARCHAR(255) NULL,
    `is_available`    TINYINT(1)   NOT NULL DEFAULT 1,  -- 0 = removed platform-wide, overrides every tenant
    `default_enabled` TINYINT(1)   NOT NULL DEFAULT 1,  -- state a newly provisioned tenant starts with
    `sort_order`      INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Per-tenant override. Absence of a row = "use features.default_enabled".
CREATE TABLE IF NOT EXISTS `tenant_features` (
    `tenant_id`   INT         NOT NULL,
    `feature_key` VARCHAR(64) NOT NULL,
    `is_enabled`  TINYINT(1)  NOT NULL,
    `updated_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_by`  INT         NULL,          -- superadmins.id, no FK — audit must outlive the operator's own account
    PRIMARY KEY (`tenant_id`, `feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

Effective state, one sentence: **a feature is on for a tenant when `features.is_available = 1` AND (that tenant's `tenant_features.is_enabled`, or `features.default_enabled` when no override row exists).**

### Initial feature registry (`core/feature_registry.php`)

| Key | Label | Primary page_keys (confirmed exact set during 11.A) | Default |
|---|---|---|---|
| `sales` | Sales | `quotations`, `sales_orders`, `lpo`, `dn` *(shared with `procurement`)*, `invoices`, `sales_returns`, `credit_notes`, `sales_customer`, `sales_forecast`, `daily_sales` — excludes `pos`, which is its own key below | on |
| `pos` | Point of Sale | `pos` + the `pos/dashboard` route + everything under `app/bms/pos/`, `api/pos_*`, `ajax/pos_*` | on |
| `procurement` | Procurement | `suppliers`, `rfq`, `purchase_orders`, `grn`, `dn` *(shared with `sales`)*, `received_invoices`, `purchase_returns`, `debit_notes`, `nip_materials`, `purchase_report` — excludes `tenders`, which is its own key below | on |
| `tenders` | Tenders | `tenders` | on |
| `warehouses` | Warehouses | `warehouses`, `locations` — deliberately **narrow**: general product catalogue (`products`, `categories`, `stock_adjustments`, `inventory_valuation`) stays an always-on base capability, since POS/Sales/Procurement all need product lookups regardless of which of *those* features are enabled | on |
| `hr` | Human Resources | The Operations nav's Workforce/Org Structure/Performance/Payroll/Attendance/Leave/Meetings/Trips group — `hr_dashboard`, `employees`, `employee_lifecycle`, `employee_contracts`, `org_chart`, `hr_performance`, `trainings`, `announcements`, `meetings`, `employee_trips`, `hr_checklists`, `recruitment`, `my_hr`, `payroll` (+ settings, payslip), `leaves` (+ application, details, reports), `attendance` | on |
| `assets` | Assets & Maintenance | `assets`, `asset_report`, `maintenance` — split from `hr` so it survives when HR is switched off | on |
| `projects` | Projects | `projects`, `project_view`, `project_budget_report`, `project_financial_report`, `project_progress_report`, `sub_contractors`, `sub_contractor_details`, `inspection_view`, `print_ipc`, `user_projects` | on |
| `ai_assistant` | AI Assistant | `ai_assistant` + the "Ask BMS" comms entry | on |
| `esignature` | E-Signatures | `e_signatures`, workflow signature includes, **and `sign_document.php` explicitly** (finding #7 — no session to gate) | on |

Core/base page_keys never appear in any feature's list and are always reachable regardless of entitlements: `dashboard`, `customers`, `products`, `profile`, `my_settings`, `user_roles`, `system_settings`, `chart_of_accounts`, and every `Reports`/`Finance` page_key — a tenant must always be able to see its own accounting truth and manage its own staff access, even with every optional module switched off.

### Phase 11.A — Feature Registry & Control-DB Schema

**Branch:** `feat/tenant-11a-feature-registry`
**Risk:** 🟢 Low — ships enforcing nothing, pure data + resolution, same discipline Phase 3 used on its first landing

| File | Purpose |
|---|---|
| `scripts/setup_control_db.php` (extended) | Adds `features` + `tenant_features` (idempotent `CREATE TABLE IF NOT EXISTS`, matching the exact pattern already used for `tenants`/`superadmins`/`tenant_admin_log`), seeds the 10 registry rows from the table above via `INSERT IGNORE`. |
| `core/feature_registry.php` (new) | The curated array above, plus lookup helpers: `featureForPageKey(string $pageKey): array` (returns every owning feature key — a page_key can have more than one), `allFeatureKeys(): array`. |
| `core/tenant_bootstrap.php` (extended) | Immediately after `bmsConnectPdo()` resolves `$GLOBALS['__bms_tenant']`, one joined query against `features`/`tenant_features` for that tenant, cached as `$GLOBALS['__bms_features']` (`['pos' => false, 'hr' => true, …]`). With `TENANT_MODE` off or no tenant resolved: every key reports `true`, no query run. |
| Public API (new) | `tenantFeatureEnabled(string $featureKey): bool`, `tenantFeatures(): array`, `tenantModuleAllowsPage(string $pageKey): bool` (true if the page has no owning feature, or **any** owning feature is enabled — the OR rule for shared page_keys like `dn`). |

**Acceptance gate**

```bash
php scripts/setup_control_db.php     # features + tenant_features created, 10 rows seeded
php scripts/setup_control_db.php     # second run: no duplicate rows (INSERT IGNORE), no error
```

- CLI check over the resolution matrix: available×enabled×default×no-override-row, every combination.
- With no tenant resolved (single-tenant, CLI, platform host): every key returns `true` and **no** control-DB query runs.
- `tenantModuleAllowsPage('dn')` returns `true` when *either* `sales` or `procurement` is enabled, `false` only when both are off.
- Every `page_key` named in the registry **exists in the real `permissions` table** — a registry naming a key that does not exist would gate nothing at all, silently.
- `app/bms/pos/payroll.php` is **not** owned by the `pos` feature (the mixed-directory trap above).

✅ **Met 2026-09-03 — `tests/test_feature_registry_cli.php`, 61 assertions, 0 failures.**
Verified beyond the suite, on the real request path (not a re-implementation of it):
a simulated request to `relivertec.dev.bms.local` resolved tenant 85 onto `bms_t85`
and primed all 10 entitlements ON; writing a single `pos` override for tenant 85
turned POS off for that tenant on the next request while **HR stayed on** and
**tenant 86 (`mufindipower`) was completely unaffected**; base pages (`invoices`)
stayed reachable throughout. The temporary override row was removed afterwards —
neither real tenant carries any entitlement row.

**Rollback:** `git revert <sha>`; the new tables are additive and read by nothing yet.

---

### Phase 11.B — Enforcement (the phase that can break every tenant)

**Branch:** `feat/tenant-11b-enforcement`
**Risk:** 🔴 High — five layers, each independently capable of leaving a gap if skipped. Do not merge with fewer than all five proven.

| Layer | File | Catches | Closes finding # |
|---|---|---|---|
| 1 | `core/permissions.php` — `canView()`, `canCreate()`, `canEdit()`, `canDelete()`, all four, checked **before** the `isAdmin()` line | 129 nav items in `header.php` (all four functions, not just `canView()` — your explicit instruction) | #1, and the create/edit/delete extension |
| 2 | `core/security_helpers.php` — `enforcePageOrAdmin()`, before its own admin bypass | Any future page adopting this helper | #2 |
| 3 | `roots.php::handleRoute()` | Every clean URL — the belt-and-braces net for a page that (like `pos.php` apparently once did) forgets its own `autoEnforcePermission()` call | #3, #6 |
| 4 | `core/tenant_bootstrap.php` — request-path guard keyed on `$_SERVER['SCRIPT_NAME']`/`REQUEST_URI` against each feature's `paths` | `api/`, `ajax/`, `actions/` — none of which reliably go through `handleRoute()` | #8 |
| 5 | `sign_document.php` — explicit `tenantModuleAllowsPage('e_signatures')` check before the token lookup | The one genuinely public, unauthenticated door | #7 |

**Acceptance gate — `tests/test_feature_gating_cli.php` (new), proving for a tenant with `pos` disabled:**

- POS nav item absent from `header.php`'s rendered output.
- `canView('pos')` **and** `canCreate('pos')` **and** `canEdit('pos')` **and** `canDelete('pos')` are all `false` — **explicitly for that tenant's own Admin** (`isAdmin() === true`), not just an ordinary staff role. This is the assertion that matters most, per your instruction that view-off means everything-off.
- `/pos` through `handleRoute()` returns 404.
- `app/bms/pos/pos.php` reached **directly** by URL still returns 404 (regression coverage for finding #3 — proving today's `autoEnforcePermission('pos')` call is real and stays real).
- A POS `api/`/`ajax/` endpoint, called directly with a valid session, returns 404, not a JSON permission-denied body.
- A tenant with `e_signatures` disabled: `sign_document.php?token=<valid, unexpired>` returns 404, not the signing page.
- **Every other feature stays completely unaffected** for that same tenant, and a second tenant with `pos` enabled is completely unaffected — the same one-tenant-at-a-time invariant Phases 6 and 9 already enforce.
- With `TENANT_MODE` off: every one of the above checks instead confirms full access — nothing in this phase is allowed to change single-tenant behaviour.

✅ **Met 2026-09-03 — `tests/test_feature_gating_cli.php`, 39 assertions, 0 failures.**
Every request is simulated in its own subprocess with `HTTP_HOST`/`REQUEST_URI` set, so the
real `includes/config.php` → `bmsConnectPdo()` → guard path executes rather than a
re-implementation of it. Proven with POS disabled for `relivertec` (tenant 85):
`canView`/`canCreate`/`canEdit`/`canDelete`/`canSubmit`/`canApprove`/`hasAnyPermission`
all false **for that tenant's own admin** (`$_SESSION['is_admin'] = true`), while `payroll`
and `invoices` stayed fully available to that same admin; `/pos` and a direct hit on
`app/bms/pos/pos.php` both 404; `api/pos/` and `api/pos_session.php` 404 with a JSON body;
`api/payroll/` unaffected; `sign_document.php` closed with e-signatures off and open again
once re-enabled; `mufindipower` (tenant 86) unaffected throughout; a non-tenant host never
gated. Platform `is_available = 0` was confirmed to beat a tenant override of 1.

**Anti-vacuity guard — load-bearing, do not remove.** Every "refused" assertion would also
pass if the worker had simply crashed, so `blocked()` requires BOTH the absence of the
worker's own success marker AND a real 404 body. Verified by hand besides: a blocked call
returns exactly `{"success":false,"message":"Not found"}` and logs
`feature gate: blocked /api/pos/sale.php — feature "pos" is not enabled for tenant 85`,
and the identical URL returns the pass marker the moment the feature is re-enabled.

**Regression, same day:** `test_tenant_routing_cli` 57, `test_tenant_admin_panel_cli` 51,
`test_tenant_superadmin_auth_cli` 53, `test_tenant_isolation_cli` 48,
`test_tenant_module_smoke_cli` 43, `test_feature_registry_cli` 61 — all 0 failures, with
no `tenant_features` rows and no platform-disabled feature left behind.

> **Note on the `PHP_SAPI` check.** `bmsFeatureGuardPath()` deliberately does **not** ask
> "is this CLI?". It asks "was a tenant resolved for this request?", which is the question
> that actually matters: real CLI (migrations, cron, the suites) resolves no tenant and is
> never gated, while a test that simulates a request by setting `HTTP_HOST` **is** gated —
> which is the only reason this layer is testable at all.

**Rollback:** `git revert <sha>` — no data migration to unwind; the control-DB rows from 11.A stay inert without this phase reading them.

---

### Phase 11.C — Superadmin Feature-Control UI

**Branch:** `feat/tenant-11c-control-ui`
**Risk:** 🟠 Medium

| File | Purpose |
|---|---|
| `app/superadmin/tenant_view.php` (extended) | New "Modules" panel: one toggle per feature, grouped the same way `header.php`'s own nav groups them. Shows the *effective* state and *why* — platform-removed, tenant-disabled, or default. |
| `app/superadmin/features.php` (new) | Platform-wide control: flip `is_available` (removes a feature for every tenant regardless of their own toggle) and `default_enabled` (what new tenants start with). Removing a feature that tenants currently use requires an explicit confirmation stating how many tenants are affected. |
| `actions/superadmin_tenant_features.php` (new) | Writes `tenant_features` rows. Follows the exact existing pattern in `actions/superadmin_tenant_action.php`: CSRF, `postAction()` JS helper, SweetAlert confirm, and calls the **already-existing** `logTenantAdminAction($id, $subdomain, 'update_features', 'enabled: pos,warehouses; disabled: tenders')` — no new audit infrastructure needed, `tenant_admin_log` already does the job. |
| `actions/superadmin_platform_features.php` (new) | Same pattern for the platform-wide screen, logged with `tenant_id = null` (a platform action, not a per-tenant one). |

**Acceptance gate**

- Toggling `pos` off for Tenant A in the UI takes effect on Tenant A's very next request (11.A's cache is keyed per-request, not per-session) and leaves Tenant B provably untouched.
- Platform-removing a feature (`is_available = 0`) overrides a tenant's own `is_enabled = 1` — confirmed by direct test, not inferred from the SQL.
- Every toggle, both per-tenant and platform-wide, produces exactly one `tenant_admin_log` row naming the actor, the tenant (or platform), the feature, and the direction.
- The panel never lets an operator remove `dashboard`/`customers`/`user_roles`/`system_settings` or any other base page_key — these aren't in the registry at all, so there's nothing to toggle, but the UI still gets an explicit test proving the base set can't appear.

**Rollback:** `git revert <sha>` — 11.A/11.B remain correct with no UI to drive them; a tenant's entitlement state simply becomes un-editable until this is restored.

---

### Phase 11.D — Tests, Docs, Regression

**Branch:** `feat/tenant-11d-tests-docs`
**Risk:** 🟢 Low

- Extend `tests/test_tenant_module_smoke_cli.php` (Phase 10) to run once with every feature on (today's behaviour, unchanged) and once with a deliberately mixed set (e.g. only `warehouses` + `sales` + `procurement`, everything else off) — proving the fresh-tenant baseline still fully works either way.
- Extend `app/constant/settings/user_roles.php`'s permission grid (line 229's `permissions` query) to exclude page_keys whose owning feature(s) are all disabled for the current tenant — so a tenant's own Admin never sees, and can never grant a staff member, a permission checkbox for a module their subscription doesn't include. This was flagged directly in conversation as the concrete symptom of *not* doing this: an Admin grants "Tenders" to staff, the checkbox exists, the staff member still hits Unauthorized — confusing, not a security hole, but sloppy.
- New section in `docs/MULTI_TENANCY.md`: the entitlement model, the registry format, how to add a new feature key, and the five enforcement layers with a one-line "what it catches" each.
- Consolidated changelog entry per this repo's standing rule, once 11.A–11.D are all merged.

**Rollback:** Standard `git revert`; documentation + test additions carry no runtime risk.

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
| 2 — Schema Template + Provisioning Engine | ✅ done (2026-08-31) | `feat/tenant-02-provisioning-engine` (stacked on Phase 1) |
| 3 — Connection Routing Layer | ✅ done (2026-08-31) — ships OFF; see conventions §9 to enable | `feat/tenant-03-connection-routing` (stacked on Phase 2) |
| 4 — Authentication Rework | ✅ done (2026-08-31) | `feat/tenant-04-auth-rework` (stacked on Phase 3) |
| 5 — Self-Registration Flow | ✅ done (2026-08-31) | `feat/tenant-05-self-registration` |
| 6 — Superadmin Tenant Panel | ✅ done (2026-08-31) | `feat/tenant-06-superadmin-panel` |
| 7 — Migrate Existing Data to Tenant #1 | ⏳ pending | `feat/tenant-07-migrate-tenant-one` |
| 8 — Migration Runner + Deploy Pipeline | ✅ done — runner 2026-08-31, `deploy.yml` wiring + CI lint 2026-09-02 | `feat/tenant-08-migration-runner`, `feat/tenant-deploy-wiring` |
| 9 — Security Hardening + Isolation Testing | ✅ done (2026-09-02) — 48 assertions green; control-DB least-privilege user remains an operator step (conventions §12) | `feat/tenant-09-isolation-hardening` |
| 10 — Full Regression + Go-Live | ✅ done (2026-09-02) — 43-assertion module smoke vs a fresh tenant, `docs/MULTI_TENANCY.md`, go-live checklist scored. Manual per-module regression + the Tenant-#1 half remain, both blocked on Phase 7 | `feat/tenant-10-go-live` |
| 11.A — Feature Registry & Control-DB Schema | ✅ done (2026-09-03) — 61 assertions green; verified on the real request path against two live tenants | `feat/tenant-11a-feature-registry` |
| 11.B — Enforcement (5 layers) | ✅ done (2026-09-03) — 39 assertions green + 6 regression suites clean; verified against two live tenants | `feat/tenant-11b-enforcement` |
| 11.C — Superadmin Feature-Control UI | ⏳ pending | `feat/tenant-11c-control-ui` |
| 11.D — Tests, Docs, Regression | ⏳ pending | `feat/tenant-11d-tests-docs` |
