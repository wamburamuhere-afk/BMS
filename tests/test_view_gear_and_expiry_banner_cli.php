<?php
/**
 * Product view + warehouse view: one gear menu (icon + triangle, no text) holding every option;
 * expiry / grace banners close until the day count changes.
 *   php tests/test_view_gear_and_expiry_banner_cli.php      Exit 0 = pass. Read-only.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once "$root/roots.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }

global $pdo;
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$pid = (int)$pdo->query("SELECT product_id FROM products WHERE status = 'active' AND is_service = 0 ORDER BY product_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
if (!$pid || !$wid) { echo "SKIP: needs an active product and warehouse\n"; exit(0); }

function page(string $file, int $id, string $session = ''): string {
    global $root, $uid;
    $tmp = tempnam(sys_get_temp_dir(), 'vgb_');
    file_put_contents($tmp, "<?php \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['user_lang'] = 'en';
        \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; $session
        \$_GET['id'] = \$_REQUEST['id'] = '$id'; ob_start(); include '$root/$file'; echo ob_get_clean();");
    $out = (string)shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return $out;
}
/** The gear menu: its button and its items. */
function gear(string $html): array {
    if (!preg_match('#<button[^>]*dropdown-toggle[^>]*>\s*<i class="bi bi-gear-fill"></i>\s*</button>\s*<ul[^>]*dropdown-menu[^>]*>(.*?)</ul>#s', $html, $m)) return [null, []];
    preg_match_all('#<(?:button|a)[^>]*dropdown-item[^>]*>.*?</i>([^<]+)</(?:button|a)>#s', $m[1], $it);
    return [$m[0], array_map('trim', $it[1])];
}

section('A. product view');
$h = page('app/bms/product/product_view.php', $pid);
[$btn, $items] = gear($h);
ok($btn !== null, 'phone gear button: the gear icon and the triangle only (no "Actions" text)');
foreach (['Print Details', 'Print Barcode', 'Edit Product', 'Duplicate Product', 'Adjust Stock', 'Generate Movement Report'] as $want) {
    ok(in_array($want, $items, true), "gear menu has \"$want\"");
}
ok((bool)array_intersect(['Deactivate Product', 'Activate Product'], $items), 'gear menu has Activate / Deactivate');
ok(in_array('Back to Products', $items, true) || in_array('Back to Services', $items, true), 'gear menu starts with Back');
ok(strpos($h, '<!-- Desktop Actions -->') !== false && strpos($h, 'class="d-none d-md-flex gap-2 ms-auto pt-1 flex-shrink-0"') !== false, 'desktop keeps its own buttons, unchanged');
ok(preg_match('#<div class="d-flex d-md-none ms-auto pt-1 flex-shrink-0">\s*<div class="dropdown">\s*<button[^>]*dropdown-toggle[^>]*>\s*<i class="bi bi-gear-fill"></i>#', $h) === 1, 'the gear menu is phone-only');
ok(strpos($h, 'Fatal error') === false, 'page renders');

section('B. warehouse view');
$h = page('app/bms/stock/warehouse_view.php', $wid);
[$btn, $items] = gear($h);
ok($btn !== null, 'phone gear button (icon + triangle only)');
foreach (['Transfer Stock', 'Manage Storage Areas', 'Print', 'Copy'] as $want) ok(in_array($want, $items, true), "gear menu has \"$want\"");
ok(strpos($h, 'onclick="exportTable()"><i class="bi bi-file-earmark-spreadsheet text-success me-2"></i>Excel') !== false, 'gear menu has Excel');
ok(strpos($h, '<div class="dropdown d-md-none">') !== false && strpos($h, 'class="btn btn-outline-secondary d-none d-md-inline-block"') !== false, 'gear on phones only; desktop keeps the Back button');
ok(strpos($h, 'Fatal error') === false, 'page renders');

section('C. expiry / grace banner');
$exp = fn(int $d) => "\$_SESSION['_bms_expiry_warning'] = ['type' => 'trial', 'days_left' => $d, 'ends_at' => '2026-12-01'];";
$h6 = page('app/bms/stock/warehouse_view.php', $wid, $exp(6));
$h5 = page('app/bms/stock/warehouse_view.php', $wid, $exp(5));
preg_match('#id="bmsExpiryBanner"[^>]*data-bms-banner-key="([^"]+)"#', $h6, $k6);
preg_match('#id="bmsExpiryBanner"[^>]*data-bms-banner-key="([^"]+)"#', $h5, $k5);
ok(!empty($k6[1]) && strpos($h6, 'ends in 6 days') !== false, 'banner shows at 6 days left, with a key');
ok(!empty($k5[1]) && $k5[1] !== $k6[1], 'the key changes when the day count changes (6 → 5), so a closed banner shows again');
ok(preg_match('#id="bmsExpiryBanner".*?onclick="bmsBannerDismiss\(this\)".*?</div>\s*</div>\s*<script>bmsBannerHideIfDismissed\(document\.currentScript\.previousElementSibling\);</script>#s', $h6) === 1,
   'the ✕ closes it through bmsBannerDismiss; a closed one is removed before the page paints');
ok(preg_match('#id="bmsExpiryBanner"[^>]*>.*?data-bs-dismiss="alert"#s', substr($h6, strpos($h6, 'id="bmsExpiryBanner"'), 2000)) === 0, 'no longer relies on Bootstrap\'s alert dismiss');
ok(strpos($h6, 'function bmsBannerDismiss') !== false && strpos($h6, 'localStorage.setItem(key') !== false, 'closing is remembered (per company, this browser)');
$hg = page('app/bms/stock/warehouse_view.php', $wid, "\$_SESSION['_bms_grace_warning'] = ['type' => 'subscription', 'days_left' => 3, 'grace_until' => '2026-12-01'];");
ok(preg_match('#id="bmsGraceBanner"[^>]*data-bms-banner-key="bmsBanner:\d+:grace:subscription:3:2026-12-01"#', $hg) === 1 && strpos($hg, 'onclick="bmsBannerDismiss(this)"') !== false,
   'the grace-period banner closes the same way');
$hn = page('app/bms/stock/warehouse_view.php', $wid);
ok(strpos($hn, 'id="bmsExpiryBanner"') === false && strpos($hn, 'id="bmsGraceBanner"') === false, 'no banner when there is nothing to warn about');

echo "\nPasses: $pass\nFailures: $fail\n";
exit($fail === 0 ? 0 : 1);
