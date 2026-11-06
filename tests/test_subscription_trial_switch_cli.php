<?php
/**
 * Free trial: the company switches plan itself (api/my_subscription.php trial_plans / trial_switch).
 *   php tests/test_subscription_trial_switch_cli.php
 * Data is 'tswtest*' tenants, 'TSW-TEST' plans and one test product, removed at the end. Exit 0 = pass.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
$_SERVER['HTTP_HOST'] = 'localhost';
require_once "$root/roots.php";
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

global $pdo;
$c = getControlPdo();
$PROD = 'tsw_test_prod';
$uid = (int)$pdo->query("SELECT user_id FROM users WHERE role_id = 1 ORDER BY user_id LIMIT 1")->fetchColumn();
$planIds = [];
$cleanup = function () use ($c, $PROD, &$planIds) {
    $ids = $c->query("SELECT id FROM tenants WHERE subdomain LIKE 'tswtest%'")->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (['tenant_addon_grants', 'tenant_payments', 'tenant_admin_log', 'tenant_features'] as $tb) {
            try { $c->exec("DELETE FROM $tb WHERE tenant_id IN ($in)"); } catch (Throwable $e) {}
        }
        $c->exec("DELETE FROM tenants WHERE id IN ($in)");
    }
    $all = array_merge($planIds, $c->query("SELECT id FROM plans WHERE name LIKE 'TSW-TEST%'")->fetchAll(PDO::FETCH_COLUMN));
    foreach (array_unique(array_map('intval', $all)) as $id) {
        $c->exec("DELETE pap FROM plan_addon_prices pap JOIN plan_addons pa ON pa.id = pap.addon_id WHERE pa.plan_id = $id");
        foreach (['plan_addons', 'plan_prices', 'plan_items', 'plan_features'] as $tb) $c->exec("DELETE FROM $tb WHERE plan_id = $id");
        $c->exec("DELETE FROM plans WHERE id = $id");
    }
    $c->prepare("DELETE FROM platform_products WHERE product_key = ?")->execute([$PROD]);
};
$cleanup();
$GLOBALS['TEST_CLEANUPS'][] = $cleanup;

$users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$shops = (int)$pdo->query("SELECT COUNT(*) FROM warehouses WHERE status = 'active'")->fetchColumn();
if ($shops < 2) { echo "SKIP: needs at least 2 active shops locally to test the downgrade block\n"; exit; }

// A product with four plans: Small (1 shop), Mid (room for this DB), Big (unlimited), Private (not public).
$c->prepare("INSERT INTO platform_products (product_key, name, show_on_registration, sort_order, is_active) VALUES (?, 'TSW Test', 1, 99, 1)")->execute([$PROD]);
$price = fn(int $tzs) => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => $tzs, 'is_default' => 1]];
$mk = function (string $name, ?int $u, ?int $s, int $tier, bool $public, int $tzs) use (&$planIds, $PROD, $price) {
    $r = createPlan(['name' => "TSW-TEST $name", 'max_users' => $u, 'max_storage_mb' => 500, 'max_shops' => $s, 'tier_rank' => $tier,
                     'is_public' => $public, 'is_popular' => false, 'product_key' => $PROD, 'feature_keys' => ['warehouses', 'pos'],
                     'items' => [], 'addons' => [], 'prices' => $price($tzs)]);
    if (!empty($r['id'])) $planIds[] = (int)$r['id'];
    return !empty($r['id']) ? getPlan((int)$r['id']) : null;
};
$small = $mk('Small', 2, 1, 1, true, 10000);
$mid   = $mk('Mid', $users + 5, $shops + 3, 2, true, 20000);
$big   = $mk('Big', null, null, 3, true, 90000);
$priv  = $mk('Private', null, null, 4, false, 1);
if (!$small || !$mid || !$big || !$priv) { ok(false, 'plan fixtures created'); exit; }

$mkTenant = function (string $sub, string $status, int $days, string $planKey) use ($c) {
    $end = date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' day'));
    $c->prepare("INSERT INTO tenants (company_name, subdomain, db_name, db_username, db_password_encrypted, owner_email, status, plan,
                                      trial_ends_at, subscription_ends_at, created_at)
                 VALUES (?, ?, 'none', 'none', 'x', 'o@example.com', ?, ?, ?, ?, ?)")
      ->execute(["TSW $sub", $sub, $status, $planKey, $status === 'trial' ? "$end 23:59:59" : null, $status === 'active' ? $end : null,
                 date('Y-m-d H:i:s', strtotime('-3 day'))]);
    return (int)$c->lastInsertId();
};

function runPhp(string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'tsw_');
    file_put_contents($tmp, "<?php\n" . $code);
    $out = shell_exec('php ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    return trim((string)$out);
}
function api(int $tid, array $req, string $method = 'POST', string $lang = 'en'): array {
    global $root, $uid;
    $o = runPhp("\$_SERVER['REQUEST_METHOD'] = '$method'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
        \$GLOBALS['__bms_tenant'] = ['id' => $tid, 'subdomain' => 'tswtest'];
        \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['user_lang'] = '$lang';
        \$_POST = \$_GET = \$_REQUEST = " . var_export($req, true) . "; \$_POST['_csrf'] = \$_SESSION['csrf_token'] = 'tok';
        ob_start(); include '$root/api/my_subscription.php'; \$o = ob_get_clean(); \$p = strpos(\$o, '{'); echo \$p === false ? \$o : substr(\$o, \$p);");
    return json_decode($o, true) ?? ['success' => false, 'message' => $o];
}
$row = fn(int $tid) => $c->query("SELECT plan, max_shops, max_users, trial_ends_at FROM tenants WHERE id = $tid")->fetch(PDO::FETCH_ASSOC);

section('A. trial company sees the plans of its product');
$t = $mkTenant('tswtest1', 'trial', 7, $small['plan_key']);
$r = api($t, ['action' => 'trial_plans'], 'GET');
$keys = array_column($r['plans'] ?? [], 'plan_key');
ok(!empty($r['success']) && !empty($r['available']), 'trial_plans: available during the trial');
ok($keys === [$small['plan_key'], $mid['plan_key'], $big['plan_key']], 'public, priced plans of its own product only, smallest first (private hidden)');
ok(($r['current'] ?? '') === $small['plan_key'], 'current plan is marked');
ok(($r['usage']['shops'] ?? null) === $shops && ($r['usage']['users'] ?? null) === $users, 'usage: active users and shops now');
ok(substr((string)($r['trial_ends_at'] ?? ''), 0, 10) === date('Y-m-d', strtotime('+7 day')), 'trial end date returned');
ok((int)(($r['plans'][1]['price']['price_tzs']) ?? 0) === 20000, 'each plan carries its default price');

section('B. switching up applies at once, trial date unchanged');
$before = $row($t);
$r = api($t, ['action' => 'trial_switch', 'plan_key' => $mid['plan_key']]);
$after = $row($t);
ok(!empty($r['success']), 'switch to Mid succeeds' . (empty($r['success']) ? ' — ' . ($r['message'] ?? '') : ''));
ok($after['plan'] === $mid['plan_key'] && (int)$after['max_shops'] === $shops + 3 && (int)$after['max_users'] === $users + 5, 'tenant now has Mid\'s plan key, shop and user limits');
ok($after['trial_ends_at'] === $before['trial_ends_at'], 'trial end date did not move');
$log = $c->query("SELECT actor_email, detail FROM tenant_admin_log WHERE tenant_id = $t AND action = 'plan_trial_switch'")->fetch(PDO::FETCH_ASSOC);
ok($log && strpos($log['detail'], $small['plan_key'] . ' → ' . $mid['plan_key']) !== false && $log['actor_email'] === "tenant:$uid", 'the switch is in the tenant history, naming the company user');
ok(strpos((string)($r['message'] ?? ''), date('d M Y', strtotime('+7 day'))) !== false, 'message repeats the unchanged trial end date');

section('C. refusals');
$r = api($t, ['action' => 'trial_switch', 'plan_key' => $mid['plan_key']]);
ok(empty($r['success']) && stripos($r['message'] ?? '', 'already') !== false, 'same plan again is refused');
$r = api($t, ['action' => 'trial_switch', 'plan_key' => $small['plan_key']]);
ok(empty($r['success']) && strpos($r['message'] ?? '', "$shops active shops") !== false, "down to Small (1 shop) refused while $shops shops are active");
ok($row($t)['plan'] === $mid['plan_key'], 'plan stays Mid after the refused downgrade');
$r = api($t, ['action' => 'trial_switch', 'plan_key' => $priv['plan_key']]);
ok(empty($r['success']) && stripos($r['message'] ?? '', 'not available') !== false, 'a private plan cannot be picked');
$r = api($t, ['action' => 'trial_switch', 'plan_key' => 'no-such-plan']);
ok(empty($r['success']), 'an unknown plan is refused');
$r = api($t, ['action' => 'trial_switch', 'plan_key' => $big['plan_key']], 'GET');
ok(empty($r['success']) && $row($t)['plan'] === $mid['plan_key'], 'GET cannot switch');
$r = api($t, ['action' => 'trial_switch', 'plan_key' => $small['plan_key']], 'POST', 'sw');
ok(empty($r['success']) && strpos($r['message'] ?? '', 'maduka') !== false, 'refusal comes back in Swahili for a Swahili user');

section('D. paid or ended trial: BJP handles plan changes');
$paid = $mkTenant('tswtest2', 'active', 30, $small['plan_key']);
$r = api($paid, ['action' => 'trial_plans'], 'GET');
ok(!empty($r['success']) && empty($r['available']), 'paid company: trial_plans not available');
$r = api($paid, ['action' => 'trial_switch', 'plan_key' => $big['plan_key']]);
ok(empty($r['success']) && $row($paid)['plan'] === $small['plan_key'], 'paid company cannot switch itself');
$ended = $mkTenant('tswtest3', 'trial', -2, $small['plan_key']);
$r = api($ended, ['action' => 'trial_switch', 'plan_key' => $big['plan_key']]);
ok(empty($r['success']) && $row($ended)['plan'] === $small['plan_key'], 'a trial that has ended cannot switch');

section('E. page + words');
$html = runPhp("\$_SERVER['REQUEST_METHOD'] = 'GET'; \$_SERVER['HTTP_HOST'] = 'localhost'; require '$root/roots.php';
    \$GLOBALS['__bms_tenant'] = ['id' => $t];
    \$_SESSION['user_id'] = $uid; \$_SESSION['role_id'] = 1; \$_SESSION['is_admin'] = true; \$_SESSION['first_name'] = 'T'; \$_SESSION['last_name'] = 'A'; \$_SESSION['user_lang'] = 'sw';
    ob_start(); include '$root/app/constant/settings/billing.php'; echo ob_get_clean();");
ok(strpos($html, 'id="trialSwitch"') !== false && strpos($html, 'trial_switch') !== false && strpos($html, 'Fatal error') === false, 'Subscription page renders with the trial switch block');
ok(strpos($html, 'Badilisha mpango wakati wa majaribio ya bure') !== false && strpos($html, 'Hamia mpango huu') !== false, 'page words in Swahili');
$sw = include "$root/lang/sw.php";
$src = file_get_contents("$root/api/my_subscription.php") . file_get_contents("$root/app/constant/settings/billing.php");
preg_match_all("/t\\('((?:[^'\\\\]|\\\\.)*)'\\)/", $src, $m);
$missing = array_values(array_filter(array_unique($m[1]), fn($s) => preg_match('/\p{L}/u', $s) && !isset($sw[stripslashes($s)])));
ok(!$missing, 'every t() string on the page and in the API has a Swahili line' . ($missing ? ' — missing: ' . implode(' | ', array_slice($missing, 0, 8)) : ''));
