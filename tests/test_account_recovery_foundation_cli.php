<?php
/**
 * Account recovery — Phase 0 (foundation) CLI test
 *   php tests/test_account_recovery_foundation_cli.php
 *
 * What it proves:
 *   - accountRecoveryEnsureSchema() creates password_resets,
 *     password_reset_attempts, the two new users columns and the lookup indexes
 *   - it is idempotent — running it twice changes nothing and throws nothing
 *   - the backfill sorts `username` by what it IS: an email-shaped username
 *     becomes users.email, a phone-shaped one becomes users.phone
 *   - the backfill NEVER overwrites a contact that is already set
 *   - an admin whose username was a phone is left with NO email (correct: there
 *     is genuinely no address for them) and is reported by
 *     accountsWithoutRecoveryContact()
 *   - username becomes UNIQUE when the data allows it
 *   - when duplicate usernames exist the index is NOT added and the condition
 *     is REPORTED — a migration must not silently rename a live login
 *
 * Builds throwaway databases and drops them again. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/includes/config.php";
require_once "$root/core/account_recovery_schema.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$made = [];

function teardown(): void
{
    global $made;
    try {
        $admin = new PDO('mysql:host=' . DB_SERVER . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($made as $db) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $db)) {
                try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {}
}
register_shutdown_function('teardown');

/** The real users table, copied from schema/tenant_schema_template.sql. */
const USERS_DDL = "
    CREATE TABLE `users` (
      `username` varchar(50) DEFAULT NULL,
      `password` varchar(255) DEFAULT NULL,
      `email` varchar(100) DEFAULT NULL,
      `phone` varchar(30) DEFAULT NULL,
      `role` varchar(100) DEFAULT NULL,
      `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
      `user_id` int NOT NULL AUTO_INCREMENT,
      `is_admin` int NOT NULL DEFAULT '0',
      `user_role` varchar(50) DEFAULT NULL,
      `first_name` varchar(100) DEFAULT NULL,
      `last_name` varchar(100) DEFAULT NULL,
      `last_login` datetime DEFAULT NULL,
      `is_active` int NOT NULL DEFAULT '1',
      `role_id` int DEFAULT NULL,
      `password_changed_at` datetime DEFAULT NULL,
      `updated_at` datetime DEFAULT NULL,
      PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC
";

function freshDb(string $suffix): PDO
{
    global $made;
    $db = 'bms_artest_' . $suffix . '_' . random_int(100000, 999999);
    $admin = new PDO('mysql:host=' . DB_SERVER . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
    $made[] = $db;
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=$db;charset=utf8mb4", DB_USERNAME, DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(USERS_DDL);
    return $pdo;
}

function cols(PDO $p): array { return array_flip($p->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN)); }
function hasIndex(PDO $p, string $name): bool { return (bool)$p->query("SHOW INDEX FROM `users` WHERE Key_name = " . $p->quote($name))->fetch(); }
function tableExists(PDO $p, string $t): bool { return (bool)$p->query("SHOW TABLES LIKE " . $p->quote($t))->fetch(); }
function userRow(PDO $p, int $id): array { $s = $p->prepare("SELECT * FROM users WHERE user_id = ?"); $s->execute([$id]); return $s->fetch(PDO::FETCH_ASSOC) ?: []; }

echo "\n\033[1mAccount recovery — Phase 0 foundation\033[0m\n";

// ─────────────────────────────────────────────────────────────────────────────
section('Clean tenant: schema is created');

$pdo = freshDb('clean');

// A — operator-created owner: username IS the email, email column left empty
$pdo->exec("INSERT INTO users (user_id, username, email, phone, is_admin, is_active, first_name)
            VALUES (1, 'owner@example.com', '', NULL, 1, 1, 'Owner')");
// B — self-registered owner: username is the PHONE, no email anywhere
$pdo->exec("INSERT INTO users (user_id, username, email, phone, is_admin, is_active, first_name)
            VALUES (2, '0759086682', '', NULL, 1, 1, 'Phone')");
// C — ordinary staff with both contacts already set: must not be touched
$pdo->exec("INSERT INTO users (user_id, username, email, phone, is_admin, is_active, first_name)
            VALUES (3, 'staff1', 'keep@example.com', '0712345678', 0, 1, 'Staff')");
// D — phone-shaped username but a DIFFERENT phone already on file
$pdo->exec("INSERT INTO users (user_id, username, email, phone, is_admin, is_active, first_name)
            VALUES (4, '0700000000', '', '0711111111', 1, 1, 'Keep')");
// E — inactive admin with no email: must NOT be reported (cannot log in anyway)
$pdo->exec("INSERT INTO users (user_id, username, email, phone, is_admin, is_active, first_name)
            VALUES (5, 'retired', '', NULL, 1, 0, 'Retired')");

$notes = accountRecoveryEnsureSchema($pdo);

ok(tableExists($pdo, 'password_resets'),          'password_resets created');
ok(tableExists($pdo, 'password_reset_attempts'),  'password_reset_attempts created');

$c = cols($pdo);
ok(isset($c['must_change_password']), 'users.must_change_password added');
ok(isset($c['email_verified_at']),    'users.email_verified_at added');
ok(hasIndex($pdo, 'idx_users_email'), 'users email index added');
ok(hasIndex($pdo, 'idx_users_phone'), 'users phone index added');

section('password_resets shape');

$prCols = array_flip($pdo->query("SHOW COLUMNS FROM `password_resets`")->fetchAll(PDO::FETCH_COLUMN));
foreach (['token_hash', 'channel', 'kind', 'issued_by', 'expires_at', 'used_at', 'destination', 'request_ip'] as $col) {
    ok(isset($prCols[$col]), "password_resets.$col present");
}
$tokIdx = $pdo->query("SHOW INDEX FROM `password_resets` WHERE Key_name = 'uq_pr_token'")->fetch(PDO::FETCH_ASSOC);
ok($tokIdx && (int)$tokIdx['Non_unique'] === 0, 'token_hash is UNIQUE (single-use is enforceable)');

$chan = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'
                        AND COLUMN_NAME = 'channel'")->fetchColumn();
ok(strpos((string)$chan, 'sms') !== false, "channel ENUM already allows 'sms' (no redesign when a gateway arrives)");

$kind = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'
                        AND COLUMN_NAME = 'kind'")->fetchColumn();
ok(strpos((string)$kind, 'operator_otp') !== false, "kind ENUM carries the operator OTP flow too");

// ─────────────────────────────────────────────────────────────────────────────
section('Backfill: username sorted by what it actually is');

$a = userRow($pdo, 1);
ok($a['email'] === 'owner@example.com', 'email-shaped username recovered into users.email');
ok(($a['phone'] ?? '') === '' || $a['phone'] === null, 'email-shaped username did NOT become a phone');

$b = userRow($pdo, 2);
ok($b['phone'] === '0759086682', 'phone-shaped username recovered into users.phone');
ok(($b['email'] ?? '') === '', 'phone-shaped username did NOT become an email (no address invented)');

$cRow = userRow($pdo, 3);
ok($cRow['email'] === 'keep@example.com', 'existing email left untouched');
ok($cRow['phone'] === '0712345678',       'existing phone left untouched');

$d = userRow($pdo, 4);
ok($d['phone'] === '0711111111', 'backfill never overwrites a phone already on file');

section('Stranded admins are reported, not guessed at');

$stranded = accountsWithoutRecoveryContact($pdo);
$ids = array_column($stranded, 'user_id');
sort($ids);
ok($ids === [2, 4], 'exactly the active admins with no email are reported (2 and 4)');
ok(!in_array(5, $ids, true), 'inactive admin is not reported');
ok(!in_array(3, $ids, true), 'non-admin staff is not reported');
$warned = false;
foreach ($notes as $n) if (strpos($n, 'WARNING') === 0 && strpos($n, 'no email') !== false) $warned = true;
ok($warned, 'the migration itself warns about stranded admins');

section('username UNIQUE');

ok(hasIndex($pdo, 'uq_users_username'), 'UNIQUE key added when data allows it');
$dupBlocked = false;
try {
    $pdo->exec("INSERT INTO users (username, is_admin, is_active) VALUES ('staff1', 0, 1)");
} catch (PDOException $e) { $dupBlocked = true; }
ok($dupBlocked, 'a duplicate username is now rejected by the database');

// ─────────────────────────────────────────────────────────────────────────────
section('Idempotent: running twice is a no-op');

$before = $pdo->query("SELECT user_id, username, email, phone FROM users ORDER BY user_id")->fetchAll(PDO::FETCH_ASSOC);
$threw = false;
try { accountRecoveryEnsureSchema($pdo); } catch (Throwable $e) { $threw = true; echo "     {$e->getMessage()}\n"; }
ok(!$threw, 'second run does not throw');
$after = $pdo->query("SELECT user_id, username, email, phone FROM users ORDER BY user_id")->fetchAll(PDO::FETCH_ASSOC);
ok($before === $after, 'second run changes no data');

// ─────────────────────────────────────────────────────────────────────────────
section('Duplicate usernames: reported, never silently rewritten');

$dup = freshDb('dupes');
$dup->exec("INSERT INTO users (user_id, username, email, is_admin, is_active) VALUES (1, 'same', 'a@example.com', 1, 1)");
$dup->exec("INSERT INTO users (user_id, username, email, is_admin, is_active) VALUES (2, 'same', 'b@example.com', 0, 1)");

$dupThrew = false;
$dupNotes = [];
try { $dupNotes = accountRecoveryEnsureSchema($dup); } catch (Throwable $e) { $dupThrew = true; echo "     {$e->getMessage()}\n"; }

ok(!$dupThrew, 'migration does NOT throw on duplicates (a good deploy is not halted by data needing a human)');
ok(!hasIndex($dup, 'uq_users_username'), 'UNIQUE key correctly withheld while duplicates exist');
$dupWarned = false;
foreach ($dupNotes as $n) if (strpos($n, 'WARNING') === 0 && strpos($n, 'UNIQUE') !== false) $dupWarned = true;
ok($dupWarned, 'the duplicate condition is reported to the operator');
$rows = $dup->query("SELECT username FROM users ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN);
ok($rows === ['same', 'same'], 'neither login name was rewritten');
ok(tableExists($dup, 'password_resets'), 'the rest of the schema still landed');

// ─────────────────────────────────────────────────────────────────────────────
echo "\n\033[1m── Result ──\033[0m\n";
echo "  \033[32m$pass passed\033[0m" . ($fail ? ", \033[31m$fail failed\033[0m" : '') . "\n\n";
exit($fail === 0 ? 0 : 1);
