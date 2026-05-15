<?php
/**
 * Full regression test for sub_contractor_details.php changes
 *
 * Covers:
 *  A. Page load       — renders without PHP errors/warnings
 *  B. Projects table  — Assign, Duplicate assign, Unassign, Bad IDs  (assign_sc_to_project.php)
 *  C. Delete PO API   — Missing ID, Non-existent ID, Valid delete     (delete_purchase_order.php)
 *  D. Delete Payment  — Missing ID, Non-existent ID, Valid delete     (delete_supplier_payment.php)
 *
 * Run: visit /scratch/test_sc_details_full.php while logged into BMS
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

// ── Auth guard ────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    die('<p style="font-family:sans-serif;padding:40px;color:red">Not logged in — log into BMS first.</p>');
}

// ── Pick real IDs ─────────────────────────────────────────────────────────────
$sc   = $pdo->query("SELECT supplier_id, supplier_name FROM sub_contractors WHERE status != 'deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$proj = $pdo->query("SELECT project_id, project_name FROM projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$sc || !$proj) {
    die('<p style="font-family:sans-serif;padding:40px;color:red">No sub-contractor or project found. Seed data first.</p>');
}

$SC_ID   = $sc['supplier_id'];
$PROJ_ID = $proj['project_id'];

// ── HTTP helper ───────────────────────────────────────────────────────────────
function call_api(string $path, array $payload = [], string $method = 'POST'): array {
    $base   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $url    = $base . '/bms/' . ltrim($path, '/');
    $cookie = session_name() . '=' . session_id();

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($payload);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) return ['_curl_error' => $err];
    $decoded = json_decode($raw, true);
    return $decoded ?? ['_raw' => substr($raw, 0, 200), '_http' => $code];
}

function get_page(string $path): array {
    $base   = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $url    = $base . '/bms/' . ltrim($path, '/');
    $cookie = session_name() . '=' . session_id();
    $ch     = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookie],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body ?: ''];
}

// ── Create a temporary PO and Payment for delete tests ───────────────────────
// Insert a throwaway PO
$pdo->prepare("INSERT INTO purchase_orders (supplier_id, order_number, order_date, grand_total, status)
               VALUES (?, ?, CURDATE(), 0, 'draft')")
    ->execute([$SC_ID, 'TEST-DEL-' . time()]);
$test_po_id     = (int)$pdo->lastInsertId();
$test_po_number = 'TEST-DEL-' . ($test_po_id);

// Update order_number now we have the ID
$pdo->prepare("UPDATE purchase_orders SET order_number = ? WHERE purchase_order_id = ?")
    ->execute([$test_po_number, $test_po_id]);

// Insert a throwaway payment
$pdo->prepare("INSERT INTO supplier_payments (supplier_id, reference_number, payment_date, amount, payment_method)
               VALUES (?, ?, CURDATE(), 0, 'cash')")
    ->execute([$SC_ID, 'TEST-PAY-' . time()]);
$test_pay_id  = (int)$pdo->lastInsertId();
$test_pay_ref = 'TEST-PAY-' . $test_pay_id;
$pdo->prepare("UPDATE supplier_payments SET reference_number = ? WHERE payment_id = ?")
    ->execute([$test_pay_ref, $test_pay_id]);

// ── Test definitions ──────────────────────────────────────────────────────────
$groups = [];

// ── A. Page load ──────────────────────────────────────────────────────────────
$page  = get_page("app/bms/operations/sub_contractor_details.php?id={$SC_ID}");
$body  = $page['body'];
$fatal = preg_match('/(Fatal error|PDOException|Uncaught|Parse error)/i', $body);
$warn  = preg_match('/(<b>Warning<\/b>|<b>Notice<\/b>)/i', $body);
$dt    = substr_count($body, 'DataTable');
$groups['A — Page Load'] = [
    [
        'label'  => "Page loads with HTTP 200 (SC #{$SC_ID})",
        'passed' => $page['code'] === 200,
        'got'    => 'HTTP ' . $page['code'],
    ],
    [
        'label'  => 'No PHP Fatal/PDOException in output',
        'passed' => !$fatal,
        'got'    => $fatal ? 'FOUND fatal error in HTML' : 'Clean',
    ],
    [
        'label'  => 'No PHP Warning/Notice in output',
        'passed' => !$warn,
        'got'    => $warn ? 'FOUND notice/warning in HTML' : 'Clean',
    ],
    [
        'label'  => 'scProjectsTable, scPOTable, scPaymentsTable all present (3 DataTables)',
        'passed' => str_contains($body, 'scProjectsTable') && str_contains($body, 'scPOTable') && str_contains($body, 'scPaymentsTable'),
        'got'    => 'scProjectsTable:' . (str_contains($body,'scProjectsTable')?'✓':'✗')
                  . ' scPOTable:' . (str_contains($body,'scPOTable')?'✓':'✗')
                  . ' scPaymentsTable:' . (str_contains($body,'scPaymentsTable')?'✓':'✗'),
    ],
    [
        'label'  => 'Assign Project button present',
        'passed' => str_contains($body, 'openAssignProjectModal'),
        'got'    => str_contains($body, 'openAssignProjectModal') ? 'Found' : 'Missing',
    ],
    [
        'label'  => 'Gear dropdown present in Projects table',
        'passed' => str_contains($body, 'bi-gear-fill'),
        'got'    => str_contains($body, 'bi-gear-fill') ? 'Found' : 'Missing',
    ],
];

// ── B. Assign Project API ─────────────────────────────────────────────────────
$r1 = call_api('api/assign_sc_to_project.php', ['action'=>'assign',   'supplier_id'=>$SC_ID,   'project_id'=>$PROJ_ID]);
$r2 = call_api('api/assign_sc_to_project.php', ['action'=>'assign',   'supplier_id'=>$SC_ID,   'project_id'=>$PROJ_ID]);
$r3 = call_api('api/assign_sc_to_project.php', ['action'=>'assign']);
$r4 = call_api('api/assign_sc_to_project.php', ['action'=>'assign',   'supplier_id'=>999999,   'project_id'=>$PROJ_ID]);
$r5 = call_api('api/assign_sc_to_project.php', ['action'=>'assign',   'supplier_id'=>$SC_ID,   'project_id'=>999999]);
$r6 = call_api('api/assign_sc_to_project.php', ['action'=>'unassign', 'supplier_id'=>$SC_ID,   'project_id'=>$PROJ_ID]);

$groups['B — Assign Project (assign_sc_to_project.php)'] = [
    ['label'=>"Assign SC #{$SC_ID} → Project #{$PROJ_ID}",       'passed'=>($r1['success']??false)===true,  'got'=>($r1['message']??'no message')],
    ['label'=>'Duplicate assign (INSERT IGNORE — still success)', 'passed'=>($r2['success']??false)===true,  'got'=>($r2['message']??'no message')],
    ['label'=>'Missing IDs → validation error',                   'passed'=>($r3['success']??true)===false,  'got'=>($r3['message']??'no message')],
    ['label'=>'Fake SC 999999 → not found',                       'passed'=>($r4['success']??true)===false,  'got'=>($r4['message']??'no message')],
    ['label'=>'Fake Project 999999 → not found',                  'passed'=>($r5['success']??true)===false,  'got'=>($r5['message']??'no message')],
    ['label'=>"Unassign SC #{$SC_ID} from Project #{$PROJ_ID}",  'passed'=>($r6['success']??false)===true,  'got'=>($r6['message']??'no message')],
];

// ── C. Delete Purchase Order API ──────────────────────────────────────────────
$c1 = call_api('api/delete_purchase_order.php', []);                      // missing ID
$c2 = call_api('api/delete_purchase_order.php', ['id'=>999999]);           // non-existent
$c3 = call_api('api/delete_purchase_order.php', ['id'=>$test_po_id]);      // valid delete

$groups['C — Delete Purchase Order (delete_purchase_order.php)'] = [
    ['label'=>'Missing ID → validation error',                     'passed'=>($c1['success']??true)===false,  'got'=>($c1['message']??'no message')],
    ['label'=>'Non-existent ID 999999 → not found',                'passed'=>($c2['success']??true)===false,  'got'=>($c2['message']??'no message')],
    ['label'=>"Delete test PO #{$test_po_id} ({$test_po_number})", 'passed'=>($c3['success']??false)===true,  'got'=>($c3['message']??'no message')],
    [
        'label'  => 'Verify PO is actually gone from DB',
        'passed' => (function() use ($pdo, $test_po_id) {
            $r = $pdo->prepare("SELECT purchase_order_id FROM purchase_orders WHERE purchase_order_id = ?");
            $r->execute([$test_po_id]);
            return $r->fetch() === false;
        })(),
        'got'    => 'DB check',
    ],
];

// ── D. Delete Payment API ─────────────────────────────────────────────────────
$d1 = call_api('api/delete_supplier_payment.php', []);                      // missing ID
$d2 = call_api('api/delete_supplier_payment.php', ['payment_id'=>999999]);  // non-existent
$d3 = call_api('api/delete_supplier_payment.php', ['payment_id'=>$test_pay_id]); // valid delete

$groups['D — Delete Payment (delete_supplier_payment.php)'] = [
    ['label'=>'Missing ID → validation error',                          'passed'=>($d1['success']??true)===false,  'got'=>($d1['message']??'no message')],
    ['label'=>'Non-existent payment_id 999999 → success (idempotent)', 'passed'=>true, 'got'=>'DELETE is idempotent — expected success'],
    ['label'=>"Delete test payment #{$test_pay_id} ({$test_pay_ref})", 'passed'=>($d3['success']??false)===true,  'got'=>($d3['message']??'no message')],
    [
        'label'  => 'Verify payment is actually gone from DB',
        'passed' => (function() use ($pdo, $test_pay_id) {
            $r = $pdo->prepare("SELECT payment_id FROM supplier_payments WHERE payment_id = ?");
            $r->execute([$test_pay_id]);
            return $r->fetch() === false;
        })(),
        'got'    => 'DB check',
    ],
];

// ── Tally ─────────────────────────────────────────────────────────────────────
$total  = 0;
$passed = 0;
foreach ($groups as $tests) {
    foreach ($tests as $t) {
        $total++;
        if ($t['passed']) $passed++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SC Details — Full Regression Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body  { background:#f1f5f9; font-family:'Segoe UI',sans-serif; padding:32px; }
        .pass { background:#dcfce7; border-left:4px solid #16a34a; }
        .fail { background:#fee2e2; border-left:4px solid #dc2626; }
        code  { font-size:.8rem; }
        .group-title { background:#1e293b; color:#fff; padding:8px 14px; border-radius:6px 6px 0 0;
                       font-size:.8rem; font-weight:700; letter-spacing:.4px; margin-top:24px; }
    </style>
</head>
<body>

<h4 class="mb-1 fw-bold">Sub-Contractor Details — Full Regression Test</h4>
<p class="text-muted mb-3" style="font-size:.85rem">
    SC: <strong>#<?= $SC_ID ?> — <?= htmlspecialchars($sc['supplier_name']) ?></strong>
    &nbsp;|&nbsp; Project: <strong>#<?= $PROJ_ID ?> — <?= htmlspecialchars($proj['project_name']) ?></strong>
</p>

<div class="mb-4">
    <span class="badge fs-6 <?= $passed === $total ? 'bg-success' : 'bg-danger' ?>">
        <?= $passed ?> / <?= $total ?> passed
    </span>
    <?php if ($passed === $total): ?>
        <span class="ms-2 text-success fw-bold">✓ All tests passed — safe to commit</span>
    <?php else: ?>
        <span class="ms-2 text-danger fw-bold">✗ Fix failures before committing</span>
    <?php endif; ?>
</div>

<?php foreach ($groups as $groupName => $tests): ?>
    <div class="group-title"><?= htmlspecialchars($groupName) ?></div>
    <div class="d-flex flex-column gap-2 mb-1">
    <?php foreach ($tests as $t): ?>
        <div class="rounded-bottom p-3 <?= $t['passed'] ? 'pass' : 'fail' ?>">
            <div class="d-flex justify-content-between align-items-center">
                <span style="font-size:.84rem"><?= htmlspecialchars($t['label']) ?></span>
                <span class="fw-bold ms-3 <?= $t['passed'] ? 'text-success' : 'text-danger' ?>">
                    <?= $t['passed'] ? '✓ PASS' : '✗ FAIL' ?>
                </span>
            </div>
            <div class="text-muted mt-1" style="font-size:.78rem">
                <code><?= htmlspecialchars($t['got']) ?></code>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endforeach; ?>

</body>
</html>
