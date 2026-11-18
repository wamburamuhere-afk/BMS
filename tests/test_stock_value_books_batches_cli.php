<?php
/**
 * tests/test_stock_value_books_batches_cli.php — Inventory in the books = the value left in the
 * batches, through a whole Smart POS stock life (2026-11-17).
 *
 * Before: a return was valued at the sale line's average cost while its bottles went back into
 * one batch (gap −25 on 5 bottles), and a stock reduction by adjustment was valued at the
 * product's current buying price while it came off older, cheaper batches (+180 on 4 bottles).
 * Now both take the value of the batches they really touch.
 *
 * Real endpoints, two buying prices (475 then 500, later 520): register, Receive Stock, a sale
 * that crosses batches, a return, a sale + void, Receive Stock again, Edit product −4, Stock
 * Adjustment out −3 then deleting it, Stock Adjustment in +6 then editing it to +2. After each
 * step: quantities in line AND books = batches.
 *
 * Runs in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
// Test seam: helpers.php's get_setting() caches settings at its first call; read live instead.
function get_setting($key, $default = '') {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $default;
    try {
        $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $s->execute([$key]); $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : $v;
    } catch (Throwable $e) { return $default; }
}
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/gl_accounts.php";
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'test-csrf';
$set = fn($k, $v) => $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
$ap = fn($a, $b, $t = 0.01) => abs((float)$a - (float)$b) < $t;

$pdo->startOuter();
try {
    $set('pos_simple_mode', '1'); $set('pos_allow_buying_above_selling', '0');
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-SVB', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $invAcc = inventoryAccountId($pdo);
    $books = fn() => (float)val("SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),0)
        FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted' WHERE jei.account_id = ?", [$invAcc]);
    $b0 = $books();
    $P = 0;
    $check = function (string $label, float $qty) use (&$P, $W, $books, $b0, $ap) {
        $shop  = (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$P, $W]);
        $copy  = (float)val("SELECT current_stock FROM products WHERE product_id = ?", [$P]);
        $left  = (float)val("SELECT COALESCE(SUM(quantity_remaining),0) FROM product_batches WHERE product_id = ? AND warehouse_id = ?", [$P, $W]);
        $value = (float)val("SELECT COALESCE(SUM(quantity_remaining * unit_cost),0) FROM product_batches WHERE product_id = ? AND warehouse_id = ?", [$P, $W]);
        $bk = $books() - $b0;
        ok($ap($shop, $qty) && $ap($copy, $qty) && $ap($left, $qty), "$label: quantity $qty everywhere (shop $shop, product $copy, batches $left)");
        ok($ap($bk, $value), "$label: books " . round($bk, 2) . " = batches " . round($value, 2));
    };
    $sell = function (int $q) use (&$P, $W) {
        return call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null,
            'items' => [['product_id' => $P, 'product_name' => 'x', 'quantity' => $q, 'price' => 850, 'discounted_price' => 850, 'tax_rate' => 0]],
            'subtotal' => 850 * $q, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => 850 * $q,
            'payment_method' => 'cash', 'amount_tendered' => 850 * $q, 'change_given' => 0, 'amount_paid' => 850 * $q], true);
    };
    if (!$invAcc) { ok(true, 'no Inventory account on this database — skipped'); throw new RuntimeException('skip'); }

    section('Register 24 @ 475, Receive Stock 48 @ 500');
    $r = call('api/create_product.php', 'POST', ['product_name' => "ZZ SVB $sfx", 'unit' => 'pcs', 'cost_price' => 475, 'selling_price' => 800, 'is_service' => 0, 'track_inventory' => 1, 'initial_stock' => [$W => 24]]);
    $P = (int)($r['product_id'] ?? val("SELECT product_id FROM products WHERE product_name = ?", ["ZZ SVB $sfx"]));
    ok($P > 0, 'registered');
    $check('after registering', 24);
    call('api/pos/quick_restock.php', 'POST', ['product_id' => $P, 'warehouse_id' => $W, 'quantity' => 48, 'buying_price' => 500, 'selling_price' => 850]);
    $check('after Receive Stock', 72);

    section('Sale crossing two batches, then a return');
    $s = $sell(30);
    $sid = (int)($s['sale_id'] ?? 0);
    ok($sid > 0, 'sale of 30' . ($sid > 0 ? '' : ' — ' . ($s['message'] ?? json_encode($s))));
    $check('after the sale', 42);
    $line = (int)val("SELECT sale_item_id FROM pos_sale_items WHERE sale_id = ?", [$sid]);
    $ret = call('api/pos/create_return.php', 'POST', ['original_sale_id' => $sid, 'reason' => 'ZZ SVB', 'refund_method' => 'cash', 'items' => json_encode([['sale_item_id' => $line, 'return_qty' => 5]])]);
    ok(!empty($ret['success']), 'return of 5');
    $check('after the return (was −25)', 47);
    ok($ap(val("SELECT unit_cost FROM pos_sale_items WHERE sale_id = ?", [(int)($ret['return_id'] ?? 0)]), 475), 'return line valued at the batch it went back into (475), not the sale\'s average (480)');

    section('Sale + void, Receive Stock 12 @ 520');
    $s2 = $sell(3);
    $check('after a sale of 3', 44);
    call('api/pos/void_sale.php', 'POST', ['sale_id' => (int)($s2['sale_id'] ?? 0), 'reason' => 'ZZ SVB', 'void_reason' => 'ZZ SVB']);
    $check('after the void', 47);
    call('api/pos/quick_restock.php', 'POST', ['product_id' => $P, 'warehouse_id' => $W, 'quantity' => 12, 'buying_price' => 520, 'selling_price' => 850]);
    $check('after Receive Stock again', 59);

    section('Edit product −4 (was +180)');
    call('api/update_product.php', 'POST', ['product_id' => $P, 'product_name' => "ZZ SVB $sfx", 'unit' => 'pcs', 'cost_price' => 520, 'selling_price' => 850, 'is_service' => 0, 'stock' => [$W => 55]]);
    $check('after Edit product −4', 55);

    section('Stock Adjustment out −3, then delete it');
    $a = call('api/create_stock_adjustment.php', 'POST', ['product_id' => $P, 'warehouse_id' => $W, 'quantity' => 3, 'movement_type' => 'adjustment_out', 'unit_cost' => 0, 'reason' => 'ZZ SVB out']);
    ok(!empty($a['success']), 'adjustment out saved');
    $check('after adjustment out −3', 52);
    $mid = (int)val("SELECT movement_id FROM stock_movements WHERE product_id = ? AND reason = 'ZZ SVB out'", [$P]);
    call('api/delete_adjustment.php', 'POST', ['adjustment_id' => $mid]);
    $check('after deleting it', 55);

    section('Stock Adjustment in +6 @ 530, then edit it to +2');
    $a = call('api/create_stock_adjustment.php', 'POST', ['product_id' => $P, 'warehouse_id' => $W, 'quantity' => 6, 'movement_type' => 'adjustment_in', 'unit_cost' => 530, 'reason' => 'ZZ SVB in']);
    ok(!empty($a['success']), 'adjustment in saved');
    $check('after adjustment in +6', 61);
    $mid = (int)val("SELECT movement_id FROM stock_movements WHERE product_id = ? AND reason = 'ZZ SVB in'", [$P]);
    $u = call('api/update_adjustment.php', 'POST', ['movement_id' => $mid, 'id' => $mid, 'product_id' => $P, 'warehouse_id' => $W, 'quantity' => 2, 'movement_type' => 'adjustment_in', 'unit_cost' => 530, 'reason' => 'ZZ SVB in', 'notes' => '']);
    ok(!empty($u['success']), 'adjustment edited' . (!empty($u['success']) ? '' : ' — ' . ($u['message'] ?? '')));
    $check('after editing it to +2', 57);
    call('api/delete_adjustment.php', 'POST', ['adjustment_id' => $mid]);
    $check('after deleting it', 55);
} catch (Throwable $e) {
    if ($e->getMessage() !== 'skip') ok(false, 'threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $pdo->endOuter();
}
ok(!$pdo->inTransaction(), 'everything rolled back');
