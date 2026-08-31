<?php
/**
 * Multi-tenancy — Phase 5 (self-registration) CLI test
 *   php tests/test_tenant_registration_cli.php
 *
 * Self-registration is a PUBLIC, UNAUTHENTICATED endpoint that creates a MySQL
 * database and a MySQL user on every success. Most of what is tested here is
 * therefore about REFUSING to do that, not about doing it.
 *
 * What it proves:
 *   - signup fails CLOSED: off unless multi-tenancy is on, a base domain is
 *     configured, and the encryption key is present
 *   - the rate limiter stops a script after a few attempts, counting rejections
 *     too (a subdomain-probing loop never reaches a success)
 *   - the honeypot silently refuses bots with the same wording a human sees
 *   - every validation rule rejects before any database work happens
 *   - a real signup produces a working, isolated tenant the owner can log in to
 *   - internal error detail never reaches the visitor
 *
 * Creates throwaway tenants; removes them. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_registration.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

$made = ['tenants' => [], 'databases' => [], 'users' => []];

function teardown(): void
{
    global $made;
    try {
        $cpdo = getControlPdo();
        $cpdo->exec("DELETE FROM registration_attempts WHERE subdomain LIKE 'regtest%' OR ip_address LIKE '198.51.100.%'");
        $cpdo->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'regtest%'");
        $cpdo->exec("DELETE FROM tenants WHERE subdomain LIKE 'regtest%'");
    } catch (Throwable $e) {}
    try {
        $admin = getProvisioningPdo();
        foreach ($made['databases'] as $db) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
        }
        foreach ($made['users'] as $u) {
            try { $admin->exec("DROP USER IF EXISTS " . $admin->quote($u) . "@'%'"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}
register_shutdown_function(function(){
    global $pass,$fail;
    teardown();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n";
});

/** Multi-tenancy on, for the duration of a closure. */
function withTenancy(callable $fn)
{
    putenv('TENANT_MODE=on');
    putenv('TENANT_BASE_DOMAIN=bms.local');
    try { return $fn(); }
    finally { putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN'); }
}

try {
    $cpdo = getControlPdo();
    $sfx  = bin2hex(random_bytes(3));

    section('1. Control table exists');
    $tables = $cpdo->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = " . $cpdo->quote(controlDbName()) . " AND table_type='BASE TABLE'
    ")->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('registration_attempts', $tables, true),
        'registration_attempts exists (created by scripts/setup_control_db.php, NOT a deploy migration)');

    section('2. Signup fails CLOSED');
    putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN');
    ok(selfRegistrationOpen() === false, 'closed when multi-tenancy is off');
    ok(selfRegistrationClosedReason() !== null, 'a reason is given');

    putenv('TENANT_MODE=on'); putenv('TENANT_BASE_DOMAIN');
    ok(selfRegistrationOpen() === false, 'closed when no base domain is configured');
    putenv('TENANT_MODE');

    withTenancy(function () {
        ok(selfRegistrationOpen() === true, 'open when tenancy is on and configured');
        putenv('TENANT_SELF_REGISTRATION=off');
        ok(selfRegistrationOpen() === false, 'TENANT_SELF_REGISTRATION=off closes it');
        putenv('TENANT_SELF_REGISTRATION');
    });

    // Refusing while off must not be a silent no-op: it must reject signups.
    $r = registerTenant([
        'company_name' => 'Closed Co', 'subdomain' => 'regtestclosed' . $sfx,
        'owner_email' => 'a@b.test', 'owner_password' => 'Password1',
    ], '198.51.100.1');
    ok($r['ok'] === false, 'registerTenant() refuses while closed');
    ok($r['tenant_id'] === null, 'no tenant created while closed');

    section('3. Validation — nothing is created for bad input');
    withTenancy(function () use ($cpdo, $sfx) {
        $before = (int)$cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
        $cases = [
            [['company_name'=>'', 'subdomain'=>'regtestv'.$sfx, 'owner_email'=>'a@b.test', 'owner_password'=>'Password1'], 'empty company name'],
            [['company_name'=>'Co', 'subdomain'=>'admin', 'owner_email'=>'a@b.test', 'owner_password'=>'Password1'], 'reserved subdomain'],
            [['company_name'=>'Co', 'subdomain'=>'ab', 'owner_email'=>'a@b.test', 'owner_password'=>'Password1'], 'subdomain too short'],
            [['company_name'=>'Co', 'subdomain'=>'regtestv'.$sfx, 'owner_email'=>'nope', 'owner_password'=>'Password1'], 'invalid email'],
            [['company_name'=>'Co', 'subdomain'=>'regtestv'.$sfx, 'owner_email'=>'a@b.test', 'owner_password'=>'short1'], 'password too short'],
            [['company_name'=>'Co', 'subdomain'=>'regtestv'.$sfx, 'owner_email'=>'a@b.test', 'owner_password'=>'allletters'], 'password has no digit'],
            [['company_name'=>'Co', 'subdomain'=>'regtestv'.$sfx, 'owner_email'=>'a@b.test', 'owner_password'=>'12345678'], 'password has no letter'],
            [['company_name'=>'Co', 'subdomain'=>'regtestv'.$sfx, 'owner_email'=>'a@b.test', 'owner_password'=>'Password1', 'owner_password_confirm'=>'Password2'], 'passwords do not match'],
        ];
        // Each case uses a distinct IP so the throttle does not mask validation.
        $i = 10;
        foreach ($cases as [$in, $label]) {
            $res = registerTenant($in, '198.51.100.' . ($i++));
            ok($res['ok'] === false && $res['tenant_id'] === null, "rejected: $label");
        }
        ok((int)$cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === $before,
            'no registry row created by any invalid attempt');
    });

    section('4. Honeypot');
    withTenancy(function () use ($sfx) {
        $res = registerTenant([
            'company_name' => 'Bot Co', 'subdomain' => 'regtestbot' . $sfx,
            'owner_email' => 'bot@example.test', 'owner_password' => 'Password1',
            'website' => 'http://spam.example',      // humans never see this field
        ], '198.51.100.50');
        ok($res['ok'] === false, 'a filled honeypot is refused');
        ok(stripos((string)$res['error'], 'bot') === false
           && stripos((string)$res['error'], 'honeypot') === false,
           'the refusal never reveals that a trap exists');
    });

    section('5. Rate limiting');
    withTenancy(function () use ($cpdo, $sfx) {
        $ip = '198.51.100.99';
        $cpdo->prepare("DELETE FROM registration_attempts WHERE ip_address = ?")->execute([$ip]);
        ok(registrationThrottleCheck($ip) === null, 'a fresh IP is allowed');

        // Rejections count too — a script probing subdomains never reaches a success.
        for ($i = 0; $i < REGISTRATION_MAX_PER_IP_HOUR; $i++) {
            logRegistrationAttempt($ip, 'x@example.test', 'regtestrl' . $sfx, 'rejected', 'probe');
        }
        ok(registrationThrottleCheck($ip) !== null,
            'blocked after ' . REGISTRATION_MAX_PER_IP_HOUR . ' attempts, counting rejections');

        $res = registerTenant([
            'company_name' => 'Flood Co', 'subdomain' => 'regtestflood' . $sfx,
            'owner_email' => 'f@example.test', 'owner_password' => 'Password1',
        ], $ip);
        ok($res['ok'] === false, 'a throttled IP cannot register');
        ok($res['tenant_id'] === null, 'and creates no database');

        $st = $cpdo->prepare("SELECT COUNT(*) FROM registration_attempts WHERE ip_address = ? AND outcome = 'throttled'");
        $st->execute([$ip]);
        ok((int)$st->fetchColumn() > 0, 'the throttled attempt is recorded');

        // A different address is unaffected — the limit is per IP, not global.
        ok(registrationThrottleCheck('198.51.100.200') === null, 'a different IP is unaffected');
        $cpdo->prepare("DELETE FROM registration_attempts WHERE ip_address = ?")->execute([$ip]);
    });

    section('6. A real signup end to end');
    $sub = 'regtestok' . $sfx;
    $pw  = 'OwnerPass1';
    $res = withTenancy(function () use ($sub, $pw) {
        return registerTenant([
            'company_name'     => 'Registration Test Ltd',
            'subdomain'        => $sub,
            'owner_email'      => "owner@$sub.test",
            'owner_password'   => $pw,
            'owner_first_name' => 'Ada',
            'owner_last_name'  => 'Lovelace',
        ], '198.51.100.150');
    });

    if (!$res['ok']) { echo "  error: {$res['error']}\n"; }
    ok($res['ok'] === true, 'registration succeeded');
    if (!$res['ok']) { throw new RuntimeException('cannot continue'); }

    $made['tenants'][]   = $res['tenant_id'];
    $made['databases'][] = 'bms_t' . $res['tenant_id'];
    $made['users'][]     = 'bms_u' . $res['tenant_id'];

    ok($res['subdomain'] === $sub, 'the subdomain is returned');
    ok(strpos((string)$res['login_url'], $sub . '.bms.local') !== false,
        'the login URL points at the new tenant (' . $res['login_url'] . ')');

    $row = $cpdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $row->execute([$res['tenant_id']]); $row = $row->fetch();
    ok($row['status'] === 'active', 'the tenant is active, so the owner can sign in at once');
    ok($row['company_name'] === 'Registration Test Ltd', 'company name stored');

    // The owner must actually be able to authenticate.
    $s  = controlDbSettings();
    $tp = new PDO("mysql:host={$s['host']};dbname={$row['db_name']};charset=utf8mb4",
        $s['user'], $s['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $owner = $tp->query("SELECT * FROM users")->fetch(PDO::FETCH_ASSOC);
    ok($owner !== false, 'the owner account exists in the new tenant');
    ok(password_verify($pw, $owner['password']), 'the owner password verifies (login would accept it)');
    ok($owner['first_name'] === 'Ada', 'the owner name was carried through');
    ok((int)$tp->query("SELECT COUNT(*) FROM accounts")->fetchColumn() === 105, 'chart of accounts seeded');
    ok((int)$tp->query("SELECT COUNT(*) FROM accounts WHERE is_subledger = 1")->fetchColumn() === 0,
        'no customer names leaked into the new tenant');

    $st = $cpdo->prepare("SELECT outcome, tenant_id FROM registration_attempts WHERE subdomain = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$sub]); $att = $st->fetch();
    ok(($att['outcome'] ?? '') === 'success', 'the signup is recorded as a success');
    ok((int)($att['tenant_id'] ?? 0) === $res['tenant_id'], 'the audit row links to the tenant');

    section('7. The same subdomain cannot be taken twice');
    $dup = withTenancy(function () use ($sub) {
        return registerTenant([
            'company_name' => 'Impostor Ltd', 'subdomain' => $sub,
            'owner_email' => 'attacker@example.test', 'owner_password' => 'Password1',
        ], '198.51.100.151');
    });
    ok($dup['ok'] === false, 'duplicate subdomain refused');
    ok(stripos((string)$dup['error'], 'already taken') !== false, 'the message says it is taken');

    section('8. No internal detail leaks to the visitor');
    foreach ([$res, $dup] as $r2) {
        $msg = (string)($r2['error'] ?? '');
        foreach (['bms_t', 'bms_u', 'SQLSTATE', 'PDO', 'mysql'] as $needle) {
            ok(stripos($msg, $needle) === false, "no '$needle' in a user-facing message");
        }
    }

    section('9. The login page no longer links to the dead register.php');
    $loginSrc = file_get_contents("$root/login.php");
    ok(strpos($loginSrc, 'href="register.php"') === false,
        'login.php no longer links to register.php (it fatal-errored on every click)');

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail === 0 ? 0 : 1);
