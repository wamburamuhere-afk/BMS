<?php
/**
 * tests/test_restaurant_phase6_cli.php — restaurant_plan.md Phase 6 (dashboard + reports
 * follow what the company has).
 *
 *   A. restaurantDashboardData(): after a real dine-in + take-away sale and an open table
 *      order, sales today (ledger) rise by the sold amount, orders today +2, open tables/amount,
 *      best seller and a low drink are reported.
 *   B. restaurantSalesByOrderType(): dine_in / take_away rows equal the ledger revenue.
 *   C. Dashboard page: restaurant-only company → restaurant dashboard (no shop Quick Links);
 *      shops + restaurant → the shop dashboard unchanged + "Restaurant today"; module off →
 *      shop dashboard with nothing restaurant. Restaurant Sales report + its menu link only
 *      when the module is on.
 *
 * A/B run in-process inside one rolled-back transaction; C uses subprocess renders and
 * restores every shop/setting. Exit 0 = all pass.
 */
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/restaurant_dashboard.php";

$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-P6', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $DISH = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "P6 Pilau $sfx", 'price' => 6000, 'prep_area' => 'kitchen'], $admin);
    // A drink on the menu with little stock → "running low".
    $pdo->prepare("UPDATE products SET is_menu_item = 1, min_stock_level = 0 WHERE product_id = ?")->execute([$DRINK]);
    $floor = restaurantDefaultFloorId($pdo, $W);
    $pdo->prepare("INSERT INTO restaurant_tables (warehouse_id, floor_id, table_number, seats, status) VALUES (?, ?, ?, 4, 'available')")->execute([$W, $floor, "P6T$sfx"]);
    $T = (int)val("SELECT table_id FROM restaurant_tables WHERE table_number = ?", ["P6T$sfx"]);

    $before = restaurantDashboardData($pdo);
    $from = date('Y-m-d'); $to = $from;
    $typesBefore = []; foreach (restaurantSalesByOrderType($pdo, $from, $to, [$W]) as $r) $typesBefore[$r['sale_type']] = $r;

    $line = fn($pid, $q, $p) => ['product_id' => $pid, 'product_name' => 'x', 'quantity' => $q, 'price' => $p, 'discounted_price' => $p, 'tax_rate' => 0];
    $pay = function (array $its, string $type) use ($W) {
        $t = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $its));
        return call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $its, 'subtotal' => $t,
            'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $t, 'payment_method' => 'cash', 'amount_tendered' => $t,
            'change_given' => 0, 'amount_paid' => $t, 'sale_type' => $type], true);
    };
    $s1 = $pay([$line($DISH, 3, 6000)], 'dine_in');
    $s2 = $pay([$line($DISH, 1, 6000), $line($DRINK, 1, $DPRICE)], 'take_away');
    ok(!empty($s1['success']) && !empty($s2['success']), 'fixture: a dine-in and a take-away sale' . (!empty($s1['success']) && !empty($s2['success']) ? '' : ' — ' . ($s1['message'] ?? '') . ' ' . ($s2['message'] ?? '')));
    $h = call('api/pos/hold_sale.php', 'POST', ['reference' => 'T', 'items' => [$line($DISH, 2, 6000)], 'subtotal' => 12000, 'tax' => 0, 'warehouse_id' => $W, 'table_id' => $T], true);
    ok(!empty($h['success']), 'fixture: an open table order of 12,000');
    // Now leave the drink with little stock → "running low" (after it was sold).
    $pdo->prepare("UPDATE product_stocks SET stock_quantity = 3 WHERE product_id = ? AND warehouse_id = ?")->execute([$DRINK, $W]);

    section('A. Dashboard figures');
    $after = restaurantDashboardData($pdo);
    $sold = 24000 + $DPRICE;
    ok(abs(($after['sales_today'] - $before['sales_today']) - $sold) < 0.01, 'sales today (ledger) up by exactly what was sold');
    ok($after['orders_today'] - $before['orders_today'] === 2, 'orders today +2');
    ok($after['open_tables'] - $before['open_tables'] === 1 && abs(($after['open_amount'] - $before['open_amount']) - 12000) < 0.01, 'one more table with an order, 12,000 not paid yet');
    $top = array_column($after['top_dishes'], 'qty', 'product_name');
    ok(isset($top["P6 Pilau $sfx"]) && (float)$top["P6 Pilau $sfx"] == 4.0, 'best sellers: the dish, 4 sold');
    $dname = (string)val("SELECT product_name FROM products WHERE product_id = ?", [$DRINK]);
    ok(in_array($dname, array_column($after['low_drinks'], 'product_name'), true), 'drinks running low lists the drink with little stock');
    ok(abs($after['month_food_profit'] - ($after['month_sales'] - $after['month_ingredients'])) < 0.01, 'food profit = sales − ingredients');

    section('B. Sales by order type (ledger)');
    $types = []; foreach (restaurantSalesByOrderType($pdo, $from, $to, [$W]) as $r) $types[$r['sale_type']] = $r;
    $d = fn($k, $f) => ($types[$k][$f] ?? 0) - ($typesBefore[$k][$f] ?? 0);
    ok(abs($d('dine_in', 'sales') - 18000) < 0.01 && $d('dine_in', 'orders') === 1, 'Dine in: 1 order, 18,000');
    ok(abs($d('take_away', 'sales') - (6000 + $DPRICE)) < 0.01 && $d('take_away', 'orders') === 1, 'Take away: 1 order, 6,000 + drink');
} finally {
    $pdo->endOuter();
}

section('C. Pages');
$active = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses WHERE status = 'active'")->fetchAll(PDO::FETCH_KEY_PAIR);
$origSimple = val("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'");
$sess = ['user_id' => $admin, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'];
$ON = ['pos' => true, 'restaurant_pos' => true]; $OFF = ['pos' => true, 'restaurant_pos' => false];
$setModes = function (array $modes) use ($pdo) { foreach ($modes as $wid => $m) $pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$m, $wid]); };
$clean = fn($o) => !preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', explode('___OUT___', $o)[0] ?? '');
try {
    $pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
    $setModes(array_fill_keys(array_keys($active), 'restaurant'));
    $o = worker(['page' => 'app/dashboard.php', 'session' => $sess, 'features' => $ON]);
    ok($clean($o) && strpos($o, 'class="rd-actions') !== false && strpos($o, 'Quick Links') === false, 'restaurant-only company → restaurant dashboard (no shop Quick Links)');
    ok(strpos($o, 'Food profit this month') !== false && strpos($o, 'Recent orders') !== false, 'restaurant dashboard shows today + month cards and recent orders');

    $setModes(array_fill_keys(array_keys($active), 'retail'));
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $o = worker(['page' => 'app/dashboard.php', 'session' => $sess, 'features' => $ON]);
    ok($clean($o) && strpos($o, 'Quick Links') !== false && strpos($o, 'Restaurant today') !== false && strpos($o, 'class="rd-actions') === false, 'shops + restaurant → shop dashboard + "Restaurant today"');

    $o = worker(['page' => 'app/dashboard.php', 'session' => $sess, 'features' => $OFF]);
    ok($clean($o) && strpos($o, 'Quick Links') !== false && strpos($o, 'Restaurant today') === false && strpos($o, 'restaurant/sales-report') === false, 'module off → shop dashboard, nothing restaurant');

    $o = worker(['page' => 'app/bms/restaurant/sales_report.php', 'session' => $sess, 'features' => $ON]);
    ok($clean($o) && strpos($o, 'id="rrTypeTable"') !== false && strpos($o, 'id="rrItemsTable"') !== false, 'Restaurant Sales report renders (by order type + dishes)');
    ok(strpos($o, 'href="/restaurant/sales-report"') !== false || strpos($o, "restaurant/sales-report") !== false, 'Reports menu links to Restaurant Sales when the module is on');
    $o = worker(['page' => 'app/bms/restaurant/sales_report.php', 'session' => $sess, 'features' => $OFF]);
    ok(strpos($o, 'id="rrTypeTable"') === false, 'module off: no report data');
} finally {
    $setModes($active);
    if ($origSimple === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    else $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSimple]);
    $now = $pdo->query("SELECT warehouse_id, pos_mode FROM warehouses WHERE status = 'active'")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok($now == $active, 'shops restored to their original POS Mode');
}
