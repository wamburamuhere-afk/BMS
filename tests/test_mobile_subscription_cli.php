<?php
/**
 * Mobile API upgrade — Phase 5: Subscription in the Flutter app — CLI regression
 *   php tests/test_mobile_subscription_cli.php
 *
 *   - api/mobile/subscription.php runs the web endpoint api/my_subscription.php
 *     for a Bearer caller (same admin-only gate, POSTs pass the CSRF check);
 *   - me.php → subscription: plan, trial | active | grace | no_end, ends_at,
 *     days_left, grace, warning (the web header banner's rule), can_manage —
 *     for every user;
 *   - mobile/notifications/list → "Subscription" group: trial / subscription
 *     ending in the last 7 days, then the grace period.
 *
 * Real HTTP with Bearer tokens on a throwaway company; the access dates are set
 * on its control-DB row to walk through each state.
 */

$root = dirname(__DIR__);
require_once "$root/tests/helpers/mobile_http.inc.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

section('1. Static');
foreach (['api/mobile/subscription.php', 'api/mobile/me.php', 'api/mobile/notifications/list.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}
check(str_contains(file_get_contents("$root/api/mobile/subscription.php"), "require __DIR__ . '/../my_subscription.php'"), 'the app endpoint runs the web endpoint (one copy of the logic)');

$m = mhStart('artsub');
if (!$m) { echo "  \033[33m⚠ multi-tenancy is off here — skipping live checks\033[0m\n"; exit; }
$c = getControlPdo();
$setRow = function (string $sql) use ($c, $m) { $c->prepare("UPDATE tenants SET $sql WHERE id = ?")->execute([$m['tid']]); };
$tok = mhLogin($m);
check((bool)$tok, 'owner signs in');
$me = fn(string $t) => mhCall($m, 'GET', '/api/mobile/me.php', [], $t)[1]['subscription'] ?? null;
$subGroup = function (string $t) use ($m) {
    [, $j] = mhCall($m, 'GET', '/api/mobile/notifications/list.php', [], $t);
    foreach ($j['groups'] ?? [] as $g) if ($g['key'] === 'subscription') return $g['items'];
    return [];
};

section('2. me.php → subscription');
$setRow("status = 'trial', trial_ends_at = CURDATE() + INTERVAL 3 DAY, subscription_ends_at = NULL, grace_until = NULL");
$s = $me($tok);
check(($s['status'] ?? '') === 'trial' && ($s['days_left'] ?? null) === 3 && ($s['warning'] ?? null) === true && ($s['can_manage'] ?? null) === true,
    'trial ending in 3 days → status trial, days_left 3, warning, can_manage (admin)');
$items = $subGroup($tok);
check(count($items) === 1 && $items[0]['type'] === 'trial_ending' && str_contains($items[0]['message'], 'in 3 days'), 'notifications: "Your free trial ends in 3 days."');

$setRow("status = 'active', trial_ends_at = NULL, subscription_ends_at = CURDATE() + INTERVAL 30 DAY, grace_until = NULL");
$s = $me($tok);
check(($s['status'] ?? '') === 'active' && ($s['days_left'] ?? null) === 30 && ($s['warning'] ?? null) === false, 'paid, 30 days left → active, no warning');
check($subGroup($tok) === [], 'notifications: nothing about the subscription');

$setRow("status = 'active', subscription_ends_at = CURDATE() - INTERVAL 2 DAY, grace_until = CURDATE() + INTERVAL 5 DAY");
$s = $me($tok);
check(($s['status'] ?? '') === 'grace' && ($s['grace_days_left'] ?? null) === 5 && ($s['warning'] ?? null) === true, 'ended 2 days ago → grace, 5 grace days left');
$items = $subGroup($tok);
check(count($items) === 1 && $items[0]['type'] === 'subscription_grace' && str_contains($items[0]['message'], 'grace period ends in 5 days'), 'notifications: grace period message');

$setRow("status = 'active', subscription_ends_at = NULL, grace_until = NULL");
check(($me($tok)['status'] ?? '') === 'no_end', 'no end date → no_end');

section('3. The Subscription page actions');
$setRow("status = 'trial', trial_ends_at = CURDATE() + INTERVAL 10 DAY");
[$code, $st] = mhCall($m, 'GET', '/api/mobile/subscription.php', ['action' => 'status'], $tok);
check($code === 200 && !empty($st['success']) && ($st['days_left'] ?? null) === 10 && ($st['period']['kind'] ?? '') === 'trial',
    "status: same data as the web page (days_left 10, period trial) (HTTP $code)");
check(array_key_exists('plan', $st ?? []) && array_key_exists('payments', $st ?? []), 'status carries plan and payments');

[$code, $j] = mhCall($m, 'POST', '/api/mobile/subscription.php', ['action' => 'request', 'product_id' => 0, 'term_id' => ''], $tok);
check($code === 422 && str_contains((string)($j['message'] ?? ''), 'subscription period'), "request without a period → 422 from the web rules — the POST passed CSRF (HTTP $code)");
[$code, $j] = mhCall($m, 'POST', '/api/mobile/subscription.php', ['action' => 'request', 'product_id' => 3, 'term_id' => 'p1', 'extras' => ['bad' => 1]], $tok, true);
check($code === 422 && str_contains((string)($j['message'] ?? ''), 'extras'), 'JSON body with bad extras → 422 (JSON bodies work)');

foreach (['catalog', 'documents', 'contracts', 'badge'] as $a) {
    [$code, $j] = mhCall($m, 'GET', '/api/mobile/subscription.php', ['action' => $a], $tok);
    check(is_array($j) && array_key_exists('success', $j) && in_array($code, [200, 502, 422], true),
        "$a: answers JSON like the web page (HTTP $code" . (empty($j['success']) ? ' — ' . ($j['message'] ?? '') : '') . ')');
}
[$code] = mhCall($m, 'GET', '/api/mobile/subscription.php', ['action' => 'nope'], $tok);
check($code === 422, 'unknown action → 422');

section('4. Staff');
$tp = mhTenantPdo($m);
$staffRole = (int)$tp->query("SELECT role_id FROM roles WHERE is_admin = 0 ORDER BY role_id LIMIT 1")->fetchColumn();
mhCall($m, 'POST', '/api/mobile/users/create.php', ['username' => 'staff1', 'first_name' => 'S', 'last_name' => 'One', 'email' => 'staff1@t.test',
    'role_id' => $staffRole, 'password' => 'Password1', 'confirm_password' => 'Password1'], $tok);
$stok = mhLogin($m, 'staff1');
$s = $me($stok);
check(($s['status'] ?? '') === 'trial' && ($s['can_manage'] ?? null) === false, 'staff see the summary, can_manage = false');
[$code] = mhCall($m, 'GET', '/api/mobile/subscription.php', ['action' => 'status'], $stok);
check($code === 403, "staff cannot open the Subscription page (403, got $code)");
[$code] = mhCall($m, 'GET', '/api/mobile/subscription.php', ['action' => 'status']);
check($code === 401, "no token → 401 (got $code)");
