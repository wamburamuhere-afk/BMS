# BMS — Tenant Resource Isolation & Tenant #1 Cutover (2026-09-02)

Companion to `ternant.md`. That document isolated the **databases**. This one
isolates the **resources every tenant still shares** — filesystem paths and any
code that builds its own connection — and then performs the Tenant #1 cutover
(`ternant.md` Phase 7) on the cleaned-up ground.

---

## Why this document exists

`ternant.md` Phase 9 proved isolation **at the MySQL grant layer**: a tenant's
`bms_u{id}` user cannot reach another tenant's database. 48 assertions, green.

It never checked the layer above. Two classes of bug survive a green Phase 9:

**Leak A — code that bypasses `$pdo`.** `api/backup_actions.php:55` builds
`new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME)` — the legacy
constants. The dump side (`bms_write_dump($pdo, …)`) *is* tenant-scoped, so a
tenant admin who backs up and restores dumps **their own** data and writes it
into the **main `bms` database**. `restoreFromFile()` line 67 forces
`DROP TABLE IF EXISTS` before every `CREATE TABLE`, so this is destructive, not
merely a read leak. Also `app/bms/pos/fix_database.php` (web-reachable, **no
auth check**, runs DDL, falls back to `root`@`bms`) and the same fallback shape
in `app/bms/pos/system_status.php:62`.

**Leak B — shared filesystem.** One wildcard vhost serves every subdomain from
one webroot, so `ROOT_DIR = __DIR__` (`roots.php:38`) is identical for all
tenants. `$backupsDir = ROOT_DIR . '/backups/'` is therefore shared, and
`app/constant/settings/backup_restore.php` lists it with
`glob($backupsDir . '*.sql')` — **no ownership filter**. Every tenant can see and
download every other tenant's full database dump. The whole `uploads/` tree
(29 subfolders) is shared the same way.

**Leak C — the backups are not restorable.** Found on 2026-09-03 while
recovering demo, and *not* a multi-tenancy bug — it has always been true.
`bms_write_dump()` (`core/backup.php:59-65`) does `SELECT *` and writes
`INSERT INTO tbl VALUES(...)` **with no column list**, so it supplies a value for
every GENERATED column. MySQL rejects those rows with
`ERROR 3105: The value specified for generated column … is not allowed`, and a
plain `mysql < dump.sql` **aborts at that point**, leaving the database
half-restored. On demo this hit `product_stocks` at line 94,561 of 115,245 —
303 tables in the dump, only 211 loaded before it stopped.

This is why the two `pre_restore` recovery attempts on the morning of
2026-09-03 did not bring demo back either. A backup system whose output cannot
be restored is not a backup system, and this defect turned a recoverable
incident into a much longer outage. It is fixed in step 2 alongside Leak A,
because they live in the same two files.

### The principle both fixes follow

> Application code must never name a database or a directory.
> It asks the request which one it is on.

Today `DB_NAME` and `ROOT_DIR` are reachable from anywhere, so tenant-awareness
is *optional* — and optional means eventually forgotten. Steps 1–4 replace them
with accessors; step 5 makes forgetting fail the push.

---

## Decisions taken (confirmed with the owner, 2026-09-02)

| Decision | Choice | Why |
|---|---|---|
| Existing company's URL | **Unchanged** — stays on `bms.bjptechnologies.co.tz` | Owner's call. No URL change for existing users. |
| How the root domain maps to a tenant | **Config-driven alias**, not a hardcoded special case. Tenant #1 is registered with an ordinary subdomain; `TENANT_ROOT_SUBDOMAIN` makes the base domain an *alias* to it, resolved through the same registry lookup. | Tenant #1 becomes "a normal tenant with a second hostname", so every existing test applies and no future feature needs an "except company #1" branch. |
| Platform surfaces | `register.php` + `app/superadmin/*` move to `superadmin.<base>` | That label is already reserved (`tenant_resolver.php:117`) and already resolves to `'none'` → `getControlPdo()`. Keeps the platform off a live company's origin with no new code. |
| Legacy paths | Tenant #1 **keeps** `uploads/` and `backups/` unprefixed; only *new* tenants get `t{id}/` | Tenant #1 *is* the legacy install. No file migration, no rewriting `document_library` paths — the same trick `ternant.md` uses to avoid renaming the database. |
| How "legacy" is detected | `$tenant['db_name'] === DB_NAME` | No schema change, no new column, correct per host (demo and bms each judge against their own `DB_NAME`). |
| Sequencing vs `ternant.md` Phase 7 | Isolation fixes **first** | Phase 7 hands production data to the routing layer. Doing that while a tenant can overwrite it is the wrong order. |

---

## Step overview

| # | Step | Where | Risk |
|---|---|---|---|
| **0** | Reconnaissance — is Leak B live? | Server (read-only) | 🟢 none |
| **1** | Resource accessors in `tenant_bootstrap.php` | Code | 🟢 Low (no behaviour change) |
| **2** | Fix the connection bypasses (Leak A) | Code | 🟠 Medium |
| **3** | Fix shared `backups/` (Leak B, severe half) | Code | 🟠 Medium |
| **4** | Fix shared `uploads/` (Leak B, large half) | Code | 🟠 Medium |
| **5** | Regression guard: audit hook + app-layer tests | Code | 🟢 Low |
| **6** | Root-domain alias in the resolver | Code | 🔴 High (touches resolution) |
| **7** | Move platform surfaces to `superadmin.<base>` | Code + Server | 🟠 Medium |
| **8** | Register Tenant #1 (`ternant.md` Phase 7) | Code + Server | 🟠 Medium |
| **9** | Verification & go-live | Both | 🟠 Medium |

Every step: own branch off `develop`, `php -l` clean, live smoke test, PR into
`develop` (never `main`), rollback line in the PR body, changelog entry.

---

## Step 0 — Reconnaissance ✅ done (2026-09-03)

Run read-only over SSH on both hosts. **Leak A is confirmed to have already
fired in the wild — see the incident record below.**

### Environment facts

| | `bms.bjptechnologies.co.tz` | `demo.bjptechnologies.co.tz` |
|---|---|---|
| Main database | **`bejundas_bms_bjp`** | **`bejundas_main`** |
| App MySQL user | `user_bjp` | `user_demo` |
| App user grants | `USAGE ON *.*`, `ALL` on `bejundas_bms_bjp`, `bms_control` | `USAGE ON *.*`, `ALL` on `bejundas_main`, `bms_control`, `demo_control` |
| Control database | `bms_control` (6 tables) | `demo_control` (6 tables) |
| `TENANT_MODE` | **on** (vhost `SetEnv`) | **on** (vhost `SetEnv` + `putenv()` in `config.php`) |
| Tenants registered | **0** | **4** — `zetatest` (9001), `mufindipower` (9002), `begwa` (9003), `mwpt` (9004), all `active` |
| PHP SAPI | mod_php (`php_module`) — `SetEnv` reaches `getenv()` | same |
| `includes/ai_app_secret.php` | **MISSING** | present (Jun 8, `-rw-------`) |
| `backups/*.sql` | 3 files, 3.6 MB each, `-rwxrwxrwx` | 6 files incl. two 26 MB, some `-rwxrwxrwx` |

### Corrections to `ternant.md` this uncovered

1. **The production database is not called `bms`.** `ternant.md` says throughout
   "kept as database `bms`". It is `bejundas_bms_bjp` on the app host and
   `bejundas_main` on demo. Every script must read `DB_NAME`, never a literal.
   The `db_name === DB_NAME` legacy test in step 1 is unaffected.
2. **The app user cannot provision.** `user_bjp` holds only `USAGE ON *.*` plus
   `ALL` on two schemas — no `CREATE DATABASE`, no `CREATE USER`, no
   `GRANT OPTION`. `provisionTenant()` steps 2–3 cannot succeed as configured, so
   Phase 5 self-registration is deployed but non-functional on the app host.
   Needs a decision at step 8 — recommended: self-registration records a
   **pending** request, provisioning runs as an operator CLI with privileged
   credentials supplied at run time, so the web tier never holds
   database-creation power.
3. **`user_demo` holds `ALL PRIVILEGES ON bms_control`** — the *app host's*
   control registry, including every tenant's encrypted credentials. Both sites
   share one MySQL server. Revoke regardless of everything else.
4. **The `crypto.php` derived-key fallback is live on the app host.**
   `includes/ai_app_secret.php` is missing there, so `core/crypto.php:55` derives
   its key from `DB_NAME|DB_PASSWORD`. Affected values: `ai_api_key_enc`,
   `zoom_client_secret_enc`, `zoom_access_token_enc`. **Changing DB credentials
   in step 8 while this holds would render them permanently undecryptable.**
   Must be resolved before step 8.
5. **`TENANT_CRED_KEY` lives in `includes/config.php` via `putenv()`**, not only
   in the vhost as the `ternant.md` go-live checklist claims. Demo's value was
   exposed in plaintext during this reconnaissance and **must be rotated** —
   decrypt-all → new key → re-encrypt-all, never edited by hand, or all four
   tenants become unopenable.

### Method note — a mistake worth not repeating

The first sweep searched `/etc/apache2/sites-enabled/` and concluded tenant mode
was off. `sites-enabled/` holds **symlinks**, and `grep -r` does not follow them.
The real setting was in `sites-available/`, on **both** hosts. Any future check
of Apache config must grep `sites-available/` or pass `grep -R`.

---

## 🔴 INCIDENT — 2026-09-02/03, demo host, main database overwritten by a tenant

**Confirmed.** Leak A fired. Tenant #9002 (`mufindipower`) used Backup → Restore;
`restoreFromFile()` connected via the `DB_*` constants instead of the tenant
connection and wrote the tenant's database over demo's main database
`bejundas_main`, dropping and recreating every table (`backup_actions.php:67`
forces `DROP TABLE IF EXISTS` before each `CREATE TABLE`).

**Evidence**

| Fact | Value |
|---|---|
| `bejundas_main` before | 26 MB dump (2026-08-31 08:35); users included `admin` / `bjptechnologies@gmail.com` and `WAMBURA` / `wamburamuhere@gmail.com` |
| `bejundas_main` now | 309 tables, ~15.7 MB (almost all empty-InnoDB overhead), **1 user, 0 journal entries** |
| The surviving user | `999050 | mufindipower@gmail.com | 2026-09-02 14:17:23` — tenant #9002's owner, in a tenant id range, sitting in the **main** database |
| Already overwritten before today's restores | `pre_restore_2026-09-03_09-15-27.sql` (a snapshot of `bejundas_main` taken *before* the first restore) already contains only `mufindipower@gmail.com` |

**Timeline.** 2026-09-02 14:17 tenant #9002 provisioned → restore run some time
before 2026-09-03 09:14 (that morning's `auto_backup` is already 454 KB) →
09:15 and 09:49 two further restores, most likely recovery attempts.

**Blast radius.** Demo only. The app host has **0 tenants**, so no tenant session
exists there to trigger the path. That is the *only* thing protecting
`bejundas_bms_bjp` — it is not a safeguard, it is an absence of opportunity.

**Recovery point.** `backups/bms_backup_2026-08-31_11-35-15.sql` (26 MB). Losses
are anything changed on demo between 2026-08-31 08:35 and the overwrite.

**Still to verify:** that tenant #9002's own database `bms_t9002` is intact (the
restore read from its dump and wrote to main, so it should be untouched).

**Consequence for this plan:** steps 1–3 are no longer routine hardening. They
are the fix for a defect with a confirmed destructive occurrence, and they take
priority over everything else in this document.

---

## Step 1 — Resource accessors

**Branch:** `feat/tenant-resource-accessors`

Add to `core/tenant_bootstrap.php`, beside the existing `bmsCurrentTenant()`:

```php
bmsCurrentDbConfig(): array    // ['host','user','pass','name'] for THIS request
bmsCurrentDbName(): string     // replaces direct DB_NAME reads in app code
bmsTenantPathPrefix(): string  // '' for legacy/single-tenant, 't{id}/' otherwise
bmsUploadsDir(string $sub = ''): string
bmsBackupDir(): string
```

- `bmsCurrentDbConfig()` returns the `DB_*` constants when `bmsCurrentTenant()`
  is null (single-tenant, CLI, root fallback), and the tenant's own credentials
  otherwise. The decrypted password is cached in `$GLOBALS` at connect time so
  this never decrypts twice per request.
- `bmsTenantPathPrefix()` returns `''` when there is no tenant **or** when
  `$tenant['db_name'] === DB_NAME` (the legacy install), else `'t{id}/'`.
- The two directory helpers `mkdir` on demand and drop the standard
  `.htaccess` (per `.claude/security.md` §19) into any directory they create.

**Why an accessor rather than "just pass `$pdo`":** the dump/restore path
genuinely needs mysqli's `multi_query`, which PDO cannot do cleanly. It needs
*credentials*, not a PDO handle — and it must stay correct in single-tenant mode
too, which a `$pdo` argument alone does not give.

**Gate:** unit test — single-tenant returns the `DB_*` values and empty prefix; a
tenant whose `db_name === DB_NAME` returns empty prefix; any other tenant returns
`t{id}/`. No production file changes behaviour yet.

**Rollback:** `git revert` — nothing calls these yet.

---

## Step 2 — Fix the connection bypasses (Leak A)

**Branch:** `feat/tenant-fix-conn-bypass`

| File | Change |
|---|---|
| `api/backup_actions.php:55` | `restoreFromFile()` takes its mysqli credentials from `bmsCurrentDbConfig()` instead of the `DB_*` constants. **This is the incident cause.** |
| `core/backup.php:53-68` | **Leak C.** `bms_write_dump()` writes an explicit column list and omits GENERATED columns, so its output is actually restorable. Fixed here because a correct restore path is worthless if the dumps it consumes are broken. |
| `api/backup_actions.php:67` | The `DROP TABLE IF EXISTS` regex injected into restored SQL becomes unnecessary once dumps carry their own (they already do) — leave it, but it must not be the only thing standing between a restore and an orphaned table. |
| `app/bms/pos/fix_database.php` | **Delete.** Its own header says `SAVE AS:` — a leftover one-off. Web-reachable, no auth check, runs DDL, falls back to `root`@`bms`. Not in `deploy.yml`'s critical-files list. Confirm no inbound references before deleting. |
| `app/bms/pos/system_status.php:48-66` | Remove the `root`/`bms` fallback block. A page must fail loudly when `$pdo` is missing, never invent a connection. |
| `core/crypto.php:55` | **No change**, documented instead: the `DB_NAME\|DB_PASSWORD` seed only fires when `includes/ai_app_secret.php` cannot be written. Step 0 confirms it exists. Revisit only if step 0 says otherwise. |

**Gate:** with a test tenant active, run a backup → restore cycle and assert the
rows landed in the **tenant's** database and `bms` was untouched (row counts
before/after).

**Rollback:** `git revert`. The deleted file can be recovered from history if it
turns out something referenced it.

---

## Step 3 — Fix shared `backups/` (Leak B, severe half)

**Branch:** `feat/tenant-backup-isolation`

- `api/backup_actions.php` and `app/constant/settings/backup_restore.php` use
  `bmsBackupDir()` instead of `ROOT_DIR . '/backups/'`.
- The listing globs **only** the current tenant's directory, so a tenant can
  never see another's dumps.
- Download/delete paths validate the resolved real path is inside
  `bmsBackupDir()` — a `basename()` check alone is not enough against traversal.
- `getDatabaseSize()` (`backup_restore.php:55`) filters `information_schema` by
  `bmsCurrentDbName()`, not the `DB_NAME` constant.

Because Tenant #1 keeps the unprefixed path, the existing
`backups/bms_backup_*.sql` files stay exactly where they are and belong to
Tenant #1 — nothing to move.

**Gate:** two active test tenants each create a backup; each sees exactly one
file in its list; neither can download the other's by guessing the filename.

---

## Step 4 — Fix shared `uploads/` (Leak B, large half)

**Branch:** `feat/tenant-uploads-isolation`

Mechanical sweep: every upload handler writing to `…/uploads/<entity>/` uses
`bmsUploadsDir('<entity>')`.

**Severity note, stated honestly:** this is lower-severity than step 3. Uploaded
files are named `bin2hex(random_bytes(16))` and the `document_library` rows that
index them live in each tenant's own database, so one tenant cannot *enumerate*
another's files. What is actually shared is disk/quota, and the risk that any
code taking a path from user input could reach across. Worth fixing properly;
not the emergency step 3 is.

**No read-path fallback is needed:** Tenant #1's prefix is `''`, so its existing
`document_library` paths resolve unchanged, and new tenants have no legacy rows.

**Gate:** a file uploaded on tenant A lands under `uploads/t{A}/…`; tenant #1's
uploads still land in `uploads/…`; every pre-existing tenant-#1 document still
opens.

---

## Step 5 — Regression guard

**Branch:** `feat/tenant-audit-guard`

Mirrors this repo's existing **project-scope audit pre-push hook** — same shape,
same escape hatch, so it is idiomatic rather than a new invention.

> Any new/changed file under `app/`, `api/`, `ajax/`, `actions/` containing
> `new PDO(`, `new mysqli(`, `mysqli_connect(`, `ROOT_DIR . '/uploads`, or
> `ROOT_DIR . '/backups` fails the push unless it carries
> `// tenant-audit: skip` with a stated reason.

Plus the assertion Phase 9 was missing — extend `tests/test_tenant_isolation_cli.php`
with an **application-layer** section: exercise the backup, restore and upload
code paths as tenant A and assert the bytes landed in tenant A's database and
tenant A's directory. Grant-layer isolation was proven; app-layer was assumed.

**Anti-vacuity, per the discipline already established in Phase 9:** validate the
new assertions against a deliberately unfixed copy of `backup_actions.php` and
confirm they go **red**. An assertion never seen failing is not evidence.

---

## Step 6 — Root-domain alias

**Branch:** `feat/tenant-root-alias`

| File | Change |
|---|---|
| `core/tenant_resolver.php` | New pure helper `isBaseDomainHost(?string $host, ?string $base = null): bool`. `extractTenantSubdomain()` stays **pure and unchanged** — its `null` also means CLI, reserved label, IP and foreign domain, so overloading it would break all four. |
| ↳ | New `tenantRootSubdomain(): ?string` reading `TENANT_ROOT_SUBDOMAIN`. |
| ↳ | In `resolveTenantFromRequest()`: when `extractTenantSubdomain()` returns null **and** `isBaseDomainHost()` is true **and** a root subdomain is configured, substitute that label and continue into the **same** registry lookup — same status checks, same credential decrypt, same session pinning. |

Unset ⇒ today's behaviour exactly (root domain → `'none'` → legacy `$pdo`). Fail-safe,
consistent with `tenantModeEnabled()`.

**Gate:** extend `tests/test_tenant_routing_cli.php` across the full host matrix —
base domain with and without the variable set, reserved labels, unknown
subdomain (must still 404, never fall back), CLI (no host), IP literal, foreign
domain, and a suspended root tenant (must show "suspended", not fall through to
the main database).

**Rollback:** unset the env var — instant revert without a deploy. The code path
disappears when the variable is absent.

---

## Step 7 — Move platform surfaces

**Branch:** `feat/tenant-platform-host` (+ server vhost work)

- `register.php` and `app/superadmin/*` served at `superadmin.<base>`.
- Those paths **refuse to serve** on a tenant host (including the root alias), so
  signup and the superadmin panel never share an origin with a company's ERP.
- Server: vhost `ServerName`/`ServerAlias` for `superadmin.bms.bjptechnologies.co.tz`
  and `superadmin.demo.…`, covered by the existing wildcard certs.

**Gate:** superadmin login reachable only on the platform host; a tenant host
returns 404 for `app/superadmin/*`; the pre-existing "superadmin unreachable from
a tenant subdomain" assertion still passes.

---

## Step 8 — Register Tenant #1 (`ternant.md` Phase 7)

**Branch:** `feat/tenant-07-migrate-tenant-one`

**Code:** `scripts/register_tenant_one.php` — idempotent CLI, criteria-based (no
hardcoded ids), safe to re-run:

1. Refuse if a tenant with `db_name = DB_NAME` already exists (idempotency).
2. Create `bms_u1` with a 24-char random password, `GRANT ALL ON \`bms\`.*` — and
   **nothing else**.
3. Verify the new user can connect to `bms` and **cannot** reach any other
   database, before writing anything.
4. Insert the `tenants` row: `db_name = DB_NAME` (unchanged), `db_host`,
   `db_username = 'bms_u1'`, password encrypted with `TENANT_CRED_KEY`,
   `status = 'active'`, chosen subdomain.
5. Log every step to `tenant_provisioning_log`; roll back the MySQL user and the
   row together on any failure.

**Server:** run the script; create `bms_control_app` (the outstanding
`ternant.md` Phase 9 / conventions §12 operator step); set `TENANT_ROOT_SUBDOMAIN`
and `TENANT_MODE=on` via vhost `SetEnv`; edit `includes/config.php` per
conventions §9 step 2 (gitignored — cannot arrive via git).

**Gate:**
- Every existing user still logs in at the unchanged URL.
- The request now connects as `bms_u1`, confirmed via `SELECT CURRENT_USER()`.
- `assertLedgerBalanced($pdo, today)` passes exactly as before.
- Module smoke across Accounting, HR, POS, Sales, Purchasing — nothing looks
  different to end users.

**Rollback:** unset `TENANT_MODE` — the application returns to the legacy
connection immediately, no deploy required. The `bms` database is never modified
by this step, so there is nothing to restore.

---

## Step 9 — Verification & go-live

- Run the full tenant suite on the server: routing, provisioning, isolation
  (now including the app-layer section), migration runner, module smoke.
- `assertLedgerBalanced()` per tenant.
- Update the `ternant.md` **Phase tracker** (Phase 7 → done) and the go-live
  checklist items this work closes.
- Changelog entries per merged step.
- Outstanding after this work, tracked in `ternant.md`: manual per-module
  regression, and the **wildcard certificate renewal — expires 2026-11-30 on both
  hosts, `certbot --manual`, no auto-renew.**

---

## Step tracker

Update the moment each step merges — this is what lets any session resume.

| Step | Status | Branch |
|---|---|---|
| 0 — Reconnaissance | ✅ done (2026-09-03) — findings + incident recorded above | — (server, read-only) |
| 0b — Contain & recover demo | ✅ done (2026-09-03) — see below | — (server) |
| 1 — Resource accessors | ⏳ pending | `feat/tenant-resource-accessors` |
| 2 — Connection bypasses + Leak C | ⏳ pending | `feat/tenant-fix-conn-bypass` |
| 3 — Backup isolation | ⏳ pending | `feat/tenant-backup-isolation` |
| 4 — Uploads isolation | 🟡 foundation only — `bmsUploadsDir()`/`bmsUploadsRel()` exist and are tested; **67 + 56 call sites still unconverted**, debt frozen by the step-5 ratchet | `feat/tenant-uploads-and-guard` |
| 5 — Regression guard | ✅ done (2026-09-03) — `tests/test_tenant_resource_audit_cli.php`, 22 assertions; found 2 call sites step 3 had missed | `feat/tenant-uploads-and-guard` |
| 6 — Root-domain alias | ⏳ pending | `feat/tenant-root-alias` |
| 7 — Platform surfaces | ⏳ pending | `feat/tenant-platform-host` |
| 8 — Register Tenant #1 | ⏳ pending | `feat/tenant-07-migrate-tenant-one` |
| 9 — Verification & go-live | ⏳ pending | — |

---

## Step 0b — Containment & demo recovery ✅ done (2026-09-03)

Performed over SSH, in this order.

1. **Froze the restore path on both hosts** — `chmod 000` on
   `api/backup_actions.php`. Blunt but unbotchable and instantly reversible.
   **This must stay in place until step 2 deploys**; the deploy's
   `git reset --hard` restores the file and its permissions along with the fix.
2. **Verified the 2026-08-31 dump before trusting it** — it ends with
   `SET FOREIGN_KEY_CHECKS=1;` (so it is complete, not truncated mid-write),
   303 `CREATE TABLE`, 115,245 `INSERT`, 12 `users` rows. *Never restore a dump
   whose last line has not been checked.*
3. **Snapshotted the damaged database first** — `INCIDENT_bejundas_main_*.sql`
   in `/var/backups/bms-demo/` (root-owned, 0700). Rollback and evidence.
4. **Dropped all 309 objects, then loaded the dump** — the dump only carries
   `DROP TABLE` for the 303 tables that existed on 31 Aug, so anything newer
   would have survived as an orphan.
5. **First load aborted** at `product_stocks` — this is how Leak C was found.
   Re-ran with `mysql --force`, which completes every `CREATE TABLE` and skips
   only the rejected rows: 74 errors, all one table.
6. **Result: 303 tables, 12 users, 347 journal entries.** `migrations/runner.php`
   reported 0 pending. Only `product_stocks` came back empty, repaired
   separately by demoting the generated column, replaying its 74 rows, then
   restoring the generated definition (MySQL recomputes the values).

**Credentials handling:** a 0600 `--defaults-extra-file` written from
`includes/config.php`, shredded afterwards — no password on any command line,
where `ps` would expose it.

**Not done, still outstanding:**
- Demo's `TENANT_CRED_KEY` was exposed in plaintext during reconnaissance and
  **must be rotated** (decrypt-all → new key → re-encrypt-all; never edit by
  hand or all 4 tenants become unopenable).
- Revoke `user_demo`'s `ALL PRIVILEGES ON bms_control`.
- Demo's dumps still sit in the webroot; move them to `/var/backups/bms-demo/`.
- Sentry reports demo as `environment = production`, so demo noise is
  indistinguishable from real production alerts.
- Verify tenant #9002's own database `bms_t9002` is intact.
