<?php
/**
 * Backup health page + alerts — CLI test
 *   php tests/test_backup_health_cli.php
 *
 * core/backup_health.php reads tenant_backup_log and the off-site status file
 * and decides, per database, Healthy / Failed / Overdue / Never / Pending;
 * cron/backup_all_tenants.php raises one alert a day (bell + email) when
 * anything needs attention; app/superadmin/backups.php shows it all.
 *
 * Needs the local control database (bms_control). Every row this test writes
 * is removed again. Email is captured (BACKUP_ALERT_CAPTURE_FILE), never sent.
 * Exit 0 = pass.
 */

// ─── Router worker — renders /backups through the real handleRoute() ────────
if (($argv[1] ?? '') === '--route') {
    $_SERVER['HTTP_HOST']      = (string)$argv[2];
    $_SERVER['REQUEST_URI']    = (string)$argv[3];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['QUERY_STRING']   = '';
    require_once __DIR__ . '/../roots.php';
    require_once __DIR__ . '/../core/superadmin_auth.php';
    superadminSessionReady();
    if (($argv[4] ?? '1') === '1') {
        $r = getControlPdo()->query('SELECT id FROM superadmins ORDER BY id LIMIT 1')->fetch();
        if ($r) $_SESSION['superadmin_id'] = (int)$r['id'];
    }
    ob_start();
    handleRoute();
    fwrite(STDOUT, ob_get_clean());
    exit(0);
}

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/tenant_backups.php";
require_once "$root/core/backup_health.php";
require_once "$root/core/superadmin_auth.php";

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }

$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms_health_' . bin2hex(random_bytes(4));
mkdir($work, 0755, true);
$cpdo = getControlPdo();
$startLogId   = (int)$cpdo->query('SELECT COALESCE(MAX(id),0) FROM tenant_backup_log')->fetchColumn();
$startNotifId = (int)$cpdo->query('SELECT COALESCE(MAX(id),0) FROM superadmin_notifications')->fetchColumn();
register_shutdown_function(function () use ($work, $cpdo, $startLogId, $startNotifId) {
    global $pass, $fail;
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) $fail++;   // a crash is a failure
    $cpdo->exec("DELETE FROM tenant_backup_log WHERE id > $startLogId AND message LIKE 'HEALTHTEST%'");
    $cpdo->exec("DELETE FROM tenant_backup_log WHERE id > $startLogId AND file_name LIKE 'healthtest_%'");
    $cpdo->exec("DELETE FROM superadmin_notifications WHERE id > $startNotifId AND type = 'backup_problem'");
    if (is_dir($work)) bms_delete_dir($work);
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    exit($fail === 0 ? 0 : 1);
});

echo "\n\033[1mBackup health\033[0m\n";

// ═══════════════════════════════════════════════════════════════════════════
section('1. State of one database');
// ═══════════════════════════════════════════════════════════════════════════

$row = fn(int $id, int $ageMin, string $msg = '') => ['id' => $id, 'age_min' => $ageMin, 'message' => $msg];
$none = ['full_ok' => null, 'full_failed' => null, 'snapshot_ok' => null, 'snapshot_failed' => null];
ok(bmsBackupHealthClassify(['full_ok' => $row(5, 60)] + $none, null) === 'ok', 'good full backup an hour ago → ok');
ok(bmsBackupHealthClassify(['full_ok' => $row(5, 25 * 60)] + $none, null) === 'ok', '25 hours ago → still ok');
ok(bmsBackupHealthClassify(['full_ok' => $row(5, 27 * 60)] + $none, null) === 'overdue', '27 hours ago → overdue (a night was missed)');
ok(bmsBackupHealthClassify(['full_ok' => $row(5, 60), 'full_failed' => $row(9, 10)] + $none, null) === 'failed', 'latest attempt failed → failed');
ok(bmsBackupHealthClassify(['full_ok' => $row(9, 10), 'full_failed' => $row(5, 60)] + $none, null) === 'ok', 'failure followed by a success → ok');
ok(bmsBackupHealthClassify(['full_ok' => $row(5, 300), 'snapshot_failed' => $row(9, 10)] + $none, null) === 'failed', 'a snapshot failing after the nightly → failed');
ok(bmsBackupHealthClassify(['snapshot_ok' => $row(9, 10)] + $none, null) === 'overdue', 'snapshots only, never a full backup → overdue');
ok(bmsBackupHealthClassify($none, null) === 'never', 'no rows at all → never');
ok(bmsBackupHealthClassify($none, 120) === 'pending', 'company created 2 hours ago, no backup yet → pending (not a problem)');
ok(bmsBackupHealthClassify($none, 30 * 60) === 'never', 'company created 30 hours ago, no backup → never');

// ═══════════════════════════════════════════════════════════════════════════
section('2. Off-site status');
// ═══════════════════════════════════════════════════════════════════════════

$sf = "$work/offsite_status.json";
$o = bmsBackupHealthOffsite($sf);
ok($o['state'] === 'never' && $o['problem'] !== null, 'no status file → never ran, reported as a problem');
file_put_contents($sf, json_encode(['status' => 'not_configured', 'error' => 'rclone is not installed', 'finished_at' => date('c')]));
$o = bmsBackupHealthOffsite($sf);
ok($o['state'] === 'not_configured' && str_contains((string)$o['problem'], 'rclone is not installed'), 'not configured → problem with the reason');
file_put_contents($sf, json_encode(['status' => 'failed', 'error' => 'upload failed — quota', 'finished_at' => date('c')]));
$o = bmsBackupHealthOffsite($sf);
ok($o['state'] === 'failed' && str_contains((string)$o['problem'], 'quota'), 'failed → problem with the error');
file_put_contents($sf, json_encode(['status' => 'ok', 'finished_at' => date('c', time() - 30 * 3600)]));
ok(bmsBackupHealthOffsite($sf)['state'] === 'overdue', 'last success 30 hours ago → overdue');
file_put_contents($sf, json_encode(['status' => 'ok', 'finished_at' => date('c', time() - 2 * 3600), 'remote_files' => 12]));
$o = bmsBackupHealthOffsite($sf);
ok($o['state'] === 'ok' && $o['problem'] === null && $o['age_min'] >= 119 && $o['age_min'] <= 121, 'success 2 hours ago → ok, age in minutes');
file_put_contents($sf, '{not json');
ok(bmsBackupHealthOffsite($sf)['state'] === 'never', 'unreadable status file → treated as never ran');

// ═══════════════════════════════════════════════════════════════════════════
section('3. Every database, from the real control DB');
// ═══════════════════════════════════════════════════════════════════════════

$health = bmsBackupHealth();
$runKeys    = array_column(bmsBackupTargets(), 'key');
$healthKeys = array_column($health['targets'], 'key');
ok($health['ready'] && $healthKeys === $runKeys, 'lists exactly the databases the backup run covers (' . count($runKeys) . '), same order');
ok($health['counts']['total'] === count($runKeys)
   && $health['counts']['ok'] + $health['counts']['problem'] + $health['counts']['pending'] === count($runKeys), 'counts add up');

$tenantKeys = array_values(array_filter($healthKeys, fn($k) => preg_match('/^t\d+$/', $k)));
[$kA, $kB, $kC] = [$tenantKeys[0], $tenantKeys[1], $tenantKeys[2]];
$ins = $cpdo->prepare("INSERT INTO tenant_backup_log (target_key, kind, status, file_name, bytes, files_count, message, created_at)
                       VALUES (?, ?, ?, ?, ?, 0, ?, NOW() - INTERVAL ? MINUTE)");
$ins->execute([$kA, 'full', 'ok', 'healthtest_a.zip', 2048000, null, 30]);
$ins->execute([$kB, 'full', 'ok', 'healthtest_b.zip', 1024, null, 30 * 60]);
$ins->execute([$kC, 'full', 'ok', 'healthtest_c.zip', 1024, null, 60]);
$ins->execute([$kC, 'snapshot', 'failed', null, 0, 'HEALTHTEST disk full', 5]);

$health = bmsBackupHealth();
$byKey = array_column($health['targets'], null, 'key');
ok($byKey[$kA]['state'] === 'ok' && $byKey[$kA]['full_ok']['file_name'] === 'healthtest_a.zip', "$kA: backup 30 min ago → Healthy, file name shown");
ok($byKey[$kB]['state'] === 'overdue', "$kB: last good full 30 h ago → Overdue");
ok($byKey[$kC]['state'] === 'failed', "$kC: snapshot failed after the nightly → Failed");
$probText = implode("\n", $health['problems']);
ok(str_contains($probText, 'HEALTHTEST disk full'), 'the failure reason is in the problem list');
ok(str_contains($probText, $byKey[$kB]['label']) && str_contains($probText, 'over 26 hours'), 'the overdue company is named in the problem list');

// ═══════════════════════════════════════════════════════════════════════════
section('4. Daily alert (bell + email, once a day)');
// ═══════════════════════════════════════════════════════════════════════════

define('BACKUP_ALERT_CAPTURE_FILE', "$work/mail.jsonl");
$marker = "$work/.backup_alert_day";
ok(bmsBackupHealthAlert(['problems' => []] + $health, $marker) === false && !is_file($marker), 'no problems → no alert, no marker');
ok(bmsBackupHealthAlert($health, $marker) === true, 'problems → alert raised');
$mail = json_decode((string)strtok((string)@file_get_contents("$work/mail.jsonl"), "\n"), true);
ok(str_starts_with((string)($mail['subject'] ?? ''), 'Backups need attention — ') && str_contains((string)$mail['html'], 'HEALTHTEST disk full'),
   'email lists every problem (captured, not sent)');
$n = $cpdo->query("SELECT title, body, tenant_id FROM superadmin_notifications WHERE id > $startNotifId AND type = 'backup_problem'")->fetchAll(PDO::FETCH_ASSOC);
ok(count($n) === 1 && $n[0]['tenant_id'] === null && str_starts_with($n[0]['title'], 'Backups need attention'),
   'one bell notification (platform-wide, no company)');
ok(trim((string)file_get_contents($marker)) === date('Y-m-d'), 'today is marked');
ok(bmsBackupHealthAlert($health, $marker) === false, 'second run the same day → no repeat');
ok(count(file("$work/mail.jsonl")) === 1, 'still exactly one email today');
file_put_contents($marker, date('Y-m-d', time() - 86400));
ok(bmsBackupHealthAlert($health, $marker) === true, 'next day, problem still there → alerted again');

// ═══════════════════════════════════════════════════════════════════════════
section('5. Wiring');
// ═══════════════════════════════════════════════════════════════════════════

$cron = file_get_contents("$root/cron/backup_all_tenants.php");
ok(str_contains($cron, "require_once __DIR__ . '/../core/backup_health.php'")
   && (bool)preg_match('/if \(\$tenantId === null\) \{\s*try \{\s*\$health = bmsBackupHealth\(\);/', $cron)
   && str_contains($cron, 'bmsBackupHealthAlert($health)'),
   'every scheduled all-company run ends with the health check (not a single --tenant retry)');
ok(strpos($cron, 'bmsBackupHealth()') > strpos($cron, 'flock($lock, LOCK_UN)'), 'checked after the run lock is released');
ok(str_ends_with(str_replace('\\', '/', superadminRouteMap()['backups'] ?? ''), 'app/superadmin/backups.php'), 'route /backups → app/superadmin/backups.php');
$ui = file_get_contents("$root/core/superadmin_ui.php");
ok(str_contains($ui, "\$navItem('backups',   saUrl('backups')"), 'nav item "Backups" in the panel header');
ok(str_contains($ui, "if (\$nType === 'backup_problem') { \$tenantUrl = saUrl('backups');"), 'bell notification opens the Backups page');
$setup = file_get_contents("$root/scripts/setup_control_db.php");
ok(str_contains($setup, "'first_login','first_product','first_sale','inactive','backup_problem') NOT NULL"), 'setup_control_db.php adds backup_problem to the notification types');
$enum = (string)$cpdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . $cpdo->quote(controlDbName())
       . " AND TABLE_NAME = 'superadmin_notifications' AND COLUMN_NAME = 'type'")->fetchColumn();
ok(str_contains($enum, "'backup_problem'") && str_contains($enum, "'inactive'") && str_contains($enum, "'bms_request'"),
   'local control DB after setup: backup_problem added, earlier types kept');

// ═══════════════════════════════════════════════════════════════════════════
section('6. The page, through the real router');
// ═══════════════════════════════════════════════════════════════════════════

$base   = getenv('TENANT_BASE_DOMAIN') ?: 'dev.bms.local';
$saHost = superadminHostLabel() . '.' . $base;
$render = fn(string $auth) => (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --route '
          . escapeshellarg($saHost) . ' /backups ' . $auth . ' 2>&1');
$html = $render('1');
ok(str_contains($html, '<title>Backups | Platform Administration</title>'), 'renders for a signed-in superadmin');
ok(!preg_match('/(Warning|Notice|Fatal error|Deprecated|Uncaught)/', $html), 'no PHP warnings or errors in the page');
ok(str_contains($html, 'healthtest_a.zip') && str_contains($html, 'HEALTHTEST disk full'), 'shows each database\'s last backup and the failure reason');
ok(substr_count($html, '<tr>') >= count($runKeys) + 1, 'one row per database');
ok(str_contains($html, 'Off-site copy (Google Drive, encrypted)') && str_contains($html, 'Failures in the last 30 days'), 'off-site and failure sections present');
ok(str_contains($html, 'nav-link active" href="/backups"'), 'Backups is the active nav item');
$anon = $render('0');
ok(!str_contains($anon, 'healthtest_a.zip') && !str_contains($anon, '<title>Backups'), 'not signed in → no backup data shown');
