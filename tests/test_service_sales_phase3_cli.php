<?php
/**
 * tests/test_service_sales_phase3_cli.php — service-sales Phase 3 (reports).
 *
 *   php tests/test_service_sales_phase3_cli.php
 *
 * Drives the real endpoints as the local admin with ZZTEST data, then deletes
 * everything it created. Switches pos_simple_mode briefly to check both modes
 * and restores it.
 *   1. fixture: services, invoices (one cancelled), a converted quotation
 *   2. Service Sales Report: per-service net/VAT/discount, Item Code, unlinked row,
 *      ranking, trend months, quoted-vs-invoiced, filters, permission
 *   3. Product Analysis: Services/Products filter, Item Code, net revenue
 *   4. full mode shows the menu link + invoice section; Simple POS mode does not
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);

// ── worker: run one endpoint/page as admin ──────────────────────────────────
if (($argv[1] ?? '') === 'call') {
    $p = json_decode(base64_decode($argv[3] ?? ''), true) ?: [];
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['user_id'] = (int)$argv[4]; $_SESSION['username'] = 'admin';
    $_SESSION['is_admin'] = true; $_SESSION['role_id'] = 1; $_SESSION['role'] = 'admin';
    if (($p['as'] ?? '') === 'nobody') { $_SESSION['is_admin'] = false; $_SESSION['role_id'] = 999999; $_SESSION['role'] = 'nobody'; $_SESSION['permissions'] = []; }
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

echo "\nBMS — Service sales Phase 3 (reports)\n";
if (!$adminId || !$customerId || !$vatId || !$whId) {
    echo "  SKIP  missing fixture data (admin, customer, 18% VAT rate or warehouse)\n";
    exit(0);
}

try {
    $origSimple = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
    $setSimple = function (?string $v) use ($pdo) {
        if ($v === null) { $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'"); return; }
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('pos_simple_mode', ?, NOW())
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
    };
    $cats = $pdo->query("SELECT category_id, category_name FROM categories WHERE status = 'active' AND type = 'product' ORDER BY category_id LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
    $catA = (int)($cats[0]['category_id'] ?? 0); $catB = (int)($cats[1]['category_id'] ?? $catA);
    $cust2 = (int)$pdo->query("SELECT customer_id FROM customers WHERE status = 'active' AND customer_id <> $customerId ORDER BY customer_id LIMIT 1")->fetchColumn() ?: $customerId;
    $from = date('Y-m-01', strtotime('-2 months')); $to = date('Y-m-t');

    // ─────────────────────────────────────────────────────────────────────
    section('1. Fixture: two services, three invoices (one cancelled), one quotation');
    $mk = function (string $name, int $cat, string $price) use ($vatId, $whId, &$made) {
        $r = call('api/create_nip_product.php', ['post' => ['product_name' => $name, 'unit' => 'job', 'selling_price' => $price,
            'tax_id' => (string)$vatId, 'warehouse_id' => (string)$whId, 'category_id' => (string)$cat]]);
        $id = (int)($r['product_id'] ?? 0); $made['products'][] = $id; return $id;
    };
    $svcA = $mk('ZZTEST P3 Consulting', $catA, '1000');
    $svcB = $mk('ZZTEST P3 Hosting', $catB, '500');
    ok('services created', $svcA > 0 && $svcB > 0);
    $inv = function (int $cust, array $lines, string $date) use ($today, &$made) {
        $r = call('api/account/save_invoice.php', ['post' => ['customer_id' => (string)$cust, 'invoice_date' => $date, 'due_date' => $date,
            'notes' => 'ZZTEST invoice', 'is_service_invoice' => 'on', 'items' => $lines]]);
        $id = (int)($r['invoice_id'] ?? 0); if ($id) $made['invoices'][] = $id; return $id;
    };
    $lineA = fn($q, $d = '0') => ['product_id' => 0, 'product_name' => '', 'quantity' => $q, 'unit' => 'job', 'unit_price' => '1000', 'tax_rate' => '18', 'discount_percent' => $d];
    $i1 = $inv($customerId, [array_merge($lineA('2', '10'), ['product_id' => $svcA, 'product_name' => 'ZZTEST P3 Consulting']),
                             ['product_id' => $svcB, 'product_name' => 'ZZTEST P3 Hosting', 'quantity' => '1', 'unit' => 'job', 'unit_price' => '500', 'tax_rate' => '18'],
                             ['product_id' => '', 'product_name' => 'ZZTEST P3 typed by hand', 'quantity' => '1', 'unit' => 'pcs', 'unit_price' => '300', 'tax_rate' => '0']],
                 date('Y-m-d', strtotime('-1 month')));
    $i2 = $inv($cust2, [array_merge($lineA('1'), ['product_id' => $svcA, 'product_name' => 'ZZTEST P3 Consulting'])], $today);
    $i3 = $inv($customerId, [array_merge($lineA('5'), ['product_id' => $svcA, 'product_name' => 'ZZTEST P3 Consulting'])], $today);
    call('api/account/update_invoice_status.php', ['post' => ['invoice_id' => (string)$i3, 'status' => 'cancelled']]);
    ok('three invoices created, third cancelled', $i1 && $i2 && $i3);
    $r = call('api/account/save_quotation.php', ['post' => ['customer_id' => (string)$customerId, 'order_date' => $today, 'notes' => 'ZZTEST quotation',
        'items' => json_encode([['product_id' => $svcA, 'product_name' => 'ZZTEST P3 Consulting', 'quantity' => '4', 'unit' => 'job', 'unit_price' => '1000', 'tax_rate' => '18', 'discount_percent' => '0']])]]);
    $qt = (int)($r['quotation_id'] ?? 0); $made['quotations'][] = $qt;
    call('api/account/review_quotation.php', ['post' => ['quotation_id' => (string)$qt]]);
    call('api/account/approve_quotation.php', ['post' => ['quotation_id' => (string)$qt]]);
    $r = call('api/account/convert_quote_to_order.php', ['post' => ['id' => (string)$qt]]);
    if (!empty($r['sales_order_id'])) $made['sales_orders'][] = (int)$r['sales_order_id'];

    // ─────────────────────────────────────────────────────────────────────
    section('2. Service Sales Report figures');
    $rep = fn(array $extra = []) => call('api/account/get_service_sales_report.php', ['get' => array_merge(['date_from' => $from, 'date_to' => $to, 'source' => 'invoice'], $extra)]);
    $r = $rep();
    ok('report loads', !empty($r['success']), $r['message'] ?? '');
    $row = fn(array $res, int $pid) => current(array_filter($res['rows'] ?? [], fn($x) => (int)$x['product_id'] === $pid)) ?: [];
    $a = $row($r, $svcA); $b = $row($r, $svcB);
    ok('Consulting: qty 3, gross 3,000, discount 200, net 2,800 (cancelled invoice excluded)',
        near($a['qty'] ?? 0, 3) && near($a['gross'] ?? 0, 3000) && near($a['discount'] ?? 0, 200) && near($a['net'] ?? 0, 2800), json_encode($a));
    ok('Consulting: VAT 504 (18% after discount), 2 invoices, 2 customers',
        near($a['vat'] ?? 0, 504) && (int)($a['documents'] ?? 0) === 2 && (int)($a['customers'] ?? 0) === 2);
    ok('Hosting: net 500', near($b['net'] ?? 0, 500));
    ok('service code is the Item Code', ($a['code'] ?? '') === (string)(one("SELECT contract_item_no FROM products WHERE product_id = ?", [$svcA])['contract_item_no'] ?? 'x'));
    $unlinked = $row($r, 0);
    ok('hand-typed line kept in a "Not linked" row (300)', ($unlinked['net'] ?? 0) >= 300 - 0.01);
    ok('Consulting ranks above Hosting', array_search($svcA, array_column($r['rows'], 'product_id')) < array_search($svcB, array_column($r['rows'], 'product_id')));
    ok('trend has one point per month in the range', count($r['charts']['trend']['months'] ?? []) === 3, json_encode($r['charts']['trend']['months'] ?? null));
    $conv = current(array_filter($r['charts']['conversion'] ?? [], fn($x) => $x['name'] === 'ZZTEST P3 Consulting')) ?: [];
    ok('quoted vs invoiced: 1 quotation, converted, quoted 4,000', (int)($conv['quotes'] ?? 0) === 1 && (int)($conv['converted'] ?? 0) === 1 && near($conv['quoted'] ?? 0, 4000), json_encode($conv));
    $r2 = $rep(['category_id' => (string)$catB]);
    ok('category filter keeps only that category', $catA === $catB || (!$row($r2, $svcA) && $row($r2, $svcB)));
    $r3 = $rep(['customer_id' => (string)$cust2]);
    ok('customer filter: only the second customer\'s invoice (net 1,000)', near($row($r3, $svcA)['net'] ?? 0, 1000) && !$row($r3, $svcB));
    $r4 = call('api/account/get_service_sales_report.php', ['get' => ['date_from' => $from, 'date_to' => $to, 'source' => 'pos']]);
    ok('POS-only source loads and contains none of the invoice-only test services', !empty($r4['success']) && !$row($r4, $svcA));
    $r5 = call('api/account/get_service_sales_report.php', ['get' => ['date_from' => $from, 'date_to' => $to], 'as' => 'nobody']);
    ok('a user without the permission is refused', empty($r5['success']) && stripos($r5['message'] ?? '', 'denied') !== false, $r5['message'] ?? '');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Product Analysis');
    $pa = call('api/account/get_product_analysis_report.php', ['get' => ['date_from' => $from, 'date_to' => $to, 'item_type' => 'service']]);
    $pr = current(array_filter($pa['rows'] ?? [], fn($x) => (int)($x['product_id'] ?? 0) === $svcA)) ?: [];
    ok('Services filter lists the service with its Item Code and net revenue 2,800',
        near($pr['revenue'] ?? 0, 2800) && ($pr['product_code'] ?? '') === ($a['code'] ?? '-'), json_encode($pr));
    $pp = call('api/account/get_product_analysis_report.php', ['get' => ['date_from' => $from, 'date_to' => $to, 'item_type' => 'product']]);
    ok('Products filter excludes services', !array_filter($pp['rows'] ?? [], fn($x) => (int)($x['is_service'] ?? 0) === 1));

    // ─────────────────────────────────────────────────────────────────────
    section('4. Full mode vs Simple POS mode');
    $setSimple('0');
    $html = call('app/constant/reports/service_sales_report.php', ['get' => []], true);
    ok('full mode: report page renders, no error', strpos($html, 'svcTable') !== false && stripos($html, 'Fatal error') === false);
    ok('full mode: Reports menu links the Service Sales Report', preg_match('#href="[^"]*/service_sales_report"#', $html) === 1);
    $sv = call('app/bms/product/service_view.php', ['get' => ['id' => (string)$svcA]], true);
    ok('full mode: service page shows Invoice Sales and the open-quotation section', strpos($sv, 'svcRecentInvoices') !== false && stripos($sv, 'Fatal error') === false);
    $setSimple('1');
    $html = call('app/bms/invoice/invoice_create.php', ['get' => []], true);
    ok('Simple POS mode: Reports menu does not link the new report', preg_match('#href="[^"]*/service_sales_report"#', $html) === 0);
    $sv = call('app/bms/product/service_view.php', ['get' => ['id' => (string)$svcA]], true);
    ok('Simple POS mode: service page has no invoice section and still shows POS sales', strpos($sv, 'svcRecentInvoices') === false
        && (strpos($sv, 'svcRecentSales') !== false || stripos($sv, 'No sales yet') !== false || strpos($sv, (string)((require "$root/lang/sw.php")['No sales yet'] ?? 'No sales yet')) !== false) && stripos($sv, 'Fatal error') === false);
} finally {
    $setSimple($origSimple === false ? null : (string)$origSimple);
    ok('Simple POS setting restored', $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn() === $origSimple);
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
