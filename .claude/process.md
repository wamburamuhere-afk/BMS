# BMS — Process: Anti-Patterns, PDO Reference & Page Walkthrough

## §26. Forbidden UI Patterns

When auditing a page, if you find any of the following, fix them as part of the task.

| Anti-pattern | Why it's forbidden |
|---|---|
| **Auto-playing audio / video on page load** | Annoying; modern browsers block it anyway |
| **Modal inside a modal** | Stacking breaks focus, `Esc` closes the wrong one, unusable on mobile |
| **Auto-refresh that wipes user input** | Never reload the whole page — use `ajax.reload()` on the DataTable |
| **Carousels / sliders on dashboards** | The second slide is rarely seen; show the most-important metric statically |
| **Pages that scroll horizontally on mobile** | Use mobile card view (§5). Test at 360px viewport |
| **Buttons that only appear on hover** | Mobile has no hover — always render buttons visibly |
| **Flash messages that disappear under 3 seconds** | SweetAlert2 toasts minimum 4 s (`timer: 4000`); success auto-close minimum 2 s |
| **More than 7 top-level navigation items** | Group related modules into dropdowns |
| **Long forms without "Save & Continue"** | Auto-save draft to `localStorage` every 30 s, or split into tabs |
| **Files over 1000 lines** | Split. Extract JS to `assets/js/pages/<page>.js`, PHP to `includes/` |
| **Functions over 100 lines** | Split into smaller named functions |
| **Nesting deeper than 4 levels** | Use early returns (`if (!$x) return;`) or extract a helper |
| **`!important` in CSS** | Only to override third-party CSS; add a comment explaining why |

---

## §27. PDO Query Patterns — Quick Reference

```php
// Fetch all rows
$rows = $pdo->query("SELECT * FROM my_table WHERE status != 'deleted'")->fetchAll(PDO::FETCH_ASSOC);

// Fetch single row (prepared)
$stmt = $pdo->prepare("SELECT * FROM my_table WHERE id = ? AND status != 'deleted'");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Insert + get new ID
$stmt = $pdo->prepare("INSERT INTO my_table (name, created_by, created_at) VALUES (?, ?, NOW())");
$stmt->execute([$name, $_SESSION['user_id']]);
$new_id = $pdo->lastInsertId();

// Update
$stmt = $pdo->prepare("UPDATE my_table SET name = ?, updated_at = NOW() WHERE id = ?");
$stmt->execute([$name, $id]);

// Soft delete
$stmt = $pdo->prepare("UPDATE my_table SET status = 'deleted' WHERE id = ?");
$stmt->execute([$id]);

// DML transaction (no DDL inside)
$pdo->beginTransaction();
try {
    // multiple inserts/updates here
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    throw $e;
}

// Check a column exists before ALTER
$col = $pdo->query("SHOW COLUMNS FROM my_table LIKE 'new_col'")->fetch();
if (!$col) {
    $pdo->exec("ALTER TABLE my_table ADD COLUMN new_col VARCHAR(100) NULL");
}
```

---

## §28. Page-Touch Walkthrough (do in this order)

Whenever you create or edit any frontend page, tick off each step in the commit message.

| # | Step | Where |
|---|---|---|
| 1 | Create / use a feature branch (`feature/<name>`); never edit on `main` | §7 |
| 2 | Pick the right `page_key` and confirm it exists in the `permissions` table | §11 |
| 3 | Run the page through the page-audit rule: list violations to fix | §1 |
| 4 | If touching the schema, write a migration file (idempotent, `YYYY_MM_DD_*.php`) | migrations.md |
| 5 | Set `$page_title`, include `header.php`, do `canView()` gate at top | §8, §11 |
| 6 | If the page has a status column, gate every transition with `canSubmit/canReview/canApprove/canPost/canVoid/canReject` | §11.1 |
| 7 | All SELECTs filter `WHERE status != 'deleted'`; all deletes are soft | §12 |
| 8 | Every `<table>` is a DataTable; every DB-backed `<select>` is Select2 | §3, §4 |
| 9 | Render mobile card view in `drawCallback`; sticky header on mobile | §5 |
| 10 | Statistics cards row above the table | §17 |
| 11 | Add Modal + Edit Modal — keep fields in parity | §2 |
| 12 | Every form has a CSRF token (`<input name="_csrf" value="<?= csrf_token() ?>">`) | §21 |
| 13 | Every dropdown / select uses Select2 with `select2-static` class | §4 |
| 14 | Every alert / confirm / prompt uses SweetAlert2 — never native | §6 |
| 15 | AJAX submit handler disables button with spinner; restores on `complete:` | §16 |
| 16 | All URLs via `getUrl()` (links) or `buildUrl()` (AJAX) — never hard-coded | §10 |
| 17 | All icons are `bi bi-*` — Bootstrap Icons only | §15 |
| 18 | Every rendered value uses `safe_output()` (PHP) or `safeOutput()` (JS) | §14 |
| 19 | File uploads: 5 checks — extension + finfo MIME + size + safe filename + `.htaccess` | §19 |
| 20 | Build the API in `api/<file>.php` following the 6-step template | §9 |
| 21 | API checks auth → permission → method → CSRF → validate → logic → log → JSON | §9, §11.1, §21 |
| 22 | Every write calls `logActivity()`; sensitive ops call `logAudit()` | §13 |
| 23 | Run per-button test cases (§29) | §29 |
| 24 | Empty state + loading state implemented | §26 |
| 25 | Anti-patterns audit (§26) — no modal-in-modal, no carousel, no hover-only buttons, no files > 1000 lines | §26 |
| 26 | Clean up debug code (`console.log`, `var_dump`, `print_r`, `TODO`) before commit | §1 |
| 27 | Log the change in `changelog.md` with date + file + description | General Rules |
| 28 | Commit on the feature branch with a "why" message; push for PR review | §7 |

If any step **cannot be applied**, say so explicitly in the commit message (e.g. "§3 N/A — page is a single-record dashboard").

---

## §29. Per-Button Test Cases (run after every modification)

After modifying any page, manually exercise these in a browser. If any FAIL, fix before committing.

**View / Detail button** — opens, shows all DB fields, hides soft-deleted rows, gated by `canView`.
**Add button** — opens empty modal, validates required fields, success closes + reloads list, CSRF token present, gated by `canCreate`, logs activity.
**Edit button** — opens pre-filled modal, fields in parity with Add (§2), saves correctly, gated by `canEdit`, updates `updated_at`, logs activity.
**Delete button** — SweetAlert2 confirms (§6), cancel does nothing, confirm soft-deletes (§12), gated by `canDelete`, logs activity.
**Status-change buttons** — each gated by its `canX()` (§11.1), only visible for the correct current status, API enforces the transition, logs both activity + audit.
**Search / Filter** — typing filters in real time, "Clear filters" resets.
**Export button** — file downloads, named `<entity>_<date>.xlsx`, excludes Actions column, gated by `canExport`.
**Print button** — print preview clean, hides navbar/sidebar/buttons, includes print footer.
**Modal close** — X / Esc / outside-click all hide; form resets; Select2 re-initialises next open.
