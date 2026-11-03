<?php
/**
 * tests/test_pos_decimal_qty_cli.php — POS sells goods by measure in parts (2026-11-03).
 *
 *   A. core/pos_quantity.php rules: kg/litre/metre (+ Swahili spellings) allow decimals,
 *      pcs/bottle/box don't; 0, negative, empty and text are refused; 3 decimal places.
 *   B. A real POS sale of 0.5 kg sugar at 3,000/kg (api/pos/process_sale.php):
 *      line = 1,500, unit price stays 3,000, warehouse stock −0.5 exactly, the product's own
 *      stock copy −0.5 exactly, stock movement 0.5, cost of goods = 0.5 × cost in the ledger.
 *      0.125 kg is kept exactly (3 decimals), not rounded to 0.13.
 *   C. The server refuses 0.5 of a "pcs" item, 0 and −1 (−1 used to push stock UP), and a
 *      refused sale leaves stock untouched. Whole-number sales are unchanged.
 *   D. A return of 0.25 kg brings back exactly 0.25; returning 0.5 of a pcs line is refused.
 *   E. Screens: POS script carries the same unit list + no whole-number rounding left;
 *      Products page shows stock without ".000".
 *
 * Runs in-process inside one rolled-back transaction. Exit 0 = all pass.
 */
require_once __DIR__ . '/helpers/restaurant_inproc.php';
require_once "$root/core/pos_quantity.php";

section('A. Which quantities may have decimals');
foreach (['kg', 'KG', 'Kg.', ' kilo ', 'litre', 'ltr', 'ml', 'g', 'metre', 'lita', 'mita', 'gramu'] as $u) ok(posUnitAllowsDecimal($u), "\"$u\" may be sold in parts");
foreach (['pcs', 'bottle', 'box', 'pack', 'job', '', null] as $u) ok(!posUnitAllowsDecimal($u), '"' . var_export($u, true) . '" stays whole numbers');
ok(posValidateLineQuantity('0.5', 'kg', 'Sugar') === 0.5, '0.5 kg accepted');
ok(posValidateLineQuantity(0.12549, 'kg', 'Sugar') === 0.125, 'kept to 3 decimal places (0.12549 → 0.125)');
ok(posValidateLineQuantity('2', 'pcs', 'Soap') === 2.0, '2 pcs accepted');
ok(posValidateLineQuantity('2.000', 'pcs', 'Soap') === 2.0, '"2.000" pcs accepted (a whole number)');
ok(is_string(posValidateLineQuantity('0.5', 'pcs', 'Soap')), '0.5 pcs refused');
foreach (['0', '-1', '', 'abc', null] as $bad) ok(is_string(posValidateLineQuantity($bad, 'kg', 'Sugar')), 'quantity ' . var_export($bad, true) . ' refused');

$pdo->startOuter();
try {
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, register_id, warehouse_id, start_time, starting_cash, status) VALUES ('TEST-DECQ', ?, 1, ?, NOW() - INTERVAL 1 HOUR, 0, 'active')")->execute([$admin, $W]);
    // A plain retail shop for this test (rolled back with everything else): the shared dev DB may
    // have every shop in Restaurant mode, which shows only menu items in the POS product list.
    $pdo->prepare("UPDATE warehouses SET pos_mode = 'retail' WHERE warehouse_id = ?")->execute([$W]);
    $sfx = substr(bin2hex(random_bytes(3)), 0, 6);
    $mk = function (string $name, string $unit, float $price, float $cost, float $stock) use ($pdo, $W, $sfx) {
        $pdo->prepare("INSERT INTO products (product_name, sku, unit, selling_price, min_selling_price, cost_price, purchase_price, is_service, status, stock_quantity, current_stock)
                       VALUES (?, ?, ?, ?, 0, ?, ?, 0, 'active', ?, ?)")
            ->execute(["$name $sfx", 'DQ-' . strtoupper(substr($name, 0, 3)) . "-$sfx", $unit, $price, $cost, $cost, $stock, $stock]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO product_stocks (product_id, warehouse_id, stock_quantity, reserved_quantity) VALUES (?, ?, ?, 0)")->execute([$id, $W, $stock]);
        return $id;
    };
    $SUGAR = $mk('Sugar', 'kg', 3000, 2000, 10);
    $SOAP  = $mk('Soap', 'pcs', 1000, 600, 10);

    $stock = fn($pid) => (float)val("SELECT stock_quantity FROM product_stocks WHERE product_id = ? AND warehouse_id = ?", [$pid, $W]);
    $copy  = fn($pid) => (float)val("SELECT stock_quantity FROM products WHERE product_id = ?", [$pid]);
    $copy2 = fn($pid) => (float)val("SELECT current_stock FROM products WHERE product_id = ?", [$pid]);
    $line = fn($pid, $q, $p) => ['product_id' => $pid, 'product_name' => 'x', 'quantity' => $q, 'price' => $p, 'discounted_price' => $p, 'tax_rate' => 0];
    $sell = function (array $its) use ($W) {
        $t = array_sum(array_map(fn($i) => $i['price'] * (float)$i['quantity'], $its));
        return call('api/pos/process_sale.php', 'POST', ['customer_id' => null, 'warehouse_id' => $W, 'project_id' => null, 'items' => $its, 'subtotal' => $t,
            'discount_percentage' => 0, 'discount_amount' => 0, 'tax' => 0, 'total' => $t, 'payment_method' => 'cash', 'amount_tendered' => $t,
            'change_given' => 0, 'amount_paid' => $t], true);
    };

    section('B. Selling 0.5 kg of sugar at 3,000 per kg');
    $s = $sell([$line($SUGAR, '0.5', 3000)]);
    ok(!empty($s['success']), 'sale of 0.5 kg goes through' . (!empty($s['success']) ? '' : ' — ' . ($s['message'] ?? '')));
    $sid = (int)($s['sale_id'] ?? 0);
    $li = $pdo->prepare("SELECT * FROM pos_sale_items WHERE sale_id = ? AND product_id = ?"); $li->execute([$sid, $SUGAR]); $li = $li->fetch(PDO::FETCH_ASSOC) ?: [];
    ok(abs((float)($li['quantity'] ?? 0) - 0.5) < 0.0001, 'sale line keeps 0.5');
    ok(abs((float)($li['unit_price'] ?? 0) - 3000) < 0.01, 'price stays 3,000 per kg');
    ok(abs((float)($li['line_total'] ?? 0) - 1500) < 0.01, 'line total 1,500');
    ok(abs((float)val("SELECT grand_total FROM pos_sales WHERE sale_id = ?", [$sid]) - 1500) < 0.01, 'sale total 1,500');
    ok(abs($stock($SUGAR) - 9.5) < 0.0001, 'shop stock 10 → 9.5 (down exactly 0.5)');
    ok(abs($copy($SUGAR) - 9.5) < 0.0001 && abs($copy2($SUGAR) - 9.5) < 0.0001, "product's own stock copy also 9.5");
    ok(abs((float)val("SELECT SUM(quantity) FROM stock_movements WHERE reference_type = 'pos_sale' AND reference_id = ? AND product_id = ?", [$sid, $SUGAR]) - 0.5) < 0.0001, 'stock movement records 0.5');
    $cogs = (float)val("SELECT SUM(jei.amount) FROM journal_entries je JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                         WHERE je.status = 'posted' AND je.entity_type = 'pos_cogs' AND je.entity_id = ? AND jei.type = 'debit'", [$sid]);
    ok(abs($cogs - 1000) < 0.01, 'ledger cost of goods = 0.5 × 2,000 = 1,000 (got ' . $cogs . ')');

    $s2 = $sell([$line($SUGAR, 0.125, 3000)]);
    ok(!empty($s2['success']), 'sale of 0.125 kg goes through');
    ok(abs($stock($SUGAR) - 9.375) < 0.0001 && abs($copy($SUGAR) - 9.375) < 0.0001, 'stock 9.5 → 9.375 exactly, in both places (not rounded to 0.13)');
    ok(abs((float)val("SELECT line_total FROM pos_sale_items WHERE sale_id = ?", [(int)($s2['sale_id'] ?? 0)]) - 375) < 0.01, '0.125 × 3,000 = 375');

    section('C. What the server refuses');
    $before = [$stock($SOAP), $stock($SUGAR)];
    $r = $sell([$line($SOAP, 0.5, 1000)]);
    ok(empty($r['success']) && stripos((string)($r['message'] ?? ''), 'whole numbers') !== false, '0.5 of a pcs item refused (' . ($r['message'] ?? '') . ')');
    $r = $sell([$line($SUGAR, 0, 3000)]);
    ok(empty($r['success']), 'quantity 0 refused');
    $r = $sell([$line($SUGAR, -1, 3000)]);
    ok(empty($r['success']), 'quantity −1 refused (it used to push stock up)');
    $r = $sell([$line($SUGAR, 1, 3000), $line($SOAP, 1.5, 1000)]);
    ok(empty($r['success']), 'a cart with one bad line is refused as a whole');
    ok([$stock($SOAP), $stock($SUGAR)] == $before, 'refused sales left stock untouched');
    $r = $sell([$line($SOAP, 2, 1000)]);
    ok(!empty($r['success']) && abs($stock($SOAP) - 8) < 0.0001, 'whole-number sale unchanged: 2 pcs, stock 10 → 8');
    $soapSale = (int)($r['sale_id'] ?? 0);

    section('D. Returns');
    $sugarLine = (int)($li['sale_item_id'] ?? 0);
    $rt = call('api/pos/create_return.php', 'POST', ['original_sale_id' => $sid, 'reason' => 'ZZ DECQ', 'refund_method' => 'cash',
        'items' => json_encode([['sale_item_id' => $sugarLine, 'return_qty' => 0.25]])]);
    ok(!empty($rt['success']), 'return of 0.25 kg goes through' . (!empty($rt['success']) ? '' : ' — ' . ($rt['message'] ?? '')));
    ok(abs($stock($SUGAR) - 9.625) < 0.0001 && abs($copy($SUGAR) - 9.625) < 0.0001, 'stock 9.375 → 9.625 (exactly 0.25 back)');
    $soapLine = (int)val("SELECT sale_item_id FROM pos_sale_items WHERE sale_id = ?", [$soapSale]);
    $rt = call('api/pos/create_return.php', 'POST', ['original_sale_id' => $soapSale, 'reason' => 'ZZ DECQ', 'refund_method' => 'cash',
        'items' => json_encode([['sale_item_id' => $soapLine, 'return_qty' => 0.5]])]);
    ok(empty($rt['success']) && abs($stock($SOAP) - 8) < 0.0001, 'returning 0.5 of a pcs line refused, stock unchanged');
    $rt = call('api/pos/create_return.php', 'POST', ['original_sale_id' => $soapSale, 'reason' => 'ZZ DECQ', 'refund_method' => 'cash',
        'items' => json_encode([['sale_item_id' => $soapLine, 'return_qty' => 1]])]);
    ok(!empty($rt['success']) && abs($stock($SOAP) - 9) < 0.0001, 'returning 1 pcs still works');

    section('E. POS product list carries the unit');
    $pl = call('api/pos/simple_products.php', 'GET', ['search' => "Sugar $sfx", 'warehouse_id' => $W]);
    $hit = null; foreach (($pl['data'] ?? []) as $p) if ((int)$p['product_id'] === $SUGAR) $hit = $p;
    ok($hit !== null && ($hit["unit"] ?? null) === "kg", "simple_products.php sends unit \"kg\"" . ($hit !== null ? "" : " — " . substr(json_encode($pl), 0, 300)));
} finally {
    $pdo->endOuter();
}

section('F. Screens');
$js = file_get_contents("$root/app/bms/pos/pos_scripts_new.php");
ok(strpos($js, 'const POS_DECIMAL_UNITS = <?= json_encode(posDecimalUnits()) ?>') !== false, 'POS screen uses the same unit list as the server');
ok(strpos($js, "parseInt($('#quickViewQty')") === false && strpos($js, 'const quantity = parseInt(value)') === false && strpos($js, 'var q=parseInt(this.value,10)') === false, 'no quantity box rounds to a whole number any more');
ok(substr_count($js, 'posRoundQty(existing.quantity + 1)') === 2, '+1 from a tile / a scan stays exact (0.5 → 1.5)');
ok(strpos($js, 'const newQuantity = posRoundQty(cart[index].quantity + change);') !== false && strpos($js, 'if (newQuantity > 0)') !== false, '+ / − move by 1 and never reach 0');
$pp = file_get_contents("$root/app/bms/product/products.php");
ok(substr_count($pp, "\$available_stock = format_qty(\$product['available_stock'])") === 2, 'Products page: table and phone cards show stock without ".000"');
ok(strpos($pp, 'toFixed(3)') === false && strpos($pp, 'bmsQtyPlain(product.total_stock)') !== false, 'Products page: stock pop-up without ".000"');
ok(format_qty('12.000') === '12' && format_qty('2.500') === '2.5' && format_qty('0.125') === '0.125' && format_qty('1500.000') === '1,500', 'format_qty: 12.000 → 12, 2.500 → 2.5, 0.125 → 0.125, 1500 → 1,500');

section('G. The real cart code, run in Node');
$src = str_replace("\r\n", "\n", $js);
preg_match('/function posUnitAllowsDecimal\(.*?\nfunction posReadTypedQty\(.*?\n}\n/s', $src, $mH);
preg_match('/function updateCartQuantity\(index, change\) \{.*?\n}\n/s', $src, $mU);
preg_match('/function updateCartQuantityInput\(index, value\) \{.*?\n}\n/s', $src, $mI);
preg_match('/function adjustQuantity\(amount\) \{.*?\n}\n/s', $src, $mA);
ok(!empty($mH) && !empty($mU) && !empty($mI) && !empty($mA), 'cart functions found in pos_scripts_new.php');
$node = trim((string)shell_exec('node -v 2>&1'));
if (!preg_match('/^v\d+/', $node)) {
    ok(false, 'node available');
} else {
    $harness = 'const POS_DECIMAL_UNITS = ' . json_encode(posDecimalUnits()) . ";\n"
        . "const PT = { wholeNumbersOnly: '%s whole' }; const toasts = []; const Swal = { fire: o => toasts.push(o.titleText) };\n"
        . "const caseFormatJs = s => s; const updateCartDisplay = () => {}; const saveCartToStorage = () => {};\n"
        . "let qv = '1'; const $ = () => ({ val: v => (v === undefined ? qv : (qv = String(v))) });\n"
        . "let cart = [];\n"
        . $mH[0] . $mU[0] . $mI[0] . $mA[0] . <<<'JS'
const o = {};
cart = [{ product_name: 'Sugar', unit: 'kg', price: 3000, discounted_price: 3000, quantity: 1 },
        { product_name: 'Soap', unit: 'pcs', price: 1000, discounted_price: 1000, quantity: 1 },
        { product_name: 'Phone', unit: 'kg', price: 9, discounted_price: 9, quantity: 1, serial_numbers: ['A'] }];
updateCartQuantityInput(0, '0.5');          o.typed = cart[0].quantity;
o.total = Math.round(cart[0].discounted_price * cart[0].quantity * 100) / 100;
updateCartQuantity(0, 1);                    o.plus = cart[0].quantity;
updateCartQuantity(0, -1);                   o.minus = cart[0].quantity;
updateCartQuantity(0, -1);                   o.minusAtPart = cart[0].quantity;
updateCartQuantityInput(0, '0,25');          o.comma = cart[0].quantity;
updateCartQuantityInput(0, '0.12549');       o.round3 = cart[0].quantity;
for (let i = 0; i < 10; i++) updateCartQuantity(0, 1);  o.manyPlus = cart[0].quantity;
updateCartQuantityInput(0, '0');             o.zeroKept = cart[0].quantity;
updateCartQuantityInput(0, '-2');            o.negKept = cart[0].quantity;
updateCartQuantityInput(0, '');              o.emptyKept = cart[0].quantity;
updateCartQuantityInput(1, '1.5');           o.pcsFraction = cart[1].quantity; o.toast = toasts.length;
updateCartQuantityInput(1, '3');             o.pcsWhole = cart[1].quantity;
updateCartQuantity(1, -1); updateCartQuantity(1, -1); updateCartQuantity(1, -1); o.pcsFloor = cart[1].quantity;
o.serialNoPart = posLineAllowsDecimal(cart[2]);
qv = '0.5'; adjustQuantity(1); o.popPlus = qv; adjustQuantity(-1); o.popMinus = qv; adjustQuantity(-1); o.popFloor = qv;
o.fmt = [posFmtQty(1500), posFmtQty(0.5), posFmtQty(2.000)];
console.log(JSON.stringify(o));
JS;
    $tf = tempnam(sys_get_temp_dir(), 'dq') . '.js'; file_put_contents($tf, $harness);
    $raw = (string)shell_exec('node ' . escapeshellarg($tf) . ' 2>&1'); @unlink($tf);
    $o = json_decode($raw, true);
    ok(is_array($o), 'node ran the cart code' . (is_array($o) ? '' : ' — ' . substr($raw, 0, 300)));
    if (is_array($o)) {
        ok($o['typed'] === 0.5 && $o['total'] === 1500, 'type 0.5 → quantity 0.5, line 3,000 × 0.5 = 1,500');
        ok($o['plus'] === 1.5 && $o['minus'] === 0.5, '+ → 1.5, − → 0.5 (exactly one each)');
        ok($o['minusAtPart'] === 0.5, '− at 0.5 stays 0.5 (never 0 or below)');
        ok($o['comma'] === 0.25, '"0,25" (comma) read as 0.25');
        ok($o['round3'] === 0.125, '0.12549 kept to 3 places → 0.125');
        ok($o['manyPlus'] === 10.125, 'ten × + → 10.125 exactly (no 10.124999)');
        ok($o['zeroKept'] === 10.125 && $o['negKept'] === 10.125 && $o['emptyKept'] === 10.125, '0, −2 and empty put the old quantity back');
        ok($o['pcsFraction'] === 1 && $o['toast'] === 1, '1.5 for a pcs item refused with a note, quantity stays 1');
        ok($o['pcsWhole'] === 3 && $o['pcsFloor'] === 1, 'pcs: typing 3 works; − stops at 1 as before');
        ok($o['serialNoPart'] === false, 'serial-tracked line never takes a part');
        ok($o['popPlus'] === '1.5' && $o['popMinus'] === '0.5' && $o['popFloor'] === '0.5', 'product pop-up: 0.5 → + 1.5 → − 0.5, never 0');
        ok($o['fmt'] === ['1,500', '0.5', '2'], 'quantities shown as 1,500 / 0.5 / 2');
    }
}
