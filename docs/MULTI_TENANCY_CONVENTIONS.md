# BMS Multi-Tenancy — Phase 0: Conventions & Pre-flight

**Status:** Phase 0 complete. **Date:** 2026-08-31.
**Plan of record:** `ternant.md` (repo root) — 11 phases, this is Phase 0.

This document is the naming/secret/infrastructure contract that every later phase
codes against. Nothing here is executable; Phase 0 deliberately changes **zero**
runtime behaviour. If a later phase needs to deviate from a rule below, change it
*here first* so the convention stays single-sourced.

---

## 1. Naming conventions

| Thing | Pattern | Example | Why |
|---|---|---|---|
| Control database | `bms_control` | `bms_control` | Fixed name. Holds the tenant registry + superadmins. Never holds business data. |
| Tenant database | `bms_t{tenant_id}` | `bms_t7` | Keyed on the numeric `tenants.id`, **not** the subdomain — a tenant can be renamed or change subdomain without touching the database name. Immune to slug collisions and to reserved-word/length limits. |
| Tenant MySQL user | `bms_u{tenant_id}` | `bms_u7` | Same numeric key, so DB↔user pairing is always derivable. |
| Tenant MySQL host grant | `'bms_u{id}'@'%'` | `'bms_u7'@'%'` | Tightened to a specific host in Phase 9 if the app and DB end up on separate machines. |
| Control DB user | `bms_control_app` | — | Least-privilege, created in Phase 9. Only the 3 control tables. Never `root`. |
| Per-tenant migrations | `migrations/tenant/YYYY_MM_DD_description.php` | — | Introduced in Phase 8. All *new* schema changes go here. |

**Tenant #1 is the exception:** the existing production database keeps the name
`bms` (not `bms_t1`). Renaming a live production database is a risk with no
payoff. `tenants.db_name` is a real column precisely so the name does not have to
be derivable — code must **always read `tenants.db_name`**, never compute
`'bms_t' . $id`. The pattern above is what the *provisioner writes into that
column* for new tenants; it is not a lookup shortcut.

### Reserved subdomains

Self-registration (Phase 5) must reject these, case-insensitively, before
provisioning. They are either already in use, needed by infrastructure, or
phishing-adjacent:

```
www  admin  superadmin  api  app  mail  smtp  imap  ftp  ns1  ns2  mx
static  cdn  assets  files  download  uploads  status  health  test  staging
dev  demo  sandbox  billing  support  help  docs  blog  login  signup
register  auth  account  accounts  secure  ssl  vpn  git  ci  bms  root
```

Subdomain format rule: `^[a-z0-9]([a-z0-9-]{1,30}[a-z0-9])$` — lowercase, starts
and ends alphanumeric, 3–32 chars, no consecutive hyphens, no leading/trailing
hyphen. Stored lowercase; compared lowercase.

---

## 2. The master encryption key — `TENANT_CRED_KEY`

Every tenant's MySQL password is stored **encrypted** in
`bms_control.tenants.db_password_encrypted`. `TENANT_CRED_KEY` is the key that
encrypts them.

- **Size:** 32 raw bytes, stored as 64 lowercase hex characters.
- **Algorithm it feeds:** AES-256-GCM (authenticated — a tampered ciphertext
  fails to decrypt rather than returning garbage). Same construction as the
  existing `core/crypto.php`, which Phase 1's `core/tenant_crypto.php` mirrors.
- **Generated:** once, in Phase 0. **Verified:** 32 bytes, round-trips, and
  rejects a tampered ciphertext.

### Where it lives (resolution order for Phase 1)

1. Environment variable `TENANT_CRED_KEY` — **preferred in production.**
2. Fallback file `includes/tenant_cred_key.php`, which defines the constant
   `TENANT_CRED_KEY`. This is the local/WAMP path and matches the repo's existing
   `includes/ai_app_secret.php` convention.

Both are **gitignored**. The file is `chmod 0600`.

### Three rules that must not be broken

1. **Never commit it.** `.gitignore` covers `includes/tenant_cred_key.php`.
2. **Never regenerate it.** Unlike `core/crypto.php`'s `aiAppSecret()` — which
   auto-generates on first use — this key must **not** self-generate. Generating
   a new key silently orphans every stored tenant credential, locking the
   platform out of every tenant database at once. Phase 1's key loader must
   therefore **throw loudly if the key is missing**, never quietly create one.
3. **Back it up separately.** Store it in the password manager, *not* beside the
   database backup — a single stolen archive should not yield both the encrypted
   credentials and the key that opens them.

**Deploy secret:** production must supply `TENANT_CRED_KEY` as an environment
variable (or have the file placed out-of-band on the server). It is **not** in
the repo, so it will not arrive via `git`. This is a manual go-live step — it is
on the Phase 10 checklist.

---

## 3. Infrastructure prerequisite — wildcard DNS + vhost

Tenant resolution is **subdomain-based** (`kampuniA.bms.co.tz`). Phase 3 cannot
be tested end-to-end without this, so it must be confirmed with the hosting
provider **before Phase 3 merges**.

Two things are needed:

1. **Wildcard DNS:** an `A`/`CNAME` record for `*.bms.<domain>` pointing at the
   production server.
2. **Wildcard vhost:** one Apache vhost with `ServerAlias *.bms.<domain>`
   serving the same webroot. Tenancy is resolved in PHP from
   `$_SERVER['HTTP_HOST']` — there is **one** codebase and **one** vhost, not one
   per tenant.
3. **Wildcard TLS certificate** for `*.bms.<domain>` (Let's Encrypt wildcard
   requires DNS-01 validation, so the provider must expose DNS API access).

**Local (WAMP) testing:** wildcards are not available in `hosts`. For Phases 3–6,
add explicit entries per test tenant:

```
127.0.0.1   t1.bms.local
127.0.0.1   t2.bms.local
127.0.0.1   admin.bms.local
```

with a matching vhost using `ServerAlias *.bms.local`. Two tenants are the
minimum — every isolation claim in this project is only meaningful when tested
with **two** tenants side by side, never one.

**If wildcard DNS turns out to be unavailable**, the documented fallback is
company-code-at-login (see `ternant.md` → "Decisions to revisit in v2"). That
changes only the lookup source inside `resolveTenantFromRequest()`, so keep that
function's contract narrow: *request in → tenant row or null out*.

---

## 4. Backups

| Item | Location |
|---|---|
| Full pre-flight backup | `C:\wamp64\bms_backups\bms_full_backup_2026_08_31.sql` (11.2 MB) |
| Schema-only template | `schema/tenant_schema_template.sql` (in repo, 383 KB) |

The full backup lives **outside the repo and outside the webroot** — it contains
real business data and must never be web-servable or committed.

**Verified on 2026-08-31:** the backup restores cleanly into a throwaway database
and reproduces production row-for-row (`journal_entries` 499, `journal_entry_items`
1118, `accounts` 195, `users` 7 — all exact matches), with the posted ledger
balanced at 37,301,977,829.49 on both the debit and credit sides.

> Note: `journal_entry_items.type` holds `debit` / `credit` — **not** `Dr` / `Cr`.
> The `Dr`/`Cr` shorthand in `.claude/reporting-source.md` is conceptual; any
> query that literally compares against `'Dr'` silently matches zero rows.

---

## 5. The schema template

`schema/tenant_schema_template.sql` is the mould every new tenant database is
cast from (Phase 2 applies it). It is **schema-only** — 306 `CREATE TABLE`
statements, 3 views, 58 foreign keys, and **zero** `INSERT` statements. No
business data is in it, which is why it is safe to keep in version control.

**Verified on 2026-08-31** by applying it to an empty database: 306 tables, 3
views, 58 foreign keys, 0 rows.

### Why a snapshot and not a migration replay

`migrations/` holds 305 ad-hoc, non-idempotent one-off scripts accumulated over
the project's life — not a clean replayable chain. Replaying them against an
empty database is not guaranteed to reproduce the current schema. The snapshot
is the only trustworthy source of truth for "what a BMS database looks like".
From Phase 8 onward, `migrations/tenant/` restores a proper replayable chain
*going forward*, with this snapshot as its baseline.

### Portability guarantees (all verified)

- No `CREATE DATABASE` / `DROP DATABASE` / `USE` statement — it applies to
  whichever database the connection has selected.
- No `DROP TABLE` statements (`--skip-add-drop-table`) — applying it can never
  destroy data.
- **Zero cross-schema references** — no `` `bms`. ``-qualified table names, so
  nothing in a tenant database can reach back into another database.
- **`DEFINER=` clauses stripped.** The dump carried
  `DEFINER=`bejundas`@`localhost`` on its 3 views — a user that will not exist on
  a tenant. Views are created owned by the provisioning connection instead.
  `SQL SECURITY INVOKER` is **retained** on all 3, so a view executes with the
  caller's privileges, never with elevated definer privileges.

### Regenerating it

Only when production's schema legitimately changes outside `migrations/tenant/`:

```bash
mysqldump -uroot --no-data --skip-add-drop-table --routines --triggers --events \
  --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 bms \
  > schema/tenant_schema_template.sql

# Strip DEFINER clauses (keeps SQL SECURITY INVOKER intact):
sed -i 's/DEFINER=`[^`]*`@`[^`]*` //g' schema/tenant_schema_template.sql
```

Then re-verify: 0 `INSERT`, 0 `DEFINER=`, 0 `` `bms`. `` before committing.

---

## 6. Two corrections to `ternant.md` found during Phase 0

Both were discovered by inspecting the repo rather than trusting the plan. They
change *how* later phases ship, not *what* they do.

### 6.1 `includes/config.php` is **gitignored and untracked**

`.gitignore` line 9 excludes `includes/config.php`, and `git ls-files` confirms
it has never been tracked. It is a per-environment file holding local DB
credentials.

**Consequence for Phase 3.** `ternant.md` lists `includes/config.php
(rewritten)` as the deliverable. That cannot work: the file is not in git, so a
rewrite would never reach production through the deploy pipeline, and every
environment would silently keep its old single-tenant connection.

**Revised Phase 3 approach.** The routing logic ships in a **new, tracked** file:

- `core/tenant_bootstrap.php` *(tracked, deployed)* — resolves the tenant,
  decrypts its credentials, and returns the tenant `$pdo`. All the real logic.
- `includes/config.php` *(untracked, per-environment)* — stays a thin file that
  `require`s the bootstrap and keeps only environment-specific values.

This is strictly better than the original: environment secrets stay out of git,
and the deployable logic stays in exactly one reviewable place. The one-time cost
is that each environment's `config.php` must be edited by hand once, during the
Phase 3 rollout — that is a documented go-live step, not a code change.

### 6.2 `*.sql` is gitignored — the schema template needed an explicit exception

`.gitignore` blanket-ignores `*.sql` (intended for database backups). That would
have silently excluded `schema/tenant_schema_template.sql`, and Phase 2's
provisioner would have failed on production with a missing-file error while
working perfectly on the developer's machine.

**Fixed in Phase 0:** an explicit `!schema/tenant_schema_template.sql` negation
was added, and verified with `git check-ignore` and `git add --dry-run`.

---

## 7. Phase 0 acceptance gate — results

| Gate | Result |
|---|---|
| Backup exists and restores cleanly to a throwaway DB | ✅ 306 tables, 3 views restored; row counts match production exactly |
| `schema/tenant_schema_template.sql` has `CREATE TABLE` for every table | ✅ 306 of 306 |
| Template contains zero `INSERT` rows | ✅ 0 |
| Template applies to an empty DB | ✅ 306 tables, 3 views, 58 FKs, 0 rows |
| `TENANT_CRED_KEY` present locally, not in repo | ✅ 32 bytes; `git check-ignore` confirms excluded |
| Key round-trips AES-256-GCM and rejects tampering | ✅ both |
| Schema template is committable despite `*.sql` | ✅ verified via `git add --dry-run` |
| Zero runtime behaviour changed | ✅ no existing file modified except `.gitignore` |

**Not yet done — carried forward:** wildcard DNS/vhost is documented here as an
infrastructure to-do and must be confirmed with the hosting provider **before
Phase 3 merges**. It is the one Phase 0 item that cannot be completed from the
codebase.

---

## 8. The defaults seed file *(added in Phase 2)*

`schema/tenant_seed_defaults.sql` is applied immediately after the schema
template. It is what makes a provisioned tenant *usable* rather than merely
present: without it a new company has 306 empty tables, no chart of accounts and
no permissions, so its owner could not do anything.

### What is in it, and why each table earns its place

| Table | Rows | Why |
|---|---|---|
| `account_types`, `account_categories` | 8 + 8 | Accounting taxonomy. Generic. |
| `accounts` | 105 | Chart of accounts — **structural accounts only**. |
| `permissions` | 156 | The application's permission catalogue. |
| `roles`, `role_permissions` | 8 + 600 | So the owner account has working access. |

### What is deliberately excluded — read this before regenerating

- **Sub-ledger accounts (`is_subledger = 1`).** This is the one that matters. In
  the source database **90 of the 195 accounts are per-customer and
  per-supplier sub-ledgers carrying real counterparty names** — "Tanzania
  Government", "MASUDI", "John Doe". Seeding them would publish Tenant #1's
  entire customer list into every company that signs up. Only the 105 structural
  accounts are included; they were verified to form a self-contained tree with
  no dangling `parent_account_id`.
- **`users`.** Every tenant's owner account is created fresh by the provisioner.
- **`system_settings`.** Mixes harmless platform defaults with company identity
  *and secrets* — including the encrypted AI provider API key. Because that key
  is encrypted with a per-environment secret shared across databases on the same
  server, copying the row would hand one tenant's credential to every later
  signup.
- **Every transactional table.** `journal_entries`, invoices, payments and the
  rest stay empty. A new tenant starts with a zero ledger.

The file also ends with two defensive statements that run regardless of how it
was generated: it zeroes every `opening_balance`/`current_balance`, and it
deletes any `is_subledger = 1` row. So a future regeneration that forgets the
filter still cannot leak counterparty names.

### Regenerating it

```bash
COMMON="-uroot --no-create-info --complete-insert --skip-extended-insert \
  --skip-add-locks --skip-disable-keys --skip-comments --no-tablespaces \
  --set-gtid-purged=OFF --default-character-set=utf8mb4"

mysqldump $COMMON bms account_types account_categories permissions roles role_permissions > /tmp/seed_a.sql
mysqldump $COMMON --where="is_subledger = 0" bms accounts                                  > /tmp/seed_b.sql
```

Then reassemble with the header, the `SET FOREIGN_KEY_CHECKS = 0` wrapper and the
sanitisation footer, keeping the existing file's structure. **Before committing,
re-verify:** zero `INSERT INTO \`users\``, zero `INSERT INTO \`system_settings\``,
zero `CUST-`/`SUPP-` account codes, and no real customer names.

`tests/test_tenant_provisioning_cli.php` asserts all of this against a freshly
provisioned tenant, so a bad regeneration fails the suite rather than reaching
production.

---

## 9. Turning multi-tenancy ON *(added in Phase 3)*

Phase 3 changes how every request picks a database — so it ships **switched off**.
With `TENANT_MODE` unset, `bmsConnectPdo()` connects using the `DB_*` constants
exactly as the application always has. Deploying Phase 3 is a no-op until an
operator does all three steps below. This is deliberate: the highest-risk phase
should be separable from the moment its risk begins.

> **Why these are operator steps, not deploy steps.** The control database was
> originally created by a deploy migration. On 2026-08-31 that failed on
> production: the application's MySQL user (`user_bjp`) has no `CREATE DATABASE`
> privilege, the migration exited 1, and `script_stop: true` correctly halted the
> **entire deploy** — including the second host, which never got the release. A
> subsystem that is switched off and that nothing reads must never be able to
> block unrelated deploys. `bms_control` is platform infrastructure, not tenant
> schema, so building it now belongs here with the other deliberate steps.

### Step 0 — create the control database

```bash
php scripts/setup_control_db.php            # idempotent; safe to re-run
php scripts/setup_control_db.php --check    # report only, changes nothing
```

If the application's MySQL user cannot create databases, the script prints the
exact SQL for the DBA and stops:

```sql
CREATE DATABASE `bms_control` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `bms_control`.* TO '<app_user>'@'localhost';
FLUSH PRIVILEGES;
```

Then create the first platform operator (the table ships empty by design):

```bash
php scripts/create_superadmin.php --email=you@example.com --name="Your Name"
```

### Step 1 — environment variables (production)

| Variable | Value | Notes |
|---|---|---|
| `TENANT_MODE` | `on` | Anything else, including unset, means single-tenant. |
| `TENANT_BASE_DOMAIN` | e.g. `bms.co.tz` | Required. Without it no host ever resolves to a tenant. |
| `TENANT_CRED_KEY` | 64 hex chars | From Phase 0. Without it tenants cannot be decrypted. |

### Step 2 — edit `includes/config.php` on each environment

`config.php` is gitignored and per-environment, so this is a **one-time manual
edit per server** — it cannot arrive via `git`. Replace the connection block:

```php
<?php
date_default_timezone_set('Africa/Dar_es_Salaam');

define('DB_SERVER',   'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME',     'bms');

require_once __DIR__ . '/../core/tenant_bootstrap.php';
$pdo = bmsConnectPdo();
```

Two details that matter:

- **Keep the `DB_*` constants.** They remain the fallback for the root domain,
  for CLI (migrations, cron, tests), and for the whole single-tenant mode. Other
  code reads `DB_NAME` directly.
- **Drop the closing `?>`.** The current file ends with one followed by blank
  lines, and that whitespace is emitted on every request — it breaks
  `session_start()` after include, and corrupts JSON API responses. A PHP file
  that contains only PHP should never close its tag.

### Step 3 — DNS and vhost

Per §3: wildcard DNS, one vhost with `ServerAlias *.<base domain>`, and a
wildcard TLS certificate. For local WAMP testing, per-tenant `hosts` entries plus
a `ServerAlias *.bms.local` vhost:

```apache
<VirtualHost *:80>
    ServerName   bms.local
    ServerAlias  *.bms.local
    DocumentRoot "C:/wamp64/www/bms"
    <Directory "C:/wamp64/www/bms">
        Options +Indexes +FollowSymLinks +Multiviews
        AllowOverride All
        Require local
    </Directory>
</VirtualHost>
```

```
127.0.0.1   bms.local
127.0.0.1   tenanta.bms.local
127.0.0.1   tenantb.bms.local
```

Always test with **two** tenants. Every isolation claim in this project is
meaningless when checked against one.

### What each kind of request does once enabled

| Request | Result |
|---|---|
| `tenanta.<base>`, tenant active or trial | Connects to that tenant's database as its own MySQL user. |
| `tenanta.<base>`, tenant **suspended** | 403 "Account suspended". **Only that tenant** — everyone else is unaffected. |
| `tenanta.<base>`, tenant **deleted** | 410 "Account closed". |
| `nosuchtenant.<base>` | **404** — never falls back to the main database. |
| `<base>` (root), or a reserved label | The main database, as before. |
| CLI (migrations, cron, tests) | The main database — there is no host to resolve. |
| Control database unreachable | 503. Never falls back, because that would serve one company's data on another's hostname. |
| Session carrying a different tenant's id | Session destroyed, 403, re-login. |

That last row is the cross-tenant session guard. `ternant.md` placed it in
`header.php`; it lives in the bootstrap instead, because **565 files include
`config.php` while far fewer include `header.php`** — a guard that misses the API
surface is not a guard.

### Turning it back off

Unset `TENANT_MODE`. The next request connects via the `DB_*` constants again.
No code change, no deploy — which is the rollback path for the riskiest phase.

---

## 10. Platform-infrastructure schema does not live in `migrations/`

**A real production incident, 2026-08-31.** `bms_control` was originally created
by a deploy migration. Production's application MySQL user has no `CREATE
DATABASE` privilege, so the migration exited 1 and `script_stop: true` —
correctly, per the repo's own rule — halted the **entire deploy**, including the
second production host, which never received that release.

The migration was wrong to exist, not the guard that stopped it. `bms_control`
is platform infrastructure — a registry of which companies exist — not tenant
schema. Nothing in the running application reads it until multi-tenancy is
switched on. An optional, disabled subsystem must never be able to veto a
release for the entire fleet.

**The fix:** `scripts/setup_control_db.php` — CLI-only, idempotent, run by hand
as **Step 0** of turning multi-tenancy on (§9 above). It creates the database
and every control table (`tenants`, `superadmins`, `tenant_provisioning_log`,
`registration_attempts`, `tenant_admin_log`, `tenant_migration_log`), adds
columns an older install might predate, and verifies itself through
`getControlPdo()` rather than assuming success. If the MySQL user cannot create
the database, it prints the exact `CREATE DATABASE` / `GRANT` for the DBA and
exits — the operator's problem to fix, never the deploy pipeline's.

**The rule going forward:** anything that is platform infrastructure rather than
either the main application's schema or a tenant's schema belongs in
`scripts/`, run by an operator, never in `migrations/`. If you are about to add
a migration that a fresh, not-yet-configured environment could plausibly fail
to run, ask first whether it belongs here instead.

---

## 11. Per-tenant migrations *(Phase 8)*

From Phase 8 onward, a schema change that must reach **every tenant database**
goes in `migrations/tenant/`, applied by `core/tenant_migration_runner.php`
rather than the app's own `migrations/runner.php` (which only ever touches the
single main application database). The full filename/structure convention is
documented in `migrations/tenant/README.md` — read it before writing one.

```bash
php core/tenant_migration_runner.php                 # every live tenant
php core/tenant_migration_runner.php --tenant=7       # one tenant only
php core/tenant_migration_runner.php --dry-run        # report only, change nothing
```

### Not wired into `deploy.yml` yet — deliberately

`ternant.md`'s original Phase 8 plan wires this into `deploy.yml`. Building it
the same day §10's incident happened, with **zero real tenants yet** (Phase 7
has not run), would risk repeating that exact mistake for no benefit: an
optional step with nothing to do, added to a pipeline that has already proven
it can take down both production hosts when a step like this misbehaves.

Wiring happens **alongside Phase 7**, once tenants genuinely exist to migrate —
and even then, its own failure must never be allowed to abort the main
application's deploy (see below). Until then, run it by hand or from a separate
cron; it is entirely safe to run with zero tenants (a documented no-op) or never
run at all.

### One tenant's failure never blocks another tenant's migration

`ternant.md`'s "what ships" table for this phase says a broken migration "stops
on first failure for **that tenant**". Its acceptance-gate wording separately
says the run "stops" without qualifying which scope — read literally, those two
sentences disagree with each other.

This codebase already has a governing answer, enforced by every other phase:
**one tenant's problem must never affect another tenant.** Phase 6's own tests
assert exactly this for suspend and delete. Halting every tenant's migration run
because one company's database happened to have already-conflicting state would
break that guarantee for no good reason — and would repeat §10's mistake at
tenant scale instead of platform scale.

So: a migration that fails for tenant A stops **only that tenant's** remaining
migrations for this run (an inconsistent partial state within one tenant is
still worse than stopping there) and is logged loudly — to the console, to
`migrations/tenant_deploy.log`, and to the `tenant_migration_log` control table
— while every other tenant keeps receiving its migrations normally.
`tests/test_tenant_migration_runner_cli.php` proves this with a real divergence:
the same migration file genuinely fails against one tenant's pre-existing state
and genuinely succeeds against another's, in the same run.

### How a migration file gets its connection

`core/tenant_migration_bootstrap.php` is what `roots.php` is to an app
migration — except it connects to whichever tenant
`core/tenant_migration_runner.php` is currently processing, using
`TENANT_MIGRATION_DB_*` environment variables the runner sets before spawning
each subprocess. Never `argv` — a password on the command line is visible to
anyone who can run `ps` on the host for the process's lifetime; an environment
variable is only readable by the same user via `/proc`, the same exposure every
other secret in this deploy already accepts.

**The one rule that matters most**, also in the README: a file under
`migrations/tenant/` must never `require_once roots.php` or
`includes/config.php` — doing so reconnects `$pdo` to the main `bms` database
mid-migration, silently running every later statement against the wrong
database.
