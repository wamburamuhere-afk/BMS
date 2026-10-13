<?php
/**
 * tests/test_service_sales_phase1_cli.php — services through quotation → sales
 * order → invoice keep their money right (Phase 1 of the service-sales plan).
 *
 *   php tests/test_service_sales_phase1_cli.php
 *
 * Drives the real endpoints as the local admin with ZZTEST data, then deletes
 * everything it created (documents, ledger entries, signatures, activity rows).
 *   1. service lookup returns the registered VAT; products.tax_rate (read by POS) untouched
 *   2. quotation → sales order keeps discount + VAT
 *   3. invoice from the sales order reproduces its totals and posts them to the ledger
 *   4. a sales order cannot be invoiced twice; cancelling frees the quantity
 *   5. amounts above 99,999,999.99 are stored whole; impossible amounts are rejected
 *   6. a service used on a quotation/invoice cannot be deleted; an unused one can
 *   7. invoice print shows the discount and the stored VAT
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);

// ── worker: run one endpoint/page as admin ──────────────────────────────────
if (($argv[1] ?? '') === 'call') {
    $p = json_decode(base64_decode($argv[3] ?? ''), true) ?: [];
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)$argv[4]; $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    $_SERVER['REQUEST_METHOD'] = empty($p['post']) ? 'GET' : 'POST';
    $_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'zztest-cli';
    $_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $argv[2];
    $_GET = $p['get'] ?? []; $_POST = $p['post'] ?? []; $_REQUEST = array_merge($_GET, $_POST);
    chdir($root);
    require_once "$root/roots.php";
    require "$root/" . $argv[2];
    exit;
}

$_SERVER['HTTP_HOST'] = 'localhost';
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

$adminId = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
function call(string $file, array $payload, bool $raw = false) {
    global $root, $adminId;
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' call '
        . escapeshellarg($file) . ' ' . escapeshellarg(base64_encode(json_encode($payload))) . ' ' . $adminId . ' 2>&1');
    if ($raw) return $out;
    $start = strpos($out, '{');
    $j = $start === false ? null : json_decode(substr($out, $start), true);
    return is_array($j) ? $j : ['success' => false, 'message' => 'non-JSON: ' . substr(trim($out), 0, 300)];
}
function one(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC); }
function all(string $sql, array $p = []) { global $pdo; $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
function near($a, $b): bool { return abs((float)$a - (float)$b) < 0.011; }

$customerId = (int)$pdo->query("SELECT customer_id FROM customers WHERE status = 'active' ORDER BY customer_id LIMIT 1")->fetchColumn();
$vatId      = (int)$pdo->query("SELECT rate_id FROM tax_rates WHERE status = 'active' AND rate_percentage = 18 ORDER BY rate_id LIMIT 1")->fetchColumn();
$whId       = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$startLog   = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM activity_logs")->fetchColumn();
$today      = date('Y-m-d');

$made = ['products' => [], 'quotations' => [], 'sales_orders' => [], 'invoices' => []];

echo "\nBMS — Service sales Phase 1 (quotation → sales order → invoice)\n";
if (!$adminId || !$customerId || !$vatId || !$whId) {
    echo "  SKIP  missing fixture data (admin, customer, 18% VAT rate or warehouse)\n";
    exit(0);
}

try {
    // ─────────────────────────────────────────────────────────────────────
    section('1. Service registration and lookup');
    $r = call('api/create_nip_product.php', ['post' => [
        'product_name' => 'ZZTEST Smart BMS – Annual Subscription', 'description' => 'ZZTEST cloud business management system',
        'unit' => 'Subscription', 'selling_price' => '1500000', 'tax_id' => (string)$vatId, 'warehouse_id' => (string)$whId,
    ]]);
    ok('create service A', !empty($r['success']), $r['message'] ?? '');
    $svcA = (int)($r['product_id'] ?? 0); $made['products'][] = $svcA;
    $r = call('api/create_nip_product.php', ['post' => [
        'product_name' => 'ZZTEST Smart BMS – Setup & Training', 'unit' => 'job', 'selling_price' => '500000',
        'tax_id' => (string)$vatId, 'warehouse_id' => (string)$whId,
    ]]);
    $svcB = (int)($r['product_id'] ?? 0); $made['products'][] = $svcB;
    ok('create service B', $svcB > 0, $r['message'] ?? '');

    $lk = call('api/account/get_products.php', ['get' => ['search' => 'ZZTEST Smart BMS', 'is_service' => '1', 'limit' => '50']]);
    $rowA = current(array_filter($lk['data'] ?? [], fn($x) => (int)$x['product_id'] === $svcA)) ?: [];
    ok('lookup returns sales_tax_rate 18 for a VAT-registered service', near($rowA['sales_tax_rate'] ?? -1, 18), json_encode($rowA['sales_tax_rate'] ?? null));
    ok('lookup still returns tax_rate 0 (field the POS cart reads is unchanged)', near($rowA['tax_rate'] ?? -1, 0));
    ok('products.tax_rate in the database stays 0 for the service', near(one("SELECT tax_rate FROM products WHERE product_id = ?", [$svcA])['tax_rate'] ?? -1, 0));

    // ─────────────────────────────────────────────────────────────────────
    section('2. Quotation → sales order');
    $qItems = json_encode([
        ['product_id' => $svcA, 'product_name' => 'ZZTEST Smart BMS – Annual Subscription', 'quantity' => '1', 'unit' => 'Subscription', 'unit_price' => '1500000', 'tax_rate' => '18', 'discount_percent' => '10'],
        ['product_id' => $svcB, 'product_name' => 'ZZTEST Smart BMS – Setup & Training', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '500000', 'tax_rate' => '18', 'discount_percent' => '0'],
    ]);
    $r = call('api/account/save_quotation.php', ['post' => ['customer_id' => (string)$customerId, 'order_date' => $today, 'notes' => 'ZZTEST quotation', 'items' => $qItems]]);
    $qid = (int)($r['quotation_id'] ?? 0); $made['quotations'][] = $qid;
    ok('quotation saved', $qid > 0, $r['message'] ?? '');
    $q = one("SELECT subtotal, discount_amount, tax_amount, grand_total FROM quotations WHERE sales_order_id = ?", [$qid]) ?: [];
    ok('quotation totals 2,000,000 − 150,000 + VAT 333,000 = 2,183,000',
        near($q['subtotal'] ?? 0, 2000000) && near($q['discount_amount'] ?? 0, 150000) && near($q['tax_amount'] ?? 0, 333000) && near($q['grand_total'] ?? 0, 2183000), json_encode($q));
    call('api/account/review_quotation.php', ['post' => ['quotation_id' => (string)$qid]]);
    call('api/account/approve_quotation.php', ['post' => ['quotation_id' => (string)$qid]]);
    $r = call('api/account/convert_quote_to_order.php', ['post' => ['id' => (string)$qid]]);
    $soId = (int)($r['sales_order_id'] ?? 0); $made['sales_orders'][] = $soId;
    ok('quotation converted to a sales order', $soId > 0, $r['message'] ?? '');
    call('api/account/review_sales_order.php', ['post' => ['sales_order_id' => (string)$soId]]);
    $r = call('api/account/approve_sales_order.php', ['post' => ['sales_order_id' => (string)$soId]]);
    ok('sales order approved', !empty($r['success']), $r['message'] ?? '');

    // The catalogue price changes after the order was agreed.
    call('api/update_nip_product.php', ['post' => [
        'product_id' => (string)$svcA, 'product_name' => 'ZZTEST Smart BMS – Annual Subscription', 'selling_price' => '1800000',
        'tax_id' => (string)$vatId, 'warehouse_id' => (string)$whId, 'status' => 'active',
    ]]);

    // ─────────────────────────────────────────────────────────────────────
    section('3. Invoice from the sales order');
    $li = call('api/account/get_sales_order_items.php', ['get' => ['order_id' => (string)$soId]]);
    $lines = $li['data'] ?? [];
    $lA = current(array_filter($lines, fn($x) => (int)$x['product_id'] === $svcA)) ?: [];
    ok('order line keeps the agreed price (1,500,000), not the new catalogue price', near($lA['unit_price'] ?? 0, 1500000), json_encode($lA['unit_price'] ?? null));
    ok('order line keeps VAT 18% and discount 10%', near($lA['tax_rate'] ?? 0, 18) && near($lA['discount_percent'] ?? 0, 10));
    ok('order line reports 1 left to invoice', near($lA['remaining_quantity'] ?? 0, 1));

    $invItems = [];
    foreach ($lines as $i => $l) {
        $invItems[$i] = ['order_item_id' => $l['order_item_id'], 'product_id' => $l['product_id'], 'product_name' => $l['product_name'],
            'description' => $l['description'] ?? '', 'quantity' => $l['quantity'], 'unit' => $l['unit'],
            'unit_price' => $l['unit_price'], 'tax_rate' => $l['tax_rate'], 'discount_percent' => $l['discount_percent']];
    }
    $invPost = ['customer_id' => (string)$customerId, 'order_id' => (string)$soId, 'invoice_date' => $today,
        'due_date' => date('Y-m-d', strtotime('+30 days')), 'notes' => 'ZZTEST invoice', 'is_service_invoice' => 'on', 'items' => $invItems];
    $r = call('api/account/save_invoice.php', ['post' => $invPost]);
    $inv1 = (int)($r['invoice_id'] ?? 0); $made['invoices'][] = $inv1;
    ok('invoice saved', $inv1 > 0, $r['message'] ?? '');
    $h = one("SELECT subtotal, discount_amount, tax_amount, grand_total FROM invoices WHERE invoice_id = ?", [$inv1]) ?: [];
    ok('invoice totals match the sales order (2,183,000 incl. VAT 333,000, discount 150,000)',
        near($h['subtotal'] ?? 0, 2000000) && near($h['discount_amount'] ?? 0, 150000) && near($h['tax_amount'] ?? 0, 333000) && near($h['grand_total'] ?? 0, 2183000), json_encode($h));
    $il = one("SELECT discount_percent, discount_amount, tax_amount, line_total, order_item_id FROM invoice_items WHERE invoice_id = ? AND product_id = ?", [$inv1, $svcA]) ?: [];
    ok('invoice line stores discount 10% / 150,000, VAT 243,000, total 1,593,000, linked to the order line',
        near($il['discount_percent'] ?? 0, 10) && near($il['discount_amount'] ?? 0, 150000) && near($il['tax_amount'] ?? 0, 243000)
        && near($il['line_total'] ?? 0, 1593000) && (int)($il['order_item_id'] ?? 0) === (int)$lA['order_item_id'], json_encode($il));

    $gl = all("SELECT a.account_code, jei.type, SUM(jei.amount) amt FROM journal_entries je
                 JOIN journal_entry_items jei ON jei.entry_id = je.entry_id
                 JOIN accounts a ON a.account_id = jei.account_id
                WHERE je.entity_type = 'invoice' AND je.entity_id = ? AND je.status = 'posted'
             GROUP BY a.account_code, jei.type", [$inv1]);
    $dr = array_sum(array_map(fn($x) => $x['type'] === 'debit' ? (float)$x['amt'] : 0, $gl));
    $cr = array_sum(array_map(fn($x) => $x['type'] === 'credit' ? (float)$x['amt'] : 0, $gl));
    $vatCr = array_sum(array_map(fn($x) => $x['type'] === 'credit' && near($x['amt'], 333000) ? 1 : 0, $gl));
    ok('ledger: Dr 2,183,000 = Cr 2,183,000 with Output VAT 333,000', near($dr, 2183000) && near($cr, 2183000) && $vatCr === 1, json_encode($gl));
    $bal = assertLedgerBalanced($pdo);
    ok('whole ledger still balanced', !empty($bal['ledger_balanced']), json_encode($bal));
    ok('sales order line quantity_invoiced updated to 1', near(one("SELECT quantity_invoiced FROM sales_order_items WHERE order_item_id = ?", [$lA['order_item_id']])['quantity_invoiced'] ?? 0, 1));

    // ─────────────────────────────────────────────────────────────────────
    section('4. No double billing; cancelling frees the quantity');
    $r = call('api/account/save_invoice.php', ['post' => $invPost]);
    if (!empty($r['invoice_id'])) $made['invoices'][] = (int)$r['invoice_id'];
    ok('second invoice for the same sales order is rejected', empty($r['success']) && stripos($r['message'] ?? '', 'left to invoice') !== false, $r['message'] ?? '');
    $listed = fn() => in_array($soId, array_map(fn($x) => (int)$x['sales_order_id'],
        call('api/account/get_sales_orders.php', ['get' => ['customer' => (string)$customerId, 'status' => 'approved', 'for_invoicing' => '1']])['data'] ?? []), true);
    ok('fully invoiced order no longer offered on the invoice form', !$listed());
    ok('fully invoiced order has nothing left to load', (call('api/account/get_sales_order_items.php', ['get' => ['order_id' => (string)$soId]])['data'] ?? [1]) === []);
    $over = $invPost; $over['items'] = [array_merge($invItems[0], ['quantity' => '2'])];
    $r = call('api/account/save_invoice.php', ['post' => $over]);
    if (!empty($r['invoice_id'])) $made['invoices'][] = (int)$r['invoice_id'];
    ok('billing more than ordered is rejected', empty($r['success']));

    $r = call('api/account/update_invoice_status.php', ['post' => ['invoice_id' => (string)$inv1, 'status' => 'cancelled']]);
    ok('invoice cancelled', !empty($r['success']), $r['message'] ?? '');
    ok('cancelled invoice returns the quantity to the order', near(one("SELECT quantity_invoiced FROM sales_order_items WHERE order_item_id = ?", [$lA['order_item_id']])['quantity_invoiced'] ?? 9, 0));
    ok('order is offered on the invoice form again', $listed());
    $part = $invPost; $part['items'] = [array_merge($invItems[0], ['quantity' => '0.5'])];
    $r = call('api/account/save_invoice.php', ['post' => $part]);
    $inv2 = (int)($r['invoice_id'] ?? 0); if ($inv2) $made['invoices'][] = $inv2;
    ok('partial invoice (0.5 of the line) accepted', $inv2 > 0, $r['message'] ?? '');
    ok('order line shows 0.5 still to invoice', near(current(array_filter(call('api/account/get_sales_order_items.php', ['get' => ['order_id' => (string)$soId]])['data'] ?? [],
        fn($x) => (int)$x['order_item_id'] === (int)$lA['order_item_id']))['remaining_quantity'] ?? 0, 0.5));

    // ─────────────────────────────────────────────────────────────────────
    section('5. Large and impossible amounts');
    $big = ['customer_id' => (string)$customerId, 'invoice_date' => $today, 'due_date' => $today, 'notes' => 'ZZTEST invoice', 'is_service_invoice' => 'on',
        'items' => [['product_id' => $svcB, 'product_name' => 'ZZTEST Smart BMS – Setup & Training', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '150000000', 'tax_rate' => '18']]];
    $r = call('api/account/save_invoice.php', ['post' => $big]);
    $inv3 = (int)($r['invoice_id'] ?? 0); if ($inv3) $made['invoices'][] = $inv3;
    $bl = one("SELECT unit_price, line_total FROM invoice_items WHERE invoice_id = ?", [$inv3]) ?: [];
    ok('150,000,000 line stored whole (no clipping at 99,999,999.99)', near($bl['unit_price'] ?? 0, 150000000) && near($bl['line_total'] ?? 0, 177000000), json_encode($bl));
    $huge = $big; $huge['items'][0]['unit_price'] = '100000000000000';
    $r = call('api/account/save_invoice.php', ['post' => $huge]);
    if (!empty($r['invoice_id'])) $made['invoices'][] = (int)$r['invoice_id'];
    ok('impossible invoice amount rejected with a clear message', empty($r['success']) && stripos($r['message'] ?? '', 'larger than the system can store') !== false, $r['message'] ?? '');
    $r = call('api/account/save_quotation.php', ['post' => ['customer_id' => (string)$customerId, 'order_date' => $today, 'notes' => 'ZZTEST quotation',
        'items' => json_encode([['product_id' => $svcB, 'product_name' => 'ZZTEST x', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '100000000000000', 'tax_rate' => '0', 'discount_percent' => '0']])]]);
    if (!empty($r['quotation_id'])) $made['quotations'][] = (int)$r['quotation_id'];
    ok('impossible quotation amount rejected', empty($r['success']), $r['message'] ?? '');
    $r = call('api/account/save_sales_order.php', ['post' => ['customer_id' => (string)$customerId, 'order_date' => $today, 'notes' => 'ZZTEST SO',
        'items' => json_encode([['product_id' => $svcB, 'product_name' => 'ZZTEST x', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '100000000000000', 'tax_rate' => '0', 'discount_percent' => '0']])]]);
    if (!empty($r['order_id'])) $made['sales_orders'][] = (int)$r['order_id'];
    ok('impossible sales order amount rejected', empty($r['success']), $r['message'] ?? '');

    // ─────────────────────────────────────────────────────────────────────
    section('6. Deleting services');
    $r = call('api/create_nip_product.php', ['post' => ['product_name' => 'ZZTEST invoiced-only service', 'selling_price' => '250000', 'warehouse_id' => (string)$whId]]);
    $svcD = (int)($r['product_id'] ?? 0); $made['products'][] = $svcD;
    $r = call('api/account/save_invoice.php', ['post' => ['customer_id' => (string)$customerId, 'invoice_date' => $today, 'due_date' => $today,
        'notes' => 'ZZTEST invoice', 'is_service_invoice' => 'on',
        'items' => [['product_id' => $svcD, 'product_name' => 'ZZTEST invoiced-only service', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '250000', 'tax_rate' => '0']]]]);
    if (!empty($r['invoice_id'])) $made['invoices'][] = (int)$r['invoice_id'];
    $r = call('api/delete_product.php', ['post' => ['product_id' => (string)$svcD]]);
    ok('service used only on an invoice cannot be deleted (was deleted before)', empty($r['success']) && stripos($r['message'] ?? '', 'Inactive') !== false, $r['message'] ?? '');
    ok('…and still exists', (bool)one("SELECT 1 FROM products WHERE product_id = ?", [$svcD]));
    $r = call('api/create_nip_product.php', ['post' => ['product_name' => 'ZZTEST unused service', 'selling_price' => '1000', 'warehouse_id' => (string)$whId]]);
    $svcC = (int)($r['product_id'] ?? 0); $made['products'][] = $svcC;
    $r = call('api/delete_product.php', ['post' => ['product_id' => (string)$svcC]]);
    ok('unused service can still be deleted', !empty($r['success']) && !one("SELECT 1 FROM products WHERE product_id = ?", [$svcC]), $r['message'] ?? '');

    // ─────────────────────────────────────────────────────────────────────
    section('7. Invoice print');
    $r = call('api/account/update_invoice_status.php', ['post' => ['invoice_id' => (string)$inv2, 'status' => 'cancelled']]);
    $r = call('api/account/save_invoice.php', ['post' => $invPost]);
    $inv4 = (int)($r['invoice_id'] ?? 0); if ($inv4) $made['invoices'][] = $inv4;
    foreach (['invoice_print', 'invoice_print_onyx', 'invoice_print_summit', 'invoice_print_wave'] as $tpl) {
        $html = call("app/bms/invoice/$tpl.php", ['get' => ['id' => (string)$inv4]], true);
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        ok("$tpl: shows Discount 150,000.00 and VAT 333,000.00, no error",
            strpos($text, 'Discount') !== false && strpos($text, '150,000.00') !== false && strpos($text, '333,000.00') !== false
            && stripos($html, 'Fatal error') === false, substr($text, 0, 0));
    }
} finally {
    // ── cleanup ──────────────────────────────────────────────────────────
    $inv = array_values(array_filter(array_unique($made['invoices'])));
    $so  = array_values(array_filter(array_unique($made['sales_orders'])));
    $qt  = array_values(array_filter(array_unique($made['quotations'])));
    $pr  = array_values(array_filter(array_unique($made['products'])));
    $in  = fn(array $ids) => $ids ? implode(',', array_map('intval', $ids)) : '0';
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM workflow_signatures WHERE (entity_type = 'invoice' AND entity_id IN ({$in($inv)}))
                 OR (entity_type = 'quotation' AND entity_id IN ({$in($qt)})) OR (entity_type = 'sales_order' AND entity_id IN ({$in($so)}))");
    $pdo->exec("DELETE jei FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id = jei.entry_id
                 WHERE je.entity_type IN ('invoice', 'invoice_void') AND je.entity_id IN ({$in($inv)})");
    $pdo->exec("DELETE FROM journal_entries WHERE entity_type IN ('invoice', 'invoice_void') AND entity_id IN ({$in($inv)})");
    $pdo->exec("DELETE FROM invoice_items WHERE invoice_id IN ({$in($inv)})");
    $pdo->exec("DELETE FROM invoices WHERE invoice_id IN ({$in($inv)}) AND notes = 'ZZTEST invoice'");
    $pdo->exec("DELETE FROM sales_order_items WHERE order_id IN ({$in($so)})");
    $pdo->exec("DELETE FROM sales_orders WHERE sales_order_id IN ({$in($so)})");
    $pdo->exec("DELETE FROM quotation_items WHERE order_id IN ({$in($qt)})");
    $pdo->exec("DELETE FROM quotations WHERE sales_order_id IN ({$in($qt)})");
    $pdo->exec("DELETE FROM product_assembly_components WHERE parent_product_id IN ({$in($pr)})");
    $pdo->exec("DELETE FROM products WHERE product_id IN ({$in($pr)}) AND product_name LIKE 'ZZTEST%'");
    $pdo->prepare("DELETE FROM activity_logs WHERE id > ? AND user_id = ?")->execute([$startLog, $adminId]);
    $pdo->commit();
    $left = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM products WHERE product_name LIKE 'ZZTEST%')
        + (SELECT COUNT(*) FROM invoices WHERE notes = 'ZZTEST invoice') + (SELECT COUNT(*) FROM quotations WHERE notes = 'ZZTEST quotation')")->fetchColumn();
    echo "\n  cleanup: " . ($left === 0 ? 'all ZZTEST data removed' : "$left ZZTEST row(s) left") . "\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
