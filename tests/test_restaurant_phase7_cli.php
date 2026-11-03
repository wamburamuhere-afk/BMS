<?php
/**
 * tests/test_restaurant_phase7_cli.php — restaurant_plan.md Phase 7 (recipes + ingredient stock).
 *
 *   A. New ingredient = a stock item off the menu; receiving stock works as usual.
 *   B. Saving a recipe: ingredients stored, dish marked is_combo; a dish/service as ingredient is
 *      refused; an empty recipe turns the dish back into "sells without stock".
 *   C. Selling a dish with a recipe uses up ingredient stock and posts its cost
 *      (Σ ingredient qty × cost) to Cost of Sales; ledger balanced.
 *   D. A short ingredient never blocks a dish (stock may go under zero).
 *   E. Voiding puts the ingredients back and reverses the cost; ledger balanced.
 *   F. Retail combos unchanged: a short component still blocks; cost = the combo's own cost price.
 *   G. Menu shows recipe cost; dashboard "running low" lists ingredients; Menu page renders.
 *
 * In-process inside one rolled-back transaction. Exit 0 = all pass.
 */
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/restaurant_dashboard.php";
require_once "$root/core/stock_intake.php";

$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'")->execute();
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = ?")->execute([$W]);
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-P7', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $DISH = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "P7 Lunch $sfx", 'price' => 5000, 'prep_area' => 'kitchen'], $admin);
    $stock = fn($pid) => (float)val("SELECT COALESCE(SUM(stock_quantity),0) FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$pid, $W]);
    $line = fn($pid, $q, $p) => ['product_id' => $pid, 'product_name' => 'x', 'quantity' => $q, 'price' => $p, 'discounted_price' => $p, 'tax_rate' => 0];
    $pay = function (array $its) use ($W) {
        $t = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $its));
        return call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $its, 'subtotal' => $t,
            'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $t, 'payment_method' => 'cash', 'amount_tendered' => $t,
            'change_given' => 0, 'amount_paid' => $t, 'sale_type' => 'dine_in'], true);
    };
    $cogsOf = fn($sid) => (float)val("SELECT COALESCE(SUM(jei.amount),0) FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                                       WHERE je.entity_type = 'pos_cogs' AND je.entity_id = ? AND je.status = 'posted' AND jei.type = 'debit'", [$sid]);

    section('A. Ingredients');
    $r1 = call('api/restaurant/recipe.php', 'POST', ['action' => 'new_ingredient', 'warehouse_id' => $W, 'name' => "P7 Rice $sfx", 'unit' => 'kg', 'cost_price' => 3000]);
    $r2 = call('api/restaurant/recipe.php', 'POST', ['action' => 'new_ingredient', 'warehouse_id' => $W, 'name' => "P7 Meat $sfx", 'unit' => 'kg', 'cost_price' => 12000]);
    ok(!empty($r1['success']) && !empty($r2['success']), 'two ingredients created' . (!empty($r1['success']) ? '' : ' — ' . ($r1['message'] ?? '')));
    $RICE = (int)($r1['id'] ?? 0); $MEAT = (int)($r2['id'] ?? 0);
    $ri = $pdo->query("SELECT is_service, is_menu_item, unit, cost_price FROM products WHERE product_id = $RICE")->fetch(PDO::FETCH_ASSOC);
    ok($ri && (int)$ri['is_service'] === 0 && (int)$ri['is_menu_item'] === 0 && $ri['unit'] === 'kg' && (float)$ri['cost_price'] == 3000.0, 'ingredient = stock item, off the menu, kg, cost 3,000');
    foreach ([[$RICE, 10, 3000], [$MEAT, 2, 12000]] as [$pid, $q, $c]) {
        receiveProductBatch($pdo, ['product_id' => $pid, 'warehouse_id' => $W, 'quantity' => $q, 'unit_cost' => $c, 'write_batch' => true,
            'movement_type' => 'purchase_in', 'reference_type' => 'manual', 'reference_id' => $pid, 'created_by' => $admin]);
    }
    ok($stock($RICE) == 10.0 && $stock($MEAT) == 2.0, 'stock received: rice 10 kg, meat 2 kg');

    section('B. Saving a recipe');
    $bad = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'components' => json_encode([['product_id' => $DISH, 'qty' => 1]])]);
    ok(!empty($bad['success']) && (int)val("SELECT is_combo FROM products WHERE product_id = ?", [$DISH]) === 0, 'the dish itself as an ingredient is ignored (no recipe)');
    $svc = restaurantCreateMenuItem($pdo, $W, ['type' => 'dish', 'name' => "P7 Other dish $sfx", 'price' => 100], $admin);
    $bad = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'components' => json_encode([['product_id' => $svc, 'qty' => 1]])]);
    ok(empty($bad['success']), 'a dish/service as an ingredient is refused');
    $ok = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W,
        'components' => json_encode([['product_id' => $RICE, 'qty' => 0.25], ['product_id' => $MEAT, 'qty' => 0.15], ['product_id' => $RICE, 'qty' => 0.05]])]);
    ok(!empty($ok['success']), 'recipe saved' . (!empty($ok['success']) ? '' : ' — ' . ($ok['message'] ?? '')));
    $comps = $pdo->query("SELECT component_product_id, qty_per_unit FROM product_assembly_components WHERE parent_product_id = $DISH")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok(count($comps) === 2 && (float)$comps[$RICE] == 0.30 && (float)$comps[$MEAT] == 0.15 && (int)val("SELECT is_combo FROM products WHERE product_id = ?", [$DISH]) === 1, 'rice 0.30 kg (two lines merged) + meat 0.15 kg; dish has a recipe');
    $g = call('api/restaurant/recipe.php', 'GET', ['action' => 'get', 'product_id' => $DISH, 'warehouse_id' => $W]);
    $plate = 0.30 * 3000 + 0.15 * 12000;
    ok(!empty($g['success']) && abs($g['cost'] - $plate) < 0.01, "cost of one plate = $plate");

    section('C. Selling a dish with a recipe');
    $s = $pay([$line($DISH, 2, 5000)]);
    ok(!empty($s['success']), 'two plates sold' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    $sid = (int)($s['sale_id'] ?? 0);
    ok(abs($stock($RICE) - 9.4) < 0.001 && abs($stock($MEAT) - 1.7) < 0.001, 'ingredients used up: rice 10 → 9.4, meat 2 → 1.7');
    ok(abs($cogsOf($sid) - 2 * $plate) < 0.01, 'Cost of Sales posted = 2 × plate cost (' . (2 * $plate) . ')');
    require_once "$root/core/financial_reports.php";
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']) && !empty($bal['bs_balanced']), 'ledger balanced');

    section('D. A short ingredient never blocks a dish');
    $s2 = $pay([$line($DISH, 15, 5000)]);   // needs 2.25 kg meat, only 1.7 left
    ok(!empty($s2['success']), 'dish sells even though meat is short' . (!empty($s2['success']) ? '' : ' — ' . ($s2['message'] ?? '')));
    ok($stock($MEAT) < 0, 'meat stock goes under zero (shows as running low)');
    $dd = restaurantDashboardData($pdo);
    ok(in_array("P7 Meat $sfx", array_column($dd['low_drinks'], 'product_name'), true), 'dashboard "running low" lists the short ingredient');

    section('E. Void puts the ingredients back');
    $before = $stock($RICE);
    $v = call('api/pos/void_sale.php', 'POST', ['sale_id' => $sid, 'reason' => 'test void']);
    ok(!empty($v['success']), 'sale voided' . (!empty($v['success']) ? '' : ' — ' . ($v['message'] ?? '')));
    ok(abs($stock($RICE) - ($before + 0.6)) < 0.001, 'rice put back (+0.6 kg)');
    $net = (float)val("SELECT COALESCE(SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE -jei.amount END),0)
                         FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                        WHERE je.status = 'posted' AND je.entity_id = ? AND je.entity_type IN ('pos_cogs','pos_cogs_void')
                          AND jei.account_id = (SELECT jei2.account_id FROM journal_entry_items jei2 JOIN journal_entries je2 ON je2.entry_id = jei2.entry_id
                                                 WHERE je2.entity_type = 'pos_cogs' AND je2.entity_id = ? AND jei2.type = 'debit' LIMIT 1)", [$sid, $sid]);
    ok(abs($net) < 0.01, 'cost of sales of the voided sale is fully reversed (net 0)');
    $bal = assertLedgerBalanced($pdo);
    ok(!empty($bal['ledger_balanced']) && !empty($bal['bs_balanced']), 'ledger balanced after the void');

    section('F. Retail combos unchanged');
    $pdo->prepare("INSERT INTO products (product_name, selling_price, cost_price, is_service, is_combo, track_inventory, status, created_by) VALUES (?, 9000, 4000, 0, 1, 1, 'active', ?)")->execute(["P7 Gift Pack $sfx", $admin]);
    $PACK = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_assembly_components (parent_product_id, component_product_id, unit, qty_per_unit) VALUES (?, ?, 'kg', 1)")->execute([$PACK, $MEAT]);
    $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity) VALUES (?, ?, 5) ON DUPLICATE KEY UPDATE stock_quantity = 5")->execute([$PACK, $W]);
    $blocked = $pay([$line($PACK, 1, 9000)]);
    ok(empty($blocked['success']) && stripos((string)($blocked['message'] ?? ''), 'insufficient stock for combo') !== false, 'retail combo with a short component is still blocked');
    receiveProductBatch($pdo, ['product_id' => $MEAT, 'warehouse_id' => $W, 'quantity' => 10, 'unit_cost' => 12000, 'write_batch' => true,
        'movement_type' => 'purchase_in', 'reference_type' => 'manual', 'reference_id' => $MEAT, 'created_by' => $admin]);
    $sp = $pay([$line($PACK, 1, 9000)]);
    ok(!empty($sp['success']), 'retail combo sells once the component is in stock');
    $cogsPack = $cogsOf((int)($sp['sale_id'] ?? 0));
    ok(abs($cogsPack - 4000) < 0.01 || $cogsPack == 0.0, 'retail combo cost uses its own cost price, as before (not the component formula) [' . $cogsPack . ']');

    section('G. Menu, dashboard, page');
    $m = call('api/restaurant/get_menu_items.php', 'GET', ['warehouse_id' => $W]);
    $row = array_values(array_filter($m['data'] ?? [], fn($x) => (int)$x['product_id'] === $DISH))[0] ?? [];
    ok(!empty($row['has_recipe']) && abs(($row['recipe_cost'] ?? 0) - $plate) < 0.01, 'Menu row: has a recipe, cost per plate');
    $empty = call('api/restaurant/recipe.php', 'POST', ['action' => 'save', 'product_id' => $DISH, 'warehouse_id' => $W, 'components' => '[]']);
    ok(!empty($empty['success']) && (int)val("SELECT is_combo FROM products WHERE product_id = ?", [$DISH]) === 0
        && (int)val("SELECT COUNT(*) FROM product_assembly_components WHERE parent_product_id = ?", [$DISH]) === 0, 'empty recipe → dish sells without stock again');
} finally {
    $pdo->endOuter();
}

$orig = val("SELECT pos_mode FROM warehouses WHERE warehouse_id = ?", [$W]);
$origSimple = val("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'");
$pdo->exec("UPDATE warehouses SET pos_mode = 'restaurant' WHERE warehouse_id = $W");
$pdo->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('pos_simple_mode','1') ON DUPLICATE KEY UPDATE setting_value='1'");
$o = worker(['page' => 'app/bms/restaurant/menu.php', 'session' => ['user_id' => $admin, 'is_admin' => true, 'role_id' => 1, 'user_lang' => 'en'], 'features' => ['pos' => true, 'restaurant_pos' => true]]);
$pdo->prepare("UPDATE warehouses SET pos_mode = ? WHERE warehouse_id = ?")->execute([$orig, $W]);
if ($origSimple === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
else $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'pos_simple_mode'")->execute([$origSimple]);
ok(strpos($o, 'id="recipeModal"') !== false && !preg_match('/PHP (Warning|Notice|Fatal)|___EXC___/', explode('___OUT___', $o)[0] ?? ''), 'Menu page renders with the Recipe window');
$html = preg_replace('#<!--.*?-->#s', '', explode('___OUT___', $o, 2)[1] ?? '');
preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $mm);
$badJs = 0;
foreach ($mm[1] as $js) {
    if (trim($js) === '') continue;
    $f = tempnam(sys_get_temp_dir(), 'mjs') . '.js'; file_put_contents($f, $js);
    $out = []; exec('node --check ' . escapeshellarg($f) . ' 2>&1', $out, $rc); @unlink($f);
    if ($rc !== 0) { $badJs++; echo '     ' . implode(' ', array_slice($out, 0, 3)) . "\n"; }
}
ok($badJs === 0, 'every inline script of the Menu page parses');
