<?php
/**
 * Mobile API upgrade — Phase 4: plans at signup + plan limits — CLI regression
 *   php tests/test_mobile_signup_plans_cli.php
 *
 * The app's signup now matches the web signup (registerTenant()):
 *   - plan_key / plan_term from GET api/platform/public_offers.php; the chosen
 *     plan comes back in register_status;
 *   - optional owner_username (else the phone, as before); phone formatting
 *     (spaces, dashes, dots, brackets) ignored, 7–15 digits;
 *   - a Smart BMS plan is locked until a superadmin approves it (it used to go
 *     live straight away from the app): no token, pending_approval = true, the
 *     superadmin bell rings;
 *   - superadmins are told about every app signup.
 * me.php returns limits.shops / limits.users {used, max}; shop create/update
 * refuse past the plan's limit with code shop_limit_reached.
 *
 * Real HTTP on a PHP built-in server (root domain for signup, the company's
 * address afterwards). Skipped when platform email is configured, so a test
 * can never send real mail.
 */

$root = dirname(__DIR__);
require_once "$root/tests/helpers/mobile_http.inc.php";
require_once "$root/core/tenant_registration.php";
require_once "$root/core/platform_settings.php";

$pass = 0; $fail = 0;
function pass(string $m): void  { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void  { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function check(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }

$c = getControlPdo();
$subs = []; $server = null; $srvPid = 0;
register_shutdown_function(function () use (&$subs, &$server, &$srvPid, $c) {
    if ($server) { @proc_terminate($server); if ($srvPid && PHP_OS_FAMILY === 'Windows') @exec("taskkill /PID $srvPid /F /T 2>NUL"); }
    foreach ($subs as $sub) {
        $st = $c->prepare("SELECT id FROM tenants WHERE subdomain = ?"); $st->execute([$sub]);
        if ($tid = (int)$st->fetchColumn()) mhStop(['tid' => $tid, 'srv' => null]);
        $c->prepare("DELETE FROM registration_jobs WHERE subdomain = ?")->execute([$sub]);
        $c->prepare("DELETE FROM registration_attempts WHERE subdomain = ?")->execute([$sub]);
    }
    try { $c->exec("DELETE FROM registration_attempts WHERE ip_address = '127.0.0.1' AND created_at > NOW() - INTERVAL 1 HOUR AND subdomain IS NULL"); } catch (Throwable $e) {}
    global $pass, $fail;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

section('1. Static');
foreach (['api/mobile/register.php', 'api/mobile/register_status.php', 'api/mobile/me.php', 'cron/process_registration_jobs.php', 'scripts/setup_control_db.php'] as $f) {
    $o = []; $rc = 0; exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    check($rc === 0, "lint $f");
}
check(controlColumnExists('registration_jobs', 'owner_username'), 'registration_jobs.owner_username exists (run scripts/setup_control_db.php)');
$worker = file_get_contents("$root/cron/process_registration_jobs.php");
check(str_contains($worker, 'holdTenantForApproval($tenantId)') && str_contains($worker, 'notifySuperadminsOfNewRegistration('), 'worker holds Smart BMS for approval and tells superadmins');

$base = tenantBaseDomain();
if (!tenantModeEnabled() || !$base || !selfRegistrationOpen()) { echo "  \033[33m⚠ signup unavailable here — skipping live checks\033[0m\n"; exit; }
if (platformMailerOpts()['configured']) { echo "  \033[33m⚠ platform email is configured — skipping live signup (tests never send mail)\033[0m\n"; exit; }

$port = 18000 + random_int(2000, 2999);
$server = proc_open([PHP_BINARY, '-d', 'max_execution_time=0', '-S', "127.0.0.1:$port", '-t', $root],
    [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
$srvPid = (int)(proc_get_status($server)['pid'] ?? 0);
usleep(800000);
$rootM = ['host' => $base, 'port' => $port];
$call = fn(string $method, string $path, array $data = [], ?string $tok = null, ?string $host = null)
    => mhCall(['host' => $host ?? $base, 'port' => $port], $method, $path, $data, $tok);
$poll = function (string $jobId) use ($call): ?array {
    for ($i = 0; $i < 60; $i++) {
        [$code, $j] = $call('GET', '/api/mobile/register_status.php', ['job_id' => $jobId]);
        if (($j['status'] ?? '') !== 'provisioning') return $j;
        sleep(3);
    }
    return null;
};

section('2. Plans for the signup screen');
[$code, $offers] = $call('GET', '/api/platform/public_offers.php');
$posPlan = $bmsPlan = null;
foreach ($offers['products'] ?? [] as $p) foreach ($p['plans'] as $pl) {
    if ($p['product_key'] === 'bms_full') $bmsPlan = $bmsPlan ?? [$pl['plan_key'], $pl['prices'][0]['term_key']];
    else $posPlan = $posPlan ?? [$pl['plan_key'], $pl['prices'][0]['term_key']];
}
check($code === 200 && $posPlan !== null, "public_offers lists plans with terms (HTTP $code)");

section('3. Refusals, before anything is built');
$baseBody = ['company_name' => 'App Signup Co', 'owner_password' => 'Wambura@123', 'owner_password_confirm' => 'Wambura@123', 'owner_email' => ''];
[$c1] = $call('POST', '/api/mobile/register.php', $baseBody + ['owner_phone' => '07-12']);
[$c2] = $call('POST', '/api/mobile/register.php', $baseBody + ['owner_phone' => '0712345678', 'owner_username' => '9bad name']);
[$c3] = $call('POST', '/api/mobile/register.php', $baseBody + ['owner_phone' => '0712345678', 'plan_key' => 'no-such-plan']);
check($c1 === 422 && $c2 === 422 && $c3 === 422, "short phone / bad username / unknown plan → 422 ($c1 / $c2 / $c3)");

section('4. Smart POS signup with a plan and a username');
$sub = 'artsu' . substr(bin2hex(random_bytes(3)), 0, 5); $subs[] = $sub;
$phone = '0' . random_int(700000000, 799999999);
[$code, $q] = $call('POST', '/api/mobile/register.php', $baseBody + [
    'subdomain' => $sub, 'owner_phone' => substr($phone, 0, 4) . '-' . substr($phone, 4, 3) . ' ' . substr($phone, 7),
    'owner_username' => 'shopowner', 'owner_email' => "owner@$sub.test", 'plan_key' => $posPlan[0], 'plan_term' => $posPlan[1],
]);
check($code === 202 && ($q['username'] ?? '') === 'shopowner' && ($q['pending_approval'] ?? null) === false,
    "queued (202), username shopowner, not pending approval (HTTP $code)" . ($code !== 202 ? ' — ' . ($q['message'] ?? '') : ''));
$ready = !empty($q['job_id']) ? $poll($q['job_id']) : null;
check(($ready['status'] ?? '') === 'ready' && !empty($ready['token']), 'register_status → ready with a token');
check(($ready['plan']['plan_key'] ?? '') === $posPlan[0] && ($ready['plan']['term_key'] ?? '') === $posPlan[1], 'ready payload names the chosen plan');
check(($ready['username'] ?? '') === 'shopowner' && ($ready['pending_approval'] ?? null) === false, 'username shopowner, not pending');
$st = $c->prepare("SELECT * FROM tenants WHERE subdomain = ?"); $st->execute([$sub]); $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];
check(($t['owner_phone'] ?? '') === $phone, "phone stored without formatting ($phone)");
check(($t['plan'] ?? '') === $posPlan[0] && ($t['status'] ?? '') === 'active', 'company is active on that plan');

$host = "$sub.$base";
[$code, $login] = $call('POST', '/api/mobile/login.php', ['username' => 'shopowner', 'password' => 'Wambura@123', 'device_name' => 'T'], null, $host);
$tok = $login['token'] ?? null;
check($code === 200 && $tok, 'owner signs in with the chosen username');

section('5. Plan limits');
$c->prepare("UPDATE tenants SET max_shops = 1, max_users = 3 WHERE id = ?")->execute([(int)$t['id']]);
[, $me] = $call('GET', '/api/mobile/me.php', [], $tok, $host);
check(($me['limits']['shops']['max'] ?? null) === 1 && ($me['limits']['shops']['used'] ?? null) === 0, 'me.php: limits.shops = {used 0, max 1}');
check(($me['limits']['users']['max'] ?? null) === 3 && ($me['limits']['users']['used'] ?? 0) >= 1, 'me.php: limits.users = {used ≥1, max 3}');
[$c1, $w1] = $call('POST', '/api/mobile/warehouses/create.php', ['warehouse_name' => 'Shop One'], $tok, $host);
[$c2, $w2] = $call('POST', '/api/mobile/warehouses/create.php', ['warehouse_name' => 'Shop Two'], $tok, $host);
check($c1 === 200 && $c2 === 422 && ($w2['code'] ?? '') === 'shop_limit_reached', "second shop refused: 422 shop_limit_reached ($c1 / $c2)");
[, $me] = $call('GET', '/api/mobile/me.php', [], $tok, $host);
check(($me['limits']['shops']['used'] ?? null) === 1, 'me.php now shows 1 of 1 shops used');

section('6. Smart BMS signup waits for approval');
if (!$bmsPlan) { echo "  \033[33m⚠ no Smart BMS plan in the catalogue here — skipped\033[0m\n"; exit; }
$sub2 = 'artsb' . substr(bin2hex(random_bytes(3)), 0, 5); $subs[] = $sub2;
[$code, $q2] = $call('POST', '/api/mobile/register.php', $baseBody + [
    'subdomain' => $sub2, 'owner_phone' => '0' . random_int(600000000, 699999999), 'owner_email' => "owner@$sub2.test",
    'plan_key' => $bmsPlan[0], 'plan_term' => $bmsPlan[1],
]);
check($code === 202 && ($q2['pending_approval'] ?? null) === true, 'queued, pending_approval = true');
$r2 = !empty($q2['job_id']) ? $poll($q2['job_id']) : null;
check(($r2['status'] ?? '') === 'ready' && empty($r2['token']) && ($r2['pending_approval'] ?? null) === true, 'ready, no token, pending approval');
$st->execute([$sub2]); $t2 = $st->fetch(PDO::FETCH_ASSOC) ?: [];
check(($t2['status'] ?? '') === 'suspended' && ($t2['suspension_reason'] ?? '') === 'awaiting_approval', 'company locked: awaiting_approval (was live straight away before)');
$b = $c->prepare("SELECT COUNT(*) FROM superadmin_notifications WHERE tenant_id = ? AND type = 'bms_request'"); $b->execute([(int)($t2['id'] ?? 0)]);
check((int)$b->fetchColumn() === 1, 'superadmin bell: Smart BMS request awaiting approval');
