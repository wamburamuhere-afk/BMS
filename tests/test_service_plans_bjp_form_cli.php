<?php
/**
 * Service plans on the service form (service_plans_plan.md Phase 2)
 *   php tests/test_service_plans_bjp_form_cli.php
 *
 * A: spParse validation (no database — runs in CI)
 * B: the real endpoints (create/update/get), retire rules, the line-label snapshot,
 *    the platform-product mapping (BJP's install) and the pages, on the local
 *    database + control DB. Test data is named 'SPT-TEST …' / 'spt…' and removed.
 *    Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
if (is_file("$root/includes/config.php")) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
require_once "$root/core/service_plans.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
function throws(callable $f): ?string { try { $f(); return null; } catch (InvalidArgumentException $e) { return $e->getMessage(); } }

register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

$t = fn($label, $v, $u, $price, $def = 0, $id = null) => ['term_id' => $id, 'term_label' => $label, 'duration_value' => $v, 'duration_unit' => $u, 'price' => $price, 'is_default' => $def];
$plan = fn($name, array $terms, array $extra = []) => array_merge(['plan_id' => null, 'plan_name' => $name, 'tagline' => '', 'is_popular' => 0, 'items' => [], 'terms' => $terms], $extra);

// ── A. Validation ──────────────────────────────────────────────────────────
section('A. spParse');
ok(spParse(null) === null, 'field not sent → null (plans left as they are)');
ok(spParse('') === [] && spParse('[]') === [], 'empty → [] (remove all plans)');
$r = spParse(json_encode([
    $plan('Starter', [$t('1 Month', 1, 'month', 10000), $t('1 Year', 1, 'year', 85000)], ['items' => [['label' => 'Users', 'value' => '2'], ['label' => '', 'value' => '']]]),
    $plan('Business', [$t('1 Month', 1, 'month', 20000)], ['is_popular' => 1, 'tagline' => 'For growing shops']),
]));
ok(count($r) === 2 && count($r[0]['terms']) === 2, 'two plans, each with its own terms');
ok($r[0]['terms'][0]['is_default'] === 1 && $r[0]['terms'][1]['is_default'] === 0 && $r[1]['terms'][0]['is_default'] === 0,
   'no default chosen → only the FIRST plan\'s first term is default (one default per service)');
ok(count($r[0]['items']) === 1 && $r[0]['items'][0]['label'] === 'Users', 'a fully blank included line is skipped');
ok($r[1]['is_popular'] && $r[1]['tagline'] === 'For growing shops', 'popular + tagline kept');
$r = spParse([$plan('A', [$t('M', 1, 'month', 1)]), $plan('B', [$t('M', 1, 'month', 2, 1)])]);
ok($r[0]['terms'][0]['is_default'] === 0 && $r[1]['terms'][0]['is_default'] === 1, 'an explicit default on the second plan is respected');
ok(throws(fn() => spParse([$plan('', [$t('M', 1, 'month', 1)])])) !== null, 'plan name required');
ok(throws(fn() => spParse([$plan(str_repeat('x', 101), [$t('M', 1, 'month', 1)])])) !== null, 'plan name ≤ 100');
ok(throws(fn() => spParse([$plan('A', [$t('M', 1, 'month', 1)]), $plan('a', [$t('M', 1, 'month', 1)])])) !== null, 'duplicate plan names refused (case-insensitive)');
ok(throws(fn() => spParse([$plan('A', [])])) !== null, 'a plan needs at least one price');
ok(str_contains((string)throws(fn() => spParse([$plan('Gold', [$t('M', 1, 'month', -1)])])), 'Plan "Gold"'), 'term errors name the plan');
ok(throws(fn() => spParse([$plan('A', [$t('M', 1, 'month', 1)], ['is_popular' => 1]), $plan('B', [$t('M', 1, 'month', 1)], ['is_popular' => 1])])) !== null, 'two "Most popular" refused');
ok(throws(fn() => spParse([$plan('A', [$t('M', 1, 'month', 1, 1)]), $plan('B', [$t('M', 1, 'month', 1, 1)])])) !== null, 'two defaults across plans refused');
ok(throws(fn() => spParse([$plan('A', [$t('M', 1, 'month', 1)], ['items' => [['label' => '', 'value' => '5']]])])) !== null, 'an included line with a value but no name refused');
ok(throws(fn() => spParse([$plan('A', [$t('M', 1, 'month', 1)], ['tagline' => str_repeat('y', 256)])])) !== null, 'tagline ≤ 255');
ok(throws(fn() => spParse(array_fill(0, 13, $plan('X', [$t('M', 1, 'month', 1)])))) !== null, 'more than 12 plans refused');
ok(throws(fn() => spParse('{bad')) !== null, 'unreadable input refused');
ok(sptDisplayLabel('Starter', '1 Month') === 'Starter — 1 Month' && sptDisplayLabel(null, '1 Month') === '1 Month', 'display label: "Plan — Term", plain term unchanged');

if (!is_file("$root/includes/config.php")) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B. Real endpoints ──────────────────────────────────────────────────────
global $pdo;
sptEnsureSchema($pdo);
spEnsureSchema($pdo);

$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid) { echo "SKIP B: needs an admin user and an active warehouse\n"; exit; }

$cleanup = function () use ($pdo) {
    $pdo->exec("DELETE FROM quotation_items WHERE product_name LIKE 'SPT-TEST%'");
    $ids = $pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'SPT-TEST%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE i FROM service_plan_items i JOIN service_plans p ON p.plan_id = i.plan_id WHERE p.product_id IN ($in)");
        $pdo->exec("DELETE FROM service_price_terms WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM service_plans WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM vendor_subscription_services WHERE product_id IN ($in)");
        $pdo->exec("DELETE FROM products WHERE product_id IN ($in)");
    }
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'spb_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
/** Call an endpoint exactly as the browser would. $env: extra lines (vendor context); $admin=false → a non-admin session. */
function callApi(string $file, array $post, string $method = 'POST', array $get = [], string $env = '', bool $admin = true): array {
    global $root, $uid;
    $adm = $admin ? 'true' : 'false';
    $out = runPhp("
        \$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . "; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php';
        $env
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = $adm;
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
function plansOf(int $id, string $status = 'active'): array { global $pdo; $s = $pdo->prepare("SELECT * FROM service_plans WHERE product_id = ? AND status = ? ORDER BY sort_order"); $s->execute([$id, $status]); return $s->fetchAll(PDO::FETCH_ASSOC); }
$base = fn(string $name, array $extra = []) => array_merge(['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active',
    'product_name' => $name, 'selling_price' => '999', 'warehouse_id' => (string)$wid, 'cost_price' => '0'], $extra);
$msg = fn(array $r) => empty($r['success']) ? ' — ' . ($r['message'] ?? '') : '';

section('B1. create with plans');
$plans = [
    $plan('Starter', [$t('1 Month', 1, 'month', 10000), $t('1 Year', 1, 'year', 85000)], ['tagline' => 'One shop', 'items' => [['label' => 'Users', 'value' => '2'], ['label' => 'Shops', 'value' => '1']]]),
    $plan('Business', [$t('1 Month', 1, 'month', 20000, 1), $t('1 Year', 1, 'year', 180000)], ['is_popular' => 1]),
];
$r = callApi('api/create_nip_product.php', $base('SPT-TEST Shop System', ['service_plans' => json_encode($plans), 'price_terms' => '[]']));
ok(!empty($r['success']), 'service with plans created via the real endpoint' . $msg($r));
$pid = (int)($r['product_id'] ?? 0);
$pl = plansOf($pid);
ok(count($pl) === 2 && $pl[0]['plan_name'] === 'Starter' && (int)$pl[1]['is_popular'] === 1, 'two plans stored in order, popular flag kept');
$terms = termsOf($pid);
ok(count($terms) === 4 && (int)$terms[0]['plan_id'] === (int)$pl[0]['plan_id'] && (int)$terms[2]['plan_id'] === (int)$pl[1]['plan_id'], 'each plan\'s terms are ordinary price terms carrying plan_id');
ok(count(array_filter($terms, fn($x) => (int)$x['is_default'] === 1)) === 1 && (float)$terms[2]['price'] === 20000.0 && (int)$terms[2]['is_default'] === 1, 'exactly one default (Business — 1 Month)');
ok((float)product($pid)['selling_price'] === 20000.0 && (float)product($pid)['min_selling_price'] === 10000.0, 'selling = default price, min = cheapest across plans');
$items = $pdo->query("SELECT label, value FROM service_plan_items WHERE plan_id = " . (int)$pl[0]['plan_id'] . " ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
ok(count($items) === 2 && $items[0]['label'] === 'Users' && $items[0]['value'] === '2', 'what\'s included stored');

section('B2. read endpoints');
$r = callApi('api/account/get_service_price_terms.php', [], 'GET', ['product_id' => (string)$pid]);
ok(!empty($r['success']) && count($r['plans']) === 2 && count($r['plans'][0]['terms']) === 2 && count($r['plans'][0]['items']) === 2, 'get_service_price_terms returns plans with terms + items');
ok(count($r['data']) === 4 && $r['data'][0]['display_label'] === 'Starter — 1 Month', 'the flat `data` list (line pickers) still has every term, labelled "Plan — Term"');
ok($r['platform_product_key'] === null && $r['platform_plans'] === [], 'no platform product off BJP\'s install');
$r = callApi('api/account/get_products.php', [], 'GET', ['search' => 'SPT-TEST Shop', 'is_service' => '1']);
$row = array_values(array_filter($r['data'] ?? [], fn($p) => (int)$p['product_id'] === $pid))[0] ?? [];
ok(!empty($row['has_price_terms']) && count($row['price_terms']) === 4 && $row['price_terms'][2]['display_label'] === 'Business — 1 Month', 'get_products gives document lines every plan term, labelled');

section('B3. the line snapshot');
$res = sptResolveLineTerm($pdo, $pid, (int)$terms[3]['term_id'], 'Setup included');
ok($res['price_term_label'] === 'Business — 1 Year' && str_contains($res['description'], 'Term: Business — 1 Year'), 'a document line snapshots "Plan — Term" (label + description line)');
$res = sptResolveLineTerm($pdo, $pid, 0, 'x');
ok($res['price_term_id'] === null, 'no term chosen → no snapshot');

section('B4. refusals');
$before = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_name LIKE 'SPT-TEST%'")->fetchColumn();
$r = callApi('api/create_nip_product.php', $base('SPT-TEST Both', ['service_plans' => json_encode($plans), 'price_terms' => json_encode([$t('1 Month', 1, 'month', 1)])]));
ok(empty($r['success']) && stripos($r['message'] ?? '', 'not both') !== false, 'plans AND plain terms together refused: ' . ($r['message'] ?? ''));
ok((int)$pdo->query("SELECT COUNT(*) FROM products WHERE product_name LIKE 'SPT-TEST%'")->fetchColumn() === $before, 'no half-created service left behind');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Shop System', ['product_id' => (string)$pid, 'price_terms' => json_encode([$t('1 Month', 1, 'month', 5)])]));
ok(empty($r['success']) && stripos($r['message'] ?? '', 'priced by plans') !== false && count(termsOf($pid)) === 4, 'an old form sending only plain terms cannot wipe the plans\' prices');

$r = callApi('api/create_nip_product.php', $base('SPT-TEST Other', ['service_plans' => json_encode([$plan('Solo', [$t('1 Month', 1, 'month', 7000)])])]));
$other = (int)($r['product_id'] ?? 0);
ok(!empty($r['success']) && $other > 0, 'second plan-priced service created' . $msg($r));
$foreignPlan = (int)plansOf($other)[0]['plan_id'];
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Shop System', ['product_id' => (string)$pid,
    'service_plans' => json_encode([$plan('Hijack', [$t('1 Month', 1, 'month', 1)], ['plan_id' => $foreignPlan])])]));
ok(empty($r['success']) && plansOf($other)[0]['plan_name'] === 'Solo' && count(plansOf($pid)) === 2, 'a plan id from another service refused; neither service touched');
$foreignTerm = (int)termsOf($other)[0]['term_id'];
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Shop System', ['product_id' => (string)$pid,
    'service_plans' => json_encode([$plan('Starter', [$t('Stolen', 1, 'month', 1, 0, $foreignTerm)], ['plan_id' => (int)$pl[0]['plan_id']])])]));
ok(empty($r['success']) && termsOf($other)[0]['term_label'] === '1 Month' && count(plansOf($pid)) === 2, 'a term id from another service refused');

section('B5. edit: rename, add, remove (used → inactive, unused → deleted)');
// A quotation line used Business — 1 Year, so Business must be kept (inactive) when removed.
$pdo->prepare("INSERT INTO quotation_items (order_id, product_id, product_name, price_term_id) VALUES (0, ?, 'SPT-TEST used line', ?)")
    ->execute([$pid, (int)$terms[3]['term_id']]);
$edit = [
    $plan('Starter Plus', [$t('1 Month', 1, 'month', 12000, 1, (int)$terms[0]['term_id']), $t('6 Months', 6, 'month', 60000)],
          ['plan_id' => (int)$pl[0]['plan_id'], 'items' => [['label' => 'Users', 'value' => '3']]]),
    $plan('Enterprise', [$t('1 Year', 1, 'year', 300000)]),
];
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Shop System', ['product_id' => (string)$pid, 'service_plans' => json_encode($edit), 'price_terms' => '[]']));
ok(!empty($r['success']), 'edit saved' . $msg($r));
$now = plansOf($pid);
ok(count($now) === 2 && $now[0]['plan_name'] === 'Starter Plus' && (int)$now[0]['plan_id'] === (int)$pl[0]['plan_id'] && $now[1]['plan_name'] === 'Enterprise', 'plan renamed in place (same id) and a new plan added');
ok(count(plansOf($pid, 'inactive')) === 1 && plansOf($pid, 'inactive')[0]['plan_name'] === 'Business', 'the removed plan a document used is kept inactive');
$tt = termsOf($pid);
ok(count($tt) === 3 && (int)$tt[0]['term_id'] === (int)$terms[0]['term_id'] && (float)$tt[0]['price'] === 12000.0, 'kept term updated in place; terms follow the plans');
$st = $pdo->prepare("SELECT status FROM service_price_terms WHERE term_id = ?");
$st->execute([(int)$terms[3]['term_id']]); ok($st->fetchColumn() === 'inactive', 'the used term stays (inactive) so the old quotation still resolves');
$st->execute([(int)$terms[1]['term_id']]); ok($st->fetchColumn() === 'deleted', 'an unused removed term is deleted');
ok((float)product($pid)['selling_price'] === 12000.0, 'selling price follows the new default');
$items = $pdo->query("SELECT label, value FROM service_plan_items WHERE plan_id = " . (int)$pl[0]['plan_id'])->fetchAll(PDO::FETCH_ASSOC);
ok(count($items) === 1 && $items[0]['value'] === '3', 'what\'s included rewritten');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Shop System', ['product_id' => (string)$pid, 'selling_price' => '1']));
ok(!empty($r['success']) && count(plansOf($pid)) === 2 && (float)product($pid)['selling_price'] === 12000.0, 'a form that sends neither field leaves the plans; price re-synced');

section('B6. remove every plan → plain service again');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Other', ['product_id' => (string)$other, 'service_plans' => '[]', 'price_terms' => '[]', 'selling_price' => '4500']));
ok(!empty($r['success']) && !plansOf($other) && !termsOf($other), 'all plans and their terms retired' . $msg($r));
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Other', ['product_id' => (string)$other, 'price_terms' => json_encode([$t('Weekly', 1, 'week', 900)])]));
ok(!empty($r['success']) && count(termsOf($other)) === 1 && termsOf($other)[0]['plan_id'] === null, 'it can then take plain terms again' . $msg($r));

section('B7. a plain service is unchanged');
$r = callApi('api/create_nip_product.php', $base('SPT-TEST Plain', ['selling_price' => '5000']));
$plain = (int)($r['product_id'] ?? 0);
ok(!empty($r['success']) && (float)product($plain)['selling_price'] === 5000.0 && !termsOf($plain) && !plansOf($plain), 'no terms, no plans: typed price kept');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Plain', ['product_id' => (string)$plain, 'selling_price' => '6000', 'price_terms' => '[]']));
ok(!empty($r['success']) && (float)product($plain)['selling_price'] === 6000.0, 'edit with an empty terms table keeps the typed price');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Plain', ['product_id' => (string)$plain, 'price_terms' => json_encode([$t('1 Month', 1, 'month', 3000), $t('1 Year', 1, 'year', 30000, 1)])]));
ok(!empty($r['success']) && count(termsOf($plain)) === 2 && (float)product($plain)['selling_price'] === 30000.0, 'plain terms work exactly as before');

// ── C. Platform product mapping (BJP's install) ────────────────────────────
section('C. platform product mapping');
$vendorEnv = "putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1');";
require_once "$root/core/plans.php";
if (!function_exists('getControlPdo') || !planCatalogReady()) { echo "SKIP C: control DB catalogue not available\n"; goto pages; }
$c = getControlPdo();
$tag = 'spt' . bin2hex(random_bytes(3));
$PROD = $tag . '_prod';
$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,1,0,1)")->execute([$PROD, "Test Shop $tag"]);
$cp = createPlan(['name' => "Shop Basic $tag", 'max_users' => 2, 'max_storage_mb' => 500, 'feature_keys' => ['pos'], 'product_key' => $PROD,
    'max_shops' => 1, 'tier_rank' => 10, 'is_public' => true, 'is_popular' => false,
    'items' => [['label' => 'Receipts by SMS', 'value' => '']],
    'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 15000, 'is_default' => 0],
                 ['duration_value' => 1, 'duration_unit' => 'year',  'price_tzs' => 150000, 'is_default' => 1]]]);
$GLOBALS['TEST_CLEANUPS'][] = function () use ($c, $cp, $PROD) {
    $id = (int)($cp['id'] ?? 0);
    if ($id) {
        $c->exec("DELETE pap FROM plan_addon_prices pap JOIN plan_addons pa ON pa.id = pap.addon_id WHERE pa.plan_id = $id");
        foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $tb) $c->exec("DELETE FROM $tb WHERE plan_id = $id");
        $c->exec("DELETE FROM plans WHERE id = $id");
    }
    $c->prepare("DELETE FROM platform_products WHERE product_key = ?")->execute([$PROD]);
};
ok(!empty($cp['ok']), 'fixture: a platform product with one priced plan' . (empty($cp['ok']) ? ' — ' . ($cp['error'] ?? '') : ''));
$pp = spPlatformPlans($PROD);
ok(count($pp) === 1 && count($pp[0]['prices']) === 2 && $pp[0]['is_public'] === true, 'spPlatformPlans reads the plan live from the control DB');
ok(isset(spPlatformProducts()[$PROD]), 'spPlatformProducts lists it');

$mapPost = fn(string $name, int $id, array $extra = []) => $base($name, array_merge(['product_id' => (string)$id, 'subscription_flag_sent' => '1',
    'is_subscription' => '1', 'platform_product_sent' => '1', 'platform_product_key' => $PROD, 'price_terms' => ''], $extra));

// The admin gate itself (a non-admin with Services edit rights reaches spSaveFromForm).
putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1');
$_SESSION['is_admin'] = false;
$e = throws(fn() => spSaveFromForm($pdo, $pid, [], null, ['platform_product_sent' => '1', 'platform_product_key' => $PROD], $uid, false));
$_SESSION['is_admin'] = true;
putenv('TENANT_MODE'); putenv('BMS_VENDOR_CONTEXT');
ok($e !== null && stripos($e, 'only an admin') !== false && vssPlatformProduct($pdo, $pid) === null, 'a non-admin cannot map a service');
$offPost = $mapPost('SPT-TEST Plain', $plain); unset($offPost['price_terms']);
$r = callApi('api/update_nip_product.php', $offPost, 'POST', [], '');
ok(!empty($r['success']) && vssPlatformProduct($pdo, $plain) === null, 'off BJP\'s install the mapping fields are ignored' . $msg($r));

$r = callApi('api/update_nip_product.php', $mapPost('SPT-TEST Shop System', $pid), 'POST', [], $vendorEnv);
ok(!empty($r['success']) && vssPlatformProduct($pdo, $pid) === $PROD, 'admin maps the plan-priced service to the platform product' . $msg($r));
ok(vssIsSubscription($pdo, $pid), 'it is a system subscription');
ok(!plansOf($pid) && !termsOf($pid) && count(plansOf($pid, 'inactive')) >= 1, 'its own plans/terms are retired (the used one kept inactive)');
ok((float)product($pid)['selling_price'] === 150000.0 && (float)product($pid)['min_selling_price'] === 15000.0, 'selling = Superadmin default, min = Superadmin cheapest');
$r = callApi('api/account/get_service_price_terms.php', [], 'GET', ['product_id' => (string)$pid], $vendorEnv);
ok(($r['platform_product_key'] ?? null) === $PROD && count($r['platform_plans'] ?? []) === 1 && $r['plans'] === [], 'read API: platform key + live Superadmin plans, no local plans');

$r = callApi('api/update_nip_product.php', $mapPost('SPT-TEST Shop System', $pid, ['service_plans' => json_encode([$plan('Local', [$t('1 Month', 1, 'month', 1)])])]), 'POST', [], $vendorEnv);
ok(empty($r['success']) && stripos($r['message'] ?? '', 'Superadmin') !== false && !plansOf($pid), 'a mapped service refuses local plans');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Shop System', ['product_id' => (string)$pid, 'price_terms' => json_encode([$t('1 Month', 1, 'month', 1)])]), 'POST', [], $vendorEnv);
ok(empty($r['success']) && !termsOf($pid), 'a mapped service refuses plain terms (even from a form without the mapping field)');
ok(throws(fn() => sptSave($pdo, $pid, sptParse([$t('1 Month', 1, 'month', 1)]), $uid)) !== null, 'sptSave itself refuses a mapped service');
$r = callApi('api/update_nip_product.php', $mapPost('SPT-TEST Shop System', $pid, ['is_subscription' => '']), 'POST', [], $vendorEnv);
ok(empty($r['success']) && vssIsSubscription($pdo, $pid), 'unticking "subscription" on a mapped service is refused');
$r = callApi('api/update_nip_product.php', $base('SPT-TEST Shop System', ['product_id' => (string)$pid, 'subscription_flag_sent' => '1', 'is_subscription' => '1', 'price_terms' => '']), 'POST', [], $vendorEnv);
ok(!empty($r['success']) && vssPlatformProduct($pdo, $pid) === $PROD, 'an edit whose form has no mapping field (the non-admin form) keeps the mapping' . $msg($r));

$r = callApi('api/update_nip_product.php', $mapPost('SPT-TEST Plain', $plain), 'POST', [], $vendorEnv);
ok(empty($r['success']) && stripos($r['message'] ?? '', 'already sold by') !== false && vssPlatformProduct($pdo, $plain) === null, 'one service per platform product');
$r = callApi('api/update_nip_product.php', $mapPost('SPT-TEST Plain', $plain, ['platform_product_key' => 'no_such_product']), 'POST', [], $vendorEnv);
ok(empty($r['success']) && vssPlatformProduct($pdo, $plain) === null, 'an unknown platform product refused');

$r = callApi('api/update_nip_product.php', $mapPost('SPT-TEST Shop System', $pid, ['platform_product_key' => '', 'price_terms' => json_encode([$t('1 Month', 1, 'month', 9000)])]), 'POST', [], $vendorEnv);
ok(!empty($r['success']) && vssPlatformProduct($pdo, $pid) === null && count(termsOf($pid)) === 1, 'unmapping lifts the lock: the service takes its own terms again' . $msg($r));
ok((float)product($pid)['selling_price'] === 9000.0 && vssIsSubscription($pdo, $pid), 'price follows its own term; still a subscription');

$r = callApi('api/create_nip_product.php', $base('SPT-TEST New Mapped', ['subscription_flag_sent' => '1', 'is_subscription' => '1',
    'platform_product_sent' => '1', 'platform_product_key' => $PROD, 'price_terms' => '']), 'POST', [], $vendorEnv);
$newMapped = (int)($r['product_id'] ?? 0);
ok(!empty($r['success']) && vssPlatformProduct($pdo, $newMapped) === $PROD && (float)product($newMapped)['selling_price'] === 150000.0, 'a NEW service can be created already mapped' . $msg($r));

// ── D. Pages ───────────────────────────────────────────────────────────────
pages:
section('D. pages');
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
/** node --check every inline script of a page (PHP-rendered JS must parse). */
function jsParses(string $html): ?string {
    $html = preg_replace('#<!--.*?-->#s', '', $html);   // comments may mention <script>
    preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $html, $m);
    $tmp = tempnam(sys_get_temp_dir(), 'spjs_') . '.js';
    foreach ($m[1] as $i => $js) {
        if (trim($js) === '') continue;
        file_put_contents($tmp, $js);
        $out = shell_exec('node --check ' . escapeshellarg($tmp) . ' 2>&1');
        if (trim((string)$out) !== '') { @unlink($tmp); return "script #$i: " . trim($out); }
    }
    @unlink($tmp);
    return null;
}
$origSimple = get_setting('pos_simple_mode', '0');
save_setting('pos_simple_mode', '0');
$GLOBALS['TEST_CLEANUPS'][] = fn() => save_setting('pos_simple_mode', $origSimple);

$html = render('app/bms/product/services.php', ['search' => 'SPT-TEST']);
ok(strpos($html, 'id="svc_sp_bar"') !== false && strpos($html, 'id="edit_svc_sp_drawer"') !== false && strpos($html, 'name="service_plans"') !== false, 'Add + Edit forms have the plans bar, drawer and field');
ok(preg_match('/From TSh 4,500|>1 term<\/span>/', $html) === 1 || strpos($html, '>1 term</span>') !== false, 'list: a plain-term service still shows its term pill');
ok(strpos($html, 'name="platform_product_key"') === false, 'no "This service sells" off BJP\'s install');
ok(strpos($html, 'Fatal error') === false && strpos($html, 'Warning:') === false && strpos($html, 'Notice:') === false, 'services page renders cleanly');
ok(($e = jsParses($html)) === null, 'every inline script parses' . ($e ? " — $e" : ''));

$html = render('app/bms/product/services.php', ['search' => 'SPT-TEST'], $vendorEnv);
if (isset($PROD)) {
    ok(strpos($html, 'name="platform_product_key"') !== false && strpos($html, 'value="' . $PROD . '"') !== false, 'BJP admin: "This service sells" lists the platform product');
    ok(strpos($html, 'From Superadmin') !== false, 'list: the mapped service shows "From Superadmin"');
}
ok(strpos($html, 'Fatal error') === false && strpos($html, 'Warning:') === false, 'vendor services page renders cleanly');
ok(($e = jsParses($html)) === null, 'vendor page: every inline script parses' . ($e ? " — $e" : ''));

// A plan-priced service in the list
$r = callApi('api/create_nip_product.php', $base('SPT-TEST Listed', ['service_plans' => json_encode([$plan('A', [$t('1 Month', 1, 'month', 1000)]), $plan('B', [$t('1 Month', 1, 'month', 2000)])])]));
$html = render('app/bms/product/services.php', ['search' => 'SPT-TEST Listed']);
ok(strpos($html, '>2 plans</span>') !== false && strpos($html, 'From TSh 1,000') !== false, 'list: "From TSh 1,000 · 2 plans" for a plan-priced service');
$html = render('app/bms/product/service_view.php', ['id' => (string)($r['product_id'] ?? 0)]);
ok(strpos($html, 'A — 1 Month') !== false && strpos($html, 'Fatal error') === false, 'Service Details shows "Plan — Term"');
