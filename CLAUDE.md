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

---

## Development Standards (apply to every file touched)

These rules apply automatically whenever any file is modified. No need to repeat them per task.

### 1. Button Testing After Every Modification
After modifying any file, verify **every interactive element** in that file still works:
- Every button (Add, Edit, Delete, Save, Cancel, Status change, Export, Print, etc.)
- Every form submission
- Every modal trigger
- Do not leave any button untested — even buttons outside the directly modified section must be confirmed working.

### 2. Add Form ↔ Edit Form Parity
Whenever a field is added to or removed from a registration / "Add New" form, apply the **exact same change** to the corresponding Edit form in the same file (or linked edit file). Both forms must always stay in sync — same fields, same validation, same order.

### 3. DataTable Enforcement
Before modifying any file, check whether it contains an HTML `<table>`. If it does:
- Confirm the table is already initialised as a **DataTable** (search, sort, pagination).
- If it is not, convert it to a DataTable as part of the task.
- Use the project's standard DataTable initialisation pattern (Bootstrap 5 styling, responsive extension).

### 4. Searchable Dropdowns (Select2)
Every `<select>` that is populated from the database must use **Select2** so the user can search by typing — never a plain `<select>`.

**Standard pattern (static Select2 — pre-loaded options):**
- Add the `select2-static` CSS class to the `<select>` element.
- PHP pre-loads the options into `<option>` tags at render time.
- `initSelect2()` picks up every `.select2-static` element and wraps it with:
```js
$(el).select2({
    theme: 'bootstrap-5',
    dropdownParent: isInsideModal ? $('#theModal') : null,
    placeholder: 'Select...',
    allowClear: true,
    width: '100%'
});
```
- Use this pattern even for very small lists (2–5 items). Select2's built-in filtering handles the search.
- For dynamically populated selects (options change at runtime, e.g. a payee list that depends on a prior selection), destroy the old Select2 instance before repopulating, then re-init after appending the new options.
- When touching any file, audit all dropdowns in that file and upgrade any plain `<select>` backed by database data to Select2.

### 5. Responsive Layout — Mobile Card View & Sticky Header
Apply to every file touched:

**Mobile view (`max-width: 767px`):**
- All tables must render as **card view by default** — no toggle to switch back to table view on mobile.
- Each card must show all row data in a compact, well-aligned layout sized for small screens.
- Action buttons (Edit, Delete, View, Status, etc.) must appear **below each card in a single responsive row** — never wrap to multiple rows; use `d-flex flex-wrap gap-1` or equivalent so they stay tight.
- Action button row background must be **white** (consistent with existing card styling across the project).
- All non-button text/labels within a card must be small (`font-size: 0.8rem` or `small` tag) and well-aligned.
- The **page header / navbar must stick to the top** (`position: sticky; top: 0; z-index: 1020`) in mobile view, matching behaviour already implemented on other pages.

**Desktop / web view (`min-width: 768px`):**
- Default display is **table view**.
- A toggle button to switch to card view is allowed but optional.
- All existing desktop styling and functionality must remain unchanged.

### 6. Branch & Commit Workflow
- Never commit directly to `main` or `develop`.
- For every task, create a **new dedicated feature branch** named `feature/<short-description>` (e.g. `feature/add-supplier-notes`).
- Commit all changes for that task to the feature branch.
- Push the branch and leave it for the user to open a PR into `develop`, then `main`.
