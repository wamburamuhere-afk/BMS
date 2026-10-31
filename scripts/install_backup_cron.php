<?php
/**
 * scripts/install_backup_cron.php — install this host's backup schedule.
 *
 *   sudo php scripts/install_backup_cron.php --host=bms.bjptechnologies.co.tz [--offset=30]
 *   php scripts/install_backup_cron.php --host=… --print     # show the file, install nothing
 *
 * Writes /etc/cron.d/bms-backup-<host> (content: bmsBackupCronFile() in
 * core/tenant_backups.php). Run by deploy.yml on every deploy, so the schedule
 * can never be forgotten or drift; idempotent — an unchanged file is left alone.
 * Touches only its own file: other systems' cron entries on the server are
 * never read or changed.
 *
 * Exit 0 = installed or already current; 1 = could not install.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../core/tenant_backups.php';

$opts    = getopt('', ['host:', 'offset:', 'print']);
$host    = trim((string)($opts['host'] ?? ''));
$offset  = (int)($opts['offset'] ?? 0);
$appRoot = dirname(__DIR__);

if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host)) {
    fwrite(STDERR, "Usage: sudo php scripts/install_backup_cron.php --host=<domain> [--offset=<minutes>] [--print]\n");
    exit(1);
}
if ($offset < 0 || $offset > 120) {
    fwrite(STDERR, "--offset must be between 0 and 120 minutes.\n");
    exit(1);
}

// The server clock's UTC offset, from the OS — PHP's own timezone setting is the
// application's (Africa/Dar_es_Salaam), not what cron runs on.
$serverOffset = 0;
$z = trim((string)@shell_exec('date +%z 2>/dev/null'));
if (preg_match('/^([+-])(\d{2})(\d{2})$/', $z, $m)) {
    $serverOffset = ($m[1] === '-' ? -1 : 1) * ((int)$m[2] * 60 + (int)$m[3]);
} else {
    fwrite(STDERR, "warning: could not read the server timezone (date +%z) — assuming UTC.\n");
}

$phpBin  = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
$content = bmsBackupCronFile($host, $appRoot, $phpBin, $serverOffset, $offset);

if (isset($opts['print'])) { echo $content; exit(0); }

if (DIRECTORY_SEPARATOR !== '/' || !is_dir('/etc/cron.d')) {
    fwrite(STDERR, "No /etc/cron.d on this machine — install the schedule by hand (see --print).\n");
    exit(1);
}
// php-posix is not always installed; `id -u` answers the same question.
$euid = function_exists('posix_geteuid') ? posix_geteuid() : (int)trim((string)@shell_exec('id -u 2>/dev/null'));
if ($euid !== 0) {
    fwrite(STDERR, "Must run as root (sudo) to write /etc/cron.d.\n");
    exit(1);
}

// The folder the backup run logs into must exist and belong to www-data before
// the first scheduled run (it runs as www-data).
$platformDir = $appRoot . '/backups/_platform';
if (!is_dir($platformDir)) @mkdir($platformDir, 0750, true);
if (!is_file($platformDir . '/.htaccess')) {
    @file_put_contents($platformDir . '/.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n");
}
@chown($platformDir, 'www-data'); @chgrp($platformDir, 'www-data');
@chown($platformDir . '/.htaccess', 'www-data'); @chgrp($platformDir . '/.htaccess', 'www-data');

$target = '/etc/cron.d/' . bmsBackupCronFileName($host);
if (is_file($target) && file_get_contents($target) === $content) {
    echo "Backup schedule already current: $target\n";
    exit(0);
}

// Atomic replace; cron requires root-owned, not group/other-writable files.
$tmp = $target . '.tmp' . getmypid();
if (@file_put_contents($tmp, $content) === false || !@chmod($tmp, 0644) || !@rename($tmp, $target)) {
    @unlink($tmp);
    fwrite(STDERR, "Could not write $target\n");
    exit(1);
}
echo "Backup schedule installed: $target\n" . $content;
exit(0);
