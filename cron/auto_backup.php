<?php
/**
 * cron/auto_backup.php
 * --------------------
 * One full backup of the MAIN install only (no tenants). Kept as a manual tool.
 *
 * NOT THE SCHEDULED BACKUP. The schedule runs cron/backup_all_tenants.php
 * (main install + every tenant + control DB), installed into /etc/cron.d by
 * scripts/install_backup_cron.php on every deploy. Do not also schedule this
 * file — it would duplicate the main install's nightly backup.
 *
 * What it does:
 *   1. Writes backups/auto_backup_<date>.zip (database + the main install's
 *      own uploads) via bms_write_zip_backup().
 *   2. Applies the retention policy (bms_apply_retention, backup_plan.md §3).
 *   3. Updates the .last_auto_backup marker (so the page's on-load auto-backup
 *      doesn't double-run the same day) and logs to cron/backup.log + activity_logs.
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

// bmsBackupDir() rather than the shared literal. This cron runs from the CLI,
// where no tenant is resolved, so it returns the unprefixed path exactly as
// before — the call is what keeps it correct if this is ever invoked in a
// tenant context, and what stops the audit from flagging a genuine offender.
require_once __DIR__ . '/../core/tenant_bootstrap.php';
$backupsDir = bmsBackupDir();
$logFile = __DIR__ . '/backup.log';

function bms_cron_log(string $logFile, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    @file_put_contents($logFile, $line . "\n", FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') echo $line . "\n";
}

try {
    global $pdo;

    $filename   = 'auto_backup_' . date('Y-m-d_H-i-s') . '.zip';
    $filepath   = $backupsDir . $filename;
    $uploadsDir = (defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__)) . '/uploads';

    $stats  = bms_write_zip_backup($pdo, $filepath, $uploadsDir, bmsTenantPathPrefix());
    $bytes  = filesize($filepath);
    $sizeLabel = $bytes >= 1048576 ? round($bytes / 1048576, 2) . ' MB' : round($bytes / 1024, 2) . ' KB';
    bms_cron_log($logFile, "Full backup created: $filename ($sizeLabel, DB: {$stats['db_size_mb']} MB, Files: {$stats['files_count']})");

    // Retention policy — never empties the folder (3 newest always kept).
    $deleted = bms_apply_retention($backupsDir);
    if (!empty($deleted)) {
        bms_cron_log($logFile, "Retention removed " . count($deleted) . " file(s): " . implode(', ', $deleted));
    }

    // Keep the page's daily throttle in sync so it won't duplicate today's run.
    @file_put_contents($backupsDir . '.last_auto_backup', time());

    // Audit trail (system user_id 0 — no session in cron context).
    if (function_exists('logActivity')) {
        try { logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Scheduled Full Backup', "File: $filename, Size: $sizeLabel, Files: {$stats['files_count']}"); } catch (Throwable $e) {}
    }

    bms_cron_log($logFile, "Done.");
    exit(0);

} catch (Throwable $e) {
    if (isset($filepath) && is_file($filepath)) @unlink($filepath);
    bms_cron_log($logFile, "ERROR: " . $e->getMessage());
    exit(1);
}
