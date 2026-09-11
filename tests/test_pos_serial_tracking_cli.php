<?php
/**
 * Phase 26 (pos_upgrade_plan.md §9) — serial/IMEI-level stock tracking —
 * CLI test
 *   php tests/test_pos_serial_tracking_cli.php
 *
 * Verifies:
 *   1. Files lint clean.
 *   2. Schema: product_serials, pos_sale_item_serials tables;
 *      products.track_serials; receipt_items.serial_numbers;
 *      stock_movements.reference_type includes 'pos_void'/'pos_return'.
 *   3. Wiring: approve_grn.php writes product_serials; process_sale.php/
 *      void_sale.php/create_return.php call the serial-tracking helpers.
 *   4. Runtime — consumeSerial()/consumeSerials(): consumes exactly the
 *      requested serial, flips it to 'sold', links it; an already-sold or
 *      unknown serial number is a no-op (returns null), never an error; a
 *      non-serial-tracked product (zero rows) yields an empty consumed list.
 *   5. Runtime — reverseSerialsForSaleItem(): full reversal (void) restores
 *      every linked serial; partial reversal (return) restores only up to
 *      the requested count and leaves the rest linked/sold.
 *   6. Runtime — the relocated stock_movements.reference_type ENUM fix: a
 *      'pos_void'/'pos_return' movement round-trips intact, no longer
 *      silently coerced to ''.
 *
 * All DB writes happen inside a rolled-back transaction — nothing persists.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/pos_serial_tracking.php";
require_once "$root/core/stock_ledger.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m)  { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function src($p)     { return is_file($p) ? file_get_contents($p) : ''; }

register_shutdown_function(function () {
    global $pass, $fail, $pdo; static $printed = false; if ($printed) return; $printed = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

try {
    // ── 1. Files lint clean ─────────────────────────────────────────────
    section('1. Files lint clean');
    $files = [
        'core/pos_serial_tracking.php', 'api/pos/process_sale.php', 'api/pos/void_sale.php',
        'api/pos/create_return.php', 'api/pos/get_available_serials.php', 'api/pos/simple_products.php',
        'api/approve_grn.php', 'api/create_grn.php', 'api/create_product.php', 'api/update_product.php',
        'app/bms/product/product_edit.php', 'app/bms/grn/grn_create.php', 'app/bms/pos/pos_scripts_new.php',
        'migrations/tenant/2026_09_11_pos_product_serials.php',
        'migrations/2026_09_11_pos_product_serials_legacy_db.php',
    ];
    foreach ($files as $f) {
        $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
        ok($rc === 0, "$f lints clean");
    }

    // ── 2. Schema ────────────────────────────────────────────────────────
    section('2. Schema');
    foreach (['product_serials', 'pos_sale_item_serials'] as $t) {
        ok((bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn(), "table `$t` exists");
    }
    $col = $pdo->query("SHOW COLUMNS FROM products LIKE 'track_serials'")->fetch(PDO::FETCH_ASSOC);
    ok($col && $col['Type'] === 'tinyint(1)', 'products.track_serials exists (tinyint(1))');
    $col = $pdo->query("SHOW COLUMNS FROM receipt_items LIKE 'serial_numbers'")->fetch(PDO::FETCH_ASSOC);
    ok((bool)$col, 'receipt_items.serial_numbers exists');
    $col = $pdo->query("SHOW COLUMNS FROM stock_movements LIKE 'reference_type'")->fetch(PDO::FETCH_ASSOC);
    ok($col && strpos($col['Type'], "'pos_void'") !== false && strpos($col['Type'], "'pos_return'") !== false,
        "stock_movements.reference_type ENUM includes 'pos_void' and 'pos_return'");

    // ── 3. Wiring ────────────────────────────────────────────────────────
    section('3. Wiring');
    $approve = src("$root/api/approve_grn.php");
    ok(strpos($approve, 'INSERT IGNORE INTO product_serials') !== false, 'approve_grn.php writes product_serials');
    ok(strpos($approve, "it['track_serials']") !== false, 'approve_grn.php checks the line product\'s track_serials flag');
    $sale = src("$root/api/pos/process_sale.php");
    ok(strpos($sale, 'consumeSerials(') !== false, 'process_sale.php consumes via consumeSerials()');
    ok(strpos($sale, "p.track_serials") !== false, 'process_sale.php selects track_serials on the product fetch');
    $void = src("$root/api/pos/void_sale.php");
    ok(strpos($void, 'reverseSerialsForSaleItem(') !== false, 'void_sale.php reverses serial consumption');
    $ret = src("$root/api/pos/create_return.php");
    ok(strpos($ret, 'reverseSerialsForSaleItem(') !== false, 'create_return.php reverses serial consumption (partial)');

    // ── 4/5/6. Runtime (rolled back) ────────────────────────────────────
    section('4/5/6. Runtime — consume/reverse + ENUM round-trip (rolled back)');
    $prodRow = $pdo->query("SELECT product_id FROM products WHERE status='active' AND is_service=0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $whRow   = $pdo->query("SELECT warehouse_id FROM warehouses LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $userRow = $pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$prodRow || !$whRow || !$userRow) {
        ok(true, 'no active product/warehouse/user to test against — skipped (n/a)');
    } else {
        $pid = (int)$prodRow['product_id'];
        $wid = (int)$whRow['warehouse_id'];
        $uid = (int)$userRow['user_id'];

        $pdo->beginTransaction();

        $saleItemId = (int)$pdo->query("SELECT COALESCE(MAX(sale_item_id),0) + 900000 FROM pos_sale_items")->fetchColumn();

        // Three serials in stock for this product/warehouse.
        $pdo->prepare("INSERT INTO product_serials (product_id, warehouse_id, serial_number, status) VALUES (?, ?, 'IMEI-A', 'in_stock')")->execute([$pid, $wid]);
        $pdo->prepare("INSERT INTO product_serials (product_id, warehouse_id, serial_number, status) VALUES (?, ?, 'IMEI-B', 'in_stock')")->execute([$pid, $wid]);
        $pdo->prepare("INSERT INTO product_serials (product_id, warehouse_id, serial_number, status) VALUES (?, ?, 'IMEI-C', 'in_stock')")->execute([$pid, $wid]);
        // Already sold — must never be consumable.
        $pdo->prepare("INSERT INTO product_serials (product_id, warehouse_id, serial_number, status) VALUES (?, ?, 'IMEI-SOLD', 'sold')")->execute([$pid, $wid]);

        // consumeSerial(): a real in_stock serial.
        $sidA = consumeSerial($pdo, $pid, $wid, 'IMEI-A', $saleItemId);
        ok($sidA !== null, 'consumeSerial() consumed an available serial');
        $statusA = $pdo->query("SELECT status FROM product_serials WHERE serial_id=$sidA")->fetchColumn();
        ok($statusA === 'sold', 'consumed serial flipped to sold');
        $linked = (int)$pdo->query("SELECT COUNT(*) FROM pos_sale_item_serials WHERE sale_item_id=$saleItemId AND serial_id=$sidA")->fetchColumn();
        ok($linked === 1, 'consumeSerial() linked the sale item to the serial');

        // Already-sold / unknown serial -> null, never an error.
        ok(consumeSerial($pdo, $pid, $wid, 'IMEI-SOLD', $saleItemId) === null, 'an already-sold serial cannot be consumed again (returns null)');
        ok(consumeSerial($pdo, $pid, $wid, 'IMEI-NOPE', $saleItemId) === null, 'an unknown serial number returns null, not an error');

        // consumeSerials(): consume the remaining two + one unknown -> only 2 actually consumed.
        $saleItemId2 = $saleItemId + 1;
        $consumed = consumeSerials($pdo, $pid, $wid, ['IMEI-B', 'IMEI-C', 'IMEI-UNKNOWN'], $saleItemId2);
        ok(count($consumed) === 2 && in_array('IMEI-B', $consumed, true) && in_array('IMEI-C', $consumed, true),
            'consumeSerials() consumes only the serials that are actually in_stock, reports a shortfall via count()');

        // Non-serial-tracked product (a product_id with zero product_serials rows) -> no-op.
        $noop = consumeSerials($pdo, 999999999, $wid, ['ANYTHING'], $saleItemId2 + 1);
        ok($noop === [], 'a product with zero serial rows -> empty consumed list, no error (regression guard)');

        // reverseSerialsForSaleItem(): full reversal (void) of saleItemId (1 serial: IMEI-A).
        $restored = reverseSerialsForSaleItem($pdo, $saleItemId);
        ok($restored === 1, 'full reversal (void) restored exactly 1 serial');
        $statusAfterVoid = $pdo->query("SELECT status, sale_item_id FROM product_serials WHERE serial_id=$sidA")->fetch(PDO::FETCH_ASSOC);
        ok($statusAfterVoid['status'] === 'in_stock' && $statusAfterVoid['sale_item_id'] === null, 'voided serial is back to in_stock with sale_item_id cleared');
        $linksLeft = (int)$pdo->query("SELECT COUNT(*) FROM pos_sale_item_serials WHERE sale_item_id=$saleItemId")->fetchColumn();
        ok($linksLeft === 0, 'link row removed after full reversal (idempotent — a repeat void is a no-op)');
        ok(reverseSerialsForSaleItem($pdo, $saleItemId) === 0, 'a repeat void of the same sale_item_id is a genuine no-op');

        // Partial reversal (return) of saleItemId2 (2 serials: IMEI-B, IMEI-C) — restore only 1.
        $partialRestored = reverseSerialsForSaleItem($pdo, $saleItemId2, 1);
        ok($partialRestored === 1, 'partial reversal (return) restored only the requested 1 of 2 serials');
        $linksLeftPartial = (int)$pdo->query("SELECT COUNT(*) FROM pos_sale_item_serials WHERE sale_item_id=$saleItemId2")->fetchColumn();
        ok($linksLeftPartial === 1, 'the other serial stays linked/sold — not restored by the partial return');

        // ENUM fix — a 'pos_void'/'pos_return' stock movement round-trips intact.
        $movIdVoid = recordStockMovement($pdo, [
            'product_id' => $pid, 'warehouse_id' => $wid, 'movement_type' => 'return_in',
            'quantity' => 1, 'reference_id' => $saleItemId, 'reference_type' => 'pos_void',
            'reference_number' => 'TEST-VOID', 'created_by' => $uid, 'notes' => 'test',
        ]);
        $savedType = $pdo->query("SELECT reference_type FROM stock_movements WHERE movement_id=$movIdVoid")->fetchColumn();
        ok($savedType === 'pos_void', "reference_type='pos_void' round-trips intact (got '" . var_export($savedType, true) . "')");

        $movIdReturn = recordStockMovement($pdo, [
            'product_id' => $pid, 'warehouse_id' => $wid, 'movement_type' => 'return_in',
            'quantity' => 1, 'reference_id' => $saleItemId2, 'reference_type' => 'pos_return',
            'reference_number' => 'TEST-RETURN', 'created_by' => $uid, 'notes' => 'test',
        ]);
        $savedType2 = $pdo->query("SELECT reference_type FROM stock_movements WHERE movement_id=$movIdReturn")->fetchColumn();
        ok($savedType2 === 'pos_return', "reference_type='pos_return' round-trips intact (got '" . var_export($savedType2, true) . "')");

        $pdo->rollBack();
        ok(!$pdo->inTransaction(), 'fixture rolled back — no test data persisted');
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok(false, 'threw: ' . $e->getMessage());
}
