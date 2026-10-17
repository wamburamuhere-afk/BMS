<?php
/**
 * Service price terms (customer_subscription_plan.md Phase 1)
 *   php tests/test_service_price_terms_cli.php
 *
 * A: validation rules (no database — runs in CI)
 * B: the real endpoints (create/update/get) + the pages, on the local database.
 *    Creates services named 'SPT-TEST …' and removes them. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
if (is_file("$root/includes/config.php")) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
require_once "$root/core/service_price_terms.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
function throws(callable $f): ?string { try { $f(); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); } }

register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    // exit() below stops any later shutdown function, so test data is removed here first.
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

// ── A. Validation ──────────────────────────────────────────────────────────
section('A. sptParse');
$t = fn($label, $v, $u, $price, $def = 0) => ['term_label' => $label, 'duration_value' => $v, 'duration_unit' => $u, 'price' => $price, 'is_default' => $def];
ok(sptParse(null) === null, 'field not sent → null (leave terms untouched)');
ok(sptParse('') === [] && sptParse('[]') === [], 'empty → [] (remove all terms)');
$r = sptParse(json_encode([$t('1 Month', 1, 'month', 10000), $t('3 Months', 3, 'month', 25000)]));
ok(count($r) === 2 && $r[0]['is_default'] === 1 && $r[1]['is_default'] === 0, 'no default chosen → the first term becomes default');
ok($r[1]['sort_order'] === 1 && $r[1]['price'] === 25000.0, 'order and price kept');
$r = sptParse([$t('Weekly', 2, 'week', 5000), $t('Yearly', 1, 'year', 85000, 1)]);
ok($r[1]['is_default'] === 1 && $r[0]['is_default'] === 0, 'explicit default respected; any unit is allowed (no hardcoded periods)');
ok(throws(fn() => sptParse([$t('', 1, 'month', 1)])) !== null, 'name required');
ok(throws(fn() => sptParse([$t('A', 0, 'month', 1)])) !== null, 'length 0 refused');
ok(throws(fn() => sptParse([$t('A', 1, 'decade', 1)])) !== null, 'unknown unit refused');
ok(throws(fn() => sptParse([$t('A', 1, 'month', '')])) !== null, 'price required');
ok(throws(fn() => sptParse([$t('A', 1, 'month', -5)])) !== null, 'negative price refused');
ok(throws(fn() => sptParse([$t('A', 1, 'month', 1), $t('a', 2, 'month', 2)])) !== null, 'duplicate names refused (case-insensitive)');
ok(throws(fn() => sptParse([$t('A', 1, 'month', 1, 1), $t('B', 2, 'month', 2, 1)])) !== null, 'two defaults refused');
ok(throws(fn() => sptParse('{bad json')) !== null, 'unreadable input refused');
ok(throws(fn() => sptParse(array_fill(0, 25, $t('X', 1, 'month', 1)))) !== null, 'more than 24 terms refused');
ok(sptMonths(3, 'month') === 3 && sptMonths(2, 'year') === 24 && sptMonths(2, 'week') === null, 'sptMonths: months/years → months; weeks/days → none');
ok(sptDurationText(1, 'month') === '1 month' && sptDurationText(6, 'month') === '6 months', 'duration text');

if (!is_file("$root/includes/config.php")) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B. Real endpoints + pages ──────────────────────────────────────────────
global $pdo;
sptEnsureSchema($pdo);

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid) { echo "SKIP B: needs an admin user and an active warehouse\n"; exit; }

$origSimple = get_setting('pos_simple_mode', '0');
$origAdv    = get_setting('pos_advanced_product', '0');
$cleanup = function () use ($pdo, $origSimple, $origAdv) {
    $ids = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SPT-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM service_price_terms WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM vendor_subscription_services WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
    }
    save_setting('pos_simple_mode', $origSimple);
    save_setting('pos_advanced_product', $origAdv);
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'spt_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
/** Call an endpoint as the admin, exactly as the browser would. $env: extra putenv lines (vendor context). */
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
function product(int $id): array { global $pdo; $s = $pdo->prepare("SELECT * FROM products WHERE product_id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: []; }
function termsOf(int $id, string $status = 'active'): array { global $pdo; $s = $pdo->prepare("SELECT * FROM service_price_terms WHERE product_id = ? AND status = ? ORDER BY sort_order"); $s->execute([$id, $status]); return $s->fetchAll(PDO::FETCH_ASSOC); }
$base = fn(string $name, array $extra = []) => array_merge(['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active',
    'product_name' => $name, 'selling_price' => '999', 'warehouse_id' => (string)$wid, 'cost_price' => '0'], $extra);

section('B1. create with terms');
$terms = [$t('1 Month', 1, 'month', 10000), $t('3 Months', 3, 'month', 25000), $t('1 Year', 1, 'year', 85000, 1)];
$r = callApi('api/create_nip_product.php', $base('SPT-TEST Hosting', ['price_terms' => json_encode($terms)]));
ok(!empty($r['success']), 'service with terms created via the real endpoint' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$pid = (int)($r['product_id'] ?? 0);
$rows = termsOf($pid);
ok(count($rows) === 3, 'three terms stored');
ok((float)product($pid)['selling_price'] === 85000.0, 'selling_price = the DEFAULT term (1 Year), not the typed 999');
ok((float)product($pid)['min_selling_price'] === 10000.0, 'min_selling_price = the cheapest term, so POS can sell any term');
ok(!vssIsSubscription($pdo, $pid), 'subscription flag ignored outside the platform root');

section('B2. create without terms (unchanged behaviour)');
$r = callApi('api/create_nip_product.php', $base('SPT-TEST Plain', ['selling_price' => '5000']));
$plain = (int)($r['product_id'] ?? 0);
ok(!empty($r['success']) && (float)product($plain)['selling_price'] === 5000.0 && !termsOf($plain), 'plain service keeps its typed price and has no terms');

section('B3. invalid terms → nothing saved');
$before = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_name LIKE 'SPT-TEST%'")->fetchColumn();
$r = callApi('api/create_nip_product.php', $base('SPT-TEST Bad', ['price_terms' => json_encode([$t('X', 1, 'month', '')])]));
$after = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_name LIKE 'SPT-TEST%'")->fetchColumn();
ok(empty($r['success']) && stripos($r['message'] ?? '', 'price') !== false, 'refused with a clear message: ' . ($r['message'] ?? ''));
ok($before === $after, 'no half-created service left behind');

section('B4. edit: rename, remove, add, change default');
$ids = array_column($rows, 'term_id');
$edit = [
    ['term_id' => (int)$ids[0], 'term_label' => 'Monthly', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 12000, 'is_default' => 1],
    ['term_id' => (int)$ids[2], 'term_label' => '1 Year', 'duration_value' => 1, 'duration_unit' => 'year', 'price' => 90000],
    $t('6 Months', 6, 'month', 45000),
];
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Hosting', ['product_id' => (string)$pid, 'price_terms' => json_encode($edit)]));
ok(!empty($r['success']), 'edit saved' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
$now = termsOf($pid);
ok(count($now) === 3 && $now[0]['term_label'] === 'Monthly' && (int)$now[0]['term_id'] === (int)$ids[0], 'existing term renamed in place (same id)');
ok(count(termsOf($pid, 'deleted')) === 1, 'the removed term (3 Months, never used) is soft-deleted');
ok((float)product($pid)['selling_price'] === 12000.0 && (float)product($pid)['min_selling_price'] === 12000.0, 'selling/min price follow the new default and cheapest term');

section('B5. edit from a form that sends no terms');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Hosting', ['product_id' => (string)$pid, 'selling_price' => '1']));
ok(!empty($r['success']) && count(termsOf($pid)) === 3, 'terms untouched when the field is not sent');
ok((float)product($pid)['selling_price'] === 12000.0, 'selling_price re-synced to the default term (a stray typed price cannot break it)');

section('B6. a term id from another service is refused');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Plain', ['product_id' => (string)$plain,
    'price_terms' => json_encode([['term_id' => (int)$ids[0]] + $t('Hijack', 1, 'month', 1)])]));
ok(empty($r['success']) && !termsOf($plain), 'refused, and nothing written to either service');

section('B7. read endpoints');
$r = callApi('api/account/get_service_price_terms.php', [], 'GET', ['product_id' => (string)$pid]);
ok(!empty($r['success']) && count($r['data']) === 3 && $r['data'][0]['term_label'] === 'Monthly', 'get_service_price_terms returns the active terms in order');
$r = callApi('api/account/get_products.php', [], 'GET', ['search' => 'SPT-TEST', 'is_service' => '1']);
$byId = [];
foreach ($r['data'] ?? [] as $p) $byId[(int)$p['product_id']] = $p;
ok(!empty($byId[$pid]['has_price_terms']) && count($byId[$pid]['price_terms']) === 3, 'get_products embeds the terms for a termed service');
ok(isset($byId[$plain]) && empty($byId[$plain]['has_price_terms']) && $byId[$plain]['price_terms'] === [], 'a plain service reports no terms');

section('B8. platform-root subscription flag');
$vendorEnv = "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1');";
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Hosting', ['product_id' => (string)$pid, 'subscription_flag_sent' => '1', 'is_subscription' => '1']), 'POST', [], $vendorEnv);
ok(!empty($r['success']) && vssIsSubscription($pdo, $pid), 'on the platform root the service can be marked as a BMS subscription');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Hosting', ['product_id' => (string)$pid]), 'POST', [], $vendorEnv);
ok(vssIsSubscription($pdo, $pid), 'an edit whose form did not render the flag leaves it set');
$r = callApi('api/create_nip_product.php', $base('SPT-TEST Weekly', ['is_subscription' => '1',
    'price_terms' => json_encode([$t('2 Weeks', 2, 'week', 3000)])]), 'POST', [], $vendorEnv);
ok(empty($r['success']) && stripos($r['message'] ?? '', 'months or years') !== false, 'a subscription with a weekly term is refused');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Hosting', ['product_id' => (string)$pid, 'subscription_flag_sent' => '1']), 'POST', [], $vendorEnv);
ok(!vssIsSubscription($pdo, $pid), 'unticking clears the flag');

section('B9. pages');
function render(string $file, array $get = []): string {
    global $root, $uid;
    return runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_GET = " . var_export($get, true) . ";
        ob_start();
        include '$root/$file';
        echo ob_get_clean();
    ");
}
save_setting('pos_simple_mode', '0');
$html = render('app/bms/product/services.php', ['search' => 'SPT-TEST']);
ok(strpos($html, 'id="svc_spt_section"') !== false && strpos($html, 'id="edit_svc_spt_section"') !== false, 'full form: the Price by term editor is in both Add and Edit');
ok(strpos($html, 'From TSh 12,000') !== false && strpos($html, '>3 terms</span>') !== false, 'list shows "From TSh 12,000 · 3 terms" for the termed service');
ok(strpos($html, 'name="is_subscription"') === false, 'subscription checkbox absent off the platform root');
ok(strpos($html, 'Fatal error') === false && strpos($html, 'Warning:') === false, 'services page renders cleanly');

save_setting('pos_simple_mode', '1'); save_setting('pos_advanced_product', '0');
$html = render('app/bms/product/services.php', ['search' => 'SPT-TEST']);
ok(strpos($html, '_spt_section') === false, 'Simple POS short form: no price-term editor at all (unchanged)');
save_setting('pos_simple_mode', '0');

$html = render('app/bms/product/service_view.php', ['id' => (string)$pid]);
ok(strpos($html, 'Price by term') !== false && strpos($html, 'Monthly') !== false && strpos($html, '6 months') !== false, 'Service Details shows the price terms');
ok(strpos($html, 'id="svcCompTbody"') === false, 'Service Details: no materials table when the service has no materials');
ok(strpos($html, 'Fatal error') === false && strpos($html, 'Warning:') === false, 'service details renders cleanly');
$html = render('app/bms/product/service_view.php', ['id' => (string)$plain]);
ok(strpos($html, 'Price by term') === false, 'a plain service shows no terms card');
