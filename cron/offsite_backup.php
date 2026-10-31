<?php
/**
 * cron/offsite_backup.php
 * -----------------------
 * Upload the nightly backups, encrypted, to Google Drive (rclone crypt remote).
 * Logic lives in core/offsite_backup.php; setup steps in backup_plan.md §4.
 *
 * Usage (as root — only root can read the rclone config):
 *   sudo php cron/offsite_backup.php --host=bms.bjptechnologies.co.tz
 *   sudo php cron/offsite_backup.php --host=… --check     # test the setup, upload nothing
 *
 * Options for tests: --rclone=<binary> --config=<rclone.conf> --remote=<name>
 *                    --backups=<dir> --no-wait
 *
 * Scheduled by scripts/install_backup_cron.php one hour after the full backup.
 * Waits for a running backup to finish first (backups/.backup_run.lock).
 *
 * Log:    backups/_platform/offsite.log
 * Status: backups/_platform/offsite_status.json (last run, for monitoring)
 *
 * Exit 0 = uploaded and verified (or nothing new); 1 = failed;
 *      2 = bad arguments or already running; 3 = off-site not configured.
 *
 * Deliberately loads no database or app bootstrap: it runs as root and needs
 * nothing but files.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../core/offsite_backup.php';

$opts = getopt('', ['host:', 'check', 'rclone:', 'config:', 'remote:', 'backups:', 'no-wait']);
$host = trim((string)($opts['host'] ?? ''));
if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host)) {
    fwrite(STDERR, "Usage: sudo php cron/offsite_backup.php --host=<domain> [--check]\n");
    exit(2);
}

$backups = rtrim((string)($opts['backups'] ?? dirname(__DIR__) . '/backups'), '/\\');
$rclone  = (string)($opts['rclone'] ?? '');
if ($rclone === '') {
    foreach (['/usr/bin/rclone', '/usr/local/bin/rclone'] as $p) {
        if (is_file($p)) { $rclone = $p; break; }
    }
}
$cfg = [
    'rclone'  => $rclone,
    'config'  => $opts['config'] ?? null,
    'remote'  => (string)($opts['remote'] ?? 'bms-offsite'),
    'host'    => $host,
    'backups' => $backups,
];

if (isset($opts['check'])) {
    $why = bmsOffsiteCheck($cfg);
    if ($why === null) {
        $l = bmsOffsiteListRemote($cfg, $cfg['remote'] . ':' . $host);
        $why = $l['ok'] ? null : $l['error'];
        if ($why === null) {
            echo "OK — off-site is ready: {$cfg['remote']}:{$host} (encrypted), " . count($l['files']) . " file(s) there now.\n";
            exit(0);
        }
    }
    echo "NOT READY — $why\n";
    exit(3);
}

// Same zone the backup files are named in (no app bootstrap loads it here).
date_default_timezone_set('Africa/Dar_es_Salaam');

// This runs as root inside a folder www-data owns: hand every file it creates
// back to www-data so the folder stays uniform and the monitoring page can
// read it. Never chown anything else.
function offsite_own(string $path): void
{
    static $isRoot = null;
    // php-posix is not always installed; `id -u` answers the same question.
    $isRoot ??= function_exists('posix_geteuid')
        ? posix_geteuid() === 0
        : DIRECTORY_SEPARATOR === '/' && trim((string)@shell_exec('id -u 2>/dev/null')) === '0';
    if ($isRoot) { @chown($path, 'www-data'); @chgrp($path, 'www-data'); }
}

$platformDir = $backups . '/_platform/';
if (!is_dir($platformDir) && @mkdir($platformDir, 0750, true)) offsite_own($platformDir);
$logFile    = $platformDir . 'offsite.log';
$statusFile = $platformDir . 'offsite_status.json';

function offsite_log(string $logFile, string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $new = !is_file($logFile);
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    if ($new) offsite_own($logFile);
    echo $line . "\n";
}

register_shutdown_function(function () use ($logFile) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] FATAL {$e['message']} in {$e['file']}:{$e['line']}\n", FILE_APPEND | LOCK_EX);
    }
});

// One off-site run at a time. The lock lives in the temp dir, NOT in backups/:
// a root-owned file there could block the www-data backup run.
$lock = fopen(sys_get_temp_dir() . '/bms-offsite-' . md5($backups) . '.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    offsite_log($logFile, 'SKIPPED — another off-site run is still in progress.');
    exit(2);
}

// Let a running backup finish (up to 3 h). Open it read-only and never create
// it — the backup run (www-data) must always be able to open it itself.
$backupLock = $backups . '/.backup_run.lock';
if (!isset($opts['no-wait']) && is_file($backupLock) && ($bl = @fopen($backupLock, 'r'))) {
    $deadline = time() + 3 * 3600;
    while (!flock($bl, LOCK_SH | LOCK_NB)) {
        if (time() > $deadline) {
            offsite_log($logFile, 'FAILED — a backup run has held the lock for over 3 hours.');
            exit(1);
        }
        sleep(30);
    }
    flock($bl, LOCK_UN);   // released at once: never block the next snapshot
    fclose($bl);
}

offsite_log($logFile, "Starting off-site upload to {$cfg['remote']}:{$host}.");
$started = time();
$res = bmsOffsiteRun($cfg, fn(string $line) => offsite_log($logFile, $line));

if ($res['status'] === 'ok') {
    $mb = round($res['remote_bytes'] / 1048576, 1);
    offsite_log($logFile, "Finished: {$res['uploaded']} uploaded, {$res['deleted']} removed by retention; off-site now {$res['remote_files']} files, {$mb} MB.");
} elseif ($res['status'] === 'not_configured') {
    offsite_log($logFile, 'NOT CONFIGURED — ' . $res['error']);
} else {
    offsite_log($logFile, 'FAILED — ' . $res['error']);
}

$newStatus = !is_file($statusFile);
@file_put_contents($statusFile, json_encode($res + [
    'host' => $host, 'remote' => $cfg['remote'],
    'started_at' => date('c', $started), 'finished_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
if ($newStatus) offsite_own($statusFile);

flock($lock, LOCK_UN);
exit(['ok' => 0, 'failed' => 1, 'not_configured' => 3][$res['status']] ?? 1);
