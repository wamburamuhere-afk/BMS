<?php
/**
 * cron/backup_all_tenants.php
 * ---------------------------
 * Scheduled backup of every database this BMS install owns: the main install,
 * every tenant, and the control database. Logic lives in core/tenant_backups.php.
 *
 * Usage:
 *   php cron/backup_all_tenants.php --kind=full        # daily: DB + each owner's uploads
 *   php cron/backup_all_tenants.php --kind=snapshot    # 6-hourly: DB only
 *   php cron/backup_all_tenants.php --kind=full --tenant=29   # one tenant (retry/debug)
 *   php cron/backup_all_tenants.php --list             # show targets, back up nothing
 *
 * Must run as the web server user (www-data) so the Backup & Restore page can
 * list, download and delete the files it writes. Scheduled by
 * scripts/install_backup_cron.php (run on every deploy).
 *
 * Log: backups/_platform/backup.log — inside backups/ because www-data can
 * write there (it cannot write cron/, which deploy owns), and _platform/ is a
 * folder no company's Backup page lists or serves.
 *
 * Exit 0 = every target backed up; 1 = one or more failed (see the log and the
 * control table tenant_backup_log); 2 = bad arguments or already running.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/tenant_backups.php';
require_once __DIR__ . '/../core/backup_health.php';

$opts     = getopt('', ['kind:', 'tenant:', 'list']);
$kind     = $opts['kind'] ?? null;
$tenantId = isset($opts['tenant']) ? (int)$opts['tenant'] : null;

$platformDir = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/backups/_platform/';
bmsEnsureDirGuard($platformDir);
$logFile = $platformDir . 'backup.log';

// Cron discards output, so a fatal error (memory, timeout, missing extension)
// would vanish. Record it where the rest of the run is logged.
register_shutdown_function(function () use ($logFile) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] FATAL {$e['message']} in {$e['file']}:{$e['line']}\n", FILE_APPEND | LOCK_EX);
    }
});

function backup_cli_log(string $logFile, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    echo $line . "\n";
}

if (isset($opts['list'])) {
    foreach (bmsBackupTargets($tenantId) as $t) {
        echo str_pad($t['key'], 9) . ' ' . str_pad($t['db']['name'], 22) . ' → ' . $t['dir']
           . ($t['error'] ? '   [ERROR: ' . $t['error'] . ']' : '') . "\n";
    }
    exit(0);
}

if ($kind !== 'full' && $kind !== 'snapshot') {
    fwrite(STDERR, "Usage: php cron/backup_all_tenants.php --kind=full|snapshot [--tenant=ID] | --list\n");
    exit(2);
}

// One run at a time: a slow full backup must not overlap the next snapshot.
$backupsRoot = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/backups/';
bmsEnsureDirGuard($backupsRoot);
$lock = fopen($backupsRoot . '.backup_run.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    backup_cli_log($logFile, "SKIPPED $kind run — another backup run is still in progress.");
    exit(2);
}

backup_cli_log($logFile, "Starting $kind backup" . ($tenantId !== null ? " of tenant $tenantId" : '') . '.');
$summary = bmsBackupRunAll($kind, $tenantId, fn(string $line) => backup_cli_log($logFile, $line));

if (!$summary['ran']) {
    backup_cli_log($logFile, 'Nothing done: ' . $summary['reason']);
    flock($lock, LOCK_UN);
    exit($tenantId !== null ? 1 : 0);
}

backup_cli_log($logFile, "Finished $kind backup: {$summary['ok']} ok, {$summary['failed']} failed.");
flock($lock, LOCK_UN);

// Health check after every scheduled run of all targets: alerts the superadmins
// (bell + email, once a day) about failed or overdue backups and about the
// off-site copy, whose root-run job cannot reach the database itself.
if ($tenantId === null) {
    try {
        $health = bmsBackupHealth();
        if ($health['problems']) {
            backup_cli_log($logFile, 'Health: ' . count($health['problems']) . ' problem(s) — ' . implode(' | ', $health['problems']));
            if (bmsBackupHealthAlert($health)) backup_cli_log($logFile, 'Health: alert sent to superadmins.');
        }
    } catch (Throwable $e) {
        backup_cli_log($logFile, 'Health check failed: ' . $e->getMessage());
    }
}
exit($summary['failed'] === 0 ? 0 : 1);
