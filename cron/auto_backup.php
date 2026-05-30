<?php
/**
 * cron/auto_backup.php
 * --------------------
 * Scheduled nightly database backup. Runs independently of the UI so a backup
 * is GUARANTEED even if no admin ever opens the Backup & Restore page.
 *
 * What it does:
 *   1. Writes backups/auto_backup_<date>.sql (schema + data + views) via the
 *      shared bms_write_dump() helper.
 *   2. Prunes auto_backup_* and pre_restore_* files older than 7 days
 *      (manual/uploaded backups are never auto-deleted).
 *   3. Updates the .last_auto_backup marker (so the page's on-load auto-backup
 *      doesn't double-run the same day) and logs to cron/backup.log + activity_logs.
 *
 * SCHEDULING
 *   Windows (this WAMP box) — Task Scheduler, daily at 00:00:
 *     schtasks /create /tn "BMS Daily Backup" /sc daily /st 00:00 /ru SYSTEM ^
 *       /tr "C:\wamp64\bin\php\php8.2.12\php.exe C:\wamp64\www\bms\cron\auto_backup.php"
 *     (adjust the php.exe path to your installed version)
 *
 *   Linux (production) — crontab:
 *     0 0 * * * /usr/bin/php /var/www/bms/cron/auto_backup.php >> /var/www/bms/cron/backup.log 2>&1
 *
 * Safe to run manually any time:  php cron/auto_backup.php
 */

// CLI-only by default; allow a guarded web trigger for admins.
$isCli = (PHP_SAPI === 'cli');

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/backup.php';

if (!$isCli) {
    // Web invocation must be an authenticated admin.
    if (!function_exists('isAuthenticated') || !isAuthenticated() || !isAdmin()) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$retentionDays = 7;
$backupsDir = ROOT_DIR . '/backups/';
if (!is_dir($backupsDir)) @mkdir($backupsDir, 0755, true);
$logFile = __DIR__ . '/backup.log';

function bms_cron_log(string $logFile, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') echo $line . "\n";
}

try {
    global $pdo;

    $filename = 'auto_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupsDir . $filename;

    bms_write_dump($pdo, $filepath);
    $sizeKb = round(filesize($filepath) / 1024, 2);
    $sizeLabel = $sizeKb >= 1024 ? round($sizeKb / 1024, 2) . ' MB' : $sizeKb . ' KB';
    bms_cron_log($logFile, "Backup created: $filename ($sizeLabel)");

    // Prune auto/pre_restore backups older than the retention window.
    $deleted = bms_prune_backups($backupsDir, $retentionDays);
    if (!empty($deleted)) {
        bms_cron_log($logFile, "Pruned " . count($deleted) . " file(s) older than {$retentionDays} days: " . implode(', ', $deleted));
    }

    // Keep the page's daily throttle in sync so it won't duplicate today's run.
    @file_put_contents($backupsDir . '.last_auto_backup', time());

    // Audit trail (system user_id 0 — no session in cron context).
    if (function_exists('logActivity')) {
        try { logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Scheduled Database Backup', "File: $filename, Size: $sizeLabel"); } catch (Throwable $e) {}
    }

    bms_cron_log($logFile, "Done.");
    exit(0);

} catch (Throwable $e) {
    if (isset($filepath) && is_file($filepath)) @unlink($filepath);
    bms_cron_log($logFile, "ERROR: " . $e->getMessage());
    exit(1);
}
