<?php
/**
 * tests/test_register_redesign_cli.php — register.php redesign (2026-10-23).
 *
 *   php tests/test_register_redesign_cli.php
 *
 * Proves, against real provisioning:
 *   1. the owner picks a login username; it becomes users.username, the phone
 *      stays in users.phone, and the free-text business type is saved
 *   2. a bad username is refused and creates nothing
 *   3. callers that send no username (mobile app) still log in by phone
 *   4. a plan from another product is refused
 *   5. a Smart BMS signup is held (suspended / awaiting_approval) and the
 *      superadmin bell gets a bms_request notification
 *   6. approve opens it with a fresh trial; approving twice is refused
 *   7. decline → the emailed link (bad token refused) turns the same company
 *      into a Smart POS trial, once
 *   8. register.php renders and its inline JS parses
 *
 * CLI ONLY. Creates throwaway tenants and one plan; restores the Smart BMS
 * product's flags and removes everything it made.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_registration.php";
require_once "$root/core/tenant_admin.php";
require_once "$root/core/plans.php";

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }
function withTenancy(callable $fn)
{
    putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.local');
    try { return $fn(); } finally { putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN'); }
}
function tenantPdo(array $t): PDO
{
    $s = controlDbSettings();
    return new PDO("mysql:host={$s['host']};dbname={$t['db_name']};charset=utf8mb4", $s['user'], $s['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$c   = getControlPdo();
$sfx = bin2hex(random_bytes(3));
$pw  = 'OwnerPass1';
$made = ['tenants' => [], 'plans' => []];
$bmsProd = $c->query("SELECT * FROM platform_products WHERE product_key = 'bms_full'")->fetch(PDO::FETCH_ASSOC) ?: null;

register_shutdown_function(function () use ($c, &$made, $bmsProd) {
    global $pass, $fail;
    try {
        $admin = getProvisioningPdo();
        foreach ($made['tenants'] as $tid) {
            $tid = (int)$tid;
            foreach (['tenant_features', 'tenant_admin_log', 'tenant_provisioning_log', 'superadmin_notifications'] as $t) {
                try { $c->exec("DELETE FROM $t WHERE tenant_id = $tid"); } catch (Throwable $e) {}
            }
            $c->exec("DELETE FROM tenants WHERE id = $tid");
            try { $admin->exec("DROP DATABASE IF EXISTS `bms_t$tid`"); } catch (Throwable $e) {}
            try { $admin->exec("DROP USER IF EXISTS 'bms_u$tid'@'%'"); } catch (Throwable $e) {}
        }
        $c->exec("DELETE FROM registration_attempts WHERE subdomain LIKE 'rrtest%' OR ip_address LIKE '198.51.100.24%'");
        foreach ($made['plans'] as $id) {
            $id = (int)$id;
            foreach (['plan_prices', 'plan_items', 'plan_features'] as $t) $c->exec("DELETE FROM $t WHERE plan_id = $id");
            $c->exec("DELETE FROM plans WHERE id = $id");
        }
        if ($bmsProd) {
            $c->prepare("UPDATE platform_products SET show_on_registration = ?, is_active = ? WHERE product_key = 'bms_full'")
              ->execute([$bmsProd['show_on_registration'], $bmsProd['is_active']]);
        }
    } catch (Throwable $e) { echo "  cleanup: " . $e->getMessage() . "\n"; }
    echo "\nPasses:   $pass\nFailures: $fail\n";
});

$signup = function (array $in, string $ip) use (&$made, $pw) {
    $r = withTenancy(fn() => registerTenant(array_merge([
        'owner_password' => $pw, 'owner_password_confirm' => $pw,
        'owner_phone' => '2557' . random_int(10000000, 99999999),
    ], $in), $ip));
    if (!empty($r['tenant_id'])) $made['tenants'][] = (int)$r['tenant_id'];
    return $r;
};

echo "\nBMS — register.php redesign: username, Smart BMS approval\n";

try {
    section('0. Schema');
    $sr = (string)$c->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'suspension_reason'")->fetchColumn();
    ok('suspension_reason knows awaiting_approval + approval_declined (run scripts/setup_control_db.php)',
       str_contains($sr, 'awaiting_approval') && str_contains($sr, 'approval_declined'), $sr);
    ok('the bms_full product exists', $bmsProd !== null);
    if (!$bmsProd || !str_contains($sr, 'awaiting_approval')) throw new RuntimeException('cannot continue');

    section('1. The owner chooses a login username');
    $sub = 'rrtestu' . $sfx;
    $phone = '2557' . random_int(10000000, 99999999);
    $r = $signup(['company_name' => 'Username Shop', 'subdomain' => $sub, 'owner_email' => "o@$sub.test",
                  'owner_phone' => $phone, 'owner_username' => 'jane.mwangi', 'owner_first_name' => 'Jane',
                  'owner_last_name' => 'Mwangi', 'business_type' => 'Hardware'], '198.51.100.241');
    ok('registration succeeded', $r['ok'] === true, (string)($r['error'] ?? ''));
    ok('the result names the username', ($r['username'] ?? '') === 'jane.mwangi');
    if ($r['ok']) {
        $t = getTenant((int)$r['tenant_id']);
        $tp = tenantPdo($t);
        $u = $tp->query("SELECT username, phone, password FROM users ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        ok('users.username is the chosen username', $u && $u['username'] === 'jane.mwangi', (string)($u['username'] ?? ''));
        ok('the phone is kept in users.phone', $u && $u['phone'] === $phone, (string)($u['phone'] ?? ''));
        ok('the password verifies', $u && password_verify($pw, $u['password']));
        $ct = $tp->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_type'")->fetchColumn();
        ok('the typed business type is saved as company_type', $ct === 'Hardware', var_export($ct, true));
    }

    section('2. A bad username is refused and creates nothing');
    $before = (int)$c->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    foreach (['ab', '1abc', 'jane mwangi', 'jane@x'] as $bad) {
        $r = $signup(['company_name' => 'Bad User Ltd', 'subdomain' => 'rrtestb' . $sfx, 'owner_email' => "b$sfx@x.test",
                      'owner_username' => $bad], '198.51.100.242');
        ok("refuses username '$bad'", $r['ok'] === false && str_contains((string)$r['error'], 'Username'), (string)$r['error']);
    }
    ok('no tenant was created', (int)$c->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === $before);

    section('3. No username sent (mobile app) -> the phone stays the username');
    $sub = 'rrtestp' . $sfx;
    $phone = '2556' . random_int(10000000, 99999999);
    $r = $signup(['company_name' => 'Phone Login Ltd', 'subdomain' => $sub, 'owner_email' => "p@$sub.test", 'owner_phone' => $phone], '198.51.100.243');
    ok('registration succeeded', $r['ok'] === true, (string)($r['error'] ?? ''));
    if ($r['ok']) {
        $u = tenantPdo(getTenant((int)$r['tenant_id']))->query("SELECT username FROM users ORDER BY user_id LIMIT 1")->fetchColumn();
        ok('users.username is the phone number', $u === $phone, (string)$u);
    }

    section('4. Fixture: Smart BMS on registration with one priced plan');
    $c->exec("UPDATE platform_products SET show_on_registration = 1, is_active = 1 WHERE product_key = 'bms_full'");
    $p = createPlan(['name' => "BMS rr $sfx", 'description' => 'x', 'max_users' => 5, 'max_storage_mb' => 1000,
                     'product_key' => 'bms_full', 'is_public' => true, 'is_popular' => true, 'tier_rank' => 10,
                     'feature_keys' => [], 'items' => [], 'addons' => [],
                     'prices' => [['duration_value' => 1, 'duration_unit' => 'month', 'price_tzs' => 50000, 'is_default' => 1]]]);
    ok('plan created', $p['ok'] === true, (string)($p['error'] ?? ''));
    if (!$p['ok']) throw new RuntimeException('cannot continue');
    $made['plans'][] = (int)$p['id'];
    $BMS = getPlan((int)$p['id'])['plan_key'];

    section('5. A plan from another product is refused');
    $before = (int)$c->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $r = $signup(['company_name' => 'Mismatch Ltd', 'subdomain' => 'rrtestm' . $sfx, 'owner_email' => "m$sfx@x.test",
                  'owner_username' => 'mismatch', 'product_key' => 'bms_shop', 'plan_key' => $BMS, 'plan_term' => '1m'], '198.51.100.244');
    ok('refused', $r['ok'] === false && str_contains((string)$r['error'], 'does not belong'), (string)$r['error']);
    ok('nothing created', (int)$c->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === $before);

    section('6. Smart BMS signup waits for approval');
    $sub = 'rrtesta' . $sfx;
    $r = $signup(['company_name' => 'Approve Me Ltd', 'subdomain' => $sub, 'owner_email' => "a@$sub.test",
                  'owner_username' => 'juma', 'product_key' => 'bms_full', 'plan_key' => $BMS, 'plan_term' => '1m'], '198.51.100.245');
    ok('registration succeeded', $r['ok'] === true, (string)($r['error'] ?? ''));
    ok('the result says it is pending approval', !empty($r['pending_approval']));
    if ($r['ok']) {
        $tid = (int)$r['tenant_id'];
        $t = getTenant($tid);
        ok('status suspended / awaiting_approval', $t['status'] === 'suspended' && $t['suspension_reason'] === 'awaiting_approval',
           $t['status'] . '/' . $t['suspension_reason']);
        ok('tenantApprovalState() = awaiting', tenantApprovalState($t) === 'awaiting');
        ok('the plan is the Smart BMS plan', $t['plan'] === $BMS, (string)$t['plan']);
        $n = (int)$c->query("SELECT COUNT(*) FROM superadmin_notifications WHERE tenant_id = $tid AND type = 'bms_request'")->fetchColumn();
        ok('the superadmin bell got a bms_request', $n === 1, (string)$n);

        $a = approveBmsTenant($tid);
        ok('approve succeeded', $a['ok'] === true, (string)($a['error'] ?? ''));
        $t = getTenant($tid);
        ok('now on its free trial with no suspension reason', $t['status'] === 'trial' && $t['suspension_reason'] === null);
        require_once "$root/core/tenant_lifecycle_policy.php";
        $days = (strtotime($t['trial_ends_at']) - time()) / 86400;
        ok('trial starts today', $days > tenantDefaultTrialDays() - 1 && $days <= tenantDefaultTrialDays() + 0.01, (string)$days);
        ok('approving twice is refused', approveBmsTenant($tid)['ok'] === false);
        ok('declining an approved company is refused', declineBmsTenant($tid)['ok'] === false);
    }

    section('7. Decline -> continue as Smart POS');
    $sub = 'rrtestd' . $sfx;
    $r = $signup(['company_name' => 'Decline Me Ltd', 'subdomain' => $sub, 'owner_email' => "d@$sub.test",
                  'owner_username' => 'asha', 'product_key' => 'bms_full', 'plan_key' => $BMS, 'plan_term' => '1m'], '198.51.100.246');
    ok('registration succeeded', $r['ok'] === true, (string)($r['error'] ?? ''));
    if ($r['ok']) {
        $tid = (int)$r['tenant_id'];
        $d = declineBmsTenant($tid, 'Not a fit yet');
        ok('decline succeeded', $d['ok'] === true, (string)($d['error'] ?? ''));
        $t = getTenant($tid);
        ok('state is declined and still locked', tenantApprovalState($t) === 'declined' && $t['status'] === 'suspended');
        ok('a wrong token is refused', continueDeclinedAsPos($tid, str_repeat('0', 64))['ok'] === false);
        $go = continueDeclinedAsPos($tid, bmsDeclineToken($tid, $t['subdomain']));
        ok('the right token switches to Smart POS', $go['ok'] === true, (string)($go['error'] ?? ''));
        ok('it returns the same username', ($go['username'] ?? '') === 'asha');
        $t = getTenant($tid);
        ok('now on its free trial', $t['status'] === 'trial' && $t['suspension_reason'] === null);
        $sm = tenantPdo($t)->query("SELECT setting_value FROM system_settings WHERE setting_key = 'pos_simple_mode'")->fetchColumn();
        ok('Smart POS defaults applied (Simple Mode on)', $sm === '1', var_export($sm, true));
        ok('the link works once only', continueDeclinedAsPos($tid, bmsDeclineToken($tid, $t['subdomain']))['ok'] === false);
    }

    section('8. register.php renders');
    $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_rr_render_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($probe, "<?php\n"
        . "ini_set('session.save_path', sys_get_temp_dir());\n"
        . "\$_SERVER['HTTP_HOST'] = 'bms.local'; \$_SERVER['REQUEST_METHOD'] = 'GET'; \$_GET = ['lang' => 'sw'];\n"
        . "chdir(" . var_export($root, true) . ");\n"
        . "require_once " . var_export("$root/includes/config.php", true) . ";\n"
        . "putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN=bms.local');\n"
        . "ob_start(); require " . var_export("$root/register.php", true) . "; echo ob_get_clean();\n");
    $html = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' 2>&1');
    @unlink($probe);
    ok('renders the Swahili page with the username field', str_contains($html, 'name="owner_username"') && str_contains($html, 'Fungua Akaunti'),
       substr(strip_tags($html), 0, 300));
    ok('Smart BMS is offered', str_contains($html, '"key":"bms_full"'));
    ok('no PHP warnings in the page', !preg_match('/(Warning|Notice|Fatal error|Deprecated):/', $html));
    if (preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#si', $html, $m)) {
        foreach ($m[1] as $i => $js) {
            $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_rr_js_' . bin2hex(random_bytes(4)) . '.js';
            file_put_contents($f, $js);
            exec('node --check ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
            @unlink($f);
            ok("inline script #$i parses", $rc === 0, implode(' ', array_slice($o, 0, 3)));
        }
    }
} catch (Throwable $e) {
    $fail++;
    echo "  FATAL " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
}
