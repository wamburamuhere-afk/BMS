<?php
/**
 * Plans: own product only + cheapest first (2026-11-10)
 *   php tests/test_plans_by_product_sorted_cli.php
 *
 * A: the sort rule on its own (no database) — by price per month, then tier, then
 *    name; a plan without a price goes last; 1-month price preferred.
 * B: on the local database + control DB, through the real signed gateway (pgHandle):
 *    a Smart-POS-like company sees only its product's plans, cheapest first; it cannot
 *    request the other product's plan; a company with no plan yet sees every product;
 *    the registration / trial list (listPublicOffers) is cheapest first too.
 * Data is 'PBS-TEST' / 'pbstest*' and removed afterwards. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
const PBS_KEY = 'e1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f95';
putenv('TENANT_CRED_KEY=' . PBS_KEY);
$hasConfig = is_file("$root/includes/config.php");
if ($hasConfig) { $_SERVER['HTTP_HOST'] = 'localhost'; require_once "$root/roots.php"; }
require_once "$root/core/plans.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $done = false; if ($done) return; $done = true;
    foreach (array_reverse($GLOBALS['TEST_CLEANUPS'] ?? []) as $fn) { try { $fn(); } catch (Throwable $e) { echo "cleanup error: {$e->getMessage()}\n"; } }
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

// ── A. The sort rule ───────────────────────────────────────────────────────
section('A. sort rule (no database)');
$p = fn(string $name, array $prices, int $tier = 0) => ['name' => $name, 'tier_rank' => $tier, 'prices' => $prices];
$m = fn(int $months, int $tzs) => ['months' => $months, 'price_tzs' => $tzs];
ok(planMonthlySortPrice([$m(12, 120000), $m(1, 15000)]) === 15000.0, '1-month price is used when there is one');
ok(planMonthlySortPrice([$m(12, 240000), $m(6, 150000)]) === 20000.0, 'else the lowest price per month');
ok(planMonthlySortPrice([]) === null, 'no price → null');
$sorted = array_column(planSortByPrice([
    $p('ENTERPRISE', [$m(1, 50000)]), $p('BASIC', [$m(1, 10000)]), $p('PROFESSIONAL', [$m(1, 30000)]),
    $p('STANDARD', [$m(1, 20000)]), $p('Nothing', []), $p('Twin', [$m(1, 20000)], 5),
], fn($x) => $x['prices']), 'name');
ok($sorted === ['BASIC', 'STANDARD', 'Twin', 'PROFESSIONAL', 'ENTERPRISE', 'Nothing'],
   'BASIC → STANDARD → (same price: lower tier first) → PROFESSIONAL → ENTERPRISE; no price last — got ' . implode(', ', $sorted));
$same = array_column(planSortByPrice([$p('b', [$m(1, 100)]), $p('A', [$m(1, 100)])], fn($x) => $x['prices']), 'name');
ok($same === ['A', 'b'], 'same price and tier → by name');

if (!$hasConfig) { echo "\nSKIP B: no includes/config.php (CI)\n"; exit; }

// ── B. Setup ───────────────────────────────────────────────────────────────
global $pdo;
require_once "$root/core/vendor_bridge.php";
require_once "$root/core/platform_link.php";
require_once "$root/core/platform_gateway.php";
sptEnsureSchema($pdo);
ob_start(); sptEnsureLineColumns($pdo); sptEnsurePlanLineColumns($pdo); ob_end_clean();
$c = getControlPdo();
if (!vlLinkReady() || !planCatalogReady()) { ok(false, 'control DB upgraded (run scripts/setup_control_db.php)'); exit; }
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$wid = (int)$pdo->query("SELECT warehouse_id FROM warehouses WHERE status = 'active' ORDER BY warehouse_id LIMIT 1")->fetchColumn();
if (!$uid || !$wid) { echo "SKIP B: needs an admin and a warehouse\n"; exit; }

$tag = 'pbs' . bin2hex(random_bytes(3));
$PA = $tag . '_pos'; $PB = $tag . '_bms';
$planIds = [];
$cleanup = function () use ($pdo, $c, &$planIds, $PA, $PB) {
    $in = fn($a) => $a ? implode(',', array_map('intval', $a)) : '0';
    $p = $in($pdo->query("SELECT product_id FROM products WHERE product_name LIKE 'PBS-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    try { $pdo->exec("DELETE FROM subscription_requests WHERE subdomain LIKE 'pbstest%'"); } catch (Throwable $e) {}
    foreach (['service_price_terms', 'vendor_subscription_services', 'products'] as $t) $pdo->exec("DELETE FROM $t WHERE product_id IN ($p)");
    $t = $in($c->query("SELECT id FROM tenants WHERE subdomain LIKE 'pbstest%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (['tenant_admin_log', 'tenant_features'] as $tb) { try { $c->exec("DELETE FROM $tb WHERE tenant_id IN ($t)"); } catch (Throwable $e) {} }
    $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'pbstest%'");
    foreach ($planIds as $id) {
        $id = (int)$id;
        $c->exec("DELETE pap FROM plan_addon_prices pap JOIN plan_addons pa ON pa.id = pap.addon_id WHERE pa.plan_id = $id");
        foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $tb) $c->exec("DELETE FROM $tb WHERE plan_id = $id");
        $c->exec("DELETE FROM plans WHERE id = $id");
    }
    $c->prepare("DELETE FROM platform_products WHERE product_key IN (?, ?)")->execute([$PA, $PB]);
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'pbs_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function lastJson(string $out): ?array { $j = json_decode($out, true); if (is_array($j)) return $j; $p = strrpos($out, "\n{"); return $p !== false ? json_decode(substr($out, $p + 1), true) : null; }
const VENDOR_ENV = "putenv('TENANT_CRED_KEY=" . PBS_KEY . "'); putenv('TENANT_MODE=on'); putenv('BMS_VENDOR_CONTEXT=1'); putenv('TENANT_BASE_DOMAIN=bms.bjptechnologies.co.tz');";
/** An endpoint as BJP staff would call it. */
function call(string $file, array $post): array {
    global $root, $uid;
    $j = lastJson(runPhp("
        \$_SERVER['REQUEST_METHOD'] = 'POST'; \$_SERVER['HTTP_HOST'] = 'localhost';
        require '$root/roots.php'; " . VENDOR_ENV . "
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true;
        \$_SESSION['first_name'] = 'Test'; \$_SESSION['last_name'] = 'Admin'; \$_SESSION['user_role'] = 'Admin'; \$_SESSION['user_lang'] = 'en';
        \$_POST = \$_REQUEST = " . var_export($post, true) . ";
        \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/$file'; \$o = ob_get_clean();
        \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);"));
    return is_array($j) ? $j : ['success' => false, 'message' => 'no JSON'];
}
/** The company's own server talking to the platform root (signed) — the real gateway path. */
function company(int $tid, array $body): array {
    global $root;
    $json = json_encode($body); $ts = time(); $sig = (string)plSignature($tid, $ts, $json);
    return lastJson(runPhp("\$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php'; " . VENDOR_ENV . " require_once '$root/core/platform_gateway.php'; global \$pdo;
        [\$code, \$o] = pgHandle(\$pdo, " . var_export($json, true) . ", ['X-Platform-Tenant' => '$tid', 'X-Platform-Ts' => '$ts', 'X-Platform-Sig' => " . var_export($sig, true) . "]);
        echo json_encode(['code' => \$code] + \$o);")) ?? ['success' => false];
}

// Product A (like Smart POS): names deliberately NOT in price order, tier all 0 except Twin.
$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?,?,1,0,1), (?,?,1,1,1)")
  ->execute([$PA, "POS $tag", $PB, "BMS $tag"]);
$mk = function (string $prod, string $name, array $prices, int $tier = 0) use (&$planIds, $tag) {
    $r = createPlan(['name' => "$name $tag", 'product_key' => $prod, 'max_users' => 2, 'max_storage_mb' => 500, 'max_shops' => 1,
                     'feature_keys' => ['warehouses', 'pos'], 'tier_rank' => $tier, 'is_public' => true, 'is_popular' => false,
                     'items' => [], 'addons' => [], 'prices' => $prices]);
    if (!empty($r['id'])) $planIds[] = (int)$r['id'];
    return !empty($r['ok']) ? getPlan((int)$r['id']) : null;
};
$pm = fn(int $v, string $u, int $tzs, int $def = 0) => ['duration_value' => $v, 'duration_unit' => $u, 'price_tzs' => $tzs, 'is_default' => $def];
$enterprise = $mk($PA, 'Enterprise', [$pm(1, 'month', 50000, 1), $pm(1, 'year', 500000)]);
$basic      = $mk($PA, 'Basic',      [$pm(1, 'month', 10000, 1), $pm(3, 'month', 25000)]);
$pro        = $mk($PA, 'Professional', [$pm(1, 'month', 30000, 1)]);
$standard   = $mk($PA, 'Standard',   [$pm(1, 'year', 240000, 1)]);          // 20,000 a month
$bmsPlan    = $mk($PB, 'Smart BMS Basic', [$pm(1, 'month', 30000, 1)]);
if (!$enterprise || !$basic || !$pro || !$standard || !$bmsPlan) { ok(false, 'plan fixtures created'); exit; }
$expected = [$basic['plan_key'], $standard['plan_key'], $pro['plan_key'], $enterprise['plan_key']];

$svcBase = ['is_service' => '1', 'track_inventory' => '0', 'unit' => 'job', 'status' => 'active', 'selling_price' => '0', 'warehouse_id' => (string)$wid, 'cost_price' => '0'];
$mkSvc = fn(string $name, string $key) => (int)(call('api/create_nip_product.php', $svcBase + ['product_name' => $name, 'subscription_flag_sent' => '1', 'is_subscription' => '1',
    'platform_product_sent' => '1', 'platform_product_key' => $key, 'price_terms' => ''])['product_id'] ?? 0);
$svcA = $mkSvc('PBS-TEST Smart POS', $PA);
$svcB = $mkSvc('PBS-TEST Smart BMS', $PB);

$mkTenant = function (string $sub, string $plan) use ($c): int {
    $c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, status, trial_ends_at, plan, max_users, max_shops)
                 VALUES (?, ?, 'none', 'none', 'x', 'pbs@example.com', 'trial', DATE_ADD(NOW(), INTERVAL 5 DAY), ?, 2, 1)")->execute(["PBS-TEST $sub", $sub, $plan]);
    return (int)$c->lastInsertId();
};
$tPos   = $mkTenant('pbstest1', $basic['plan_key']);
$tBlank = $mkTenant('pbstest2', 'blank');
ok($svcA && $svcB && vssPlatformProduct($pdo, $svcA) === $PA && vssPlatformProduct($pdo, $svcB) === $PB && $tPos && $tBlank,
   'fixtures: two products (POS-like with 4 plans, BMS-like), their BJP services, a company on POS Basic, a company with no plan');

// ── B1. Own product only, cheapest first ───────────────────────────────────
section('B1. a POS company sees only POS plans, cheapest first');
$cat = company($tPos, ['action' => 'catalog']);
$ids = array_column($cat['services'] ?? [], 'product_id');
ok(($cat['code'] ?? 0) === 200 && in_array($svcA, $ids, true), 'its own product (POS) is listed');
ok(!in_array($svcB, $ids, true), 'the other product (BMS) is NOT listed');
$svc = array_values(array_filter($cat['services'] ?? [], fn($s) => $s['product_id'] === $svcA))[0] ?? [];
$got = array_column($svc['plans'] ?? [], 'plan_key');
ok($got === $expected, 'plan cards cheapest first: Basic 10,000 → Standard 20,000/month → Professional 30,000 → Enterprise 50,000');
$bTerms = array_values(array_filter($svc['terms'] ?? [], fn($t) => $t['plan_key'] === $basic['plan_key']));
ok(array_column($bTerms, 'months') === [1, 3], 'periods inside a plan keep their order (1 month → 3 months)');

// ── B2. Requests follow the same rule ──────────────────────────────────────
section('B2. the other product cannot be requested');
$bmsToken = 'p' . (int)planPrices((int)$bmsPlan['id'], true)[0]['id'];
$r = company($tPos, ['action' => 'request_quote', 'product_id' => $svcB, 'term_id' => $bmsToken, 'extras' => []]);
ok(empty($r['success']) && str_contains((string)($r['message'] ?? ''), 'another product'), 'request for the BMS plan refused: "' . ($r['message'] ?? '') . '"');
$r = company($tPos, ['action' => 'request_quote', 'product_id' => $svcA, 'term_id' => 'p999999999', 'extras' => []]);
ok(empty($r['success']) && ($r['message'] ?? '') === 'That plan is not available.', 'own product passes the product check (then the normal plan check applies)');

// ── B3. No plan yet → everything ───────────────────────────────────────────
section('B3. a company with no plan yet sees every product');
$ids = array_column(company($tBlank, ['action' => 'catalog'])['services'] ?? [], 'product_id');
ok(in_array($svcA, $ids, true) && in_array($svcB, $ids, true), 'both products listed');

// ── B4. Registration / free-trial list ─────────────────────────────────────
section('B4. registration and free-trial lists are cheapest first');
$offer = array_values(array_filter(listPublicOffers(null, true), fn($o) => $o['product_key'] === $PA))[0] ?? [];
ok(array_column($offer['plans'] ?? [], 'plan_key') === $expected, 'registration (listPublicOffers) — same order');
$own = listPublicOffers($PA)[0] ?? [];
ok(array_column($own['plans'] ?? [], 'plan_key') === $expected && count(listPublicOffers($PA)) === 1, 'free-trial switch list — own product only, same order');
