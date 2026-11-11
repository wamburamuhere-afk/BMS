<?php
/**
 * tests/test_pos_no_price_edit_cli.php — "Edit Price" removed from the till (2026-11-10, owner's decision).
 *
 *   php tests/test_pos_no_price_edit_cli.php
 *
 * A lower price for one customer is a Discount (its own right, pos_discount_override); the price itself is
 * changed where it is stored (the product). Real api/pos/process_sale.php runs in-process inside one
 * rolled-back transaction (tests/helpers/restaurant_inproc.php), as the admin.
 *
 *   A. Till screen: no "Edit Price" button / function / flag; a line saved earlier with a hand-typed price
 *      shows a warning; Swahili for it.
 *   B. Normal sale at the stored price → goes through, line at the stored price, stock −qty.
 *   C. A hand-typed price (lower, or higher) → refused by name, even for the admin; stock untouched.
 *   D. A higher price sent without the flag (stale / crafted cart) → charged the stored price, never more.
 *   E. Discount still works (admin): lower price recorded as a discount, not as a price change.
 *   F. Price tier (price group): the tier's price is charged — not lowered to the base price.
 *   G. Another unit priced on its own (a dozen above 12 × one): charged as set — not lowered.
 *   H. Add-on (modifier +500): base + 500 charged — not lowered, not a discount.
 *   I. Shop batch: editing a batch's price still works, and (as designed) does not change the till price.
 *   J. Roles page: the POS Price Override row is retired (hidden, saved grants kept).
 */
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/role_permission_ui.php";
require_once "$root/core/pos_nav.php";
// Registered before the harness's summary prints: an endpoint that exit()s mid-test must never pass as green.
register_shutdown_function(function () {
    if (empty($GLOBALS['__reached_end'])) { echo "\n  ❌ FAILED: the test stopped before its end (an endpoint exit()ed)\n"; exit(1); }
});

// ─────────────────────────────────────────────────────────────────────────────
section('A. Till screen');
$js = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
ok(strpos($js, 'function editLinePrice(') === false, 'no editLinePrice() on the till');
ok(strpos($js, 'POS_CAN_PRICE_OVERRIDE') === false, 'no POS_CAN_PRICE_OVERRIDE flag');
ok(strpos($js, 'PT.editPriceTitle') === false && strpos($js, 'editPriceTitle:') === false, 'no "Edit Price" label left in the cart');
ok(strpos($js, "item.manual_price_override ? `<br><span class=\"small text-danger pos-hand-price\">") !== false, 'a line saved earlier with a hand-typed price is flagged in the cart');
ok(strpos($js, 'manual_price_override = true') === false, 'nothing on the till can set a hand-typed price any more');
$sw = require "$root/lang/sw.php";
ok(($sw['Price typed by hand — remove and add again'] ?? '') !== '', 'warning translated to Swahili');
$pos = file_get_contents("$root/app/bms/pos/pos.php");
ok(strpos($pos, "canEdit('pos_discount_override')") !== false, 'Discount button still gated by its own right (unchanged)');

// ─────────────────────────────────────────────────────────────────────────────
$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-NOPRICE', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $mk = function (string $name, float $price, float $stock = 50) use ($pdo, $W, $sfx) {
        $pdo->prepare("INSERT INTO products (product_name, sku, unit, selling_price, min_selling_price, cost_price, purchase_price, is_service, status, stock_quantity, current_stock)
                       VALUES (?, ?, 'pcs', ?, 0, ?, ?, 0, 'active', ?, ?)")
            ->execute(["$name $sfx", 'NP-' . strtoupper(substr(preg_replace('/\W/', '', $name), 0, 4)) . "-$sfx", $price, $price / 2, $price / 2, $stock, $stock]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, ?, 0)")->execute([$id, $W, $stock]);
        return $id;
    };
    $EGGS = $mk('Eggs', 350);
    $stock = fn($pid) => (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$pid, $W]);
    $line  = fn($pid, $q, $p, $extra = []) => array_merge(['product_id' => $pid, 'product_name' => 'x', 'quantity' => $q, 'price' => $p, 'discounted_price' => $p, 'tax_rate' => 0], $extra);
    $sell  = function (array $its, array $extra = []) use ($W) {
        $t = array_sum(array_map(fn($i) => (float)$i['discounted_price'] * (float)$i['quantity'], $its));
        return call('api/pos/process_sale.php', 'POST', array_merge(['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $its, 'subtotal' => $t,
            'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $t, 'payment_method' => 'cash', 'amount_tendered' => $t,
            'change_given' => 0, 'amount_paid' => $t], $extra), true);
    };
    $saleLine = function (int $sid, int $pid): array {
        global $pdo;
        $s = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ? AND product_id = ?");
        $s->execute([$sid, $pid]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // ─────────────────────────────────────────────────────────────────────────
    section('B. Normal sale at the stored price');
    $s = $sell([$line($EGGS, 2, 350)]);
    ok(!empty($s['success']), 'sale goes through' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    $li = $saleLine((int)($s['sale_id'] ?? 0), $EGGS);
    ok(abs((float)($li['unit_price'] ?? 0) - 350) < 0.01 && abs((float)($li['line_total'] ?? 0) - 700) < 0.01, 'line: 2 × 350 = 700');
    ok(abs($stock($EGGS) - 48) < 0.0001, 'stock 50 → 48');

    // ─────────────────────────────────────────────────────────────────────────
    section('C. A hand-typed price is refused — admin included');
    $before = $stock($EGGS);
    ok(!empty($_SESSION['is_admin']), 'running as the admin (admins used to see "Edit Price")');
    $r = $sell([$line($EGGS, 1, 200, ['manual_price_override' => true])]);
    ok(empty($r['success']) && str_contains((string)($r['message'] ?? ''), 'typed by hand') && str_contains((string)($r['message'] ?? ''), "Eggs $sfx"),
       'lower hand-typed price refused, by name (' . ($r['message'] ?? '') . ')');
    $r = $sell([$line($EGGS, 1, 500, ['manual_price_override' => true])]);
    ok(empty($r['success']), 'higher hand-typed price refused too');
    $r = $sell([$line($EGGS, 1, 350), $line($EGGS, 1, 100, ['manual_price_override' => true])]);
    ok(empty($r['success']), 'a cart with one hand-typed line is refused as a whole');
    ok(abs($stock($EGGS) - $before) < 0.0001, 'refused sales left stock untouched');

    // ─────────────────────────────────────────────────────────────────────────
    section('D. A higher price without the flag (stale / crafted cart) → the stored price, never more');
    $s = $sell([$line($EGGS, 2, 500)]);
    ok(!empty($s['success']), 'sale goes through' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    $sid = (int)($s['sale_id'] ?? 0);
    $li = $saleLine($sid, $EGGS);
    ok(abs((float)($li['line_total'] ?? 0) - 700) < 0.01, 'charged 2 × 350 = 700, not 2 × 500 (got ' . ($li['line_total'] ?? '?') . ')');
    ok(abs((float)val("SELECT grand_total FROM pos_sales WHERE sale_id = ?", [$sid]) - 700) < 0.01, 'sale total 700');
    ok(abs((float)val("SELECT COALESCE(discount_amount,0) FROM pos_sales WHERE sale_id = ?", [$sid])) < 0.01, 'no negative "discount" recorded');

    // ─────────────────────────────────────────────────────────────────────────
    section('E. Discount still works');
    $s = $sell([$line($EGGS, 1, 350, ['discounted_price' => 300, 'discount_percent' => 0])]);
    ok(!empty($s['success']), 'admin gives 50 off one egg' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    $sid = (int)($s['sale_id'] ?? 0);
    $li = $saleLine($sid, $EGGS);
    ok(abs((float)($li['line_total'] ?? 0) - 300) < 0.01, 'line charged 300');
    ok(abs((float)val("SELECT COALESCE(discount_amount,0) FROM pos_sales WHERE sale_id = ?", [$sid]) - 50) < 0.01, 'recorded as a 50 discount (the price stays 350)');
    ok(abs((float)val("SELECT selling_price FROM products WHERE product_id = ?", [$EGGS]) - 350) < 0.01, "the product's price is still 350 for the next customer");

    // ─────────────────────────────────────────────────────────────────────────
    section('F. Price tier (price group)');
    $GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true, 'pos_advanced' => true];
    $pdo->prepare("INSERT INTO price_groups (name, status, created_by, created_at) VALUES (?, 'active', ?, NOW())")->execute(["ZZ tier $sfx", $admin]);
    $GID = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_price_group_prices (product_id, price_group_id, price) VALUES (?, ?, 420)")->execute([$EGGS, $GID]);
    $s = $sell([$line($EGGS, 1, 420)], ['price_group_id' => $GID]);
    ok(!empty($s['success']), 'sale at the tier price goes through' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    $li = $saleLine((int)($s['sale_id'] ?? 0), $EGGS);
    ok(abs((float)($li['line_total'] ?? 0) - 420) < 0.01, 'tier price 420 charged — not lowered to 350 (got ' . ($li['line_total'] ?? '?') . ')');
    $s = $sell([$line($EGGS, 1, 999)], ['price_group_id' => $GID]);
    $li = $saleLine((int)($s['sale_id'] ?? 0), $EGGS);
    ok(!empty($s['success']) && abs((float)($li['line_total'] ?? 0) - 420) < 0.01, 'above the tier price → the tier price, never more');
    $GLOBALS['__bms_features'] = ['pos' => true, 'restaurant_pos' => true];

    // ─────────────────────────────────────────────────────────────────────────
    section('G. Another unit priced on its own');
    $pdo->prepare("INSERT INTO product_unit_conversions (product_id, unit_label, base_unit_multiplier, unit_price_override) VALUES (?, 'Dozen', 12, 4500)")->execute([$EGGS]);
    $s = $sell([$line($EGGS, 1, 4500, ['unit_label' => 'Dozen'])]);
    ok(!empty($s['success']), 'sale of 1 dozen at 4,500 goes through' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    $li = $saleLine((int)($s['sale_id'] ?? 0), $EGGS);
    ok(abs((float)($li['line_total'] ?? 0) - 4500) < 0.01, 'a dozen charged 4,500 as set (12 × 350 = 4,200 would be wrong) (got ' . ($li['line_total'] ?? '?') . ')');

    // ─────────────────────────────────────────────────────────────────────────
    section('H. Add-on (+500)');
    $pdo->prepare("INSERT INTO modifier_groups (name, status, created_by, created_at) VALUES (?, 'active', ?, NOW())")->execute(["ZZ extras $sfx", $admin]);
    $MG = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO modifier_options (group_id, name, price_adjustment, status) VALUES (?, 'Extra cheese', 500, 'active')")->execute([$MG]);
    $OPT = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO product_modifier_groups (product_id, group_id) VALUES (?, ?)")->execute([$EGGS, $MG]);
    $mods = ['modifiers' => [['group_id' => $MG, 'option_id' => $OPT, 'option_name' => 'Extra cheese', 'price_adjustment' => 500]]];
    $s = $sell([$line($EGGS, 1, 850, $mods)]);
    ok(!empty($s['success']), 'sale with the add-on goes through' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    $sid = (int)($s['sale_id'] ?? 0);
    $li = $saleLine($sid, $EGGS);
    ok(abs((float)($li['line_total'] ?? 0) - 850) < 0.01, '350 + 500 = 850 charged (got ' . ($li['line_total'] ?? '?') . ')');
    ok(abs((float)val("SELECT COALESCE(discount_amount,0) FROM pos_sales WHERE sale_id = ?", [$sid])) < 0.01, 'the add-on is not counted as a discount');
    $s = $sell([$line($EGGS, 1, 2000, $mods)]);
    $li = $saleLine((int)($s['sale_id'] ?? 0), $EGGS);
    ok(!empty($s['success']) && abs((float)($li['line_total'] ?? 0) - 850) < 0.01, 'above price + add-on → 850, never more');

    // ─────────────────────────────────────────────────────────────────────────
    section('J. Roles page');
    $savedF = $GLOBALS['__bms_features'];
    $GLOBALS['__bms_features'] = array_fill_keys(allFeatureKeys(), true);
    $m = loadRolePermissionMatrix($pdo);
    $id = (int)val("SELECT permission_id FROM permissions WHERE page_key = 'pos_price_override'");
    ok(in_array('pos_price_override', rolePermissionRetiredKeys(), true), 'POS Price Override is retired');
    ok($id === 0 || !in_array('pos_price_override', array_column($m['visible'], 'page_key'), true), 'not shown on the Roles page');
    ok($id === 0 || in_array($id, $m['preserved_ids'], true), 'saved ticks on it are kept (preserved), not deleted');
    ok(in_array('pos_discount_override', array_column($m['visible'], 'page_key'), true), 'POS Discount Override still shown');
    $GLOBALS['__bms_features'] = $savedF;

    // ─────────────────────────────────────────────────────────────────────────
    // Last: the batch endpoint exit()s when Smart POS is off, which would end this process.
    section('I. Shop batch price');
    if (!posSimpleModeEnabled()) {
        echo "  SKIPPED — Smart POS is off on this database, and the shop batch editor only exists in Smart POS.\n";
        echo "            (This change does not touch it; tests/test_warehouse_batches_tab_cli.php covers it.)\n";
        throw new RuntimeException('__skip_batch__');
    }
    $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, batch_number, quantity_received, quantity_remaining, unit_cost, selling_price, created_at)
                   VALUES (?, ?, ?, 10, 10, 175, 350, NOW())")->execute([$EGGS, $W, "B-$sfx"]);
    $BID = (int)$pdo->lastInsertId();
    $r = call('api/stock/update_warehouse_batch.php', 'POST', ['batch_id' => $BID, 'warehouse_id' => $W, 'batch_number' => "B-$sfx",
        'unit_cost' => 175, 'wholesale_price' => '', 'selling_price' => 380]);
    ok(!empty($r['success']), 'editing the batch price in the shop still works' . (!empty($r['success']) ? '' : ' — ' . ($r['message'] ?? '')));
    ok(abs((float)val("SELECT selling_price FROM product_batches WHERE batch_id = ?", [$BID]) - 380) < 0.01, 'batch price saved (380)');
    ok(abs((float)val("SELECT selling_price FROM products WHERE product_id = ?", [$EGGS]) - 350) < 0.01, 'as designed, a batch price is history only: the till still sells at the product price (350)');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== '__skip_batch__') throw $e;
} finally {
    $pdo->endOuter();
}
echo "\n  (all sections ran to the end)\n";
$GLOBALS['__reached_end'] = true;
