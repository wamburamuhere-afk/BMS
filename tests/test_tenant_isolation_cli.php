<?php
/**
 * Multi-tenancy — Phase 9 (isolation hardening) CLI test
 *   php tests/test_tenant_isolation_cli.php
 *
 * THE PROMISE THIS SUITE EXISTS TO PROVE: one company's data is unreachable
 * from another company's connection, session or hostname — not "filtered out",
 * unreachable. Every other suite tests that a feature works. This one tests
 * that the product's central claim to its customers is true.
 *
 * It is deliberately adversarial. Each section takes the position of a tenant
 * (or of someone holding a tenant's credentials) actively trying to reach
 * another tenant's data, and asserts the attempt fails:
 *
 *   1. two real tenants exist, physically separate
 *   2. tenant A's MySQL credentials cannot touch tenant B's database
 *   3. tenant A cannot read the platform's own metadata — the control registry
 *      (which holds EVERY tenant's encrypted credentials), mysql.*, or another
 *      session's queries
 *   4. the same primary key exists in both companies; id-guessing returns your
 *      own row and can never return the neighbour's
 *   5. the real bootstrap routes each hostname to its own database
 *   6. a session pinned to one tenant is refused on another tenant's hostname
 *   7. each tenant's ledger balances independently of the other's
 *   8. no plaintext tenant database password is recorded anywhere
 *   9. control-database privilege posture (advisory — see the section note)
 *
 * Per ternant.md this suite becomes permanent and should be re-run before every
 * release, like tests/test_project_scope_cli.php.
 *
 * Creates two throwaway tenants (databases + MySQL users) and removes them.
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir());

require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_crypto.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/financial_reports.php";

const ISO_BASE = 'bms.local';

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
/** Advisory output — reports posture without passing or failing a legitimate install. */
function note($m){ echo "  \033[33m•\033[0m $m\n"; }

$made  = ['databases' => [], 'users' => []];
$probe = null;

function teardown(): void
{
    global $made, $probe;
    if ($probe && is_file($probe)) @unlink($probe);
    try {
        $c = getControlPdo();
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'isotest%'");
        $c->exec("DELETE FROM tenant_migration_log   WHERE subdomain LIKE 'isotest%'");
        $c->exec("DELETE FROM tenant_admin_log       WHERE subdomain LIKE 'isotest%'");
        $c->exec("DELETE FROM registration_attempts  WHERE subdomain LIKE 'isotest%'");
        $c->exec("DELETE FROM tenants                WHERE subdomain LIKE 'isotest%'");
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

/**
 * Assert that an operation is REFUSED by the database server.
 *
 * The point is the refusal itself, so a returned value — any returned value —
 * is the failure. Written this way round because a silent success here is
 * exactly the bug this suite exists to catch, and a try/catch that swallows
 * everything would hide it.
 */
function refused(callable $fn, string $what): void
{
    try {
        $fn();
        ok(false, "$what — *** SUCCEEDED, THIS IS A DATA LEAK ***");
    } catch (Throwable $e) {
        ok(true, $what);
    }
}

/** A PDO connected as a tenant's own least-privilege MySQL user. */
function tenantPdo(array $t): PDO
{
    $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
    if ($pw === null) throw new RuntimeException("cannot decrypt credentials for tenant {$t['id']}");
    return new PDO(
        "mysql:host={$t['db_host']};dbname={$t['db_name']};charset=utf8mb4",
        $t['db_username'], $pw,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/** Full registry row, including the encrypted credential the panel never selects. */
function registryRow(int $id): array
{
    $st = getControlPdo()->prepare("SELECT * FROM tenants WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: [];
}

/** Run the REAL bootstrap for a hostname, in a subprocess. Returns [db, tenantId, raw]. */
function runBootstrap(string $host, array $env = [], ?int $sessionTenantId = null): array
{
    global $probe;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($host)
         . ' ' . escapeshellarg((string)($sessionTenantId ?? ''));
    foreach ($env as $k => $v) putenv("$k=$v");
    $raw = (string)shell_exec($cmd . ' 2>&1');
    foreach ($env as $k => $v) putenv($k);

    $db = $tenant = null;
    if (preg_match('/DB:([A-Za-z0-9_]*)\|TENANT:([0-9-]*)/', $raw, $m)) { $db = $m[1]; $tenant = $m[2]; }
    return ['db' => $db, 'tenant' => $tenant, 'raw' => $raw];
}

try {
    $cpdo = getControlPdo();
    $sfx  = bin2hex(random_bytes(3));

    // The probe is written at runtime so this suite stays self-contained.
    $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_iso_probe_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($probe, <<<'PHP'
<?php
// Throwaway probe: exercises the REAL bootstrap with a simulated host.
// (Never write a literal close-tag in here, not even inside a comment — it ends
// PHP mode mid-line and truncates the generated file.)
$root = getenv('BMS_ROOT');
$_SERVER['HTTP_HOST'] = $argv[1] ?? '';

// The CLI php.ini points session.save_path at a directory that does not exist,
// so without this override the session silently fails to open and the
// cross-tenant guard under test never runs.
if (($argv[2] ?? '') !== '') {
    ini_set('session.save_path', sys_get_temp_dir());
    session_start();
    if (session_status() !== PHP_SESSION_ACTIVE) { echo 'PROBE-ERROR:no-session'; exit; }
    $_SESSION['tenant_id'] = (int)$argv[2];
}

// HERMETIC ENV — do not remove. includes/config.php is per-environment and
// untracked, and on any machine with multi-tenancy switched on it putenv()s
// that machine's TENANT_MODE / TENANT_BASE_DOMAIN, overwriting whatever this
// case asked for. That silently turns a tenant host into a non-tenant host and
// makes isolation assertions pass for reasons unrelated to isolation. Snapshot
// what the harness set, let config.php define DB_*, then put it back.
$want = [];
foreach (['TENANT_MODE', 'TENANT_BASE_DOMAIN'] as $k) {
    $v = getenv($k);
    $want[$k] = ($v === false) ? null : $v;
}

require $root . '/includes/config.php';

foreach ($want as $k => $v) {
    if ($v === null) { putenv($k); } else { putenv("$k=$v"); }
}

require $root . '/core/tenant_bootstrap.php';
$pdo = bmsConnectPdo();
echo 'DB:' . $pdo->query('SELECT DATABASE()')->fetchColumn();
echo '|TENANT:' . (bmsCurrentTenantId() ?? '-');
PHP
    );
    putenv('BMS_ROOT=' . $root);

    // ────────────────────────────────────────────────────────────────────────
    section('1. Two real tenants, physically separate');

    $subA = 'isotesta' . $sfx;
    $subB = 'isotestb' . $sfx;
    $rA = provisionTenant('Isolation Alpha Ltd', $subA, "owner@$subA.test", 'Password!123');
    $rB = provisionTenant('Isolation Beta Ltd',  $subB, "owner@$subB.test", 'Password!123');
    ok($rA['ok'] && $rB['ok'], 'both tenants provisioned');
    if (!$rA['ok'] || !$rB['ok']) throw new RuntimeException($rA['error'] ?? $rB['error'] ?? 'provisioning failed');

    foreach ([$rA, $rB] as $r) { $made['databases'][] = $r['db_name']; $made['users'][] = $r['db_username']; }

    $A = registryRow((int)$rA['tenant_id']);
    $B = registryRow((int)$rB['tenant_id']);

    ok($A['db_name'] !== $B['db_name'], "separate databases ({$A['db_name']} vs {$B['db_name']})");
    ok($A['db_username'] !== $B['db_username'], "separate MySQL users ({$A['db_username']} vs {$B['db_username']})");
    ok($A['db_password_encrypted'] !== $B['db_password_encrypted'], 'separate database passwords');

    $pdoA = tenantPdo($A);
    $pdoB = tenantPdo($B);
    ok($pdoA->query('SELECT DATABASE()')->fetchColumn() === $A['db_name'], "A's credentials open A's database");
    ok($pdoB->query('SELECT DATABASE()')->fetchColumn() === $B['db_name'], "B's credentials open B's database");

    // ────────────────────────────────────────────────────────────────────────
    section("2. Tenant A's credentials cannot touch tenant B's database");
    // This is the foundation the whole product rests on. If any of these
    // succeed, nothing in the PHP layer can save the isolation promise.

    $bDb = $B['db_name'];
    refused(fn() => $pdoA->query("SELECT COUNT(*) FROM `$bDb`.users")->fetchColumn(),
        "cross-database SELECT on B's users table");
    refused(fn() => $pdoA->query("SELECT COUNT(*) FROM `$bDb`.customers")->fetchColumn(),
        "cross-database SELECT on B's customers table");
    refused(fn() => $pdoA->exec("USE `$bDb`"),
        "USE B's database");
    refused(fn() => $pdoA->exec("INSERT INTO `$bDb`.customers (customer_name) VALUES ('injected')"),
        "writing into B's database");
    refused(fn() => $pdoA->exec("DROP DATABASE `$bDb`"),
        "dropping B's database");
    refused(fn() => $pdoA->exec("GRANT ALL ON `$bDb`.* TO " . $pdoA->quote($A['db_username']) . "@'%'"),
        "granting itself access to B");

    $visible = $pdoA->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
    ok(!in_array($bDb, $visible, true), "B's database is not even listed in A's SHOW DATABASES");

    // POSITIVE CONTROL — this assertion is what stops the six refusals above
    // from passing vacuously. Every refused() would also "pass" if A's
    // connection were simply broken, so prove in the same breath that A's
    // connection is alive and fully privileged on its OWN database. The
    // refusals are therefore targeted, not blanket.
    $pdoA->exec("CREATE TABLE IF NOT EXISTS `iso_control_probe` (id INT PRIMARY KEY)");
    $pdoA->exec("INSERT INTO `iso_control_probe` (id) VALUES (1)");
    ok((int)$pdoA->query("SELECT COUNT(*) FROM `iso_control_probe`")->fetchColumn() === 1,
        'CONTROL: A can freely create, write and read inside its own database');
    $pdoA->exec("DROP TABLE `iso_control_probe`");

    // ────────────────────────────────────────────────────────────────────────
    section("3. Tenant A cannot read the platform's own metadata");
    // The control registry is the crown jewel: it holds EVERY tenant's
    // encrypted credentials. A tenant that could read it could, with the
    // TENANT_CRED_KEY, decrypt its way into every other company.

    refused(fn() => $pdoA->query('SELECT COUNT(*) FROM `' . controlDbName() . '`.tenants')->fetchColumn(),
        'reading the control registry (every tenant\'s credentials)');
    refused(fn() => $pdoA->query('SELECT COUNT(*) FROM `' . controlDbName() . '`.superadmins')->fetchColumn(),
        'reading the superadmin table');
    refused(fn() => $pdoA->query('SELECT user, authentication_string FROM mysql.user')->fetchAll(),
        'reading mysql.user (password hashes)');

    // Without PROCESS privilege a connection sees only its own threads, so one
    // tenant cannot watch another tenant's queries go by.
    $threads = $pdoA->query('SELECT user, db FROM information_schema.processlist')->fetchAll();
    $foreign = array_filter($threads, fn($t) => $t['db'] !== null && $t['db'] !== $A['db_name']);
    ok($foreign === [], "A sees no other tenant's database in the process list (no PROCESS privilege)");

    // ────────────────────────────────────────────────────────────────────────
    section('4. The same primary key in both companies — id guessing cannot cross');
    // The classic IDOR: guess a neighbour's row id. Both companies are given a
    // customer with the SAME id and different data, so a leak would be obvious
    // rather than coincidental.

    $sharedId = 4242;
    $pdoA->prepare('INSERT INTO customers (customer_id, customer_name) VALUES (?, ?)')
         ->execute([$sharedId, 'ALPHA-SECRET-' . $sfx]);
    $pdoB->prepare('INSERT INTO customers (customer_id, customer_name) VALUES (?, ?)')
         ->execute([$sharedId, 'BETA-SECRET-' . $sfx]);

    $seenByA = $pdoA->query("SELECT customer_name FROM customers WHERE customer_id = $sharedId")->fetchColumn();
    $seenByB = $pdoB->query("SELECT customer_name FROM customers WHERE customer_id = $sharedId")->fetchColumn();

    ok($seenByA === 'ALPHA-SECRET-' . $sfx, "id $sharedId read by A returns A's own row");
    ok($seenByB === 'BETA-SECRET-' . $sfx,  "id $sharedId read by B returns B's own row");
    ok($seenByA !== $seenByB, 'the same id means different data in each company');
    ok(strpos((string)$seenByA, 'BETA') === false, "A never sees B's value under any id");
    ok(strpos((string)$seenByB, 'ALPHA') === false, "B never sees A's value under any id");

    // ────────────────────────────────────────────────────────────────────────
    section('5. The real bootstrap routes each hostname to its own database');
    $mt = ['TENANT_MODE' => 'on', 'TENANT_BASE_DOMAIN' => ISO_BASE];

    $r = runBootstrap("$subA." . ISO_BASE, $mt);
    ok($r['db'] === $A['db_name'], "$subA." . ISO_BASE . " connects to {$A['db_name']}");
    ok($r['tenant'] === (string)$A['id'], 'the request is attributed to tenant A');

    $r = runBootstrap("$subB." . ISO_BASE, $mt);
    ok($r['db'] === $B['db_name'], "$subB." . ISO_BASE . " connects to {$B['db_name']}");
    ok($r['tenant'] === (string)$B['id'], 'the request is attributed to tenant B');

    // Inventing a neighbour-ish hostname must not fall back to anything.
    $r = runBootstrap("$subA-x." . ISO_BASE, $mt);
    ok($r['db'] === null, 'an invented hostname opens NO database at all');
    ok(strpos($r['raw'], 'Account not found') !== false, 'and is told the account does not exist');

    // ────────────────────────────────────────────────────────────────────────
    section("6. A session pinned to one tenant is refused on another's hostname");
    // Stealing or replaying a cookie across subdomains must not carry the
    // session with it.

    $r = runBootstrap("$subB." . ISO_BASE, $mt, (int)$A['id']);
    ok(strpos($r['raw'], 'sign in again') !== false, "A's session presented on B's host is refused");
    ok($r['db'] === null, "and no database is opened for it — B's data is never reached");

    $r = runBootstrap("$subB." . ISO_BASE, $mt, (int)$B['id']);
    ok($r['db'] === $B['db_name'], "B's own session on B's host still works (the guard is not indiscriminate)");

    // ────────────────────────────────────────────────────────────────────────
    section('7. Each tenant\'s ledger balances independently');
    // Per ternant.md: the guardrail must hold per tenant, not platform-wide.
    // A freshly provisioned company has an empty ledger, which is balanced.

    $balA = assertLedgerBalanced($pdoA);
    $balB = assertLedgerBalanced($pdoB);
    ok($balA['ledger_balanced'] === true, "tenant A's ledger balances (Dr {$balA['sum_debit']} = Cr {$balA['sum_credit']})");
    ok($balB['ledger_balanced'] === true, "tenant B's ledger balances (Dr {$balB['sum_debit']} = Cr {$balB['sum_credit']})");
    ok($balA['bs_balanced'] === true, "tenant A's balance sheet balances");
    ok($balB['bs_balanced'] === true, "tenant B's balance sheet balances");

    // Posting into one company must not move the other company's numbers.
    $accId = (int)$pdoA->query('SELECT account_id FROM accounts ORDER BY account_id LIMIT 1')->fetchColumn();
    $accId2 = (int)$pdoA->query('SELECT account_id FROM accounts ORDER BY account_id LIMIT 1 OFFSET 1')->fetchColumn();
    if ($accId && $accId2) {
        $pdoA->prepare("INSERT INTO journal_entries (entry_date, description, status) VALUES (CURDATE(), ?, 'posted')")
             ->execute(['isolation probe ' . $sfx]);
        $eid = (int)$pdoA->lastInsertId();
        $ins = $pdoA->prepare('INSERT INTO journal_entry_items (entry_id, account_id, type, amount) VALUES (?,?,?,?)');
        $ins->execute([$eid, $accId,  'debit',  150.00]);
        $ins->execute([$eid, $accId2, 'credit', 150.00]);

        $balA2 = assertLedgerBalanced($pdoA);
        $balB2 = assertLedgerBalanced($pdoB);
        ok($balA2['sum_debit'] > $balA['sum_debit'], "posting into A moved A's ledger (Dr now {$balA2['sum_debit']})");
        ok($balA2['ledger_balanced'] === true, "A's ledger still balances after the posting");
        ok($balB2['sum_debit'] === $balB['sum_debit'] && $balB2['sum_credit'] === $balB['sum_credit'],
            "B's ledger is COMPLETELY UNMOVED by A's posting");
    } else {
        ok(false, 'could not find two seeded accounts to post between');
    }

    // ────────────────────────────────────────────────────────────────────────
    section('8. No plaintext tenant database password is recorded anywhere');
    // core/tenant_provisioner.php's logProvisioningStep() carries the note
    // "NEVER pass a plaintext password as $message (audited in Phase 9)".
    // This is that audit.

    $pwA = decryptTenantSecret((string)$A['db_password_encrypted']);
    ok(is_string($pwA) && $pwA !== '', 'A\'s password decrypts (so the search below is meaningful)');

    ok(strpos((string)$A['db_password_encrypted'], (string)$pwA) === false,
        'the registry stores ciphertext, never the plaintext password');
    ok(strpos((string)$A['db_password_encrypted'], 'tenc:') === 0,
        'the stored credential carries the tenant ciphertext marker');

    foreach (['tenant_provisioning_log', 'tenant_admin_log', 'tenant_migration_log'] as $tbl) {
        $hit = false;
        try {
            $rows = $cpdo->query("SELECT * FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                foreach ($row as $v) {
                    if (is_string($v) && $v !== '' && strpos($v, $pwA) !== false) { $hit = true; break 2; }
                }
            }
        } catch (Throwable $e) { /* table absent on an older control DB */ }
        ok($hit === false, "no plaintext password anywhere in $tbl");
    }

    // Source-level: the audit above only proves this run was clean. This proves
    // the call sites cannot regress into passing the password as a log message.
    $provSrc = (string)@file_get_contents("$root/core/tenant_provisioner.php");
    ok(!preg_match('/logProvisioningStep\([^;]*\$dbPassword/s', $provSrc),
        'logProvisioningStep() is never called with the database password');
    ok(!preg_match('/\$step\(\s*[\'"][a-z_]+[\'"]\s*,\s*[\'"][a-z_]+[\'"]\s*,\s*\$dbPassword/s', $provSrc),
        'the provisioning step callback is never handed the database password');

    // ────────────────────────────────────────────────────────────────────────
    section('9. Control-database privilege posture');
    // ADVISORY, NOT A GATE. Which MySQL user the control database uses is an
    // environment fact, not a property of the code: local development
    // legitimately reuses the app's root/blank connection, while production
    // should run a dedicated least-privilege user. Asserting "never root" here
    // would fail on every developer's machine — the same mistake the old
    // "superadmins ships empty" assertion made. So: assert the CAPABILITY, and
    // report the posture.

    $before = getenv('CONTROL_DB_USER');
    putenv('CONTROL_DB_USER=bms_control_app');
    putenv('CONTROL_DB_PASS=irrelevant-for-this-assertion');
    $s = controlDbSettings();
    ok($s['user'] === 'bms_control_app' && $s['source'] === 'environment (CONTROL_DB_*)',
        'a dedicated control-DB user can be supplied by environment, without touching any file');
    if ($before === false) { putenv('CONTROL_DB_USER'); } else { putenv('CONTROL_DB_USER=' . $before); }
    putenv('CONTROL_DB_PASS');

    $live = controlDbSettings();
    note("this environment's control DB: user '{$live['user']}' on '{$live['name']}' via {$live['source']}");
    if (in_array(strtolower($live['user']), ['root', 'admin'], true)) {
        note("\033[0m  → acceptable locally; on production create a dedicated least-privilege user:");
        note("\033[0m    CREATE USER 'bms_control_app'@'localhost' IDENTIFIED BY '<pw>';");
        note("\033[0m    GRANT SELECT, INSERT, UPDATE, DELETE ON `" . $live['name'] . "`.* TO 'bms_control_app'@'localhost';");
        note("\033[0m    then set CONTROL_DB_USER / CONTROL_DB_PASS in the vhost (see conventions §9).");
    }

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getTraceAsString() . "\n";
}
