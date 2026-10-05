<?php
/**
 * Superadmin two-step sign-in — Phase 5 CLI test
 *   php tests/test_superadmin_2fa_cli.php
 *
 * What it proves, about the TOTP implementation:
 *   - base32 round-trips, and tolerates the spaces and lower case a person
 *     types when copying a key by hand
 *   - codes match RFC 6238's published test vectors, so any authenticator app
 *     will agree with us
 *   - the accepted window is ±1 step and nothing wider
 *
 * And about the account flow:
 *   - enrolment does NOT switch anything on until a live code is typed, so a
 *     mis-scanned secret cannot lock the platform's own administrator out
 *   - the secret is stored encrypted, never in the clear
 *   - a code cannot be replayed inside its own validity window
 *   - signing in is genuinely two-step: the right password alone leaves NO
 *     operator session, only a short-lived pending marker
 *   - a wrong code counts against the same lockout counter as a wrong password
 *   - the pending marker expires
 *   - recovery codes work once each, are stored only as hashes, and are
 *     removed as they are spent
 *   - turning it off requires the current password, not just a session
 *
 * Creates a throwaway operator and removes it. Exit 0 = pass.
 */
$root = dirname(__DIR__);

ini_set('session.save_path', sys_get_temp_dir());
if (session_status() === PHP_SESSION_NONE) session_start();

$GLOBALS['__sent'] = [];
function sendEmail($to, string $subject, string $htmlBody, array $opts = []): bool
{
    $GLOBALS['__sent'][] = ['to' => (string)(is_array($to) ? ($to[0] ?? '') : $to), 'subject' => $subject];
    return true;
}

require_once "$root/includes/config.php";
require_once "$root/core/control_db.php";
require_once "$root/core/totp.php";
require_once "$root/core/superadmin_auth.php";
require_once "$root/core/superadmin_2fa.php";

$_SERVER['REMOTE_ADDR'] = '203.0.113.90';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$made = [];
register_shutdown_function(function () use (&$made) {
    global $pass, $fail;
    try {
        $c = getControlPdo();
        foreach ($made as $id) $c->prepare("DELETE FROM superadmins WHERE id = ?")->execute([$id]);
        $c->exec("DELETE FROM superadmins WHERE email LIKE 'tfatest%'");
    } catch (Throwable $e) {}
    echo "\n\033[1m── Result ──\033[0m\n";
    echo "  \033[32m$pass passed\033[0m" . ($fail ? ", \033[31m$fail failed\033[0m" : '') . "\n\n";
});

echo "\n\033[1mSuperadmin two-step sign-in\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('base32');

$bytes = random_bytes(20);
$b32   = totpBase32Encode($bytes);
ok(totpBase32Decode($b32) === $bytes, 'encode/decode round-trips 20 random bytes');
ok(preg_match('/^[A-Z2-7]+$/', $b32) === 1, 'output uses only the RFC 4648 alphabet');
ok(totpBase32Decode(totpFormatSecret($b32)) === $bytes, 'a key copied WITH the display spaces still decodes');
ok(totpBase32Decode(strtolower($b32)) === $bytes, 'and typed in lower case');
ok(totpBase32Decode('not valid!') === null, 'rubbish is rejected rather than silently producing bytes');
ok(totpBase32Decode('') === null, 'empty is rejected');

section('RFC 6238 test vectors');

// The RFC's shared secret is the ASCII "12345678901234567890".
$rfcSecret = totpBase32Encode('12345678901234567890');
foreach ([
    [59,          '287082'],
    [1111111109,  '081804'],
    [1111111111,  '050471'],
    [1234567890,  '005924'],
    [2000000000,  '279037'],
] as [$ts, $expected]) {
    $got = totpCodeAt($rfcSecret, totpCounterNow($ts));
    ok($got === $expected, "t=$ts → $expected" . ($got === $expected ? '' : " (got " . var_export($got, true) . ")"));
}

section('The accepted window');

$s  = totpGenerateSecret();
$ts = time();
$c0 = totpCodeAt($s, totpCounterNow($ts));
ok(totpVerify($s, $c0, 1, $ts) !== null, 'the current code is accepted');
ok(totpVerify($s, totpCodeAt($s, totpCounterNow($ts) - 1), 1, $ts) !== null, 'the previous step is accepted (clock drift)');
ok(totpVerify($s, totpCodeAt($s, totpCounterNow($ts) + 1), 1, $ts) !== null, 'the next step is accepted');
ok(totpVerify($s, totpCodeAt($s, totpCounterNow($ts) - 2), 1, $ts) === null, 'two steps back is NOT');
ok(totpVerify($s, totpCodeAt($s, totpCounterNow($ts) + 2), 1, $ts) === null, 'two steps forward is NOT');
ok(totpVerify($s, '000000', 1, $ts) === null || $c0 === '000000', 'a made-up code is refused');
ok(totpVerify($s, '12345', 1, $ts) === null, 'a 5-digit code is refused');

$uri = totpUri($s, 'ops@example.test', 'BMS Platform');
ok(strpos($uri, 'otpauth://totp/') === 0, 'the enrolment URI has the scheme apps expect');
ok(strpos($uri, 'secret=' . $s) !== false, 'carries the secret');
ok(strpos($uri, 'issuer=BMS%20Platform') !== false, 'and repeats the issuer as a parameter, which some apps read instead of the label');

// ─────────────────────────────────────────────────────────────────────────────
section('Enrolment does not switch anything on by itself');

$ctrl  = getControlPdo();
$email = 'tfatest.' . bin2hex(random_bytes(3)) . '@example.test';
$ctrl->prepare("INSERT INTO superadmins (name, email, password_hash) VALUES (?,?,?)")
     ->execute(['2FA Test Operator', $email, password_hash('OperatorPw123', PASSWORD_DEFAULT)]);
$saId = (int)$ctrl->lastInsertId();
$made[] = $saId;

ok(saTotpEnabled(saTotpRow($saId)) === false, 'a new operator has no second factor');

$begin = saTotpBeginEnrollment($saId);
ok($begin['ok'] === true, 'enrolment starts');
ok(!empty($begin['secret']) && !empty($begin['uri']), 'and returns a secret and a URI to scan');
ok(saTotpEnabled(saTotpRow($saId)) === false,
   'but it is still OFF — an unconfirmed secret must not start demanding codes');

$stored = (string)saTotpRow($saId)['totp_secret_enc'];
ok(strncmp($stored, 'enc:v1:', 7) === 0, 'the secret is stored encrypted');
ok(strpos($stored, $begin['secret']) === false, 'the raw secret is not in the column');
ok(decryptSecret($stored) === $begin['secret'], 'and decrypts back to what was shown');

ok(saTotpConfirm($saId, '000000')['ok'] === false || totpCodeAt($begin['secret'], totpCounterNow()) === '000000',
   'a wrong code does not switch it on');
ok(saTotpEnabled(saTotpRow($saId)) === false, 'still off after a failed confirmation');

$code = totpCodeAt($begin['secret'], totpCounterNow());
$conf = saTotpConfirm($saId, $code);
ok($conf['ok'] === true, 'a live code switches it on');
ok(saTotpEnabled(saTotpRow($saId)) === true, 'and it is now on');
ok(is_array($conf['recovery_codes']) && count($conf['recovery_codes']) === SA_2FA_RECOVERY_CODES,
   SA_2FA_RECOVERY_CODES . ' recovery codes are issued');

$hashes = json_decode((string)saTotpRow($saId)['totp_recovery_hashes'], true);
ok(is_array($hashes) && count($hashes) === SA_2FA_RECOVERY_CODES, 'and stored');
$plainInDb = false;
foreach ($conf['recovery_codes'] as $rc) {
    if (in_array($rc, $hashes, true)) $plainInDb = true;
}
ok(!$plainInDb, 'stored as hashes, not in plain text');

ok(saTotpBeginEnrollment($saId)['ok'] === false, 'enrolment cannot be restarted while it is on');

// ─────────────────────────────────────────────────────────────────────────────
section('A code cannot be used twice');

$secret = decryptSecret((string)saTotpRow($saId)['totp_secret_enc']);
// Confirmation already spent the current counter, so move to the next one.
$next   = totpCounterNow() + 1;
$code2  = totpCodeAt($secret, $next);

$first  = saTotpCheck($saId, $code2);
ok($first['ok'] === true, 'a fresh code is accepted');
$replay = saTotpCheck($saId, $code2);
ok($replay['ok'] === false, 'the SAME code is refused the second time, inside its own window');
ok(stripos((string)$replay['error'], 'already been used') !== false, 'and says why');

$older = saTotpCheck($saId, totpCodeAt($secret, $next - 1));
ok($older['ok'] === false, 'an older code from the accepted window is refused too');

// ─────────────────────────────────────────────────────────────────────────────
section('Signing in is genuinely two-step');

$_SESSION = [];
$r = attemptSuperadminLogin($email, 'WrongPassword1');
ok($r['ok'] === false, 'a wrong password fails as before');
ok(empty($_SESSION['superadmin_id']), 'and leaves no session');

$_SESSION = [];
$ctrl->prepare("UPDATE superadmins SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$saId]);
$r = attemptSuperadminLogin($email, 'OperatorPw123');
ok($r['ok'] === true, 'the right password is accepted');
ok(!empty($r['needs_2fa']), 'but the result says a second factor is needed');
ok(empty($_SESSION['superadmin_id']), 'AND NO OPERATOR SESSION EXISTS YET');
ok(!empty($_SESSION['superadmin_2fa_pending']), 'only a pending marker');
ok(isSuperadminLoggedIn() === false, 'isSuperadminLoggedIn() agrees the operator is not in');

$bad = completeSuperadminTwoFactor('000000');
ok($bad['ok'] === false || totpCodeAt($secret, totpCounterNow()) === '000000', 'a wrong code is refused');
ok(empty($_SESSION['superadmin_id']), 'and still no session');
$attempts = (int)$ctrl->query("SELECT failed_attempts FROM superadmins WHERE id = $saId")->fetchColumn();
ok($attempts >= 1, 'a wrong code counts against the lockout counter — the second factor is not a free brute-force target');

$ctrl->prepare("UPDATE superadmins SET failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$saId]);

// Stand in for the clock moving on. The replay guard above spent counter
// now+1, and the verifier only accepts now-1…now+1 — so every code the app
// could currently show is either spent or outside the window. That is the
// guard working, not a bug: having used a code, you wait for the next one.
// Winding totp_last_counter back is how a test reaches "30 seconds later"
// without sleeping for it.
$ctrl->prepare("UPDATE superadmins SET totp_last_counter = ? WHERE id = ?")
     ->execute([totpCounterNow() - 2, $saId]);

$good = completeSuperadminTwoFactor(totpCodeAt($secret, totpCounterNow()));
ok($good['ok'] === true, 'a correct code completes the sign-in');
ok(!empty($_SESSION['superadmin_id']) && (int)$_SESSION['superadmin_id'] === $saId, 'and the session now exists');
ok(empty($_SESSION['superadmin_2fa_pending']), 'the pending marker is cleared');
ok((int)$ctrl->query("SELECT failed_attempts FROM superadmins WHERE id = $saId")->fetchColumn() === 0,
   'the lockout counter is reset');

section('The pending marker expires');

$_SESSION = [];
attemptSuperadminLogin($email, 'OperatorPw123');
$_SESSION['superadmin_2fa_pending']['expires_at'] = time() - 1;
$late = completeSuperadminTwoFactor(totpCodeAt($secret, totpCounterNow()));
ok($late['ok'] === false, 'a stale pending sign-in cannot be finished');
ok(empty($_SESSION['superadmin_id']), 'no session is created');
ok(empty($_SESSION['superadmin_2fa_pending']), 'and the marker is dropped');

$_SESSION = [];
ok(completeSuperadminTwoFactor('123456')['ok'] === false, 'a code with no pending sign-in at all is refused');

// ─────────────────────────────────────────────────────────────────────────────
section('Recovery codes');

$rc = $conf['recovery_codes'][0];
$r1 = saTotpCheck($saId, $rc);
ok($r1['ok'] === true && $r1['used_recovery'] === true, 'a recovery code is accepted');
ok($r1['remaining'] === SA_2FA_RECOVERY_CODES - 1, 'and one fewer remains');

$r2 = saTotpCheck($saId, $rc);
ok($r2['ok'] === false, 'the same recovery code cannot be used twice');

$lower = strtolower(str_replace('-', ' ', $conf['recovery_codes'][1]));
ok(saTotpCheck($saId, $lower)['ok'] === true, 'one typed in lower case with spaces still works');
ok(saTotpRecoveryRemaining(saTotpRow($saId)) === SA_2FA_RECOVERY_CODES - 2, 'two are now spent');
ok(saTotpCheck($saId, 'ZZZZZ-ZZZZZ')['ok'] === false, 'an invented recovery code is refused');

// ─────────────────────────────────────────────────────────────────────────────
section('Turning it off takes the password, not just a session');

ok(saTotpDisable($saId, 'WrongPassword1')['ok'] === false, 'a wrong password cannot turn it off');
ok(saTotpEnabled(saTotpRow($saId)) === true, 'it is still on');

ok(saTotpDisable($saId, 'OperatorPw123')['ok'] === true, 'the correct password turns it off');
$after = saTotpRow($saId);
ok(saTotpEnabled($after) === false, 'it is off');
ok($after['totp_secret_enc'] === null, 'the secret is cleared, not left behind');
ok($after['totp_recovery_hashes'] === null, 'and so are the recovery codes');

$_SESSION = [];
$r = attemptSuperadminLogin($email, 'OperatorPw123');
ok($r['ok'] === true && empty($r['needs_2fa']), 'sign-in is one step again');
ok(!empty($_SESSION['superadmin_id']), 'and the session is created straight away');

section('The operator is told when it changes');

$subjects = array_column($GLOBALS['__sent'], 'subject');
ok(count($subjects) >= 2, 'security-change emails were sent');
$toThem = array_filter($GLOBALS['__sent'], fn($m) => $m['to'] === $email);
ok(count($toThem) >= 2, 'to the operator themselves');

exit($fail === 0 ? 0 : 1);
