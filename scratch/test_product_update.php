<?php
/**
 * Test: update_product.php API
 * Run via: http://localhost/bms/scratch/test_product_update.php
 *
 * Creates a temp product, runs update tests, then deletes it.
 */
echo "<pre>\n";
echo "=== TEST: update_product.php ===\n\n";

// Start session and set admin credentials before roots.php loads
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role_id']  = 1; // role_id=1 → isAdmin() returns true → all permissions pass

require_once __DIR__ . '/../roots.php';
global $pdo;

// ── Pre-cleanup: remove any leftover test data from previous runs ─────────────
$leftovers = $pdo->query("SELECT product_id FROM products WHERE sku LIKE 'UPD-TEST-%' OR sku LIKE 'OTHER-%'")->fetchAll(PDO::FETCH_COLUMN);
if ($leftovers) {
    $in = implode(',', array_map('intval', $leftovers));
    $pdo->exec("DELETE FROM stock_movements WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM product_stocks WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
    echo "Pre-cleanup: removed " . count($leftovers) . " leftover test product(s)\n\n";
}

// ── Setup: insert a real product to update ────────────────────────────────────
$category_id = $pdo->query("SELECT category_id FROM product_categories LIMIT 1")->fetchColumn() ?: null;
$warehouse_id = $pdo->query("SELECT warehouse_id FROM warehouses WHERE status='active' LIMIT 1")->fetchColumn() ?: null;

echo "Using: category_id=$category_id, warehouse_id=$warehouse_id\n\n";

$sku = 'UPD-TEST-' . time();
$pdo->prepare("INSERT INTO products (product_name, sku, unit, cost_price, selling_price, min_selling_price, wholesale_price, discount_rate, status, is_service, track_inventory, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute(['Update Test Product', $sku, 'pcs', 400, 600, 570, 550, 5, 'active', 0, 1, 1]);
$test_pid = $pdo->lastInsertId();

// Add initial stock
if ($warehouse_id) {
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?,?,?,0)")
        ->execute([$test_pid, $warehouse_id, 30]);
}

echo "Setup: created product_id=$test_pid with SKU=$sku, stock=30\n\n";

// ── TEST 1: Basic product info update ─────────────────────────────────────────
echo "--- TEST 1: Basic field update ---\n";
$_POST = [
    'product_id'      => $test_pid,
    'product_name'    => 'Updated Product Name',
    'sku'             => $sku,
    'unit'            => 'kg',
    'cost_price'      => '450.00',
    'selling_price'   => '700.00',
    'wholesale_price' => '640.00',
    'discount_rate'   => '8',
    'min_selling_price' => '',
    'status'          => 'active',
    'is_service'      => '0',
    'track_inventory' => '1',
    'reorder_level'   => '5',
    'min_stock_level' => '2',
    'max_stock_level' => '200',
    'warranty_period' => '6',
    'expiry_days'     => '0',
    'weight'          => '1.2',
];
$_FILES = [];

ob_start();
require __DIR__ . '/../api/update_product.php';
$raw = ob_get_clean();
$res = json_decode($raw, true);

if ($res && $res['success']) {
    echo "✓ Basic update: PASSED\n";
    // Verify fields in DB
    $p = $pdo->prepare("SELECT product_name, unit, cost_price, selling_price FROM products WHERE product_id=?");
    $p->execute([$test_pid]);
    $row = $p->fetch(PDO::FETCH_ASSOC);
    if ($row['product_name'] === 'Updated Product Name' && $row['unit'] === 'kg' && floatval($row['cost_price']) == 450.00) {
        echo "✓ DB values verified: PASSED\n";
    } else {
        echo "✗ DB values: FAILED - " . json_encode($row) . "\n";
    }
} else {
    echo "✗ Basic update: FAILED\n";
    echo "  Response: $raw\n";
}

// ── TEST 2: Stock adjustment via stock[] ──────────────────────────────────────
echo "\n--- TEST 2: Stock adjustment (30 → 55) ---\n";
if ($warehouse_id) {
    $_POST['stock'] = [$warehouse_id => 55];

    ob_start();
    require __DIR__ . '/../api/update_product.php';
    $raw2 = ob_get_clean();
    $res2 = json_decode($raw2, true);

    if ($res2 && $res2['success']) {
        // Verify new stock
        $qty = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?");
        $qty->execute([$test_pid, $warehouse_id]);
        $new_qty = floatval($qty->fetchColumn());

        // Verify movement was recorded
        $mv = $pdo->prepare("SELECT movement_type, quantity, stock_before, stock_after FROM stock_movements WHERE product_id=? AND warehouse_id=? ORDER BY movement_id DESC LIMIT 1");
        $mv->execute([$test_pid, $warehouse_id]);
        $movement = $mv->fetch(PDO::FETCH_ASSOC);

        if ($new_qty == 55) {
            echo "✓ Stock updated to 55: PASSED\n";
        } else {
            echo "✗ Stock qty: FAILED (expected 55, got $new_qty)\n";
        }

        if ($movement && $movement['movement_type'] === 'adjustment_in' && floatval($movement['quantity']) == 25) {
            echo "✓ Stock movement recorded (adjustment_in qty=25, before=30, after=55): PASSED\n";
        } else {
            echo "✗ Stock movement: FAILED - " . json_encode($movement) . "\n";
        }
    } else {
        echo "✗ Stock adjustment update: FAILED\n";
        echo "  Response: $raw2\n";
    }

    // TEST 2b: Decrease stock (55 → 40, adjustment_out)
    echo "\n--- TEST 2b: Stock decrease (55 → 40, adjustment_out) ---\n";
    $_POST['stock'] = [$warehouse_id => 40];
    unset($_POST['product_name']); // keep product_name from previous POST
    $_POST['product_name'] = 'Updated Product Name';

    ob_start();
    require __DIR__ . '/../api/update_product.php';
    $raw2b = ob_get_clean();
    $res2b = json_decode($raw2b, true);

    if ($res2b && $res2b['success']) {
        $qty2b = $pdo->prepare("SELECT stock_quantity FROM product_stocks WHERE product_id=? AND warehouse_id=?");
        $qty2b->execute([$test_pid, $warehouse_id]);
        $new_qty2b = floatval($qty2b->fetchColumn());

        $mv2b = $pdo->prepare("SELECT movement_type, quantity FROM stock_movements WHERE product_id=? AND warehouse_id=? ORDER BY movement_id DESC LIMIT 1");
        $mv2b->execute([$test_pid, $warehouse_id]);
        $mv2b_row = $mv2b->fetch(PDO::FETCH_ASSOC);

        if ($new_qty2b == 40) {
            echo "✓ Stock decreased to 40: PASSED\n";
        } else {
            echo "✗ Stock decrease: FAILED (expected 40, got $new_qty2b)\n";
        }
        if ($mv2b_row && $mv2b_row['movement_type'] === 'adjustment_out' && floatval($mv2b_row['quantity']) == 15) {
            echo "✓ adjustment_out movement (qty=15): PASSED\n";
        } else {
            echo "✗ adjustment_out movement: FAILED - " . json_encode($mv2b_row) . "\n";
        }
    } else {
        echo "✗ Stock decrease: FAILED\n";
        echo "  Response: $raw2b\n";
    }

    // TEST 2c: Same quantity — no movement should be created
    echo "\n--- TEST 2c: Same stock quantity — no movement ---\n";
    $count_before = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id=?");
    $count_before->execute([$test_pid]);
    $before = intval($count_before->fetchColumn());

    $_POST['stock'] = [$warehouse_id => 40]; // same as current

    ob_start();
    require __DIR__ . '/../api/update_product.php';
    ob_get_clean();

    $count_after = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE product_id=?");
    $count_after->execute([$test_pid]);
    $after = intval($count_after->fetchColumn());

    if ($before === $after) {
        echo "✓ No movement for unchanged stock: PASSED\n";
    } else {
        echo "✗ Unexpected movement created: FAILED (before=$before, after=$after)\n";
    }
} else {
    echo "~ Skipped (no warehouse_id)\n";
}

// ── TEST 3: Duplicate SKU on another product should fail ─────────────────────
echo "\n--- TEST 3: Duplicate SKU rejected ---\n";
$other_sku = 'OTHER-' . time();
$pdo->prepare("INSERT INTO products (product_name, sku, unit, cost_price, selling_price, min_selling_price, status, is_service, track_inventory, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
    ->execute(['Other Product', $other_sku, 'pcs', 100, 200, 180, 'active', 0, 1, 1]);
$other_pid = $pdo->lastInsertId();

$_POST['product_id'] = $test_pid;
$_POST['sku'] = $other_sku; // try to steal another product's SKU
unset($_POST['stock']);

ob_start();
require __DIR__ . '/../api/update_product.php';
$raw3 = ob_get_clean();
$res3 = json_decode($raw3, true);

if ($res3 && !$res3['success'] && strpos($res3['message'], 'SKU') !== false) {
    echo "✓ Duplicate SKU rejected: PASSED\n";
} else {
    echo "✗ Duplicate SKU: FAILED\n";
    echo "  Response: $raw3\n";
}

// ── TEST 4: Missing product_id guard (code inspection) ───────────────────────
echo "\n--- TEST 4: Missing product_id guard (code check) ---\n";
// Cannot test via require() because the API calls exit() — that would kill this script.
// Verify the guard exists in the source instead.
$api_src = file_get_contents(__DIR__ . '/../api/update_product.php');
if (strpos($api_src, "Product ID is required") !== false) {
    echo "✓ Missing product_id guard present in update_product.php: PASSED\n";
} else {
    echo "✗ Missing product_id guard: NOT FOUND in source\n";
}

// ── Cleanup ────────────────────────────────────────────────────────────────────
echo "\n--- CLEANUP ---\n";
$pdo->prepare("DELETE FROM stock_movements WHERE product_id IN (?,?)")->execute([$test_pid, $other_pid]);
$pdo->prepare("DELETE FROM product_stocks WHERE product_id IN (?,?)")->execute([$test_pid, $other_pid]);
$pdo->prepare("DELETE FROM products WHERE product_id IN (?,?)")->execute([$test_pid, $other_pid]);
echo "✓ Test products deleted (ids=$test_pid, $other_pid)\n";

echo "\n=== DONE ===\n";
echo "</pre>\n";
