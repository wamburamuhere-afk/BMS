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

**Not wired into `deploy.yml` yet** (see `docs/MULTI_TENANCY_CONVENTIONS.md` §11).
Run it by hand, or from a separate cron, until Phase 7 gives it real tenants to
migrate.

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
