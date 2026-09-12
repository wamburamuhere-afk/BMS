# Per-tenant migrations

Every schema change that must reach **every tenant database** goes here, from
Phase 8 onward. The main `migrations/` folder still exists — it applies to the
single main application database (`bms` today, Tenant #1 after Phase 7) — but
new tenant-facing schema changes belong in this folder instead, so
`core/tenant_migration_runner.php` can roll them out across the whole fleet.

## How it runs

```bash
php core/tenant_migration_runner.php                 # every live tenant
php core/tenant_migration_runner.php --tenant=7       # one tenant only (retry/debug)
php core/tenant_migration_runner.php --dry-run        # report what WOULD run, change nothing
```

**Wired into `deploy.yml` as of 2026-09-02** (see `.github/workflows/deploy.yml`'s
deploy step, and `core/tenant_migration_runner.php`'s own docblock) — it runs
automatically, once per host, on every push to `main`, as the LAST step of the
deploy, right after `migrations/runner.php`. It never blocks the deploy: it is
guarded with `|| echo` so a per-tenant failure only warns loudly (console,
`migrations/tenant_deploy.log`, the `tenant_migration_log` control table) —
the release still lands on every host either way (see "What happens on
failure" below). Running it by hand (or from cron) is still supported and
useful for a targeted retry (`--tenant=<id>`) after a logged failure, or for a
host with no CI/CD wiring at all — it is a no-op (exits 0) wherever there is
no control database or no files under this folder.

## Filename and structure — copy this exactly

```
migrations/tenant/YYYY_MM_DD_short_description.php
```

```php
<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: <description>...\n";

try {
    // Idempotent DDL/DML here — same rules as .claude/migrations.md:
    //   - CREATE TABLE IF NOT EXISTS, never plain CREATE TABLE
    //   - SHOW COLUMNS ... before ALTER TABLE ADD COLUMN
    //   - INSERT IGNORE for seed data
    //   - never wrap DDL in a transaction (MySQL DDL auto-commits)
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

## The one rule that matters most

**Never `require_once roots.php` or `includes/config.php` in a file here.**
Doing so reconnects `$pdo` to the main `bms` database mid-migration — every
statement after that point would silently run against the wrong database
instead of the tenant this run is processing. Always get `$pdo` from
`core/tenant_migration_bootstrap.php`, which connects to exactly the tenant the
runner is currently on.

## What happens on failure

If a migration fails for one tenant, the runner stops applying *further*
migrations to *that* tenant (so it never ends up half-migrated) and logs the
failure loudly — to the console, to `migrations/tenant_deploy.log`, and to the
`tenant_migration_log` control table. It then **continues to every other
tenant**. One company's schema problem never blocks another's, matching this
project's isolation guarantee everywhere else. See
`docs/MULTI_TENANCY_CONVENTIONS.md` §11 for why this deliberately reads
differently from a literal `ternant.md` acceptance-gate sentence.

**Fixed (2026-09-11):** a run that leaves one or more tenants un-migrated (a
genuine migration error, or credentials that can't be decrypted) now also
emails every superadmin — `notifySuperadminsOfTenantMigrationFailures()` in
this same file, called automatically at the end of the CLI run whenever
`$anyFailed` is true. It uses platform mail (`core/platform_settings.php`),
not the tenant's own notification engine — a migration failure means that
tenant's own database may not be in a state to write a notification into
safely, and this is a platform-operator concern, not something a tenant's own
users should see. Fail-silent like every other notifier here: if platform
SMTP isn't configured, it logs why and moves on rather than turning a
migration failure into a mail-sending fatal error on top of it. This closes
exactly the gap that let a production tenant stay un-migrated for the
Restaurant Module/Product Variants schema until it started throwing
uncaught exceptions in front of real users — caught via Sentry, not this
pipeline, before this fix.

**Also fixed (2026-09-11):** the feature catalogue (`bmsFeatureRegistry()` →
the control DB's `features` table) now syncs automatically too, via
`syncFeatureCatalogue()` (`core/feature_registry.php`), called at the start of
every real (non-dry-run) CLI invocation of this runner. This is deliberately
NOT `scripts/setup_control_db.php` run automatically — that script needs
`CREATE DATABASE`/`CREATE TABLE` privilege a hardened production DB user has
no reason to hold, and auto-running it caused a real outage on 2026-08-31 (see
that script's own docblock). `syncFeatureCatalogue()` only does `INSERT
IGNORE` into a table that must already exist — ordinary DML, using the exact
same restricted credentials this runner already uses successfully in
production every deploy. Before this, shipping a new feature-registry entry
(like the "Comms"/"Docs" toggle work) needed a manual re-run of
`scripts/setup_control_db.php` in production before a superadmin could
actually see/toggle it correctly.
