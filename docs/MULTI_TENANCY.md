# BMS Multi-Tenancy — Architecture Reference

*How the database-per-tenant system actually works. Written for the session,
developer or operator who arrives with no prior context.*

Companion documents:

| Document | Role |
|---|---|
| `ternant.md` | The 11-phase implementation **plan** and its phase tracker. Historical. |
| `docs/MULTI_TENANCY_CONVENTIONS.md` | The binding **contract**: naming, secrets, operator steps, per-tenant migrations. Read before changing anything. |
| **this file** | The **architecture** as built. Start here. |

---

## 1. The model in one paragraph

Every company that registers gets its **own physically separate MySQL database**
(`bms_t{id}`) with **its own MySQL user** (`bms_u{id}`), and is reached at its
own subdomain (`kampunia.bms.bjptechnologies.co.tz`). A small **control
database** (`bms_control`) knows which tenants exist and how to connect to each.
Isolation is enforced by MySQL grants, not by application `WHERE` clauses — a
bug in PHP cannot leak one company's ledger into another's, because the
connection literally cannot see the other database.

---

## 2. The three moving parts

```
                    ┌─────────────────────────────┐
   request  ──────► │  core/tenant_resolver.php   │  which tenant is this host?
   Host: kampunia.  └──────────────┬──────────────┘
   bms.example.tz                  │ subdomain
                                   ▼
                    ┌─────────────────────────────┐
                    │  bms_control.tenants        │  registry: db_name, db_user,
                    │  (the CONTROL database)     │  encrypted password, status
                    └──────────────┬──────────────┘
                                   │ credentials
                                   ▼
                    ┌─────────────────────────────┐
                    │  core/tenant_bootstrap.php  │  returns the $pdo the whole
                    │  bmsConnectPdo()            │  app runs on
                    └──────────────┬──────────────┘
                                   ▼
                    ┌─────────────────────────────┐
                    │  bms_t7  (the TENANT db)    │  310 tables, this company only
                    └─────────────────────────────┘
```

**`includes/config.php` is per-environment and untracked.** It defines the
`DB_*` constants and then calls `bmsConnectPdo()`. This is why the routing logic
lives in the tracked `core/tenant_bootstrap.php` and not in `config.php` — a
rewrite of an untracked file could never reach production through git.

---

## 3. The control database

Fixed name `bms_control` (overridable per environment with `CONTROL_DB_NAME` —
demo uses `demo_control` so its test tenants never mix with production's).
It holds **no business data**, so none of `.claude/reporting-source.md`'s
reporting rules apply to it.

| Table | What it is |
|---|---|
| `tenants` | The registry. One row per company: `subdomain`, `db_name`, `db_username`, `db_password_encrypted`, `status`, `owner_email`. |
| `superadmins` | Platform operators. Ships **empty** — created explicitly with `scripts/create_superadmin.php`. |
| `tenant_provisioning_log` | Step-by-step audit of every signup attempt. **The single fastest diagnostic in this subsystem** when a registration fails. |
| `registration_attempts` | Rate-limiting for the public signup endpoint (3 per IP per hour). |
| `tenant_admin_log` | Who suspended/deleted which tenant, and when. No FK — the record outlives both parties. |
| `tenant_migration_log` | Per-tenant migration outcomes. |

It is created by `php scripts/setup_control_db.php` — **an operator step, never
a deploy migration.** (A deploy migration for it once halted an entire
production deploy: the app's MySQL user lacks `CREATE DATABASE`, the migration
exited 1, and `script_stop: true` correctly stopped everything — including the
second host. Platform infrastructure must never be able to veto a release.)

---

## 4. What happens on every request

`bmsConnectPdo()` in `core/tenant_bootstrap.php` resolves exactly one of these:

| Resolution | Meaning | Result |
|---|---|---|
| `disabled` | `TENANT_MODE` is not `on` | The legacy single-tenant connection. Nothing changes. |
| `none` | Root domain, a reserved label, CLI, an IP | The main application database — these are the platform's own surfaces. |
| `unknown` | Looks like a tenant address, but no such tenant | **Stops the request (404).** Never falls back — otherwise inventing a hostname would serve the main database. |
| `found` + suspended/deleted | Tenant exists, gate closed | 403 / 410. Scoped to that tenant alone. |
| `found` + active/trial | A live tenant | Connects as `bms_u{id}` to `bms_t{id}`. |

Two things worth knowing:

- **The base domain can never be a tenant.** `extractTenantSubdomain()` returns
  `null` for the base domain itself, so `bms.example.tz` always takes the legacy
  path. A tenant always lives on a label in front of it.
- **The cross-tenant session guard lives here, not in `header.php`.** 565 files
  include `config.php`; far fewer include `header.php`. A guard that misses the
  API surface is not a guard. A session carrying another tenant's id is
  destroyed and the request refused.

---

## 5. How a new company is created

`core/tenant_provisioner.php` → `provisionTenant()`, driven by the public
endpoint in `core/tenant_registration.php`.

1. Validate (subdomain format, reserved labels, email, password, honeypot, throttle).
2. **Reserve the registry row first** — the database name needs the `id` that only
   the row's `AUTO_INCREMENT` can produce. (The plan originally had this backwards.)
3. `CREATE DATABASE bms_t{id}`, load `schema/tenant_schema_template.sql`.
4. Load `schema/tenant_seed_defaults.sql` — chart of accounts, account types and
   categories, roles, permissions, role-permission mappings.
5. `CREATE USER bms_u{id}` + `GRANT ALL ON bms_t{id}.*` — **that database only**.
6. Create the owner user from the signup form.
7. **Reconnect as the tenant's own credentials and verify** — if the GRANT were
   wrong, the failure would otherwise surface at the customer's first login.
8. Finalise the registry row, storing the password encrypted (`tenc:v1:…`).

Any failure rolls the whole thing back: database, user and registry row.

**Provisioning uses its own MySQL account (`bms_prov`), not the app's.** Creating
databases and granting privileges needs `WITH GRANT OPTION`; the everyday app
user must never have that, or an app-user compromise would break isolation
outright — the entire point of the project.

---

## 6. Adding a schema change that must reach every tenant

Put it in **`migrations/tenant/`** (not `migrations/`, which only ever touches
the single main application database). Read `migrations/tenant/README.md` first.

```bash
php core/tenant_migration_runner.php              # every live tenant
php core/tenant_migration_runner.php --tenant=7   # one tenant (retry/debug)
php core/tenant_migration_runner.php --dry-run    # report only
```

It runs automatically on deploy, last per host, guarded so **a tenant-side
failure can never abort the release**. One tenant's failed migration stops only
that tenant's sequence; every other tenant still receives its migrations.

**The one rule that matters most:** a file under `migrations/tenant/` must never
`require` `roots.php` or `includes/config.php` — that would reconnect `$pdo` to
the main database mid-migration and silently run every later statement against
the wrong database. Use `core/tenant_migration_bootstrap.php`.

---

## 7. Environment variables

These must be **real server-level variables** (Apache `SetEnv` in each vhost).
`putenv()` inside `config.php` covers CLI only — `register.php` is standalone and
never loads `config.php`, which is exactly how self-registration once shipped
silently broken.

| Variable | Purpose |
|---|---|
| `TENANT_MODE` | `on` enables everything. Anything else = single-tenant. |
| `TENANT_BASE_DOMAIN` | The domain subdomains hang off. Without it nothing resolves. |
| `TENANT_CRED_KEY` | 64 hex chars. Decrypts tenant credentials. Falls back to `includes/tenant_cred_key.php`. |
| `CONTROL_DB_NAME` | Override the control database name (demo uses `demo_control`). |
| `CONTROL_DB_USER` / `_PASS` | The control connection's own account. Unset = falls back to the app's credentials. |

Turning it **off** is unsetting `TENANT_MODE` — no deploy required.

---

## 7b. Feature entitlements — selling part of the platform

*(ternant.md Phase 11. Ships inert: every feature is granted to every tenant until
an operator switches something off, so nothing changed the day it merged.)*

### The two axes, and why entitlement is checked first

| Axis | Question | Where it lives | Who decides |
|---|---|---|---|
| **Entitlement** | Does this company's subscription include this module at all? | `bms_control` — `features`, `tenant_features` | The platform |
| **Permission** | Which of the things this company HAS may this user touch? | The tenant's own DB — `role_permissions` | The tenant's own admin |

Entitlement is evaluated **before every `isAdmin()` bypass**. This is not a style
preference: `canView()` returns `true` immediately for a tenant administrator, so
an entitlement checked after that line would gate nobody who matters. The data
lives in the control database for the same reason — a flag the tenant can reach
is not a flag, and Phase 9 already proved tenants cannot read it.

**Off means off.** `VIEW`, `CREATE`, `EDIT`, `DELETE` and every workflow verb
(`canSubmit`/`canApprove`/`canReject`) refuse together. There is no state in which
a module is invisible but still writable.

### Effective state

> A feature is on for a tenant when `features.is_available = 1`
> **AND** (that tenant's `tenant_features.is_enabled`, or `features.default_enabled`
> when the tenant has no row).

No row means "follow the default", so `setTenantFeatures()` **deletes** an override
that merely restates the default instead of writing it. A tenant left alone keeps
following the default if that default later changes; a redundant row would pin them
forever.

### The five enforcement layers

| Layer | File | What it catches |
|---|---|---|
| 1 | `core/permissions.php` | The 129 `canView()` calls that build the nav, plus every page and API that calls any `canX()` — one edit, no per-page sweep |
| 2 | `core/security_helpers.php` | `enforcePageOrAdmin()`, which has its own admin bypass |
| 3 | `roots.php::handleRoute()` | Every clean URL, including a page that forgot its own `autoEnforcePermission()` call |
| 4 | `core/tenant_bootstrap.php` | `api/`, `ajax/`, `actions/` — excluded from the router, so nothing else reaches them |
| 5 | `sign_document.php` | The public, unauthenticated token link, which has no session to gate |

Refusals are **404, never 403** — a 403 confirms the module exists and is merely
switched off for you.

### Adding a new switchable feature

1. Add an entry to `bmsFeatureRegistry()` in `core/feature_registry.php` —
   `label`, `description`, `default`, `sort_order`, `page_keys`, `paths`.
2. Run `php scripts/setup_control_db.php` (idempotent; `INSERT IGNORE`, so an
   operator's own `is_available`/`default_enabled` edits are never overwritten).
3. Nothing else. No enforcement code changes — every layer reads the registry.

**The registry is curated in code on purpose.** `permissions.module_name` looks
like it would do the job and must not be used: it is a UI label for grouping
checkboxes on `user_roles.php`, it is inconsistent (`Inventory` vs
`Inventory & Products`), and it has no POS/Tenders/Warehouses/Projects split. A
cosmetic rename must never silently change what is gated. The empty `modules`
table is that same idea abandoned halfway; leave it alone.

**⚠ Directory names lie.** `app/bms/pos/` holds 47 files of which only 5 are POS —
the rest is the entire HR module. Declaring `app/bms/pos/` as the POS path would
switch HR off with POS. `app/bms/operations/` mixes Projects with Assets;
`app/bms/stock/` mixes Warehouses with always-on inventory. **Where a directory is
mixed, list files, never the directory.**

### What is never switchable

Page keys absent from every registry entry are always reachable: `dashboard`,
`customers`, `products`, all Finance, all Reports, CRM, Documents (except
`e_signatures`), Settings and System Settings. A company must always be able to
invoice, read its own ledger and administer its own staff, whatever it was sold.

### Operating it

`superadmin.<base>/tenant_view.php?id=N` → **Modules** for one company;
`superadmin.<base>/features.php` for platform-wide availability and the
new-tenant defaults. Every change is written to `tenant_admin_log`
(`update_features` / `platform_feature`) with actor, tenant, module and direction.
Changes take effect on that tenant's **next request** — the resolution is
per-request, not cached in a session.

---

## 7c. Usage quotas — capping seats and storage

*(ternant.md Phase 12. Ships inert: `NULL` = unlimited, and every tenant is
unlimited until an operator sets a real number, so nothing changed the day it
merged.)*

### The two numbers

Two nullable columns on `tenants` (control DB): `max_users`, `max_storage_mb`.
`NULL` means unlimited — never a magic `-1`. Not a `features`/`tenant_features`-
shaped pair of tables: a quota isn't an open-ended catalogue of independent
toggles, it's two numbers per tenant, so it gets two columns.

No plumbing was needed to reach them from a request: `tenant_resolver.php`
already runs `SELECT * FROM tenants`, so both columns arrive inside
`bmsCurrentTenant()` the moment they exist in the schema.

### Why storage is measured live, not with a running counter

An upload attempt is rare — nothing like a per-request cost — so recomputing
the total on the spot is cheap enough to not matter. A live sum can never
drift out of sync; a maintained counter can, silently, the day one of the
50+ upload call sites forgets to update it. Correctness was chosen over a
speed nobody needs here.

The sum is **17 independent queries**, one per table, each in its own
`try`/`catch` — not one `UNION ALL`. A unioned statement fails as a single
unit the moment any member table has a problem; independent queries are the
only way "a table that's later dropped or renamed degrades to zero" is
actually true, rather than taking the whole total down with it.

### The 17 tables

`core/tenant_quotas.php`'s `TENANT_STORAGE_TABLES()` — confirmed by parsing
every `CREATE TABLE` in `schema/tenant_schema_template.sql` for a genuine
`file_size` column, not by name-matching "attachment"/"document":

```
documents, employee_documents, purchase_order_attachments, rfq_attachments,
do_attachments, delivery_attachments, sales_return_attachments,
credit_note_attachments, debit_note_attachments, customer_lpo_attachments,
purchase_receipt_attachments, collateral_attachments, compliance_documents,
inspection_attachments, loan_documents, payment_attachments,
project_progress_report_attachments, customer_attachments, document_templates,
project_scope_documents, user_signatures, compliance_records
```

**Adding a new table that stores files?** Give it a `file_size INT` column at
build time (`.claude/security.md` §19 already requires this for every upload)
and add its name to `TENANT_STORAGE_TABLES()` in the same PR — reviewable in a
diff, the same discipline as the feature registry's `page_keys`.

### Enforcement — two real choke points, found by reading the code, not guessed

- **Seats**: exactly one place ever creates a staff account —
  `app/constant/settings/add_user.php`. The check sits inside its existing
  validation block, counting only `is_active = 1` users — `users.php`'s
  existing activate/deactivate toggle is the "free a seat" release valve.
- **Storage**: there is no shared upload function in BMS to hook into — every
  one of **56 files** (49 under `api/`, 7 under `app/`) re-implements
  `.claude/security.md` §19's 5-step pattern independently. `roots.php` gained
  one `require_once core/tenant_quotas.php`, so all 56 got
  `assertUploadWithinQuota()` for free and needed only their one-line call —
  the same choke-point principle §7b used for `canView()`, applied to a
  codebase area that had no existing choke point to extend.

Four handlers are deliberately excluded, by name, in
`tests/test_quota_enforcement_cli.php` (which also runs a permanent automated
scan proving every *other* `move_uploaded_file()` call is guarded):
`api/backup_actions.php`'s restore path (blocking disaster recovery over a
quota would cause real harm) and three overwritten branding/avatar singletons
(`system_settings.php`, `company_profile.php`, `profile.php`) that don't
accumulate the way business records do.

### Checking current usage — the one narrow exception to a stated invariant

`app/superadmin/tenant_view.php`'s own docblock promises it never opens a
tenant's own database. Current usage exists *only* inside that database — the
whole premise of database-per-tenant — so showing it needs one deliberate,
narrow exception: `tenantUsageSnapshotFor()` (`core/tenant_quotas.php`)
returns exactly two integers, never a row or any business content; reached
only via the "Check current usage" button — never automatically on page
load — through its own separate endpoint
(`actions/superadmin_tenant_usage.php`), kept apart from the limits-writing
one so the single code path that crosses this boundary stays easy to find and
audit. `tenant_view.php` itself still never connects to a tenant's own
database — the promise is unbroken; one clearly-labelled neighbour does the
one necessary crossing.

### Operating it

`superadmin.<base>/tenants/view?id=N` → **Usage & Limits**: set
`max_users`/`max_storage_mb` (blank = unlimited), or click **Check current
usage** for the live count/sum. Every limit change is written to
`tenant_admin_log` (`update_quotas`) with the old value, the new value and the
actor. Takes effect on that tenant's **next request**, same as entitlements.

---

## 8. The test suites, and what each is actually for

```bash
php tests/test_tenant_control_db_cli.php        # registry shape, crypto, no backdoor account
php tests/test_tenant_provisioning_cli.php      # the provisioning engine + rollback
php tests/test_tenant_routing_cli.php           # hostname → database resolution
php tests/test_tenant_registration_cli.php      # the public signup endpoint, failing closed
php tests/test_tenant_superadmin_auth_cli.php   # platform operator auth
php tests/test_tenant_admin_panel_cli.php       # suspend/delete affect ONE tenant
php tests/test_tenant_migration_runner_cli.php  # per-tenant migrations + isolation of failures
php tests/test_tenant_isolation_cli.php         # ← the isolation PROMISE. Re-run before every release.
php tests/test_tenant_module_smoke_cli.php      # ← does the APP work inside a tenant database?
php tests/test_feature_registry_cli.php         # the entitlement catalogue + resolution matrix
php tests/test_feature_gating_cli.php           # ← the five enforcement layers, incl. against a tenant's OWN admin
php tests/test_feature_panel_cli.php            # the superadmin control surface + its endpoints
php tests/test_superadmin_urls_cli.php          # the panel's short URLs, and that a tenant host is never hijacked
php tests/test_tenant_quotas_cli.php            # quota schema, resolution, the 5-table undercount fix
php tests/test_quota_enforcement_cli.php        # ← add_user.php + all 56 upload handlers, permanently audited
php tests/test_quota_panel_cli.php              # the usage/limits panel + the narrow database-crossing exception
```

The last two carry the weight:

- **`test_tenant_isolation_cli.php`** is adversarial — it holds one tenant's
  credentials and tries to reach another's data. It contains two deliberate
  anti-vacuity guards (a positive control, and a helper validated against a real
  leak). **Do not remove them**; without them a broken connection would make
  every "refused" assertion pass.
- **`test_tenant_module_smoke_cli.php`** covers the one risk no other suite
  touches: a table or column that exists in the application database but never
  made it into the schema template would break that module for **every new
  customer** while working perfectly for the original company.

**Writing a new tenant test?** Any probe that spawns a subprocess must be
*hermetic*. `config.php` `putenv()`s the machine's own `TENANT_MODE` /
`TENANT_BASE_DOMAIN` and will silently overwrite what your test set — and the
parent test process loads `config.php` too, so inheriting the parent's
environment is not neutral either. Copy the pattern in the existing probes.

---

## 9. Known gaps

| Gap | Status |
|---|---|
| Tenant #1 (the original company) is not yet a registered tenant | Phase 7 — its data is still served by the legacy fallback on the base domain. Cost of leaving it: schema changes must be written twice. |
| `bms_control_app` least-privilege control user | Operator step, conventions §12. The capability ships; the production user is not created. |
| Wildcard TLS certificates do not auto-renew | `certbot --manual`, DNS-01. **Expire 2026-11-30.** Needs a calendar reminder or an auth hook. |
| `bms_t{id}` / `bms_u{id}` prefix is hardcoded | In `tenant_provisioner.php`. Demo works around it with `AUTO_INCREMENT = 9000`. Making it configurable is the proper fix. |

---

## 10. When something breaks

**Read `tenant_provisioning_log` first.** It records `step`, `status` and
`message` per provisioning step and is by far the fastest way to find out what
actually happened — the customer-facing message ("We could not finish setting up
your account") deliberately says nothing useful.

Other recurring ones:

- *"Registration is not available on this installation"* → `TENANT_MODE` is not
  reaching the web layer. Check the vhost `SetEnv`, not `config.php`.
- *"Registration failed. Please try again."* in the browser → stale CSRF token on
  a long-open page, or a non-JSON response. Hard-refresh first.
- Repeat signups silently blocked → the 3/IP/hour throttle.
  `DELETE FROM registration_attempts` in that environment's control database.
- Superadmin panel 404s → once tenancy is genuinely on, it lives at
  `superadmin.<host>/app/superadmin/login.php`, not the plain domain.
