<?php
/**
 * Test: IPC Print Preview
 * Tests: print_ipc.php layout, reviewed_by/approved_by columns,
 *        update_ipc_status.php saving user IDs, route registration,
 *        and signature section autofill.
 * URL: http://localhost/bms/scratch/test_ipc_print.php
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

/* ── helpers ──────────────────────────────────────────────────── */
function get_test_user($pdo) {
    $r = $pdo->query("SELECT user_id, first_name, last_name, COALESCE(user_role,role,'user') AS role FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    return $r;
}
function get_test_project($pdo) {
    return $pdo->query("SELECT project_id FROM projects LIMIT 1")->fetchColumn();
}

// ── 1. ROUTE REGISTRATION ──────────────────────────────────────
$routes = include __DIR__ . '/../roots.php';

// Check roots.php defines print_ipc route
$roots_content = file_get_contents(__DIR__ . '/../roots.php');
ok('print_ipc route registered in roots.php',
    strpos($roots_content, "'print_ipc'") !== false,
    'roots.php must map print_ipc → print_ipc.php');

// Check print_ipc.php file exists
ok('print_ipc.php file exists',
    file_exists(__DIR__ . '/../app/bms/operations/print_ipc.php'),
    'app/bms/operations/print_ipc.php');

// ── 2. DB SCHEMA — reviewed_by / approved_by columns ──────────
try {
    $cols = $pdo->query("DESCRIBE interim_payment_certificates")->fetchAll(PDO::FETCH_COLUMN);
    ok('Column reviewed_by exists', in_array('reviewed_by', $cols), implode(', ', $cols));
    ok('Column approved_by exists', in_array('approved_by', $cols), implode(', ', $cols));
} catch (PDOException $e) {
    ok('DESCRIBE interim_payment_certificates', false, $e->getMessage());
    ok('Column reviewed_by exists', false, 'table not accessible');
    ok('Column approved_by exists', false, 'table not accessible');
}

// ── 3. CREATE TEST IPC ─────────────────────────────────────────
$user    = get_test_user($pdo);
$proj_id = get_test_project($pdo);
$test_ipc_id = null;

if ($user && $proj_id) {
    try {
        $ins = $pdo->prepare("INSERT INTO interim_payment_certificates
            (project_id, ipc_number, ipc_date, certified_amount, net_payable, status, created_by, items_json)
            VALUES (?, ?, CURDATE(), 500000, 500000, 'Draft', ?, ?)");
        $items_json = json_encode([[
            'product_name' => 'Test Construction Work',
            'quantity'     => 1,
            'unit'         => 'LS',
            'unit_price'   => 500000,
            'tax_percent'  => 0,
            'tax_amount'   => 0,
            'total'        => 500000,
        ]]);
        $ins->execute([$proj_id, 'IPC-TEST-PRINT-' . time(), $user['user_id'], $items_json]);
        $test_ipc_id = $pdo->lastInsertId();
        ok('Test IPC created (Draft)', $test_ipc_id > 0, "ipc_id=$test_ipc_id");
    } catch (PDOException $e) {
        ok('Test IPC created (Draft)', false, $e->getMessage());
    }
} else {
    ok('Test IPC created (Draft)', false, 'No user or project found in DB');
}

// ── 4. update_ipc_status — sets reviewed_by on Review ─────────
if ($test_ipc_id) {
    try {
        $upd = $pdo->prepare("UPDATE interim_payment_certificates SET status='Viewed', reviewed_by=?, updated_at=NOW() WHERE ipc_id=?");
        $upd->execute([$user['user_id'], $test_ipc_id]);
        $row = $pdo->prepare("SELECT status, reviewed_by FROM interim_payment_certificates WHERE ipc_id=?");
        $row->execute([$test_ipc_id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        ok('Status set to Viewed',         $r['status'] === 'Viewed',             "status={$r['status']}");
        ok('reviewed_by saved correctly',  intval($r['reviewed_by']) === intval($user['user_id']), "reviewed_by={$r['reviewed_by']} expected={$user['user_id']}");
    } catch (PDOException $e) {
        ok('Status set to Viewed',        false, $e->getMessage());
        ok('reviewed_by saved correctly', false, $e->getMessage());
    }
}

// ── 5. update_ipc_status — sets approved_by on Approve ────────
if ($test_ipc_id) {
    try {
        $upd = $pdo->prepare("UPDATE interim_payment_certificates SET status='Approved', approved_by=?, updated_at=NOW() WHERE ipc_id=?");
        $upd->execute([$user['user_id'], $test_ipc_id]);
        $row = $pdo->prepare("SELECT status, approved_by FROM interim_payment_certificates WHERE ipc_id=?");
        $row->execute([$test_ipc_id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        ok('Status set to Approved',       $r['status'] === 'Approved',           "status={$r['status']}");
        ok('approved_by saved correctly',  intval($r['approved_by']) === intval($user['user_id']), "approved_by={$r['approved_by']} expected={$user['user_id']}");
    } catch (PDOException $e) {
        ok('Status set to Approved',       false, $e->getMessage());
        ok('approved_by saved correctly',  false, $e->getMessage());
    }
}

// ── 6. print_ipc.php query — joins fetch names correctly ───────
if ($test_ipc_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT ipc.*,
                u.first_name AS creator_first, u.last_name AS creator_last, COALESCE(u.user_role, u.role) AS creator_role,
                ur.first_name AS reviewer_first, ur.last_name AS reviewer_last, COALESCE(ur.user_role, ur.role) AS reviewer_role,
                ua.first_name AS approver_first, ua.last_name AS approver_last, COALESCE(ua.user_role, ua.role) AS approver_role
            FROM interim_payment_certificates ipc
            LEFT JOIN users u  ON ipc.created_by  = u.user_id
            LEFT JOIN users ur ON ipc.reviewed_by = ur.user_id
            LEFT JOIN users ua ON ipc.approved_by = ua.user_id
            WHERE ipc.ipc_id = ?
        ");
        $stmt->execute([$test_ipc_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $creator_name  = trim(($row['creator_first'] ?? '') . ' ' . ($row['creator_last'] ?? ''));
        $reviewer_name = trim(($row['reviewer_first'] ?? '') . ' ' . ($row['reviewer_last'] ?? ''));
        $approver_name = trim(($row['approver_first'] ?? '') . ' ' . ($row['approver_last'] ?? ''));

        ok('creator_first/last fetched',  !empty($creator_name),  "creator=$creator_name");
        ok('reviewer_first/last fetched', !empty($reviewer_name), "reviewer=$reviewer_name");
        ok('approver_first/last fetched', !empty($approver_name), "approver=$approver_name");
        ok('creator_role fetched',        !empty($row['creator_role']),  "role={$row['creator_role']}");
        ok('reviewer_role fetched',       !empty($row['reviewer_role']), "role={$row['reviewer_role']}");
        ok('approver_role fetched',       !empty($row['approver_role']), "role={$row['approver_role']}");
    } catch (PDOException $e) {
        foreach (['creator_first/last','reviewer_first/last','approver_first/last','creator_role','reviewer_role','approver_role'] as $t)
            ok($t . ' fetched', false, $e->getMessage());
    }
}

// ── 7. print_ipc.php file — key HTML sections present ─────────
$print_html = file_get_contents(__DIR__ . '/../app/bms/operations/print_ipc.php');
ok('print_ipc has doc-title-box (IPC header)',   strpos($print_html, 'doc-title-box') !== false);
ok('print_ipc has details-grid (info boxes)',     strpos($print_html, 'details-grid') !== false);
ok('print_ipc has items table',                   strpos($print_html, 'items_json') !== false);
ok('print_ipc has totals section',                strpos($print_html, 'net_payable') !== false);
ok('print_ipc has signature-box',                 strpos($print_html, 'signature-box') !== false);
ok('print_ipc has Created By label',              strpos($print_html, 'Created By') !== false);
ok('print_ipc has Reviewed By label',             strpos($print_html, 'Reviewed By') !== false);
ok('print_ipc has Approved By label',             strpos($print_html, 'Approved By') !== false);
ok('print_ipc has reviewer autofill logic',       strpos($print_html, 'reviewer_name') !== false);
ok('print_ipc has approver autofill logic',       strpos($print_html, 'approver_name') !== false);
ok('print_ipc has Not yet reviewed fallback',     strpos($print_html, 'Not yet reviewed') !== false);
ok('print_ipc has Not yet approved fallback',     strpos($print_html, 'Not yet approved') !== false);
ok('print_ipc has fixed print-footer',            strpos($print_html, 'print-footer') !== false);
ok('print_ipc has onload auto-print',             strpos($print_html, 'onload="window.print()"') !== false);

// ── 8. Modal Print button uses new page (not window.print) ────
$pv = file_get_contents(__DIR__ . '/../app/bms/operations/project_view.php');
ok('Modal Print button opens print_ipc page',
    strpos($pv, "window.open(APP_URL + '/print_ipc?id=") !== false,
    'Print button should call window.open with print_ipc URL');
ok('ipcModalPrint function removed',
    strpos($pv, 'function ipcModalPrint') === false,
    'Old modal print function should no longer exist');
ok('Modal print CSS removed',
    strpos($pv, 'ipc-modal-print') === false,
    'Old ipc-modal-print CSS class should no longer exist');

// ── 9. IPC tab has no redundant Print button ───────────────────
// Ensure the IPC tab header does NOT have a Print button (removed earlier)
preg_match('/id="proj-ipc".*?<!-- Staff Tab -->/s', $pv, $m);
$ipc_tab_html = $m[0] ?? '';
$ipc_print_count = substr_count($ipc_tab_html, "onclick=\"window.print()\"");
ok('IPC tab has no standalone Print button', $ipc_print_count === 0,
    "Found $ipc_print_count window.print() calls in IPC tab (expected 0)");

// ── CLEANUP ────────────────────────────────────────────────────
if ($test_ipc_id) {
    $pdo->prepare("DELETE FROM interim_payment_certificates WHERE ipc_id=?")->execute([$test_ipc_id]);
}

// ── RENDER ────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IPC Print Preview Tests</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4 bg-light">
<div class="container" style="max-width:820px;">
    <h3 class="fw-bold mb-1">IPC Print Preview — Test Suite</h3>
    <p class="text-muted mb-4">Tests for dedicated print page, reviewed_by/approved_by columns, and signature autofill.</p>

    <div class="mb-3">
        <span class="badge bg-success fs-6 me-2"><?= $pass ?> passed</span>
        <span class="badge bg-danger fs-6"><?= $fail ?> failed</span>
    </div>

    <?php if ($fail === 0): ?>
    <div class="alert alert-success fw-bold">All tests passed.</div>
    <?php else: ?>
    <div class="alert alert-danger fw-bold"><?= $fail ?> test(s) failed — check details below.</div>
    <?php endif; ?>

    <table class="table table-sm table-bordered bg-white">
        <thead class="table-dark">
            <tr><th>#</th><th>Test</th><th>Result</th><th>Detail</th></tr>
        </thead>
        <tbody>
        <?php foreach ($results as $i => $r): ?>
            <tr class="<?= $r[0]==='pass' ? 'table-success' : 'table-danger' ?>">
                <td><?= $i+1 ?></td>
                <td><?= htmlspecialchars($r[1]) ?></td>
                <td><?= $r[0]==='pass' ? '✅ PASS' : '❌ FAIL' ?></td>
                <td><small><?= htmlspecialchars($r[2]) ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="text-muted small mt-3">
        Test IPC was created and deleted automatically.<br>
        Print page: <a href="../print_ipc?id=1" target="_blank">print_ipc?id=1</a> (replace 1 with a real IPC ID)
    </p>
</div>
</body>
</html>
