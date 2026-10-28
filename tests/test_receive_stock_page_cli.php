<?php
/**
 * tests/test_receive_stock_page_cli.php
 *   php tests/test_receive_stock_page_cli.php
 *
 * Smart POS "Products" window (phone bottom bar / dashboard Add Product tile)
 * and the Receive Stock page:
 *   1. the window closes with an X at the top right — no Cancel button, no ">"
 *      arrow on its rows;
 *   2. every Receive Stock link opens the Receive Stock page, which shows only
 *      the form (no POS till, product grid or cart loading first);
 *   3. the form is one copy (restock_modal.php + restock_modal_js.php) shared
 *      by the POS and the page; old ?restock=1 POS links still open it.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function check(bool $ok, string $label): void { $ok ? pass($label) : fail($label); }
function src(string $rel): string { global $root; return (string)@file_get_contents("$root/$rel"); }

echo "\n\033[1m═══ Products window + Receive Stock page ═══\033[0m\n";

section('1. Products window');
$h = src('header.php');
$a = strpos($h, 'id="bmsAddProductModal"');
$b = strpos($h, '</script>', $a);
$win = $a !== false && $b !== false ? substr($h, $a, $b - $a) : '';
check($win !== '', 'window found in header.php');
check(strpos($win, 'class="apm-head"') !== false && strpos($win, 'class="btn-close" data-bs-dismiss="modal"') !== false, 'X close button at the top');
check(strpos($win, 'modal-footer') === false && stripos($win, "t('Cancel')") === false, 'no Cancel button / footer');
check(strpos($win, 'chevron') === false && strpos($h, 'apm-chev') === false, 'no ">" arrow on the rows');
check(strpos($h, '#bmsAddProductModal .apm-head { display: flex; justify-content: flex-end;') !== false, 'X aligned to the right');

section('2. Receive Stock links open the Receive Stock page');
check(strpos($win, "<a class=\"apm-item\" href=\"<?= getUrl('receive_stock') ?>\">") !== false, 'Products window → Receive Stock page');
check(strpos($h, "\$__restock_url = getUrl('receive_stock');") !== false, 'Shop menu → Receive Stock page');
check(strpos($h, "\$__restock_url ?? getUrl('receive_stock')") !== false, 'side menu → Receive Stock page');
check(strpos(src('app/dashboard.php'), "getUrl('receive_stock')") !== false, 'dashboard tile → Receive Stock page');
check(strpos(src('app/bms/product/products.php'), "getUrl('receive_stock') ?>?product_id=") !== false, 'Products list → Receive Stock page with the product');
check(strpos(src('core/help_nav.php'), "'restock' => ['receive_stock', '']") !== false, 'help "take me there" → Receive Stock page');
$left = [];
foreach (['header.php', 'app/dashboard.php', 'app/bms/product/products.php', 'core/help_nav.php'] as $f) {
    if (strpos(src($f), 'restock=1') !== false) $left[] = $f;
}
check(!$left, 'no link still goes through the POS (?restock=1)' . ($left ? ': ' . implode(', ', $left) : ''));
check(strpos(src('roots.php'), "'receive_stock' => POS_DIR . '/receive_stock.php'") !== false, 'route registered');

section('3. One form, shared');
$modal = src('app/bms/pos/restock_modal.php');
$js    = src('app/bms/pos/restock_modal_js.php');
$page  = src('app/bms/pos/receive_stock.php');
check(substr_count($modal, 'id="restockProductModal"') === 1, 'form markup lives in restock_modal.php');
check(strpos(src('app/bms/pos/pos_modals_new.php'), 'id="restockProductModal"') === false
      && strpos(src('app/bms/pos/pos_modals_new.php'), "include __DIR__ . '/restock_modal.php'") !== false, 'POS includes it (no second copy)');
check(strpos(src('app/bms/pos/pos_scripts_new.php'), 'quick_restock.php') === false
      && strpos(src('app/bms/pos/pos_scripts_new.php'), "include __DIR__ . '/restock_modal_js.php'") !== false, 'POS uses the shared script (no second copy)');
check(strpos($js, 'function initRestockModal(onSaved)') !== false && strpos($js, 'function openRestockProductModal()') !== false, 'shared script: init + open');
check(strpos(src('app/bms/pos/pos_scripts_new.php'), "get('restock') === '1'") !== false, 'old POS links (?restock=1) still open the form');
check(strpos($page, "autoEnforcePermission('pos')") < strpos($page, 'includeHeader()')
      && strpos($page, "canView('pos_restock')") < strpos($page, 'includeHeader()'), 'page checks pos + pos_restock before any output');
check(strpos($page, "setAttribute('data-no-autoclose', 'true')") !== false, 'page keeps the form open after a save (next product)');
foreach (['app/bms/pos/restock_modal.php', 'app/bms/pos/restock_modal_js.php', 'app/bms/pos/receive_stock.php',
          'app/bms/pos/pos_modals_new.php', 'app/bms/pos/pos_scripts_new.php', 'header.php'] as $f) {
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "$f — php -l");
}

section('4. The page renders only the form');
global $pdo;
$adminId = ($pdo instanceof PDO) ? (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn() : 0;
if ($adminId <= 0) {
    echo "  (no database / admin — skipped)\n";
} else {
    $stub = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = (int)$argv[2]; $_SESSION['role_id'] = 1; $_SESSION['is_admin'] = true;
$_SESSION['first_name'] = 'Test'; $_SESSION['last_name'] = 'Runner'; $_SESSION['user_role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SCRIPT_NAME'] = '/index.php'; $_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/receive_stock?product_id=1';
$_GET = ['product_id' => '1'];
ob_start(); ob_start();
include $argv[1];
while (ob_get_level() > 1) ob_end_flush();
echo ob_get_clean();
PHP;
    $tmp = sys_get_temp_dir() . '/bms_rs_' . getmypid() . '.php';
    file_put_contents($tmp, $stub);
    $html = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' '
          . escapeshellarg("$root/app/bms/pos/receive_stock.php") . ' ' . $adminId . ' 2>/dev/null');
    @unlink($tmp);
    check(strpos($html, 'id="restockProductModal"') !== false && strpos($html, 'id="restockProductForm"') !== false, 'page has the Receive Stock form');
    check(strpos($html, 'id="posWarehouseId"') === false && strpos($html, 'id="cartItems"') === false, 'no POS till / cart on the page');
    check(strpos($html, 'openRestockProductModal();') !== false, 'form opens straight away');
    check(strpos($html, 'const RESTOCK_PRESELECT = {"id":1') !== false || strpos($html, 'const RESTOCK_PRESELECT = null') !== false, '?product_id handed to the form');
}

echo "\nPasses:   \033[32m$pass\033[0m\n";
echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
exit($fail > 0 ? 1 : 0);
