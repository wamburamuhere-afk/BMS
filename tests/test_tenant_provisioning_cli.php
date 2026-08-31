<?php
/**
 * Multi-tenancy — Phase 2 (provisioning engine) CLI test
 *   php tests/test_tenant_provisioning_cli.php
 *
 * Provisions REAL throwaway tenants against the live MySQL server, proves the
 * guarantees, then tears everything down. Nothing is left behind on success or
 * on failure (a shutdown handler cleans up even if an assertion throws).
 *
 * What it proves:
 *   - a provisioned tenant gets its own database, its own MySQL user, the full
 *     schema and the default seed
 *   - the tenant's MySQL user can reach ITS OWN database and NOTHING ELSE —
 *     not `bms`, not another tenant's database (the core isolation promise)
 *   - the owner can authenticate with the password they chose
 *   - no business data from the seed leaks in (no sub-ledger customer names)
 *   - validation rejects bad and reserved subdomains
 *   - a duplicate subdomain is refused with no partial tenant created
 *   - a forced mid-provisioning failure rolls back COMPLETELY — no orphaned
 *     database, no orphaned MySQL user, no orphaned registry row
 *   - rollback never destroys a pre-existing database it merely refused to use
 *
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_crypto.php";
require_once "$root/core/tenant_provisioner.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

/** Everything this run creates, so teardown can be exhaustive. */
$created = ['tenants' => [], 'databases' => [], 'users' => []];

function teardown(): void
{
    global $created;
    try { $admin = getProvisioningPdo(); } catch (Throwable $e) { return; }
    foreach ($created['databases'] as $db) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $db)) { try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {} }
    }
    foreach ($created['users'] as $u) {
        try { $admin->exec("DROP USER IF EXISTS " . $admin->quote($u) . "@'%'"); } catch (Throwable $e) {}
    }
    if ($created['tenants']) {
        try {
            $cpdo = getControlPdo();
            $in = implode(',', array_fill(0, count($created['tenants']), '?'));
            $cpdo->prepare("DELETE FROM tenants WHERE id IN ($in)")->execute($created['tenants']);
            $cpdo->prepare("DELETE FROM tenant_provisioning_log WHERE tenant_id IN ($in)")->execute($created['tenants']);
        } catch (Throwable $e) {}
    }
    // Belt-and-braces: sweep any stray probe rows this suite could have made.
    try {
        getControlPdo()->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'ptest%'");
        getControlPdo()->exec("DELETE FROM tenants WHERE subdomain LIKE 'ptest%'");
    } catch (Throwable $e) {}
}

register_shutdown_function(function(){
    global $pass,$fail;
    teardown();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n";
});

/** Can $user@$pass reach $db at all? */
function canConnect(string $host, string $db, string $user, string $pass): bool
{
    try {
        $p = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $p->query('SELECT 1');
        return true;
    } catch (Throwable $e) { return false; }
}

try {
    $cpdo = getControlPdo();
    $host = controlDbSettings()['host'];
    $sfx  = bin2hex(random_bytes(3));

    section('1. Subdomain validation (no DB involved)');
    ok(tenantSubdomainError('ab') !== null,           'too short rejected');
    ok(tenantSubdomainError(str_repeat('a', 33)) !== null, 'too long rejected');
    ok(tenantSubdomainError('-abc') !== null,          'leading hyphen rejected');
    ok(tenantSubdomainError('abc-') !== null,          'trailing hyphen rejected');
    ok(tenantSubdomainError('ab--c') !== null,         'double hyphen rejected');
    ok(tenantSubdomainError('AB_C') !== null,          'underscore/uppercase rejected');
    ok(tenantSubdomainError('admin') !== null,         'reserved "admin" rejected');
    ok(tenantSubdomainError('www') !== null,           'reserved "www" rejected');
    ok(tenantSubdomainError('bms') !== null,           'reserved "bms" rejected');
    ok(tenantSubdomainError('kampuni-a') === null,     'valid subdomain accepted');

    section('2. Password generator');
    $pw = generateTenantDbPassword();
    ok(strlen($pw) === 24, 'default length is 24');
    ok(preg_match('/[\'"\\\\`]/', $pw) === 0, 'contains no quote, backslash or backtick');
    ok(generateTenantDbPassword() !== generateTenantDbPassword(), 'not repeatable');

    section('3. Provision tenant A (the happy path)');
    $subA = 'ptesta' . $sfx;
    $ownerPwA = 'OwnerPass!' . bin2hex(random_bytes(4));
    $a = provisionTenant('Alpha Traders Ltd', $subA, "owner@$subA.test", $ownerPwA);

    if (!$a['ok']) { echo "  provisioning error: {$a['error']}\n"; }
    ok($a['ok'] === true, 'provisionTenant reported success');
    ok(is_int($a['tenant_id']) && $a['tenant_id'] > 0, 'a tenant id was allocated');

    if ($a['ok']) {
        $created['tenants'][]   = $a['tenant_id'];
        $created['databases'][] = $a['db_name'];
        $created['users'][]     = $a['db_username'];
    } else {
        throw new RuntimeException('Cannot continue: tenant A did not provision.');
    }

    ok($a['db_name']     === 'bms_t' . $a['tenant_id'], "database named bms_t{id} ({$a['db_name']})");
    ok($a['db_username'] === 'bms_u' . $a['tenant_id'], "user named bms_u{id} ({$a['db_username']})");

    $admin = getProvisioningPdo();
    $dbExists = (bool)$admin->query("SELECT 1 FROM information_schema.schemata WHERE schema_name = " . $admin->quote($a['db_name']))->fetchColumn();
    ok($dbExists, 'the database physically exists');
    $userExists = (bool)$admin->query("SELECT 1 FROM mysql.user WHERE user = " . $admin->quote($a['db_username']))->fetchColumn();
    ok($userExists, 'the MySQL user physically exists');

    section('4. Tenant A schema + seed');
    $rowA = $cpdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $rowA->execute([$a['tenant_id']]); $rowA = $rowA->fetch();
    ok($rowA !== false, 'registry row exists');
    ok($rowA['status'] === 'active', 'new tenant is active (can log in immediately)');
    ok(!empty($rowA['activated_at']), 'activated_at stamped');
    ok(isEncryptedTenantSecret($rowA['db_password_encrypted']), 'db password stored encrypted');
    $pwA = decryptTenantSecret($rowA['db_password_encrypted']);
    ok(is_string($pwA) && $pwA !== '', 'db password decrypts');

    $ta = new PDO("mysql:host=$host;dbname={$a['db_name']};charset=utf8mb4", $a['db_username'], $pwA,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $objs = (int)$ta->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $ta->quote($a['db_name']))->fetchColumn();
    ok($objs >= 300, "full schema applied ($objs objects)");
    ok((int)$ta->query("SELECT COUNT(*) FROM accounts")->fetchColumn() === 105, 'chart of accounts seeded (105 structural)');
    ok((int)$ta->query("SELECT COUNT(*) FROM accounts WHERE is_subledger = 1")->fetchColumn() === 0,
        'NO sub-ledger accounts (no customer names leaked from Tenant #1)');
    ok((int)$ta->query("SELECT COUNT(*) FROM accounts WHERE opening_balance <> 0 OR current_balance <> 0")->fetchColumn() === 0,
        'every account starts at a zero balance');
    ok((int)$ta->query("SELECT COUNT(*) FROM permissions")->fetchColumn() > 100, 'permission catalogue seeded');
    ok((int)$ta->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn() > 0, 'role permissions seeded');
    ok((int)$ta->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === 0, 'ledger starts empty');

    section('5. Tenant A owner account');
    $owner = $ta->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    ok(count($owner) === 1, 'exactly one user exists');
    $owner = $owner[0];
    ok($owner['username'] === "owner@$subA.test", 'owner username is their email');
    ok((int)$owner['is_admin'] === 1, 'owner is an admin');
    ok((int)$owner['is_active'] === 1, 'owner is active (login.php checks this)');
    ok($owner['password'] !== $ownerPwA, 'password is not stored in plaintext');
    ok(password_verify($ownerPwA, $owner['password']), 'owner password verifies (login.php would accept it)');
    $adminRole = $ta->query("SELECT role_name FROM roles WHERE role_id = " . (int)$owner['role_id'])->fetchColumn();
    ok($adminRole === 'Admin', "owner is bound to the Admin role (got '$adminRole')");

    section('6. Provision tenant B, then prove ISOLATION');
    $subB = 'ptestb' . $sfx;
    $b = provisionTenant('Beta Holdings Ltd', $subB, "owner@$subB.test", 'OwnerPassB!123');
    ok($b['ok'] === true, 'tenant B provisioned');
    if (!$b['ok']) { echo "  error: {$b['error']}\n"; throw new RuntimeException('Tenant B failed.'); }
    $created['tenants'][]   = $b['tenant_id'];
    $created['databases'][] = $b['db_name'];
    $created['users'][]     = $b['db_username'];

    $rowB = $cpdo->prepare("SELECT db_password_encrypted FROM tenants WHERE id = ?");
    $rowB->execute([$b['tenant_id']]);
    $pwB = decryptTenantSecret((string)$rowB->fetchColumn());

    ok($a['db_name'] !== $b['db_name'], 'the two tenants have different databases');
    ok($pwA !== $pwB, 'the two tenants have different database passwords');

    // The whole point of database-per-tenant.
    ok(canConnect($host, $a['db_name'], $a['db_username'], $pwA), 'tenant A reaches its OWN database');
    ok(canConnect($host, $b['db_name'], $b['db_username'], $pwB), 'tenant B reaches its OWN database');
    ok(!canConnect($host, $b['db_name'], $a['db_username'], $pwA), "tenant A CANNOT reach tenant B's database");
    ok(!canConnect($host, $a['db_name'], $b['db_username'], $pwB), "tenant B CANNOT reach tenant A's database");
    ok(!canConnect($host, DB_NAME,       $a['db_username'], $pwA), 'tenant A CANNOT reach the main `' . DB_NAME . '` database');
    ok(!canConnect($host, controlDbName(), $a['db_username'], $pwA), 'tenant A CANNOT reach the control database');
    ok(!canConnect($host, $a['db_name'], $a['db_username'], $pwB), "tenant A's user is rejected with the wrong password");

    // Cross-database reads must fail even from an authenticated tenant session.
    $blocked = false;
    try { $ta->query("SELECT COUNT(*) FROM `{$b['db_name']}`.users"); }
    catch (Throwable $e) { $blocked = true; }
    ok($blocked, "tenant A's live connection cannot cross-query tenant B by qualified name");

    $blockedMain = false;
    try { $ta->query("SELECT COUNT(*) FROM `" . DB_NAME . "`.users"); }
    catch (Throwable $e) { $blockedMain = true; }
    ok($blockedMain, "tenant A's live connection cannot cross-query `" . DB_NAME . "`");

    section('7. Duplicate subdomain leaves nothing behind');
    $before = (int)$cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $dup = provisionTenant('Impostor Ltd', $subA, 'attacker@example.test', 'Password!123');
    ok($dup['ok'] === false, 'duplicate subdomain rejected');
    ok($dup['tenant_id'] === null, 'no tenant id allocated for the duplicate');
    ok((int)$cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === $before, 'no registry row created');
    ok(canConnect($host, $a['db_name'], $a['db_username'], $pwA), 'the original tenant A is untouched');

    section('8. Bad input is refused before anything is created');
    $before = (int)$cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    foreach ([
        ['', 'ptestx' . $sfx, 'a@b.test', 'Password!123', 'empty company name'],
        ['Co', 'admin', 'a@b.test', 'Password!123', 'reserved subdomain'],
        ['Co', 'ptesty' . $sfx, 'not-an-email', 'Password!123', 'invalid email'],
        ['Co', 'ptestz' . $sfx, 'a@b.test', 'short', 'weak password'],
    ] as [$c, $s, $e, $p, $label]) {
        $r = provisionTenant($c, $s, $e, $p);
        ok($r['ok'] === false && $r['tenant_id'] === null, "rejected: $label");
    }
    ok((int)$cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === $before,
        'none of the invalid attempts created a registry row');

    section('9. Forced mid-provisioning failure rolls back COMPLETELY');
    // Occupy the exact database name the next tenant will be given, so
    // provisioning fails AFTER the registry row is inserted. This is the real
    // late-failure path, not a simulated one.
    // Work out the next id EXACTLY. information_schema caches AUTO_INCREMENT and
    // goes stale after repeated inserts/deletes, which would make the squat below
    // miss its target and let provisioning succeed — the test would then pass
    // vacuously while proving nothing about rollback. Inserting a throwaway row
    // and deleting it is exact: AUTO_INCREMENT never rewinds on delete.
    $cpdo->prepare("
        INSERT INTO tenants (company_name, subdomain, db_host, db_name, db_username,
                             db_password_encrypted, status, owner_email)
        VALUES ('probe', ?, 'localhost', '', '', '', 'trial', 'probe@example.test')
    ")->execute(['ptestprobe' . $sfx]);
    $probeId = (int)$cpdo->lastInsertId();
    $cpdo->prepare("DELETE FROM tenants WHERE id = ?")->execute([$probeId]);
    $nextId  = $probeId + 1;
    $squatDb = 'bms_t' . $nextId;
    $admin->exec("DROP DATABASE IF EXISTS `$squatDb`");
    $admin->exec("CREATE DATABASE `$squatDb`");
    $admin->exec("CREATE TABLE `$squatDb`.`precious` (id INT)");
    $admin->exec("INSERT INTO `$squatDb`.`precious` VALUES (42)");
    $created['databases'][] = $squatDb;   // so an early failure still cleans it up

    $subF = 'ptestf' . $sfx;
    $before = (int)$cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $f = provisionTenant('Doomed Ltd', $subF, "owner@$subF.test", 'Password!123');

    // If the squat ever misses and provisioning unexpectedly SUCCEEDS, the tenant
    // it created must still be torn down — otherwise a failing test leaks a real
    // database and MySQL user onto the server.
    if ($f['ok']) {
        $created['tenants'][]   = $f['tenant_id'];
        $created['databases'][] = $f['db_name'];
        $created['users'][]     = $f['db_username'];
    }
    ok($f['ok'] === false, 'provisioning failed as forced');
    ok((int)$cpdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn() === $before,
        'NO orphaned registry row left behind');
    $st = $cpdo->prepare("SELECT COUNT(*) FROM tenants WHERE subdomain = ?");
    $st->execute([$subF]);
    ok((int)$st->fetchColumn() === 0, 'the failed subdomain is free to retry');
    $orphanUser = (bool)$admin->query("SELECT 1 FROM mysql.user WHERE user = " . $admin->quote('bms_u' . $nextId))->fetchColumn();
    ok(!$orphanUser, 'NO orphaned MySQL user left behind');

    // The guard said it would refuse to overwrite — it must not then delete it.
    $survived = false;
    try { $survived = (int)$admin->query("SELECT id FROM `$squatDb`.`precious`")->fetchColumn() === 42; }
    catch (Throwable $e) {}
    ok($survived, 'the pre-existing database it refused to use was NOT destroyed by rollback');

    // The audit trail must outlive the deleted tenant row.
    $st = $cpdo->prepare("SELECT COUNT(*) FROM tenant_provisioning_log WHERE subdomain = ? AND status IN ('failed','rolled_back')");
    $st->execute([$subF]);
    ok((int)$st->fetchColumn() > 0, 'the failure is recorded in tenant_provisioning_log');

    $admin->exec("DROP DATABASE IF EXISTS `$squatDb`");

    section('10. No password ever reaches the audit log');
    $st = $cpdo->prepare("SELECT COUNT(*) FROM tenant_provisioning_log WHERE message LIKE ?");
    $st->execute(['%' . $pwA . '%']);
    ok((int)$st->fetchColumn() === 0, "tenant A's database password appears nowhere in the log");
    $st->execute(['%' . $ownerPwA . '%']);
    ok((int)$st->fetchColumn() === 0, "the owner's password appears nowhere in the log");

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail === 0 ? 0 : 1);
