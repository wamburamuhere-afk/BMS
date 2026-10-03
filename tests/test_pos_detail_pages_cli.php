<?php
/**
 * tests/test_pos_detail_pages_cli.php — pos_detail_pages_plan.md
 * (Customers · Suppliers · Products · Services list menus + detail pages).
 *
 * Every page is rendered for real in its own PHP process
 * (tests/helpers/page_request.php) as a forged admin, in two tenant shapes:
 *   POS  = only POS + Warehouse on, Simple Mode on, supplier access on
 *          (the shop.demo set-up the problems were found on);
 *   FULL = every module on, Simple Mode off (nothing may disappear there).
 * Settings are switched in the DB for the run and restored at the end.
 *
 *   php tests/test_pos_detail_pages_cli.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../roots.php';
require_once ROOT_DIR . '/core/stock_ledger.php';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✓ $m\n"; } else { $fail++; echo "  ✗ $m\n"; } }
function section($t) { echo "\n── $t\n"; }

$POS = [];
foreach (allFeatureKeys() as $k) $POS[$k] = false;
$POS['pos'] = $POS['warehouses'] = true;

/** Render a page → [code, html]. $features null = no tenant (everything on). */
function render(string $file, array $get = [], ?array $features = null, int $uid = 0, bool $admin = true): array
{
    global $ADMIN;
    $get += ['__lang' => 'en'];   // assertions are written against English unless a test asks for sw
    $args = [PHP_BINARY, __DIR__ . '/helpers/page_request.php', $file, (string)($uid ?: $ADMIN), $admin ? '1' : '0', json_encode($get)];
    if ($features !== null) $args[] = json_encode($features);
    $p = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_DIR);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $code = preg_match('/HTTP_CODE=(\d+)/', $err, $m) ? (int)$m[1] : 0;
    if (preg_match('/(PHP )?(Fatal error|Warning|Notice|Deprecated|Uncaught)[: ]/', $err . preg_replace('#<script\b.*?</script>#s', '', $out), $w)) {
        echo "    ! PHP said '{$w[2]}' rendering $file: " . substr(trim(strip_tags($err)), 0, 300) . "\n";
        $GLOBALS['__phpNoise'][] = $file;
    }
    return [$code, $out];
}
/** Internal clean routes linked from the page body (header/nav excluded). */
function linkedRoutes(string $html): array
{
    global $routes;
    $body = preg_replace('#^.*?<!-- end navbar -->#s', '', $html);   // best effort; nav links are checked by their own suites
    preg_match_all('#href="/([a-z0-9_/\-]+)(?:\?[^"]*)?"#i', $body, $m);
    return array_values(array_unique(array_filter($m[1], fn($r) => isset($routes[$r]))));
}
function setSetting(string $k, ?string $v): void
{
    global $pdo;
    if ($v === null) { $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$k]); return; }
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$k, $v]);
}

$ADMIN = (int)$pdo->query("SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id WHERE r.is_admin = 1 AND u.is_active = 1 ORDER BY u.user_id LIMIT 1")->fetchColumn();
// Fixtures: existing records (read-only use).
$PRODUCT  = (int)$pdo->query("SELECT sm.product_id FROM stock_movements sm JOIN products p ON p.product_id = sm.product_id
                               WHERE p.is_service = 0 AND p.status = 'active' AND sm.movement_type = 'sale_out'
                               GROUP BY sm.product_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$SERVICE  = (int)$pdo->query("SELECT product_id FROM products WHERE is_service = 1 AND status = 'active' ORDER BY product_id LIMIT 1")->fetchColumn();
$CUSTOMER = (int)$pdo->query("SELECT customer_id FROM pos_sales WHERE customer_id IS NOT NULL GROUP BY customer_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$SUPPLIER = (int)$pdo->query("SELECT supplier_id FROM suppliers WHERE status = 'active' ORDER BY supplier_id LIMIT 1")->fetchColumn();
if (!$ADMIN || !$PRODUCT || !$SERVICE || !$CUSTOMER || !$SUPPLIER) { echo "Missing fixtures (admin/product/service/customer/supplier).\n"; exit(1); }
echo "Fixtures: admin #$ADMIN product #$PRODUCT service #$SERVICE customer #$CUSTOMER supplier #$SUPPLIER\n";

$saved = [];
foreach (['pos_simple_mode', 'pos_supplier_access'] as $k) {
    $v = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?"); $v->execute([$k]);
    $saved[$k] = $v->fetchColumn(); $saved[$k] = $saved[$k] === false ? null : $saved[$k];
}
$pages = [
    'suppliers'        => ['app/bms/Suppliers/suppliers.php', []],
    'supplier_details' => ['app/bms/Suppliers/supplier_details.php', ['id' => (string)$SUPPLIER]],
    'products'         => ['app/bms/product/products.php', []],
    'product_view'     => ['app/bms/product/product_view.php', ['id' => (string)$PRODUCT]],
    'services'         => ['app/bms/product/services.php', []],
    'service_view'     => ['app/bms/product/service_view.php', ['id' => (string)$SERVICE]],
    'customers'        => ['app/bms/customer/customers.php', []],
    'customer_details' => ['app/bms/customer/customer_details.php', ['id' => (string)$CUSTOMER]],
];

try {
    // ═════════════════════════════════════════════════════════════════
    section('0. bmsRouteAvailable() — the router\'s own rule');
    $GLOBALS['__bms_features'] = null;
    ok(bmsRouteAvailable('pos') && bmsRouteAvailable('vendor_statement') && bmsRouteAvailable('purchase_order_create'), 'no tenant: mapped routes are available');
    ok(!bmsRouteAvailable('no_such_route_xyz'), 'unmapped route is not available');
    $GLOBALS['__bms_features'] = $POS;
    foreach (['vendor_statement', 'purchase_order_create', 'purchase_orders', 'product_analysis'] as $r) ok(!bmsRouteAvailable($r), "POS+Warehouse only: '$r' not available (the router 404s it)");
    foreach (['pos', 'products', 'products/view', 'product_edit', 'stock_movements', 'stock_transfers', 'services', 'customers'] as $r) ok(bmsRouteAvailable($r), "POS+Warehouse only: '$r' available");
    $GLOBALS['__bms_features'] = null;

    // ── Render every page in both shapes once; later sections reuse them.
    setSetting('pos_simple_mode', '1'); setSetting('pos_supplier_access', '1');
    $H = ['POS' => [], 'FULL' => []];
    foreach ($pages as $k => [$file, $get]) $H['POS'][$k] = render($file, $get, $POS);
    $AVAIL = json_decode(render('--routes', [], $POS)[1], true) ?: [];   // with the POS settings, in a fresh process
    setSetting('pos_simple_mode', '0');
    foreach ($pages as $k => [$file, $get]) $H['FULL'][$k] = render($file, $get, null);
    setSetting('pos_simple_mode', '1');

    // ═════════════════════════════════════════════════════════════════
    section('A. No link to a page that would 404');
    foreach ($H['POS'] as $k => [$code, $html]) {
        ok($code === 200 && strlen($html) > 5000, "POS: $k renders (200)");
        $dead = array_values(array_filter(linkedRoutes($html), fn($r) => empty($AVAIL[$r])));
        ok(!$dead, "POS: $k links only to pages that open" . ($dead ? ' — dead: ' . implode(', ', $dead) : ''));
    }
    foreach ($H['FULL'] as $k => [$code]) ok($code === 200, "FULL: $k renders (200)");
    ok(strpos($H['FULL']['suppliers'][1], 'href="/vendor_statement?') !== false, 'FULL: suppliers still offer View Account');
    ok(strpos($H['FULL']['products'][1], 'href="/purchase_order_create?') !== false, 'FULL: products still offer Create Purchase Order');
    ok(strpos($H['POS']['products'][1], 'href="/pos?restock=1&amp;product_id=') !== false, 'POS: products offer "Receive Stock" (POS restock, product pre-selected)');
    ok(strpos($H['POS']['products'][1], '/purchase_order_create') === false, 'POS: no "Create Purchase Order"');
    ok(strpos($H['POS']['suppliers'][1], '/vendor_statement') === false, 'POS: no "View Account"');
    ok(strpos($H['POS']['product_view'][1], '/purchase_orders') === false && strpos($H['POS']['product_view'][1], '/product_analysis') === false,
       'POS: product page has no Purchase Orders / Sales Report links');

    // A3 — POS restock pre-select
    [, $pos] = render('app/bms/pos/pos.php', ['restock' => '1', 'product_id' => (string)$PRODUCT], $POS);
    ok(preg_match('/const restockPre = \{"id":' . $PRODUCT . ',"text":"[^"]+"\}/', $pos) === 1, 'POS ?restock=1&product_id=N pre-selects that product');
    [, $pos] = render('app/bms/pos/pos.php', ['restock' => '1', 'product_id' => (string)$SERVICE], $POS);
    ok(strpos($pos, 'const restockPre = null') !== false, 'a service id is not pre-selected (services are not restocked)');
    [, $pos] = render('app/bms/pos/pos.php', ['restock' => '1', 'product_id' => '1 OR 1=1'], $POS);
    ok(strpos($pos, 'const restockPre = null') !== false, 'a non-numeric product_id is ignored');

    // A5/A6 — movement sign + who
    $html = $H['POS']['product_view'][1];
    preg_match('#id="movements".*?</tbody>#s', $html, $mv);
    $mvText = preg_replace('/\s+/', ' ', strip_tags($mv[0] ?? ''));
    $rows = $pdo->prepare("SELECT movement_type, stock_before, stock_after FROM stock_movements WHERE product_id = ? ORDER BY created_at DESC LIMIT 10");
    $rows->execute([$PRODUCT]);
    $expOut = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $in = ($r['stock_before'] !== null && $r['stock_after'] !== null && (float)$r['stock_after'] != (float)$r['stock_before'])
            ? (float)$r['stock_after'] > (float)$r['stock_before'] : stockMovementIsInbound($r['movement_type']);
        if (!$in) $expOut++;
    }
    ok($expOut > 0 && substr_count($mvText, '−') === $expOut, "every outgoing movement shows '−' ($expOut expected, " . substr_count($mvText, '−') . ' shown)');
    ok(preg_match('/Sale [^+−]*?\+\d/u', $mvText) === 0, "no sale shows '+'");
    ok(strpos($mvText, '@') === false, 'Adjusted By shows names, not e-mail addresses');

    // A8 — Recent Sales receipt opens the POS receipt, never a sales order
    foreach (['POS', 'FULL'] as $mode) {
        $pv = $H[$mode]['product_view'][1];
        ok(strpos($pv, '/sales_order_view') === false, "$mode: product Recent Sales no longer links to sales_order_view");
    }
    $hasSale = $pdo->prepare("SELECT COUNT(*) FROM pos_sale_items psi JOIN pos_sales ps ON ps.sale_id = psi.sale_id WHERE psi.product_id = ? AND ps.sale_status = 'completed'");
    $hasSale->execute([$PRODUCT]);
    if ((int)$hasSale->fetchColumn() > 0) ok(strpos($H['POS']['product_view'][1], 'api/pos/print_receipt.php?id=') !== false, 'product Recent Sales receipt opens the POS receipt');

    // A7 — Swahili
    $sw = include ROOT_DIR . '/lang/sw.php';
    ok(($sw['Nobody owes you anything right now'] ?? '') === 'Hakuna mteja anayedaiwa kwa sasa', 'sw: credit list empty message corrected');
    ok(($sw['This customer owes nothing right now'] ?? '') === 'Mteja huyu hadaiwi chochote kwa sasa', 'sw: customer page message');
    ok(strpos($H['POS']['customer_details'][1], 'Nobody owes you anything right now') === false, 'customer page no longer uses the list wording');

    // ═════════════════════════════════════════════════════════════════
    section('B1. Suppliers — Simple mode: Activate/Deactivate only, nothing hidden');
    $sup = $H['POS']['suppliers'][1];
    ok(!preg_match("/updateStatus\(\d+, 'suspended'\)/", $sup) && !preg_match("/updateStatus\(\d+, 'blacklisted'\)/", $sup), 'POS: no Suspend / Blacklist actions');
    $stuck = $pdo->query("SELECT supplier_id FROM suppliers WHERE status IN ('suspended','blacklisted') ORDER BY supplier_id")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_slice($stuck, 0, 3) as $sid) ok(strpos($sup, "updateStatus($sid, 'active')") !== false, "POS: suspended/blacklisted supplier #$sid can be re-activated");
    if ($stuck) ok(strpos($sup, 'id="stat-blacklisted-suppliers"') !== false, 'POS: Suspended/Blacklisted cards still shown while such suppliers exist (no data hidden)');
    ok(preg_match("/updateStatus\(\d+, 'suspended'\)/", $H['FULL']['suppliers'][1]) === 1, 'FULL: Suspend still offered');
    // Other branch: no supplier in those states → the cards/filter give way to "Inactive".
    $risk = $pdo->query("SELECT supplier_id, status FROM suppliers WHERE status IN ('suspended','blacklisted')")->fetchAll(PDO::FETCH_KEY_PAIR);
    try {
        if ($risk) $pdo->exec("UPDATE suppliers SET status = 'inactive' WHERE supplier_id IN (" . implode(',', array_map('intval', array_keys($risk))) . ")");
        [, $plain] = render('app/bms/Suppliers/suppliers.php', [], $POS);
        ok(strpos($plain, 'id="stat-suspended-suppliers"') === false && strpos($plain, '<option value="blacklisted">') === false, 'POS, none suspended/blacklisted: those cards + filter options gone');
        ok(substr_count($plain, '<div class="col-6 col-lg-3 mb-3">') === 3, 'POS: stat row = Suppliers, Active, Inactive');
        ok(preg_match_all('#<div\b#', $plain) === preg_match_all('#</div>#', $plain), 'POS: page markup stays balanced');
    } finally {
        $u = $pdo->prepare("UPDATE suppliers SET status = ? WHERE supplier_id = ?");
        foreach ($risk as $sid => $st) $u->execute([$st, $sid]);
    }

    section('B2. Supplier details');
    $sd = $H['POS']['supplier_details'][1];
    ok(strpos($sd, 'Projects Linked') === false && strpos($sd, 'id="pane-projects"') === false, 'POS: no Projects tab / "Projects Linked"');
    [, $sdsw] = render('app/bms/Suppliers/supplier_details.php', ['id' => (string)$SUPPLIER, '__lang' => 'sw'], $POS);
    foreach (['Taarifa za Msambazaji', 'Rudi kwa Wasambazaji', 'Taarifa za Rekodi', 'Kumbukumbu za Mabadiliko'] as $w) ok(strpos($sdsw, $w) !== false, "sw: supplier page shows '$w'");
    ok(strpos($sdsw, '> Supplier View<') === false && strpos($sdsw, 'Record Information<') === false, 'sw: no English header/section titles left');

    section('B3. Product details');
    $pv = $H['POS']['product_view'][1];
    $reserved = (float)$pdo->query("SELECT COALESCE(SUM(reserved_quantity),0) FROM product_stocks WHERE product_id = $PRODUCT")->fetchColumn();
    ok(($reserved > 0) === (strpos($pv, "t('Reserved Stock')") !== false || preg_match('#Reserved Stock</small>#', $pv) === 1), 'POS: "Reserved Stock" shown only when stock is actually reserved (' . $reserved . ')');
    $grn = (int)$pdo->query("SELECT COUNT(*) FROM product_batches pb JOIN purchase_receipts pr ON pr.receipt_id = pb.receipt_id WHERE pb.product_id = $PRODUCT")->fetchColumn();
    $hasBatches = (int)$pdo->query("SELECT COUNT(*) FROM product_batches WHERE product_id = $PRODUCT")->fetchColumn() > 0;
    if ($hasBatches) ok(($grn > 0) === (strpos($pv, '>Source GRN<') !== false), 'POS: "Source GRN" column only when a batch came from a GRN');
    ok(strpos($pv, 'No reason provided') === false, 'POS: no "No reason provided" noise');
    [, $pvsw] = render('app/bms/product/product_view.php', ['id' => (string)$PRODUCT, '__lang' => 'sw'], $POS);
    foreach (['Taarifa za Bidhaa', 'Taarifa za Bei', 'Mienendo ya Stoku ya Karibuni', 'Takwimu za Mauzo'] as $w) ok(strpos($pvsw, $w) !== false, "sw: product page shows '$w'");
    foreach (['Basic Information<', 'Pricing Information<', 'Recent Stock Movements<', 'No reason provided', ' units<'] as $w) ok(strpos($pvsw, $w) === false, "sw: no English '" . trim($w, '<') . "'");

    section('B4. Services list');
    $sl = $H['POS']['services'][1];
    ok(strpos($sl, 'Services you sell at the POS') !== false && strpos($sl, 'used in Sales, Invoices') === false, 'POS: subtitle no longer mentions Sales/Invoices');
    ok(strpos($H['FULL']['services'][1], 'used in Sales, Invoices') !== false, 'FULL: original subtitle kept');
    ok(preg_match('#<hr class="dropdown-divider"></li>\s*</ul>#', $sl) === 0, 'no menu ends with a separator');
    $shopWords = strpos($sl, 'Non-Inventory Products') === false;   // same wLabel() rule as the page title
    ok($shopWords ? (strpos($sl, 'Total Services') !== false && strpos($sl, 'Service Name') !== false) : (strpos($sl, 'Total Products') !== false), 'stats/columns use the same word as the page title');
    $usedCats = (int)$pdo->query("SELECT COUNT(DISTINCT category_id) FROM products WHERE is_service = 1 AND status <> 'deleted' AND category_id IS NOT NULL")->fetchColumn();
    preg_match('#id="svcCategoryFilter".*?</select>#s', $sl, $cf);
    ok(substr_count($cf[0] ?? '', '<option value="') - 1 <= $usedCats, 'category filter lists only categories services use');

    section('B5. Service details');
    $sv = $H['POS']['service_view'][1];
    ok(strpos($sv, 'Assembly Information') === false && strpos($sv, 'Contract Item No') === false, 'POS: no Assembly / Contract Item No');
    ok(strpos($sv, 'SKU:') === false, 'POS: no SKU');
    ok(strpos($sv, 'Product Dashboard') === false && strpos($sv, '>SELLING<') === false, 'POS: no "Product Dashboard" / hard-coded SELLING label');
    $svcCost = (float)$pdo->query("SELECT cost_price FROM products WHERE product_id = $SERVICE")->fetchColumn();
    ok(($svcCost > 0) === (strpos($sv, "fw-bold text-danger\" style=\"white-space: nowrap;\">") !== false), 'POS: Cost/Margin shown only when the service has a cost (' . $svcCost . ')');
    ok(strpos($H['FULL']['service_view'][1], 'Assembly Information') !== false, 'FULL: Assembly information kept');

    section('B6. Customer details');
    $cd = $H['POS']['customer_details'][1];
    $limit = (float)$pdo->query("SELECT credit_limit FROM customers WHERE customer_id = $CUSTOMER")->fetchColumn();
    ok(($limit > 0) === (strpos($cd, 'Available Credit') !== false), 'POS: "Available Credit" only with a credit limit (' . $limit . ')');
    [, $cdsw] = render('app/bms/customer/customer_details.php', ['id' => (string)$CUSTOMER, '__lang' => 'sw'], $POS);
    foreach (['Taarifa za Mteja', 'Taarifa kwa Ufupi', 'Amesajiliwa', 'Historia ya Madeni'] as $w) ok(strpos($cdsw, $w) !== false, "sw: customer page shows '$w'");
    [, $cden] = render('app/bms/customer/customer_details.php', ['id' => (string)$CUSTOMER, '__lang' => 'en'], $POS);
    ok(strpos($cden, 'Credit History') !== false && strpos($cden, 'Amekopa Mara') === false, 'en: Madeni labels are English for English users (were hard-coded Swahili)');

    // ═════════════════════════════════════════════════════════════════
    section('C1. Supplier — Stock Received (from POS "Receive Stock")');
    $WH = (int)$pdo->query("SELECT warehouse_id FROM warehouses ORDER BY warehouse_id LIMIT 1")->fetchColumn();
    $batchId = 0;
    try {
        $pdo->prepare("INSERT INTO product_batches (product_id, warehouse_id, supplier_id, batch_number, quantity_received, quantity_remaining, unit_cost, created_at)
                       VALUES (?, ?, ?, 'PDP-TEST', 7, 7, 1234.50, NOW())")->execute([$PRODUCT, $WH, $SUPPLIER]);
        $batchId = (int)$pdo->lastInsertId();
        $agg = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(quantity_received * unit_cost),0) t FROM product_batches WHERE supplier_id = $SUPPLIER")->fetch(PDO::FETCH_ASSOC);
        [, $sd] = render('app/bms/Suppliers/supplier_details.php', ['id' => (string)$SUPPLIER], $POS);
        ok(strpos($sd, 'id="pane-received"') !== false && preg_match('#class="tab-pane fade show active" id="pane-received"#', $sd) === 1, 'POS: "Stock Received" tab present and opened by default');
        ok(strpos($sd, format_currency((float)$agg['t'])) !== false, 'total bought = Σ qty × unit cost (' . format_currency((float)$agg['t']) . ')');
        ok(preg_match('#Deliveries.*?#s', $sd) && strpos($sd, '>' . (int)$agg['n'] . '</span>') !== false, 'tab badge shows the number of deliveries (' . (int)$agg['n'] . ')');
        ok(strpos($sd, format_currency(7 * 1234.50)) !== false && strpos($sd, 'products/view?id=' . $PRODUCT) !== false, 'the delivery row shows the line total and links to the product');
        ok(substr_count($sd, 'show active') === 1 || preg_match_all('#tab-pane fade show active#', $sd) === 1, 'exactly one tab pane is open');
        setSetting('pos_simple_mode', '0');
        [, $sdF] = render('app/bms/Suppliers/supplier_details.php', ['id' => (string)$SUPPLIER], null);
        setSetting('pos_simple_mode', '1');
        ok(strpos($sdF, 'id="pane-received"') !== false && preg_match('#class="tab-pane fade show active" id="pane-received"#', $sdF) === 0, 'FULL: tab present but Recent Payments stays the default');

        section('C3. Product — barcode, last delivery, wholesale, days of stock left');
        [, $pv] = render('app/bms/product/product_view.php', ['id' => (string)$PRODUCT], $POS);
        $p = $pdo->query("SELECT barcode FROM products WHERE product_id = $PRODUCT")->fetch(PDO::FETCH_ASSOC);
        if (!empty($p['barcode'])) ok(strpos($pv, htmlspecialchars($p['barcode'])) !== false, 'Simple mode shows the barcode (what "Print Barcode" prints)');
        ok(strpos($pv, 'Last delivery:') !== false && strpos($pv, '@ ' . format_currency(1234.50)) !== false, 'Last delivery = the newest batch (qty @ unit cost)');
        ok(strpos($pv, 'suppliers/view?id=' . $SUPPLIER) !== false, 'Last delivery links to its supplier');
        ok(strpos($pv, 'Days of stock left:') !== false, 'Days of stock left shown');
        ok(strpos($pv, '<th><?= t(\'Supplier\') ?>') === false && (strpos($pv, '>Source GRN<') !== false || strpos($pv, '>Supplier<') !== false), 'Batches table shows Supplier where Source GRN is hidden');
        ok(strpos($pv, 'PDP-TEST') !== false, 'the new batch appears in Batches / Lots');
    } finally {
        if ($batchId) $pdo->prepare("DELETE FROM product_batches WHERE batch_id = ?")->execute([$batchId]);
    }

    section('C2. Customer — purchase summary');
    $recOrig = "sale_status IN ('completed','partially_refunded','refunded') AND is_return_sale = 0 AND invoice_id IS NULL";
    $recRet  = "is_return_sale = 1 AND sale_status NOT IN ('voided','cancelled') AND invoice_id IS NULL";
    $orig = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(grand_total),0) t FROM pos_sales WHERE customer_id = $CUSTOMER AND $recOrig")->fetch(PDO::FETCH_ASSOC);
    $ret  = (float)$pdo->query("SELECT COALESCE(SUM(grand_total),0) FROM pos_sales WHERE customer_id = $CUSTOMER AND $recRet")->fetchColumn();
    $cd = $H['POS']['customer_details'][1];
    ok(strpos($cd, 'Total Purchases') !== false && strpos($cd, format_currency((float)$orig['t'] - $ret)) !== false, 'Total Purchases = sales − returns (' . format_currency((float)$orig['t'] - $ret) . ')');
    ok(preg_match('#>' . (int)$orig['n'] . '</div>\s*<div class="small text-muted">Number of Purchases#', $cd) === 1, 'Number of Purchases = ' . (int)$orig['n']);
    if ((int)$orig['n'] > 0) ok(strpos($cd, 'Most Bought') !== false, '"Most Bought" list shown when there are purchases');
    ok(strpos($H['FULL']['customer_details'][1], 'Number of Purchases') === false, 'FULL (Sales on): the Sales module\'s own cards stay, no duplicate summary');

    section('C4. Service — Edit button + sales');
    $sv = $H['POS']['service_view'][1];
    ok(strpos($sv, 'href="/services?edit=' . $SERVICE . '"') !== false, 'service page has an Edit button → services?edit=N');
    [, $sl] = render('app/bms/product/services.php', ['edit' => (string)$SERVICE], $POS);
    ok(preg_match('/const editSvcOnLoad = \{"product_id":' . $SERVICE . ',/', $sl) === 1, 'services?edit=N opens that service\'s Edit form on load');
    [, $sl] = render('app/bms/product/services.php', ['edit' => '99999999'], $POS);
    ok(strpos($sl, 'const editSvcOnLoad = null') !== false, 'an unknown id opens nothing');
    $times = (int)$pdo->query("SELECT COUNT(DISTINCT ps.sale_id) FROM pos_sale_items psi JOIN pos_sales ps ON ps.sale_id = psi.sale_id WHERE psi.product_id = $SERVICE AND ps.$recOrig")->fetchColumn();
    ok(preg_match('#>' . $times . '</div><div class="small text-muted">Times Sold#', $sv) === 1, "Times Sold = $times");
    ok(strpos($sv, 'Recent Sales (Last 10)') !== false, 'recent sales list shown');
} finally {
    foreach ($saved as $k => $v) setSetting($k, $v);
    $GLOBALS['__bms_features'] = null;
}

if (!empty($GLOBALS['__phpNoise'])) { $fail++; echo "  ✗ PHP warnings/notices in: " . implode(', ', array_unique($GLOBALS['__phpNoise'])) . "\n"; }
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
