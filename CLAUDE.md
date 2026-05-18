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
- The **table/card view toggle** must be **hidden on mobile** (`d-none d-md-flex` on the toggle button group). Mobile always shows card view; the user must not see a switch-to-table option.
- Each card must show all row data in a compact, well-aligned layout sized for small screens.
- Action buttons (Edit, Delete, View, Status, etc.) must appear **below each card in a single non-wrapping row — no matter how many buttons there are**. Never use `flex-wrap` or `overflow-auto`. Use `display:flex; flex-wrap:nowrap` with `flex:1; min-width:0` on each button so they share the available width equally and shrink to fit — no scrolling, no wrapping. Use **icon-only buttons** (no text labels) on mobile cards to keep them compact. Apply small padding (`padding: 3px 4px`) and small font-size (`0.72rem`) on card buttons.
- Action button row background must be **white** (consistent with existing card styling across the project).
- All non-button text/labels within a card must be small (`font-size: 0.8rem` or `small` tag) and well-aligned.
- The **page header / navbar must stick to the top** (`position: sticky; top: 0; z-index: 1020`) in mobile view, matching behaviour already implemented on other pages.

**Desktop / web view (`min-width: 768px`):**
- Default display is **table view**.
- A toggle button to switch to card view is allowed but optional — **only visible on desktop**.
- All existing desktop styling and functionality must remain unchanged.

### 6. SweetAlert2 for All Alerts & Confirmations
**Never use native browser dialogs** — `alert()`, `confirm()`, or `prompt()` are forbidden everywhere in the project. They produce "dev.bms.local says…" pop-ups that look unprofessional and break the UI.

**Always use SweetAlert2 (`Swal.fire`)** for:
- Confirmation dialogs before destructive actions (delete, status change, etc.)
- Success / error feedback after async operations
- Any informational alert shown to the user

**Standard patterns:**
```js
// Confirmation before delete
Swal.fire({
    title: 'Delete?', text: 'This action cannot be undone.', icon: 'warning',
    showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
}).then(r => { if (r.isConfirmed) { /* do the delete */ } });

// Loading while async runs
Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

// Success after async
Swal.fire({ icon: 'success', title: 'Done!', text: res.message, confirmButtonColor: '#198754' });

// Error
Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
```

When touching any file, **audit all `alert()` / `confirm()` calls** and replace them with the patterns above.

### 7. Branch & Commit Workflow
- Never commit directly to `main` or `develop`.
- For every task, create a **new dedicated feature branch** named `feature/<short-description>` (e.g. `feature/add-supplier-notes`).
- Commit all changes for that task to the feature branch.
- Push the branch and leave it for the user to open a PR into `develop`, then `main`.

---

## New Page Reference — Templates & Patterns

Use these templates verbatim when creating any new page or API endpoint. They encode every project convention in one place.

### 8. New Page Template (PHP)

Every page in `app/bms/` or `app/constant/` follows this exact skeleton:

```php
<?php
ob_start();

// Set page title BEFORE including header
$page_title = 'My Page Title';
require_once __DIR__ . '/../../header.php';  // adjust depth as needed

// Permission check — page_key must exist in the permissions table
$can_view   = canView('page_key');
$can_create = canCreate('page_key');
$can_edit   = canEdit('page_key');
$can_delete = canDelete('page_key');

if (!$can_view) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

// Fetch data
$stmt = $pdo->prepare("SELECT * FROM my_table WHERE status != 'deleted' ORDER BY created_at DESC");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-grid me-2"></i>My Page Title</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle me-1"></i> Add New
        </button>
        <?php endif; ?>
    </div>

    <!-- Statistics cards (one per key metric) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary"><?= count($rows) ?></div>
                <div class="small text-muted">Total</div>
            </div>
        </div>
    </div>

    <!-- Desktop table / mobile card container -->
    <div id="tableView">
        <table id="myTable" class="table table-hover align-middle w-100">
            <thead class="table-dark">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $i => $row): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= safe_output($row['name']) ?></td>
                    <td><span class="badge bg-success"><?= safe_output($row['status']) ?></span></td>
                    <td class="text-end">
                        <?php if ($can_edit): ?>
                        <button class="btn btn-sm btn-outline-primary" onclick="editRow(<?= $row['id'] ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php endif; ?>
                        <?php if ($can_delete): ?>
                        <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(<?= $row['id'] ?>)">
                            <i class="bi bi-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile card view (populated by DataTable drawCallback) -->
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Add Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Add New</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm" autocomplete="off">
                <div class="modal-body">
                    <div id="add-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Edit Modal (mirror of Add Modal with edit_ prefixed IDs) -->
<?php if ($can_edit): ?>
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_id">
                    <div id="edit-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle me-1"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function () {
    // DataTable init
    if (!$.fn.DataTable.isDataTable('#myTable')) {
        const table = $('#myTable').DataTable({
            responsive: false,
            scrollX: true,
            pageLength: 25,
            order: [[1, 'asc']],
            dom: 'rtipB',
            buttons: [
                { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
            ],
            drawCallback: function () {
                renderCards(this.api().rows({ page: 'current' }).data().toArray());
            }
        });
    }

    // View toggle: card on mobile, table on desktop
    function applyView() {
        if (window.innerWidth < 768) {
            $('#tableView').addClass('d-none');
            $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none');
            $('#cardView').addClass('d-none');
        }
    }
    applyView();
    $(window).on('resize', applyView);

    // Add form submit
    $('#addForm').on('submit', function (e) {
        e.preventDefault();
        submitForm(this, '<?= buildUrl('api/add_item.php') ?>', function () {
            location.reload();
        });
    });

    // Edit form submit
    $('#editForm').on('submit', function (e) {
        e.preventDefault();
        submitForm(this, '<?= buildUrl('api/edit_item.php') ?>', function () {
            location.reload();
        });
    });

    // Clear modals on close
    $('.modal').on('hidden.bs.modal', function () {
        $(this).find('form')[0]?.reset();
        $(this).find('[id$="-message"]').html('');
    });

    // Init Select2 in modals
    $('#addModal, #editModal').on('shown.bs.modal', function () {
        const modal = $(this);
        modal.find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: modal, placeholder: 'Select...', allowClear: true, width: '100%' });
            }
        });
    });
});

// Shared AJAX submit helper — disables button, restores on complete
function submitForm(form, url, onSuccess) {
    const btn = $(form).find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
    $.ajax({
        url: url,
        type: 'POST',
        data: new FormData(form),
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Success!', text: res.message, timer: 2000, showConfirmButton: false }).then(onSuccess);
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
}

// Load row data into edit modal
function editRow(id) {
    $.getJSON('<?= buildUrl('api/get_item.php') ?>', { id: id }, function (res) {
        if (res.success) {
            $('#edit_id').val(res.data.id);
            $('#edit_name').val(res.data.name);
            new bootstrap.Modal(document.getElementById('editModal')).show();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Could not load data.' });
        }
    });
}

// Delete confirmation
function confirmDelete(id) {
    Swal.fire({
        title: 'Delete?', text: 'This action cannot be undone.', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/delete_item.php') ?>', { id }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

// Mobile card renderer — customise per page
function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No records found</div>');
        return;
    }
    let html = '';
    rows.forEach(row => {
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="fw-bold">${safeOutput(row.name)}</div>
                    <small class="text-muted">${safeOutput(row.status)}</small>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                        <button class="btn btn-sm btn-outline-primary" onclick="editRow(${row.id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-sm btn-outline-danger"  onclick="confirmDelete(${row.id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php require_once __DIR__ . '/../../footer.php'; ?>
```

---

### 9. New API Endpoint Template (PHP)

Every file in `api/` follows this exact 6-step structure:

```php
<?php
require_once __DIR__ . '/../../roots.php';   // adjust depth to reach project root

header('Content-Type: application/json');

// 1. Auth check
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. Permission check
if (!canCreate('page_key')) {  // use canView / canCreate / canEdit / canDelete as appropriate
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

// 3. Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 4. Input validation
$name = trim($_POST['name'] ?? '');
if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Name is required']);
    exit;
}

// 5. Business logic
try {
    $stmt = $pdo->prepare("INSERT INTO my_table (name, created_by, created_at) VALUES (?, ?, NOW())");
    $stmt->execute([$name, $_SESSION['user_id']]);
    $new_id = $pdo->lastInsertId();

    // 6. Activity log — required on every create / update / delete
    logActivity($pdo, $_SESSION['user_id'], "Created item: $name");

    echo json_encode(['success' => true, 'message' => 'Item created successfully.', 'id' => $new_id]);

} catch (PDOException $e) {
    error_log("API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
```

**GET endpoint (fetch single record for edit modal):**

```php
<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit; }
if (!canView('page_key')) { echo json_encode(['success' => false, 'message' => 'Permission denied']); exit; }

$id = intval($_GET['id'] ?? 0);
if (!$id) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

$stmt = $pdo->prepare("SELECT * FROM my_table WHERE id = ? AND status != 'deleted'");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) { echo json_encode(['success' => false, 'message' => 'Record not found']); exit; }

echo json_encode(['success' => true, 'data' => $row]);
```

---

### 10. URL & Routing Rules

Never hardcode paths. Always use the two URL helpers:

| Use case | Function | Example |
|---|---|---|
| `<a href>` links, `src=`, `action=` | `getUrl('path')` | `getUrl('customers')` |
| JS AJAX `url:` targets | `buildUrl('path')` | `buildUrl('api/delete_customer.php')` |

`getUrl()` returns a root-relative path; `buildUrl()` returns the full `https://…` URL needed for jQuery AJAX to work across environments (local, staging, production).

```php
<!-- Correct -->
<a href="<?= getUrl('customers') ?>">Customers</a>
<img src="<?= getUrl('assets/images/logo.png') ?>">

<!-- Correct in JS -->
$.ajax({ url: '<?= buildUrl('api/delete_customer.php') ?>' });

<!-- Wrong — breaks on subdirectory installs and production -->
<a href="/customers">Customers</a>
$.ajax({ url: '/api/delete_customer.php' });
```

---

### 11. Permission System

**Page key** — every page has a short string key that must exist in the `permissions` table (column `page_key`). Use the same key consistently across the page and its API files.

```php
// At the top of every page, after header.php:
$can_view   = canView('customers');    // blocks page if false
$can_create = canCreate('customers');  // show/hide Add button
$can_edit   = canEdit('customers');    // show/hide Edit button
$can_delete = canDelete('customers');  // show/hide Delete button

if (!$can_view) { header("Location: " . getUrl('unauthorized')); exit(); }
```

**In API files** — check the specific permission before acting:
```php
if (!canDelete('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']); exit;
}
```

**In JS** — pass the permission flag from PHP so buttons are hidden when rendered, not just after an API call:
```php
// Render action button conditionally
if (<?= json_encode($can_edit) ?>) {
    actions += `<button onclick="editRow(${row.id})">Edit</button>`;
}
```

**Admin bypass** — `isAdmin()` returns true for `role_id = 1`; all `canX()` functions return true automatically for admins, so no special admin checks are needed.

---

### 12. Soft Delete — Never Use Hard DELETE

The project never runs `DELETE FROM`. All deletions are soft:

```php
// Correct
$stmt = $pdo->prepare("UPDATE my_table SET status = 'deleted' WHERE id = ?");
$stmt->execute([$id]);

// Wrong — never do this
$stmt = $pdo->prepare("DELETE FROM my_table WHERE id = ?");
```

Every `SELECT` query on user-managed tables must exclude deleted rows:

```php
// Always filter deleted
SELECT * FROM my_table WHERE status != 'deleted'
```

---

### 13. Activity Logging — Required on Every Write

Every API endpoint that creates, updates, or deletes data must call `logActivity()`.

**PHP (in API files):**
```php
// After a successful write:
logActivity($pdo, $_SESSION['user_id'], "Created supplier: $supplier_name");
logActivity($pdo, $_SESSION['user_id'], "Updated invoice #$invoice_id");
logActivity($pdo, $_SESSION['user_id'], "Deleted product: $product_name");
```

**JS (for client-side events not going through an API):**
```js
// Available globally via header.php
logActivityAction('create', 'expense', 'Added expense: Office supplies', 'expense', 42);
// args: action, activity_type, description, entity_type (optional), entity_id (optional)
```

---

### 14. Safe Output / XSS Prevention

All user-supplied or database-sourced values rendered in HTML must be escaped.

**PHP — use `safe_output()`:**
```php
// Correct
echo safe_output($row['customer_name']);        // escapes + returns 'N/A' if empty
echo safe_output($row['notes'], '—');           // custom default when empty

// Also acceptable for inline use:
<?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?>

// Wrong — raw output
echo $row['customer_name'];
```

**JS — use `safeOutput()` (globally available via header.php):**
```js
// Correct — inside template literals / innerHTML
`<div class="fw-bold">${safeOutput(row.customer_name)}</div>`

// Wrong
`<div class="fw-bold">${row.customer_name}</div>`
```

---

### 15. Icon Library — Bootstrap Icons Only

The project uses **Bootstrap Icons** (`bi bi-*`) everywhere. Do not introduce Font Awesome (`fa fa-*`) classes on new pages.

```html
<!-- Correct -->
<i class="bi bi-plus-circle me-1"></i> Add New
<i class="bi bi-pencil"></i>
<i class="bi bi-trash"></i>
<i class="bi bi-eye"></i>
<i class="bi bi-search"></i>
<i class="bi bi-download"></i>
<i class="bi bi-printer"></i>
<i class="bi bi-check-circle"></i>
<i class="bi bi-x-circle"></i>
<i class="bi bi-envelope"></i>
<i class="bi bi-telephone"></i>
<i class="bi bi-geo-alt"></i>
<i class="bi bi-person"></i>
<i class="bi bi-building"></i>
<i class="bi bi-calendar"></i>
<i class="bi bi-currency-dollar"></i>
<i class="bi bi-grid"></i>

<!-- Wrong — Font Awesome (already loaded in header but not the project standard) -->
<i class="fa fa-plus"></i>
```

---

### 16. AJAX Submit Button Pattern

Every form submit handler must disable the submit button with a loading spinner during the request, then restore it on completion — whether the request succeeds or fails.

```js
$('#myForm').on('submit', function (e) {
    e.preventDefault();
    const btn  = $(this).find('[type="submit"]');
    const orig = btn.html();

    // Disable + show spinner
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    $.ajax({
        url: '<?= buildUrl('api/save_item.php') ?>',
        type: 'POST',
        data: new FormData(this),
        contentType: false,
        processData: false,
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
            }
        },
        error: function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
        },
        complete: function () {
            btn.prop('disabled', false).html(orig);  // always restore
        }
    });
});
```

Rules:
- `contentType: false` and `processData: false` are **required** when using `FormData` (file uploads).
- Always use `complete:` (not just `success:`/`error:`) to restore the button so it is restored even on network errors.
- Never use `$.post()` shorthand for form submissions — it doesn't handle `FormData`.

---

### 17. Statistics Cards Pattern

Every list page must show a summary row of metric cards above the table. Use this structure:

```html
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-4 fw-bold text-primary" id="stat-total">0</div>
            <div class="small text-muted">Total</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-4 fw-bold text-success" id="stat-active">0</div>
            <div class="small text-muted">Active</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-4 fw-bold text-warning" id="stat-pending">0</div>
            <div class="small text-muted">Pending</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-4 fw-bold text-danger" id="stat-inactive">0</div>
            <div class="small text-muted">Inactive</div>
        </div>
    </div>
</div>
```

- Use `col-6 col-md-3` so cards show 2-per-row on mobile, 4-per-row on desktop.
- Colour convention: `text-primary` = total, `text-success` = active/good, `text-warning` = pending/caution, `text-danger` = inactive/overdue.
- When using server-side DataTable, update stat values from the `dataSrc` callback using the `stats` object returned by the API.

---

### 18. PDO Query Patterns — Quick Reference

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
