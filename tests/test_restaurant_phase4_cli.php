<?php
/**
 * tests/test_restaurant_phase4_cli.php — restaurant_plan.md Phase 4 (split bill, join tables).
 *
 *   A. Split bill: paying part of a table's order with keep_table keeps the table and its open
 *      order; the remainder saved back is the ONE open order; paying it closes the table.
 *   B. Join: the first table's items join the second table's open order (total recalculated),
 *      the first order closes, its kitchen ticket moves, the first table is free; bad targets refused.
 *   C. Retail safety: keep_table without a table changes nothing; the till's split pieces only
 *      render with the restaurant module on; every inline script parses.
 *
 * A/B run in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
require_once __DIR__ . '/helpers/restaurant_inproc.php';

$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $pdo->prepare("UPDATE products SET kitchen_station_id = NULL WHERE product_id = ?")->execute([$DRINK]);
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-P4', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    require_once "$root/core/restaurant_scope.php";
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $DISH = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "P4 Wali $sfx", 'price' => 5000, 'prep_area' => 'kitchen'], $admin);
    $floor = restaurantDefaultFloorId($pdo, $W);
    foreach (['A', 'B', 'C'] as $n) {
        $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, ?, 4, 'available')")->execute([$W, $floor, "P4$n$sfx"]);
    }
    $TA = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["P4A$sfx"]);
    $TB = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["P4B$sfx"]);
    $TC = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["P4C$sfx"]);
    $line = fn($pid, $q, $p) => ['product_id' => $pid, 'product_name' => 'x', 'quantity' => $q, 'price' => $p, 'discounted_price' => $p, 'tax_rate' => 0];
    $hold = fn($t, $its) => call('api/pos/hold_sale.php', 'POST', ['reference' => 'T', 'items' => $its, 'subtotal' => array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $its)), 'tax' => 0, 'warehouse_id' => $W, 'table_id' => $t], true);
    $pay = function (array $its, ?int $table, bool $keep) use ($W) {
        $t = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $its));
        $d = ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $its, 'subtotal' => $t, 'discount_percentage' => 0, 'discount_amount' => 0,
              'tax' => 0, 'total' => $t, 'payment_method' => 'cash', 'amount_tendered' => $t, 'change_given' => 0, 'amount_paid' => $t, 'sale_type' => 'dine_in'];
        if ($table) $d['table_id'] = $table;
        if ($keep) $d['keep_table'] = 1;
        return call('api/pos/process_sale.php', 'POST', $d, true);
    };

    section('A. Split bill');
    $h = $hold($TA, [$line($DISH, 3, 5000), $line($DRINK, 1, $DPRICE)]);
    ok(!empty($h['success']), 'table order saved (3 × dish + 1 drink)');
    $s1 = $pay([$line($DISH, 1, 5000)], $TA, true);
    ok(!empty($s1['success']), 'first part paid (1 × dish) with keep_table' . (!empty($s1['success']) ? '' : ' — ' . ($s1['message'] ?? '')));
    ok(val("SELECT status FROM restaurant_tables WHERE table_id = ?", [$TA]) === 'occupied', 'table stays occupied after a part payment');
    ok((int)val("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = ? AND status = 'held'", [$TA]) === 1, "the table's open order is NOT closed by a part payment");
    $r = $hold($TA, [$line($DISH, 2, 5000), $line($DRINK, 1, $DPRICE)]);
    ok(!empty($r['success']) && (int)val("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = ? AND status = 'held'", [$TA]) === 1, 'remainder saved back = the one open order');
    ok(abs((float)val("SELECT total_amount FROM pos_held_sales WHERE table_id = ? AND status = 'held'", [$TA]) - (10000 + $DPRICE)) < 0.01, 'open order total = what is still owed');
    $s2 = $pay([$line($DISH, 2, 5000), $line($DRINK, 1, $DPRICE)], $TA, false);
    ok(!empty($s2['success']), 'rest of the bill paid' . (!empty($s2['success']) ? '' : ' — ' . ($s2['message'] ?? '')));
    ok(val("SELECT status FROM restaurant_tables WHERE table_id = ?", [$TA]) === 'available'
        && (int)val("SELECT COUNT(*) FROM pos_held_sales WHERE table_id = ? AND status = 'held'", [$TA]) === 0, 'final payment frees the table and closes its order');
    ok((int)val("SELECT COUNT(*) FROM pos_sales WHERE table_id = ? AND sale_id IN (?, ?)", [$TA, (int)($s1['sale_id'] ?? 0), (int)($s2['sale_id'] ?? 0)]) === 2, 'both parts are sales on the same table');
    require_once "$root/core/financial_reports.php";
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']) && !empty($bal['bs_balanced']), 'ledger balanced after the split bill');

    section('B. Join tables');
    $ha = $hold($TA, [$line($DISH, 1, 5000)]);
    $hb = $hold($TB, [$line($DRINK, 2, $DPRICE)]);
    call('api/restaurant/send_to_kitchen.php', 'POST', ['hold_id' => $ha['hold_id'] ?? 0, 'warehouse_id' => $W]);
    $j = call('api/restaurant/join_table_orders.php', 'POST', ['warehouse_id' => $W, 'from_table_id' => $TA, 'to_table_id' => $TB]);
    ok(!empty($j['success']), 'join A into B' . (!empty($j['success']) ? '' : ' — ' . ($j['message'] ?? '')));
    $b = $pdo->query("SELECT * FROM pos_held_sales WHERE table_id = $TB AND status = 'held'")->fetchAll(PDO::FETCH_ASSOC);
    $items = $b ? json_decode($b[0]['items_data'], true) : [];
    ok(count($b) === 1 && count($items) === 2 && abs((float)$b[0]['total_amount'] - (5000 + 2 * $DPRICE)) < 0.01, "B's order holds both orders' items and the combined total");
    ok(val("SELECT status FROM pos_held_sales WHERE hold_id = ?", [$ha['hold_id'] ?? 0]) === 'cancelled'
        && val("SELECT status FROM restaurant_tables WHERE table_id = ?", [$TA]) === 'available', "A's order closed and A is free");
    ok((int)val("SELECT COUNT(*) FROM kitchen_tickets WHERE table_id = ? AND status IN ('queued','preparing')", [$TB]) === 1
        && (int)val("SELECT COUNT(*) FROM kitchen_tickets WHERE table_id = ? AND status IN ('queued','preparing')", [$TA]) === 0, "A's kitchen ticket moved to B");
    $bad = call('api/restaurant/join_table_orders.php', 'POST', ['warehouse_id' => $W, 'from_table_id' => $TB, 'to_table_id' => $TC]);
    ok(empty($bad['success']), 'join onto a table with no order is refused (use Move)');
    $bad = call('api/restaurant/join_table_orders.php', 'POST', ['warehouse_id' => $W, 'from_table_id' => $TB, 'to_table_id' => $TB]);
    ok(empty($bad['success']), 'join a table with itself is refused');

    section('C. Retail safety');
    $s3 = $pay([$line($DRINK, 1, $DPRICE)], null, true);
    ok(!empty($s3['success']) && val("SELECT table_id FROM pos_sales WHERE sale_id = ?", [(int)($s3['sale_id'] ?? 0)]) === null, 'keep_table without a table: an ordinary sale, nothing else touched');
} finally {
    $pdo->endOuter();
}

$render = function (array $features) use ($W, $admin) {
    $pdo2 = $GLOBALS['pdo'];
    $orig = $pdo2->query("SELECT pos_mode FROM warehouses WHERE warehouse_id = $W")->fetchColumn();
    $origSimple = $pdo2->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    $pdo2->exec("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = $W");
    $pdo2->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $out = worker(['page' => 'app/bms/pos/pos.php', 'session' => ['user_id' => $admin, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'], 'features' => $features]);
    $pdo2->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$orig, $W]);
    if ($origSimple === false) $pdo2->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    else $pdo2->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSimple]);
    return $out;
};
$on = $render(['pos' => true, 'restaurant_pos' => true]);
ok(strpos($on, 'id="rsSplitModal"') !== false && strpos($on, 'id="rsSplitRow"') !== false, 'module on: till has Split bill');
ok(preg_match('#modal-header bg-success text-white">\s*<h5 class="modal-title"><i class="bi bi-grid-3x3-gap#', $on) === 0, 'table picker header is blue (UI standard)');
$off = $render(['pos' => true, 'restaurant_pos' => false]);
ok(strpos($off, 'id="rsSplitRow"') === false, 'module off: no Split bill in the till');
$html = preg_replace('#<!--.*?-->#s', '', explode('___OUT___', $on, 2)[1] ?? '');
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $mm);
$bad = 0;
foreach ($mm[1] as $i => $js) {
    if (trim($js) === '') continue;
    $f = tempnam(sys_get_temp_dir(), 'pjs') . '.js'; file_put_contents($f, $js);
    $o = []; exec('node --check ' . escapeshellarg($f) . ' 2>&1', $o, $rc); @unlink($f);
    if ($rc !== 0) { $bad++; echo "     script #$i: " . implode(' ', array_slice($o, 0, 3)) . "\n"; }
}
ok($bad === 0, 'every inline script of the rendered till parses (' . count($mm[1]) . ' scripts)');
$tb = worker(['page' => 'app/bms/restaurant/tables_board.php', 'session' => ['user_id' => $admin, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'], 'features' => ['pos' => true, 'restaurant_pos' => true]]);
ok(strpos($tb, 'function doJoin(') !== false && !preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', explode('___OUT___', $tb)[0] ?? ''), 'Tables screen renders with Join');
