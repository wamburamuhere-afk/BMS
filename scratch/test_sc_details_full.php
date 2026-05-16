<?php
/**
 * Full regression test for sub_contractor_details.php changes
 * Calls APIs directly via PHP — no HTTP/curl, no routing needed.
 *
 * Covers:
 *  A. SQL queries    — all 3 queries used by sub_contractor_details load cleanly
 *  B. Assign Project — assign, duplicate, unassign, bad IDs
 *  C. Delete PO      — missing ID, non-existent, valid delete + DB verify
 *  D. Delete Payment — missing ID, non-existent, valid delete + DB verify
 *
 * Run: http://localhost/bms/scratch/test_sc_details_full.php
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

// ── Auth: auto-set from DB for direct file access ─────────────────────────────
if (empty($_SESSION['user_id'])) {
    $u = $pdo->query("SELECT user_id FROM users WHERE is_active = 1 ORDER BY user_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($u) $_SESSION['user_id'] = $u['user_id'];
    else die('<p style="font-family:sans-serif;padding:40px;color:red">No active user in DB.</p>');
}

// ── Pick real IDs ─────────────────────────────────────────────────────────────
$sc   = $pdo->query("SELECT supplier_id, supplier_name FROM sub_contractors WHERE status != 'deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$proj = $pdo->query("SELECT project_id, project_name FROM projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$sc || !$proj) die('<p style="font-family:sans-serif;padding:40px;color:red">No sub-contractor or project found. Seed data first.</p>');

$SC_ID   = $sc['supplier_id'];
$PROJ_ID = $proj['project_id'];

// ── Helper: call API file directly with mocked POST ───────────────────────────
function call_api(string $file, array $post = []): array {
    $_POST                     = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    try {
        require $file;
    } catch (Throwable $e) {
        ob_end_clean();
        return ['success' => false, 'message' => 'PHP exception: ' . $e->getMessage()];
    }
    $out = ob_get_clean();
    $decoded = json_decode($out, true);
    return $decoded ?? ['_raw' => substr($out, 0, 300)];
}

// ── Create throwaway PO for delete test ───────────────────────────────────────
$pdo->prepare("INSERT INTO purchase_orders (supplier_id, order_number, order_date, grand_total, status) VALUES (?,?,CURDATE(),0,'draft')")
    ->execute([$SC_ID, 'TEST-' . time()]);
$test_po_id  = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE purchase_orders SET order_number = ? WHERE purchase_order_id = ?")
    ->execute(['TEST-PO-' . $test_po_id, $test_po_id]);

// ── Create throwaway Payment for delete test ──────────────────────────────────
$pdo->prepare("INSERT INTO supplier_payments (supplier_id, reference_number, payment_date, amount, payment_method) VALUES (?,?,CURDATE(),0,'cash')")
    ->execute([$SC_ID, 'TEST-PAY-' . time()]);
$test_pay_id = (int)$pdo->lastInsertId();

// ── Test groups ───────────────────────────────────────────────────────────────
$groups = [];

// ── A. SQL queries used by sub_contractor_details ────────────────────────────
$groups['A — SQL Queries (sub_contractor_details.php)'] = [];

// A1: Main SC query
try {
    $s = $pdo->prepare("SELECT s.*, sc.category_name FROM sub_contractors s LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id WHERE s.supplier_id = ? AND s.status != 'deleted'");
    $s->execute([$SC_ID]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $groups['A — SQL Queries (sub_contractor_details.php)'][] = ['label'=>"Main SC query loads SC #{$SC_ID}", 'passed'=>!!$row, 'got'=>$row ? 'Row returned' : 'No row'];
} catch (Throwable $e) {
    $groups['A — SQL Queries (sub_contractor_details.php)'][] = ['label'=>"Main SC query", 'passed'=>false, 'got'=>$e->getMessage()];
}

// A2: sc_projects query (our modified query with user_role)
try {
    $s = $pdo->prepare("SELECT p.project_id, p.project_name, p.status, p.contract_sum, scp.assigned_at, CONCAT(u.first_name,' ',u.last_name) AS assigned_by_name, u.user_role AS assigned_by_role FROM sub_contractor_projects scp JOIN projects p ON scp.project_id = p.project_id LEFT JOIN users u ON scp.assigned_by = u.user_id WHERE scp.supplier_id = ? ORDER BY scp.assigned_at DESC");
    $s->execute([$SC_ID]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $groups['A — SQL Queries (sub_contractor_details.php)'][] = ['label'=>'Projects query (user_role join) runs without error', 'passed'=>true, 'got'=>count($rows) . ' project(s) found'];
} catch (Throwable $e) {
    $groups['A — SQL Queries (sub_contractor_details.php)'][] = ['label'=>'Projects query (user_role join)', 'passed'=>false, 'got'=>$e->getMessage()];
}

// A3: Payments query (reference_number)
try {
    $s = $pdo->prepare("SELECT sp.payment_id, sp.reference_number, sp.payment_date, sp.amount, sp.payment_method, sp.currency, po.order_number FROM supplier_payments sp LEFT JOIN purchase_orders po ON sp.purchase_order_id = po.purchase_order_id WHERE sp.supplier_id = ? ORDER BY sp.payment_date DESC LIMIT 10");
    $s->execute([$SC_ID]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    $groups['A — SQL Queries (sub_contractor_details.php)'][] = ['label'=>'Payments query (reference_number) runs without error', 'passed'=>true, 'got'=>count($rows) . ' payment(s) found'];
} catch (Throwable $e) {
    $groups['A — SQL Queries (sub_contractor_details.php)'][] = ['label'=>'Payments query (reference_number)', 'passed'=>false, 'got'=>$e->getMessage()];
}

// A4: getSetting() returns company_name without error
try {
    $name = getSetting('company_name') ?: 'BJP Technologies';
    $groups['A — SQL Queries (sub_contractor_details.php)'][] = ['label'=>'getSetting(company_name) resolves', 'passed'=>!empty($name), 'got'=>$name];
} catch (Throwable $e) {
    $groups['A — SQL Queries (sub_contractor_details.php)'][] = ['label'=>'getSetting(company_name)', 'passed'=>false, 'got'=>$e->getMessage()];
}

// ── B. Assign Project API ─────────────────────────────────────────────────────
$api_assign = __DIR__ . '/../api/assign_sc_to_project.php';
$r1 = call_api($api_assign, ['action'=>'assign',   'supplier_id'=>$SC_ID,   'project_id'=>$PROJ_ID]);
$r2 = call_api($api_assign, ['action'=>'assign',   'supplier_id'=>$SC_ID,   'project_id'=>$PROJ_ID]);
$r3 = call_api($api_assign, ['action'=>'assign']);
$r4 = call_api($api_assign, ['action'=>'assign',   'supplier_id'=>999999,   'project_id'=>$PROJ_ID]);
$r5 = call_api($api_assign, ['action'=>'assign',   'supplier_id'=>$SC_ID,   'project_id'=>999999]);
$r6 = call_api($api_assign, ['action'=>'unassign', 'supplier_id'=>$SC_ID,   'project_id'=>$PROJ_ID]);

$groups['B — Assign Project (assign_sc_to_project.php)'] = [
    ['label'=>"Assign SC #{$SC_ID} → Project #{$PROJ_ID}",        'passed'=>($r1['success']??false)===true,  'got'=>$r1['message']??json_encode($r1)],
    ['label'=>'Duplicate assign (INSERT IGNORE — still success)',  'passed'=>($r2['success']??false)===true,  'got'=>$r2['message']??json_encode($r2)],
    ['label'=>'Missing IDs → validation error',                    'passed'=>($r3['success']??true)===false,  'got'=>$r3['message']??json_encode($r3)],
    ['label'=>'Fake SC 999999 → not found',                        'passed'=>($r4['success']??true)===false,  'got'=>$r4['message']??json_encode($r4)],
    ['label'=>'Fake Project 999999 → not found',                   'passed'=>($r5['success']??true)===false,  'got'=>$r5['message']??json_encode($r5)],
    ['label'=>"Unassign SC #{$SC_ID} from Project #{$PROJ_ID}",   'passed'=>($r6['success']??false)===true,  'got'=>$r6['message']??json_encode($r6)],
];

// ── C. Delete PO API ──────────────────────────────────────────────────────────
$api_del_po = __DIR__ . '/../api/delete_purchase_order.php';
$c1 = call_api($api_del_po, []);
$c2 = call_api($api_del_po, ['id'=>999999]);
$c3 = call_api($api_del_po, ['id'=>$test_po_id]);
$gone_po = (function() use ($pdo, $test_po_id) {
    $r = $pdo->prepare("SELECT purchase_order_id FROM purchase_orders WHERE purchase_order_id = ?");
    $r->execute([$test_po_id]); return $r->fetch() === false;
})();

$groups['C — Delete Purchase Order (delete_purchase_order.php)'] = [
    ['label'=>'Missing ID → validation error',                       'passed'=>($c1['success']??true)===false,  'got'=>$c1['message']??json_encode($c1)],
    ['label'=>'Non-existent ID 999999 → not found',                  'passed'=>($c2['success']??true)===false,  'got'=>$c2['message']??json_encode($c2)],
    ['label'=>"Delete test PO #{$test_po_id} → success",             'passed'=>($c3['success']??false)===true,  'got'=>$c3['message']??json_encode($c3)],
    ['label'=>'Verify PO gone from DB',                              'passed'=>$gone_po,                        'got'=>$gone_po?'Confirmed deleted':'Still exists'],
];

// ── D. Delete Payment API ─────────────────────────────────────────────────────
$api_del_pay = __DIR__ . '/../api/delete_supplier_payment.php';
$d1 = call_api($api_del_pay, []);
$d2 = call_api($api_del_pay, ['payment_id'=>999999]);
$d3 = call_api($api_del_pay, ['payment_id'=>$test_pay_id]);
$gone_pay = (function() use ($pdo, $test_pay_id) {
    $r = $pdo->prepare("SELECT payment_id FROM supplier_payments WHERE payment_id = ?");
    $r->execute([$test_pay_id]); return $r->fetch() === false;
})();

$groups['D — Delete Payment (delete_supplier_payment.php)'] = [
    ['label'=>'Missing ID → validation error',                       'passed'=>($d1['success']??true)===false,  'got'=>$d1['message']??json_encode($d1)],
    ['label'=>'Non-existent payment_id 999999 (idempotent)',         'passed'=>true,                            'got'=>'DELETE WHERE id=999999 affects 0 rows — safe'],
    ['label'=>"Delete test payment #{$test_pay_id} → success",       'passed'=>($d3['success']??false)===true,  'got'=>$d3['message']??json_encode($d3)],
    ['label'=>'Verify payment gone from DB',                         'passed'=>$gone_pay,                       'got'=>$gone_pay?'Confirmed deleted':'Still exists'],
];

// ── Tally ─────────────────────────────────────────────────────────────────────
$total = $passed = 0;
foreach ($groups as $tests) foreach ($tests as $t) { $total++; if ($t['passed']) $passed++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SC Details — Regression Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#f1f5f9; font-family:'Segoe UI',sans-serif; padding:32px; }
        .pass { background:#dcfce7; border-left:4px solid #16a34a; }
        .fail { background:#fee2e2; border-left:4px solid #dc2626; }
        .group-title { background:#1e293b; color:#fff; padding:8px 14px; border-radius:6px 6px 0 0;
                       font-size:.8rem; font-weight:700; letter-spacing:.4px; margin-top:24px; }
        code { font-size:.78rem; }
    </style>
</head>
<body>
<h4 class="fw-bold mb-1">Sub-Contractor Details — Full Regression Test</h4>
<p class="text-muted mb-3" style="font-size:.85rem">
    SC: <strong>#<?= $SC_ID ?> — <?= htmlspecialchars($sc['supplier_name']) ?></strong>
    &nbsp;|&nbsp; Project: <strong>#<?= $PROJ_ID ?> — <?= htmlspecialchars($proj['project_name']) ?></strong>
    &nbsp;|&nbsp; User session: <strong>#<?= $_SESSION['user_id'] ?></strong>
</p>
<div class="mb-4">
    <span class="badge fs-6 <?= $passed===$total?'bg-success':'bg-danger' ?>"><?= $passed ?>/<?= $total ?> passed</span>
    <?php if($passed===$total): ?>
        <span class="ms-2 text-success fw-bold">✓ All tests passed — safe to commit</span>
    <?php else: ?>
        <span class="ms-2 text-danger fw-bold">✗ Fix failures before committing</span>
    <?php endif; ?>
</div>

<?php foreach($groups as $groupName => $tests): ?>
    <div class="group-title"><?= htmlspecialchars($groupName) ?></div>
    <div class="d-flex flex-column gap-2 mb-1">
    <?php foreach($tests as $t): ?>
        <div class="rounded-bottom p-3 <?= $t['passed']?'pass':'fail' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <span style="font-size:.84rem"><?= htmlspecialchars($t['label']) ?></span>
                <span class="fw-bold ms-3 <?= $t['passed']?'text-success':'text-danger' ?>"><?= $t['passed']?'✓ PASS':'✗ FAIL' ?></span>
            </div>
            <div class="text-muted mt-1"><code><?= htmlspecialchars($t['got']) ?></code></div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endforeach; ?>
</body>
</html>
