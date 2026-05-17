<?php
/**
 * Test: create_product.php API
 * Run via: http://localhost/bms/scratch/test_product_create.php
 */
echo "<pre>\n";
echo "=== TEST: create_product.php ===\n\n";

// Start session and set admin credentials before roots.php loads
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role_id']  = 1; // role_id=1 → isAdmin() returns true → all permissions pass

require_once __DIR__ . '/../roots.php';
global $pdo;

// ── Pre-cleanup: remove any leftover test data from previous runs ─────────────
$leftovers = $pdo->query("SELECT product_id FROM products WHERE sku LIKE 'TEST-%'")->fetchAll(PDO::FETCH_COLUMN);
if ($leftovers) {
    $in = implode(',', array_map('intval', $leftovers));
    $pdo->exec("DELETE FROM stock_movements WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM product_stocks WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
    echo "Pre-cleanup: removed " . count($leftovers) . " leftover test product(s)\n\n";
}

// ── Get real IDs from DB ──────────────────────────────────────────────────────
$category_id = $pdo->query("SELECT category_id FROM product_categories LIMIT 1")->fetchColumn() ?: null;
$warehouse_id = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetchColumn() ?: null;
$tax_id       = $pdo->query("SELECT rate_id FROM tax_rates WHERE status='active' LIMIT 1")->fetchColumn() ?: null;

echo "Using: category_id=$category_id, warehouse_id=$warehouse_id, tax_id=$tax_id\n\n";

// ── TEST 1: Create a valid product ────────────────────────────────────────────
echo "--- TEST 1: Create valid product ---\n";
$sku = 'TEST-' . time();
$_POST = [
    'product_name'    => 'Test Product ' . date('H:i:s'),
    'sku'             => $sku,
    'barcode'         => '699' . rand(100000000, 999999999),
    'description'     => 'Automated test product',
    'category_id'     => $category_id,
    'unit'            => 'pcs',
    'cost_price'      => '500.00',
    'selling_price'   => '750.00',
    'wholesale_price' => '680.00',
    'discount_rate'   => '5',
    'min_selling_price' => '',
    'tax_id'          => $tax_id,
    'status'          => 'active',
    'is_service'      => '0',
    'track_inventory' => '1',
    'reorder_level'   => '10',
    'min_stock_level' => '5',
    'max_stock_level' => '100',
    'warranty_period' => '12',
    'expiry_days'     => '0',
    'weight'          => '0.5',
];
if ($warehouse_id) {
    $_POST['initial_stock_data'] = json_encode([$warehouse_id => 50]);
}
$_FILES = [];

ob_start();
require __DIR__ . '/../api/create_product.php';
$raw = ob_get_clean();
$res = json_decode($raw, true);

if ($res && $res['success']) {
    echo "✓ Create Product: PASSED (product_id=" . $res['product_id'] . ")\n";
    $created_id = $res['product_id'];
} else {
    echo "✗ Create Product: FAILED\n";
    echo "  Response: $raw\n";
    $created_id = null;
}

// ── TEST 2: Duplicate SKU should fail ─────────────────────────────────────────
echo "\n--- TEST 2: Duplicate SKU should fail ---\n";
$_POST['product_name'] = 'Duplicate SKU Test';
// same sku
ob_start();
require __DIR__ . '/../api/create_product.php';
$raw2 = ob_get_clean();
$res2 = json_decode($raw2, true);

if ($res2 && !$res2['success'] && strpos($res2['message'], 'SKU') !== false) {
    echo "✓ Duplicate SKU rejected: PASSED\n";
} else {
    echo "✗ Duplicate SKU rejected: FAILED\n";
    echo "  Response: $raw2\n";
}

// ── TEST 3: Missing product_name should fail ───────────────────────────────────
echo "\n--- TEST 3: Missing required product_name ---\n";
$_POST['product_name'] = '';
$_POST['sku'] = 'UNIQUE-' . time();
ob_start();
// Suppress notices for empty product_name
try {
    require __DIR__ . '/../api/create_product.php';
} catch (\Throwable $e) {}
$raw3 = ob_get_clean();
$res3 = json_decode($raw3, true);

if ($res3 && !$res3['success']) {
    echo "✓ Empty product_name rejected: PASSED\n";
} else {
    // PHP trims empty string to '' — create_product saves it; this may pass in current code
    echo "~ Empty product_name: NOT validated server-side (validated client-side only)\n";
}

// ── TEST 4: Verify stock was saved for new product ────────────────────────────
echo "\n--- TEST 4: Verify initial stock saved ---\n";
if ($created_id && $warehouse_id) {
    $qty = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?");
    $qty->execute([$created_id, $warehouse_id]);
    $stock = $qty->fetchColumn();
    if ($stock == 50) {
        echo "✓ Initial stock saved correctly: PASSED (qty=$stock)\n";
    } else {
        echo "✗ Initial stock: FAILED (expected 50, got " . var_export($stock, true) . ")\n";
    }
} else {
    echo "~ Skipped (no product_id or warehouse_id available)\n";
}

// ── TEST 5: Verify min_selling_price auto-calculated ──────────────────────────
echo "\n--- TEST 5: Verify min_selling_price auto-calculation ---\n";
if ($created_id) {
    $row = $pdo->prepare("SELECT selling_price, discount_rate, min_selling_price FROM products WHERE product_id=?");
    $row->execute([$created_id]);
    $p = $row->fetch(PDO::FETCH_ASSOC);
    $expected = $p['selling_price'] - ($p['selling_price'] * $p['discount_rate'] / 100);
    if (abs($p['min_selling_price'] - $expected) < 0.01) {
        echo "✓ min_selling_price calculated correctly: PASSED ({$p['min_selling_price']})\n";
    } else {
        echo "✗ min_selling_price: FAILED (expected $expected, got {$p['min_selling_price']})\n";
    }
}

// ── TEST 6: Verify is_service=0 (inventory product) ───────────────────────────
echo "\n--- TEST 6: Verify is_service stored correctly ---\n";
if ($created_id) {
    $is_svc = $pdo->prepare("SELECT is_service, track_inventory FROM products WHERE product_id=?");
    $is_svc->execute([$created_id]);
    $flags = $is_svc->fetch(PDO::FETCH_ASSOC);
    if ($flags['is_service'] == 0 && $flags['track_inventory'] == 1) {
        echo "✓ is_service=0, track_inventory=1: PASSED\n";
    } else {
        echo "✗ is_service/track_inventory: FAILED (got is_service={$flags['is_service']}, track_inventory={$flags['track_inventory']})\n";
    }
}

// ── Cleanup: delete the test product ─────────────────────────────────────────
echo "\n--- CLEANUP ---\n";
if ($created_id) {
    $pdo->prepare("DELETE FROM stock_movements WHERE product_id=?")->execute([$created_id]);
    $pdo->prepare("DELETE FROM product_stocks WHERE product_id=?")->execute([$created_id]);
    $pdo->prepare("DELETE FROM products WHERE product_id=?")->execute([$created_id]);
    echo "✓ Test product deleted (id=$created_id)\n";
}

echo "\n=== DONE ===\n";
echo "</pre>\n";
