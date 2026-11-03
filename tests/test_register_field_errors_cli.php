<?php
/**
 * Public registration — exact field errors, normal phone formats, password eye
 *   php tests/test_register_field_errors_cli.php
 *
 * What it proves:
 *   - the server accepts phone numbers typed with dashes, dots, brackets or
 *     spaces ("0712-345-678", "(+255) 712 345 678") and stores digits only,
 *     while letters are still refused with the phone message
 *   - a real signup with a formatted phone creates the company
 *   - register.php names the exact problem under each field (no catch-all
 *     "complete every field" message), in English and Swahili, maps server
 *     refusals to their field, and has a show/hide eye on both password boxes
 *
 * Creates one throwaway tenant; removes it. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_registration.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }
function section($t) { echo "\n── $t ──\n"; }

$made = ['databases' => [], 'users' => []];
function teardown(): void
{
    global $made;
    try {
        $c = getControlPdo();
        $c->exec("DELETE FROM registration_attempts WHERE subdomain LIKE 'regfe%' OR ip_address LIKE '198.51.100.2%'");
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'regfe%'");
        $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'regfe%'");
    } catch (Throwable $e) {}
    try {
        $admin = getProvisioningPdo();
        foreach ($made['databases'] as $db) if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
        foreach ($made['users'] as $u) { try { $admin->exec("DROP USER IF EXISTS " . $admin->quote($u) . "@'%'"); } catch (Throwable $e) {} }
    } catch (Throwable $e) {}
}
register_shutdown_function(function () {
    global $pass, $fail;
    teardown();
    echo "\nPasses:   $pass\nFailures: $fail\n";
});
function withTenancy(callable $fn)
{
    putenv('TENANT_MODE=on');
    putenv('TENANT_BASE_DOMAIN=bms.local');
    try { return $fn(); }
    finally { putenv('TENANT_MODE'); putenv('TENANT_BASE_DOMAIN'); }
}

$sfx = bin2hex(random_bytes(3));
$ip  = 200;
$try = function (array $over) use (&$ip, $sfx) {
    return withTenancy(fn() => registerTenant($over + [
        'company_name' => 'Field Errors Ltd', 'subdomain' => 'regfe' . $sfx . $ip,
        'owner_email' => "regfe{$sfx}{$ip}@example.test", 'owner_username' => 'saumu.test',
        'owner_phone' => '0712345678', 'owner_password' => 'Password1', 'owner_password_confirm' => 'Password1',
    ], '198.51.100.' . ($ip++)));
};

try {
    section('1. Server: phone formatting is accepted, letters are not');
    $phoneMsg = 'valid phone number';
    foreach (['0712-345-678', '(+255) 712 345 678', '+255.712.345.678', '0712 345 678'] as $p) {
        // A bad password makes the call stop right after the phone check: no tenant is created.
        $r = $try(['owner_phone' => $p, 'owner_password' => 'short', 'owner_password_confirm' => 'short']);
        ok($r['ok'] === false && stripos((string)$r['error'], $phoneMsg) === false && stripos((string)$r['error'], 'Password') !== false,
           "\"$p\" passes the phone check (stopped at the password rule instead)");
    }
    foreach (['0712-345-67a', '071234', '0712/345/678'] as $p) {
        $r = $try(['owner_phone' => $p]);
        ok($r['ok'] === false && stripos((string)$r['error'], $phoneMsg) !== false, "\"$p\" is refused with the phone message");
    }

    section('2. A real signup with a formatted phone creates the company');
    $digits = '07' . random_int(10000000, 99999999);
    $typed  = substr($digits, 0, 4) . '-' . substr($digits, 4, 3) . '-' . substr($digits, 7);
    $sub    = 'regfe' . $sfx . 'ok';
    $r = $try(['subdomain' => $sub, 'owner_phone' => $typed, 'owner_email' => "regfe{$sfx}ok@example.test"]);
    ok($r['ok'] === true, "signup with phone \"$typed\" succeeded" . ($r['ok'] ? '' : ': ' . $r['error']));
    $t = getControlPdo()->prepare('SELECT owner_phone, db_name, db_username FROM tenants WHERE subdomain = ?');
    $t->execute([$sub]);
    $row = $t->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($row['db_name'])) $made['databases'][] = $row['db_name'];
    if (!empty($row['db_username'])) $made['users'][] = $row['db_username'];
    ok(($row['owner_phone'] ?? null) === $digits, "stored as digits only ($digits)");

    section('3. register.php: the exact problem under each field');
    $src = (string)file_get_contents("$root/register.php");
    ok(strpos($src, 'meet all password rules') === false && strpos($src, "'err_admin'") === false, 'the catch-all "complete every field" message is gone');
    foreach (['f_first', 'f_last', 'f_user_empty', 'f_user_space', 'f_user_at', 'f_user_start', 'f_user_short', 'f_user_chars',
              'f_phone_empty', 'f_phone_chars', 'f_phone_len', 'f_phone_taken', 'f_email_empty', 'f_email', 'f_email_taken',
              'f_pw_empty', 'f_pw_need', 'f_pw2_empty', 'f_pw2', 'f_terms', 'f_name', 'f_sub_wait', 'f_sub_taken', 'err_fix', 'pw_show', 'pw_hide'] as $k) {
        ok(preg_match_all("/'" . $k . "'\s*=>/", $src) === 2, "$k written in English and Swahili");
    }
    ok(strpos($src, "const phoneClean = v => String(v).replace(/[\\s().\\-]/g, '');") !== false, 'page strips the same phone formatting as the server');
    ok(strpos($src, "preg_replace('/[\\s().\\-]+/', ''") !== false || strpos((string)file_get_contents("$root/core/tenant_registration.php"), "preg_replace('/[\\s().\\-]+/', ''") !== false, 'server strips spaces, dashes, dots and brackets');
    ok(strpos($src, 'function showErrs(') !== false && strpos($src, '$e.text(msg)') !== false, 'field messages are inserted as text');
    ok(strpos($src, 'const SERVER_FIELDS = [') !== false && strpos($src, 'phone number is already registered') !== false, 'server refusals are mapped to their field');

    section('4. Eye button on both password boxes');
    ok(substr_count($src, "\$pwEye('owner_password')") === 1 && substr_count($src, "\$pwEye('owner_password_confirm')") === 1, 'eye on Password and Confirm password');
    ok(strpos($src, 'aria-pressed="false"') !== false && strpos($src, "\$i.attr('type', show ? 'text' : 'password')") !== false, 'eye toggles the box between hidden and shown, with aria-pressed');

    section('5. Password checklist keeps its labels');
    ok(substr_count($src, '<b class="ck" aria-hidden="true">○</b>') === 4 && strpos($src, 'firstChild.nodeValue') === false, 'only the ✓/○ mark changes, the label text stays');
} catch (Throwable $e) {
    ok(false, 'test threw: ' . $e->getMessage());
}
exit($fail === 0 ? 0 : 1);
