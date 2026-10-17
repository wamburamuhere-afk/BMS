<?php
/**
 * tenantMigrationHealth() — a failure counts only while it is the LATEST event
 * for that migration; a failure later fixed by a successful re-run is history.
 *   php tests/test_tenant_migration_health_cli.php
 * Writes rows for throwaway tenant ids into tenant_migration_log; removes them. Exit 0 = pass.
 */
$root = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir());
@session_start();

if (!is_file("$root/includes/config.php")) { echo "SKIP: needs the control database (no includes/config.php — CI)\n"; exit(0); }
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_admin.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  ✅ $m\n"; } else { $fail++; echo "  ❌ $m\n"; } }

const TID_FIXED = 990001, TID_OPEN = 990002, TID_CONN = 990003, TID_OTHER = 990004, TID_EMPTY = 990005;

try {
    $c = getControlPdo();
} catch (Throwable $e) {
    echo "SKIP: control database not reachable ({$e->getMessage()})\n";
    exit(0);
}

$cleanup = function () use ($c) {
    $c->exec("DELETE FROM tenant_migration_log WHERE tenant_id BETWEEN 990001 AND 990005");
};
$cleanup();
register_shutdown_function(function () use ($cleanup) {
    global $pass, $fail;
    $cleanup();
    echo "\nPasses: $pass\nFailures: $fail\n";
    exit($fail === 0 ? 0 : 1);
});

$log = function (int $tid, string $name, string $status, ?string $msg = null) use ($c) {
    $c->prepare("INSERT INTO tenant_migration_log (tenant_id, subdomain, migration_name, status, message) VALUES (?, 'mhtest', ?, ?, ?)")
      ->execute([$tid, $name, $status, $msg]);
};

// Failed, then fixed by a later successful run (the 2026-10-13 duplicate-column case).
$log(TID_FIXED, 'a.php', 'ok');
$log(TID_FIXED, 'b.php', 'failed', 'Duplicate column name discount_percent');
$log(TID_FIXED, 'b.php', 'ok');
$h = tenantMigrationHealth(TID_FIXED);
ok($h['failed'] === 0, 'failure later fixed by a re-run is not counted');
ok($h['last_failure'] === null, 'no red "last failure" for a fixed migration');
ok(($h['last_resolved']['migration_name'] ?? '') === 'b.php', 'the fixed failure is reported as resolved history');
ok($h['total'] === 2, 'total = distinct migrations applied (2), not log rows (3)');

// Succeeded once, then failed on a later run — still open.
$log(TID_OPEN, 'a.php', 'ok');
$log(TID_OPEN, 'c.php', 'failed', 'boom');
$h = tenantMigrationHealth(TID_OPEN);
ok($h['failed'] === 1, 'a failure that is the latest event is counted');
ok(($h['last_failure']['migration_name'] ?? '') === 'c.php', 'open failure is shown as last failure');
ok($h['last_resolved'] === null, 'no resolved note while a failure is open');

// Connection failure '(all)' followed by a successful run of any migration.
$log(TID_CONN, '(all)', 'failed', 'could not connect');
$log(TID_CONN, 'a.php', 'ok');
ok(tenantMigrationHealth(TID_CONN)['failed'] === 0, "'(all)' connection failure is resolved by any later success");
$log(TID_CONN, '(all)', 'failed', 'could not connect again');
ok(tenantMigrationHealth(TID_CONN)['failed'] === 1, "a newer '(all)' failure with no success after it is open");

// Tenants never mix.
$log(TID_OTHER, 'z.php', 'failed', 'other tenant');
ok(tenantMigrationHealth(TID_FIXED)['failed'] === 0, "another tenant's failure does not leak in");

$h = tenantMigrationHealth(TID_EMPTY);
ok($h['total'] === 0 && $h['failed'] === 0, 'tenant with no history: zero, zero');
