<?php
/**
 * Platform subscriptions stay out of POS (service_plans_plan.md Phase 6)
 *   php tests/test_service_plans_pos_cli.php
 *
 * A: wiring (CI).  B: on the local database, through the real endpoints:
 *   api/pos/simple_products.php hides a service sold as a platform product and still
 *   lists an ordinary service; api/pos/process_sale.php refuses a forged sale of the
 *   mapped service (alone or beside other lines) before anything is written, and an
 *   ordinary service still goes past the check. Unlinking the service brings it back.
 * Data is 'SPPOS-TEST' and removed afterwards. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$hasConfig = is_file("$root/includes/config.php");
if ($hasConfig) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

// ── A. Wiring ──────────────────────────────────────────────────────────────
section('A. wiring');
$src = fn($f) => file_get_contents("$root/$f");
$sp = $src('core/service_plans.php');
ok(strpos($sp, 'function spPosExcludeSql') !== false && strpos($sp, 'function spPlatformMappedNames') !== false, 'helpers: POS filter + mapped-service lookup');
$list = $src('api/pos/simple_products.php');
ok(strpos($list, "\$platformExclude = spPosExcludeSql(\$pdo);") !== false && strpos($list, "WHERE p.status = 'active'\" . \$platformExclude") !== false, 'POS product list hides platform subscriptions');
$sale = $src('api/pos/process_sale.php');
$g = strpos($sale, 'spPlatformMappedNames('); $rn = strpos($sale, 'nextReceiptNumber($pdo);');
ok($g !== false && $rn !== false && $g < $rn && $g < strpos($sale, 'INSERT INTO pos_sales'), 'process_sale refuses them before a receipt number or a sale is made');
foreach (['core/service_plans.php', 'api/pos/simple_products.php', 'api/pos/process_sale.php'] as $f) {
    ok(strpos((string)shell_exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1'), 'No syntax errors') !== false, "$f lints clean");
}

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B. Setup ───────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/service_plans.php";
require_once "$root/core/plans.php";
require_once "$root/core/vendor_bridge.php";
spEnsureSchema($pdo);
$c = getControlPdo();
if (!spMappingReady($pdo)) { ok(false, 'service plan mapping ready (vendor_subscription_services.platform_product_key)'); exit; }
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid) { echo "SKIP B: needs an admin and a warehouse\n"; exit; }

$PROD = 'sppos' . bin2hex(random_bytes(3)) . '_prod';
$cleanup = function () use ($pdo, $c, &$PROD) {
    $p = implode(',', array_map('intval', $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SPPOS-TEST%'")->fetchAll(PDO::FETCH_COLUMN))) ?: '0';
    foreach (['service_price_terms', 'vendor_subscription_services', 'products'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($p)");
    $c->prepare("DELETE FROM platform_products WHERE product_key = ?")->execute([$PROD]);
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'sppos_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function lastJson(string $out): ?array { $p = strpos($out, '{'); $j = $p === false ? null : json_decode(substr($out, $p), true); return is_array($j) ? $j : null; }
$session = "\$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['user_lang'] = 'en';
            \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin';";
/** BJP staff creating a service the normal way (the service form's endpoint). */
function call(string $file, array $post): array {
    global $root, $session;
    return lastJson(runPhp("\$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
        putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');
        $session \$_POST = \$_REQUEST = " . var_export($post, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; echo ob_get_clean();")) ?? ['success' => false];
}
/** The POS product grid, as the till asks for it. */
function posList(array $get): array {
    global $root, $session;
    return lastJson(runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; $session
        \$_GET = \$_REQUEST = " . var_export($get, true) . "; ob_start(); include '$root/api/pos/simple_products.php'; echo ob_get_clean();")) ?? ['success' => false];
}
/** A POS sale posted straight to the endpoint (JSON body on php://input), as a forged request would be. */
function posSale(array $body): array {
    global $root, $session;
    $json = var_export(json_encode($body), true);
    return lastJson(runPhp("
        class FakeInput {
            public \$context; private \$h;
            public function stream_open(\$path, \$mode, \$o, &\$op) {
                if (stripos(\$path, 'php://input') === 0) { \$this->h = fopen('data://text/plain;base64,' . base64_encode(\$GLOBALS['__body']), 'r'); }
                else { stream_wrapper_restore('php'); \$this->h = fopen(\$path, \$mode); stream_wrapper_unregister('php'); stream_wrapper_register('php', 'FakeInput'); }
                return (bool)\$this->h;
            }
            public function stream_read(\$n) { return fread(\$this->h, \$n); }
            public function stream_write(\$d) { return fwrite(\$this->h, \$d); }
            public function stream_eof() { return feof(\$this->h); }
            public function stream_stat() { return fstat(\$this->h); }
            public function stream_flush() { return fflush(\$this->h); }
            public function stream_close() { fclose(\$this->h); }
            public function stream_set_option(\$a, \$b, \$c) { return false; }
        }
        \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; $session
        \$GLOBALS['__body'] = $json; stream_wrapper_unregister('php'); stream_wrapper_register('php', 'FakeInput');
        ob_start(); include '$root/api/pos/process_sale.php'; \$o = ob_get_clean(); stream_wrapper_restore('php'); echo \$o;")) ?? ['success' => false, 'message' => 'no JSON'];
}

$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,0,0,1)")->execute([$PROD, "SPPOS Shop $PROD"]);
$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SPPOS-TEST Shop System', 'subscription_flag_sent' => '1', 'is_subscription' => '1',
    'platform_product_sent' => '1', 'platform_product_key' => $PROD, 'price_terms' => '']);
$sys = (int)($r['product_id'] ?? 0);
$r = call('api/create_nip_product.php', $svcBase + ['product_name' => 'SPPOS-TEST Installation', 'selling_price' => '25000', 'price_terms' => '']);
$plain = (int)($r['product_id'] ?? 0);
ok($sys && $plain && vssPlatformProduct($pdo, $sys) === $PROD && vssPlatformProduct($pdo, $plain) === null, 'fixtures: a service sold as a platform product + an ordinary service');

// ── B1. The POS list ───────────────────────────────────────────────────────
section('B1. POS product list');
$ids = fn(array $r) => array_map('intval', array_column($r['data'] ?? [], 'product_id'));
$l = posList(['search' => 'SPPOS-TEST', 'warehouse_id' => (string)$wid]);
ok(!empty($l['success']) && in_array($plain, $ids($l), true), 'an ordinary service is still listed');
ok(!in_array($sys, $ids($l), true), 'the platform subscription is not listed');
$l = posList(['search' => 'SPPOS-TEST']);
ok(!empty($l['success']) && !in_array($sys, $ids($l), true) && in_array($plain, $ids($l), true), 'hidden without a warehouse filter too');
$l = posList(['search' => 'Shop System', 'warehouse_id' => (string)$wid]);
ok(!in_array($sys, $ids($l), true), 'searching its exact name does not find it');

// ── B2. A forged sale ──────────────────────────────────────────────────────
section('B2. process_sale refuses it');
$sales = fn() => (int)$pdo->query("SELECT COUNT(*) FROM pos_sales")->fetchColumn();
$n = $sales();
$line = fn(int $pid, float $price) => ['product_id' => $pid, 'quantity' => 1, 'price' => $price, 'unit_price' => $price, 'discount' => 0];
$base = ['warehouse_id' => $wid, 'payment_method' => 'cash', 'amount_tendered' => 300000, 'subtotal' => 300000, 'total' => 300000, 'tax' => 0];
$s = posSale($base + ['items' => [$line($sys, 300000)]]);
ok(empty($s['success']) && strpos((string)($s['message'] ?? ''), 'Sell this through an invoice') !== false && strpos((string)$s['message'], 'SPPOS-TEST Shop System') !== false,
   'the mapped service alone → refused, named, told to use an invoice');
$s = posSale($base + ['items' => [$line($plain, 25000), $line($sys, 300000)]]);
ok(empty($s['success']) && strpos((string)($s['message'] ?? ''), 'Sell this through an invoice') !== false, 'hidden among other lines → still refused');
$s = posSale($base + ['items' => [$line($plain, 25000), ['product_id' => (string)$sys, 'quantity' => 1, 'price' => 1]]]);
ok(empty($s['success']) && strpos((string)($s['message'] ?? ''), 'Sell this through an invoice') !== false, 'its id sent as a string → still refused');
ok($sales() === $n, 'nothing written for the refused sales');
// An ordinary service is not caught by the check: it goes on to the normal checks (no open shift /
// a missing test product here, so nothing is saved).
$passed = fn(array $s) => empty($s['success']) && ($s['message'] ?? '') !== '' && strpos((string)$s['message'], 'Sell this through an invoice') === false
    && (strpos((string)$s['message'], 'shift') !== false || strpos((string)$s['message'], 'not found') !== false);
$s = posSale($base + ['items' => [$line($plain, 25000), $line(999999999, 1)]]);
ok($passed($s), 'an ordinary service passes the check (stops later: ' . ($s['message'] ?? '?') . ')');
ok($sales() === $n, 'and that sale was rolled back');

// ── B3. Unlinked → an ordinary service again ───────────────────────────────
section('B3. unlinking brings it back');
spSetPlatformProduct($pdo, $sys, null, $uid);
$l = posList(['search' => 'SPPOS-TEST', 'warehouse_id' => (string)$wid]);
ok(in_array($sys, $ids($l), true), 'unlinked → listed in POS again');
$s = posSale($base + ['items' => [$line($sys, 1), $line(999999999, 1)]]);
ok($passed($s), 'unlinked → no longer refused');
ok($sales() === $n, 'still nothing written');
