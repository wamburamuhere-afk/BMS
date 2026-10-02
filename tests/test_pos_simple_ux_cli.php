<?php
/**
 * tests/test_pos_simple_ux_cli.php — POS Simple Mode usability pass.
 *
 *   php tests/test_pos_simple_ux_cli.php
 *
 *   1. JS (executed in Node): tap-to-add only for plain products; popup for
 *      units / serials / restaurant / unknown; merge rules; XSS-safe toast;
 *      report date formatting and quick-period ranges
 *   2. API: simple_products.php returns unit_count (real fixture row)
 *   3. Rendered pages with pos_simple_mode ON vs OFF — ON gets the new UI,
 *      OFF renders none of it (pos.php, header, sales report, dashboard)
 *   4. Bug fixes that apply to everyone (cashier name, dropdown, currency)
 *
 * CLI ONLY. Temporarily flips pos_simple_mode and adds one shift + one unit
 * row; every change is restored in finally blocks.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once $root . '/roots.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_pos_simple_ux_' . getmypid();
@mkdir($tmp);

echo "\nBMS — POS Simple Mode usability\n";

// ─────────────────────────────────────────────────────────────────────────────
section('1. JavaScript, executed in Node');

$scripts = str_replace("\r\n", "\n", file_get_contents("$root/app/bms/pos/pos_scripts_new.php"));
$report  = str_replace("\r\n", "\n", file_get_contents("$root/app/constant/reports/sales_report.php"));
preg_match('/function posTapProduct\(.*?\n}\n/s', $scripts, $mTap);
preg_match('/const fmtDate = .*?;\n/', $report, $mFmt);
preg_match('/function salesPeriodRange\(.*?\n    }\n/s', $report, $mRange);
preg_match('/function updateMobileCartFab\(\) \{.*?\n}\n/s', $scripts, $mFab);
ok('extracted posTapProduct / fmtDate / salesPeriodRange from source', !empty($mTap) && !empty($mFmt) && !empty($mRange));

$node = trim((string)shell_exec('node -v 2>&1'));
if (!preg_match('/^v\d+/', $node) || empty($mTap)) {
    ok('node available for JS checks', false, $node);
} else {
    $js = <<<'JS'
let cart = [], products = [], popups = [], toasts = [], saleVatRate = 0, shop = '1';
const POS_RESTAURANT_ENABLED = true, POS_WAREHOUSE_MODES = { 1: 'retail', 2: 'restaurant' };
const PT = { addedToCart: 'Added' };
const $ = sel => ({ val: () => shop });
const updateCartDisplay = () => {}, saveCartToStorage = () => {}, caseFormatJs = s => s;
const showProductQuickView = id => popups.push(id);
const Swal = { fire: o => toasts.push(o) };
__TAP__
__FMT__
__RANGE__
const out = {};
const P = (id, extra) => Object.assign({ product_id: id, product_name: 'P' + id, sku: 'S', selling_price: 100, effective_price: 100, min_selling_price: 0, track_serials: 0, unit_count: 0 }, extra || {});
products = [P(1), P(2, { unit_count: 2 }), P(3, { unit_count: null }), P(4, { track_serials: 1 }), P(5), P(6), P(7, { unit_count: undefined }), P(8, { effective_price: 80 })];

posTapProduct(1); posTapProduct(1);
out.plain = { lines: cart.filter(c => c.product_id == 1).length, qty: (cart.find(c => c.product_id == 1) || {}).quantity, popups: popups.length };
posTapProduct(2); posTapProduct(3); posTapProduct(4); posTapProduct(7);
out.popupIds = popups.slice();
shop = '2'; posTapProduct(5); out.restaurantPopup = popups.includes(5); shop = '1';
cart.push({ product_id: 6, unit_label: 'Carton', quantity: 1 });
posTapProduct(6); out.unitLineKept = cart.filter(c => c.product_id == 6).length;
cart.push({ product_id: 5, serial_numbers: ['X'], quantity: 1 });
posTapProduct(5); out.serialLineKept = cart.filter(c => c.product_id == 5).length;
posTapProduct(8); out.priceGroup = (cart.find(c => c.product_id == 8) || {}).price;
saleVatRate = 18; posTapProduct(999); out.unknownIgnored = cart.length;
out.toastSafe = toasts.every(t => t.titleText !== undefined && t.title === undefined && t.toast === true);
out.fmt = [fmtDate('2026-10-02'), fmtDate('2026-10-02 00:30:00'), fmtDate('2026-01-31T23:00:00Z'), fmtDate('bad')];
const y = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const r = (p, d) => { const x = salesPeriodRange(p, d); return y(x.from) + '..' + y(x.to); };
out.ranges = [
  r('today', new Date(2026, 9, 2)), r('week', new Date(2026, 9, 2)), r('week', new Date(2026, 9, 4)), r('week', new Date(2026, 8, 28)),
  r('month', new Date(2026, 1, 15)), r('month', new Date(2028, 1, 10)), r('year', new Date(2026, 9, 2)), r('week', new Date(2026, 11, 31))
];
console.log(JSON.stringify(out));
JS;
    $js = str_replace(['__TAP__', '__FMT__', '__RANGE__'], [$mTap[0], $mFmt[0], $mRange[0]], $js);
    file_put_contents("$tmp/t.js", $js);
    $o = json_decode((string)shell_exec('node ' . escapeshellarg("$tmp/t.js") . ' 2>&1'), true);
    ok('node ran the extracted code', is_array($o), (string)shell_exec('node ' . escapeshellarg("$tmp/t.js") . ' 2>&1'));
    if (is_array($o)) {
        ok('plain product: tap adds 1, second tap -> qty 2 on ONE line, no popup', $o['plain'] === ['lines' => 1, 'qty' => 2, 'popups' => 0]);
        ok('extra units / unknown units (null, missing) / serials -> popup', $o['popupIds'] === [2, 3, 4, 7], json_encode($o['popupIds']));
        ok('restaurant-mode shop -> popup', $o['restaurantPopup'] === true);
        ok('existing Carton line is not merged into (new base line added)', $o['unitLineKept'] === 2);
        ok('existing serial line is not merged into', $o['serialLineKept'] === 2);
        ok('uses the price-group/promo effective price', $o['priceGroup'] === 80);
        ok('unknown product id is ignored (no crash, no line)', is_int($o['unknownIgnored']));
        ok('toast uses titleText (text-only), never HTML title', $o['toastSafe'] === true);
        ok('fmtDate -> DD/MM/YYYY without day shift', $o['fmt'] === ['02/10/2026', '02/10/2026', '31/01/2026', 'bad'], json_encode($o['fmt']));
        ok('period ranges: today/week(Mon start)/month(leap Feb)/year/cross-year week', $o['ranges'] === [
            '2026-10-02..2026-10-02', '2026-09-28..2026-10-04', '2026-09-28..2026-10-04', '2026-09-28..2026-10-04',
            '2026-02-01..2026-02-28', '2028-02-01..2028-02-29', '2026-01-01..2026-12-31', '2026-12-28..2027-01-03',
        ], json_encode($o['ranges']));
    }

    // Phone cart sheet rows (Simple Mode branch of updateMobileCartFab).
    $js2 = <<<'JS'
const nodes = {}; let hidden = 0;
const $ = sel => ({ html: v => { nodes[sel] = v; }, text: v => { nodes[sel] = v; }, show: () => {}, hide: () => { hidden++; } });
const document = { getElementById: () => null };
const POS_CURRENCY = 'TZS', PT = { remove: 'Remove' };
const safeOutput = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const caseFormatJs = s => s;
let POS_SIMPLE_MODE = true, cart = [
  { product_name: 'Soda <b>x</b>', price: 500, discounted_price: 500, quantity: 2 },
  { product_name: 'Phone', price: 1000, discounted_price: 1000, quantity: 1, serial_numbers: ['A1'] },
  { product_name: 'Rice', price: 3000, discounted_price: 3000, quantity: 1, unit_label: 'Bag' },
];
__FAB__
updateMobileCartFab();
const simple = nodes['#mobileCartOffcanvasItems'];
POS_SIMPLE_MODE = false; updateMobileCartFab(); const plain = nodes['#mobileCartOffcanvasItems'];
cart = []; updateMobileCartFab();
console.log(JSON.stringify({ simple, plain, emptyHidden: hidden }));
JS;
    file_put_contents("$tmp/t2.js", str_replace('__FAB__', $mFab[0] ?? '', $js2));
    $o2 = json_decode((string)shell_exec('node ' . escapeshellarg("$tmp/t2.js") . ' 2>&1'), true);
    ok('sheet rows: node ran updateMobileCartFab', is_array($o2), (string)shell_exec('node ' . escapeshellarg("$tmp/t2.js") . ' 2>&1'));
    if (is_array($o2)) {
        $s = $o2['simple'];
        ok('sheet rows: -/+ wired to updateCartQuantity with the right index', str_contains($s, 'updateCartQuantity(0, -1)') && str_contains($s, 'updateCartQuantity(0, 1)') && str_contains($s, 'updateCartQuantity(2, 1)'));
        ok('sheet rows: every line has a remove button', substr_count($s, 'onclick="removeFromCart(') === 3 && str_contains($s, 'removeFromCart(1)'));
        ok('sheet rows: serial line qty is read-only (no -/+ for index 1)', !str_contains($s, 'updateCartQuantity(1,'));
        ok('sheet rows: unit label shown', str_contains($s, '>Bag</span>'));
        ok('sheet rows: product names escaped', str_contains($s, 'Soda &lt;b&gt;x&lt;/b&gt;') && !str_contains($s, '<b>x</b>'));
        ok('sheet rows: non-Simple output unchanged (no -/+ / remove)', !str_contains($o2['plain'], 'updateCartQuantity') && !str_contains($o2['plain'], 'removeFromCart'));
        ok('empty cart hides the cart button', $o2['emptyHidden'] >= 1);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Subprocess runner: renders a page / calls an endpoint as the local admin.
$admin = $pdo->query("SELECT user_id, username, first_name, last_name FROM users WHERE role_id = 1 AND is_active = 1 ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
file_put_contents("$tmp/run.php", <<<'PHP'
<?php
[$_, $root, $file, $query, $uid] = $argv;
chdir($root);
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/' . basename($file, '.php') . ($query !== '' ? "?$query" : ''); $_SERVER['SCRIPT_NAME'] = '/index.php';
parse_str($query, $_GET);
ob_start();
require_once $root . '/roots.php';
$u = $pdo->prepare("SELECT * FROM users WHERE user_id = ?"); $u->execute([(int)$uid]); $u = $u->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = $u['user_id']; $_SESSION['username'] = $u['username'];
$_SESSION['first_name'] = $u['first_name']; $_SESSION['last_name'] = $u['last_name'];
$_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
register_shutdown_function(function () { echo "\n<!--END-->"; });
require $root . '/' . $file;
PHP);
$run = function (string $file, string $query = '') use ($tmp, $root, $admin): string {
    $cmd = 'php ' . escapeshellarg("$tmp/run.php") . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($query) . ' ' . (int)$admin['user_id'];
    return (string)shell_exec($cmd . ' 2>&1');
};
$warn = fn(string $h) => preg_match_all('/(Warning|Notice|Fatal error|Uncaught)\b.{0,3}(<\/b>)?:/', $h);

$origSimple = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
$setSimple = function (string $v) use ($pdo): void {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('pos_simple_mode', ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")->execute([$v]);
};

$unitRowId = null; $shiftId = null;
try {
    // ─────────────────────────────────────────────────────────────────────────
    section('2. API — unit_count on every product');
    $pid = (int)$pdo->query("SELECT product_id FROM products WHERE status = 'active' AND is_service = 0 ORDER BY product_id LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO product_unit_conversions (product_id, unit_label, base_unit_multiplier) VALUES (?, 'ZZTEST Carton', 12)")->execute([$pid]);
    $unitRowId = (int)$pdo->lastInsertId();
    $raw = $run('api/pos/simple_products.php', '');
    $json = json_decode(trim(str_replace('<!--END-->', '', $raw)), true);
    ok('simple_products.php returns success JSON', is_array($json) && !empty($json['success']), substr($raw, 0, 300));
    $rows = $json['data'] ?? [];
    ok('every product carries an integer unit_count', $rows && !array_filter($rows, fn($p) => !array_key_exists('unit_count', $p) || !is_int($p['unit_count'])));
    $mine = array_values(array_filter($rows, fn($p) => (int)$p['product_id'] === $pid));
    ok('product with a unit conversion reports unit_count = 1', $mine && $mine[0]['unit_count'] === 1);
    ok('existing fields untouched (variant_count, track_serials, effective_price)', $mine && array_key_exists('variant_count', $mine[0]) && array_key_exists('track_serials', $mine[0]) && array_key_exists('effective_price', $mine[0]));

    // Shift from yesterday for the old-shift reminder.
    $pdo->prepare("INSERT INTO cash_register_shifts (shift_code, user_id, start_time, starting_cash, status) VALUES (?, ?, ?, 0, 'active')")
        ->execute(['ZZTEST-SHIFT-' . uniqid(), $admin['user_id'], date('Y-m-d 08:00:00', strtotime('-1 day'))]);
    $shiftId = (int)$pdo->lastInsertId();

    // ─────────────────────────────────────────────────────────────────────────
    section('3. Simple Mode ON');
    $setSimple('1');
    $pos = $run('app/bms/pos/pos.php');
    ok('pos.php rendered fully, no PHP warnings', str_contains($pos, '<!--END-->') && str_contains($pos, 'id="productGrid"') && !$warn($pos), substr(strip_tags($pos), 0, 300));
    ok('Receive Stock button', str_contains($pos, 'id="posRestockBtn"'));
    ok('pinned-pay layout classes', str_contains($pos, 'pos-simple-layout') && str_contains($pos, 'pos-pay-actions') && str_contains($pos, 'id="pos-container" style') && str_contains($pos, 'px-0 pos-simple"'));
    ok('Pay button still has its id/handler (inside pos-pay-actions)', (bool)preg_match('/pos-pay-actions">.*?id="processPaymentBtn"/s', $pos));
    ok('pinned Pay bar shows the total', (bool)preg_match('/pos-pay-actions">.*?id="posPayTotal".*?id="processPaymentBtn"/s', $pos)
       && str_contains($scripts, "\$('#posPayTotal').text(\$('#cartTotal').text())"));
    ok('Pay bar sticks to the screen bottom (no fixed-height panel)', (bool)preg_match('/\.pos-simple-layout \.pos-pay-actions \{\s*position: sticky;\s*bottom: 0;/', $pos)
       && !str_contains($pos, 'height: calc(100vh - var(--pos-top'));
    ok('cart buttons have text labels', substr_count($pos, 'class="pos-btn-label"') >= 3);
    ok('search box gets the wide column, one row on phones (nowrap)', (bool)preg_match('/<div class="col">\s*<div class="input-group flex-nowrap pos-search-group">\s*<input type="text" class="form-control" id="productSearch"/', $pos));
    ok('desktop Receive Stock is blue and hidden on phones', (bool)preg_match('/<div class="col-auto d-none d-md-block">\s*<button type="button" class="btn btn-primary pos-restock-btn" id="posRestockBtn"/', $pos));
    ok('phone Receive Stock is blue, on the shop row, phone-only', (bool)preg_match('/id="posWarehouseId".*?<\/select>\s*<\/div>\s*<\/div>\s*<!--[^>]*-->\s*<div class="col-auto d-md-none">\s*<button type="button" class="btn btn-primary btn-sm pos-restock-btn"/s', $pos)
       && str_contains($pos, '<div class="col col-md-'));
    ok('old-shift notice is dismissible with shift id + 24h period',
       (bool)preg_match('/id="posOldShiftNotice"\s*data-shift-id="(\d+)"\s*data-period="(\d+)"/', $pos, $mN)
       && (int)$mN[1] === $shiftId
       && (int)$mN[2] === (int)floor((time() - strtotime(date('Y-m-d 08:00:00', strtotime('-1 day')))) / 86400)
       && str_contains($pos, 'id="posOldShiftDismiss"') && str_contains($pos, "'pos_oldshift_dismissed_' + n.dataset.shiftId"));
    ok('phone sheet has slots for the moved payment controls', str_contains($pos, 'id="mobileSheetExtra"') && str_contains($pos, 'id="mobileSheetFooterSlot"') && str_contains($pos, 'id="mobileSheetDefaultFooter"'));
    ok('FAB/sheet buttons escape the global .btn min-width', str_contains($pos, '#mobileCartFab { min-width: 0; }') && str_contains($pos, '.pos-search-group .btn, .pos-restock-btn, .pos-sheet-btn { min-width: 0; }'));
    ok('old-shift reminder shown for a shift opened yesterday', str_contains($pos, 'id="posOldShiftNotice"') && str_contains($pos, date('d/m/Y', strtotime('-1 day'))));
    ok('JS flag POS_SIMPLE_MODE = true', str_contains($pos, 'const POS_SIMPLE_MODE = true'));
    ok('header: Shop menu offers Receive Stock', str_contains($pos, 'id="simpleShopDropdown"') && str_contains($pos, 'pos?restock=1'));
    ok('header: "Core" renamed to My Business', str_contains($pos, 'My Business') || str_contains($pos, 'Biashara Yangu'));

    $rep = $run('app/constant/reports/sales_report.php');
    ok('sales report rendered, no warnings', str_contains($rep, 'id="filterForm"') && !$warn($rep));
    ok('period chips present (Today/Week/Month/Year)', substr_count($rep, 'data-period=') === 4);
    ok('defaults to this month', str_contains($rep, 'id="f-from" class="form-control" value="' . date('Y-m-01') . '"') && str_contains($rep, 'value="' . date('Y-m-t') . '"'));
    ok('This Month chip active by default', (bool)preg_match('/btn-primary active rounded-pill px-3" data-period="month"/', $rep));
    $rep2 = $run('app/constant/reports/sales_report.php', 'date_from=2026-01-05&date_to=2026-01-09');
    ok('explicit dates in URL win, no chip active', str_contains($rep2, 'value="2026-01-05"') && !preg_match('/btn-primary active rounded-pill/', $rep2));

    // Fresh entries so they're the newest 10: noise must vanish, a real action must stay.
    logActivity($pdo, $admin['user_id'], 'Filtered ZZTEST List', 'User applied search filters ZZTEST-ACT');
    logActivity($pdo, $admin['user_id'], 'Searched ZZTEST', 'User searched ZZTEST-ACT');
    logActivity($pdo, $admin['user_id'], 'View Page', 'User viewed ZZTEST-ACT page');
    logActivity($pdo, $admin['user_id'], 'Deleted ZZTEST Item', 'User deleted item ZZTEST-ACT-REAL');
    $dash = $run('app/dashboard.php');
    ok('dashboard rendered, no PHP warnings', str_contains($dash, '<!--END-->') && !$warn($dash));
    preg_match('/bi-clock-history"><\/i>.{0,200}?<\/h6>(.{0,8000})/s', $dash, $mAct);
    $acts = $mAct[1] ?? '';
    ok('Recent Activities hides page-view noise', $acts !== '' && stripos($acts, 'User viewed') === false && stripos($acts, '[VIEW]') === false);
    ok('Recent Activities hides Filtered/Searched entries', !str_contains($acts, 'applied search filters ZZTEST-ACT') && !str_contains($acts, 'searched ZZTEST-ACT'));
    ok('Recent Activities keeps real actions', str_contains($acts, 'ZZTEST-ACT-REAL'));

    // ─────────────────────────────────────────────────────────────────────────
    section('4. Simple Mode OFF — none of it renders');
    $setSimple('0');
    $pos = $run('app/bms/pos/pos.php');
    ok('pos.php rendered fully, no PHP warnings', str_contains($pos, '<!--END-->') && str_contains($pos, 'id="productGrid"') && !$warn($pos));
    foreach (['id="posRestockBtn"' => 'Receive Stock button', 'class="row g-0 pos-simple-layout"' => 'pinned layout', 'bg-white pos-pay-actions"' => 'pay block', 'class="pos-btn-label"' => 'button labels',
              'id="posOldShiftNotice"' => 'old-shift reminder', 'id="simpleShopDropdown"' => 'Shop dropdown'] as $needle => $label) {
        ok("OFF: no $label", !str_contains($pos, $needle));
    }
    ok('OFF: search keeps col-md-5', (bool)preg_match('/<div class="col-md-5">\s*<div class="input-group">\s*<input type="text" class="form-control" id="productSearch"/', $pos));
    ok('OFF: no Receive Stock buttons / dismiss button', !str_contains($pos, 'class="btn btn-primary btn-sm pos-restock-btn"') && !str_contains($pos, 'id="posRestockBtn"') && !str_contains($pos, 'id="posOldShiftDismiss"'));
    ok('OFF: shop column unchanged', !str_contains($pos, '<div class="col col-md-'));
    ok('OFF: Pay button still inside the payment section', (bool)preg_match('/pos-pay-fields">.*?id="processPaymentBtn"/s', $pos));
    ok('OFF: JS flag POS_SIMPLE_MODE = false', str_contains($pos, 'const POS_SIMPLE_MODE = false'));
    ok('OFF: header keeps "Core" (no My Business label)', !str_contains($pos, 'My Business') && !str_contains($pos, 'Biashara Yangu'));

    $rep = $run('app/constant/reports/sales_report.php');
    ok('OFF: sales report has no chips, keeps full-year default', !str_contains($rep, 'data-period=') && str_contains($rep, 'value="' . date('Y-01-01') . '"'));

    // ─────────────────────────────────────────────────────────────────────────
    section('5. Fixes for everyone (checked in the OFF render)');
    $name = trim($admin['first_name'] . ' ' . $admin['last_name']);
    ok("cashier shows the user's name ($name), not \"User\"", (bool)preg_match('/pos-shift-info-item">[^<]*' . preg_quote(htmlspecialchars($name), '/') . '<\/span>/', $pos));
    ok('"+" menu uses static display (no corner jump)', str_contains($pos, 'id="addProductDropdown" data-bs-toggle="dropdown" data-bs-display="static"'));
    $cur = getSetting('currency', 'TZS');
    ok("cash balance uses the tenant currency code ($cur)", (bool)preg_match('/cash-balance-display">' . preg_quote($cur, '/') . ' [\d,]+\.\d{2}</', $pos));
    ok('JS balance refresh no longer hard-codes TSh', !str_contains($scripts, "text('TSh '") && str_contains($scripts, "text(POS_CURRENCY + ' ' + response.data.balance)"));
    ok('sales report table no longer uses new Date(r.sale_date)', !str_contains($report, 'new Date(r.sale_date)') && str_contains($report, 'fmtDate(r.sale_date)'));
} finally {
    if ($origSimple === false) $pdo->exec("DELETE FROM system_settings WHERE setting_key = 'pos_simple_mode'");
    else $setSimple((string)$origSimple);
    if ($unitRowId) $pdo->prepare("DELETE FROM product_unit_conversions WHERE id = ?")->execute([$unitRowId]);
    if ($shiftId)   $pdo->prepare("DELETE FROM cash_register_shifts WHERE shift_id = ?")->execute([$shiftId]);
    $pdo->exec("DELETE FROM activity_logs WHERE description LIKE '%ZZTEST-ACT%'");
    array_map('unlink', glob("$tmp/*") ?: []); @rmdir($tmp);
}

section('6. Clean-up');
$now = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
ok('pos_simple_mode restored', $now === $origSimple);
ok('fixture rows removed', (int)$pdo->query("SELECT COUNT(*) FROM product_unit_conversions WHERE unit_label = 'ZZTEST Carton'")->fetchColumn() === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM cash_register_shifts WHERE shift_code LIKE 'ZZTEST-SHIFT-%'")->fetchColumn() === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
