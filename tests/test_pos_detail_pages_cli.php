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
} finally {
    foreach ($saved as $k => $v) setSetting($k, $v);
    $GLOBALS['__bms_features'] = null;
}

if (!empty($GLOBALS['__phpNoise'])) { $fail++; echo "  ✗ PHP warnings/notices in: " . implode(', ', array_unique($GLOBALS['__phpNoise'])) . "\n"; }
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
