<?php
/**
 * The service form's plan switches (includes/service_type_switches.php)
 *   php tests/test_service_type_switches_cli.php
 *
 * A: the markup + script contract (no database — runs in CI)
 * B: the real pages (tenant + BJP's install) and a real create/save of an
 *    "Other system, own plans" service, on the local DB. Test data is named
 *    'STS-TEST …' and removed. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
if (is_file("$root/includes/config.php")) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

// ── A. Source contract ─────────────────────────────────────────────────────
section('A. markup + script');
$strip  = file_get_contents("$root/includes/service_type_switches.php");
$editor = file_get_contents("$root/includes/service_price_terms_editor.php");
$page   = file_get_contents("$root/app/bms/product/services.php");
ok(strpos($strip, '_type_sa"') !== false && strpos($strip, '_type_own"') !== false && strpos($strip, 'role="switch"') !== false, 'strip has the two switches');
$saAt = strpos($strip, '_type_sa"'); $gateAt = strpos($strip, 'if (!empty($canMapPlatform)):'); $endAt = strpos($strip, 'endif;');
ok($gateAt !== false && $saAt > $gateAt && $saAt < $endAt,
   'the Superadmin switch only on BJP\'s install, for admins');
ok(preg_match('/class="[^"]*\bd-none\b[^"]*" id="<\?= \$p \?>_spt_section"/', $editor) === 1, 'the plans section starts hidden (tab 2 opens as before)');
foreach (['_spt_head', '_platform_wrap', '_sub_wrap', '_contract_wrap'] as $id) {
    ok(strpos($editor, 'id="<?= $p ?>' . $id . '"') !== false, "editor part $id can be shown/hidden");
}
ok(substr_count($page, "include ROOT_DIR . '/includes/service_type_switches.php'") === 2, 'strip in both Add and Edit (full form only)');
ok(preg_match('/function svcTypeApply\(prefix\)/', $page) && preg_match('/function svcTypeSwitch\(prefix, kind, input\)/', $page)
   && preg_match('/function svcTypeClear\(prefix, mode\)/', $page), 'switch logic present');
ok(strpos($page, "st.mode = st.mapped ? 'sa' : (own ? 'own' : null);") !== false && strpos($page, 'st.locked = !!st.mapped;') !== false,
   'edit opens with the saved switch; a Superadmin link is locked');
ok(strpos($page, "if (sa) sa.checked = mode === 'sa';") !== false && strpos($page, "if (own) own.checked = mode === 'own';") !== false,
   'the switches are exclusive (one mode at a time)');
ok(strpos($page, "if (mode === 'sa') sub.checked = true;") !== false && strpos($page, 'else if (!st.legacySub) sub.checked = false;') !== false,
   'Superadmin plans = subscription; an outside system is never auto-extended');
ok(strpos($page, "classList.toggle('d-none', !!mode);") !== false && strpos($page, "mode !== 'sa'") !== false,
   'materials hide for both; the warehouse hides for Superadmin plans');

if (!is_file("$root/includes/config.php")) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B. Real pages + save ───────────────────────────────────────────────────
global $pdo;
require_once "$root/core/service_plans.php";
sptEnsureSchema($pdo);
spEnsureSchema($pdo);
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid) { echo "SKIP B: needs an admin user and an active warehouse\n"; exit; }

$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'STS-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    $pdo->exec("DELETE i FROM service_plan_items i JOIN service_plans p ON p.plan_id = i.plan_id WHERE p.product_id IN ($in)");
    $pdo->exec("DELETE FROM service_price_terms WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM service_plans WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM vendor_subscription_services WHERE product_id IN ($in)");
    $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'sts_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function callApi(string $file, array $post, string $method = 'POST', array $get = [], string $env = ''): array {
    global $root, $uid;
    $out = runPhp("
        \$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . "; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        $env
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_POST = " . var_export($post, true) . ";
        \$_GET = " . var_export($get, true) . ";
        ob_start();
        include '$root/$file';
        \$o = ob_get_clean();
        \$p = strpos(\$o, '{');
        echo \$p === false ? \$o : substr(\$o, \$p);
    ");
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['success' => false, 'message' => $out];
}
function render(string $file, array $get = [], string $env = ''): string {
    global $root, $uid;
    return runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        $env
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_GET = " . var_export($get, true) . ";
        ob_start();
        include '$root/$file';
        echo ob_get_clean();
    ");
}
function jsParses(string $html): ?string {
    $html = preg_replace('#<!--.*?-->#s', '', $html);
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $m);
    $tmp = tempnam(sys_get_temp_dir(), 'stsjs_') . '.js';
    foreach ($m[1] as $i => $js) {
        if (trim($js) === '') continue;
        file_put_contents($tmp, $js);
        $out = shell_exec('node --check ' . escapeshellarg($tmp) . ' 2>&1');
        if (trim((string)$out) !== '') { @unlink($tmp); return "script #$i: " . trim($out); }
    }
    @unlink($tmp);
    return null;
}
$vendorEnv = "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1');";
$origSimple = get_setting('pos_simple_mode', '0');
save_setting('pos_simple_mode', '0');
$GLOBALS['TEST_CLEANUPS'][] = fn() => save_setting('pos_simple_mode', $origSimple);

section('B1. pages');
$html = render('app/bms/product/services.php', ['search' => 'STS-TEST']);
ok(strpos($html, 'id="svc_type_strip"') !== false && strpos($html, 'id="edit_svc_type_strip"') !== false, 'tenant: the strip is on Add and Edit');
ok(strpos($html, 'id="svc_type_own"') !== false && strpos($html, 'id="svc_type_sa"') === false, 'tenant: only the own-plans switch (no Superadmin one)');
ok(strpos($html, 'Own plans and terms') !== false, 'tenant: the switch reads "Own plans and terms"');
ok(preg_match('/id="svc_type_strip"[\s\S]*?id="svc_sell"/', $html) === 1, 'the strip sits at the top of tab 2, above the price');
ok(strpos($html, 'Fatal error') === false && strpos($html, 'Warning:') === false && strpos($html, 'Notice:') === false, 'tenant page renders cleanly');
ok(($e = jsParses($html)) === null, 'tenant page: every inline script parses' . ($e ? " — $e" : ''));

$html = render('app/bms/product/services.php', ['search' => 'STS-TEST'], $vendorEnv);
ok(strpos($html, 'id="svc_type_sa"') !== false && strpos($html, 'BMS plan from Superadmin') !== false
   && strpos($html, 'Other system, own plans') !== false, 'BJP admin: both switches');
ok(strpos($html, 'id="edit_svc_platform_wrap"') !== false && strpos($html, 'id="edit_svc_sub_wrap"') !== false, 'BJP admin: platform + subscription parts present');
ok(strpos($html, 'Fatal error') === false && strpos($html, 'Warning:') === false, 'vendor page renders cleanly');
ok(($e = jsParses($html)) === null, 'vendor page: every inline script parses' . ($e ? " — $e" : ''));

section('B2. "Other system, own plans" — real create / edit / switch off');
// Exactly what the BJP form posts with the own switch on: plans, no platform product, subscription unticked.
$post = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'cost_price' => '0',
    'product_name' => 'STS-TEST Vikundi', 'selling_price' => '20000', 'warehouse_id' => (string)$wid, 'price_terms' => '[]',
    'platform_product_sent' => '1', 'platform_product_key' => '', 'subscription_flag_sent' => '1', 'contract_template_id' => '',
    'service_plans' => json_encode([['plan_id' => null, 'plan_name' => 'Basic', 'tagline' => '', 'is_popular' => 0, 'items' => [],
        'terms' => [['term_id' => null, 'term_label' => '1 Month', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 20000, 'is_default' => 1],
                    ['term_id' => null, 'term_label' => '1 Year', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 200000, 'is_default' => 0]]]])];
$r = callApi('api/create_nip_product.php', $post, 'POST', [], $vendorEnv);
$pid = (int)($r['product_id'] ?? 0);
ok(!empty($r['success']) && $pid > 0, 'saved through the real endpoint' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$g = callApi('api/account/get_service_price_terms.php', [], 'GET', ['product_id' => (string)$pid], $vendorEnv);
ok(count($g['plans'] ?? []) === 1 && count($g['plans'][0]['terms'] ?? []) === 2, 'its plan and two terms are stored (Edit opens with the own switch on)');
ok(empty($g['platform_product_key']) && empty($g['is_subscription']), 'not linked to Superadmin and not a subscription (no auto-extension)');

$r = callApi('api/update_nip_product.php', ['product_id' => (string)$pid, 'service_plans' => '[]'] + $post, 'POST', [], $vendorEnv);
$g = callApi('api/account/get_service_price_terms.php', [], 'GET', ['product_id' => (string)$pid], $vendorEnv);
ok(!empty($r['success']) && ($g['plans'] ?? null) === [], 'switch turned off + Save → the plans are removed; a plain service again'
   . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
