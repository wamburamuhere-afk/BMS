<?php
/**
 * tests/test_product_edit_stock_sync_cli.php — stock adjustments keep shop stock, the product's
 * own copy and (Smart POS) the batches together (2026-11-14, core/stock_adjust_sync.php).
 *
 * The Piga Kazi case: Coca cola registered as 30 "units" (cartons) at 533.34, sold to 5 left;
 * the owner corrects the count in Edit product to 599 bottles. Before the fix: shop 599,
 * product 5, batches 5. Now all three follow, through the real endpoints:
 *   Edit product up / down, a sale after it, Stock Adjustment page in, deleting that adjustment,
 *   Smart BMS (no batches touched, copy still synced), services skipped, and the one-off repair
 *   (+ re-run changes nothing). Ledger: the adjustment's own entry, nothing extra.
 *
 * Runs in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
// Test seam: helpers.php's get_setting() caches every setting at its first call; this one reads
// live so settings written inside the test transaction are seen. Test-only.
function get_setting($key, $default = '') {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $default;
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/stock_adjust_sync.php";
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf';

$set = function (string $k, string $v) use ($pdo) {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")->execute([$k, $v]);
};
$approx = fn($a, $b) => abs((float)$a - (float)$b) < 0.001;

$pdo->startOuter();
try {
    $set('pos_simple_mode', '1');
    $set('pos_allow_buying_above_selling', '0');
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-ESS', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $mkProduct = function (string $name, float $stock, float $cost, float $sell, int $service = 0) use ($pdo, $W) {
        $pdo->prepare("INSERT INTO products (product_name, sku, unit, selling_price, min_selling_price, cost_price, purchase_price, is_service, status, stock_quantity, current_stock)
                       VALUES (?, ?, 'pcs', ?, 0, ?, ?, ?, 'active', ?, ?)")->execute([$name, 'ESS-' . substr(md5($name), 0, 8), $sell, $cost, $cost, $service, $stock, $stock]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, ?, 0)")->execute([$id, $W, $stock]);
        return $id;
    };
    $state = function (int $pid) use ($W) {
        return [
            'shop'    => (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$pid, $W]),
            'copy'    => (float)val("SELECT current_stock FROM products WHERE product_id = ?", [$pid]),
            'copy2'   => (float)val("SELECT stock_quantity FROM products WHERE product_id = ?", [$pid]),
            'batches' => (float)val("SELECT COALESCE(SUM(quantity_remaining), 0) FROM product_batches WHERE product_id = ? AND warehouse_id = ?", [$pid, $W]),
        ];
    };
    $inLine = function (array $s, float $n) use ($approx) {
        return $approx($s['shop'], $n) && $approx($s['copy'], $n) && $approx($s['copy2'], $n) && $approx($s['batches'], $n);
    };
    $fmt = fn(array $s) => "shop {$s['shop']}, product {$s['copy']}/{$s['copy2']}, batches {$s['batches']}";
    $edit = function (int $pid, float $stock) use ($W, $sfx) {
        return call('api/update_product.php', 'POST', ['product_id' => $pid, 'product_name' => "ZZ COCA $sfx", 'unit' => 'pcs',
            'cost_price' => 533.34, 'selling_price' => 800, 'is_service' => 0, 'stock' => [$W => $stock]]);
    };

    section('1. Edit product: correcting the count up (5 → 599)');
    $P = $mkProduct("ZZ COCA $sfx", 5, 533.34, 800);
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost, selling_price, created_at)
                   VALUES (?, ?, 'REG', 30, 5, 533.34, 800, NOW() - INTERVAL 10 DAY)")->execute([$P, $W]);
    ok($inLine($state($P), 5), 'start: ' . $fmt($state($P)));
    $r = $edit($P, 599);
    ok(!empty($r['success']), 'Edit product saves' . (!empty($r['success']) ? '' : ' — ' . ($r['message'] ?? '')));
    ok($inLine($state($P), 599), 'all three follow: ' . $fmt($state($P)));
    $corr = $pdo->query("SELECT * FROM product_batches WHERE product_id = $P ORDER BY batch_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok($approx($corr['quantity_received'], 594) && $approx($corr['unit_cost'], 533.34), 'one correction batch of 594 at 533.34 (the buying price)');
    $ref = (string)val("SELECT reference_number FROM stock_movements WHERE product_id = ? AND reason = 'Stock adjustment via product edit' ORDER BY movement_id DESC LIMIT 1", [$P]);
    ok($ref !== '' && $corr['batch_number'] === $ref, "batch named after the adjustment ($ref)");
    $gl = (float)val("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                       JOIN stock_movements sm ON sm.movement_id = je.entity_id AND je.entity_type = 'stock_adjustment'
                      WHERE sm.product_id = ? AND jei.type = 'debit' AND je.status = 'posted'", [$P]);
    ok($gl === 0.0 || $approx($gl, round(594 * 533.34, 2)), 'ledger: only the adjustment\'s own entry (594 × 533.34) — nothing added by the sync (got ' . $gl . ')');

    section('2. Edit product: correcting down (599 → 300), taken off in sale order');
    $r = $edit($P, 300);
    ok(!empty($r['success']) && $inLine($state($P), 300), 'all three follow: ' . $fmt($state($P)));
    ok($approx(val("SELECT quantity_remaining FROM product_batches WHERE product_id = ? AND batch_number = 'REG'", [$P]), 0), 'oldest batch emptied first (FEFO), the correction batch keeps the rest');

    section('3. A sale after the correction');
    $s = call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null,
        'items' => [['product_id' => $P, 'product_name' => 'x', 'quantity' => 10, 'price' => 800, 'discounted_price' => 800, 'tax_rate' => 0]],
        'subtotal' => 8000, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => 8000,
        'payment_method' => 'cash', 'amount_tendered' => 8000, 'change_given' => 0, 'amount_paid' => 8000], true);
    ok(!empty($s['success']), 'sale of 10 goes through' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    ok($inLine($state($P), 290), 'all three down by 10: ' . $fmt($state($P)));
    $lc = val("SELECT unit_cost FROM pos_sale_items WHERE sale_id = ? AND product_id = ?", [(int)($s['sale_id'] ?? 0), $P]);
    ok($lc === false || $lc === null || $approx($lc, 533.34), 'sale line cost 533.34 from the correction batch');

    section('4. Stock Adjustment page, then deleting that adjustment');
    $a = call('api/create_stock_adjustment.php', 'POST', ['product_id' => $P, 'warehouse_id' => $W, 'quantity' => 10, 'movement_type' => 'adjustment_in', 'unit_cost' => 0, 'reason' => 'ZZ test']);
    ok(!empty($a['success']) && $inLine($state($P), 300), 'adjustment in +10: ' . $fmt($state($P)));
    $mid = (int)val("SELECT movement_id FROM stock_movements WHERE product_id = ? AND reason = 'ZZ test' ORDER BY movement_id DESC LIMIT 1", [$P]);
    $d = call('api/delete_adjustment.php', 'POST', ['id' => $mid, 'adjustment_id' => $mid, 'movement_id' => $mid]);
    ok(!empty($d['success']), 'delete adjustment saves' . (!empty($d['success']) ? '' : ' — ' . ($d['message'] ?? json_encode($d))));
    ok($inLine($state($P), 290), 'deleting it brings all three back (the product copy too): ' . $fmt($state($P)));

    section('5. Product in a second shop: the copy is the total of both shops');
    $W2 = (int)val("SELECT warehouse_id FROM warehouses WHERE warehouse_id <> ? ORDER BY warehouse_id LIMIT 1", [$W]);
    if ($W2 > 0) {
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 40, 0)")->execute([$P, $W2]);
        $r = $edit($P, 280);
        ok(!empty($r['success']) && $approx($state($P)['copy'], 320) && $approx($state($P)['shop'], 280) && $approx($state($P)['batches'], 280), 'shop 280 + other shop 40 → product copy 320; this shop\'s batches 280');
        $pdo->prepare("DELETE FROM product_stocks WHERE product_id = ? AND warehouse_id = ?")->execute([$P, $W2]);
        stockAdjustSyncProductCopy($pdo, $P);
    } else {
        ok(true, 'only one shop on this database — skipped');
    }

    section('6. Smart BMS (Simple Mode off): batches untouched, copy still synced');
    $set('pos_simple_mode', '0');
    $Q = $mkProduct("ZZ BMS $sfx", 5, 100, 150);
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost, selling_price, created_at)
                   VALUES (?, ?, 'GRN', 5, 5, 100, 150, NOW())")->execute([$Q, $W]);
    $r = call('api/update_product.php', 'POST', ['product_id' => $Q, 'product_name' => "ZZ BMS $sfx", 'unit' => 'pcs', 'cost_price' => 100, 'selling_price' => 150, 'is_service' => 0, 'stock' => [$W => 50]]);
    $sq = $state($Q);
    ok(!empty($r['success']) && $approx($sq['shop'], 50) && $approx($sq['copy'], 50) && $approx($sq['batches'], 5), 'shop 50, product 50, batches stay 5 (Smart BMS keeps its own batches)');
    $set('pos_simple_mode', '1');

    section('7. Services and combos hold no batches');
    $S = $mkProduct("ZZ SVC $sfx", 0, 0, 1000, 1);
    ok(stockAdjustReconcileBatches($pdo, $S, $W, 0)['action'] === 'skipped', 'service skipped');

    section('8. One-off repair of products edited before the fix (+ re-run)');
    $R = $mkProduct("ZZ PEPSI $sfx", 98, 475, 800);
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost, selling_price, created_at)
                   VALUES (?, ?, 'REG', 100, 98, 475, 800, NOW() - INTERVAL 5 DAY)")->execute([$R, $W]);
    // The old Edit product: shop stock + movement only.
    $pdo->prepare("UPDATE product_stocks SET stock_quantity = 1920 WHERE product_id = ? AND warehouse_id = ?")->execute([$R, $W]);
    $pdo->prepare("INSERT INTO stock_movements (product_id, movement_type, quantity, unit, reference_type, reference_id, reference_number, warehouse_id, stock_before, stock_after, reason, notes, created_by)
                   VALUES (?, 'adjustment_in', 1822, 'pcs', 'manual', ?, 'ZZ-ADJ', ?, 98, 1920, 'Stock adjustment via product edit', 'Manual stock edit', ?)")->execute([$R, $R, $W, $admin]);
    ok(!$inLine($state($R), 1920), 'broken state reproduced: ' . $fmt($state($R)));
    $n = stockAdjustRepairProductEdits($pdo);
    ok($inLine($state($R), 1920), 'after repair: ' . $fmt($state($R)));
    ok($approx(val("SELECT unit_cost FROM product_batches WHERE product_id = ? AND batch_number = 'Stock correction'", [$R]), 475), 'correction batch at the buying price 475');
    $again = stockAdjustRepairProductEdits($pdo);
    ok($again['batches_added'] === 0 && $again['batches_reduced'] === 0 && $inLine($state($R), 1920), 'running the repair again changes nothing');
    $glBefore = (int)val("SELECT COUNT(*) FROM journal_entries");
    stockAdjustRepairProductEdits($pdo);
    ok((int)val("SELECT COUNT(*) FROM journal_entries") === $glBefore, 'the repair posts nothing to the ledger');

    section('9. Wiring');
    foreach (['api/update_product.php' => 'stockAdjustAfterChange(', 'api/create_stock_adjustment.php' => 'stockAdjustReconcileBatches(',
              'api/process_bulk_adjustment.php' => 'stockAdjustReconcileBatches(', 'api/update_adjustment.php' => 'stockAdjustReconcileBatches(',
              'api/delete_adjustment.php' => 'stockAdjustAfterChange('] as $f => $needle) {
        ok(strpos((string)file_get_contents("$root/$f"), $needle) !== false, "$f syncs after the stock change");
    }
    $mig = (string)file_get_contents("$root/migrations/tenant/2026_11_14_product_edit_stock_sync.php");
    ok(strpos($mig, 'roots.php') === false && strpos($mig, 'notify.php') === false, 'tenant migration does not load roots.php / notify.php');
    ok(strpos((string)file_get_contents("$root/core/stock_adjust_sync.php"), 'require') === false, 'core/stock_adjust_sync.php has no includes (safe in migrations)');
} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $pdo->endOuter();
}
ok(!$pdo->inTransaction(), 'everything rolled back');
