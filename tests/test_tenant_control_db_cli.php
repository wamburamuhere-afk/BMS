<?php
/**
 * Multi-tenancy — Phase 1 (control database) CLI test
 *   php tests/test_tenant_control_db_cli.php
 *
 * Verifies the tenant registry foundation:
 *   - bms_control exists with its 3 tables, correct shape, InnoDB
 *   - subdomain is UNIQUE (a duplicate signup cannot hijack a tenant)
 *   - provisioning log survives a rollback (tenant_id nullable, no FK)
 *   - tenant crypto round-trips, rejects tampering, never leaks plaintext
 *   - the two key domains (AI vs tenant) cannot decrypt each other
 *   - the master key is NEVER auto-generated
 *   - superadmins ships EMPTY (no default backdoor account)
 *   - a real tenant row can be written, read back and decrypted end-to-end
 *
 * Every write is rolled back. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_crypto.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }
function src($root,$rel){ $p="$root/$rel"; return is_file($p)?file_get_contents($p):''; }

$cpdo = null;
register_shutdown_function(function(){
    global $pass,$fail,$cpdo;
    if ($cpdo instanceof PDO && $cpdo->inTransaction()) $cpdo->rollBack();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n";
});

try {
    $db = controlDbName();

    section('1. Control database + tables');
    $cpdo = getControlPdo();
    ok($cpdo instanceof PDO, 'getControlPdo() returns a live connection');
    ok(controlDbReady() === true, 'controlDbReady() is true');
    ok($db === 'bms_control', "control database name defaults to bms_control (got '$db')");

    $tables = $cpdo->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = " . $cpdo->quote($db) . " AND table_type = 'BASE TABLE'
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['tenants','superadmins','tenant_provisioning_log'] as $t) {
        ok(in_array($t, $tables, true), "table $t exists");
    }

    $engines = $cpdo->query("
        SELECT table_name, engine FROM information_schema.tables
        WHERE table_schema = " . $cpdo->quote($db) . " AND table_type = 'BASE TABLE'
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok(count(array_unique(array_values($engines))) === 1 && in_array('InnoDB', $engines, true),
        'every control table is InnoDB (transactional)');

    section('2. tenants shape');
    $cols = $cpdo->query("
        SELECT column_name, is_nullable FROM information_schema.columns
        WHERE table_schema = " . $cpdo->quote($db) . " AND table_name = 'tenants'
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ([
        'id','company_name','subdomain','db_host','db_name','db_username',
        'db_password_encrypted','status','plan','owner_email','created_at',
        'activated_at','suspended_at',
    ] as $c) {
        ok(array_key_exists($c, $cols), "tenants.$c exists");
    }

    // db_name must be a stored column, never derived from the id — Tenant #1
    // keeps the legacy name `bms` while provisioned tenants get bms_t{id}.
    ok(($cols['db_name'] ?? '') === 'NO', 'tenants.db_name is NOT NULL (never computed from id)');

    $status = (string)$cpdo->query("
        SELECT column_type FROM information_schema.columns
        WHERE table_schema = " . $cpdo->quote($db) . " AND table_name='tenants' AND column_name='status'
    ")->fetchColumn();
    foreach (['trial','active','suspended','deleted'] as $s) {
        ok(strpos($status, "'$s'") !== false, "tenants.status accepts '$s'");
    }

    $idx = $cpdo->query("
        SELECT index_name, non_unique FROM information_schema.statistics
        WHERE table_schema = " . $cpdo->quote($db) . " AND table_name='tenants'
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok(isset($idx['uq_tenants_subdomain']) && (int)$idx['uq_tenants_subdomain'] === 0,
        'tenants.subdomain is UNIQUE');
    ok(isset($idx['idx_tenants_status']), 'tenants.status is indexed (Phase 8 loops active tenants)');

    section('3. Provisioning log survives a rollback');
    // tenant_id must be nullable and carry NO foreign key: Phase 2 logs steps
    // before the tenants row exists, and its rollback deletes that row while the
    // record of WHY it failed has to survive.
    $plCols = $cpdo->query("
        SELECT column_name, is_nullable FROM information_schema.columns
        WHERE table_schema = " . $cpdo->quote($db) . " AND table_name='tenant_provisioning_log'
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    ok(($plCols['tenant_id'] ?? '') === 'YES', 'tenant_provisioning_log.tenant_id is NULLable');

    $fks = (int)$cpdo->query("
        SELECT COUNT(*) FROM information_schema.referential_constraints
        WHERE constraint_schema = " . $cpdo->quote($db) . " AND table_name='tenant_provisioning_log'
    ")->fetchColumn();
    ok($fks === 0, 'tenant_provisioning_log has no FK (evidence outlives a rolled-back tenant)');

    // Prove it for real: log against a tenant_id that does not exist.
    $cpdo->beginTransaction();
    $cpdo->prepare("INSERT INTO tenant_provisioning_log (tenant_id, subdomain, step, status, message)
                    VALUES (?,?,?,?,?)")
         ->execute([999999, 'ghost', 'create_database', 'failed', 'orphan-log probe']);
    ok((int)$cpdo->lastInsertId() > 0, 'a log row for a non-existent tenant_id is accepted');
    $cpdo->rollBack();

    section('4. Tenant crypto');
    ok(tenantCredKeyAvailable() === true, 'TENANT_CRED_KEY is configured on this environment');
    ok(strlen(tenantCredKeyRaw()) === 32, 'master key is exactly 32 bytes (AES-256)');

    $plain = 'tenant-pw-' . bin2hex(random_bytes(8));
    $enc   = encryptTenantSecret($plain);
    ok(isEncryptedTenantSecret($enc), 'encryptTenantSecret produces a tenc:v1 token');
    ok(strpos($enc, $plain) === false, 'ciphertext does not contain the plaintext');
    ok(decryptTenantSecret($enc) === $plain, 'decryptTenantSecret round-trips exactly');
    ok(encryptTenantSecret($plain) !== encryptTenantSecret($plain),
        'same plaintext encrypts differently each time (random nonce)');
    ok(decryptTenantSecret('tenc:v1:' . base64_encode(random_bytes(40))) === null,
        'garbage token rejected (null)');
    ok(decryptTenantSecret('not-a-token') === null, 'non-token rejected');
    ok(encryptTenantSecret('') === '', 'empty string encrypts to empty (no useless ciphertext)');

    // Authenticated encryption: flipping one ciphertext byte must fail, not decode garbage.
    $raw = base64_decode(substr($enc, 8), true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);
    ok(decryptTenantSecret('tenc:v1:' . base64_encode($raw)) === null,
        'tampered ciphertext rejected (GCM auth tag holds)');

    section('5. Key domains are separated');
    require_once "$root/core/crypto.php";
    ok(decryptTenantSecret(encryptSecret($plain)) === null,
        'an AI-key token cannot be read by the tenant key domain');
    ok(decryptSecret($enc) === null,
        'a tenant token cannot be read by the AI key domain');

    // The whole point of this file existing separately: it must NEVER mint a key.
    // core/crypto.php's aiAppSecret() deliberately does; doing so here would
    // silently orphan every stored tenant credential at once.
    $cryptoSrc = src($root, 'core/tenant_crypto.php');
    ok(strpos($cryptoSrc, 'file_put_contents') === false,
        'tenant_crypto.php never writes a key file (no silent regeneration)');
    ok(strpos($cryptoSrc, 'random_bytes(32)') === false,
        'tenant_crypto.php never generates a 32-byte key');
    ok(strpos($cryptoSrc, 'RuntimeException') !== false,
        'a missing key throws loudly rather than degrading');

    section('6. No default backdoor account');
    $sa = (int)$cpdo->query("SELECT COUNT(*) FROM superadmins")->fetchColumn();
    ok($sa === 0, "superadmins ships empty (found $sa) — no shipped credentials into every tenant");

    section('7. End-to-end: write, read back, decrypt a real tenant row');
    $cpdo->beginTransaction();
    $sub = 'ttest' . bin2hex(random_bytes(4));
    $pw  = 'Pw-' . bin2hex(random_bytes(12));
    $cpdo->prepare("
        INSERT INTO tenants (company_name, subdomain, db_host, db_name, db_username,
                             db_password_encrypted, status, owner_email)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute(['Phase 1 Test Co', $sub, 'localhost', 'bms_t_probe', 'bms_u_probe',
                 encryptTenantSecret($pw), 'trial', 'owner@example.test']);

    $id = (int)$cpdo->lastInsertId();
    ok($id > 0, 'tenant row inserted');

    $row = $cpdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $row->execute([$id]);
    $row = $row->fetch();
    ok($row !== false, 'tenant row reads back');
    ok($row['status'] === 'trial', "status defaults sensibly (got '{$row['status']}')");
    ok(!empty($row['created_at']), 'created_at auto-populates');
    ok($row['activated_at'] === null && $row['suspended_at'] === null,
        'lifecycle timestamps start NULL');
    ok(isEncryptedTenantSecret($row['db_password_encrypted']),
        'stored password is a ciphertext token, not plaintext');
    ok(strpos($row['db_password_encrypted'], $pw) === false,
        'the plaintext password is nowhere in the stored value');
    ok(decryptTenantSecret($row['db_password_encrypted']) === $pw,
        'password decrypts back to the original after a DB round trip');

    // A duplicate subdomain must be impossible — this is what stops one signup
    // from hijacking another company's tenant.
    $dupBlocked = false;
    try {
        $cpdo->prepare("
            INSERT INTO tenants (company_name, subdomain, db_host, db_name, db_username,
                                 db_password_encrypted, status, owner_email)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute(['Impostor Ltd', $sub, 'localhost', 'bms_t_dupe', 'bms_u_dupe',
                     encryptTenantSecret('x'), 'trial', 'attacker@example.test']);
    } catch (PDOException $e) {
        $dupBlocked = ((string)$e->getCode() === '23000');
    }
    ok($dupBlocked, 'duplicate subdomain rejected by the unique constraint');

    $cpdo->rollBack();
    ok(!$cpdo->inTransaction(), 'test transaction rolled back');

    $left = (int)$cpdo->query("SELECT COUNT(*) FROM tenants WHERE subdomain LIKE 'ttest%'")->fetchColumn();
    ok($left === 0, 'no test tenant rows persisted');

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail === 0 ? 0 : 1);
