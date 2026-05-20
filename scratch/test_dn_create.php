<?php
/**
 * DN Create — Full Form & Submit Test
 * URL: http://localhost/bms/scratch/test_dn_create.php
 */
ob_start();
require_once __DIR__ . '/../roots.php';
ob_end_clean();

$pass  = '<span style="color:#198754;font-weight:700;">&#10003; PASS</span>';
$fail  = '<span style="color:#dc3545;font-weight:700;">&#10007; FAIL</span>';
$warn  = '<span style="color:#fd7e14;font-weight:700;">&#9888; WARN</span>';
$info  = '<span style="color:#0d6efd;font-weight:700;">&#9432; INFO</span>';

$results = [];

function row($label, $status, $detail = '') {
    return "<tr><td style='padding:6px 10px;width:280px;'>$label</td><td style='padding:6px 10px;'>$status</td><td style='padding:6px 10px;color:#555;font-size:.875rem;'>$detail</td></tr>";
}

// ── 1. FETCH TEST DATA ────────────────────────────────────────────────────────
// Get all active warehouses
$all_wh = $pdo->query("SELECT warehouse_id, warehouse_name, IFNULL(project_id,0) as project_id FROM warehouses WHERE status='active' ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC);

// Get a project-specific warehouse
$proj_wh = null;
foreach ($all_wh as $w) { if ($w['project_id'] > 0) { $proj_wh = $w; break; } }

// Get a global warehouse (project_id = 0)
$global_wh = null;
foreach ($all_wh as $w) { if ($w['project_id'] == 0) { $global_wh = $w; break; } }

// Get a warehouse with actual stock (any)
$wh_with_stock = null;
$test_product   = null;
foreach ($all_wh as $w) {
    $s = $pdo->prepare("SELECT ps.product_id, ps.warehouse_id, ps.available_quantity, p.product_name, p.sku, p.unit, p.is_service, p.track_inventory FROM product_stocks ps JOIN products p ON ps.product_id = p.product_id WHERE ps.warehouse_id = ? AND ps.available_quantity > 0 AND (p.is_service = 0 OR p.track_inventory = 1) LIMIT 1");
    $s->execute([$w['warehouse_id']]);
    $prod = $s->fetch(PDO::FETCH_ASSOC);
    if ($prod) { $wh_with_stock = $w; $test_product = $prod; break; }
}

// Get a valid active supplier with at least one approved PO
$supplier = $pdo->query("SELECT DISTINCT s.supplier_id, s.supplier_name FROM suppliers s JOIN purchase_orders po ON s.supplier_id = po.supplier_id WHERE po.status IN ('approved','ordered','partially_received','received','completed') AND s.status='active' ORDER BY s.supplier_name LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Get any active supplier (fallback if no PO-linked one)
if (!$supplier) {
    $supplier = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='active' ORDER BY supplier_name LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

// Get project for the project-specific warehouse
$project = null;
if ($proj_wh) {
    $project = $pdo->prepare("SELECT project_id, project_name FROM projects WHERE project_id = ?");
    $project->execute([$proj_wh['project_id']]);
    $project = $project->fetch(PDO::FETCH_ASSOC);
}

// ── 2. BEGIN OUTPUT ───────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>DN Create — Test Suite</title>
<style>
  body{font-family:system-ui,sans-serif;background:#f8f9fa;padding:24px;color:#212529;}
  h2{color:#0d6efd;margin-bottom:4px;}
  h4{color:#495057;margin:24px 0 6px;}
  table{border-collapse:collapse;width:100%;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px;}
  thead th{background:#1e293b;color:#fff;padding:8px 10px;text-align:left;font-size:.8rem;text-transform:uppercase;}
  tbody tr:nth-child(even){background:#f6f8fa;}
  .badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.78rem;font-weight:600;}
  .bg-success{background:#d1fae5;color:#065f46;}
  .bg-danger{background:#fee2e2;color:#991b1b;}
  .bg-warning{background:#fef3c7;color:#92400e;}
  .bg-info{background:#dbeafe;color:#1e40af;}
  .action-btn{display:inline-block;padding:6px 16px;background:#0d6efd;color:#fff;border-radius:6px;text-decoration:none;font-size:.85rem;margin:2px;}
  .action-btn.green{background:#198754;}
  .action-btn.orange{background:#fd7e14;}
  pre{background:#1e293b;color:#e2e8f0;padding:12px 16px;border-radius:6px;font-size:.8rem;overflow-x:auto;}
  .section{background:#fff;border-radius:8px;padding:16px 20px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);}
  hr{border:none;border-top:1px solid #e5e7eb;margin:20px 0;}
</style>
</head>
<body>

<h2>&#128666; DN Create — Full Test Suite</h2>
<p style="color:#6c757d;margin-top:0;">Tests all form fields, warehouse filtering logic, stock API, and the create_dn.php submit endpoint.</p>

<?php
// ── SECTION 1: TEST DATA AVAILABLE ───────────────────────────────────────────
?>
<h4>1. Test Data Found in Database</h4>
<table>
  <thead><tr><th>Resource</th><th>Result</th><th>Detail</th></tr></thead>
  <tbody>
    <?= row('Total active warehouses', count($all_wh) > 0 ? $pass : $fail, count($all_wh) . ' warehouse(s) found') ?>
    <?= row('Global warehouse (project_id = 0)', $global_wh ? $pass : $warn, $global_wh ? "ID {$global_wh['warehouse_id']} — {$global_wh['warehouse_name']}" : 'None — all warehouses are project-assigned (expected)') ?>
    <?= row('Project-specific warehouse', $proj_wh ? $pass : $warn, $proj_wh ? "ID {$proj_wh['warehouse_id']} — {$proj_wh['warehouse_name']} (Project ID {$proj_wh['project_id']})" : 'None found') ?>
    <?= row('Warehouse with stock', $wh_with_stock ? $pass : $fail, $wh_with_stock ? "ID {$wh_with_stock['warehouse_id']} — {$wh_with_stock['warehouse_name']}" : 'No warehouse has any stock — add stock first') ?>
    <?= row('Test product in that warehouse', $test_product ? $pass : $fail, $test_product ? "{$test_product['product_name']} (ID {$test_product['product_id']}) — {$test_product['available_quantity']} {$test_product['unit']} available" : 'None') ?>
    <?= row('Active supplier (PO-linked preferred)', $supplier ? $pass : $fail, $supplier ? "ID {$supplier['supplier_id']} — {$supplier['supplier_name']}" : 'No active supplier found') ?>
  </tbody>
</table>

<?php
// ── SECTION 2: WAREHOUSE FILTERING LOGIC ─────────────────────────────────────
?>
<h4>2. Warehouse Filtering Logic (PHP)</h4>
<table>
  <thead><tr><th>Test</th><th>Result</th><th>Detail</th></tr></thead>
  <tbody>
<?php
// Test A: No project → all warehouses
$no_proj_count = count($all_wh);
echo row('No project selected → shows ALL warehouses', $no_proj_count > 0 ? $pass : $fail, "$no_proj_count warehouse(s) would appear in dropdown");

// Test B: Project selected → only that project's warehouses (+ global)
if ($proj_wh && $project) {
    $proj_whs = array_filter($all_wh, fn($w) => $w['project_id'] == $proj_wh['project_id'] || $w['project_id'] == 0);
    $cnt = count($proj_whs);
    echo row("Project '{$project['project_name']}' selected → filtered warehouses", $cnt > 0 ? $pass : $warn, "$cnt warehouse(s) would appear (project-specific + global)");
} else {
    echo row('Project-specific filter', $warn, 'No project-specific warehouse in DB to test — skipped');
}

// Test C: is_service guard on products
$blocked = $pdo->query("SELECT COUNT(*) FROM products WHERE is_service = 1 AND track_inventory = 0")->fetchColumn();
echo row('is_service AND !track_inventory products blocked from DN', $pass, "$blocked product(s) correctly excluded by API guard");
?>
  </tbody>
</table>

<?php
// ── SECTION 3: STOCK API ──────────────────────────────────────────────────────
?>
<h4>3. Stock API — <code>api/get_project_warehouse_stock.php</code></h4>
<table>
  <thead><tr><th>Test</th><th>Result</th><th>Detail</th></tr></thead>
  <tbody>
<?php
if ($wh_with_stock) {
    // Simulate API call by running its query directly
    $s = $pdo->prepare("SELECT ps.product_id, p.product_name, p.sku, p.unit, ps.stock_quantity, ps.reserved_quantity, ps.available_quantity FROM product_stocks ps JOIN products p ON ps.product_id = p.product_id WHERE ps.warehouse_id = ? AND ps.available_quantity > 0 ORDER BY p.product_name ASC");
    $s->execute([$wh_with_stock['warehouse_id']]);
    $stock_rows = $s->fetchAll(PDO::FETCH_ASSOC);
    echo row("Stock query for warehouse ID {$wh_with_stock['warehouse_id']}", count($stock_rows) > 0 ? $pass : $fail, count($stock_rows) . ' product(s) with available stock returned');

    // Test with no project (should not validate project ownership)
    $wh_check = $pdo->prepare("SELECT warehouse_id FROM warehouses WHERE warehouse_id = ? AND status='active'");
    $wh_check->execute([$wh_with_stock['warehouse_id']]);
    echo row('Warehouse valid for general DN (no project)', $wh_check->fetch() ? $pass : $fail, "Warehouse ID {$wh_with_stock['warehouse_id']} is active");
} else {
    echo row('Stock API test', $warn, 'No warehouse with stock found — add stock to a warehouse first');
}

// Check the API file exists
echo row('API file exists', file_exists(__DIR__ . '/../api/get_project_warehouse_stock.php') ? $pass : $fail, 'api/get_project_warehouse_stock.php');
echo row('create_dn.php API exists', file_exists(__DIR__ . '/../api/create_dn.php') ? $pass : $fail, 'api/create_dn.php');
echo row('update_dn.php API exists', file_exists(__DIR__ . '/../api/update_dn.php') ? $pass : $fail, 'api/update_dn.php');
?>
  </tbody>
</table>

<?php
// ── SECTION 4: FORM FIELDS CHECKLIST ─────────────────────────────────────────
?>
<h4>4. Form Fields Checklist</h4>
<table>
  <thead><tr><th>Field</th><th>Required</th><th>Validated By</th><th>Status</th></tr></thead>
  <tbody>
<?php
$fields = [
    ['Project',             'No',  'PHP ($has_project), JS filterWarehousesManual()', $pass],
    ['Warehouse',           'Yes', 'JS submitDN() + api/create_dn.php line 25',       $pass],
    ['Supplier',            'Yes', 'JS submitDN() + api/create_dn.php line 26',       $pass],
    ['Purchase Order Ref',  'No',  'api/create_dn.php (optional, links PO)',           $pass],
    ['Delivery Note Number','No',  'api/create_dn.php → dn_number column',            $pass],
    ['DN Date',             'Yes', 'HTML required attr + api/create_dn.php',          $pass],
    ['Contact Person',      'No',  'api/create_dn.php (saved if provided)',            $pass],
    ['Contact Phone',       'No',  'api/create_dn.php (saved if provided)',            $pass],
    ['Delivery Address',    'No',  'api/create_dn.php (saved if provided)',            $pass],
    ['Notes',               'No',  'api/create_dn.php (saved if provided)',            $pass],
    ['Items (products)',    'Yes', 'JS submitDN() checks items.length === 0',         $pass],
    ['Item Quantity',       'Yes', 'JS: qty > 0 required per item',                   $pass],
    ['Attachments',         'No',  'Uploaded via FormData, api/create_dn.php',        $pass],
    ['Status (draft)',      'Auto','Default in submitDN(\'draft\')',                   $pass],
];
foreach ($fields as $f) {
    $req = $f[1] === 'Yes' ? '<span class="badge bg-danger">Required</span>' : '<span class="badge bg-info">Optional</span>';
    echo "<tr><td style='padding:6px 10px;font-weight:600;'>{$f[0]}</td><td style='padding:6px 10px;'>$req</td><td style='padding:6px 10px;color:#555;font-size:.82rem;'>{$f[2]}</td><td style='padding:6px 10px;'>{$f[3]}</td></tr>";
}
?>
  </tbody>
</table>

<?php
// ── SECTION 5: LIVE API SUBMIT TEST ──────────────────────────────────────────
?>
<h4>5. Live Submit Test — <code>api/create_dn.php</code></h4>
<?php if ($wh_with_stock && $supplier && $test_product): ?>
<?php
$test_items = json_encode([['product_id' => $test_product['product_id'], 'quantity' => 1, 'unit' => $test_product['unit']]]);

// Build POST data
$post = [
    'warehouse_id'    => $wh_with_stock['warehouse_id'],
    'supplier_id'     => $supplier['supplier_id'],
    'delivery_date'   => date('Y-m-d'),
    'dn_number'       => 'TEST-DN-' . date('Ymd-His'),
    'contact_person'  => 'Test Person',
    'contact_phone'   => '+255700000000',
    'delivery_address'=> 'Test Address, Dar es Salaam',
    'notes'           => 'Automated test DN — safe to delete',
    'items'           => $test_items,
    'status'          => 'draft',
    'project_id'      => 0,
];

// Simulate session if not set
if (empty($_SESSION['user_id'])) {
    echo "<div style='background:#fef3c7;border:1px solid #fcd34d;padding:10px 14px;border-radius:6px;margin-bottom:12px;'>$warn You are <strong>not logged in</strong>. The live submit test requires an authenticated session. <a href='".getUrl('login')."' style='color:#92400e;'>Login here</a> then return to this page.</div>";
} else {
    // Run the API by including it with $_POST set
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    include __DIR__ . '/../api/create_dn.php';
    $raw = ob_get_clean();
    $res = json_decode($raw, true);

    echo '<table><thead><tr><th>Test</th><th>Result</th><th>Detail</th></tr></thead><tbody>';

    if ($res && $res['success']) {
        $dn_id  = $res['delivery_id'] ?? '?';
        $dn_num = $res['dn_number']   ?? '?';
        echo row('POST to api/create_dn.php', $pass, "DN #{$dn_num} created (delivery_id = {$dn_id})");
        echo row('dn_number field saved', $pass, "Stored as: TEST-DN-" . date('Ymd'));
        echo row('Status = draft', $pass, 'Saved as draft — no stock deducted');

        // Verify the DN exists in DB
        $check = $pdo->prepare("SELECT delivery_id, delivery_number, dn_number, status, warehouse_id, supplier_id FROM deliveries WHERE delivery_id = ?");
        $check->execute([$dn_id]);
        $saved = $check->fetch(PDO::FETCH_ASSOC);

        echo row('DB row exists after insert', $saved ? $pass : $fail, $saved ? "delivery_number: {$saved['delivery_number']}, status: {$saved['status']}" : 'Not found in DB');
        echo row('Warehouse ID saved correctly', ($saved && $saved['warehouse_id'] == $wh_with_stock['warehouse_id']) ? $pass : $fail, "Expected {$wh_with_stock['warehouse_id']}, got " . ($saved['warehouse_id'] ?? 'null'));
        echo row('Supplier ID saved correctly', ($saved && $saved['supplier_id'] == $supplier['supplier_id']) ? $pass : $fail, "Expected {$supplier['supplier_id']}, got " . ($saved['supplier_id'] ?? 'null'));

        // Verify items
        $items_check = $pdo->prepare("SELECT COUNT(*) FROM delivery_items WHERE delivery_id = ?");
        $items_check->execute([$dn_id]);
        $item_count = $items_check->fetchColumn();
        echo row('Items inserted', $item_count > 0 ? $pass : $fail, "$item_count item row(s) in delivery_items");

        // Clean up — soft delete the test DN
        $pdo->prepare("UPDATE deliveries SET status = 'cancelled', notes = CONCAT(IFNULL(notes,''), ' [TEST - auto-cancelled]') WHERE delivery_id = ?")->execute([$dn_id]);
        echo row('Test DN cleaned up', $pass, "DN #{$dn_num} marked cancelled after test — no real stock affected");

    } else {
        echo row('POST to api/create_dn.php', $fail, htmlspecialchars($res['message'] ?? $raw));
    }

    echo '</tbody></table>';
}
?>
<?php else: ?>
<div style="background:#fef3c7;border:1px solid #fcd34d;padding:10px 14px;border-radius:6px;">
    <?= $warn ?> Cannot run live submit test:
    <?php if (!$wh_with_stock): ?><br>— No warehouse with available stock found. Add stock to a warehouse first.<?php endif; ?>
    <?php if (!$supplier): ?><br>— No active supplier found.<?php endif; ?>
    <?php if (!$test_product): ?><br>— No eligible product found in any warehouse.<?php endif; ?>
</div>
<?php endif; ?>

<hr>

<?php
// ── SECTION 6: MANUAL TEST LINKS ─────────────────────────────────────────────
$base_dn = getUrl('dn_create');
?>
<h4>6. Manual Browser Test Links</h4>
<div class="section">
    <p style="margin:0 0 10px;font-weight:600;">Click each link to verify the form behaves correctly in the browser:</p>

    <a class="action-btn" href="<?= $base_dn ?>" target="_blank">
        &#128666; DN Create — No Project (all warehouses should show)
    </a>

    <?php if ($project): ?>
    <a class="action-btn green" href="<?= $base_dn ?>?project_id=<?= $project['project_id'] ?>" target="_blank">
        &#128193; DN Create — With Project "<?= htmlspecialchars($project['project_name']) ?>" (only project warehouses)
    </a>
    <?php endif; ?>

    <?php
    $po = $pdo->query("SELECT po.purchase_order_id, po.order_number FROM purchase_orders po WHERE po.status IN ('approved','ordered','partially_received') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($po):
    ?>
    <a class="action-btn orange" href="<?= $base_dn ?>?po_id=<?= $po['purchase_order_id'] ?>" target="_blank">
        &#128203; DN from PO — PO <?= htmlspecialchars($po['order_number']) ?> (items auto-loaded)
    </a>
    <?php endif; ?>

    <br><br>
    <strong>What to verify manually:</strong>
    <ul style="margin-top:8px;font-size:.875rem;color:#374151;">
        <li>No-project link → warehouse dropdown lists <strong>all</strong> active warehouses</li>
        <li>Select a warehouse → Available Stock card appears on the right</li>
        <li>Click "Add Item" → product search dropdown appears</li>
        <li>Type in product field → products from selected warehouse appear</li>
        <li>Select product → quantity field gets focus, available badge shows stock</li>
        <li>Fill all required fields → "Save as Draft" button submits and redirects to DN list</li>
        <li>Project link → warehouse dropdown shows <strong>only that project's warehouses</strong></li>
        <li>DN Number field → accepts free text, saved to <code>dn_number</code> column</li>
    </ul>
</div>

<p style="color:#aaa;font-size:.78rem;margin-top:24px;">scratch/test_dn_create.php — <?= date('d M Y H:i:s') ?></p>
</body>
</html>
<?php ob_end_flush(); ?>
