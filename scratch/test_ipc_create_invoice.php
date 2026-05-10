<?php
/**
 * Test: IPC → Create Invoice Flow
 * Tests: redirect URL building, get_ipc proj_customer_id join,
 *        invoice_create.php IPC param handling, approved_ipcs query,
 *        applyIpcData JS presence, and form field autofill logic.
 * URL: http://localhost/bms/scratch/test_ipc_create_invoice.php
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

// ── helpers ────────────────────────────────────────────────────
function get_first_user($pdo) {
    return $pdo->query("SELECT user_id, first_name, last_name FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
function get_first_project($pdo) {
    return $pdo->query("SELECT project_id, customer_id FROM projects LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

// ── 1. get_ipc.php includes proj_customer_id ──────────────────
$get_ipc_src = file_get_contents(__DIR__ . '/../api/operations/get_ipc.php');
ok('get_ipc joins projects table',
    strpos($get_ipc_src, 'JOIN projects') !== false,
    'Must LEFT JOIN projects to get proj_customer_id');
ok('get_ipc selects proj_customer_id',
    strpos($get_ipc_src, 'proj_customer_id') !== false,
    'p.customer_id AS proj_customer_id must be in SELECT');

// ── 2. project_view.php — ipcCreateInvoice redirects ──────────
$pv = file_get_contents(__DIR__ . '/../app/bms/operations/project_view.php');
ok('ipcCreateInvoice uses getJSON (not POST)',
    strpos($pv, 'function ipcCreateInvoice') !== false &&
    strpos($pv, "$.getJSON") !== false,
    'Should fetch IPC data then redirect');
ok('ipcCreateInvoice builds invoice_create URL',
    strpos($pv, "'/invoice_create?ipc_id='") !== false,
    'URL must include ipc_id param');
ok('ipcCreateInvoice appends project param',
    strpos($pv, "'&project='") !== false);
ok('ipcCreateInvoice appends customer param',
    strpos($pv, "'&customer='") !== false);
ok('ipcCreateInvoice uses proj_customer_id fallback',
    strpos($pv, 'proj_customer_id') !== false,
    'Falls back to project customer if no SO customer');
ok('ipcCreateInvoice does window.location.href redirect',
    strpos($pv, 'window.location.href = url') !== false);

// ── 3. invoice_create.php — PHP param handling ─────────────────
$ic = file_get_contents(__DIR__ . '/../app/bms/invoice/invoice_create.php');
ok('invoice_create reads ipc_id GET param',
    strpos($ic, "\$ipc_id") !== false && strpos($ic, "'ipc_id'") !== false);
ok('invoice_create fetches IPC prefill data',
    strpos($ic, "\$ipc_prefill") !== false,
    '$ipc_prefill should be fetched when ipc_id > 0');
ok('invoice_create only prefills Approved IPCs',
    strpos($ic, "status = 'Approved'") !== false,
    'Query must filter status = Approved');
ok('invoice_create fetches approved_ipcs for dropdown',
    strpos($ic, "\$approved_ipcs") !== false,
    'List of all approved uninvoiced IPCs for dropdown');
ok('approved_ipcs filters uninvoiced (invoice_id IS NULL)',
    strpos($ic, 'invoice_id IS NULL') !== false || strpos($ic, 'invoice_id = 0') !== false);

// ── 4. invoice_create.php — HTML IPC dropdown ──────────────────
ok('IPC dropdown renders in form',
    strpos($ic, 'id="ipc_select"') !== false,
    'Select element with id=ipc_select must exist');
ok('IPC dropdown calls loadIpcData on change',
    strpos($ic, 'loadIpcData(this.value)') !== false);
ok('IPC option pre-selected when ipc_id in URL',
    strpos($ic, "\$ipc_id > 0 && \$ipc['ipc_id'] == \$ipc_id") !== false ||
    strpos($ic, 'ipc_id > 0 && $ipc[') !== false);
ok('IPC option stores data-project attribute',
    strpos($ic, "data-project=") !== false,
    'Needed to pre-select project dropdown');
ok('IPC option stores data-customer attribute',
    strpos($ic, "data-customer=") !== false,
    'Needed to pre-select customer dropdown');

// ── 5. invoice_create.php — JS autofill functions ──────────────
ok('applyIpcData function defined',
    strpos($ic, 'function applyIpcData') !== false);
ok('applyIpcData fills items from items_json',
    strpos($ic, 'items_json') !== false && strpos($ic, 'addItemRow') !== false);
ok('applyIpcData fills notes field',
    strpos($ic, "data.notes") !== false);
ok('applyIpcData fills invoice_date',
    strpos($ic, "data.ipc_date") !== false || strpos($ic, "ipc_date") !== false);
ok('applyIpcData calls updateDueDate after setting date',
    strpos($ic, 'updateDueDate()') !== false);
ok('loadIpcData function defined',
    strpos($ic, 'function loadIpcData') !== false);
ok('loadIpcData pre-selects project from data attribute',
    strpos($ic, "data('project')") !== false || strpos($ic, "data-project") !== false);
ok('loadIpcData pre-selects customer from data attribute',
    strpos($ic, "data('customer')") !== false || strpos($ic, "data-customer") !== false);
ok('loadIpcData calls applyIpcData after fetch',
    strpos($ic, 'applyIpcData(res.data)') !== false);
ok('PHP ipc_prefill passed to JS on page load',
    strpos($ic, 'applyIpcData(<?= json_encode') !== false ||
    strpos($ic, 'if ($ipc_prefill)') !== false,
    'PHP block must emit JS auto-prefill when ipc_id in URL');

// ── 6. DB: live query tests ────────────────────────────────────
$user    = get_first_user($pdo);
$project = get_first_project($pdo);
$test_ipc_id = null;

if ($user && $project) {
    // Create a test Approved IPC
    try {
        $items_json = json_encode([[
            'product_name' => 'IPC Invoice Test Item',
            'quantity'     => 2,
            'unit'         => 'M2',
            'unit_price'   => 250000,
            'tax_percent'  => 18,
            'tax_amount'   => 90000,
            'total'        => 590000,
        ]]);
        $ins = $pdo->prepare("INSERT INTO interim_payment_certificates
            (project_id, ipc_number, ipc_date, certified_amount, net_payable, status, created_by, items_json, notes)
            VALUES (?, ?, CURDATE(), 590000, 590000, 'Approved', ?, ?, ?)");
        $ins->execute([$project['project_id'], 'IPC-INV-TEST-' . time(), $user['user_id'], $items_json, 'Test note for invoice prefill']);
        $test_ipc_id = $pdo->lastInsertId();
        ok('Test Approved IPC created', $test_ipc_id > 0, "ipc_id=$test_ipc_id");
    } catch (PDOException $e) {
        ok('Test Approved IPC created', false, $e->getMessage());
    }
}

// Test get_ipc.php query returns proj_customer_id
if ($test_ipc_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT ipc.*, i.invoice_number, so.order_number, so.customer_id AS so_customer_id,
                p.customer_id AS proj_customer_id
            FROM interim_payment_certificates ipc
            LEFT JOIN invoices i ON ipc.invoice_id = i.invoice_id
            LEFT JOIN sales_orders so ON ipc.sales_order_id = so.sales_order_id
            LEFT JOIN projects p ON ipc.project_id = p.project_id
            WHERE ipc.ipc_id = ?
        ");
        $stmt->execute([$test_ipc_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        ok('get_ipc query returns proj_customer_id',
            array_key_exists('proj_customer_id', $row),
            "proj_customer_id=" . ($row['proj_customer_id'] ?? 'NULL'));
        ok('get_ipc proj_customer_id matches project',
            intval($row['proj_customer_id']) === intval($project['customer_id']),
            "got={$row['proj_customer_id']} expected={$project['customer_id']}");
    } catch (PDOException $e) {
        ok('get_ipc query returns proj_customer_id', false, $e->getMessage());
        ok('get_ipc proj_customer_id matches project', false, $e->getMessage());
    }
}

// Test approved_ipcs query returns only Approved uninvoiced IPCs
if ($test_ipc_id) {
    try {
        $rows = $pdo->query("
            SELECT ipc.ipc_id, ipc.ipc_number, ipc.status, ipc.invoice_id,
                   p.project_name, p.customer_id AS proj_customer_id
            FROM interim_payment_certificates ipc
            LEFT JOIN projects p ON ipc.project_id = p.project_id
            WHERE ipc.status = 'Approved' AND (ipc.invoice_id IS NULL OR ipc.invoice_id = 0)
            ORDER BY ipc.ipc_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $found = array_filter($rows, fn($r) => $r['ipc_id'] == $test_ipc_id);
        ok('approved_ipcs query finds test IPC',   count($found) > 0, "found " . count($rows) . " approved uninvoiced IPCs");
        ok('approved_ipcs all have status Approved', array_reduce($rows, fn($c, $r) => $c && $r['status'] === 'Approved', true));
        ok('approved_ipcs all have no invoice',      array_reduce($rows, fn($c, $r) => $c && empty($r['invoice_id']), true));
    } catch (PDOException $e) {
        ok('approved_ipcs query finds test IPC',     false, $e->getMessage());
        ok('approved_ipcs all have status Approved', false, $e->getMessage());
        ok('approved_ipcs all have no invoice',      false, $e->getMessage());
    }
}

// Test items_json is readable for prefill
if ($test_ipc_id) {
    try {
        $row = $pdo->prepare("SELECT items_json, notes, ipc_date FROM interim_payment_certificates WHERE ipc_id = ? AND status = 'Approved'");
        $row->execute([$test_ipc_id]);
        $ipc = $row->fetch(PDO::FETCH_ASSOC);
        $items = json_decode($ipc['items_json'] ?? '[]', true) ?: [];
        ok('items_json is valid JSON array',    is_array($items) && count($items) > 0, count($items) . " items");
        ok('items have product_name',           !empty($items[0]['product_name']),  $items[0]['product_name'] ?? '');
        ok('items have unit_price',             isset($items[0]['unit_price']),      $items[0]['unit_price'] ?? '');
        ok('items have tax_percent',            isset($items[0]['tax_percent']),     $items[0]['tax_percent'] ?? '');
        ok('notes field is readable',           !empty($ipc['notes']),              $ipc['notes']);
        ok('ipc_date field is readable',        !empty($ipc['ipc_date']),           $ipc['ipc_date']);
    } catch (PDOException $e) {
        foreach (['items_json valid','product_name','unit_price','tax_percent','notes','ipc_date'] as $t)
            ok($t, false, $e->getMessage());
    }
}

// ── CLEANUP ────────────────────────────────────────────────────
if ($test_ipc_id) {
    $pdo->prepare("DELETE FROM interim_payment_certificates WHERE ipc_id = ?")->execute([$test_ipc_id]);
}

// ── RENDER ─────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>IPC → Create Invoice Tests</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4 bg-light">
<div class="container" style="max-width:860px;">
    <h3 class="fw-bold mb-1">IPC → Create Invoice — Test Suite</h3>
    <p class="text-muted mb-4">Tests redirect flow, URL params, IPC dropdown, and JS autofill in invoice_create.php.</p>

    <div class="mb-3">
        <span class="badge bg-success fs-6 me-2"><?= $pass ?> passed</span>
        <span class="badge bg-danger fs-6"><?= $fail ?> failed</span>
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

    <p class="text-muted small mt-3">Test IPC was created and deleted automatically. No permanent data was modified.</p>
</div>
</body>
</html>
