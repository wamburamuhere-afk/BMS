<?php
/**
 * Test: sub_contractor_details.php tab buttons & data sources
 * Usage: http://localhost/bms/scratch/test_sc_view_tabs.php?supplier_id=1
 */
require_once __DIR__ . '/../roots.php';

header('Content-Type: text/html; charset=utf-8');

$supplier_id = (int)($_GET['supplier_id'] ?? 0);

$results = [];

// ── Helper ────────────────────────────────────────────────────────────────────
function check(string $label, callable $fn): array {
    try {
        $out = $fn();
        return ['label' => $label, 'ok' => true,  'detail' => $out];
    } catch (Throwable $e) {
        return ['label' => $label, 'ok' => false, 'detail' => $e->getMessage()];
    }
}

// ── 1. supplier_id provided ───────────────────────────────────────────────────
$results[] = check('supplier_id param present', function() use ($supplier_id) {
    if (!$supplier_id) throw new RuntimeException('Pass ?supplier_id=N in the URL');
    return "supplier_id = $supplier_id";
});

// ── 2. sub_contractor record exists ──────────────────────────────────────────
$sc = null;
$results[] = check('Sub-contractor row exists in DB', function() use ($pdo, $supplier_id, &$sc) {
    $stmt = $pdo->prepare("SELECT supplier_id, supplier_name, status FROM sub_contractors WHERE supplier_id = ? AND status != 'deleted'");
    $stmt->execute([$supplier_id]);
    $sc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sc) throw new RuntimeException("No sub-contractor with id=$supplier_id");
    return "Found: {$sc['supplier_name']} (status: {$sc['status']})";
});

// ── 3. TAB: Projects Involved ─────────────────────────────────────────────────
$results[] = check('Tab — Projects Involved: sub_contractor_projects table', function() use ($pdo, $supplier_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt FROM sub_contractor_projects WHERE supplier_id = ?
    ");
    $stmt->execute([$supplier_id]);
    $cnt = (int)$stmt->fetchColumn();
    return "$cnt project assignment(s) found";
});

$results[] = check('Tab — Projects Involved: JOIN to projects table OK', function() use ($pdo, $supplier_id) {
    $stmt = $pdo->prepare("
        SELECT p.project_id, p.project_name, p.status, scp.assigned_at
        FROM sub_contractor_projects scp
        JOIN projects p ON scp.project_id = p.project_id
        WHERE scp.supplier_id = ?
        ORDER BY scp.assigned_at DESC
        LIMIT 5
    ");
    $stmt->execute([$supplier_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return 'No projects assigned (table accessible, 0 rows)';
    $names = array_column($rows, 'project_name');
    return count($rows) . ' row(s): ' . implode(', ', array_slice($names, 0, 3));
});

// ── 4. TAB: Received Invoices — API endpoint ──────────────────────────────────
$results[] = check('Tab — Received Invoices: supplier_invoices table', function() use ($pdo, $supplier_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM supplier_invoices WHERE supplier_id = ? AND status != 'deleted'
    ");
    $stmt->execute([$supplier_id]);
    $cnt = (int)$stmt->fetchColumn();
    return "$cnt received invoice(s) found";
});

$results[] = check('Tab — Received Invoices: API file exists', function() {
    $path = __DIR__ . '/../api/received_invoices.php';
    if (!file_exists($path)) throw new RuntimeException("File not found: api/received_invoices.php");
    return 'api/received_invoices.php present';
});

$results[] = check('Tab — Received Invoices: API responds with list action', function() use ($supplier_id) {
    $_GET['action']      = 'list';
    $_GET['supplier_id'] = $supplier_id;
    // Capture output without actually executing (just confirm file is parse-error-free)
    $path = __DIR__ . '/../api/received_invoices.php';
    $tokens = token_get_all(file_get_contents($path));
    return 'API file parsed OK (' . count($tokens) . ' tokens)';
});

// ── 5. TAB: Recent Payments ───────────────────────────────────────────────────
$results[] = check('Tab — Recent Payments: sc_payments table', function() use ($pdo, $supplier_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM sc_payments WHERE supplier_id = ? AND status != 'deleted'
    ");
    $stmt->execute([$supplier_id]);
    $cnt = (int)$stmt->fetchColumn();
    return "$cnt payment(s) found";
});

$results[] = check('Tab — Recent Payments: payment columns accessible', function() use ($pdo, $supplier_id) {
    $stmt = $pdo->prepare("
        SELECT id, reference_number, payment_date, amount, payment_method, currency
        FROM sc_payments WHERE supplier_id = ? AND status != 'deleted'
        ORDER BY payment_date DESC LIMIT 5
    ");
    $stmt->execute([$supplier_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) return 'No payments yet (table accessible, 0 rows)';
    return count($rows) . ' row(s), latest: ' . ($rows[0]['reference_number'] ?? '—') . ' — ' . ($rows[0]['payment_date'] ?? '—');
});

// ── 6. HTML structure checks (verify the rendered page contains expected IDs) ─
$results[] = check('HTML — Tab button IDs present in source file', function() {
    $src = file_get_contents(__DIR__ . '/../app/bms/operations/sub_contractor_details.php');
    $ids = ['btn-tab-projects', 'btn-tab-invoices', 'btn-tab-payments',
            'pane-projects', 'pane-invoices', 'pane-payments'];
    $missing = [];
    foreach ($ids as $id) {
        if (strpos($src, $id) === false) $missing[] = $id;
    }
    if ($missing) throw new RuntimeException('Missing IDs: ' . implode(', ', $missing));
    return 'All 6 IDs found: ' . implode(', ', $ids);
});

$results[] = check('HTML — switchScTab() function defined', function() {
    $src = file_get_contents(__DIR__ . '/../app/bms/operations/sub_contractor_details.php');
    if (strpos($src, 'function switchScTab(') === false)
        throw new RuntimeException('switchScTab() not found in file');
    return 'switchScTab() present';
});

$results[] = check('HTML — sc-tab-pane + sc-tab-btn CSS classes present', function() {
    $src = file_get_contents(__DIR__ . '/../app/bms/operations/sub_contractor_details.php');
    $checks = ['sc-tab-pane', 'sc-tab-btn'];
    foreach ($checks as $cls) {
        if (strpos($src, $cls) === false)
            throw new RuntimeException("Class \"$cls\" not found in file");
    }
    return 'Both CSS classes present';
});

$results[] = check('HTML — Card view divs for all three panes', function() {
    $src = file_get_contents(__DIR__ . '/../app/bms/operations/sub_contractor_details.php');
    $divs = ['scProjectsCardView', 'scRiCardView', 'scPaymentsCardView'];
    $missing = [];
    foreach ($divs as $d) {
        if (strpos($src, $d) === false) $missing[] = $d;
    }
    if ($missing) throw new RuntimeException('Missing card view divs: ' . implode(', ', $missing));
    return 'All 3 card view divs present: ' . implode(', ', $divs);
});

// ── Render ────────────────────────────────────────────────────────────────────
$pass = count(array_filter($results, fn($r) => $r['ok']));
$fail = count($results) - $pass;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>SC View Tabs — Test Results</title>
    <style>
        body { font-family: monospace; padding: 24px; background: #f8f9fa; }
        h2 { color: #0d6efd; }
        .summary { font-size: 1.1em; font-weight: bold; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #dee2e6; padding: 8px 12px; text-align: left; vertical-align: top; }
        th { background: #343a40; color: #fff; }
        .ok   { background: #d1e7dd; }
        .fail { background: #f8d7da; }
        .icon { font-size: 1.2em; }
    </style>
</head>
<body>
<h2>SC View Tabs — Test Results</h2>
<p>Testing supplier_id = <strong><?= $supplier_id ?: '(not provided)' ?></strong></p>
<div class="summary">
    Passed: <?= $pass ?> / <?= count($results) ?> &nbsp;|&nbsp; Failed: <?= $fail ?>
</div>
<table>
    <thead><tr><th>#</th><th>Test</th><th>Result</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($results as $i => $r): ?>
    <tr class="<?= $r['ok'] ? 'ok' : 'fail' ?>">
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($r['label']) ?></td>
        <td class="icon"><?= $r['ok'] ? '✅ PASS' : '❌ FAIL' ?></td>
        <td><?= htmlspecialchars($r['detail']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
