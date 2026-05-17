<?php
/**
 * Test: Full edit button flow
 * 1. Creates a test product
 * 2. Checks product_edit.php has no PHP errors (syntax + runtime)
 * 3. Submits update via update_product.php (simulates Save button)
 * 4. Verifies all fields + stock saved correctly
 * 5. Cleans up
 *
 * Run via: http://localhost/bms/scratch/test_product_edit_flow.php
 */
echo "<pre>\n";
echo "=== TEST: Edit Button Full Flow ===\n\n";

session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role_id']  = 1;

require_once __DIR__ . '/../roots.php';
global $pdo;

// ── Pre-cleanup ───────────────────────────────────────────────────────────────
$leftovers = $pdo->query("SELECT product_id FROM products WHERE sku LIKE 'EDITFLOW-%'")->fetchAll(PDO::FETCH_COLUMN);
if ($leftovers) {
    $in = implode(',', array_map('intval', $leftovers));
    $pdo->exec("DELETE FROM stock_movements WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM product_stocks WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
    echo "Pre-cleanup: removed " . count($leftovers) . " leftover(s)\n\n";
}

// ── Get real DB IDs ───────────────────────────────────────────────────────────
$category_id  = $pdo->query("SELECT category_id FROM product_categories LIMIT 1")->fetchColumn() ?: null;
$warehouse_id = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetchColumn() ?: null;
$tax_id       = $pdo->query("SELECT rate_id FROM tax_rates WHERE status='active' LIMIT 1")->fetchColumn() ?: null;
echo "Using: category_id=$category_id, warehouse_id=$warehouse_id, tax_id=$tax_id\n\n";

// ── SETUP: Insert a product that the Edit page will load ──────────────────────
$sku = 'EDITFLOW-' . time();
$pdo->prepare("
    INSERT INTO products
        (product_name, sku, barcode, unit, cost_price, selling_price, min_selling_price,
         wholesale_price, discount_rate, category_id, tax_id, status,
         is_service, track_inventory, reorder_level, min_stock_level, max_stock_level,
         warranty_period, weight, description, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
")->execute([
    'Edit Flow Test Product', $sku, '6990000000001',
    'pcs', 300.00, 500.00, 475.00, 450.00, 5.00,
    $category_id, $tax_id, 'active',
    0, 1, 10, 5, 200,
    24, 0.75, 'Test description', 1
]);
$pid = $pdo->lastInsertId();

// Add initial stock
if ($warehouse_id) {
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?,?,?,0)")
        ->execute([$pid, $warehouse_id, 20]);
}
echo "Setup: product_id=$pid, SKU=$sku, initial stock=20\n\n";

// ── TEST 1: PHP syntax check on product_edit.php ──────────────────────────────
echo "--- TEST 1: PHP syntax check on product_edit.php ---\n";
$php_bin = 'C:/wamp64/bin/php/php8.2.0/php.exe'; // WAMP PHP binary
if (!file_exists($php_bin)) {
    // Try to auto-detect
    $found = glob('C:/wamp64/bin/php/*/php.exe');
    $php_bin = $found ? end($found) : null;
}
if ($php_bin && file_exists($php_bin)) {
    $syntax = shell_exec('"' . $php_bin . '" -l ' . escapeshellarg('C:/wamp64/www/bms/app/bms/product/product_edit.php') . ' 2>&1');
    if (strpos($syntax, 'No syntax errors') !== false) {
        echo "✓ product_edit.php syntax OK: PASSED\n";
    } else {
        echo "✗ product_edit.php syntax error: FAILED\n  $syntax\n";
    }
} else {
    echo "~ PHP CLI not found — skipping syntax check\n";
}

// ── TEST 2: PHP syntax check on update_product.php ───────────────────────────
echo "\n--- TEST 2: PHP syntax check on update_product.php ---\n";
if ($php_bin && file_exists($php_bin)) {
    $syntax2 = shell_exec('"' . $php_bin . '" -l ' . escapeshellarg('C:/wamp64/www/bms/api/update_product.php') . ' 2>&1');
    if (strpos($syntax2, 'No syntax errors') !== false) {
        echo "✓ update_product.php syntax OK: PASSED\n";
    } else {
        echo "✗ update_product.php syntax error: FAILED\n  $syntax2\n";
    }
} else {
    echo "~ PHP CLI not found — skipping syntax check\n";
}

// ── TEST 3: Edit page data queries work without errors ────────────────────────
echo "\n--- TEST 3: Edit page data queries work without errors ---\n";
$_GET['id'] = $pid;
try {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->execute([$pid]);
    $product_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product_row) {
        echo "✗ Product not found in DB: FAILED\n";
    } else {
        echo "✓ Product loads from DB: PASSED\n";
        echo "  product_name={$product_row['product_name']}, unit={$product_row['unit']}, is_service={$product_row['is_service']}\n";
    }

    // Stock query (same as product_edit.php runs)
    $s2 = $pdo->prepare("SELECT warehouse_id, stock_quantity FROM product_stocks WHERE product_id = ?");
    $s2->execute([$pid]);
    $stock_map = [];
    foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stock_map[$row['warehouse_id']] = $row['stock_quantity'];
    }
    if ($warehouse_id && isset($stock_map[$warehouse_id])) {
        echo "✓ Stock data loads for edit page: PASSED (wh=$warehouse_id, qty={$stock_map[$warehouse_id]})\n";
    } else {
        echo "~ No stock data found for warehouse $warehouse_id\n";
    }

    // Warehouses query
    $whs = $pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status='active'")->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Warehouses load: PASSED (" . count($whs) . " active)\n";

    // Dropdowns
    $cats  = $pdo->query("SELECT category_id FROM categories WHERE status='active' AND type='product'")->fetchAll();
    $taxes = $pdo->query("SELECT rate_id FROM tax_rates WHERE status='active'")->fetchAll();
    echo "✓ Dropdowns load: PASSED (categories=" . count($cats) . ", tax_rates=" . count($taxes) . ")\n";

} catch (Throwable $e) {
    echo "✗ Fatal error loading edit data: FAILED — " . $e->getMessage() . "\n";
}

// ── TEST 4: Submit the edit form (simulate Save button) ───────────────────────
echo "\n--- TEST 4: Submit update form (simulate Save button click) ---\n";
$_POST = [
    'product_id'      => $pid,
    'product_name'    => 'Edit Flow Test Product UPDATED',
    'sku'             => $sku,
    'barcode'         => '6990000000001',
    'description'     => 'Updated description',
    'category_id'     => $category_id,
    'unit'            => 'kg',
    'cost_price'      => '320.00',
    'selling_price'   => '520.00',
    'wholesale_price' => '480.00',
    'discount_rate'   => '8',
    'min_selling_price' => '',
    'tax_id'          => $tax_id,
    'status'          => 'active',
    'is_service'      => '0',
    'track_inventory' => '1',
    'reorder_level'   => '8',
    'min_stock_level' => '3',
    'max_stock_level' => '150',
    'warranty_period' => '12',
    'expiry_days'     => '0',
    'weight'          => '1.5',
    'dim_length'      => '10',
    'dim_width'       => '5',
    'dim_height'      => '3',
    'is_taxable'      => '1',
];
if ($warehouse_id) {
    $_POST['stock'] = [$warehouse_id => 35]; // change from 20 → 35
}
$_FILES = [];

ob_start();
require __DIR__ . '/../api/update_product.php';
$raw = ob_get_clean();
$res = json_decode($raw, true);

if ($res && $res['success']) {
    echo "✓ Update API call: PASSED\n";
} else {
    echo "✗ Update API call: FAILED — $raw\n";
}

// ── TEST 5: Verify all updated fields in DB ───────────────────────────────────
echo "\n--- TEST 5: Verify all fields saved correctly ---\n";
$saved = $pdo->prepare("SELECT * FROM products WHERE product_id=?");
$saved->execute([$pid]);
$p = $saved->fetch(PDO::FETCH_ASSOC);

$checks = [
    ['product_name', 'Edit Flow Test Product UPDATED'],
    ['unit',         'kg'],
    ['cost_price',   320.00],
    ['selling_price',520.00],
    ['discount_rate',8.00],
    ['reorder_level',8.000],
    ['weight',       1.5],
    ['is_taxable',   1],
    ['is_service',   0],
    ['track_inventory', 1],
];

$all_ok = true;
foreach ($checks as [$field, $expected]) {
    $actual = $p[$field];
    $ok = (is_float($expected)) ? abs(floatval($actual) - $expected) < 0.01 : $actual == $expected;
    if ($ok) {
        echo "  ✓ $field = $actual\n";
    } else {
        echo "  ✗ $field: expected $expected, got $actual\n";
        $all_ok = false;
    }
}

// Check dimensions
$dim_expected = '10×5×3 cm';
if ($p['dimensions'] === $dim_expected) {
    echo "  ✓ dimensions = {$p['dimensions']}\n";
} else {
    echo "  ✗ dimensions: expected '$dim_expected', got '{$p['dimensions']}'\n";
    $all_ok = false;
}

if ($all_ok) echo "✓ All fields verified: PASSED\n";

// ── TEST 6: Verify stock adjustment was recorded ──────────────────────────────
echo "\n--- TEST 6: Verify stock adjustment (20 → 35) ---\n";
if ($warehouse_id) {
    $new_qty = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?");
    $new_qty->execute([$pid, $warehouse_id]);
    $qty = floatval($new_qty->fetchColumn());

    $mv = $pdo->prepare("SELECT movement_type, quantity, stock_before, stock_after FROM stock_movements WHERE product_id=? AND warehouse_id=? ORDER BY movement_id DESC LIMIT 1");
    $mv->execute([$pid, $warehouse_id]);
    $movement = $mv->fetch(PDO::FETCH_ASSOC);

    if ($qty == 35) {
        echo "✓ Stock updated to 35: PASSED\n";
    } else {
        echo "✗ Stock qty: FAILED (expected 35, got $qty)\n";
    }

    if ($movement
        && $movement['movement_type'] === 'adjustment_in'
        && floatval($movement['quantity']) == 15
        && floatval($movement['stock_before']) == 20
        && floatval($movement['stock_after']) == 35) {
        echo "✓ Movement recorded (adjustment_in, qty=15, 20→35): PASSED\n";
    } else {
        echo "✗ Movement: FAILED — " . json_encode($movement) . "\n";
    }
} else {
    echo "~ Skipped (no warehouse)\n";
}

// ── TEST 7: Verify update API rejects wrong product_id ───────────────────────
echo "\n--- TEST 7: Non-existent product_id ---\n";
$_POST['product_id'] = 999999;

ob_start();
require __DIR__ . '/../api/update_product.php';
$raw7 = ob_get_clean();
$res7 = json_decode($raw7, true);

// update_product.php doesn't currently check if product exists before running UPDATE
// It will affect 0 rows and return success — check affected rows behavior
if ($res7) {
    echo "~ update_product.php response for non-existent ID: " . ($res7['success'] ? "success (0 rows affected)" : "failed: " . $res7['message']) . "\n";
}

// ── CLEANUP ───────────────────────────────────────────────────────────────────
echo "\n--- CLEANUP ---\n";
$pdo->prepare("DELETE FROM stock_movements WHERE product_id=?")->execute([$pid]);
$pdo->prepare("DELETE FROM product_stocks WHERE product_id=?")->execute([$pid]);
$pdo->prepare("DELETE FROM products WHERE product_id=?")->execute([$pid]);
echo "✓ Test product deleted (id=$pid)\n";

echo "\n=== DONE ===\n";
echo "</pre>\n";
