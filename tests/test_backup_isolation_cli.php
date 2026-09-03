<?php
/**
 * Backup / restore isolation + dump correctness — CLI test
 *   php tests/test_backup_isolation_cli.php
 *
 * Written after a real incident (2026-09-02, demo host): tenant #9002 pressed
 * Backup → Restore and their data was written over the MAIN database, dropping
 * every table. Recovery then failed a second time because the dump itself could
 * not be restored. See tenant_isolation_plan.md.
 *
 * Three defects, three sections:
 *
 *   1. Leak C — bms_write_dump() wrote `INSERT INTO t VALUES(...)` with no
 *      column list, supplying a value for GENERATED columns. MySQL rejects
 *      those rows (ERROR 3105) and `mysql < dump.sql` ABORTS there, leaving a
 *      half-restored database. Proved end-to-end against a real throwaway
 *      database: dump → drop → restore → compare.
 *
 *   2. Leak A — restoreFromFile() built its mysqli from the DB_* constants,
 *      which on a tenant request are the MAIN database. Now it asks
 *      bmsCurrentDbConfig() which database this request owns.
 *
 *   3. Leak B — every tenant subdomain is served from one webroot, so a
 *      hardcoded backups/ was shared: any tenant could list and download every
 *      other tenant's dump. bmsBackupDir() gives each its own.
 *
 * ANTI-VACUITY. Section 1 also builds a dump the OLD way and asserts it FAILS
 * to restore. An assertion never seen failing is not evidence — that negative
 * control is what proves this suite would catch the bug coming back.
 *
 * Creates and drops one throwaway database. Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/includes/config.php";
require_once "$root/core/backup.php";
require_once "$root/core/tenant_bootstrap.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$scratchDb  = 'bms_dumptest_' . bin2hex(random_bytes(4));
$dumpFile   = sys_get_temp_dir() . '/bms_dumptest_' . getmypid() . '.sql';
$oldDump    = sys_get_temp_dir() . '/bms_dumptest_old_' . getmypid() . '.sql';
$madeDb     = false;
$savedDirs  = [];

function teardown(): void
{
    global $scratchDb, $dumpFile, $oldDump, $madeDb, $savedDirs;
    foreach ([$dumpFile, $oldDump] as $f) { if (is_file($f)) @unlink($f); }
    foreach ($savedDirs as $d) {
        if (is_file("$d/.htaccess")) @unlink("$d/.htaccess");
        if (is_dir($d)) @rmdir($d);
    }
    if ($madeDb) {
        try {
            $a = new PDO('mysql:host=' . DB_SERVER, DB_USERNAME, DB_PASSWORD,
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $a->exec("DROP DATABASE IF EXISTS `$scratchDb`");
        } catch (Throwable $e) {}
    }
    unset($GLOBALS['__bms_tenant'], $GLOBALS['__bms_tenant_pw']);
}
register_shutdown_function(function () {
    global $pass, $fail;
    teardown();
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

echo "\n\033[1mBackup isolation & dump correctness\033[0m\n";

// ═══════════════════════════════════════════════════════════════════════════
section('1. Leak C — a dump with a GENERATED column must actually restore');
// ═══════════════════════════════════════════════════════════════════════════

try {
    $admin = new PDO('mysql:host=' . DB_SERVER, DB_USERNAME, DB_PASSWORD,
                     [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec("CREATE DATABASE `$scratchDb` CHARACTER SET utf8mb4");
    $madeDb = true;
} catch (Throwable $e) {
    echo "  \033[31mCannot create a scratch database: {$e->getMessage()}\033[0m\n";
    echo "  (this test needs CREATE DATABASE on the local server)\n";
    $fail++;
    return;
}

$sp = new PDO("mysql:host=" . DB_SERVER . ";dbname=$scratchDb;charset=utf8mb4",
              DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// A table shaped exactly like the one that broke the real recovery:
// product_stocks.available_quantity is STORED GENERATED.
$sp->exec("CREATE TABLE `stock_like` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
    reserved DECIMAL(12,2) NOT NULL DEFAULT 0,
    available_quantity DECIMAL(12,2) AS (quantity - reserved) STORED,
    note TEXT NULL
) ENGINE=InnoDB");

// A plain table too, so we prove the fix didn't break the ordinary path.
$sp->exec("CREATE TABLE `plain_rows` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(60) NOT NULL,
    tricky TEXT NULL
) ENGINE=InnoDB");

$ins = $sp->prepare("INSERT INTO stock_like (name, quantity, reserved, note) VALUES (?,?,?,?)");
foreach ([['Cement', 100, 15, 'bags'], ['Sand', 42.5, 0.5, null], ["O'Brien \"quoted\"", 7, 2, "line1\nline2"]] as $r) {
    $ins->execute($r);
}
$ins2 = $sp->prepare("INSERT INTO plain_rows (label, tricky) VALUES (?,?)");
$ins2->execute(['normal', 'nothing special']);
$ins2->execute(['edge', "a'b\"c\\d\ne"]);

$expectStock = (int)$sp->query("SELECT COUNT(*) FROM stock_like")->fetchColumn();
$expectPlain = (int)$sp->query("SELECT COUNT(*) FROM plain_rows")->fetchColumn();
$expectAvail = $sp->query("SELECT SUM(available_quantity) FROM stock_like")->fetchColumn();
ok($expectStock === 3 && $expectPlain === 2, "fixture built ($expectStock stock rows, $expectPlain plain rows)");

// ── write the dump with the real helper ──────────────────────────────────
bms_write_dump($sp, $dumpFile);
ok(is_file($dumpFile) && filesize($dumpFile) > 0, 'bms_write_dump() produced a file');

$sql = file_get_contents($dumpFile);
ok(strpos($sql, 'INSERT INTO `stock_like` (`id`,`name`,`quantity`,`reserved`,`note`) VALUES(') !== false,
   'INSERT carries an explicit column list');
ok(strpos($sql, '`available_quantity`') === false || !preg_match('/INSERT INTO `stock_like` \([^)]*available_quantity/', $sql),
   'the GENERATED column is NOT in any INSERT column list');
ok(substr(rtrim($sql), -strlen('SET FOREIGN_KEY_CHECKS=1;')) === 'SET FOREIGN_KEY_CHECKS=1;',
   'dump is complete (ends with the sentinel line)');

// ── restore it exactly the way the app does: mysqli multi_query ───────────
function loadDump(string $file, string $db): array
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $m = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, $db);
    if ($m->connect_error) return ['connect: ' . $m->connect_error];
    $errors = [];
    if (!$m->multi_query(file_get_contents($file))) {
        $errors[] = $m->error;
    } else {
        // NOTE the shape here. The obvious loop —
        //   do { …; } while ($m->more_results() && $m->next_result());
        // silently LOSES the error, because a failing next_result() returns
        // false and exits the loop before anything reads $m->error. That is the
        // loop api/backup_actions.php uses, which is one reason the incident's
        // restore reported so little. Each result must be checked explicitly.
        while (true) {
            if ($res = $m->store_result()) $res->free();
            if ($m->errno) $errors[] = $m->error;
            if (!$m->more_results()) break;
            if (!$m->next_result()) { if ($m->errno) $errors[] = $m->error; break; }
        }
    }
    $m->close();
    return $errors;
}

/**
 * Source with comments stripped.
 *
 * The guards below assert that a dangerous construct is ABSENT. The fixes are
 * documented with comments that quote the old line verbatim, so a naive grep
 * matches the warning about the bug and reports the bug itself.
 */
function phpCodeOnly(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

$sp->exec("SET FOREIGN_KEY_CHECKS=0");
$sp->exec("DROP TABLE stock_like");
$sp->exec("DROP TABLE plain_rows");
$sp->exec("SET FOREIGN_KEY_CHECKS=1");
ok((int)$sp->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$scratchDb'")->fetchColumn() === 0,
   'database emptied before the restore');

$errs = loadDump($dumpFile, $scratchDb);
ok(empty($errs), 'restore completed with NO errors' . ($errs ? ' — got: ' . implode(' | ', array_slice($errs, 0, 3)) : ''));

$gotStock = (int)$sp->query("SELECT COUNT(*) FROM stock_like")->fetchColumn();
$gotPlain = (int)$sp->query("SELECT COUNT(*) FROM plain_rows")->fetchColumn();
ok($gotStock === $expectStock, "every stock row came back ($gotStock/$expectStock)");
ok($gotPlain === $expectPlain, "every plain row came back ($gotPlain/$expectPlain)");

$gotAvail = $sp->query("SELECT SUM(available_quantity) FROM stock_like")->fetchColumn();
ok((float)$gotAvail === (float)$expectAvail,
   "the GENERATED column recomputed correctly ($gotAvail == $expectAvail)");

$edge = $sp->query("SELECT tricky FROM plain_rows WHERE label='edge'")->fetchColumn();
ok($edge === "a'b\"c\\d\ne", 'quotes, backslashes and newlines survived the round trip');

// ── NEGATIVE CONTROL: the old format must fail, or this test proves nothing ──
$old = "SET FOREIGN_KEY_CHECKS=0;\n";
foreach ($sp->query("SELECT * FROM stock_like", PDO::FETCH_ASSOC) as $row) {
    $vals = array_map(fn($v) => $v === null ? 'NULL' : $sp->quote($v), $row);
    $old .= "INSERT INTO `stock_like` VALUES(" . implode(',', $vals) . ");\n";   // no column list
}
$old .= "SET FOREIGN_KEY_CHECKS=1;\n";
file_put_contents($oldDump, $old);
$sp->exec("DELETE FROM stock_like");
$oldErrs = loadDump($oldDump, $scratchDb);
$saysGenerated = false;
foreach ($oldErrs as $e) { if (stripos($e, 'generated column') !== false) { $saysGenerated = true; break; } }
ok($saysGenerated,
   'NEGATIVE CONTROL: the old no-column-list format is still rejected by MySQL — so the assertions above are real');
ok((int)$sp->query("SELECT COUNT(*) FROM stock_like")->fetchColumn() === 0,
   'NEGATIVE CONTROL: and it restored nothing, exactly as it did during the incident');

// ═══════════════════════════════════════════════════════════════════════════
section('2. Leak A — restore must target the database THIS request owns');
// ═══════════════════════════════════════════════════════════════════════════

unset($GLOBALS['__bms_tenant'], $GLOBALS['__bms_tenant_pw']);
$cfg = bmsCurrentDbConfig();
ok($cfg['name'] === DB_NAME, 'no tenant → the legacy DB_* connection (single-tenant unchanged)');
ok(bmsCurrentDbName() === DB_NAME, 'bmsCurrentDbName() agrees');
ok(bmsTenantPathPrefix() === '', 'no tenant → unprefixed paths');

// Simulate a resolved tenant exactly as bmsConnectPdo() leaves the request.
$GLOBALS['__bms_tenant'] = [
    'id' => 9002, 'subdomain' => 'mufindipower',
    'db_host' => DB_SERVER, 'db_name' => $scratchDb,
    'db_username' => DB_USERNAME, 'db_password_encrypted' => '',
    'status' => 'active',
];
$GLOBALS['__bms_tenant_pw'] = DB_PASSWORD;

$cfg = bmsCurrentDbConfig();
ok($cfg['name'] === $scratchDb,
   "tenant request → the TENANT's database ({$cfg['name']}), not " . DB_NAME);
ok($cfg['name'] !== DB_NAME,
   'THE INCIDENT ASSERTION: a tenant request never resolves to the main database');
ok(bmsCurrentDbName() === $scratchDb, 'bmsCurrentDbName() follows the tenant');

// The call site itself — a source guard against the exact line that caused it,
// matched against CODE ONLY so the comment documenting the old line cannot
// masquerade as the old line.
$code = phpCodeOnly(file_get_contents("$root/api/backup_actions.php"));
ok(strpos($code, 'bmsCurrentDbConfig()') !== false,
   'api/backup_actions.php asks bmsCurrentDbConfig() for its credentials');
ok(!preg_match('/new\s+mysqli\s*\(\s*DB_SERVER/', $code),
   'api/backup_actions.php no longer builds mysqli from the DB_* constants');
ok(!preg_match('/ROOT_DIR\s*\.\s*[\'"]\/backups/', $code),
   'api/backup_actions.php no longer hardcodes the shared backups path');

// ═══════════════════════════════════════════════════════════════════════════
section('3. Leak B — each tenant gets its own backup directory');
// ═══════════════════════════════════════════════════════════════════════════

$tenantDir = bmsBackupDir();
$savedDirs[] = rtrim($tenantDir, '/\\');
ok(strpos($tenantDir, 't9002') !== false, "tenant #9002 → its own directory ($tenantDir)");
ok(is_dir($tenantDir), 'the directory is created on demand');
ok(is_file(rtrim($tenantDir, '/\\') . '/.htaccess'), 'a deny-all .htaccess guards it (dumps are never web-fetchable)');

// The legacy install — the tenant whose db_name IS this environment's DB_NAME —
// must keep the unprefixed path, or every stored document path breaks.
$GLOBALS['__bms_tenant']['db_name'] = DB_NAME;
ok(bmsTenantPathPrefix() === '',
   'the LEGACY tenant (db_name === DB_NAME) keeps unprefixed paths — existing files stay valid');
ok(rtrim(bmsBackupDir(), '/\\') === rtrim(ROOT_DIR . '/backups', '/\\'),
   'and its backup directory is the original backups/ — nothing to migrate');

// Two different tenants must never share a directory.
$GLOBALS['__bms_tenant']['db_name'] = $scratchDb;
$a = bmsBackupDir();
$GLOBALS['__bms_tenant']['id'] = 9003;
$b = bmsBackupDir();
$savedDirs[] = rtrim($b, '/\\');
ok($a !== $b, 'two tenants resolve to two different directories');

$pageCode = phpCodeOnly(file_get_contents("$root/app/constant/settings/backup_restore.php"));
ok(strpos($pageCode, 'bmsBackupDir()') !== false,
   'the settings page lists from bmsBackupDir(), so the glob() cannot see another tenant');
ok(strpos($pageCode, 'bmsCurrentDbName()') !== false,
   'and reports the size of the database it actually owns');
ok(!preg_match('/__DIR__\s*\.\s*[\'"][^\'"]*\/backups/', $pageCode),
   'and no longer builds the shared backups path from __DIR__');
