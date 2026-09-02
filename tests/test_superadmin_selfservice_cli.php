<?php
/**
 * Superadmin self-service — CLI test
 *   php tests/test_superadmin_selfservice_cli.php
 *
 * Covers the two capabilities a platform operator did not previously have:
 *
 *   A. changing their OWN credentials (name, email, password) from inside the
 *      panel — before this, the only route was scripts/create_superadmin.php or
 *      raw SQL
 *   B. registering a company FROM the panel, rather than only through the
 *      public self-registration form
 *
 * The interesting assertions are the refusals. A credential-change screen that
 * accepts a change without proving possession of the current password is an
 * account-takeover primitive, and an operator-provisioning path that skipped
 * validation would create subtly broken tenants that the public path never
 * would.
 *
 * Creates a throwaway superadmin and a throwaway tenant; removes both.
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir());
session_start();

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_admin.php";
require_once "$root/core/tenant_registration.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

$made = ['databases' => [], 'users' => []];

function teardown(): void
{
    global $made;
    try {
        $c = getControlPdo();
        $c->exec("DELETE FROM tenant_admin_log        WHERE subdomain LIKE 'satest%'");
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'satest%'");
        $c->exec("DELETE FROM tenants                 WHERE subdomain LIKE 'satest%'");
        $c->exec("DELETE FROM superadmins             WHERE email LIKE 'satest%'");
    } catch (Throwable $e) {}
    try {
        $a = getProvisioningPdo();
        foreach ($made['databases'] as $db) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $a->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
        }
        foreach ($made['users'] as $u) {
            try { $a->exec("DROP USER IF EXISTS " . $a->quote($u) . "@'%'"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}
register_shutdown_function(function(){
    global $pass,$fail;
    teardown();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n";
});

/** Read a superadmin row straight from the control DB. */
function saRow(int $id): ?array
{
    $st = getControlPdo()->prepare("SELECT * FROM superadmins WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

try {
    $cpdo = getControlPdo();
    $sfx  = bin2hex(random_bytes(3));

    // ────────────────────────────────────────────────────────────────────────
    section('1. The password rule is one rule, shared with tenant signup');
    ok(superadminPasswordError('short1', 'short1') !== null, 'under 8 characters is refused');
    ok(superadminPasswordError('alllettersx', 'alllettersx') !== null, 'no digit is refused');
    ok(superadminPasswordError('12345678', '12345678') !== null, 'no letter is refused');
    ok(superadminPasswordError('Password1', 'Password2') !== null, 'mismatched confirmation is refused');
    ok(superadminPasswordError('Password1', 'Password1') === null, '8+ chars with a letter and a digit is accepted');

    // ────────────────────────────────────────────────────────────────────────
    section('2. A throwaway operator to act as');
    $email = "satest$sfx@example.test";
    $pw    = 'Password1';
    $cpdo->prepare("INSERT INTO superadmins (name,email,password_hash) VALUES (?,?,?)")
         ->execute(['SA Probe', $email, password_hash($pw, PASSWORD_DEFAULT)]);
    $saId  = (int)$cpdo->lastInsertId();
    $_SESSION['superadmin_id'] = $saId;      // so actions are attributed
    ok(currentSuperadmin() !== null, 'operator session established');

    // ────────────────────────────────────────────────────────────────────────
    section('3. Profile changes require the current password');
    $r = updateSuperadminProfile($saId, 'New Name', "satest-new$sfx@example.test", 'the-wrong-password');
    ok($r['ok'] === false, 'a wrong current password is refused');
    ok(saRow($saId)['name'] === 'SA Probe', 'and nothing was written');

    $r = updateSuperadminProfile($saId, 'A', "satest-new$sfx@example.test", $pw);
    ok($r['ok'] === false, 'a one-character name is refused');
    $r = updateSuperadminProfile($saId, 'New Name', 'not-an-email', $pw);
    ok($r['ok'] === false, 'an invalid email is refused');

    $newEmail = "satest-new$sfx@example.test";
    $r = updateSuperadminProfile($saId, 'Renamed Operator', $newEmail, $pw);
    ok($r['ok'] === true, 'a valid change with the correct password succeeds');
    $row = saRow($saId);
    ok($row['name'] === 'Renamed Operator', 'the name is updated');
    ok($row['email'] === $newEmail, 'the email is updated');
    ok(password_verify($pw, (string)$row['password_hash']), 'the password is untouched by a profile change');

    // Email is a login credential — the operator must be able to sign in with it.
    $login = attemptSuperadminLogin($newEmail, $pw);
    ok($login['ok'] === true, 'the operator can sign in with the NEW email');

    section('4. An email already in use is refused');
    $otherEmail = "satest-other$sfx@example.test";
    $cpdo->prepare("INSERT INTO superadmins (name,email,password_hash) VALUES (?,?,?)")
         ->execute(['Other Op', $otherEmail, password_hash('Password1', PASSWORD_DEFAULT)]);
    $r = updateSuperadminProfile($saId, 'Renamed Operator', $otherEmail, $pw);
    ok($r['ok'] === false, "taking another operator's email is refused");
    ok(saRow($saId)['email'] === $newEmail, 'and the address is unchanged');

    // ────────────────────────────────────────────────────────────────────────
    section('5. Password change');
    $newPw = 'Brandnew9';

    $r = changeSuperadminPassword($saId, 'wrong-current', $newPw, $newPw);
    ok($r['ok'] === false, 'a wrong current password is refused');
    ok(password_verify($pw, (string)saRow($saId)['password_hash']), 'and the password is unchanged');

    $r = changeSuperadminPassword($saId, $pw, 'weak', 'weak');
    ok($r['ok'] === false, 'a weak new password is refused');
    $r = changeSuperadminPassword($saId, $pw, $newPw, 'Different9');
    ok($r['ok'] === false, 'a mismatched confirmation is refused');
    $r = changeSuperadminPassword($saId, $pw, $pw, $pw);
    ok($r['ok'] === false, 'reusing the current password is refused');

    // A stale lockout must not survive a legitimate password change.
    $cpdo->prepare("UPDATE superadmins SET failed_attempts = 4, locked_until = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?")
         ->execute([$saId]);

    $r = changeSuperadminPassword($saId, $pw, $newPw, $newPw);
    ok($r['ok'] === true, 'a valid change succeeds');

    $row = saRow($saId);
    ok(password_verify($newPw, (string)$row['password_hash']), 'the new password is stored');
    ok(!password_verify($pw, (string)$row['password_hash']), 'the old password no longer verifies');
    ok((int)$row['failed_attempts'] === 0, 'the failed-attempt counter is cleared');
    ok($row['locked_until'] === null, 'a stale lockout is lifted (the owner proved possession)');
    ok(strpos((string)$row['password_hash'], $newPw) === false, 'the password is hashed, never stored in the clear');

    ok(attemptSuperadminLogin($newEmail, $newPw)['ok'] === true, 'sign-in works with the new password');
    ok(attemptSuperadminLogin($newEmail, $pw)['ok'] === false, 'sign-in with the OLD password is refused');

    // ────────────────────────────────────────────────────────────────────────
    section('6. Creating a company from the panel — validation is NOT relaxed');
    $base = ['owner_email' => "owner$sfx@example.test", 'owner_password' => 'Password1',
             'owner_password_confirm' => 'Password1', 'status' => 'active'];

    $cases = [
        [['company_name' => '',        'subdomain' => "satest$sfx"], 'an empty company name'],
        [['company_name' => 'Co Ltd',  'subdomain' => 'admin'],      'a reserved subdomain'],
        [['company_name' => 'Co Ltd',  'subdomain' => 'ab'],         'a too-short subdomain'],
        [['company_name' => 'Co Ltd',  'subdomain' => 'Bad_Sub'],    'an invalid subdomain format'],
    ];
    foreach ($cases as [$over, $label]) {
        $r = createTenantAsOperator(array_merge($base, $over));
        ok($r['ok'] === false && $r['tenant_id'] === null, "refused: $label");
    }

    $r = createTenantAsOperator(array_merge($base, ['company_name' => 'Co Ltd', 'subdomain' => "satest$sfx", 'owner_email' => 'nope']));
    ok($r['ok'] === false, 'refused: an invalid owner email');
    $r = createTenantAsOperator(array_merge($base, ['company_name' => 'Co Ltd', 'subdomain' => "satest$sfx", 'owner_password' => 'weak', 'owner_password_confirm' => 'weak']));
    ok($r['ok'] === false, 'refused: a weak owner password');
    $r = createTenantAsOperator(array_merge($base, ['company_name' => 'Co Ltd', 'subdomain' => "satest$sfx", 'owner_password_confirm' => 'Different9']));
    ok($r['ok'] === false, 'refused: a mismatched password confirmation');
    $r = createTenantAsOperator(array_merge($base, ['company_name' => 'Co Ltd', 'subdomain' => "satest$sfx", 'status' => 'banana']));
    ok($r['ok'] === false, 'refused: an unknown status');

    ok((int)$cpdo->query("SELECT COUNT(*) FROM tenants WHERE subdomain LIKE 'satest%'")->fetchColumn() === 0,
        'no registry row was created by any invalid attempt');

    // ────────────────────────────────────────────────────────────────────────
    section('7. Creating a company from the panel — the real thing');
    // The differentiator: an operator must be able to onboard a customer even
    // when PUBLIC signup is switched off. That is exactly when this page matters.
    putenv('TENANT_SELF_REGISTRATION=off');
    ok(selfRegistrationOpen() === false, 'public self-registration is OFF for this check');

    $sub = 'satest' . $sfx;
    $r = createTenantAsOperator(array_merge($base, ['company_name' => 'Operator Made Ltd', 'subdomain' => $sub,
                                                    'owner_first_name' => 'Ops', 'owner_last_name' => 'Owner']));
    putenv('TENANT_SELF_REGISTRATION');

    ok($r['ok'] === true, 'the operator can still create a company' . ($r['ok'] ? '' : ': ' . $r['error']));
    if (!$r['ok']) throw new RuntimeException((string)$r['error']);

    $st = $cpdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $st->execute([$r['tenant_id']]);
    $T = $st->fetch();
    $made['databases'][] = $T['db_name'];
    $made['users'][]     = $T['db_username'];

    ok($T['status'] === 'active', 'the tenant is created with the requested status');
    ok($T['company_name'] === 'Operator Made Ltd', 'the company name is stored');
    ok(strpos((string)$r['login_url'], $sub) !== false, 'a sign-in URL for the new tenant is returned');

    // Indistinguishable from a self-registered tenant.
    require_once "$root/core/tenant_crypto.php";
    $tpdo = new PDO("mysql:host={$T['db_host']};dbname={$T['db_name']};charset=utf8mb4",
        $T['db_username'], decryptTenantSecret((string)$T['db_password_encrypted']),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    ok((int)$tpdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn() > 0, 'its chart of accounts is seeded');
    $owner = $tpdo->query('SELECT * FROM users ORDER BY user_id LIMIT 1')->fetch();
    ok($owner !== false, 'an owner account exists');
    ok(password_verify('Password1', (string)$owner['password']), 'the owner can actually sign in');

    section('8. The action is attributed');
    $log = $cpdo->prepare("SELECT * FROM tenant_admin_log WHERE subdomain = ? AND action = 'create'");
    $log->execute([$sub]);
    $entry = $log->fetch();
    ok($entry !== false, 'a create action is recorded in tenant_admin_log');
    ok(($entry['actor_email'] ?? '') === $newEmail, 'attributed to the operator who did it');
    ok((int)($entry['tenant_id'] ?? 0) === (int)$r['tenant_id'], 'linked to the tenant it created');

    section('9. A duplicate subdomain is refused after the fact');
    $dup = createTenantAsOperator(array_merge($base, ['company_name' => 'Copycat Ltd', 'subdomain' => $sub]));
    ok($dup['ok'] === false, 'the same subdomain cannot be taken twice');
    ok((int)$cpdo->query("SELECT COUNT(*) FROM tenants WHERE subdomain = " . $cpdo->quote($sub))->fetchColumn() === 1,
        'and only one tenant holds it');

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getTraceAsString() . "\n";
}
