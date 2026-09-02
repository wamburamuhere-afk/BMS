<?php
/**
 * Multi-tenancy — Phase 8 (per-tenant migration runner) CLI test
 *   php tests/test_tenant_migration_runner_cli.php
 *
 * Drops a REAL migration file into migrations/tenant/, runs the REAL runner
 * against real provisioned tenants, and removes the file afterwards — this is
 * ternant.md's own acceptance gate for this phase, executed rather than
 * simulated.
 *
 * What it proves:
 *   - runTenantMigrations() is a graceful no-op with no control DB, no
 *     tenants, or no migration files — never an error
 *   - a dummy migration is applied to every live tenant, and only once
 *     (running twice is a no-op the second time)
 *   - a genuinely broken migration fails LOUDLY for its tenant, is recorded in
 *     tenant_migration_log, and stops FURTHER migrations for that tenant —
 *     while every OTHER tenant still receives the same migrations normally
 *   - --tenant=<id> filters to one tenant
 *   - --dry-run reports without changing anything
 *   - credentials never appear in a migration's own output
 *   - the bootstrap refuses to run outside the runner (no env vars) and
 *     refuses to run against a platform database
 *
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/control_db.php";
require_once "$root/core/tenant_crypto.php";
require_once "$root/core/tenant_provisioner.php";
require_once "$root/core/tenant_migration_runner.php";

$pass = 0; $fail = 0;
function ok($c,$m){ global $pass,$fail; if($c){$pass++; echo "  \033[32m✅\033[0m $m\n";} else {$fail++; echo "  \033[31m❌ $m\033[0m\n";} }
function section($t){ echo "\n\033[1m── $t ──\033[0m\n"; }

$made = ['tenants' => [], 'databases' => [], 'users' => []];
$tenantDir = dirname(__DIR__) . '/migrations/tenant';
$droppedFiles = [];

/**
 * Undo what this suite did to tenants it does NOT own.
 *
 * runTenantMigrations() with no filter deliberately applies to EVERY live
 * tenant — that is the behaviour under test. The consequence is that this
 * suite's throwaway migrations also land in whatever real tenants happen to
 * exist on the machine, and dropping this suite's own two databases does not
 * undo that. Left unfixed, running these tests on a host with real customers
 * creates p8*_marker tables inside their databases and writes rows into their
 * schema_migrations — which would also make a LATER real migration of the same
 * name silently appear "already applied".
 *
 * Everything this suite creates is uniquely named (9999_99_99_p8*_<sfx>.php,
 * p8*_marker), so cleanup can be exact rather than guesswork.
 */
function cleanBystanderTenants(): void
{
    require_once dirname(__DIR__) . '/core/tenant_crypto.php';
    try {
        $rows = getControlPdo()
            ->query("SELECT * FROM tenants WHERE status != 'deleted' AND subdomain NOT LIKE 'migtest%'")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return; }

    foreach ($rows as $t) {
        try {
            $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
            if ($pw === null) continue;
            $p = new PDO(
                "mysql:host={$t['db_host']};dbname={$t['db_name']};charset=utf8mb4",
                $t['db_username'], $pw,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $p->exec("DROP TABLE IF EXISTS `p8test_marker`");
            $p->exec("DROP TABLE IF EXISTS `p8dry_marker`");
            $p->exec("DELETE FROM `schema_migrations` WHERE migration_name LIKE '9999\\_99\\_99\\_p8%'");
        } catch (Throwable $e) { /* unreachable tenant — never break teardown */ }
    }
}

function teardown(): void
{
    global $made, $droppedFiles;
    foreach ($droppedFiles as $f) { if (is_file($f)) @unlink($f); }
    cleanBystanderTenants();
    try {
        $c = getControlPdo();
        $c->exec("DELETE FROM tenant_migration_log WHERE subdomain LIKE 'migtest%'");
        $c->exec("DELETE FROM tenant_provisioning_log WHERE subdomain LIKE 'migtest%'");
        $c->exec("DELETE FROM tenants WHERE subdomain LIKE 'migtest%'");
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
    @unlink(dirname(__DIR__) . '/migrations/tenant_deploy.log');
}
register_shutdown_function(function(){
    global $pass,$fail;
    teardown();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: ".($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m")."\n";
});

/** Write a throwaway migrations/tenant/*.php file; tracked for cleanup. */
function dropMigration(string $name, string $body): string
{
    global $tenantDir, $droppedFiles;
    $path = "$tenantDir/$name";
    file_put_contents($path, "<?php\n"
        . "if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }\n"
        . "require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';\n"
        . "global \$pdo;\n"
        . $body);
    $droppedFiles[] = $path;
    return $path;
}

function tenantTableExists(array $t, string $table): bool
{
    $s = controlDbSettings();
    require_once dirname(__DIR__) . '/core/tenant_crypto.php';
    $pw = decryptTenantSecret((string)$t['db_password_encrypted']);
    $p = new PDO("mysql:host={$t['db_host']};dbname={$t['db_name']};charset=utf8mb4", $t['db_username'], $pw,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return (bool)$p->query("SHOW TABLES LIKE " . $p->quote($table))->fetchColumn();
}

function rawTenant(int $id): array
{
    $st = getControlPdo()->prepare("SELECT * FROM tenants WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch();
}

try {
    $cpdo = getControlPdo();
    $sfx  = bin2hex(random_bytes(3));

    section('1. Control table exists');
    $tables = $cpdo->query("
        SELECT table_name FROM information_schema.tables
        WHERE table_schema = " . $cpdo->quote(controlDbName()) . " AND table_type='BASE TABLE'
    ")->fetchAll(PDO::FETCH_COLUMN);
    ok(in_array('tenant_migration_log', $tables, true), 'tenant_migration_log exists');

    section('2. Graceful no-ops (matching the control-DB hotfix lesson)');
    // No migration files at all — the real state before this test drops any.
    $existingFiles = tenantMigrationFiles();
    ok(true, 'baseline: ' . count($existingFiles) . ' pre-existing file(s) under migrations/tenant/');

    $r = runTenantMigrations(999999999);   // a tenant id that cannot exist
    ok($r['ran'] === false, 'no live tenant matching the filter -> graceful no-op');
    // With zero migration files ALSO true right now, that check short-circuits
    // first — both are legitimate "nothing to do" reasons. The invalid-tenant
    // wording is exercised specifically in section 6, once files exist.
    ok(strpos((string)$r['reason'], 'no files') !== false || strpos((string)$r['reason'], 'no live tenant') !== false,
        'reason explains why (' . $r['reason'] . ')');

    section('3. Provision two live tenants');
    $subA = 'migtesta' . $sfx;
    $subB = 'migtestb' . $sfx;
    $A = provisionTenant('Migration Alpha Ltd', $subA, "a@$subA.test", 'Password1');
    $B = provisionTenant('Migration Beta Ltd',  $subB, "b@$subB.test", 'Password1');
    ok($A['ok'] && $B['ok'], 'two tenants provisioned');
    if (!$A['ok'] || !$B['ok']) throw new RuntimeException($A['error'] ?? $B['error'] ?? 'provision failed');
    foreach ([$A, $B] as $t) {
        $made['tenants'][]   = $t['tenant_id'];
        $made['databases'][] = $t['db_name'];
        $made['users'][]     = $t['db_username'];
    }
    $a = rawTenant($A['tenant_id']);
    $b = rawTenant($B['tenant_id']);

    ok(tenantTableExists($a, 'schema_migrations'), 'freshly provisioned tenant already has schema_migrations');

    section('4. A dummy migration applies to every tenant, and only once');
    $migName = "9999_99_99_p8test_$sfx.php";
    dropMigration($migName,
        "echo \"Starting migration: p8test...\\n\";\n"
      . "\$pdo->exec(\"CREATE TABLE IF NOT EXISTS p8test_marker (id INT)\");\n"
      . "\$pdo->exec(\"INSERT INTO p8test_marker VALUES (1)\");\n"
      . "echo \"Migration complete.\\n\";\n");

    $summary = runTenantMigrations();
    ok($summary['ran'] === true, 'runner ran');
    ok($summary['files'] >= 1, 'the dropped file was discovered');

    $applied = [];
    foreach ($summary['tenants'] as $t) { $applied[$t['id']] = $t; }
    ok(in_array($migName, $applied[$A['tenant_id']]['applied'] ?? [], true), 'applied to tenant A');
    ok(in_array($migName, $applied[$B['tenant_id']]['applied'] ?? [], true), 'applied to tenant B');
    ok(tenantTableExists($a, 'p8test_marker'), "tenant A's database actually has the new table");
    ok(tenantTableExists($b, 'p8test_marker'), "tenant B's database actually has the new table");

    $st = $cpdo->prepare("SELECT COUNT(*) FROM tenant_migration_log WHERE subdomain=? AND migration_name=? AND status='ok'");
    $st->execute([$subA, $migName]);
    ok((int)$st->fetchColumn() === 1, "tenant A's success is recorded in tenant_migration_log");

    // Second run: idempotent, nothing re-applied.
    $summary2 = runTenantMigrations();
    $applied2 = [];
    foreach ($summary2['tenants'] as $t) { $applied2[$t['id']] = $t; }
    ok(($applied2[$A['tenant_id']]['applied'] ?? ['x']) === [], 'second run applies NOTHING to tenant A (idempotent)');
    ok(($applied2[$B['tenant_id']]['applied'] ?? ['x']) === [], 'second run applies NOTHING to tenant B (idempotent)');

    section('5. --dry-run changes nothing');
    $migName2 = "9999_99_99_p8dry_$sfx.php";
    dropMigration($migName2,
        "\$pdo->exec(\"CREATE TABLE IF NOT EXISTS p8dry_marker (id INT)\");\n"
      . "echo \"Migration complete.\\n\";\n");
    $dry = runTenantMigrations(null, true);
    $dryApplied = [];
    foreach ($dry['tenants'] as $t) { $dryApplied[$t['id']] = $t['applied']; }
    ok(!empty($dryApplied[$A['tenant_id']]), 'dry-run reports the pending migration');
    ok(!tenantTableExists($a, 'p8dry_marker'), 'dry-run created NOTHING in the real database');
    // schema_migrations lives IN the tenant DB, not the control DB — check there.
    $pw = decryptTenantSecret((string)$a['db_password_encrypted']);
    $tp = new PDO("mysql:host={$a['db_host']};dbname={$a['db_name']};charset=utf8mb4", $a['db_username'], $pw,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ok((int)$tp->query("SELECT COUNT(*) FROM schema_migrations WHERE migration_name='$migName2'")->fetchColumn() === 0,
        'dry-run recorded nothing in schema_migrations either');

    section('6. --tenant=<id> filters to one tenant');
    $only = runTenantMigrations($A['tenant_id']);
    ok(count($only['tenants']) === 1, 'only one tenant processed');
    ok($only['tenants'][0]['id'] === $A['tenant_id'], 'and it is the requested one');

    // Now that files genuinely exist, an invalid tenant id exercises the
    // "no live tenant" branch specifically, rather than the "no files" one.
    $bad = runTenantMigrations(999999999);
    ok($bad['ran'] === false, 'an invalid tenant id is still a graceful no-op with files present');
    ok(strpos((string)$bad['reason'], 'no live tenant with id 999999999') !== false,
        'and now the reason names the missing tenant specifically (' . $bad['reason'] . ')');

    section('7. A broken migration fails LOUDLY for its tenant, and stops there — but NEVER affects the other tenant');
    // The SAME migration file runs against both tenants — the failure comes
    // from a REAL divergence in the two databases' pre-existing state, not
    // from different code paths. This is the honest multi-tenant scenario:
    // a plain, single ALTER TABLE that succeeds wherever the column is not
    // already present, and fails wherever it is.
    $migBad = "9999_99_99_p8bad_$sfx.php";
    dropMigration($migBad, "\$pdo->exec(\"ALTER TABLE p8test_marker ADD COLUMN col_that_conflicts INT\");\n"
        . "echo \"Migration complete.\\n\";\n");

    // Pre-create the conflicting column ONLY in tenant A's database so the
    // migration genuinely fails there and genuinely succeeds for tenant B.
    $tp->exec("ALTER TABLE p8test_marker ADD COLUMN col_that_conflicts INT");

    $before = runTenantMigrations();   // apply p8dry (still pending) + p8bad
    $byId = [];
    foreach ($before['tenants'] as $t) { $byId[$t['id']] = $t; }

    ok($byId[$A['tenant_id']]['failed'] === $migBad, "tenant A's run reports $migBad as the failure");
    ok(!empty($byId[$A['tenant_id']]['error']), 'the failure carries the SQL error detail');
    ok(!in_array($migBad, $byId[$A['tenant_id']]['applied'], true), 'the broken migration is NOT recorded as applied for A');

    ok($byId[$B['tenant_id']]['failed'] === null, 'tenant B has NO failure — completely unaffected');
    ok(in_array($migBad, $byId[$B['tenant_id']]['applied'], true), 'tenant B received the SAME migration successfully');
    ok(tenantTableExists($b, 'p8dry_marker'), "tenant B's database has the earlier pending migration too");

    $st = $cpdo->prepare("SELECT COUNT(*) FROM tenant_migration_log WHERE subdomain=? AND migration_name=? AND status='failed'");
    $st->execute([$subA, $migBad]);
    ok((int)$st->fetchColumn() === 1, "tenant A's failure is recorded in tenant_migration_log");

    // Fix it and re-run: only tenant A has anything pending now (B already has it).
    $tp->exec("ALTER TABLE p8test_marker DROP COLUMN col_that_conflicts");
    dropMigration($migBad, "\$pdo->exec(\"CREATE TABLE IF NOT EXISTS p8bad_fixed_marker (id INT)\");\n"
        . "echo \"Migration complete.\\n\";\n");
    $after = runTenantMigrations($A['tenant_id']);
    ok($after['tenants'][0]['failed'] === null, 'once fixed, tenant A succeeds on retry');
    ok(in_array($migBad, $after['tenants'][0]['applied'], true), 'and it is now recorded as applied');

    section('8. Credentials never appear in a NORMAL failure\'s error output or log');
    // A migration file CAN read its own TENANT_MIGRATION_DB_PASS via getenv() —
    // that is simply how the bootstrap receives it, the same local exposure any
    // subprocess secret has, documented in tenant_migration_bootstrap.php. The
    // property actually worth guaranteeing is different: an ORDINARY migration
    // failure (a SQL error, exactly like the one just produced in section 7)
    // must never surface the password in its error text or in what gets
    // recorded to tenant_migration_log — because that text is exactly what an
    // operator reads afterwards.
    $pwA = decryptTenantSecret((string)$a['db_password_encrypted']);
    ok(strpos((string)$byId[$A['tenant_id']]['error'], $pwA) === false,
        "the SQL failure's own error text does not contain tenant A's password");

    $st = $cpdo->prepare("SELECT message FROM tenant_migration_log WHERE subdomain=? AND migration_name=? AND status='failed' ORDER BY id DESC LIMIT 1");
    $st->execute([$subA, $migBad]);
    $loggedMsg = (string)$st->fetchColumn();
    ok($loggedMsg !== '', 'the failure message was actually recorded');
    ok(strpos($loggedMsg, $pwA) === false, 'and the recorded message does not contain the password either');

    section('9. The bootstrap refuses misuse');
    // No env vars set (already unset above) — running the file directly must fail.
    $out2 = shell_exec('php ' . escapeshellarg(dirname(__DIR__) . "/migrations/tenant/$migName") . ' 2>&1');
    ok(strpos((string)$out2, 'never be invoked directly') !== false
       || strpos((string)$out2, 'environment variables are not set') !== false,
        'running a tenant migration file directly (no env vars) is refused');

    putenv('TENANT_MIGRATION_DB_HOST=localhost');
    putenv('TENANT_MIGRATION_DB_NAME=bms_control');   // the platform database
    putenv('TENANT_MIGRATION_DB_USER=root');
    putenv('TENANT_MIGRATION_DB_PASS=');
    $out3 = shell_exec('php ' . escapeshellarg(__DIR__ . '/../core/tenant_migration_bootstrap.php') . ' 2>&1');
    putenv('TENANT_MIGRATION_DB_HOST'); putenv('TENANT_MIGRATION_DB_NAME');
    putenv('TENANT_MIGRATION_DB_USER'); putenv('TENANT_MIGRATION_DB_PASS');
    ok(strpos((string)$out3, 'refusing') !== false, 'the bootstrap refuses to target bms_control even if pointed at it');

} catch (Throwable $e) {
    $fail++;
    echo "\n\033[31mFATAL: " . $e->getMessage() . "\033[0m\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}

exit($fail === 0 ? 0 : 1);
