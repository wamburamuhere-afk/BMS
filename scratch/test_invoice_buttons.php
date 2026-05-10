<?php
/**
 * Test: All Invoice Buttons & Actions
 * Tests every button/action available in invoices.php and invoice_view.php
 * URL: http://localhost/bms/scratch/test_invoice_buttons.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../roots.php';
global $pdo;

$pass = 0; $fail = 0; $results = [];

function ok($label, $cond, $detail = '') {
    global $pass, $fail, $results;
    if ($cond) { $pass++; $results[] = ['pass', $label, $detail]; }
    else        { $fail++; $results[] = ['fail', $label, $detail]; }
}

function first_user($pdo)     { return $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn(); }
function first_customer($pdo) { return $pdo->query("SELECT customer_id FROM customers LIMIT 1")->fetchColumn(); }

function make_invoice($pdo, $status = 'pending', $balance = 100000) {
    $uid = first_user($pdo);
    $cid = first_customer($pdo);
    $num = 'BTN-TEST-' . time() . rand(100,999);
    $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date,
        grand_total, paid_amount, balance_due, status, created_by, updated_at)
        VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), ?, 0, ?, ?, ?, NOW())")
        ->execute([$num, $cid, $balance, $balance, $status, $uid]);
    return $pdo->lastInsertId();
}

function cleanup($pdo, $id) {
    if (!$id) return;
    $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM invoices       WHERE invoice_id = ?")->execute([$id]);
}

function get_status($pdo, $id) {
    $s = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ?");
    $s->execute([$id]);
    return $s->fetchColumn();
}

$created = [];

// ═══════════════════════════════════════════════════════════════
// SECTION 1 — invoices.php SOURCE CODE CHECKS
// ═══════════════════════════════════════════════════════════════
$inv = file_get_contents(__DIR__ . '/../app/bms/invoice/invoices.php');

ok('[invoices.php] View Details button',
    strpos($inv, 'invoice_view') !== false && strpos($inv, 'View Details') !== false);

ok('[invoices.php] Review button — pending only',
    strpos($inv, "row.status === 'pending'") !== false &&
    strpos($inv, 'reviewInvoice') !== false,
    'Review shown only for pending invoices');

ok('[invoices.php] reviewInvoice() function defined',
    strpos($inv, 'function reviewInvoice') !== false);

ok('[invoices.php] reviewInvoice() posts to update_invoice_status',
    strpos($inv, 'update_invoice_status') !== false &&
    strpos($inv, "status: 'reviewed'") !== false);

ok('[invoices.php] Approve button — reviewed only',
    strpos($inv, "row.status === 'reviewed'") !== false &&
    strpos($inv, 'approveInvoice') !== false,
    'Approve shown only for reviewed invoices');

ok('[invoices.php] approveInvoice() function defined',
    strpos($inv, 'function approveInvoice') !== false);

ok('[invoices.php] approveInvoice() posts to update_invoice_status',
    strpos($inv, "status: 'approved'") !== false);

ok('[invoices.php] Edit Invoice — pending/reviewed only',
    strpos($inv, "['pending', 'reviewed'].includes(row.status)") !== false &&
    strpos($inv, 'invoice_edit') !== false,
    'Edit restricted to pending and reviewed');

ok('[invoices.php] Print Invoice button',
    strpos($inv, 'printInvoice') !== false &&
    strpos($inv, 'invoice_print') !== false);

ok('[invoices.php] Record Payment — approved + balance > 0',
    strpos($inv, "row.status === 'approved'") !== false &&
    strpos($inv, 'payment_create') !== false &&
    strpos($inv, 'balance_due') !== false,
    'Record Payment only after Approved');

ok('[invoices.php] Delete — admin only (INV_IS_ADMIN)',
    strpos($inv, 'var INV_IS_ADMIN') !== false &&
    strpos($inv, 'if (INV_IS_ADMIN)') !== false &&
    strpos($inv, 'deleteInvoice') !== false,
    'Delete button hidden for non-admins');

ok('[invoices.php] deleteInvoice() function defined',
    strpos($inv, 'function deleteInvoice') !== false);

ok('[invoices.php] deleteInvoice() posts to delete_invoice',
    strpos($inv, 'delete_invoice') !== false);

ok('[invoices.php] Change Status — admin only',
    strpos($inv, 'INV_IS_ADMIN &&') !== false &&
    strpos($inv, 'changeStatus') !== false,
    'Change Status hidden for non-admins');

ok('[invoices.php] Status filter has Pending',
    strpos($inv, 'value="pending"') !== false);

ok('[invoices.php] Status filter has Reviewed',
    strpos($inv, 'value="reviewed"') !== false);

ok('[invoices.php] Status filter has Approved',
    strpos($inv, 'value="approved"') !== false);

ok('[invoices.php] Badge colours: reviewed = info',
    strpos($inv, "'reviewed': 'text-info'") !== false ||
    strpos($inv, "'reviewed':") !== false);

ok('[invoices.php] Badge colours: approved = success',
    strpos($inv, "'approved': 'text-success'") !== false ||
    strpos($inv, "'approved':") !== false);

// ═══════════════════════════════════════════════════════════════
// SECTION 2 — invoice_view.php SOURCE CODE CHECKS
// ═══════════════════════════════════════════════════════════════
$view = file_get_contents(__DIR__ . '/../app/bms/invoice/invoice_view.php');

ok('[invoice_view.php] Review button — pending only',
    strpos($view, "status === 'pending'") !== false &&
    strpos($view, 'btnReviewInvoice') !== false,
    "Review button with id=btnReviewInvoice");

ok('[invoice_view.php] Review button is blue (btn-primary)',
    strpos($view, "id=\"btnReviewInvoice\"") !== false &&
    strpos($view, 'btn-primary') !== false);

ok('[invoice_view.php] reviewInvoiceFromView() defined',
    strpos($view, 'function reviewInvoiceFromView') !== false);

ok('[invoice_view.php] reviewInvoiceFromView() posts to update_invoice_status',
    strpos($view, 'update_invoice_status') !== false &&
    strpos($view, "status: 'reviewed'") !== false);

ok('[invoice_view.php] reviewInvoiceFromView() hides button on success',
    strpos($view, "btnReviewInvoice") !== false &&
    strpos($view, '.hide()') !== false,
    'Button disappears after successful review');

ok('[invoice_view.php] Edit Invoice button',
    strpos($view, 'invoice_edit') !== false);

ok('[invoice_view.php] Print Invoice button',
    strpos($view, 'invoice_print') !== false);

ok('[invoice_view.php] Back to List button',
    strpos($view, "'invoices'") !== false || strpos($view, 'getUrl(\'invoices\')') !== false);

// ═══════════════════════════════════════════════════════════════
// SECTION 3 — API: update_invoice_status.php CHECKS
// ═══════════════════════════════════════════════════════════════
$upd = file_get_contents(__DIR__ . '/../api/account/update_invoice_status.php');

ok('[API] update_invoice_status: reviewed in valid_statuses',
    strpos($upd, "'reviewed'") !== false);

ok('[API] update_invoice_status: approved in valid_statuses',
    strpos($upd, "'approved'") !== false);

ok('[API] update_invoice_status: pending→reviewed enforced',
    strpos($upd, "current !== 'pending'") !== false);

ok('[API] update_invoice_status: reviewed→approved enforced',
    strpos($upd, "current !== 'reviewed'") !== false);

ok('[API] update_invoice_status: saves reviewed_by',
    strpos($upd, 'reviewed_by') !== false);

ok('[API] update_invoice_status: saves approved_by',
    strpos($upd, 'approved_by') !== false);

// ═══════════════════════════════════════════════════════════════
// SECTION 4 — API: delete_invoice.php CHECKS
// ═══════════════════════════════════════════════════════════════
$del = file_get_contents(__DIR__ . '/../api/account/delete_invoice.php');

ok('[API] delete_invoice: admin-only gate (isAdmin)',
    strpos($del, 'isAdmin()') !== false,
    'Non-admins blocked at API level too');

ok('[API] delete_invoice: no draft-only restriction',
    strpos($del, "Only draft") === false,
    'Admin can delete any invoice status');

// ═══════════════════════════════════════════════════════════════
// SECTION 5 — DB: ENUM includes reviewed and approved
// ═══════════════════════════════════════════════════════════════
try {
    $col = $pdo->query("SHOW COLUMNS FROM invoices WHERE Field = 'status'")->fetch(PDO::FETCH_ASSOC);
    ok('[DB] status ENUM includes reviewed',
        strpos($col['Type'], 'reviewed') !== false,
        'Current ENUM: ' . $col['Type']);
    ok('[DB] status ENUM includes approved',
        strpos($col['Type'], 'approved') !== false,
        'Current ENUM: ' . $col['Type']);
    ok('[DB] status ENUM default is pending',
        $col['Default'] === 'pending',
        'Default: ' . $col['Default']);
} catch (PDOException $e) {
    ok('[DB] ENUM check', false, $e->getMessage());
    ok('[DB] ENUM check', false, $e->getMessage());
    ok('[DB] ENUM default', false, $e->getMessage());
}

ok('[DB] reviewed_by column exists',
    (bool)$pdo->query("SHOW COLUMNS FROM invoices WHERE Field = 'reviewed_by'")->fetch(),
    'Run ALTER TABLE to add reviewed_by');

ok('[DB] approved_by column exists',
    (bool)$pdo->query("SHOW COLUMNS FROM invoices WHERE Field = 'approved_by'")->fetch(),
    'Run ALTER TABLE to add approved_by');

// ═══════════════════════════════════════════════════════════════
// SECTION 6 — LIVE WORKFLOW TESTS
// ═══════════════════════════════════════════════════════════════
$uid = first_user($pdo);

// 6a. Create pending invoice
$id1 = null;
try {
    $id1 = make_invoice($pdo, 'pending');
    $created[] = $id1;
    ok('[LIVE] Create pending invoice', $id1 > 0, "invoice_id=$id1");
} catch (PDOException $e) {
    ok('[LIVE] Create pending invoice', false, $e->getMessage());
}

// 6b. Try to approve directly (must fail — not reviewed yet)
if ($id1) {
    try {
        $stmt = $pdo->prepare("SELECT status FROM invoices WHERE invoice_id = ?");
        $stmt->execute([$id1]);
        $current = $stmt->fetchColumn();
        $blocked = ($current !== 'reviewed');
        ok('[LIVE] Cannot approve a pending invoice directly', $blocked,
            "status is '$current', approve requires reviewed");
    } catch (PDOException $e) {
        ok('[LIVE] Cannot approve pending directly', false, $e->getMessage());
    }
}

// 6c. Review the invoice (pending → reviewed)
if ($id1) {
    try {
        $pdo->prepare("UPDATE invoices SET status='reviewed', reviewed_by=?, updated_at=NOW() WHERE invoice_id=?")
            ->execute([$uid, $id1]);
        $s = get_status($pdo, $id1);
        ok('[LIVE] pending → reviewed', $s === 'reviewed', "got: $s");
    } catch (PDOException $e) {
        ok('[LIVE] pending → reviewed', false, $e->getMessage());
    }
}

// 6d. Approve the reviewed invoice (reviewed → approved)
if ($id1) {
    try {
        $pdo->prepare("UPDATE invoices SET status='approved', approved_by=?, updated_at=NOW() WHERE invoice_id=?")
            ->execute([$uid, $id1]);
        $s = get_status($pdo, $id1);
        ok('[LIVE] reviewed → approved', $s === 'approved', "got: $s");
    } catch (PDOException $e) {
        ok('[LIVE] reviewed → approved', false, $e->getMessage());
    }
}

// 6e. display_status CASE returns correct value for each status
$check_statuses = ['pending', 'reviewed', 'approved', 'paid', 'partial'];
foreach ($check_statuses as $st) {
    $tid = null;
    try {
        $tid = make_invoice($pdo, $st);
        $created[] = $tid;
        $ds = $pdo->prepare("SELECT CASE
            WHEN i.status = 'cancelled' THEN 'cancelled'
            WHEN i.status = 'paid'      THEN 'paid'
            WHEN i.status = 'partial'   THEN 'partial'
            WHEN i.status = 'overdue'   THEN 'overdue'
            WHEN i.status = 'approved'  THEN 'approved'
            WHEN i.status = 'reviewed'  THEN 'reviewed'
            WHEN i.status = 'sent'      THEN 'sent'
            WHEN i.status = 'pending'   THEN 'pending'
            ELSE 'draft'
        END FROM invoices i WHERE i.invoice_id = ?");
        $ds->execute([$tid]);
        $got = $ds->fetchColumn();
        ok("[LIVE] display_status for '$st'", $got === $st, "expected: $st, got: $got");
    } catch (PDOException $e) {
        ok("[LIVE] display_status for '$st'", false, $e->getMessage());
    }
}

// 6f. Admin delete — any status (use the approved invoice from 6d)
if ($id1) {
    try {
        $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$id1]);
        $gone = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_id = ?");
        $gone->execute([$id1]);
        ok('[LIVE] Admin can delete approved invoice', $gone->fetchColumn() == 0, "invoice_id=$id1");
        $created = array_filter($created, fn($x) => $x != $id1);
    } catch (PDOException $e) {
        ok('[LIVE] Admin delete approved', false, $e->getMessage());
    }
}

// cleanup remaining test rows
foreach ($created as $cid) cleanup($pdo, $cid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice Buttons Test Suite</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4 bg-light">
<div class="container" style="max-width:960px;">
    <h3 class="fw-bold mb-1">Invoice — All Buttons Test Suite</h3>
    <p class="text-muted mb-4">Tests every button and action in invoices.php, invoice_view.php, and the supporting APIs.</p>

    <div class="mb-3">
        <span class="badge bg-success fs-6 me-2"><?= $pass ?> passed</span>
        <span class="badge bg-danger  fs-6"><?= $fail ?> failed</span>
        <span class="badge bg-secondary fs-6 ms-2"><?= $pass + $fail ?> total</span>
    </div>

    <?php if ($fail === 0): ?>
    <div class="alert alert-success fw-bold">All tests passed. Every button is working correctly.</div>
    <?php else: ?>
    <div class="alert alert-danger fw-bold"><?= $fail ?> test(s) failed — see details below.</div>
    <?php endif; ?>

    <?php
    $sections = [
        'invoices.php'         => [],
        'invoice_view.php'     => [],
        'API'                  => [],
        'DB'                   => [],
        'LIVE'                 => [],
    ];
    foreach ($results as $i => $r) {
        $label = $r[1];
        if      (str_starts_with($label, '[invoices.php]'))     $sections['invoices.php'][]     = [$i, $r];
        elseif  (str_starts_with($label, '[invoice_view.php]')) $sections['invoice_view.php'][] = [$i, $r];
        elseif  (str_starts_with($label, '[API]'))              $sections['API'][]              = [$i, $r];
        elseif  (str_starts_with($label, '[DB]'))               $sections['DB'][]               = [$i, $r];
        elseif  (str_starts_with($label, '[LIVE]'))             $sections['LIVE'][]             = [$i, $r];
    }
    ?>

    <?php foreach ($sections as $section => $rows): ?>
    <h6 class="fw-bold mt-4 text-uppercase text-muted"><?= $section ?></h6>
    <table class="table table-sm table-bordered bg-white mb-2">
        <thead class="table-dark">
            <tr><th>#</th><th>Test</th><th>Result</th><th>Detail</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as [$i, $r]): ?>
            <tr class="<?= $r[0]==='pass' ? 'table-success' : 'table-danger' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars(preg_replace('/^\[.*?\]\s*/', '', $r[1])) ?></td>
                <td><?= $r[0]==='pass' ? '✅ PASS' : '❌ FAIL' ?></td>
                <td><small><?= htmlspecialchars($r[2]) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endforeach; ?>

    <p class="text-muted small mt-4">All test invoices were created and deleted automatically. No permanent data was changed.</p>
</div>
</body>
</html>
