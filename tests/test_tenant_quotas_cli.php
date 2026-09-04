<?php
/**
 * tests/test_tenant_quotas_cli.php — Phase 12.A acceptance gate.
 *
 *   php tests/test_tenant_quotas_cli.php
 *
 * Proves the DATA layer, which ships enforcing nothing yet (12.B is where
 * add_user.php and the upload handlers start calling these):
 *   1. schema is idempotent, both live tenants stay unlimited
 *   2. bmsCurrentTenant() carries max_users/max_storage_mb with NO query change
 *      to tenant_resolver.php — proven, not assumed
 *   3. tenantStorageUsedBytes() sums exactly right across real seeded rows in
 *      multiple tables
 *   4. it fails OPEN per table — a dropped table degrades the total, never throws
 *   5. the 5-table undercount fix: real backfill from a real file on disk,
 *      idempotent, and a missing file is left at 0 rather than failing
 *   6. tenantActiveUserCount() counts only is_active=1, and the boundary — at
 *      the limit refuses one more, deactivating someone frees a seat
 *
 * Provisions one real throwaway tenant, seeds real rows and a real file on
 * disk, and removes all of it. CLI ONLY.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../core/control_db.php';
require_once __DIR__ . '/../core/tenant_crypto.php';
require_once __DIR__ . '/../core/tenant_provisioner.php';
require_once __DIR__ . '/../core/tenant_admin.php';
require_once __DIR__ . '/../core/tenant_quotas.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "\n          -> $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

echo "\nBMS — Phase 12.A: quota schema, resolution & the undercount fix\n";

$c = getControlPdo();
$probeDir = __DIR__ . '/../uploads/quota_test_probe_' . bin2hex(random_bytes(4));
$tenantId = null;

register_shutdown_function(function () use (&$tenantId, $probeDir) {
    if ($tenantId) {
        try {
            $t = getTenant($tenantId);
            if ($t) deleteTenant($tenantId, $t['company_name']);
        } catch (Throwable $e) { error_log('quota test cleanup: ' . $e->getMessage()); }
    }
    if (is_dir($probeDir)) {
        foreach (glob($probeDir . '/*') as $f) @unlink($f);
        @rmdir($probeDir);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
section('1. Schema is idempotent; both live tenants stay unlimited');

exec('php ' . escapeshellarg(__DIR__ . '/../scripts/setup_control_db.php') . ' 2>&1', $out1, $rc1);
exec('php ' . escapeshellarg(__DIR__ . '/../scripts/setup_control_db.php') . ' 2>&1', $out2, $rc2);
ok('setup_control_db.php runs cleanly twice in a row', $rc1 === 0 && $rc2 === 0);

$cols = $c->query("SELECT column_name FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = 'tenants'")->fetchAll(PDO::FETCH_COLUMN);
ok('tenants.max_users exists', in_array('max_users', $cols, true));
ok('tenants.max_storage_mb exists', in_array('max_storage_mb', $cols, true));

$realTenants = $c->query("SELECT max_users, max_storage_mb FROM tenants
                           WHERE status IN ('active','trial')")->fetchAll();
$allUnlimited = true;
foreach ($realTenants as $t) {
    if ($t['max_users'] !== null || $t['max_storage_mb'] !== null) $allUnlimited = false;
}
ok('every real tenant is unlimited by default (zero behaviour change)', $allUnlimited);

// ─────────────────────────────────────────────────────────────────────────────
section('2. Provision a real throwaway tenant');

$sub = 'quotatest' . bin2hex(random_bytes(3));
$r = provisionTenant('Quota Test Co', $sub, "owner@$sub.test", 'Password!123');
ok('tenant provisioned', $r['ok'] === true, (string)($r['error'] ?? ''));
if (!$r['ok']) { echo "\nCannot continue without a tenant.\n"; exit(1); }
$tenantId = (int)$r['tenant_id'];

// getTenant() deliberately never selects db_password_encrypted (no superadmin
// page has a reason to hold it) — this CLI test genuinely needs it to connect
// as the tenant, so it reads the control row directly, the same way
// tests/test_tenant_module_smoke_cli.php already does.
$tStmt = $c->prepare("SELECT * FROM tenants WHERE id = ?");
$tStmt->execute([$tenantId]);
$tRow = $tStmt->fetch();
$pw   = decryptTenantSecret((string)$tRow['db_password_encrypted']);
$pdo  = new PDO("mysql:host={$tRow['db_host']};dbname={$tRow['db_name']};charset=utf8mb4",
    $tRow['db_username'], $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ─────────────────────────────────────────────────────────────────────────────
section('3. bmsCurrentTenant() carries the new columns with NO resolver change');

$_SERVER['HTTP_HOST'] = $sub . '.' . (getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local');
require_once __DIR__ . '/../core/tenant_bootstrap.php';
$reqPdo = bmsConnectPdo();
$t = bmsCurrentTenant();
ok('the real request path resolved this tenant', ($t['id'] ?? null) === $tenantId);
ok('max_users key is present on the resolved row (SELECT * picked it up for free)',
   array_key_exists('max_users', $t));
ok('max_storage_mb key is present too', array_key_exists('max_storage_mb', $t));
ok('unset -> tenantUserLimit() is null (unlimited)', tenantUserLimit() === null);
ok('unset -> tenantStorageLimitMb() is null (unlimited)', tenantStorageLimitMb() === null);

$c->prepare("UPDATE tenants SET max_users = 3, max_storage_mb = 1 WHERE id = ?")->execute([$tenantId]);
$reqPdo = bmsConnectPdo();   // re-resolve, proving the change is picked up live
ok('after setting max_users=3, tenantUserLimit() reads 3', tenantUserLimit() === 3);
ok('after setting max_storage_mb=1, tenantStorageLimitBytes() reads 1 MiB',
   tenantStorageLimitBytes() === 1024 * 1024);

// ─────────────────────────────────────────────────────────────────────────────
section('4. Storage sum is exact across multiple real tables');

$pdo->exec("INSERT INTO documents (document_name, file_path, original_filename, file_size, file_type, uploaded_by)
            VALUES ('t1','uploads/x1.pdf','x1.pdf',1000,'pdf',1)");
$pdo->exec("INSERT INTO rfq_attachments (rfq_id, attachment_name, file_path, original_name, file_size)
            VALUES (1,'a','uploads/a.pdf','a.pdf',5000)");
$pdo->exec("INSERT INTO do_attachments (do_id, attachment_name, file_path, original_name, file_size)
            VALUES (1,'b','uploads/b.pdf','b.pdf',7500)");

ok('SUM across 3 real tables is exact (1000+5000+7500=13500)',
   tenantStorageUsedBytes($pdo) === 13500, 'got ' . tenantStorageUsedBytes($pdo));

// ─────────────────────────────────────────────────────────────────────────────
section('5. Fails OPEN per table, not all-or-nothing');

$pdo->exec("DROP TABLE rfq_attachments");
ok('dropping one table lowers the total by exactly its share, does not throw',
   tenantStorageUsedBytes($pdo) === 8500, 'got ' . tenantStorageUsedBytes($pdo));

// Restore it so later assertions (and any other suite run after this one) see
// a tenant whose schema still matches the template — this tenant is deleted at
// the end regardless, but a mid-test throw must not leave a half-broken DB.
$pdo->exec("CREATE TABLE rfq_attachments (
    attachment_id INT NOT NULL AUTO_INCREMENT, rfq_id INT NOT NULL,
    attachment_name VARCHAR(255) NOT NULL DEFAULT '', file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL DEFAULT '', file_size INT DEFAULT NULL,
    PRIMARY KEY (attachment_id))");

// ─────────────────────────────────────────────────────────────────────────────
section('6. The 5-table undercount fix — real file, real backfill, idempotent');

// Before the migration: these 5 tables have no file_size column at all, so
// contributing 0 is not a bug, it's the exact problem the migration exists to
// close — proven here, not assumed.
$before = null;
try { $pdo->query("SELECT file_size FROM customer_attachments LIMIT 1"); $before = 'has column already?!'; }
catch (Throwable $e) { $before = 'no file_size column yet, as expected pre-migration'; }
ok('customer_attachments has no file_size before the migration runs', str_contains($before, 'expected'));

if (!is_dir($probeDir)) mkdir($probeDir, 0755, true);
$realFile = $probeDir . '/realfile.bin';
file_put_contents($realFile, random_bytes(4321));
ok('a real 4321-byte test file exists on disk', filesize($realFile) === 4321);

$relPath = 'uploads/' . basename($probeDir) . '/realfile.bin';
$pdo->exec("INSERT INTO customer_attachments (customer_id, file_type, file_path)
            VALUES (1, 'ID Document', " . $pdo->quote($relPath) . ")");
$pdo->exec("INSERT INTO document_templates (template_name, file_path, file_type)
            VALUES ('missing tpl', 'uploads/does_not_exist_" . bin2hex(random_bytes(4)) . ".bin', 'pdf')");

putenv('TENANT_MIGRATION_DB_HOST=' . $tRow['db_host']);
putenv('TENANT_MIGRATION_DB_NAME=' . $tRow['db_name']);
putenv('TENANT_MIGRATION_DB_USER=' . $tRow['db_username']);
putenv('TENANT_MIGRATION_DB_PASS=' . $pw);
exec('php ' . escapeshellarg(__DIR__ . '/../migrations/tenant/2026_09_04_backfill_file_size_columns.php') . ' 2>&1', $mOut, $mRc);
putenv('TENANT_MIGRATION_DB_HOST'); putenv('TENANT_MIGRATION_DB_NAME');
putenv('TENANT_MIGRATION_DB_USER'); putenv('TENANT_MIGRATION_DB_PASS');
ok('the migration exits 0', $mRc === 0, implode("\n", $mOut));

$caSize = (int)$pdo->query("SELECT file_size FROM customer_attachments WHERE file_path = " . $pdo->quote($relPath))->fetchColumn();
ok('the row with a real file is backfilled to its exact size', $caSize === 4321, "got $caSize");

$dtSize = $pdo->query("SELECT file_size FROM document_templates WHERE template_name = 'missing tpl'")->fetchColumn();
ok('the row whose file is missing is left at 0, not failed', (int)$dtSize === 0);

exec('php ' . escapeshellarg(__DIR__ . '/../migrations/tenant/2026_09_04_backfill_file_size_columns.php') . ' 2>&1', $mOut2, $mRc2)
    ; // env vars already unset above — re-set for this second run
putenv('TENANT_MIGRATION_DB_HOST=' . $tRow['db_host']);
putenv('TENANT_MIGRATION_DB_NAME=' . $tRow['db_name']);
putenv('TENANT_MIGRATION_DB_USER=' . $tRow['db_username']);
putenv('TENANT_MIGRATION_DB_PASS=' . $pw);
exec('php ' . escapeshellarg(__DIR__ . '/../migrations/tenant/2026_09_04_backfill_file_size_columns.php') . ' 2>&1', $mOut3, $mRc3);
putenv('TENANT_MIGRATION_DB_HOST'); putenv('TENANT_MIGRATION_DB_NAME');
putenv('TENANT_MIGRATION_DB_USER'); putenv('TENANT_MIGRATION_DB_PASS');
ok('running the migration again is a clean no-op', $mRc3 === 0, implode("\n", $mOut3));

ok('the previously-skipped tables now contribute to the total',
   tenantStorageUsedBytes($pdo) === 8500 + 4321, 'got ' . tenantStorageUsedBytes($pdo));

// ─────────────────────────────────────────────────────────────────────────────
section('7. Active-only user counting, and the seat-limit boundary');

ok('a fresh tenant starts with exactly 1 (the owner)', tenantActiveUserCount($pdo) === 1);

$pdo->exec("INSERT INTO users (username, email, password, first_name, last_name, is_active) VALUES ('u2','u2@t.test','x','U','2',1)");
$pdo->exec("INSERT INTO users (username, email, password, first_name, last_name, is_active) VALUES ('u3','u3@t.test','x','U','3',1)");
$pdo->exec("INSERT INTO users (username, email, password, first_name, last_name, is_active) VALUES ('u4','u4@t.test','x','U','4',0)");   // inactive

ok('3 active + 1 inactive seeded -> active count is 3, not 4', tenantActiveUserCount($pdo) === 3);
ok('at the limit (3 of 3): no room for one more', tenantWithinUserLimit($pdo) === false);

$pdo->exec("UPDATE users SET is_active = 0 WHERE username = 'u3'");
ok('deactivating one user frees a seat', tenantActiveUserCount($pdo) === 2);
ok('  ...and there is room again', tenantWithinUserLimit($pdo) === true);

// ─────────────────────────────────────────────────────────────────────────────
section('8. Storage-limit boundary');

ok('well under the 1 MiB limit is fine', tenantWithinStorageLimit($pdo, 0) === true);
ok('adding enough to exceed 1 MiB is refused', tenantWithinStorageLimit($pdo, 2 * 1024 * 1024) === false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  $pass passed, $fail failed\n\n";
exit($fail === 0 ? 0 : 1);
