<?php
/**
 * Account recovery — Phase 2 (operator-assisted) CLI test
 *   php tests/test_account_recovery_operator_cli.php
 *
 * Provisions a REAL throwaway tenant and drives the panel's recovery
 * operations against it, then removes everything.
 *
 * What it proves, tenant side:
 *   - an operator can send a one-time code, and NEVER learns it: the return
 *     value carries only a masked destination, and the code appears nowhere in
 *     the audit log
 *   - the code is typeable, survives being written with spaces or hyphens, and
 *     sets a password through the same path a self-service reset uses
 *   - there is no function anywhere in the bridge that writes users.password
 *   - a code cannot be sent to an account with no address, a non-admin, or a
 *     deactivated account
 *   - changing the recovery address tells the OLD address, clears
 *     email_verified_at, burns codes already in flight, and refuses to create
 *     two active admins on one address
 *   - usernames can finally be changed, duplicates are refused, and the holder
 *     is told
 *   - a deactivated account can be switched back on
 *   - every one of these lands in tenant_admin_log
 *
 * What it proves, operator side:
 *   - a superadmin can recover their own password without SSH
 *   - the form says the same thing whether the address exists or not
 *   - the link is single-use, expiring, and killed by a newer one
 *   - completing it LIFTS a lockout — the thing that previously needed SQL
 *
 * And: a tenant created by an operator is flagged must_change_password,
 * because the operator chose that password; a self-registered one is not.
 *
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);

ini_set('session.save_path', sys_get_temp_dir());
if (session_status() === PHP_SESSION_NONE) session_start();

// Captured instead of sent — must be declared before core/mailer.php is read.
$GLOBALS['__sent'] = [];
function sendEmail($to, string $subject, string $htmlBody, array $opts = []): bool
{
    $GLOBALS['__sent'][] = ['to' => (string)(is_array($to) ? ($to[0] ?? '') : $to),
                            'subject' => $subject, 'body' => $htmlBody];
    return true;
}

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_crypto.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_account_recovery.php";
require_once "$root/core/superadmin_recovery.php";

$_SERVER['REMOTE_ADDR'] = '203.0.113.77';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function sent(): array { return $GLOBALS['__sent']; }
function clearSent(): void { $GLOBALS['__sent'] = []; }
function lastMail(): ?array { $s = sent(); return $s ? $s[count($s) - 1] : null; }
function mailTo(string $addr): ?array {
    foreach (array_reverse(sent()) as $m) if ($m['to'] === $addr) return $m;
    return null;
}

$created = ['tenants' => [], 'databases' => [], 'users' => [], 'superadmins' => []];

function teardown(): void
{
    global $created;
    try { $admin = getProvisioningPdo(); } catch (Throwable $e) { $admin = null; }
    if ($admin) {
        foreach ($created['databases'] as $db) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
        }
        foreach ($created['users'] as $u) {
            try { $admin->exec("DROP USER IF EXISTS " . $admin->quote($u) . "@'%'"); } catch (Throwable $e) {}
        }
    }
    try {
        $c = getControlPdo();
        if ($created['tenants']) {
            $in = implode(',', array_fill(0, count($created['tenants']), '?'));
            $c->prepare("DELETE FROM tenant_admin_log WHERE tenant_id IN ($in)")->execute($created['tenants']);
            $c->prepare("DELETE FROM tenant_provisioning_log WHERE tenant_id IN ($in)")->execute($created['tenants']);
            $c->prepare("DELETE FROM tenants WHERE id IN ($in)")->execute($created['tenants']);
        }
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'artest%'");
        $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'artest%'");
        foreach ($created['superadmins'] as $id) {
            $c->prepare("DELETE FROM superadmin_password_resets WHERE superadmin_id = ?")->execute([$id]);
            $c->prepare("DELETE FROM superadmins WHERE id = ?")->execute([$id]);
        }
        $c->exec("DELETE FROM superadmin_reset_attempts WHERE identifier LIKE '%artest%'");
    } catch (Throwable $e) {}
}

register_shutdown_function(function () {
    global $pass, $fail;
    teardown();
    echo "\n\033[1m── Result ──\033[0m\n";
    echo "  \033[32m$pass passed\033[0m" . ($fail ? ", \033[31m$fail failed\033[0m" : '') . "\n\n";
});

echo "\n\033[1mAccount recovery — Phase 2 operator-assisted\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('The one-time code itself');

$codes = [];
for ($i = 0; $i < 200; $i++) $codes[] = recoveryGenerateCode();
ok(count(array_unique($codes)) === 200, '200 generated codes are all different');
$bad = array_filter($codes, fn($c) => !preg_match('/^[A-HJ-NP-Z2-9]{10}$/', $c));
ok(!$bad, 'every code is 10 characters from the unambiguous alphabet');
$confusable = array_filter($codes, fn($c) => preg_match('/[OI01]/', $c));
ok(!$confusable, 'no O, 0, I or 1 — it has to survive being read down a phone');

$c = 'K7M29QX4PL';
ok(recoveryFormatCode($c) === 'K7M2-9QX4-PL', 'grouped for reading aloud');
ok(recoveryNormalizeToken('k7m2-9qx4-pl') === $c, 'typed back in lower case with hyphens still matches');
ok(recoveryNormalizeToken(' K7M2 9QX4 PL ') === $c, 'spaces and stray whitespace are forgiven');
ok(recoveryNormalizeToken('K7M29QX4P') === null, 'a code one character short is not accepted');
ok(recoveryNormalizeToken(str_repeat('a', 64)) === str_repeat('a', 64), 'a link token still passes through unchanged');
ok(recoveryNormalizeToken('') === null, 'empty is not a token');

section('The bridge cannot set a password');

$bridge = file_get_contents("$root/core/tenant_account_recovery.php");
ok(!preg_match('/UPDATE\s+users\s+SET[^;]*\bpassword\s*=/i', $bridge),
   'core/tenant_account_recovery.php contains no statement that writes users.password');
ok(strpos($bridge, 'password_hash(') === false,
   'and never hashes a password — the owner always sets their own');

// ─────────────────────────────────────────────────────────────────────────────
$sfx     = bin2hex(random_bytes(3));
$sub     = 'artest' . $sfx;
$ownerPw = 'OperatorSet99';

section('Provisioning a real tenant to work against');

$prov = provisionTenant('AR Test Ltd', $sub, "owner@$sub.test", $ownerPw, [
    'status'                => 'active',
    'owner_first_name'      => 'Ada',
    'owner_last_name'       => 'Owner',
    'owner_phone'           => '255700000001',
    'owner_contact_email'   => "owner@$sub.test",
    'force_password_change' => true,       // what createTenantAsOperator passes
    'skip_welcome_email'    => true,
]);
ok($prov['ok'] === true, 'tenant provisioned' . ($prov['ok'] ? '' : ': ' . $prov['error']));
if (!$prov['ok']) { exit(1); }

$tenantId = (int)$prov['tenant_id'];
$created['tenants'][]   = $tenantId;
$created['databases'][] = $prov['db_name'];
$created['users'][]     = $prov['db_username'];

[$tpdo, $trow] = tarOpenTenant($tenantId);
ok($tpdo !== null, 'the recovery bridge can open it');

$owner = $tpdo->query("SELECT * FROM users WHERE is_admin = 1 ORDER BY user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$ownerId = (int)$owner['user_id'];

ok($owner['email'] === "owner@$sub.test", 'the owner row carries a real email (Phase 0 fix holds end to end)');
ok($owner['phone'] === '255700000001',    'and a real phone');
ok((int)$owner['must_change_password'] === 1,
   'operator-created tenant is flagged must_change_password — the operator chose that password');
ok($tpdo->query("SHOW TABLES LIKE 'password_resets'")->fetch() !== false,
   'the recovery schema was ensured during provisioning, not left to the next deploy');

// ─────────────────────────────────────────────────────────────────────────────
section('Issuing a one-time code');

clearSent();
$r = tenantIssueAdminOtp($tenantId, $ownerId);
ok($r['ok'] === true, 'code issued' . ($r['ok'] ? '' : ': ' . $r['error']));
ok($r['sent_to'] === tarMaskEmail("owner@$sub.test"), 'the operator is given only a MASKED destination');
ok(strpos((string)$r['sent_to'], 'owner@') === false, 'the full address is not handed back');
ok(!array_key_exists('code', $r) && !array_key_exists('token', $r),
   'the response carries no code field at all');
ok($r['expires_minutes'] === (int)RECOVERY_OTP_TTL_MIN, 'and says how long it lasts');

$mail = mailTo("owner@$sub.test");
ok($mail !== null, 'the code went to the account holder, not the operator');
preg_match('/([A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{2})/', $mail['body'] ?? '', $cm);
$otp = str_replace('-', '', $cm[1] ?? '');
ok(strlen($otp) === 10, 'the email shows a typeable code');
ok(strpos($mail['body'], 'nobody at your provider can see what you choose') !== false
   || stripos($mail['body'], 'nobody at your provider') !== false,
   'the email tells them the provider cannot see their new password');

$log = getControlPdo()->prepare("SELECT action, detail FROM tenant_admin_log WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
$log->execute([$tenantId]);
$entry = $log->fetch(PDO::FETCH_ASSOC);
ok($entry && $entry['action'] === 'admin_otp_issued', 'the issue is recorded in tenant_admin_log');
ok($entry && strpos($entry['detail'], $otp) === false, 'and the code itself is NOT in the log');

section('Using the code');

ok(recoveryVerifyToken($tpdo, $otp) !== null, 'the plain code verifies');
ok(recoveryVerifyToken($tpdo, recoveryFormatCode($otp)) !== null, 'so does the hyphenated form from the email');

$done = recoveryCompleteReset($tpdo, strtolower(recoveryFormatCode($otp)), 'OwnerChose123', 'OwnerChose123');
ok($done['ok'] === true, 'the owner sets their own password with it');

$after = $tpdo->query("SELECT password, must_change_password FROM users WHERE user_id = $ownerId")->fetch(PDO::FETCH_ASSOC);
ok(password_verify('OwnerChose123', $after['password']), 'the password they chose works');
ok(!password_verify($ownerPw, $after['password']), 'the one the operator typed no longer does');
ok((int)$after['must_change_password'] === 0, 'and the forced-change flag is cleared');
ok(recoveryCompleteReset($tpdo, $otp, 'Again12345', 'Again12345')['ok'] === false, 'the code works only once');

section('When a code cannot be sent');

$tpdo->exec("INSERT INTO users (username, password, email, is_admin, is_active, first_name)
             VALUES ('ar_staff', 'x', 'staff@$sub.test', 0, 1, 'Staff')");
$staffId = (int)$tpdo->lastInsertId();
$tpdo->exec("INSERT INTO users (username, password, email, is_admin, is_active, first_name)
             VALUES ('ar_off', 'x', 'off@$sub.test', 1, 0, 'Off')");
$offId = (int)$tpdo->lastInsertId();
$tpdo->exec("INSERT INTO users (username, password, email, is_admin, is_active, first_name)
             VALUES ('ar_noemail', 'x', '', 1, 1, 'NoMail')");
$noMailId = (int)$tpdo->lastInsertId();

ok(tenantIssueAdminOtp($tenantId, $staffId)['ok'] === false,  'refused for a non-admin');
ok(tenantIssueAdminOtp($tenantId, $offId)['ok'] === false,    'refused for a deactivated account');
$nm = tenantIssueAdminOtp($tenantId, $noMailId);
ok($nm['ok'] === false, 'refused when there is no address to send to');
ok(stripos((string)$nm['error'], 'no email') !== false, 'and the operator is told exactly what to fix');
ok(tenantIssueAdminOtp($tenantId, 999999)['ok'] === false, 'refused for an account that does not exist');

// ─────────────────────────────────────────────────────────────────────────────
section('Correcting the recovery address');

clearSent();
$se = tenantSetUserEmail($tenantId, $ownerId, "newowner@$sub.test");
ok($se['ok'] === true, 'the address can be corrected' . ($se['ok'] ? '' : ': ' . $se['error']));

$row = $tpdo->query("SELECT email, email_verified_at FROM users WHERE user_id = $ownerId")->fetch(PDO::FETCH_ASSOC);
ok($row['email'] === "newowner@$sub.test", 'the new address is stored');
ok($row['email_verified_at'] === null, 'email_verified_at cleared — an operator typed it, nobody proved it');

$oldNotice = mailTo("owner@$sub.test");
ok($oldNotice !== null, 'the OLD address is told it changed — a redirect cannot be silent');
ok(stripos($oldNotice['body'], 'did not ask for this') !== false, 'and told what to do if it was not them');

ok(tenantSetUserEmail($tenantId, $ownerId, 'not-an-email')['ok'] === false, 'a malformed address is refused');
ok(tenantSetUserEmail($tenantId, $ownerId, "newowner@$sub.test")['ok'] === false, 'setting the same address again is refused');

// A second active admin on the same address would make recovery ambiguous.
$tpdo->exec("INSERT INTO users (username, password, email, is_admin, is_active, first_name)
             VALUES ('ar_admin2', 'x', 'second@$sub.test', 1, 1, 'Two')");
$admin2 = (int)$tpdo->lastInsertId();
$clash = tenantSetUserEmail($tenantId, $admin2, "newowner@$sub.test");
ok($clash['ok'] === false, 'two active admins cannot be given one address');
ok(stripos((string)$clash['error'], 'own') !== false, 'and the reason explains why');

section('A code already in flight is burned when the address changes');

tenantIssueAdminOtp($tenantId, $ownerId);
$live = $tpdo->query("SELECT COUNT(*) FROM password_resets WHERE user_id = $ownerId AND used_at IS NULL")->fetchColumn();
ok((int)$live === 1, 'there is a live code');
tenantSetUserEmail($tenantId, $ownerId, "third@$sub.test");
$live = $tpdo->query("SELECT COUNT(*) FROM password_resets WHERE user_id = $ownerId AND used_at IS NULL")->fetchColumn();
ok((int)$live === 0, 'changing the address kills it — it was heading to the old inbox');

// ─────────────────────────────────────────────────────────────────────────────
section('Changing a username');

clearSent();
$su = tenantSetUserUsername($tenantId, $ownerId, 'ada.owner');
ok($su['ok'] === true, 'a username can finally be changed' . ($su['ok'] ? '' : ': ' . $su['error']));
ok($tpdo->query("SELECT username FROM users WHERE user_id = $ownerId")->fetchColumn() === 'ada.owner', 'it is stored');
$un = mailTo("third@$sub.test");
ok($un !== null && stripos($un['subject'], 'username') !== false, 'the holder is told their sign-in name changed');
ok($un !== null && strpos($un['body'], 'ada.owner') !== false, 'and the email states the new one');

ok(tenantSetUserUsername($tenantId, $ownerId, 'ar_staff')['ok'] === false, 'a duplicate username is refused');
ok(tenantSetUserUsername($tenantId, $ownerId, 'ab')['ok'] === false, 'too short is refused');
ok(tenantSetUserUsername($tenantId, $ownerId, 'bad name!')['ok'] === false, 'illegal characters are refused');
ok(tenantSetUserUsername($tenantId, $ownerId, 'ada.owner')['ok'] === false, 'setting the same name again is refused');

section('Re-enabling a switched-off account');

ok(tenantReactivateUser($tenantId, $ownerId)['ok'] === false, 'an already-active account is refused');
$re = tenantReactivateUser($tenantId, $offId);
ok($re['ok'] === true, 'a deactivated account is switched back on');
ok((int)$tpdo->query("SELECT is_active FROM users WHERE user_id = $offId")->fetchColumn() === 1, 'and is_active is now 1');

section('Everything an operator did is on the record');

$acts = getControlPdo()->prepare("SELECT action FROM tenant_admin_log WHERE tenant_id = ?");
$acts->execute([$tenantId]);
$actions = array_unique($acts->fetchAll(PDO::FETCH_COLUMN));
foreach (['admin_otp_issued', 'admin_email_changed', 'admin_username_changed', 'admin_account_reactivated'] as $a) {
    ok(in_array($a, $actions, true), "tenant_admin_log records '$a'");
}

// ─────────────────────────────────────────────────────────────────────────────
section('Self-registration is NOT force-changed');

$sub2  = 'artest' . bin2hex(random_bytes(3));
$prov2 = provisionTenant('AR Self Ltd', $sub2, '255700000002', 'SelfChose123', [
    'status'              => 'active',
    'owner_phone'         => '255700000002',
    'owner_contact_email' => "self@$sub2.test",
    'skip_welcome_email'  => true,
]);
ok($prov2['ok'] === true, 'a self-registration-shaped tenant provisions');
if ($prov2['ok']) {
    $created['tenants'][]   = (int)$prov2['tenant_id'];
    $created['databases'][] = $prov2['db_name'];
    $created['users'][]     = $prov2['db_username'];

    [$t2] = tarOpenTenant((int)$prov2['tenant_id']);
    $o2 = $t2->query("SELECT username, email, phone, must_change_password FROM users WHERE is_admin = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok((int)$o2['must_change_password'] === 0, 'an owner who chose their own password is not forced to change it');
    ok($o2['username'] === '255700000002', 'their username is the phone, as the signup path intends');
    ok($o2['email'] === "self@$sub2.test", 'and the email they typed is on the account, not lost');
    ok($o2['phone'] === '255700000002', 'the phone is stored as a phone too');
}

// ─────────────────────────────────────────────────────────────────────────────
section('The operator can recover their OWN password — no SSH');

$ctrl  = getControlPdo();
$saMail = 'artest.op.' . bin2hex(random_bytes(3)) . '@example.test';
$ctrl->prepare("INSERT INTO superadmins (name, email, password_hash, failed_attempts, locked_until)
                VALUES (?,?,?,?,?)")
     ->execute(['AR Operator', $saMail, password_hash('OriginalOp99', PASSWORD_DEFAULT), 5,
                date('Y-m-d H:i:s', time() + 3600)]);   // locked out, as if they tripped it themselves
$saId = (int)$ctrl->lastInsertId();
$created['superadmins'][] = $saId;

ok((int)$ctrl->query("SELECT failed_attempts FROM superadmins WHERE id = $saId")->fetchColumn() === 5,
   'the operator starts locked out, which previously needed SQL to undo');

clearSent();
$unknown = saRecoveryRequest('nobody.artest@example.test', 'https://superadmin.example.test');
$known   = saRecoveryRequest($saMail, 'https://superadmin.example.test');
ok($unknown === $known, 'the form says exactly the same thing for a real and a fake address');
ok(mailTo('nobody.artest@example.test') === null, 'nothing is sent for an unknown address');

$saMailMsg = mailTo($saMail);
ok($saMailMsg !== null, 'a link is sent to a real operator');
preg_match('~/forgot\?token=([a-f0-9]{64})~', $saMailMsg['body'] ?? '', $sm);
$saTok = $sm[1] ?? '';
ok($saTok !== '', 'the link carries a 64-hex token');

$stored = $ctrl->query("SELECT token_hash FROM superadmin_password_resets WHERE superadmin_id = $saId ORDER BY reset_id DESC LIMIT 1")->fetchColumn();
ok($stored === hash('sha256', $saTok), 'only its SHA-256 is stored');

ok(saRecoveryVerify($saTok) !== null, 'it verifies');
ok(saRecoveryComplete($saTok, 'short', 'short')['ok'] === false, 'a weak password is refused');
ok(saRecoveryComplete($saTok, 'NewOpPass123', 'Different123')['ok'] === false, 'a mismatched confirmation is refused');
ok(saRecoveryVerify($saTok) !== null, 'a rejected attempt does not burn the link');

clearSent();
$fin = saRecoveryComplete($saTok, 'NewOpPass123', 'NewOpPass123');
ok($fin['ok'] === true, 'the reset completes' . ($fin['ok'] ? '' : ': ' . $fin['error']));

$sa = $ctrl->query("SELECT password_hash, failed_attempts, locked_until FROM superadmins WHERE id = $saId")->fetch(PDO::FETCH_ASSOC);
ok(password_verify('NewOpPass123', $sa['password_hash']), 'the new operator password works');
ok((int)$sa['failed_attempts'] === 0, 'failed_attempts cleared');
ok($sa['locked_until'] === null, 'THE LOCKOUT IS LIFTED — this is what used to require SSH');
ok(mailTo($saMail) !== null, 'the operator is emailed that it happened');

ok(saRecoveryComplete($saTok, 'Another12345', 'Another12345')['ok'] === false, 'the link works only once');

section('Operator link: newer kills older, and expiry bites');

saRecoveryRequest($saMail, 'https://superadmin.example.test');
preg_match('~/forgot\?token=([a-f0-9]{64})~', mailTo($saMail)['body'] ?? '', $s1);
$tokA = $s1[1] ?? '';
saRecoveryRequest($saMail, 'https://superadmin.example.test');
preg_match('~/forgot\?token=([a-f0-9]{64})~', mailTo($saMail)['body'] ?? '', $s2);
$tokB = $s2[1] ?? '';
ok($tokA !== '' && $tokB !== '' && $tokA !== $tokB, 'two requests give two different links');
ok(saRecoveryVerify($tokA) === null, 'the older link is dead');
ok(saRecoveryVerify($tokB) !== null, 'the newer one is live');

$ctrl->prepare("UPDATE superadmin_password_resets SET expires_at = ? WHERE token_hash = ?")
     ->execute([date('Y-m-d H:i:s', time() - 60), hash('sha256', $tokB)]);
ok(saRecoveryVerify($tokB) === null, 'an expired link is refused');

section('The operator recovery page is actually reachable');

// The panel's short URLs come from superadminRouteMap(), not the tenant
// router, so a page that is not in that map is unreachable no matter how
// correct its code is.
$map = superadminRouteMap();
ok(isset($map['forgot']), "superadminRouteMap() has a 'forgot' entry");
ok(isset($map['forgot']) && is_file($map['forgot']), 'and it points at a file that exists');
ok(isset($map['forgot']) && basename($map['forgot']) === 'forgot.php', 'which is app/superadmin/forgot.php');

$loginSrc = file_get_contents("$root/app/superadmin/login.php");
ok(strpos($loginSrc, "saUrl('forgot')") !== false,
   'the operator sign-in page links to it — otherwise nobody would ever find it');

section('Operator recovery is throttled too');

$ctrl->exec("DELETE FROM superadmin_reset_attempts WHERE identifier = " . $ctrl->quote($saMail));
$_SERVER['REMOTE_ADDR'] = '198.51.100.230';
$seq = [];
for ($i = 0; $i < SA_RECOVERY_MAX_PER_EMAIL + 1; $i++) {
    $seq[] = saRecoveryRequest($saMail, 'https://superadmin.example.test')['throttled'];
}
ok(end($seq) === true, 'repeated requests for one operator address are throttled');
ok($seq[0] === false, 'but the first was allowed');

exit($fail === 0 ? 0 : 1);
