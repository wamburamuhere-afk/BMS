<?php
/**
 * Multi-tenancy — Phase 4 (authentication rework) CLI test
 *   php tests/test_tenant_superadmin_auth_cli.php
 *
 * The security property under test is SEPARATION: a platform operator and a
 * tenant user are different identities, in different stores, on different hosts.
 *
 * What it proves:
 *   - superadmins authenticate ONLY against bms_control.superadmins
 *   - a tenant admin (is_admin = 1) is NOT a superadmin, and cannot become one
 *     by any session key a tenant login sets
 *   - failed logins lock the account after 5 attempts (§20)
 *   - failures are generic — no account enumeration
 *   - a superadmin session and a tenant session cannot coexist
 *   - the panel returns a flat 404 from a tenant's subdomain — it does not even
 *     show a login form there
 *   - deleting an operator invalidates their live session immediately
 *
 * Creates a throwaway operator and a throwaway tenant; removes both.
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);

// Sessions must work before anything is output, and this CLI php.ini points
// session.save_path at a directory that does not exist.
ini_set('session.save_path', sys_get_temp_dir());
session_start();

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/superadmin_auth.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

$made = ['superadmins' => [], 'tenants' => [], 'databases' => [], 'users' => []];
$probe = null;

function teardown(): void
{
    global $made, $probe;
    if ($probe && is_file($probe)) @unlink($probe);
    try {
        $cpdo = getControlPdo();
        foreach ($made['superadmins'] as $id) {
            $cpdo->prepare("DELETE FROM superadmins WHERE id = ?")->execute([$id]);
        }
        $cpdo->exec("DELETE FROM superadmins WHERE email LIKE 'satest%'");
        $cpdo->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'satest%'");
        $cpdo->exec("DELETE FROM tenants WHERE subdomain LIKE 'satest%'");
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

/** Run a snippet in a subprocess with a simulated host; returns its output. */
function runHost(string $file, string $host, array $env = []): string
{
    foreach ($env as $k => $v) { putenv("$k=$v"); }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($host);
    $out = (string)shell_exec($cmd . ' 2>&1');
    foreach ($env as $k => $v) { putenv($k); }
    return $out;
}

try {
    $cpdo = getControlPdo();
    $sfx  = bin2hex(random_bytes(3));

    section('1. Schema — lockout columns');
    $cols = $cpdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = " . $cpdo->quote(controlDbName()) . " AND table_name = 'superadmins'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['failed_attempts','locked_until','last_login'] as $c) {
        ok(in_array($c, $cols, true), "superadmins.$c exists");
    }

    section('2. Create a throwaway operator');
    $email = "satest$sfx@example.test";
    $pw    = 'OpsPass1!' . bin2hex(random_bytes(4));
    $cpdo->prepare("INSERT INTO superadmins (name, email, password_hash) VALUES (?,?,?)")
         ->execute(['SA Test', $email, password_hash($pw, PASSWORD_DEFAULT)]);
    $saId = (int)$cpdo->lastInsertId();
    $made['superadmins'][] = $saId;
    ok($saId > 0, 'operator created');

    section('3. Login');
    $_SESSION = [];
    ok(isSuperadminLoggedIn() === false, 'not signed in to begin with');

    $r = attemptSuperadminLogin($email, 'wrong-password');
    ok($r['ok'] === false, 'wrong password rejected');
    ok($r['error'] === 'Invalid email or password.', 'the failure message is generic');
    ok(isSuperadminLoggedIn() === false, 'no session opened on failure');

    $r2 = attemptSuperadminLogin('nobody' . $sfx . '@example.test', 'whatever');
    ok($r2['error'] === $r['error'],
        'an unknown email gives the SAME message as a wrong password (no enumeration)');

    $r = attemptSuperadminLogin($email, $pw);
    ok($r['ok'] === true, 'correct password accepted');
    ok(superadminId() === $saId, 'session carries the operator id');
    ok(isSuperadminLoggedIn() === true, 'isSuperadminLoggedIn() is true');
    $me = currentSuperadmin();
    ok(is_array($me) && $me['email'] === $email, 'currentSuperadmin() returns the right row');
    ok(!array_key_exists('password_hash', $me ?? []), 'currentSuperadmin() never exposes the password hash');

    $last = $cpdo->prepare("SELECT last_login, failed_attempts FROM superadmins WHERE id = ?");
    $last->execute([$saId]); $last = $last->fetch();
    ok(!empty($last['last_login']), 'last_login stamped');
    ok((int)$last['failed_attempts'] === 0, 'failed_attempts reset on success');

    section('4. Lockout after repeated failures (§20)');
    $_SESSION = [];
    $cpdo->prepare("UPDATE superadmins SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$saId]);
    $row = $cpdo->prepare("SELECT failed_attempts, locked_until FROM superadmins WHERE id = ?");

    // The lock must land on exactly the Nth failure — not earlier. Locking early
    // is a real denial-of-service against the operator, so check each step.
    for ($i = 1; $i < SUPERADMIN_MAX_ATTEMPTS; $i++) {
        attemptSuperadminLogin($email, 'nope');
        $row->execute([$saId]); $r4 = $row->fetch();
        ok((int)$r4['failed_attempts'] === $i, "failure $i counted (failed_attempts = $i)");
        ok(empty($r4['locked_until']), "still unlocked after $i of " . SUPERADMIN_MAX_ATTEMPTS . " failures");
    }

    attemptSuperadminLogin($email, 'nope');
    $row->execute([$saId]); $r4 = $row->fetch();
    ok((int)$r4['failed_attempts'] === SUPERADMIN_MAX_ATTEMPTS,
        'all ' . SUPERADMIN_MAX_ATTEMPTS . ' failures counted');
    ok(!empty($r4['locked_until']), 'account locked on exactly the ' . SUPERADMIN_MAX_ATTEMPTS . 'th failure');

    $r = attemptSuperadminLogin($email, $pw);
    ok($r['ok'] === false, 'the CORRECT password is refused while locked');
    ok(strpos((string)$r['error'], 'Try again in') !== false, 'the lockout message says how long to wait');

    $cpdo->prepare("UPDATE superadmins SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$saId]);
    ok(attemptSuperadminLogin($email, $pw)['ok'] === true, 'login works again once the lock is cleared');

    section('5. A tenant admin is NOT a platform operator');
    $_SESSION = [];
    // Exactly what actions/login.php sets for a tenant administrator.
    $_SESSION['user_id']   = 1;
    $_SESSION['role_id']   = 1;
    $_SESSION['is_admin']  = 1;
    $_SESSION['user_role'] = 'Admin';
    $_SESSION['tenant_id'] = 999;
    ok(isSuperadminLoggedIn() === false, 'a tenant admin session does not grant superadmin');
    ok(superadminId() === null, 'no operator id is inferred from tenant session keys');
    ok(currentSuperadmin() === null, 'currentSuperadmin() is null for a tenant admin');

    section('6. The two sessions cannot coexist');
    // Signing in as an operator must drop the tenant identity...
    attemptSuperadminLogin($email, $pw);
    ok(isSuperadminLoggedIn() === true, 'operator signed in');
    ok(!isset($_SESSION['user_id']), 'the tenant user identity was cleared');
    ok(!isset($_SESSION['role_id']), 'the tenant role was cleared');

    // ...and the tenant login clears the operator identity (asserted at source,
    // since running actions/login.php needs a full web request context).
    $loginSrc = file_get_contents("$root/actions/login.php");
    ok(strpos($loginSrc, "unset(\$_SESSION['superadmin_id'])") !== false,
        'actions/login.php clears superadmin_id on tenant login');
    ok(strpos($loginSrc, 'bmsCurrentTenantId') !== false,
        'actions/login.php pins the session to the resolved tenant');

    section('7. Signing out');
    superadminLogout();
    ok(isSuperadminLoggedIn() === false, 'logout ends the operator session');

    section('8. A deleted operator loses access immediately');
    attemptSuperadminLogin($email, $pw);
    ok(isSuperadminLoggedIn() === true, 'signed in again');
    $cpdo->prepare("DELETE FROM superadmins WHERE id = ?")->execute([$saId]);
    // currentSuperadmin() caches per-request; a fresh process is the honest test.
    $_SESSION['superadmin_id'] = $saId;
    $probeDel = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_sa_del_' . $sfx . '.php';
    file_put_contents($probeDel, "<?php\n"
        . "ini_set('session.save_path', sys_get_temp_dir());\n"
        . "session_start();\n"
        . "\$_SESSION['superadmin_id'] = " . $saId . ";\n"
        . "require '" . str_replace('\\', '/', $root) . "/core/superadmin_auth.php';\n"
        . "echo currentSuperadmin() === null ? 'REVOKED' : 'STILL-VALID';\n");
    $outDel = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeDel) . ' 2>&1');
    @unlink($probeDel);
    ok(strpos($outDel, 'REVOKED') !== false, 'a deleted operator is rejected on the next request');

    // Recreate for the remaining host tests.
    $cpdo->prepare("INSERT INTO superadmins (name, email, password_hash) VALUES (?,?,?)")
         ->execute(['SA Test', $email, password_hash($pw, PASSWORD_DEFAULT)]);
    $made['superadmins'][] = (int)$cpdo->lastInsertId();

    section('9. Host guard — the panel is invisible from a tenant subdomain');
    $t = provisionTenant('SA Host Ltd', 'satest' . $sfx, "owner@satest$sfx.test", 'Password!123');
    ok($t['ok'] === true, 'throwaway tenant provisioned');
    if (!$t['ok']) { throw new RuntimeException($t['error']); }
    $made['tenants'][]   = $t['tenant_id'];
    $made['databases'][] = $t['db_name'];
    $made['users'][]     = $t['db_username'];

    $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_sa_host_' . $sfx . '.php';
    file_put_contents($probe, "<?php\n"
        . "\$_SERVER['HTTP_HOST'] = \$argv[1] ?? '';\n"
        . "require '" . str_replace('\\', '/', $root) . "/core/superadmin_auth.php';\n"
        . "assertSuperadminHost();\n"
        . "echo 'REACHED-PANEL';\n");

    $env = ['TENANT_MODE' => 'on', 'TENANT_BASE_DOMAIN' => 'bms.local'];

    $out = runHost($probe, 'satest' . $sfx . '.bms.local', $env);
    ok(strpos($out, 'REACHED-PANEL') === false, "a TENANT's subdomain cannot reach the panel");
    ok(strpos($out, 'Not found') !== false, 'it returns a flat "Not found", not a login form');

    $out = runHost($probe, 'nosuchtenant.bms.local', $env);
    ok(strpos($out, 'REACHED-PANEL') === false, 'an unknown subdomain cannot reach the panel');

    $out = runHost($probe, 'bms.local', $env);
    ok(strpos($out, 'REACHED-PANEL') === false, 'the marketing root cannot reach the panel either');

    $out = runHost($probe, 'superadmin.bms.local', $env);
    ok(strpos($out, 'REACHED-PANEL') !== false, 'the superadmin host CAN reach the panel');

    // With multi-tenancy off there are no tenant hosts, so the panel stays usable.
    $out = runHost($probe, 'localhost', []);
    ok(strpos($out, 'REACHED-PANEL') !== false, 'with TENANT_MODE off the panel is reachable locally');

    section('10. The panel never opens a tenant database');
    foreach (['app/superadmin/index.php', 'app/superadmin/login.php', 'actions/superadmin_login.php'] as $f) {
        $src = file_get_contents("$root/$f");
        ok(strpos($src, 'getControlPdo') !== false || strpos($src, 'superadmin_auth') !== false,
            "$f goes through the control DB / superadmin auth only");
        ok(strpos($src, "require_once __DIR__ . '/../../roots.php'") === false
           && strpos($src, "require_once __DIR__ . '/../roots.php'") === false,
            "$f does not boot the tenant application stack");
    }

    section('11. A control-DB failure re-checking a session does not create a login loop (regression)');
    // Reproduces the real bug: a live session (superadmin_id set) whose next
    // currentSuperadmin() call cannot reach the control DB. Forced by pointing
    // CONTROL_DB_USER at an account that cannot authenticate — getControlPdo()
    // then throws inside the very try/catch this regression is about.
    $probeErr = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_sa_err_' . $sfx . '.php';
    file_put_contents($probeErr, "<?php\n"
        . "ini_set('session.save_path', sys_get_temp_dir());\n"
        . "session_start();\n"
        . "\$_SESSION['superadmin_id'] = 999999;\n"
        . "require '" . str_replace('\\', '/', $root) . "/core/superadmin_auth.php';\n"
        . "\$before = isSuperadminLoggedIn() ? 'LOGGED-IN' : 'LOGGED-OUT';\n"
        . "\$me = currentSuperadmin();\n"
        . "\$after = isSuperadminLoggedIn() ? 'LOGGED-IN' : 'LOGGED-OUT';\n"
        . "echo (\$me === null ? 'NULL' : 'ROW') . '|' . \$before . '|' . \$after;\n");

    $envBad = [
        'CONTROL_DB_USER' => 'bms_sa_bogus_' . $sfx,
        'CONTROL_DB_PASS' => 'x',
        'CONTROL_DB_HOST' => 'localhost',
    ];
    $out = runHost($probeErr, '', $envBad);
    @unlink($probeErr);

    ok(strpos($out, 'NULL|LOGGED-IN|') !== false,
        'a live session sees currentSuperadmin() fail (before the call it still looked logged in)');
    ok(strpos($out, '|LOGGED-OUT') !== false,
        'the SAME call that discovered the control-DB failure drops the session — '
        . 'previously it stayed set, which is what made login.php keep sending the '
        . 'visitor back to "/" while requireSuperadmin() kept bouncing them to /login '
        . '(ERR_TOO_MANY_REDIRECTS)');

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail === 0 ? 0 : 1);
