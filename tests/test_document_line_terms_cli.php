<?php
/**
 * Price term on Quotation / Sales Order / Invoice lines (customer_subscription_plan.md Phase 2)
 *   php tests/test_document_line_terms_cli.php
 *
 * A: pure helpers (no database — runs in CI)
 * B: the real save endpoints + quote→SO conversion + SO→invoice, on the local DB.
 *    Everything created is named/linked 'DLT-TEST' and removed (incl. its ledger entries). Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
if (is_file("$root/includes/config.php")) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
require_once "$root/core/service_price_terms.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    // exit() below stops any later shutdown function, so test data is removed here first.
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

section('A. helpers');
ok(sptStripTermLine("Hosting\nTerm: 3 Months") === 'Hosting', 'strips a previous Term line');
ok(sptStripTermLine("Term: 1 Year") === '', 'a description that is only a Term line becomes empty');
ok(sptStripTermLine("Line one\nTerm: X\nLine three") === "Line one\nLine three", 'keeps the other lines');
ok(sptStripTermLine(null) === '', 'null safe');
$js = file_get_contents("$root/assets/js/line_price_terms.js");
ok(strpos($js, 'window.BmsLineTerms') !== false && strpos($js, 'selectName') !== false, 'shared line-term script present');
foreach (['app/bms/sales/quotations/quotation_form.php', 'app/bms/sales/sales_order_create.php', 'app/bms/sales/sales_order_edit.php',
          'app/bms/invoice/invoice_create.php', 'app/bms/invoice/invoice_edit.php'] as $f) {
    $src = file_get_contents("$root/$f");
    ok(strpos($src, "includes/line_price_terms_js.php") !== false && strpos($src, 'BmsLineTerms.attach') !== false
       && strpos($src, 'BmsLineTerms.missing') !== false, "$f offers the term and blocks saving without one");
}
foreach (['api/account/save_quotation.php' => 'quotation_items', 'api/account/save_sales_order.php' => 'sales_order_items', 'api/account/save_invoice.php' => 'invoice_items'] as $f => $tbl) {
    $src = file_get_contents("$root/$f");
    ok(strpos($src, 'sptResolveLineTerm(') !== false && strpos($src, "sptStampLine(\$pdo, '$tbl'") !== false, "$f verifies the term against the DB and records it");
}

if (!is_file("$root/includes/config.php")) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B ──────────────────────────────────────────────────────────────────────
global $pdo;
sptEnsureSchema($pdo);
ob_start(); sptEnsureLineColumns($pdo); ob_end_clean();

$uid  = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid  = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
$cust = (int)$pdo->query("SELECT customer_id FROM customers WHERE status = 'active' ORDER BY customer_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid || !$cust) { echo "SKIP B: needs an admin, a warehouse and a customer\n"; exit; }

$made = ['qt' => [], 'so' => [], 'inv' => []];
$cleanup = function () use ($pdo, &$made) {
    $in = fn(array $a) => $a ? implode(',', array_map('intval', $a)) : '0';
    // By marker too, so rows left by an interrupted earlier run are removed as well.
    $made['inv'] = array_merge($made['inv'], $pdo->query("SELECT invoice_id FROM invoices WHERE notes = 'DLT-TEST'")->fetchAll(PDO::FETCH_COLUMN));
    $made['qt']  = array_merge($made['qt'],  $pdo->query("SELECT sales_order_id FROM quotations WHERE reference = 'DLT-TEST'")->fetchAll(PDO::FETCH_COLUMN));
    $made['so']  = array_merge($made['so'],  $pdo->query("SELECT sales_order_id FROM sales_orders WHERE reference = 'DLT-TEST'")->fetchAll(PDO::FETCH_COLUMN));
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
    $ids = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'DLT-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $p = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM service_price_terms WHERE product_id IN ($p)");
        $pdo->exec("DELETE FROM products WHERE product_id IN ($p)");
    }
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'dlt_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function callApi(string $file, array $post): array {
    global $root, $uid;
    $out = runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';
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
function rowsOf(string $table, string $fk, int $id): array {
    global $pdo; $s = $pdo->prepare("SELECT * FROM `$table` WHERE `$fk` = ? ORDER BY 1"); $s->execute([$id]); return $s->fetchAll(PDO::FETCH_ASSOC);
}
$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];

// Two services: one priced by term, one plain.
$r = callApi('api/create_nip_product.php', $svcBase + ['product_name' => 'DLT-TEST Hosting', 'description' => 'Cloud hosting',
    'price_terms' => json_encode([
        ['term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10000, 'is_default' => 1],
        ['term_label' => '3 Months', 'duration_value' => 3, 'duration_unit' => 'month', 'price' => 25000],
        ['term_label' => '1 Year', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 85000],
    ])]);
$svc = (int)($r['product_id'] ?? 0);
$r = callApi('api/create_nip_product.php', $svcBase + ['product_name' => 'DLT-TEST Setup', 'selling_price' => '50000']);
$plain = (int)($r['product_id'] ?? 0);
$r = callApi('api/create_nip_product.php', $svcBase + ['product_name' => 'DLT-TEST Other',
    'price_terms' => json_encode([['term_label' => 'Weekly', 'duration_value' => 1, 'duration_unit' => 'week', 'price' => 1000]])]);
$other = (int)($r['product_id'] ?? 0);
$terms = [];
foreach (sptForProduct($pdo, $svc) as $t) $terms[$t['term_label']] = (int)$t['term_id'];
$otherTerm = (int)(sptForProduct($pdo, $other)[0]['term_id'] ?? 0);
if (!$svc || !$plain || !$other || count($terms) !== 3) { ok(false, 'test services created'); exit; }

$line = fn(int $pid, string $name, float $price, $termId = null, string $desc = '') => [
    'product_id' => $pid, 'product_name' => $name, 'sku' => '', 'quantity' => 1, 'unit' => 'job', 'unit_price' => $price,
    'tax_rate' => 0, 'discount_percent' => 0, 'description' => $desc, 'price_term_id' => $termId];
$qtPost = fn(array $items, int $id = 0) => ['quotation_id' => $id ?: '', 'customer_id' => $cust, 'order_date' => date('Y-m-d'),
    'warehouse_id' => $wid, 'currency' => 'TZS', 'reference' => 'DLT-TEST', 'items' => json_encode($items)];

section('B1. quotation with a term');
$r = callApi('api/account/save_quotation.php', $qtPost([
    $line($svc, 'DLT-TEST Hosting', 25000, $terms['3 Months'], 'Cloud hosting'),
    $line($plain, 'DLT-TEST Setup', 50000, null, 'One-off setup'),
]));
$qt = (int)($r['quotation_id'] ?? $r['id'] ?? 0);
if (!$qt) $qt = (int)$pdo->query("SELECT MAX(sales_order_id) FROM quotations WHERE reference = 'DLT-TEST'")->fetchColumn();
if ($qt) $made['qt'][] = $qt;
ok(!empty($r['success']) && $qt > 0, 'quotation saved through the real endpoint' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$items = rowsOf('quotation_items', 'order_id', $qt);
$h = array_values(array_filter($items, fn($i) => (int)$i['product_id'] === $svc))[0] ?? [];
$p = array_values(array_filter($items, fn($i) => (int)$i['product_id'] === $plain))[0] ?? [];
ok((int)($h['price_term_id'] ?? 0) === $terms['3 Months'] && ($h['price_term_label'] ?? '') === '3 Months'
   && (int)$h['term_duration_value'] === 3 && $h['term_duration_unit'] === 'month', 'term id + label + length snapshot stored on the line');
ok(($h['description'] ?? '') === "Cloud hosting\nTerm: 3 Months", 'description carries "Term: 3 Months" so prints show it');
ok($p && $p['price_term_id'] === null && $p['description'] === 'One-off setup', 'a line without a term is stored exactly as before');

section('B2. re-save switches term without duplicating the Term line');
$r = callApi('api/account/save_quotation.php', $qtPost([$line($svc, 'DLT-TEST Hosting', 85000, $terms['1 Year'], $h['description'])], $qt));
$h = rowsOf('quotation_items', 'order_id', $qt)[0] ?? [];
ok(!empty($r['success']) && ($h['price_term_label'] ?? '') === '1 Year', 'term switched to 1 Year');
ok(($h['description'] ?? '') === "Cloud hosting\nTerm: 1 Year", 'exactly one Term line, the new one');

section('B3. a term that does not belong to the service is refused');
$before = (int)$pdo->query("SELECT COUNT(*) FROM quotations WHERE reference = 'DLT-TEST'")->fetchColumn();
$r = callApi('api/account/save_quotation.php', $qtPost([$line($svc, 'DLT-TEST Hosting', 1000, $otherTerm)]));
$after = (int)$pdo->query("SELECT COUNT(*) FROM quotations WHERE reference = 'DLT-TEST'")->fetchColumn();
ok(empty($r['success']) && stripos($r['message'] ?? '', 'does not belong') !== false, 'refused: ' . ($r['message'] ?? ''));
ok($before === $after, 'nothing saved (the whole quotation rolled back)');

section('B4. approved quotation → sales order keeps the term');
$pdo->prepare("UPDATE quotations SET status = 'approved' WHERE sales_order_id = ?")->execute([$qt]);   // the workflow itself is not under test
$r = callApi('api/account/convert_quote_to_order.php', ['quotation_id' => $qt, 'id' => $qt]);
$so = (int)$pdo->query("SELECT converted_to_so_id FROM quotations WHERE sales_order_id = $qt")->fetchColumn();
if ($so) $made['so'][] = $so;
ok(!empty($r['success']) && $so > 0, 'converted to a sales order' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$soLine = rowsOf('sales_order_items', 'order_id', $so)[0] ?? [];
ok((int)($soLine['price_term_id'] ?? 0) === $terms['1 Year'] && ($soLine['price_term_label'] ?? '') === '1 Year', 'the sales-order line carries the same term (all three tables share the columns)');

section('B5. invoice from the sales order inherits the term');
$invPost = fn(array $items, array $extra = []) => $extra + ['customer_id' => $cust, 'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+14 days')), 'currency' => 'TZS', 'warehouse_id' => $wid, 'notes' => 'DLT-TEST', 'items' => $items];
$r = callApi('api/account/save_invoice.php', $invPost([[
    'product_id' => $svc, 'product_name' => 'DLT-TEST Hosting', 'order_item_id' => $soLine['order_item_id'] ?? 0,
    'quantity' => 1, 'unit' => 'job', 'unit_price' => 85000, 'tax_rate' => 0, 'discount_percent' => 0,
    'description' => $soLine['description'] ?? '',
]], ['order_id' => $so]));
$inv = (int)($r['invoice_id'] ?? 0);
if (!$inv) $inv = (int)$pdo->query("SELECT MAX(invoice_id) FROM invoices WHERE notes = 'DLT-TEST'")->fetchColumn();
if ($inv) $made['inv'][] = $inv;
ok(!empty($r['success']) && $inv > 0, 'invoice saved from the sales order' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$il = rowsOf('invoice_items', 'invoice_id', $inv)[0] ?? [];
ok((int)($il['price_term_id'] ?? 0) === $terms['1 Year'] && (int)$il['term_duration_value'] === 1 && $il['term_duration_unit'] === 'year',
   'invoice line inherited the sales-order line\'s term (posted no term itself)');
ok(substr_count((string)($il['description'] ?? ''), 'Term: ') === 1, 'one Term line on the invoice');

section('B6. stand-alone invoice with a term');
$r = callApi('api/account/save_invoice.php', $invPost([[
    'product_id' => $svc, 'product_name' => 'DLT-TEST Hosting', 'quantity' => 2, 'unit' => 'job', 'unit_price' => 10000,
    'tax_rate' => 0, 'discount_percent' => 0, 'description' => 'Cloud hosting', 'price_term_id' => $terms['1 Month'],
]]));
$inv2 = (int)($r['invoice_id'] ?? 0);
if (!$inv2) $inv2 = (int)$pdo->query("SELECT MAX(invoice_id) FROM invoices WHERE notes = 'DLT-TEST'")->fetchColumn();
if ($inv2 && $inv2 !== $inv) $made['inv'][] = $inv2;
$il2 = rowsOf('invoice_items', 'invoice_id', $inv2)[0] ?? [];
ok(!empty($r['success']) && (int)($il2['price_term_id'] ?? 0) === $terms['1 Month'], 'invoice line stores the chosen term');
ok((float)$pdo->query("SELECT grand_total FROM invoices WHERE invoice_id = $inv2")->fetchColumn() === 20000.0, 'totals unchanged by the term logic (2 × 10,000)');

section('B7. history is a snapshot');
callApi('api/update_nip_product.php', $svcBase + ['product_id' => $svc, 'product_name' => 'DLT-TEST Hosting',
    'price_terms' => json_encode([
        ['term_id' => $terms['1 Month'], 'term_label' => 'Monthly plan', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 12000, 'is_default' => 1],
    ])]);
$il2 = rowsOf('invoice_items', 'invoice_id', $inv2)[0] ?? [];
ok(($il2['price_term_label'] ?? '') === '1 Month', 'renaming the term later does not rewrite the invoice line');
$st = $pdo->prepare("SELECT status FROM service_price_terms WHERE term_id = ?");
$st->execute([$terms['1 Year']]);
ok($st->fetchColumn() === 'inactive', 'a removed term that documents used is kept as inactive, not deleted');
$st->execute([$terms['3 Months']]);
ok($st->fetchColumn() === 'deleted', 'a removed term that no document kept is soft-deleted');
