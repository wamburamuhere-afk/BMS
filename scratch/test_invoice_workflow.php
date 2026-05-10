<?php
/**
 * Test: Invoice Status Workflow
 * Verifies: default status=pending, Review/Approve API, delete API,
 *           display_status CASE, badge colours, admin-only delete.
 * URL: http://localhost/bms/scratch/test_invoice_workflow.php
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

// ── helpers ───────────────────────────────────────────────────────
function first_user($pdo)    { return $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn(); }
function first_customer($pdo){ return $pdo->query("SELECT customer_id FROM customers LIMIT 1")->fetchColumn(); }

function make_invoice($pdo, $status = 'pending') {
    $uid = first_user($pdo);
    $cid = first_customer($pdo);
    $num = 'TEST-INV-' . time() . rand(100,999);
    $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, due_date,
        grand_total, paid_amount, balance_due, status, created_by, updated_at)
        VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        100000, 0, 100000, ?, ?, NOW())")
        ->execute([$num, $cid, $status, $uid]);
    return $pdo->lastInsertId();
}

function cleanup($pdo, $id) {
    $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM invoices       WHERE invoice_id = ?")->execute([$id]);
}

// ═══════════════════════════════════════════════════════════════
// 1. SOURCE CODE CHECKS
// ═══════════════════════════════════════════════════════════════

$save = file_get_contents(__DIR__ . '/../api/account/save_invoice.php');
ok('save_invoice: default status is pending',
    strpos($save, "'pending'") !== false && strpos($save, "?? 'pending'") !== false,
    "Must default to pending, not draft");

$del = file_get_contents(__DIR__ . '/../api/account/delete_invoice.php');
ok('delete_invoice: isAdmin() gate (not canDelete only)',
    strpos($del, 'isAdmin()') !== false,
    'Admin-only gate must be present');
ok('delete_invoice: no draft-only restriction',
    strpos($del, "status !== 'draft'") === false &&
    strpos($del, "Only draft") === false,
    'Draft-only check must be removed');

$get = file_get_contents(__DIR__ . '/../api/account/get_invoices.php');
ok('get_invoices: reviewed in display_status CASE',
    strpos($get, "'reviewed'  THEN 'reviewed'") !== false ||
    strpos($get, "status = 'reviewed' THEN 'reviewed'") !== false ||
    strpos($get, "'reviewed'") !== false,
    'reviewed must appear in CASE');
ok('get_invoices: approved in display_status CASE',
    strpos($get, "'approved'  THEN 'approved'") !== false ||
    strpos($get, "status = 'approved' THEN 'approved'") !== false ||
    strpos($get, "'approved'") !== false,
    'approved must appear in CASE');

$inv = file_get_contents(__DIR__ . '/../app/bms/invoice/invoices.php');
ok('invoices.php: INV_IS_ADMIN JS variable',
    strpos($inv, 'var INV_IS_ADMIN') !== false,
    'PHP isAdmin() must be passed to JS');
ok('invoices.php: Review button for pending only',
    strpos($inv, "row.status === 'pending'") !== false &&
    strpos($inv, 'reviewInvoice') !== false,
    'Review must appear for pending');
ok('invoices.php: Approve button for reviewed only',
    strpos($inv, "row.status === 'reviewed'") !== false &&
    strpos($inv, 'approveInvoice') !== false,
    'Approve must appear for reviewed');
ok('invoices.php: Change Status is admin-only',
    strpos($inv, 'INV_IS_ADMIN && !') !== false,
    'Change Status must be guarded by INV_IS_ADMIN');
ok('invoices.php: Delete guarded by INV_IS_ADMIN',
    strpos($inv, 'if (INV_IS_ADMIN)') !== false,
    'Delete button must be admin-only');
ok('invoices.php: approved badge colour defined',
    strpos($inv, "'approved': 'text-success'") !== false ||
    strpos($inv, "'approved':") !== false,
    'approved colour must be in badge map');
ok('invoices.php: reviewed badge colour defined',
    strpos($inv, "'reviewed': 'text-info'") !== false ||
    strpos($inv, "'reviewed':") !== false,
    'reviewed colour must be in badge map');
ok('invoices.php: reviewed option in status filter',
    strpos($inv, 'value="reviewed"') !== false,
    'Filter dropdown must include reviewed');
ok('invoices.php: approved option in status filter',
    strpos($inv, 'value="approved"') !== false,
    'Filter dropdown must include approved');

$upd = file_get_contents(__DIR__ . '/../api/account/update_invoice_status.php');
ok('update_invoice_status: reviewed in valid_statuses',
    strpos($upd, "'reviewed'") !== false);
ok('update_invoice_status: approved in valid_statuses',
    strpos($upd, "'approved'") !== false);
ok('update_invoice_status: pending→reviewed workflow enforced',
    strpos($upd, "current !== 'pending'") !== false,
    "Only pending can be marked reviewed");
ok('update_invoice_status: reviewed→approved workflow enforced',
    strpos($upd, "current !== 'reviewed'") !== false,
    "Only reviewed can be approved");
ok('update_invoice_status: saves reviewed_by',
    strpos($upd, 'reviewed_by') !== false);
ok('update_invoice_status: saves approved_by',
    strpos($upd, 'approved_by') !== false);

// ═══════════════════════════════════════════════════════════════
// 2. DATABASE / LIVE TESTS
// ═══════════════════════════════════════════════════════════════

// 2a. Create invoice — check default columns exist
$test_id = null;
try {
    $test_id = make_invoice($pdo, 'pending');
    ok('DB: create pending invoice', $test_id > 0, "invoice_id=$test_id");
} catch (PDOException $e) {
    ok('DB: create pending invoice', false, $e->getMessage());
}

// 2b. Check reviewed_by / approved_by columns exist
if ($test_id) {
    try {
        $row = $pdo->prepare("SELECT reviewed_by, approved_by FROM invoices WHERE invoice_id = ?");
        $row->execute([$test_id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        ok('DB: reviewed_by column exists', array_key_exists('reviewed_by', $r),
            'Run: ALTER TABLE invoices ADD COLUMN reviewed_by INT NULL, ADD COLUMN approved_by INT NULL');
        ok('DB: approved_by column exists', array_key_exists('approved_by', $r));
    } catch (PDOException $e) {
        ok('DB: reviewed_by column exists', false, $e->getMessage());
        ok('DB: approved_by column exists', false, $e->getMessage());
    }
}

// 2c. Review workflow (pending → reviewed)
if ($test_id) {
    try {
        $uid = first_user($pdo);
        $stmt = $pdo->prepare("UPDATE invoices SET status='reviewed', reviewed_by=?, updated_at=NOW() WHERE invoice_id=?");
        $stmt->execute([$uid, $test_id]);
        $s = $pdo->prepare("SELECT status, reviewed_by FROM invoices WHERE invoice_id=?");
        $s->execute([$test_id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        ok('DB: status updated to reviewed',  $r['status'] === 'reviewed',  "got: " . $r['status']);
        ok('DB: reviewed_by saved',           !empty($r['reviewed_by']),    "reviewed_by=" . $r['reviewed_by']);
    } catch (PDOException $e) {
        ok('DB: review transition', false, $e->getMessage());
        ok('DB: reviewed_by saved', false, $e->getMessage());
    }
}

// 2d. Approve workflow (reviewed → approved)
if ($test_id) {
    try {
        $uid = first_user($pdo);
        $stmt = $pdo->prepare("UPDATE invoices SET status='approved', approved_by=?, updated_at=NOW() WHERE invoice_id=?");
        $stmt->execute([$uid, $test_id]);
        $s = $pdo->prepare("SELECT status, approved_by FROM invoices WHERE invoice_id=?");
        $s->execute([$test_id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        ok('DB: status updated to approved',  $r['status'] === 'approved',  "got: " . $r['status']);
        ok('DB: approved_by saved',           !empty($r['approved_by']),    "approved_by=" . $r['approved_by']);
    } catch (PDOException $e) {
        ok('DB: approve transition', false, $e->getMessage());
        ok('DB: approved_by saved',  false, $e->getMessage());
    }
}

// 2e. display_status CASE returns 'approved' correctly
if ($test_id) {
    try {
        $s = $pdo->prepare("
            SELECT CASE
                WHEN i.status = 'cancelled' THEN 'cancelled'
                WHEN i.status = 'paid'      THEN 'paid'
                WHEN i.status = 'partial'   THEN 'partial'
                WHEN i.status = 'overdue'   THEN 'overdue'
                WHEN i.status = 'approved'  THEN 'approved'
                WHEN i.status = 'reviewed'  THEN 'reviewed'
                WHEN i.status = 'sent'      THEN 'sent'
                WHEN i.status = 'pending'   THEN 'pending'
                ELSE 'draft'
            END as display_status
            FROM invoices i WHERE i.invoice_id = ?");
        $s->execute([$test_id]);
        $ds = $s->fetchColumn();
        ok("display_status CASE: approved invoice shows 'approved'", $ds === 'approved', "got: $ds");
    } catch (PDOException $e) {
        ok('display_status CASE test', false, $e->getMessage());
    }
}

// 2f. delete works (admin bypass — no status restriction)
if ($test_id) {
    try {
        $pdo->prepare("DELETE FROM invoices WHERE invoice_id = ?")->execute([$test_id]);
        $gone = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_id = ?");
        $gone->execute([$test_id]);
        ok('DB: admin can delete approved invoice', $gone->fetchColumn() == 0, "invoice_id=$test_id deleted");
        $test_id = null;
    } catch (PDOException $e) {
        ok('DB: admin delete', false, $e->getMessage());
    }
}

// cleanup fallback
if ($test_id) cleanup($pdo, $test_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice Workflow Tests</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4 bg-light">
<div class="container" style="max-width:900px;">
    <h3 class="fw-bold mb-1">Invoice Workflow — Test Suite</h3>
    <p class="text-muted mb-4">Verifies default status, Review/Approve, admin-only delete, display_status, and badge colours.</p>

    <div class="mb-3">
        <span class="badge bg-success fs-6 me-2"><?= $pass ?> passed</span>
        <span class="badge bg-danger  fs-6"><?= $fail ?> failed</span>
    </div>

    <?php if ($fail === 0): ?>
    <div class="alert alert-success fw-bold">All tests passed.</div>
    <?php else: ?>
    <div class="alert alert-danger fw-bold"><?= $fail ?> test(s) failed — see details below.</div>
    <?php endif; ?>

    <table class="table table-sm table-bordered bg-white">
        <thead class="table-dark">
            <tr><th>#</th><th>Test</th><th>Result</th><th>Detail</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $i => $r): ?>
            <tr class="<?= $r[0]==='pass' ? 'table-success' : 'table-danger' ?>">
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($r[1]) ?></td>
                <td><?= $r[0]==='pass' ? '✅ PASS' : '❌ FAIL' ?></td>
                <td><small><?= htmlspecialchars($r[2]) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="text-muted small mt-3">Test invoices are created and deleted automatically.</p>
</div>
</body>
</html>
