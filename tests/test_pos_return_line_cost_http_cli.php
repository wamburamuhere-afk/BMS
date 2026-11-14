<?php
/**
 * tests/test_pos_return_line_cost_http_cli.php — the real endpoints, end to end (2026-11-14).
 *
 * The Piga Kazi Shop case through api/pos/process_sale.php and api/pos/create_return.php:
 * a product whose cost_price is the CRATE price (11,400) and whose batch costs 475 a bottle,
 * sold at 800. Sell 30, return 24, then the last 6 (a full return — the batch links go).
 *   - the sale line keeps cost 475; each return line carries 475;
 *   - the dashboard series (posSimpleBuySellSeries) moves by sold 0 / bought 0 for the whole
 *     story — before the fix "bought" stayed +14,250 while "sold" went back to 0;
 *   - the ledger: sale COGS 14,250 and the two returns' COGS reversals add up to 14,250.
 *
 * Runs in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/pos_dashboard_metrics.php";
require_once "$root/core/pos_line_cost.php";

$pdo->startOuter();
try {
    ok(posLineCostReady($pdo), 'pos_sale_items.unit_cost exists (migration ran)');
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-LCOST', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $pdo->prepare("INSERT INTO products (product_name, sku, unit, selling_price, min_selling_price, cost_price, purchase_price, is_service, status, stock_quantity, current_stock)
                   VALUES (?, ?, 'pcs', 800, 0, 11400, 0, 0, 'active', 50, 50)")->execute(["Pepsi $sfx", "LC-$sfx"]);
    $P = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, 50, 0)")->execute([$P, $W]);
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost, selling_price, created_at)
                   VALUES (?, ?, 'LC-BATCH', 50, 50, 475, 800, NOW() - INTERVAL 1 DAY)")->execute([$P, $W]);

    $today = date('Y-m-d');
    $series = function () use ($pdo, $today) {
        $r = posSimpleBuySellSeries($pdo, $today, $today, 'daily', '');
        return ['sold' => array_sum(array_column($r, 'sold')), 'bought' => array_sum(array_column($r, 'bought'))];
    };
    $base = $series();

    section('A. Sell 30 bottles through the till');
    $s = call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null,
        'items' => [['product_id' => $P, 'product_name' => 'x', 'quantity' => 30, 'price' => 800, 'discounted_price' => 800, 'tax_rate' => 0]],
        'subtotal' => 24000, 'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => 24000,
        'payment_method' => 'cash', 'amount_tendered' => 24000, 'change_given' => 0, 'amount_paid' => 24000], true);
    ok(!empty($s['success']), 'sale goes through' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? json_encode($s))));
    $sid  = (int)($s['sale_id'] ?? 0);
    $line = (int)val("SELECT sale_item_id FROM pos_sale_items WHERE sale_id = ? AND product_id = ?", [$sid, $P]);
    ok(abs((float)val("SELECT unit_cost FROM pos_sale_items WHERE sale_item_id = ?", [$line]) - 475) < 0.01, 'sale line fixed cost 475 (batch), not 11,400 (crate)');
    $cogs = (float)val("SELECT SUM(jei.amount) FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                         WHERE je.status = 'posted' AND je.entity_type = 'pos_cogs' AND je.entity_id = ? AND jei.type = 'debit'", [$sid]);
    ok(abs($cogs - 14250) < 0.01, "ledger COGS for the sale = 30 × 475 = 14,250 (got $cogs)");
    $afterSale = $series();
    ok(abs(($afterSale['sold'] - $base['sold']) - 24000) < 0.01 && abs(($afterSale['bought'] - $base['bought']) - 14250) < 0.01, 'dashboard: +24,000 sold, +14,250 bought');

    section('B. Customer returns 24, then the last 6 (full return)');
    $r1 = call('api/pos/create_return.php', 'POST', ['original_sale_id' => $sid, 'reason' => 'ZZ LCOST', 'refund_method' => 'cash',
        'items' => json_encode([['sale_item_id' => $line, 'return_qty' => 24]])]);
    ok(!empty($r1['success']), 'return of 24 goes through' . (!empty($r1['success']) ? '' : ' — ' . ($r1['message'] ?? '')));
    $r2 = call('api/pos/create_return.php', 'POST', ['original_sale_id' => $sid, 'reason' => 'ZZ LCOST', 'refund_method' => 'cash',
        'items' => json_encode([['sale_item_id' => $line, 'return_qty' => 6]])]);
    ok(!empty($r2['success']), 'return of the last 6 goes through' . (!empty($r2['success']) ? '' : ' — ' . ($r2['message'] ?? '')));
    $rids = array_filter([(int)($r1['return_id'] ?? 0), (int)($r2['return_id'] ?? 0)]);
    ok(count($rids) === 2, 'two return records created');
    foreach ($rids as $rid) {
        $uc = val("SELECT unit_cost FROM pos_sale_items WHERE sale_id = ?", [$rid]);
        ok($uc !== null && abs((float)$uc - 475) < 0.01, "return #$rid line carries the original cost 475 (got " . var_export($uc, true) . ')');
    }
    ok((int)val("SELECT COUNT(*) FROM pos_sale_item_batches WHERE sale_item_id = ?", [$line]) === 0, 'original line has no batch links left (the case that used to cost 0)');
    ok(abs((float)val("SELECT unit_cost FROM pos_sale_items WHERE sale_item_id = ?", [$line]) - 475) < 0.01, 'original line still remembers 475');
    ok(abs((float)val("SELECT quantity_remaining FROM product_batches WHERE product_id = ? AND warehouse_id = ?", [$P, $W]) - 50) < 0.0001, 'batch stock back to 50');

    $end = $series();
    ok(abs($end['sold'] - $base['sold']) < 0.01, 'dashboard sold back to where it started (24,000 − 24,000)');
    ok(abs($end['bought'] - $base['bought']) < 0.01, 'dashboard bought back too (14,250 − 14,250) — before the fix it stayed +14,250, a false loss');

    $rev = 0.0;
    foreach ($rids as $rid) {
        $rev += (float)val("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                             WHERE je.status = 'posted' AND je.entity_id = ? AND je.entity_type LIKE '%return%' AND jei.type = 'credit'
                               AND jei.description LIKE 'COGS reversal%'", [$rid]);
    }
    ok(abs($rev - 14250) < 0.01, "ledger COGS reversals for the two returns = 14,250 (got $rev)");
} catch (Throwable $e) {
    ok(false, 'threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $pdo->endOuter();
}
ok(!$pdo->inTransaction(), 'everything rolled back');
