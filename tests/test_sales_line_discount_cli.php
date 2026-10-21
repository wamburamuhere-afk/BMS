<?php
/**
 * Sales documents — service lines + discount as % or a fixed amount (2026-10-21)
 *   php tests/test_sales_line_discount_cli.php
 *
 * A: pure maths (core/sales_line_discount.php) + static wiring (no database — runs in CI)
 * B: the real save endpoints, edit pages, prints, quote → order → invoice and the
 *    ledger posting, on the local DB. Everything created is named/linked 'SLD-TEST'
 *    and removed (incl. its ledger entries). Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
if (is_file("$root/includes/config.php")) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
if (!function_exists('get_setting')) { function get_setting($k, $d = '') { return $d; } }
require_once "$root/core/sales_line_discount.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
function throwsMsg(callable $fn): string { try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); } }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

// ── A ──────────────────────────────────────────────────────────────────────
section('A1. line maths');
$m = salesLineMath(['quantity' => 1, 'unit_price' => 894000, 'tax_rate' => 0, 'discount_type' => 'amount', 'discount_value' => 10000]);
ok($m['line_discount'] === 10000.0 && $m['line_total'] === 884000.0, 'fixed 10,000 off 894,000 → exactly 884,000');
ok($m['discount_type'] === 'amount' && $m['discount_percent'] === 1.12, 'its % is kept for display only (1.12)');
$m = salesLineMath(['quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 18, 'discount_type' => 'amount', 'discount_value' => 10000]);
ok($m['line_discount'] === 10000.0 && abs($m['line_tax'] - 16200) < 0.001 && abs($m['line_total'] - 106200) < 0.001, 'VAT is charged after a fixed discount (100,000 − 10,000 + 18% = 106,200)');
$m = salesLineMath(['quantity' => 1, 'unit_price' => 894000, 'tax_rate' => 0, 'discount_type' => 'percent', 'discount_value' => 10]);
ok($m['line_discount'] === 89400.0 && $m['line_total'] === 804600.0, '10% still works as before');
$m = salesLineMath(['quantity' => 3, 'unit_price' => 1000, 'tax_rate' => 0, 'discount_percent' => 5]);
ok($m['discount_type'] === 'percent' && $m['line_discount'] === 150.0, 'an older caller sending only discount_percent is read as %');
$m = salesLineMath(['quantity' => 1, 'unit_price' => 1000, 'discount_type' => 'percent', 'discount_value' => 150]);
ok($m['line_discount'] === 1000.0, 'a % above 100 is capped at 100');
$m = salesLineMath(['quantity' => 1, 'unit_price' => 5000, 'discount_type' => 'amount', 'discount_value' => '1,500']);
ok($m['line_discount'] === 1500.0, 'an amount typed with a thousands comma is read correctly');
ok(stripos(throwsMsg(fn() => salesLineMath(['quantity' => 1, 'unit_price' => 1000, 'discount_type' => 'amount', 'discount_value' => 1000.5], 'X')), 'more than the line amount') !== false,
   'a fixed discount above the line is refused');
ok(stripos(throwsMsg(fn() => salesLineMath(['quantity' => 1, 'unit_price' => 1000, 'discount_type' => 'amount', 'discount_value' => -1], 'X')), 'negative') !== false,
   'a negative discount is refused');
ok(throwsMsg(fn() => salesLineMath(['quantity' => 1, 'unit_price' => 1000, 'discount_type' => 'amount', 'discount_value' => 1000])) === '', 'a discount of the whole line is allowed');
ok(salesDiscountValueOf(['discount_type' => 'amount', 'discount_amount' => '10000.00', 'discount_percent' => '1.12']) === 10000.0
   && salesDiscountValueOf(['discount_type' => 'percent', 'discount_amount' => '89400', 'discount_percent' => '10.00']) === 10.0,
   'a saved line reopens with what was typed (the amount, or the %)');

section('A2. wiring');
$forms = ['app/bms/sales/quotations/quotation_form.php', 'app/bms/sales/sales_order_create.php', 'app/bms/sales/sales_order_edit.php',
          'app/bms/invoice/invoice_create.php', 'app/bms/invoice/invoice_edit.php'];
foreach ($forms as $f) {
    $src = file_get_contents("$root/$f");
    ok(strpos($src, "includes/sales_line_extras_js.php") !== false && strpos($src, 'BmsLineDiscount.enhance(') !== false
       && strpos($src, 'BmsLineDiscount.amount(') !== false && strpos($src, 'BmsLineDesc.show(') !== false
       && preg_match('/<textarea class="form-control[^"]*item-description/', $src) === 1,
       "$f: % / amount discount + visible description on service lines");
}
foreach (['api/account/save_quotation.php' => 'quotation_items', 'api/account/save_sales_order.php' => 'sales_order_items', 'api/account/save_invoice.php' => 'invoice_items'] as $f => $t) {
    $src = file_get_contents("$root/$f");
    ok(strpos($src, 'salesLineMath(') !== false && strpos($src, "salesStampLineDiscount(\$pdo, '$t'") !== false
       && strpos($src, "* (\$discount_percent / 100)") === false, "$f: every line through salesLineMath(), type + amount recorded");
}
ok(strpos(file_get_contents("$root/api/account/save_invoice.php"), 'soLineDiscountFor(') !== false, 'save_invoice.php: a sales-order line keeps the order\'s discount');
$prints = array_merge(glob("$root/app/bms/sales/quotations/print_quotation*.php"), glob("$root/app/bms/sales/print_sales_order*.php"), glob("$root/app/bms/invoice/invoice_print*.php"));
ok(count($prints) === 12, '12 print designs found');
foreach ($prints as $p) {
    $src = file_get_contents($p);
    ok(strpos($src, "\$svcDesc = trim((string)(\$item['description'] ?? '')) !== '' ? (string)\$item['description'] : (string)(\$item['svc_description'] ?? '');") !== false
       && strpos($src, "empty(\$item['price_term_label'])") !== false && stripos($src, 'Discount') !== false,
       basename($p) . ': line description first, no "1 Months", discount row');
}
$ss = file_get_contents("$root/app/constant/settings/system_settings.php");
ok(strpos($ss, "'sales_discount_type'") !== false && strpos($ss, "'sales_discount_allow_switch'") !== false && strpos($ss, 'name="sales_discount_type"') !== false,
   'System Settings: default discount type + allow-switch, saved with General');

if (!is_file("$root/includes/config.php")) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/service_price_terms.php";
require_once "$root/core/sales_invoicing.php";
require_once "$root/core/revenue_posting.php";
sptEnsureSchema($pdo);
ob_start(); sptEnsureLineColumns($pdo); salesEnsureDiscountColumns($pdo); ob_end_clean();

$uid  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid  = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$cust = (int)$pdo->query("SELECT customer_id FROM customers WHERE status = 'active' ORDER BY customer_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid || !$cust) { echo "SKIP B: needs an admin, a warehouse and a customer\n"; exit; }

$savedSettings = [];
foreach (['sales_discount_type', 'sales_discount_allow_switch'] as $k) {
    $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $s->execute([$k]);
    $savedSettings[$k] = $s->fetchColumn();
}
$made = ['qt' => [], 'so' => [], 'inv' => []];
$cleanup = function () use ($pdo, &$made, $savedSettings) {
    $in = fn(array $a) => $a ? implode(',', array_map('intval', $a)) : '0';
    $made['inv'] = array_merge($made['inv'], $pdo->query("SELECT invoice_id FROM invoices WHERE notes = 'SLD-TEST'")->fetchAll(PDO::FETCH_COLUMN));
    $made['qt']  = array_merge($made['qt'],  $pdo->query("SELECT sales_order_id FROM quotations WHERE reference = 'SLD-TEST'")->fetchAll(PDO::FETCH_COLUMN));
    $made['so']  = array_merge($made['so'],  $pdo->query("SELECT sales_order_id FROM sales_orders WHERE reference = 'SLD-TEST'")->fetchAll(PDO::FETCH_COLUMN));
    $inv = $in($made['inv']); $so = $in($made['so']); $qt = $in($made['qt']);
    $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE je.entity_type IN ('invoice','invoice_cogs','invoice_void') AND je.entity_id IN ($inv)");
    $pdo->exec("DELETE FROM journal_entries WHERE entity_type IN ('invoice','invoice_cogs','invoice_void') AND entity_id IN ($inv)");
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ($inv)");
    $pdo->exec("DELETE FROM sales_order_items WHERE order_id IN ($so)");
    $pdo->exec("DELETE FROM sales_orders WHERE sales_order_id IN ($so)");
    $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ($qt)");
    $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ($qt)");
    try {
        $pdo->exec("DELETE FROM workflow_signatures WHERE (entity_type='invoice' AND entity_id IN ($inv))
                     OR (entity_type='sales_order' AND entity_id IN ($so)) OR (entity_type='quotation' AND entity_id IN ($qt))");
    } catch (Throwable $e) {}
    $ids = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SLD-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $p = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM service_price_terms WHERE product_id IN ($p)");
        try { $pdo->exec("DELETE FROM product_stocks WHERE product_id IN ($p)"); } catch (Throwable $e) {}
        $pdo->exec("DELETE FROM products WHERE product_id IN ($p)");
    }
    foreach ($savedSettings as $k => $v) {
        if ($v === false) $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]);
        else $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
    }
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'sld_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function asAdmin(): string {
    global $uid;
    return "\$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
            \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['username'] = 'test';";
}
function callApi(string $file, array $post): array {
    global $root;
    $out = runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        " . asAdmin() . "
        \$_POST = " . var_export($post, true) . ";
        ob_start();
        include '$root/$file';
        \$o = ob_get_clean();
        \$p = strpos(\$o, '{');
        echo \$p === false ? \$o : substr(\$o, \$p);
    ");
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => $out];
}
/** Render a page as the admin (GET), returning its HTML. */
function renderPage(string $file, array $get): string {
    global $root;
    return runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; \$_SERVER['REQUEST_URI'] = '/$file';
        require '$root/roots.php';
        " . asAdmin() . "
        \$_GET = " . var_export($get, true) . ";
        include '$root/$file';
    ");
}
function rowsOf(string $table, string $fk, int $id): array {
    global $pdo; $s = $pdo->prepare("SELECT * FROM `$table` WHERE `$fk` = ? ORDER BY 1"); $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function headerOf(string $table, string $pk, int $id): array {
    global $pdo; $s = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'Months', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = callApi('api/create_nip_product.php', $svcBase + ['product_name' => 'SLD-TEST PMS', 'description' => 'A property management system for landlords.',
    'price_terms' => json_encode([
        ['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 149000, 'is_default' => 1],
        ['term_label' => '6 Months', 'duration_value' => 6, 'duration_unit' => 'month', 'price' => 894000],
    ])]);
$svc = (int)($r['product_id'] ?? 0);
$r = callApi('api/create_nip_product.php', $svcBase + ['product_name' => 'SLD-TEST Training', 'unit' => 'Days', 'selling_price' => '50000']);
$plain = (int)($r['product_id'] ?? 0);
$terms = [];
foreach (sptForProduct($pdo, $svc) as $t) $terms[$t['term_label']] = (int)$t['term_id'];
if (!$svc || !$plain || count($terms) !== 2) { ok(false, 'test services created — ' . json_encode($r)); exit; }

$line = fn(int $pid, string $name, float $price, array $extra = []) => $extra + [
    'product_id' => $pid, 'product_name' => $name, 'sku' => '', 'quantity' => 1, 'unit' => 'Months', 'unit_price' => $price,
    'tax_rate' => 0, 'discount_type' => 'percent', 'discount_value' => 0, 'description' => '', 'price_term_id' => null];
$qtPost = fn(array $items, int $id = 0, array $extra = []) => $extra + ['quotation_id' => $id ?: '', 'customer_id' => $cust, 'order_date' => date('Y-m-d'),
    'currency' => 'TZS', 'reference' => 'SLD-TEST', 'items' => json_encode($items)];

section('B1. service quotation: plan term + fixed discount + own description');
$r = callApi('api/account/save_quotation.php', $qtPost([
    $line($svc, 'SLD-TEST PMS', 894000, ['price_term_id' => $terms['6 Months'], 'discount_type' => 'amount', 'discount_value' => 10000,
        'description' => "Plan chosen by the customer: Standard\nPaying for six months"]),
]));
$qt = (int)($r['quotation_id'] ?? 0);
if ($qt) $made['qt'][] = $qt;
ok(!empty($r['success']) && $qt > 0, 'saved through the real endpoint (no warehouse)' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$q  = headerOf('quotations', 'sales_order_id', $qt);
$ql = rowsOf('quotation_items', 'order_id', $qt)[0] ?? [];
ok(($ql['discount_type'] ?? '') === 'amount' && (float)$ql['discount_amount'] === 10000.0, 'line: type amount, 10,000.00 stored as typed');
ok((float)$ql['line_total'] === 884000.0, 'line total 884,000.00');
ok((float)$q['subtotal'] === 894000.0 && (float)$q['discount_amount'] === 10000.0 && (float)$q['grand_total'] === 884000.0,
   'header: subtotal 894,000 − discount 10,000 = grand total 884,000');
ok(($ql['description'] ?? '') === "Plan chosen by the customer: Standard\nPaying for six months\nTerm: 6 Months",
   'the user\'s own description is kept, with the term line added once');

section('B2. a fixed discount larger than the line is refused (nothing saved)');
$before = (int)$pdo->query("SELECT COUNT(*) FROM quotations WHERE reference = 'SLD-TEST'")->fetchColumn();
$r = callApi('api/account/save_quotation.php', $qtPost([$line($svc, 'SLD-TEST PMS', 894000, ['price_term_id' => $terms['6 Months'], 'discount_type' => 'amount', 'discount_value' => 900000])]));
$after = (int)$pdo->query("SELECT COUNT(*) FROM quotations WHERE reference = 'SLD-TEST'")->fetchColumn();
ok(empty($r['success']) && stripos($r['message'] ?? '', 'more than the line amount') !== false, 'refused: ' . ($r['message'] ?? ''));
ok($before === $after, 'the whole quotation rolled back');

section('B3. percentage discount unchanged (regression)');
$r = callApi('api/account/save_quotation.php', $qtPost([$line($plain, 'SLD-TEST Training', 50000, ['quantity' => 3, 'discount_type' => 'percent', 'discount_value' => 10, 'tax_rate' => 18])],
    0, ['warehouse_id' => $wid]));
$qtP = (int)($r['quotation_id'] ?? 0);
if ($qtP) $made['qt'][] = $qtP;
$hp = headerOf('quotations', 'sales_order_id', $qtP);
$lp = rowsOf('quotation_items', 'order_id', $qtP)[0] ?? [];
ok(!empty($r['success']) && ($lp['discount_type'] ?? '') === 'percent' && (float)$lp['discount_percent'] === 10.0 && (float)$lp['discount_amount'] === 15000.0,
   '10% of 150,000 → 15,000 (type percent)');
ok((float)$hp['grand_total'] === 159300.0, 'grand total 150,000 − 15,000 + 18% VAT = 159,300');

section('B4. edit page reopens as a service quotation');
$html = renderPage('app/bms/sales/quotations/quotation_edit.php', ['id' => $qt]);
ok(preg_match('/id="is_service_order"[^>]*checked/', $html) === 1, 'the "Service Quotation" switch is on');
ok(preg_match('/<\?php|Fatal error|Parse error/', $html) === 0 && strpos($html, 'toggleServiceOrderMode();') !== false, 'renders cleanly and applies Service mode on load');
ok(strpos($html, '"discount_type":"amount"') !== false && strpos($html, '"discount_value":10000') !== false, 'the line reopens with its 10,000 fixed discount');
ok(strpos($html, 'Plan chosen by the customer') !== false, 'the line reopens with the user\'s description');
ok(strpos($html, 'sales_line_extras.js') !== false && strpos($html, 'window.BMS_SALES_DISCOUNT') !== false, 'discount script + settings loaded');
$htmlP = renderPage('app/bms/sales/quotations/quotation_edit.php', ['id' => $qtP]);
ok(preg_match('/id="is_service_order"[^>]*checked/', $htmlP) === 0, 'a product quotation (with a warehouse) does not switch to Service mode');

section('B5. prints show the description, the term, the discount — never "1 Months"');
foreach (['print_quotation.php', 'print_quotation_meadow.php', 'print_quotation_noir.php', 'print_quotation_terra.php'] as $pf) {
    $p = renderPage("app/bms/sales/quotations/$pf", ['id' => $qt]);
    ok(strpos($p, 'Plan chosen by the customer: Standard') !== false && strpos($p, 'Term: 6 Months') !== false
       && strpos($p, 'Discount') !== false && strpos($p, '10,000.00') !== false && strpos($p, '884,000.00') !== false
       && strpos($p, '1 Months') === false && stripos($p, 'Fatal error') === false, "$pf");
}

section('B6. quotation → sales order keeps type, amount, description');
$pdo->prepare("UPDATE quotations SET status = 'approved' WHERE sales_order_id = ?")->execute([$qt]);
$r = callApi('api/account/convert_quote_to_order.php', ['quotation_id' => $qt, 'id' => $qt]);
$so = (int)$pdo->query("SELECT converted_to_so_id FROM quotations WHERE sales_order_id = $qt")->fetchColumn();
if ($so) { $made['so'][] = $so; $pdo->prepare("UPDATE sales_orders SET reference = 'SLD-TEST' WHERE sales_order_id = ?")->execute([$so]); }
$sol = rowsOf('sales_order_items', 'order_id', $so)[0] ?? [];
ok(!empty($r['success']) && $so > 0, 'converted' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
ok(($sol['discount_type'] ?? '') === 'amount' && (float)$sol['discount_amount'] === 10000.0 && (float)$sol['line_total'] === 884000.0, 'order line: fixed 10,000, total 884,000');
ok((float)headerOf('sales_orders', 'sales_order_id', $so)['grand_total'] === 884000.0, 'order grand total 884,000');
$html = renderPage('app/bms/sales/sales_order_edit.php', ['id' => $so]);
ok(strpos($html, 'svc-mode') !== false && preg_match('/id="warehouse_container" style="display:none"/', $html) === 1
   && preg_match('/<select class="form-select" id="warehouse_id" name="warehouse_id" >/', $html) === 1, 'order edit page: stays a service order (no warehouse asked, no SKU/Unit)');
ok(strpos($html, '"discount_value":10000') !== false, 'order edit page: the 10,000 discount reopens');
foreach (['print_sales_order.php', 'print_sales_order_confirmation.php', 'print_sales_order_ledger.php', 'print_sales_order_studio.php'] as $pf) {
    $p = renderPage("app/bms/sales/$pf", ['id' => $so]);
    ok(strpos($p, 'Plan chosen by the customer') !== false && strpos($p, '10,000.00') !== false && strpos($p, '884,000.00') !== false
       && strpos($p, '1 Months') === false && stripos($p, 'Fatal error') === false, "$pf");
}

section('B7. order → invoice: the order\'s discount is charged, not the browser\'s');
$pdo->prepare("UPDATE sales_orders SET status = 'approved' WHERE sales_order_id = ?")->execute([$so]);
$open = soInvoiceableLines($pdo, $so)[0] ?? [];
ok(($open['discount_type'] ?? '') === 'amount' && (float)$open['discount_value'] === 10000.0, 'invoice form is offered the line with its 10,000 discount');
$invPost = fn(array $items, array $extra = []) => $extra + ['customer_id' => $cust, 'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+14 days')), 'currency' => 'TZS', 'notes' => 'SLD-TEST', 'is_service_invoice' => '1', 'items' => $items];
$r = callApi('api/account/save_invoice.php', $invPost([[
    'product_id' => $svc, 'product_name' => 'SLD-TEST PMS', 'order_item_id' => $sol['order_item_id'],
    'quantity' => 1, 'unit' => 'Months', 'unit_price' => 894000, 'tax_rate' => 0,
    'discount_type' => 'amount', 'discount_value' => 0,                 // tampered: the order's 10,000 must still apply
    'description' => $sol['description'],
]], ['order_id' => $so]));
$inv = (int)($r['invoice_id'] ?? 0);
if ($inv) $made['inv'][] = $inv;
$ih = headerOf('invoices', 'invoice_id', $inv);
$il = rowsOf('invoice_items', 'invoice_id', $inv)[0] ?? [];
ok(!empty($r['success']) && $inv > 0, 'invoice saved' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
ok(($il['discount_type'] ?? '') === 'amount' && (float)$il['discount_amount'] === 10000.0 && (float)$il['line_total'] === 884000.0, 'invoice line: the order\'s 10,000 (tampered 0 ignored)');
ok((float)$ih['discount_amount'] === 10000.0 && (float)$ih['grand_total'] === 884000.0, 'invoice: discount 10,000, grand total 884,000');

section('B8. the ledger posts exactly what was sold');
$res = postInvoiceRevenue($pdo, $inv, $uid);
$je = $pdo->prepare("SELECT jei.type, SUM(jei.amount) AS amt FROM journal_entry_items jei
                       JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
                      WHERE je.entity_type = 'invoice' AND je.entity_id = ? GROUP BY jei.type");
$je->execute([$inv]);
$sides = []; foreach ($je->fetchAll(PDO::FETCH_ASSOC) as $x) $sides[strtolower($x['type'])] = round((float)$x['amt'], 2);
ok(!empty($res['posted']) || ($res['reason'] ?? '') === 'already_posted', 'invoice posted to the ledger' . (empty($res['posted']) ? ' — ' . json_encode($res) : ''));
ok(($sides['debit'] ?? $sides['dr'] ?? 0) === 884000.0 && ($sides['credit'] ?? $sides['cr'] ?? 0) === 884000.0,
   'Dr Receivable 884,000 = Cr Revenue 884,000 (net of the 10,000 discount) — ' . json_encode($sides));
foreach (['invoice_print.php', 'invoice_print_onyx.php', 'invoice_print_summit.php', 'invoice_print_wave.php'] as $pf) {
    $p = renderPage("app/bms/invoice/$pf", ['id' => $inv]);
    ok(strpos($p, 'Plan chosen by the customer') !== false && strpos($p, '10,000.00') !== false && strpos($p, '884,000.00') !== false
       && strpos($p, '1 Months') === false && stripos($p, 'Fatal error') === false, "$pf");
}
// The ledger posting locks the invoice; remove it so the edit tests below can run on an unposted copy.
$pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id WHERE je.entity_type = 'invoice' AND je.entity_id = $inv");
$pdo->exec("DELETE FROM journal_entries WHERE entity_type = 'invoice' AND entity_id = $inv");
try { $pdo->exec("UPDATE invoices SET output_vat_posted = NULL WHERE invoice_id = $inv"); } catch (Throwable $e) {}

section('B9. invoice edit keeps the discount, description and the order link');
$html = renderPage('app/bms/invoice/invoice_edit.php', ['id' => $inv]);
ok(stripos($html, 'Fatal error') === false && strpos($html, 'id="discount-total"') !== false, 'edit page renders with a Discount total');
ok(strpos($html, '"discount_value":10000') !== false && strpos($html, '"so_disc_left":10000') !== false
   && strpos($html, 'item-order-item-id') !== false, 'the line reopens with its discount and its sales-order link');
// Re-save exactly as the edit form posts it.
$r = callApi('api/account/save_invoice.php', $invPost([[
    'product_id' => $svc, 'product_name' => 'SLD-TEST PMS', 'order_item_id' => $sol['order_item_id'], 'quantity' => 1, 'unit' => 'Months',
    'unit_price' => 894000, 'tax_rate' => 0, 'discount_type' => 'amount', 'discount_value' => 10000, 'description' => $il['description'],
]], ['invoice_id' => $inv, 'invoice_number' => $ih['invoice_number'], 'order_id' => $so]));
$ih2 = headerOf('invoices', 'invoice_id', $inv);
$il2 = rowsOf('invoice_items', 'invoice_id', $inv)[0] ?? [];
ok(!empty($r['success']) && (float)$ih2['grand_total'] === 884000.0 && (float)$il2['discount_amount'] === 10000.0
   && (int)$il2['order_item_id'] === (int)$sol['order_item_id'], 'saved again: still 884,000, still linked' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));

section('B10. one order billed in three invoices adds up to exactly its discount');
$r = callApi('api/account/save_sales_order.php', ['customer_id' => $cust, 'order_date' => date('Y-m-d'), 'currency' => 'TZS', 'reference' => 'SLD-TEST',
    'status' => 'pending', 'items' => json_encode([$line($plain, 'SLD-TEST Training', 50000, ['quantity' => 3, 'unit' => 'Days', 'discount_type' => 'amount', 'discount_value' => 10000])])]);
$so3 = (int)($r['sales_order_id'] ?? $r['id'] ?? 0);
if (!$so3) $so3 = (int)$pdo->query("SELECT MAX(sales_order_id) FROM sales_orders WHERE reference = 'SLD-TEST'")->fetchColumn();
if ($so3) $made['so'][] = $so3;
$so3l = rowsOf('sales_order_items', 'order_id', $so3)[0] ?? [];
ok(!empty($r['success']) && (float)($so3l['discount_amount'] ?? 0) === 10000.0 && (float)headerOf('sales_orders', 'sales_order_id', $so3)['grand_total'] === 140000.0,
   'order: 3 × 50,000 − 10,000 = 140,000' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$pdo->prepare("UPDATE sales_orders SET status = 'approved' WHERE sales_order_id = ?")->execute([$so3]);
$discs = [];
for ($i = 1; $i <= 3; $i++) {
    $r = callApi('api/account/save_invoice.php', $invPost([[
        'product_id' => $plain, 'product_name' => 'SLD-TEST Training', 'order_item_id' => $so3l['order_item_id'], 'quantity' => 1, 'unit' => 'Days',
        'unit_price' => 50000, 'tax_rate' => 0, 'discount_type' => 'amount', 'discount_value' => 10000, 'description' => '',
    ]], ['order_id' => $so3]));
    $id = (int)($r['invoice_id'] ?? 0);
    if ($id) $made['inv'][] = $id;
    $discs[] = (float)(rowsOf('invoice_items', 'invoice_id', $id)[0]['discount_amount'] ?? -1);
}
// 1st: 10,000 × 1/3 = 3,333.33; 2nd: 6,666.67 × 1/2 = 3,333.335 → 3,333.34; 3rd (the last): what is left, 3,333.33.
ok($discs === [3333.33, 3333.34, 3333.33], 'each invoice takes its share of what is still open — ' . json_encode($discs));
ok(abs(array_sum($discs) - 10000) < 0.001, 'together exactly the order\'s 10,000');

section('B11. System Settings choice reaches the forms');
$pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('sales_discount_type', 'amount', NOW())
               ON DUPLICATE KEY UPDATE setting_value = 'amount'")->execute();
$pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('sales_discount_allow_switch', '0', NOW())
               ON DUPLICATE KEY UPDATE setting_value = '0'")->execute();
$html = renderPage('app/bms/sales/quotations/quotation_create.php', []);
ok(strpos($html, '"type":"amount"') !== false && strpos($html, '"allowSwitch":false') !== false, 'new forms default to a fixed amount, switching off');
$html = renderPage('app/constant/settings/system_settings.php', []);
ok(preg_match('/<option value="amount" selected>/', $html) === 1 && preg_match('/id="sales_discount_allow_switch"[^>]*value="1" >/', $html) === 1,
   'System Settings shows the saved choice');
