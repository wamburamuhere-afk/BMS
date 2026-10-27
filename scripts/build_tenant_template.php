<?php
/**
 * scripts/build_tenant_template.php — refresh the new-company template.
 *
 *   php scripts/build_tenant_template.php
 *
 * A new company is built from schema/tenant_schema_template.sql +
 * schema/tenant_seed_defaults.sql, then provisionTenant() runs every
 * migrations/tenant/ file the template has not recorded in schema_migrations
 * — one PHP subprocess each. With an old template that is every migration
 * since the snapshot (91 on 2026-10-27, ~32 s of a signup) and it grows with
 * each new migration.
 *
 * This script applies the current template + seed + geography to a scratch
 * database, applies the account-recovery schema and every tenant migration
 * exactly as provisioning would, then writes the result back:
 *   - tenant_schema_template.sql  ← structure of every table (no data)
 *   - tenant_seed_defaults.sql    ← data of every table EXCEPT the geography
 *                                   tables (they stay in their own file), and
 *                                   including schema_migrations, so provisioning
 *                                   finds nothing pending.
 * The scratch database is dropped afterwards. No company, owner or setting of
 * any real tenant is read — the source is only the files above, so nothing
 * tenant-specific can leak into the template.
 *
 * Run it on a dev machine and commit the two files; the server never runs it.
 * Migrations added later still run at signup until the next rebuild.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_migration_runner.php";
require_once "$root/core/account_recovery_schema.php";

$schemaDir = "$root/schema";
$geoTables = ['countries', 'regions', 'districts', 'wards', 'villages'];

$mysqldump = getenv('MYSQLDUMP') ?: 'mysqldump';
exec(escapeshellarg($mysqldump) . ' --version 2>&1', $o, $rc);
if ($rc !== 0) { fwrite(STDERR, "mysqldump not found — set MYSQLDUMP=/path/to/mysqldump\n"); exit(1); }

$s     = controlDbSettings();
$admin = getProvisioningPdo();
$db    = 'bms_tplbuild_' . bin2hex(random_bytes(4));

$cleanup = function () use ($admin, $db) {
    try { $admin->exec("DROP DATABASE IF EXISTS `$db`"); } catch (Throwable $e) {}
};
register_shutdown_function($cleanup);

echo "Scratch database: $db\n";
$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$tpdo = new PDO("mysql:host={$s['host']};dbname={$db};charset=utf8mb4", $s['user'], $s['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

foreach (['tenant_schema_template.sql', 'tenant_seed_defaults.sql', 'tenant_geography_seed.sql'] as $f) {
    $tpdo->exec(file_get_contents("$schemaDir/$f"));
    echo "  applied $f\n";
}
accountRecoveryEnsureSchema($tpdo);
echo "  applied account-recovery schema\n";

$tpdo->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
        `migration_name` VARCHAR(191) NOT NULL,
        `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uq_schema_migrations_name` (`migration_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
");
$done   = $tpdo->query("SELECT migration_name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$record = $tpdo->prepare("INSERT IGNORE INTO schema_migrations (migration_name) VALUES (?)");
$applied = 0;
foreach (tenantMigrationFiles() as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) continue;
    // Same isolated subprocess + env contract as runTenantMigrations().
    putenv("TENANT_MIGRATION_DB_HOST={$s['host']}");
    putenv("TENANT_MIGRATION_DB_NAME=$db");
    putenv("TENANT_MIGRATION_DB_USER={$s['user']}");
    putenv("TENANT_MIGRATION_DB_PASS={$s['pass']}");
    $out = []; $code = 0;
    exec(escapeshellarg(tenantMigrationPhpBinary()) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Migration $name failed — template NOT written:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    $record->execute([$name]);
    $applied++;
}
foreach (['HOST', 'NAME', 'USER', 'PASS'] as $k) putenv("TENANT_MIGRATION_DB_$k");
echo "  applied $applied migration(s)\n";

// Credentials for mysqldump go through a temporary option file, never argv.
$cnf = tempnam(sys_get_temp_dir(), 'tplcnf');
file_put_contents($cnf, "[client]\nhost={$s['host']}\nuser={$s['user']}\npassword=\"" . addcslashes($s['pass'], "\"\\") . "\"\n");
$dump = function (string $args, string $outFile) use ($mysqldump, $cnf, $db) {
    $cmd = escapeshellarg($mysqldump) . ' --defaults-extra-file=' . escapeshellarg($cnf)
         // --skip-tz-utc: timestamps are written as stored, not shifted to UTC.
         . " --single-transaction --skip-dump-date --skip-triggers --no-tablespaces --set-gtid-purged=OFF --skip-tz-utc $args "
         . escapeshellarg($db) . ' > ' . escapeshellarg($outFile) . ' 2>&1';
    exec($cmd, $o, $rc);
    if ($rc !== 0) { fwrite(STDERR, "mysqldump failed:\n" . file_get_contents($outFile) . "\n"); exit(1); }
};

$tmpSchema = "$schemaDir/.tenant_schema_template.sql.new";
$tmpSeed   = "$schemaDir/.tenant_seed_defaults.sql.new";
$dump('--no-data', $tmpSchema);
$ignore = implode(' ', array_map(fn($t) => '--ignore-table=' . escapeshellarg("$db.$t"), $geoTables));
// One row per INSERT, column names included — the same shape as the
// hand-made seed, so diffs stay readable and row-level checks keep working.
$dump("--no-create-info --complete-insert --skip-extended-insert $ignore", $tmpSeed);
@unlink($cnf);

// The dump names the scratch database in its header; nothing in the body does.
foreach ([$tmpSchema, $tmpSeed] as $f) {
    file_put_contents($f, str_replace($db, 'tenant', file_get_contents($f)));
}
$seedHeader = <<<TXT
-- ============================================================================
-- BMS — Default seed data for a newly provisioned tenant database
-- ============================================================================
-- GENERATED by scripts/build_tenant_template.php — do not edit by hand; edit
-- the source data or add a migration under migrations/tenant/ and rebuild.
--
-- Built from the previous seed (accounting taxonomy, structural chart of
-- accounts, permission catalogue, roles + role_permissions — no users, no
-- company identity, no secrets, no sub-ledger accounts) plus everything every
-- tenant migration adds to a brand-new company. schema_migrations lists the
-- migrations already included, so provisioning does not run them again.
-- Geography lives in tenant_geography_seed.sql.
-- ============================================================================

TXT;
file_put_contents($tmpSeed, $seedHeader . file_get_contents($tmpSeed));

rename($tmpSchema, "$schemaDir/tenant_schema_template.sql");
rename($tmpSeed, "$schemaDir/tenant_seed_defaults.sql");
$n = (int)$tpdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
echo "Wrote schema/tenant_schema_template.sql and schema/tenant_seed_defaults.sql ($n migrations recorded).\n";
