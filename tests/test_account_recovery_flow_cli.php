<?php
/**
 * Account recovery — Phase 1 (self-service reset) CLI test
 *   php tests/test_account_recovery_flow_cli.php
 *
 * What it proves:
 *   - only an ACTIVE ADMIN can be sent a reset; staff, disabled accounts and
 *     unknown addresses silently get nothing
 *   - two admins sharing one address get nothing either — the system refuses to
 *     guess which was meant rather than resetting the wrong account
 *   - the caller is handed NOTHING it could use to tell those cases apart
 *     (no account enumeration)
 *   - the raw token is never stored; only its SHA-256 is
 *   - a token works exactly once, dies on expiry, and is killed by a newer one
 *   - completing a reset changes the password, ends every open session, and
 *     emails the account holder that it happened
 *   - two simultaneous uses of one link cannot both succeed
 *   - the password policy matches signup (8+, a letter, a number, confirmed)
 *   - throttling bites per account AND per IP, and a broken throttle fails
 *     closed rather than open
 *   - "forgot username" mails the username and never returns it to the page
 *
 * Builds a throwaway database and drops it again. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/includes/config.php";

// Must be defined BEFORE core/mailer.php is reached: it only declares
// sendEmail() when nothing else has (tests/helpers/field_reports_request.php
// uses the same trick).
$GLOBALS['__sent'] = [];
function sendEmail($to, string $subject, string $htmlBody, array $opts = []): bool
{
    $GLOBALS['__sent'][] = ['to' => (string)(is_array($to) ? ($to[0] ?? '') : $to),
                            'subject' => $subject, 'body' => $htmlBody];
    return true;
}

require_once "$root/core/account_recovery_schema.php";
require_once "$root/core/account_recovery.php";

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_HOST']   = 'shop.example.test';
$_SERVER['HTTPS']       = 'on';

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
function sent(): array { return $GLOBALS['__sent']; }
function clearSent(): void { $GLOBALS['__sent'] = []; }
function lastMail(): ?array { $s = sent(); return $s ? $s[count($s) - 1] : null; }

$dbName = 'bms_arflow_' . random_int(100000, 999999);
register_shutdown_function(function () use ($dbName) {
    try {
        $a = new PDO('mysql:host=' . DB_SERVER . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $a->exec("DROP DATABASE IF EXISTS `$dbName`");
    } catch (Throwable $e) {}
});

$admin = new PDO('mysql:host=' . DB_SERVER . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4");
$pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=$dbName;charset=utf8mb4", DB_USERNAME, DB_PASSWORD,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec("
    CREATE TABLE `users` (
      `user_id` int NOT NULL AUTO_INCREMENT,
      `username` varchar(50) DEFAULT NULL,
      `password` varchar(255) DEFAULT NULL,
      `email` varchar(100) DEFAULT NULL,
      `phone` varchar(30) DEFAULT NULL,
      `first_name` varchar(100) DEFAULT NULL,
      `last_name` varchar(100) DEFAULT NULL,
      `is_admin` int NOT NULL DEFAULT '0',
      `is_active` int NOT NULL DEFAULT '1',
      `password_changed_at` datetime DEFAULT NULL,
      PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE `user_sessions` (
      `id` int NOT NULL AUTO_INCREMENT,
      `user_id` int NOT NULL,
      `login_at` datetime NOT NULL,
      `logout_at` datetime DEFAULT NULL,
      `duration_seconds` int DEFAULT NULL,
      `logout_type` varchar(20) DEFAULT NULL,
      `revoked_by` int DEFAULT NULL,
      `revoked_at` datetime DEFAULT NULL,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$OLD = password_hash('OldPassw0rd', PASSWORD_DEFAULT);
$ins = $pdo->prepare("INSERT INTO users (user_id, username, password, email, first_name, is_admin, is_active)
                      VALUES (?,?,?,?,?,?,?)");
$ins->execute([1, 'boss',    $OLD, 'admin@test.example',  'Ada',   1, 1]);  // the admin
$ins->execute([2, 'clerk',   $OLD, 'staff@test.example',  'Sam',   0, 1]);  // staff
$ins->execute([3, 'retired', $OLD, 'off@test.example',    'Rex',   1, 0]);  // disabled admin
$ins->execute([4, 'twinA',   $OLD, 'dup@test.example',    'Twin1', 1, 1]);  // shared address
$ins->execute([5, 'twinB',   $OLD, 'dup@test.example',    'Twin2', 1, 1]);

accountRecoveryEnsureSchema($pdo);

echo "\n\033[1mAccount recovery — Phase 1 self-service flow\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('Who may be sent a reset');

ok(recoveryFindAdminByEmail($pdo, 'admin@test.example') !== null, 'active admin is found');
ok(recoveryFindAdminByEmail($pdo, 'staff@test.example') === null, 'ordinary staff is NOT (their own admin resets them)');
ok(recoveryFindAdminByEmail($pdo, 'off@test.example')   === null, 'disabled admin is NOT');
ok(recoveryFindAdminByEmail($pdo, 'nobody@test.example') === null, 'unknown address is NOT');
ok(recoveryFindAdminByEmail($pdo, 'dup@test.example')   === null, 'two admins on one address: refuses to guess');
ok(recoveryFindAdminByEmail($pdo, 'not-an-email')       === null, 'malformed address is NOT');

// ─────────────────────────────────────────────────────────────────────────────
section('No account enumeration');

$outcomes = [];
foreach (['admin@test.example', 'staff@test.example', 'off@test.example', 'nobody@test.example'] as $i => $addr) {
    clearSent();
    // Fresh IP each time so the throttle does not interfere with this check.
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . (20 + $i);
    $outcomes[$addr] = recoveryRequestReset($pdo, $addr, 'Test Co');
}
$distinct = array_unique(array_map('json_encode', $outcomes));
ok(count($distinct) === 1, 'every address produces an identical return value');
ok(reset($outcomes) === ['throttled' => false], 'and that value carries no "found" flag at all');

$_SERVER['REMOTE_ADDR'] = '203.0.113.11';
clearSent();
recoveryRequestReset($pdo, 'staff@test.example', 'Test Co');
ok(count(sent()) === 0, 'no email is sent for a staff address');
clearSent();
recoveryRequestReset($pdo, 'nobody@test.example', 'Test Co');
ok(count(sent()) === 0, 'no email is sent for an unknown address');
clearSent();
recoveryRequestReset($pdo, 'dup@test.example', 'Test Co');
ok(count(sent()) === 0, 'no email is sent when two admins share the address');

// ─────────────────────────────────────────────────────────────────────────────
section('Issuing a reset');

$_SERVER['REMOTE_ADDR'] = '203.0.113.12';
$pdo->exec("DELETE FROM password_reset_attempts");
clearSent();
$r = recoveryRequestReset($pdo, 'admin@test.example', 'Test Co');
ok($r['throttled'] === false, 'request accepted');
ok(count(sent()) === 1, 'exactly one email sent');

$mail = lastMail();
ok($mail && $mail['to'] === 'admin@test.example', 'sent to the address on the account');
ok($mail && stripos($mail['subject'], 'reset') !== false, 'subject says what it is');
ok($mail && strpos($mail['body'], 'https://shop.example.test/forgot-password?token=') !== false,
   'body carries an absolute link to this tenant host');

preg_match('~forgot-password\?token=([a-f0-9]{64})~', $mail['body'] ?? '', $m);
$rawToken = $m[1] ?? '';
ok($rawToken !== '', 'the link carries a 64-hex token');

$stored = $pdo->query("SELECT token_hash, kind, channel, destination, used_at FROM password_resets ORDER BY reset_id DESC LIMIT 1")
              ->fetch(PDO::FETCH_ASSOC);
ok($stored && $stored['token_hash'] === hash('sha256', $rawToken), 'only the SHA-256 is stored');
ok($stored && $stored['token_hash'] !== $rawToken, 'the raw token is NOT in the database');
$rawAnywhere = (int)$pdo->query("SELECT COUNT(*) FROM password_resets WHERE token_hash = " . $pdo->quote($rawToken))->fetchColumn();
ok($rawAnywhere === 0, 'and cannot be found by searching for it');
ok($stored['kind'] === 'self_service', "kind recorded as 'self_service'");
ok($stored['channel'] === 'email', "channel recorded as 'email'");
ok($stored['destination'] === 'admin@test.example', 'destination recorded for the audit trail');

// ─────────────────────────────────────────────────────────────────────────────
section('Verifying a token');

$v = recoveryVerifyToken($pdo, $rawToken);
ok($v !== null && $v['user_id'] === 1, 'the real token resolves to the right account');
ok(recoveryVerifyToken($pdo, str_repeat('a', 64)) === null, 'an unknown 64-hex token is rejected');
ok(recoveryVerifyToken($pdo, 'short')            === null, 'a malformed token is rejected');
ok(recoveryVerifyToken($pdo, '')                 === null, 'an empty token is rejected');

section('A newer link kills the older one');

$_SERVER['REMOTE_ADDR'] = '203.0.113.13';
clearSent();
recoveryRequestReset($pdo, 'admin@test.example', 'Test Co');
preg_match('~forgot-password\?token=([a-f0-9]{64})~', lastMail()['body'] ?? '', $m2);
$newToken = $m2[1] ?? '';
ok($newToken !== '' && $newToken !== $rawToken, 'a second request mints a different token');
ok(recoveryVerifyToken($pdo, $rawToken) === null, 'the FIRST link no longer works');
ok(recoveryVerifyToken($pdo, $newToken) !== null, 'the newest link does');

// ─────────────────────────────────────────────────────────────────────────────
section('Password policy matches signup');

foreach ([
    ['Ab1',          'Ab1',          'shorter than 8'],
    ['abcdefghij',   'abcdefghij',   'no number'],
    ['1234567890',   '1234567890',   'no letter'],
    ['Password1',    'Password2',    'confirmation does not match'],
] as [$pw, $cf, $label]) {
    $res = recoveryCompleteReset($pdo, $newToken, $pw, $cf);
    ok($res['ok'] === false, "rejected: $label");
}
ok(recoveryVerifyToken($pdo, $newToken) !== null, 'a rejected attempt does NOT burn the token');

// ─────────────────────────────────────────────────────────────────────────────
section('Completing the reset');

$pdo->prepare("INSERT INTO user_sessions (user_id, login_at) VALUES (1, DATE_SUB(NOW(), INTERVAL 1 HOUR))")->execute();
$pdo->prepare("INSERT INTO user_sessions (user_id, login_at) VALUES (1, DATE_SUB(NOW(), INTERVAL 10 MINUTE))")->execute();
$pdo->prepare("INSERT INTO user_sessions (user_id, login_at) VALUES (2, DATE_SUB(NOW(), INTERVAL 10 MINUTE))")->execute();
clearSent();

$res = recoveryCompleteReset($pdo, $newToken, 'BrandNew99', 'BrandNew99');
ok($res['ok'] === true, 'reset completes');

$row = $pdo->query("SELECT password, password_changed_at, must_change_password FROM users WHERE user_id = 1")->fetch(PDO::FETCH_ASSOC);
ok(password_verify('BrandNew99', $row['password']), 'the new password works');
ok(!password_verify('OldPassw0rd', $row['password']), 'the old password no longer does');
ok($row['password_changed_at'] !== null, 'password_changed_at stamped');
ok((int)$row['must_change_password'] === 0, 'must_change_password cleared — they chose it themselves');

$open1 = (int)$pdo->query("SELECT COUNT(*) FROM user_sessions WHERE user_id = 1 AND logout_at IS NULL")->fetchColumn();
ok($open1 === 0, 'every open session for that account is closed');
$kind = $pdo->query("SELECT DISTINCT logout_type FROM user_sessions WHERE user_id = 1")->fetchColumn();
ok($kind === 'password_reset', "closed sessions are marked 'password_reset'");
$open2 = (int)$pdo->query("SELECT COUNT(*) FROM user_sessions WHERE user_id = 2 AND logout_at IS NULL")->fetchColumn();
ok($open2 === 1, "another user's session is untouched");

$notice = lastMail();
ok($notice && $notice['to'] === 'admin@test.example', 'the account holder is told their password changed');
ok($notice && stripos($notice['subject'], 'changed') !== false, 'the notice says so in the subject');
ok($notice && stripos($notice['body'], 'not you') !== false, 'and tells them what to do if it was not them');

section('A link works exactly once');

$again = recoveryCompleteReset($pdo, $newToken, 'Another123', 'Another123');
ok($again['ok'] === false, 'the same link cannot be used twice');
ok(password_verify('BrandNew99', $pdo->query("SELECT password FROM users WHERE user_id = 1")->fetchColumn()),
   'and the password from the first use still stands');

section('Expiry');

$expTok = recoveryIssueToken($pdo, 1, 'admin@test.example', 'self_service');
ok(recoveryVerifyToken($pdo, $expTok) !== null, 'a fresh token verifies');
$pdo->exec("UPDATE password_resets SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             WHERE token_hash = " . $pdo->quote(hash('sha256', $expTok)));
ok(recoveryVerifyToken($pdo, $expTok) === null, 'an expired token is refused');
ok(recoveryCompleteReset($pdo, $expTok, 'Later1234', 'Later1234')['ok'] === false, 'and cannot complete a reset');

section('Two simultaneous uses of one link');

$raceTok = recoveryIssueToken($pdo, 1, 'admin@test.example', 'self_service');
$hash    = hash('sha256', $raceTok);
// Stand in for the other request winning the claim a microsecond earlier.
$verified = recoveryVerifyToken($pdo, $raceTok) !== null;
$pdo->exec("UPDATE password_resets SET used_at = NOW() WHERE token_hash = " . $pdo->quote($hash));
$raced = recoveryCompleteReset($pdo, $raceTok, 'Racing12345', 'Racing12345');
ok($verified, 'both requests saw a valid token');
ok($raced['ok'] === false, 'the loser is refused — claim-then-act, not check-then-act');

// ─────────────────────────────────────────────────────────────────────────────
section('Throttling');

$pdo->exec("DELETE FROM password_reset_attempts");
$_SERVER['REMOTE_ADDR'] = '198.51.100.200';
$results = [];
for ($i = 0; $i < 4; $i++) {
    $results[] = recoveryRequestReset($pdo, 'admin@test.example', 'Test Co')['throttled'];
}
ok($results === [false, false, false, true],
   'the 4th request for one account within the window is throttled (limit ' . RECOVERY_MAX_PER_IDENTIFIER . ')');

$pdo->exec("DELETE FROM password_reset_attempts");
$_SERVER['REMOTE_ADDR'] = '198.51.100.201';
$ipHit = false;
for ($i = 0; $i < RECOVERY_MAX_PER_IP + 1; $i++) {
    // A different address each time, so only the per-IP limit can fire.
    $ipHit = recoveryRequestReset($pdo, "probe{$i}@test.example", 'Test Co')['throttled'];
}
ok($ipHit === true, 'a sweep of different addresses from one IP is throttled (limit ' . RECOVERY_MAX_PER_IP . ')');

$pdo->exec("DROP TABLE password_reset_attempts");
ok(recoveryThrottled($pdo, 'admin@test.example', '198.51.100.202') === true,
   'a broken throttle fails CLOSED, not open');
accountRecoveryEnsureSchema($pdo);   // put it back

// ─────────────────────────────────────────────────────────────────────────────
section('Forgot username');

$pdo->exec("DELETE FROM password_reset_attempts");
$_SERVER['REMOTE_ADDR'] = '198.51.100.210';
clearSent();
$u = recoveryRequestUsername($pdo, 'admin@test.example', 'Test Co');
ok($u === ['throttled' => false], 'the return value reveals nothing, same as a reset request');
ok(count(sent()) === 1, 'one email sent');
ok(strpos(lastMail()['body'] ?? '', 'boss') !== false, 'the username is in the email');
ok(json_encode($u) === json_encode(['throttled' => false]), 'and never in the page response');

clearSent();
recoveryRequestUsername($pdo, 'nobody@test.example', 'Test Co');
ok(count(sent()) === 0, 'nothing is sent for an unknown address');

// A username request must not mint a token — it is a reminder, not a way in.
$before = (int)$pdo->query("SELECT COUNT(*) FROM password_resets")->fetchColumn();
clearSent();
recoveryRequestUsername($pdo, 'admin@test.example', 'Test Co');
$after = (int)$pdo->query("SELECT COUNT(*) FROM password_resets")->fetchColumn();
ok($before === $after, 'a username reminder creates no reset token');

// ─────────────────────────────────────────────────────────────────────────────
section('Token lifetime does not depend on the connection time zone');

// REGRESSION. The app pins SET time_zone = '+03:00' in four separate
// bootstraps; a connection that does not sees a different MySQL NOW() — on
// this server, ten hours apart. While the token's lifetime was computed with
// NOW(), a token issued on one connection and verified on another was either
// dead on arrival or alive long after it should have died. Issuing and
// verifying from two deliberately different session time zones must now be
// indistinguishable from doing both on one.
$connAt = function (string $tz) use ($dbName): PDO {
    $c = new PDO("mysql:host=" . DB_SERVER . ";dbname=$dbName;charset=utf8mb4", DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $c->exec("SET time_zone = '$tz'");
    return $c;
};
$issuer   = $connAt('+03:00');   // what the app uses
$verifier = $connAt('-07:00');   // an entry point that forgot

$nowGap = (int)abs(strtotime($issuer->query("SELECT NOW()")->fetchColumn())
                 - strtotime($verifier->query("SELECT NOW()")->fetchColumn()));
ok($nowGap >= 3600, 'the two connections really do disagree about NOW() (' . round($nowGap / 3600) . 'h apart)');

$tzTok = recoveryIssueToken($issuer, 1, 'admin@test.example', 'self_service');
ok(recoveryVerifyToken($verifier, $tzTok) !== null, 'a token issued on one zone verifies on the other');
ok(recoveryVerifyToken($issuer,   $tzTok) !== null, 'and still verifies on the one that issued it');

// The other direction: expiry must still actually bite.
$shortTok = recoveryIssueToken($issuer, 1, 'admin@test.example', 'self_service', null, 'email', 5);
$issuer->prepare("UPDATE password_resets SET expires_at = ? WHERE token_hash = ?")
       ->execute([date('Y-m-d H:i:s', time() - 60), hash('sha256', $shortTok)]);
ok(recoveryVerifyToken($verifier, $shortTok) === null, 'an expired token is still refused across zones');

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m── Result ──\033[0m\n";
echo "  \033[32m$pass passed\033[0m" . ($fail ? ", \033[31m$fail failed\033[0m" : '') . "\n\n";
exit($fail === 0 ? 0 : 1);
