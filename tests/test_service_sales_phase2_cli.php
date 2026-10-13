<?php
/**
 * tests/test_service_sales_phase2_cli.php — service-sales Phase 2.
 *
 *   php tests/test_service_sales_phase2_cli.php
 *
 * Drives the real endpoints as the local admin with ZZTEST data, then deletes
 * everything it created.
 *   1. service edit saves unit + category; an edit without category keeps it
 *   2. negative price and duplicate names rejected (same name in another shop is fine)
 *   3. services pass the sales-order stock check; service-only invoices need no warehouse
 *   4. lookup finds a service by Item Code
 *   5. description flows quotation -> sales order -> invoice and shows on view/print
 *   6. cancelling an invoice links its reversal to the original ledger entry
 *   7. forms carry the hand-typed-line warning; Simple POS service form has no Category
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

echo "\nBMS — Service sales Phase 2\n";
if (!$adminId || !$customerId || !$vatId || !$whId) {
    echo "  SKIP  missing fixture data (admin, customer, 18% VAT rate or warehouse)\n";
    exit(0);
}

try {
    $wh2 = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' AND warehouse_id <> $whId ORDER BY warehouse_id LIMIT 1")->fetchColumn();
    $catId = (int)$pdo->query("SELECT category_id FROM categories WHERE status = 'active' AND type = 'product' ORDER BY category_id LIMIT 1")->fetchColumn();
    $stockId = (int)$pdo->query("SELECT product_id FROM products WHERE is_service = 0 AND COALESCE(track_inventory,1) = 1 AND status = 'active' ORDER BY product_id LIMIT 1")->fetchColumn();
    $svcPost = fn(array $extra) => ['post' => array_merge(['product_name' => 'ZZTEST P2 Service', 'unit' => 'job', 'selling_price' => '1000',
        'tax_id' => (string)$vatId, 'warehouse_id' => (string)$whId, 'status' => 'active'], $extra)];

    // ─────────────────────────────────────────────────────────────────────
    section('1. Service edit: unit and category');
    $r = call('api/create_nip_product.php', $svcPost(['product_name' => 'ZZTEST P2 Annual Licence', 'description' => 'ZZTEST licence description', 'category_id' => (string)$catId]));
    $svc = (int)($r['product_id'] ?? 0); $made['products'][] = $svc;
    ok('service created with a category', $svc > 0 && (int)(one("SELECT category_id FROM products WHERE product_id = ?", [$svc])['category_id'] ?? 0) === $catId, $r['message'] ?? '');
    call('api/update_nip_product.php', $svcPost(['product_id' => (string)$svc, 'product_name' => 'ZZTEST P2 Annual Licence', 'description' => 'ZZTEST licence description', 'unit' => 'Year', 'category_id' => '']));
    $row = one("SELECT unit, category_id FROM products WHERE product_id = ?", [$svc]) ?: [];
    ok('edit saves the new unit (Year)', ($row['unit'] ?? '') === 'Year', json_encode($row));
    ok('edit with an empty category clears it', array_key_exists('category_id', $row) && $row['category_id'] === null);
    call('api/update_nip_product.php', $svcPost(['product_id' => (string)$svc, 'product_name' => 'ZZTEST P2 Annual Licence', 'description' => 'ZZTEST licence description', 'unit' => 'Year', 'category_id' => (string)$catId]));
    // Simple POS form: no category_id field at all
    call('api/update_nip_product.php', $svcPost(['product_id' => (string)$svc, 'product_name' => 'ZZTEST P2 Annual Licence', 'description' => 'ZZTEST licence description', 'unit' => 'Year']));
    ok('edit without a category field (Simple POS form) keeps the category', (int)(one("SELECT category_id FROM products WHERE product_id = ?", [$svc])['category_id'] ?? 0) === $catId);

    // ─────────────────────────────────────────────────────────────────────
    section('2. Validation');
    $r = call('api/create_nip_product.php', $svcPost(['product_name' => 'ZZTEST P2 negative', 'selling_price' => '-500']));
    if (!empty($r['product_id'])) $made['products'][] = (int)$r['product_id'];
    ok('negative price rejected on create', empty($r['success']) && stripos($r['message'] ?? '', 'negative') !== false, $r['message'] ?? '');
    $r = call('api/update_nip_product.php', $svcPost(['product_id' => (string)$svc, 'product_name' => 'ZZTEST P2 Annual Licence', 'selling_price' => '-1', 'unit' => 'Year']));
    ok('negative price rejected on edit', empty($r['success']), $r['message'] ?? '');
    $r = call('api/create_nip_product.php', $svcPost(['product_name' => 'zztest p2 annual licence ']));
    if (!empty($r['product_id'])) $made['products'][] = (int)$r['product_id'];
    ok('duplicate name in the same shop rejected (case/space-insensitive)', empty($r['success']) && stripos($r['message'] ?? '', 'already exists') !== false, $r['message'] ?? '');
    if ($wh2) {
        $r = call('api/create_nip_product.php', $svcPost(['product_name' => 'ZZTEST P2 Annual Licence', 'warehouse_id' => (string)$wh2]));
        if (!empty($r['product_id'])) $made['products'][] = (int)$r['product_id'];
        ok('same name in a different shop allowed', !empty($r['success']), $r['message'] ?? '');
    }
    $r = call('api/create_nip_product.php', $svcPost(['product_name' => 'ZZTEST P2 Second']));
    $svc2 = (int)($r['product_id'] ?? 0); $made['products'][] = $svc2;
    $r = call('api/update_nip_product.php', $svcPost(['product_id' => (string)$svc2, 'product_name' => 'ZZTEST P2 Annual Licence']));
    ok('renaming onto an existing name rejected', empty($r['success']), $r['message'] ?? '');

    // ─────────────────────────────────────────────────────────────────────
    section('3. Stock and warehouse rules');
    $soLine = ['product_id' => $svc, 'product_name' => 'ZZTEST P2 Annual Licence', 'description' => 'ZZTEST licence description', 'quantity' => '3', 'unit' => 'Year', 'unit_price' => '1000', 'tax_rate' => '18', 'discount_percent' => '0'];
    $r = call('api/account/save_sales_order.php', ['post' => ['customer_id' => (string)$customerId, 'order_date' => $today, 'warehouse_id' => (string)$whId, 'notes' => 'ZZTEST SO', 'items' => json_encode([$soLine])]]);
    $so = (int)($r['order_id'] ?? 0); if ($so) $made['sales_orders'][] = $so;
    ok('sales order with a warehouse and a service saves (no "Insufficient stock")', $so > 0, $r['message'] ?? '');
    $r = call('api/account/check_stock.php', ['post' => ['warehouse_id' => (string)$whId, 'items' => json_encode([$soLine])]]);
    ok('stock check reports no shortage for a service', !empty($r['success']) && empty($r['shortages'] ?? []), json_encode($r));
    $invNoWh = ['customer_id' => (string)$customerId, 'invoice_date' => $today, 'due_date' => $today, 'notes' => 'ZZTEST invoice',
        'items' => [['product_id' => $svc, 'product_name' => 'ZZTEST P2 Annual Licence', 'quantity' => '1', 'unit' => 'Year', 'unit_price' => '1000', 'tax_rate' => '18']]];
    $r = call('api/account/save_invoice.php', ['post' => $invNoWh]);
    $invA = (int)($r['invoice_id'] ?? 0); if ($invA) $made['invoices'][] = $invA;
    ok('service-only invoice saves without a warehouse even with the Service switch off', $invA > 0, $r['message'] ?? '');
    if ($stockId) {
        $withStock = $invNoWh;
        $withStock['items'][] = ['product_id' => $stockId, 'product_name' => 'stock item', 'quantity' => '1', 'unit' => 'pcs', 'unit_price' => '10', 'tax_rate' => '0'];
        $r = call('api/account/save_invoice.php', ['post' => $withStock]);
        if (!empty($r['invoice_id'])) $made['invoices'][] = (int)$r['invoice_id'];
        ok('invoice with a stock item still requires a warehouse', empty($r['success']) && stripos($r['message'] ?? '', 'required') !== false, $r['message'] ?? '');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('4. Search by Item Code');
    $code = (string)(one("SELECT contract_item_no FROM products WHERE product_id = ?", [$svc])['contract_item_no'] ?? '');
    $lk = call('api/account/get_products.php', ['get' => ['search' => $code, 'is_service' => '1']]);
    ok("lookup finds the service by its code ($code)", $code !== '' && in_array($svc, array_map(fn($x) => (int)$x['product_id'], $lk['data'] ?? []), true));

    // ─────────────────────────────────────────────────────────────────────
    section('5. Description from quotation to invoice');
    $r = call('api/account/save_quotation.php', ['post' => ['customer_id' => (string)$customerId, 'order_date' => $today, 'notes' => 'ZZTEST quotation', 'items' => json_encode([$soLine])]]);
    $qt = (int)($r['quotation_id'] ?? 0); $made['quotations'][] = $qt;
    ok('quotation line stores the description', (one("SELECT description FROM quotation_items WHERE order_id = ?", [$qt])['description'] ?? '') === 'ZZTEST licence description');
    ok('direct sales order line stores the description', (one("SELECT description FROM sales_order_items WHERE order_id = ?", [$so])['description'] ?? '') === 'ZZTEST licence description');
    call('api/account/review_quotation.php', ['post' => ['quotation_id' => (string)$qt]]);
    call('api/account/approve_quotation.php', ['post' => ['quotation_id' => (string)$qt]]);
    $r = call('api/account/convert_quote_to_order.php', ['post' => ['id' => (string)$qt]]);
    $so2 = (int)($r['sales_order_id'] ?? 0); $made['sales_orders'][] = $so2;
    ok('converted sales order keeps the description', (one("SELECT description FROM sales_order_items WHERE order_id = ?", [$so2])['description'] ?? '') === 'ZZTEST licence description');
    call('api/account/review_sales_order.php', ['post' => ['sales_order_id' => (string)$so2]]);
    call('api/account/approve_sales_order.php', ['post' => ['sales_order_id' => (string)$so2]]);
    $lines = call('api/account/get_sales_order_items.php', ['get' => ['order_id' => (string)$so2]])['data'] ?? [];
    $items = array_map(fn($l) => ['order_item_id' => $l['order_item_id'], 'product_id' => $l['product_id'], 'product_name' => $l['product_name'], 'description' => $l['description'],
        'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unit_price' => $l['unit_price'], 'tax_rate' => $l['tax_rate'], 'discount_percent' => $l['discount_percent']], $lines);
    $r = call('api/account/save_invoice.php', ['post' => ['customer_id' => (string)$customerId, 'order_id' => (string)$so2, 'invoice_date' => $today, 'due_date' => $today, 'notes' => 'ZZTEST invoice', 'items' => $items]]);
    $invB = (int)($r['invoice_id'] ?? 0); if ($invB) $made['invoices'][] = $invB;
    ok('invoice line stores the description', (one("SELECT description FROM invoice_items WHERE invoice_id = ?", [$invB])['description'] ?? '') === 'ZZTEST licence description', $r['message'] ?? '');
    foreach ([['app/bms/sales/quotations/quotation_view.php', $qt], ['app/bms/sales/quotations/print_quotation.php', $qt],
              ['app/bms/sales/sales_order_view.php', $so2], ['app/bms/sales/print_sales_order.php', $so2],
              ['app/bms/invoice/invoice_view.php', $invB], ['app/bms/invoice/invoice_print.php', $invB]] as [$page, $id]) {
        $html = call($page, ['get' => ['id' => (string)$id]], true);
        ok(basename($page) . ' shows the line description, no error',
            stripos($html, 'ZZTEST licence description') !== false && stripos($html, 'Fatal error') === false);
    }

    // ─────────────────────────────────────────────────────────────────────
    section('6. Reversal linked to the original entry');
    call('api/account/update_invoice_status.php', ['post' => ['invoice_id' => (string)$invA, 'status' => 'cancelled']]);
    $orig = (int)(one("SELECT entry_id FROM journal_entries WHERE entity_type = 'invoice' AND entity_id = ? AND status = 'posted'", [$invA])['entry_id'] ?? 0);
    $rev  = one("SELECT entry_id, reverses_entry_id FROM journal_entries WHERE entity_type = 'invoice_void' AND entity_id = ?", [$invA]) ?: [];
    ok('revenue reversal points at the original invoice entry', $orig > 0 && (int)($rev['reverses_entry_id'] ?? 0) === $orig, json_encode([$orig, $rev]));

    // ─────────────────────────────────────────────────────────────────────
    section('7. Forms');
    foreach (['app/bms/invoice/invoice_create.php', 'app/bms/sales/quotations/quotation_create.php', 'app/bms/sales/sales_order_create.php'] as $page) {
        $html = call($page, ['get' => []], true);
        ok(basename($page) . ' loads the hand-typed-line warning', strpos($html, 'function bmsConfirmUnlinkedLines') !== false && stripos($html, 'Fatal error') === false);
    }
    require_once "$root/core/pos_nav.php";
    $simpleForm = posSimpleModeEnabled() && !(function_exists('advancedProductEnabled') && advancedProductEnabled());
    $html = call('app/bms/product/services.php', ['get' => []], true);
    if ($simpleForm) {
        ok('Simple POS service form has no Category field', strpos($html, 'id="svc_category_id"') === false && strpos($html, 'id="edit_svc_category_id"') === false && stripos($html, 'Fatal error') === false);
    } else {
        ok('full-mode service form has the Category field', strpos($html, 'id="svc_category_id"') !== false && stripos($html, 'Fatal error') === false);
    }
    ok('service edit form no longer has a duplicate unit field id', substr_count($html, 'id="edit_svc_unit"') <= 1);
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
