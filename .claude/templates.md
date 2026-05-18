# BMS — New Page & API Templates

Use these templates verbatim when creating any new page or API endpoint.

## §8. New Page Template (PHP)

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
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
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
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
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

## §9. New API Endpoint Template (PHP)

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

// 4. CSRF + Input validation
csrf_check();
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

## §10. URL & Routing Rules

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

## §11. Permission System

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

### §11.1 Workflow-Status Permissions (beyond CRUD)

`canView / canCreate / canEdit / canDelete` cover **basic CRUD only**. They do **not** cover workflow transitions — moving a record from `draft → submitted → reviewed → approved → posted → void`. Every status change needs its own permission gate.

| Status verb | When to use | Helper | Typical role allowed |
|---|---|---|---|
| `can_submit` | Move `draft → submitted` | `canSubmit($pageKey)` | Creator, Sales, Procurement |
| `can_review` | Move `submitted → reviewed` | `canReview($pageKey)` | Manager, Senior Officer |
| `can_approve` | Move `reviewed → approved` | `canApprove($pageKey)` | Manager, Director |
| `can_post` | Move `approved → posted` (immutable) | `canPost($pageKey)` | Accountant |
| `can_void` | Reverse a posted record | `canVoid($pageKey)` | Accountant, Manager |
| `can_reject` | Move any state → `rejected` | `canReject($pageKey)` | Reviewer, Approver |
| `can_publish` | Make draft visible to others | `canPublish($pageKey)` | Manager |
| `can_cancel` | Cancel without posting | `canCancel($pageKey)` | Creator + Manager |
| `can_reopen` | Move `closed → open` | `canReopen($pageKey)` | Manager |
| `can_export` | Download Excel/PDF | `canExport($pageKey)` | Manager, Accountant, Auditor |
| `can_print` | Print individual record | `canPrint($pageKey)` | All operational roles |

**Rule:** if the page has a **status column or status-change button**, every transition button must be gated by the matching `canX()` helper. If the verb does not yet exist in the permissions table, add it via a migration as part of the task.

**Pattern — defining workflow gates:**
```php
$can_view     = canView('purchase_orders');
$can_create   = canCreate('purchase_orders');
$can_edit     = canEdit('purchase_orders');
$can_delete   = canDelete('purchase_orders');
$can_submit   = canSubmit('purchase_orders');
$can_review   = canReview('purchase_orders');
$can_approve  = canApprove('purchase_orders');
$can_post     = canPost('purchase_orders');
$can_void     = canVoid('purchase_orders');
$can_reject   = canReject('purchase_orders');

if (!$can_view) { header("Location: " . getUrl('unauthorized')); exit(); }
```

**Pattern — rendering status-change buttons:**
```php
<?php $status = $row['status']; // 'draft','submitted','reviewed','approved','posted','void','rejected'

if ($status === 'draft' && $can_submit): ?>
    <button class="btn btn-sm btn-outline-primary" onclick="changeStatus(<?= $row['id'] ?>, 'submitted')">
        <i class="bi bi-send"></i> Submit
    </button>
<?php endif;
if ($status === 'submitted' && $can_review): ?>
    <button class="btn btn-sm btn-outline-info" onclick="changeStatus(<?= $row['id'] ?>, 'reviewed')">
        <i class="bi bi-check2"></i> Mark Reviewed
    </button>
<?php endif;
if ($status === 'reviewed' && $can_approve): ?>
    <button class="btn btn-sm btn-outline-success" onclick="changeStatus(<?= $row['id'] ?>, 'approved')">
        <i class="bi bi-check2-all"></i> Approve
    </button>
<?php endif;
if ($status === 'approved' && $can_post): ?>
    <button class="btn btn-sm btn-success" onclick="changeStatus(<?= $row['id'] ?>, 'posted')">
        <i class="bi bi-lock"></i> Post
    </button>
<?php endif;
if ($status === 'posted' && $can_void): ?>
    <button class="btn btn-sm btn-outline-danger" onclick="changeStatus(<?= $row['id'] ?>, 'void')">
        <i class="bi bi-x-octagon"></i> Void
    </button>
<?php endif;
if (in_array($status, ['submitted','reviewed','approved'], true) && $can_reject): ?>
    <button class="btn btn-sm btn-outline-warning" onclick="changeStatus(<?= $row['id'] ?>, 'rejected')">
        <i class="bi bi-slash-circle"></i> Reject
    </button>
<?php endif; ?>
```

**Pattern — API enforcement:**
```php
// api/change_po_status.php
require_once __DIR__ . '/../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }
csrf_check();

$id        = intval($_POST['id'] ?? 0);
$newStatus = $_POST['new_status'] ?? '';

$transitions = [
    'draft'     => ['submitted' => 'submit'],
    'submitted' => ['reviewed'  => 'review',  'rejected' => 'reject'],
    'reviewed'  => ['approved'  => 'approve', 'rejected' => 'reject'],
    'approved'  => ['posted'    => 'post',    'rejected' => 'reject'],
    'posted'    => ['void'      => 'void'],
];

$stmt = $pdo->prepare("SELECT status FROM purchase_orders WHERE id = ? AND status != 'deleted'");
$stmt->execute([$id]);
$current = $stmt->fetchColumn();

if (!$current) { echo json_encode(['success'=>false,'message'=>'Record not found']); exit; }
if (!isset($transitions[$current][$newStatus])) {
    echo json_encode(['success'=>false,'message'=>"Cannot move $current → $newStatus"]); exit;
}

$verb = $transitions[$current][$newStatus];
$fn = 'can' . ucfirst($verb);
if (!$fn('purchase_orders')) {
    echo json_encode(['success'=>false,'message'=>'You do not have permission to '.$verb]); exit;
}

$pdo->prepare("UPDATE purchase_orders SET status = ?, status_changed_by = ?, status_changed_at = NOW() WHERE id = ?")
    ->execute([$newStatus, $_SESSION['user_id'], $id]);

logActivity($pdo, $_SESSION['user_id'], "PO #$id: $current → $newStatus");
logAudit($pdo, $_SESSION['user_id'], "po_$verb", [
    'entity_type' => 'purchase_order', 'entity_id' => $id,
    'old_values'  => ['status' => $current],
    'new_values'  => ['status' => $newStatus],
]);

echo json_encode(['success'=>true,'message'=>'Status updated to '.$newStatus]);
```

**When you touch a page that has a status column** (always check first):
1. Identify every possible status transition.
2. Confirm each has a `canX()` gate in PHP and in JS.
3. Confirm the API enforces the same gate.
4. Confirm `logActivity` + `logAudit` fire on every transition.
5. Confirm the UI hides the transition button when the user lacks permission AND when the current status doesn't permit it.

---

## §12. Soft Delete — Never Use Hard DELETE

```php
// Correct
$stmt = $pdo->prepare("UPDATE my_table SET status = 'deleted' WHERE id = ?");
$stmt->execute([$id]);

// Wrong — never do this
$stmt = $pdo->prepare("DELETE FROM my_table WHERE id = ?");
```

Every `SELECT` query on user-managed tables must exclude deleted rows:
```php
SELECT * FROM my_table WHERE status != 'deleted'
```

---

## §13. Activity Logging — Required on Every Write

```php
// PHP — after a successful write:
logActivity($pdo, $_SESSION['user_id'], "Created supplier: $supplier_name");
logActivity($pdo, $_SESSION['user_id'], "Updated invoice #$invoice_id");
logActivity($pdo, $_SESSION['user_id'], "Deleted product: $product_name");
```

```js
// JS — for client-side events
logActivityAction('create', 'expense', 'Added expense: Office supplies', 'expense', 42);
// args: action, activity_type, description, entity_type (optional), entity_id (optional)
```

---

## §14. Safe Output / XSS Prevention

```php
// PHP — use safe_output()
echo safe_output($row['customer_name']);        // escapes + returns 'N/A' if empty
echo safe_output($row['notes'], '—');           // custom default when empty
```

```js
// JS — use safeOutput() inside template literals
`<div class="fw-bold">${safeOutput(row.customer_name)}</div>`
```

---

## §15. Icon Library — Bootstrap Icons Only

Use `bi bi-*` only. Do not introduce Font Awesome (`fa fa-*`) on new pages.

Common icons: `bi-plus-circle`, `bi-pencil`, `bi-trash`, `bi-eye`, `bi-search`, `bi-download`, `bi-printer`, `bi-check-circle`, `bi-x-circle`, `bi-envelope`, `bi-telephone`, `bi-geo-alt`, `bi-person`, `bi-building`, `bi-calendar`, `bi-currency-dollar`, `bi-grid`

---

## §16. AJAX Submit Button Pattern

Every form submit handler must disable the submit button with a spinner, then restore on completion.

```js
$('#myForm').on('submit', function (e) {
    e.preventDefault();
    const btn  = $(this).find('[type="submit"]');
    const orig = btn.html();
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
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
});
```

Rules: `contentType: false` + `processData: false` required for FormData. Always use `complete:` to restore the button. Never use `$.post()` shorthand for form submissions.

---

## §17. Statistics Cards Pattern

Every list page must show a summary row of metric cards above the table.

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

Colour convention: `text-primary` = total, `text-success` = active/good, `text-warning` = pending/caution, `text-danger` = inactive/overdue.
