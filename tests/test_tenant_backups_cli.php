<?php
/**
 * Scheduled per-tenant backups — CLI test
 *   php tests/test_tenant_backups_cli.php
 *
 * core/tenant_backups.php + cron/backup_all_tenants.php back up the main
 * install, every tenant and the control database. Written after production
 * showed 23 of 25 live tenants had never been backed up (2026-10-31).
 *
 * Sections:
 *   1. Target list — every live tenant, never deleted / unprovisioned ones,
 *      each into its own backups/t{id}/, control DB kept out of tenant folders
 *   2. Full backup — DB + only the owner's uploads, verified, marker written
 *   3. Snapshot    — DB only, distinct file name
 *   4. Restorable  — the dump inside loads into an empty database, rows intact
 *   5. Verification catches a truncated or corrupt file
 *   6. Isolation   — one failing target never stops the others, leaves no file
 *   7. Audit log   — each attempt lands in tenant_backup_log
 *   8. CLI         — bad arguments rejected; a second run while one holds the
 *                    lock is refused
 *
 * Creates/drops throwaway databases, temp dirs and one uploads/t{id} folder.
 * Exit 0 = pass.
 */
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/includes/config.php";
require_once "$root/core/tenant_backups.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$suffix   = bin2hex(random_bytes(4));
$dbA      = "bms_tbtest_a_$suffix";
$dbB      = "bms_tbtest_b_$suffix";
$dbLoad   = "bms_tbtest_load_$suffix";
$work     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "bms_tbtest_$suffix";
$fakeTid  = 9300 + random_int(0, 99);
$fakeUp   = ROOT_DIR . "/uploads/t$fakeTid";
$otherUp  = ROOT_DIR . '/uploads/t' . ($fakeTid + 1000);
$logIds   = [];

$admin = new PDO('mysql:host=' . DB_SERVER, DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

register_shutdown_function(function () {
    global $pass, $fail, $admin, $dbA, $dbB, $dbLoad, $work, $fakeUp, $otherUp, $logIds;
    foreach ([$dbA, $dbB, $dbLoad] as $d) { try { $admin->exec("DROP DATABASE IF EXISTS `$d`"); } catch (Throwable $e) {} }
    foreach ([$work, $fakeUp, $otherUp] as $d) { if (is_dir($d)) bms_delete_dir($d); }
    if ($logIds && controlDbReady()) {
        try { getControlPdo()->exec('DELETE FROM tenant_backup_log WHERE id IN (' . implode(',', array_map('intval', $logIds)) . ')'); } catch (Throwable $e) {}
    }
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

/** A target array shaped like bmsBackupTargets() returns, on a scratch DB. */
function target(string $key, string $db, string $dir, string $prefix, ?int $tid, ?string $pass = null, ?string $error = null): array
{
    return [
        'key' => $key, 'label' => "Test $key", 'tenant_id' => $tid, 'subdomain' => $tid ? "sub$tid" : null,
        'db' => ['host' => DB_SERVER, 'user' => DB_USERNAME, 'pass' => $pass ?? DB_PASSWORD, 'name' => $db],
        'prefix' => $prefix, 'dir' => $dir, 'uploads' => true, 'error' => $error,
    ];
}

function zipNames(string $p): array
{
    $z = new ZipArchive(); $z->open($p);
    $n = []; for ($i = 0; $i < $z->numFiles; $i++) $n[] = $z->getNameIndex($i);
    $z->close(); sort($n); return $n;
}

echo "\n\033[1mScheduled per-tenant backups\033[0m\n";

// Fixtures: two scratch "tenant" databases.
foreach ([$dbA, $dbB] as $d) {
    $admin->exec("CREATE DATABASE `$d` CHARACTER SET utf8mb4");
    $admin->exec("CREATE TABLE `$d`.sales (id INT AUTO_INCREMENT PRIMARY KEY, item VARCHAR(40), qty INT,
                  total DECIMAL(12,2) AS (qty * 1000) STORED) ENGINE=InnoDB");
    $admin->exec("CREATE VIEW `$d`.big_sales AS SELECT id, item FROM `$d`.sales WHERE qty > 1");
}
$admin->exec("INSERT INTO `$dbA`.sales (item, qty) VALUES ('Cement', 3), ('Nails', 1), ('O''Brien \"quoted\"', 2)");
$admin->exec("INSERT INTO `$dbB`.sales (item, qty) VALUES ('Soda', 5)");

mkdir("$fakeUp/products", 0755, true);
file_put_contents("$fakeUp/products/own.jpg", 'OWN');
mkdir("$otherUp/products", 0755, true);
file_put_contents("$otherUp/products/someone_else.jpg", 'OTHER');

// ═══════════════════════════════════════════════════════════════════════════
section('1. Target list');
// ═══════════════════════════════════════════════════════════════════════════

$targets = bmsBackupTargets();
$keys    = array_column($targets, 'key');
ok(($targets[0]['key'] ?? '') === 'legacy' && $targets[0]['db']['name'] === DB_NAME && $targets[0]['prefix'] === '',
   'first target is the main install (' . DB_NAME . ') with unprefixed paths');
ok(count($keys) === count(array_unique($keys)), 'every target key is unique (' . count($keys) . ' targets)');

if (controlDbReady()) {
    $cp   = getControlPdo();
    $live = $cp->query("SELECT id FROM tenants WHERE status != 'deleted' AND db_name IS NOT NULL AND db_name != ''
                        AND db_name != " . $cp->quote(DB_NAME))->fetchAll(PDO::FETCH_COLUMN);
    $tenantTargets = array_values(array_filter($targets, fn($t) => $t['tenant_id'] !== null));
    ok(count($tenantTargets) === count($live), 'one target per live provisioned tenant (' . count($live) . ')');

    $deleted = $cp->query("SELECT id FROM tenants WHERE status = 'deleted' LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
    $tids    = array_column($tenantTargets, 'tenant_id');
    ok(!array_intersect($deleted, $tids), 'no deleted tenant is in the list');
    $noDb = $cp->query("SELECT id FROM tenants WHERE status != 'deleted' AND (db_name IS NULL OR db_name = '')")->fetchAll(PDO::FETCH_COLUMN);
    ok(!array_intersect($noDb, $tids), 'no unprovisioned tenant (empty db_name) is in the list');

    $dirsOk = true;
    foreach ($tenantTargets as $t) {
        if ($t['prefix'] !== "t{$t['tenant_id']}/" || substr(str_replace('\\', '/', $t['dir']), -strlen("/backups/t{$t['tenant_id']}/")) !== "/backups/t{$t['tenant_id']}/") $dirsOk = false;
    }
    ok($dirsOk, "every tenant writes to its own backups/t{id}/ with prefix t{id}/ — the folder its Backup page lists");

    $last = end($targets);
    ok($last['key'] === 'control' && $last['uploads'] === false
       && substr(str_replace('\\', '/', $last['dir']), -strlen('/backups/_platform/')) === '/backups/_platform/',
       'control database last, database only, in backups/_platform/ (no tenant page lists it)');

    $one = $tenantTargets ? bmsBackupTargets($tenantTargets[0]['tenant_id']) : [];
    ok(!$tenantTargets || (count($one) === 1 && $one[0]['tenant_id'] === $tenantTargets[0]['tenant_id']),
       '--tenant=ID narrows the list to that one tenant (no legacy, no control)');
} else {
    ok(count($targets) === 1, 'no control DB → only the main install is listed');
}

// ═══════════════════════════════════════════════════════════════════════════
section('2. Full backup');
// ═══════════════════════════════════════════════════════════════════════════

$dirA = "$work/backups/t$fakeTid/";
$tA   = target("t$fakeTid", $dbA, $dirA, "t$fakeTid/", $fakeTid);
$r    = bmsBackupRunTarget($tA, 'full');
ok($r['ok'] && $r['error'] === null, 'full backup OK' . ($r['error'] ? ' — ' . $r['error'] : ''));
ok((bool)preg_match('/^auto_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', (string)$r['file']), "named auto_backup_<timestamp>.zip ({$r['file']})");
$fullZip = $dirA . $r['file'];
$names   = zipNames($fullZip);
ok($names === ['database.sql', 'manifest.json', "uploads/t$fakeTid/products/own.jpg"],
   'holds the dump, manifest and ONLY its own upload — got: ' . implode(', ', $names));
ok($r['files_count'] === 1 && $r['bytes'] === filesize($fullZip), 'result reports files_count 1 and the real size');
ok(is_file($dirA . '.htaccess'), 'its folder got a deny-all .htaccess');
ok(is_file($dirA . '.last_auto_backup') && abs((int)file_get_contents($dirA . '.last_auto_backup') - time()) < 120,
   ".last_auto_backup written, so the Backup page's own daily fallback won't duplicate it");

// ═══════════════════════════════════════════════════════════════════════════
section('3. Snapshot');
// ═══════════════════════════════════════════════════════════════════════════

sleep(1);   // distinct timestamp
unlink($dirA . '.last_auto_backup');
$r = bmsBackupRunTarget($tA, 'snapshot');
ok($r['ok'] && (bool)preg_match('/^auto_snapshot_.*\.zip$/', (string)$r['file']), "snapshot OK, named auto_snapshot_… ({$r['file']})");
$snapZip = $dirA . $r['file'];
ok(zipNames($snapZip) === ['database.sql', 'manifest.json'], 'database only — no uploads');
$z = new ZipArchive(); $z->open($snapZip);
$man = json_decode($z->getFromName('manifest.json'), true); $z->close();
ok(($man['includes_uploads'] ?? null) === false, 'manifest says includes_uploads: false');
ok(!is_file($dirA . '.last_auto_backup'), 'a snapshot does not move the daily full-backup marker');

// ═══════════════════════════════════════════════════════════════════════════
section('4. Restorable');
// ═══════════════════════════════════════════════════════════════════════════

$admin->exec("CREATE DATABASE `$dbLoad` CHARACTER SET utf8mb4");
$ex = bms_extract_zip_backup($fullZip);
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, $dbLoad);
$errs = [];
if (!$m->multi_query(file_get_contents($ex['sql_path']))) $errs[] = $m->error;
else { while (true) { if ($res = $m->store_result()) $res->free(); if ($m->errno) $errs[] = $m->error;
       if (!$m->more_results()) break; if (!$m->next_result()) { if ($m->errno) $errs[] = $m->error; break; } } }
$m->close();
bms_delete_dir($ex['temp_dir']);
ok(!$errs, 'the dump loads into an empty database with no errors' . ($errs ? ': ' . implode(' | ', $errs) : ''));
$lp = new PDO("mysql:host=" . DB_SERVER . ";dbname=$dbLoad", DB_USERNAME, DB_PASSWORD);
ok((int)$lp->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 3, 'all 3 rows are back');
ok((int)$lp->query('SELECT SUM(total) FROM sales')->fetchColumn() === 6000, 'generated column recomputed (6000)');
ok($lp->query("SELECT item FROM sales WHERE qty = 2")->fetchColumn() === "O'Brien \"quoted\"", 'quotes survived');
ok((int)$lp->query('SELECT COUNT(*) FROM big_sales')->fetchColumn() === 2, 'the view came back and works');

// ═══════════════════════════════════════════════════════════════════════════
section('5. Verification catches bad files');
// ═══════════════════════════════════════════════════════════════════════════

$trunc = "$work/trunc.zip";
$z = new ZipArchive(); $z->open($trunc, ZipArchive::CREATE);
$z->addFromString('database.sql', "SET FOREIGN_KEY_CHECKS=0;\nINSERT INTO x VALUES(1);\n");   // no closing line
$z->addFromString('manifest.json', '{"bms_backup_format":"1.0"}');
$z->close();
$caught = null; try { bmsBackupVerifyZip($trunc); } catch (Exception $e) { $caught = $e->getMessage(); }
ok($caught !== null && str_contains($caught, 'truncated'), "a dump missing its closing line is rejected ($caught)");

$corrupt = "$work/corrupt.zip";
file_put_contents($corrupt, substr(file_get_contents($fullZip), 0, 200));
$caught = null; try { bmsBackupVerifyZip($corrupt); } catch (Exception $e) { $caught = $e->getMessage(); }
ok($caught !== null, "a cut-off ZIP is rejected ($caught)");

$caught = null; try { bmsBackupVerifyZip($fullZip); } catch (Exception $e) { $caught = $e->getMessage(); }
ok($caught === null, 'the real backup passes verification');

// ═══════════════════════════════════════════════════════════════════════════
section('6. One failure never stops the others');
// ═══════════════════════════════════════════════════════════════════════════

$dirBad = "$work/backups/bad/";
$dirB   = "$work/backups/b/";
$dirKey = "$work/backups/nokey/";
$run = bmsBackupRunAll('snapshot', null, null, [
    target('tbad',  $dbA, $dirBad, 'tbad/', null, 'definitely-wrong-password-' . $suffix),
    target('tnokey', $dbA, $dirKey, 'tnokey/', null, null, 'could not decrypt stored credentials (check TENANT_CRED_KEY)'),
    target('tb',    $dbB, $dirB,   'tb/',   null),
]);
ok($run['ran'] && $run['ok'] === 1 && $run['failed'] === 2, "2 failures, and the target after them still backed up (ok={$run['ok']}, failed={$run['failed']})");
$byKey = array_column($run['results'], null, 'key');
ok(!$byKey['tbad']['ok'] && stripos((string)$byKey['tbad']['error'], 'denied') !== false, 'wrong password → reported as failed with the reason');
ok(!str_contains((string)$byKey['tbad']['error'], 'definitely-wrong-password'), 'the password never appears in the error');
ok(!$byKey['tnokey']['ok'] && str_contains((string)$byKey['tnokey']['error'], 'TENANT_CRED_KEY'), 'undecryptable credentials → failed, named');
ok(!glob($dirBad . '*.zip') && !glob($dirKey . '*.zip'), 'a failed target leaves no half-written file');
ok($byKey['tb']['ok'] && is_file($dirB . $byKey['tb']['file']), 'the healthy target after the failures has its file');

// ═══════════════════════════════════════════════════════════════════════════
section('7. Audit log');
// ═══════════════════════════════════════════════════════════════════════════

if (controlDbReady()) {
    $cp = getControlPdo();
    $before = (int)$cp->query('SELECT COALESCE(MAX(id),0) FROM tenant_backup_log')->fetchColumn();
    bmsBackupLogWrite($tA, 'full', ['ok' => true, 'file' => 'x.zip', 'bytes' => 10, 'files_count' => 1, 'ms' => 5, 'error' => null]);
    bmsBackupLogWrite($tA, 'snapshot', ['ok' => false, 'file' => null, 'bytes' => 0, 'files_count' => 0, 'ms' => 5, 'error' => 'boom']);
    $rows = $cp->query("SELECT * FROM tenant_backup_log WHERE id > $before ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $logIds = array_column($rows, 'id');
    ok(count($rows) === 2, 'two attempts → two rows in tenant_backup_log');
    ok(($rows[0]['status'] ?? '') === 'ok' && $rows[0]['kind'] === 'full' && (int)$rows[0]['tenant_id'] === $fakeTid,
       'success row: status ok, kind full, tenant id recorded');
    ok(($rows[1]['status'] ?? '') === 'failed' && $rows[1]['message'] === 'boom', 'failure row keeps the reason');
} else {
    ok(true, 'no control DB on this host — logging is skipped (by design)');
}

// ═══════════════════════════════════════════════════════════════════════════
section('8. CLI');
// ═══════════════════════════════════════════════════════════════════════════

$php  = escapeshellarg(PHP_BINARY);
$cron = escapeshellarg("$root/cron/backup_all_tenants.php");
exec("$php $cron 2>&1", $o1, $c1);
ok($c1 === 2, 'no --kind → exit 2 with usage');
exec("$php $cron --kind=weekly 2>&1", $o2, $c2);
ok($c2 === 2, 'unknown --kind → exit 2');

// Hold the run lock exactly as a running backup does, then start another.
$lockPath = ROOT_DIR . '/backups/.backup_run.lock';
bmsEnsureDirGuard(ROOT_DIR . '/backups/');
$held = fopen($lockPath, 'c');
flock($held, LOCK_EX);
exec("$php $cron --kind=snapshot --tenant=0 2>&1", $o3, $c3);
flock($held, LOCK_UN); fclose($held);
ok($c3 === 2 && str_contains(implode("\n", $o3), 'another backup run is still in progress'),
   'a second run while one is in progress is refused (exit 2)');
