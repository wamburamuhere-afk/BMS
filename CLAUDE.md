# BMS — Claude Code Instructions

## Project Overview
Full-stack PHP/MySQL ERP for BJP Technologies Co. Ltd (Tanzania).
Stack: PHP, MySQL, Bootstrap 5, PDO, GitHub Actions CI/CD.
Live sites deploy automatically on push to `main` via `.github/workflows/deploy.yml`.

---

## Database Schema Changes — Migration System

**ALWAYS use the migration file approach for any database change.**
Never suggest raw SQL to run manually. Every schema change must go through a migration file.

### How it works
- Migration files live in `migrations/` and are named `YYYY_MM_DD_description.php`
- `migrations/runner.php` runs all pending files on every deploy (via GitHub Actions)
- The `migrations` table in the database tracks which files have already run
- `migrations/status.php` — browser dashboard to check what has/hasn't run

### Rules for writing migration files

1. **Filename format:** `migrations/YYYY_MM_DD_short_description.php` (e.g. `2026_05_15_add_invoice_status.php`)

2. **Structure — always use this template:**
```php
<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: <description>...\n";

try {
    // your SQL here
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

3. **Always make migrations idempotent** (safe to run twice):
   - `CREATE TABLE IF NOT EXISTS` — never plain `CREATE TABLE`
   - Check with `SHOW COLUMNS FROM table LIKE 'col'` before `ALTER TABLE ADD COLUMN`
   - Use `INSERT IGNORE` instead of `INSERT` for seed data
   - For DROP + RECREATE: check if the change is needed first, skip if already done

4. **Never wrap DDL in transactions.** MySQL DDL (CREATE TABLE, ALTER TABLE, DROP TABLE) auto-commits. Calling `beginTransaction()` then DDL then `commit()` throws "There is no active transaction". Remove all `beginTransaction/commit/rollBack` from migrations that contain DDL.

5. **Foreign key constraint on DROP TABLE** — if dropping a table that other tables reference via FK:
```php
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("DROP TABLE IF EXISTS the_table");
$pdo->exec("CREATE TABLE the_table ( ... ) ENGINE=InnoDB");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
```

6. **DML-only migrations** (INSERT/UPDATE/DELETE only, no DDL) may use transactions normally.

7. **exit(1) on failure** — the runner detects this and stops the deploy. Never suppress errors silently.

### Deploy flow
```
Push to main
  → GitHub Actions triggers
  → SSH into each server
  → git reset --hard HEAD   (discard any local changes)
  → git pull origin main
  → php migrations/runner.php
  → runner finds pending files, runs each as subprocess
  → records success in migrations table
```

### Checking migration status
Visit `/migrations/status.php` on the live site (requires BMS login) to see ran vs pending files and the deploy log.

### Runner flags
- `php migrations/runner.php` — normal run, executes pending migrations
- `php migrations/runner.php --seed` — marks all files as done WITHOUT running them (use on a server where migrations were applied manually)

---

## General Rules

- Ask for go-ahead before making any edit; only modify exactly what is instructed
- Log every change to `changelog.md` with date, file, and description
- Never push directly to `main` — always use a feature branch and open a PR
- Use `git reset --hard HEAD && git pull origin main` (not plain `git pull`) in deploy scripts
- `script_stop: true` must remain in deploy.yml so a failed migration halts the deploy
