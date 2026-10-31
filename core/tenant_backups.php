<?php
/**
 * core/tenant_backups.php
 * -----------------------
 * Scheduled backups of EVERY database this BMS install owns: the original
 * install (legacy DB_NAME), every provisioned tenant, and the control database.
 * Driven by cron/backup_all_tenants.php. See backup_plan.md.
 *
 * Why this exists: cron/auto_backup.php runs from the CLI where no tenant is
 * resolved, so it only ever backed up the legacy database. On 2026-10-31, 23 of
 * 25 live tenants on production had never been backed up at all.
 *
 * Rules this file enforces:
 *   - One target's failure never stops the others (same principle as
 *     core/tenant_migration_runner.php).
 *   - Each tenant's files land in ITS OWN backups/t{id}/ — the exact folder its
 *     Backup & Restore page lists — and hold only its own uploads
 *     (bms_write_zip_backup + bms_upload_in_scope).
 *   - Each dump is taken inside START TRANSACTION WITH CONSISTENT SNAPSHOT, so a
 *     shop selling while the backup runs still gets a coherent restore point.
 *   - A file is only reported OK after it has been re-opened and checked.
 *
 * Two kinds of run:
 *   full     — database + the owner's uploads → auto_backup_<ts>.zip   (daily)
 *   snapshot — database only                  → auto_snapshot_<ts>.zip (6-hourly)
 *
 * tenant-audit: skip — a fleet-wide job like core/tenant_migration_runner.php:
 * it runs from the CLI with no request tenant and must connect to EVERY
 * database by name (main install via DB_*, each tenant via its decrypted
 * credentials, the control DB via controlDbSettings()). The per-request
 * accessors (bmsCurrentDbConfig/bmsCurrentDbName) answer "this request's
 * database" and cannot serve that. Uploads/backup paths are still scoped per
 * owner through bms_write_zip_backup() + bms_upload_in_scope().
 *
 * Public API:
 *   bmsBackupTargets(?int $onlyTenantId = null): array
 *   bmsBackupRunTarget(array $target, string $kind): array
 *   bmsBackupRunAll(string $kind, ?int $onlyTenantId = null, ?callable $log = null): array
 *   bmsBackupLogSchema(PDO $pdo, string $db): string[]
 */

require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/control_db.php';
require_once __DIR__ . '/tenant_crypto.php';
require_once __DIR__ . '/tenant_bootstrap.php';   // bmsEnsureDirGuard()

if (!function_exists('bmsBackupTargets')) {
    /**
     * Every database to back up on this host.
     *
     * @return array<int, array{
     *   key:string, label:string, tenant_id:?int, subdomain:?string,
     *   db:array{host:string,user:string,pass:?string,name:string},
     *   prefix:string, dir:string, uploads:bool, error:?string
     * }>
     *   `error` is set (and `db.pass` null) when a tenant's credentials cannot be
     *   decrypted — the target is still listed so the failure is reported.
     */
    function bmsBackupTargets(?int $onlyTenantId = null): array
    {
        $root    = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $base    = rtrim($root, '/\\') . '/backups/';
        $targets = [];

        // The original install. Skipped when a single tenant was asked for.
        if ($onlyTenantId === null && defined('DB_NAME')) {
            $targets[] = [
                'key' => 'legacy', 'label' => 'Main install (' . DB_NAME . ')',
                'tenant_id' => null, 'subdomain' => null,
                'db' => ['host' => DB_SERVER, 'user' => DB_USERNAME, 'pass' => DB_PASSWORD, 'name' => DB_NAME],
                'prefix' => '', 'dir' => $base, 'uploads' => true, 'error' => null,
            ];
        }

        if (!controlDbReady()) return $targets;   // single-tenant host

        // Suspended and archived tenants keep every byte and can be reactivated —
        // back them up. 'deleted' tenants have no database left, and a row with
        // no db_name never finished provisioning, so there is nothing to dump.
        $sql  = "SELECT id, company_name, subdomain, db_host, db_name, db_username, db_password_encrypted
                 FROM tenants WHERE status != 'deleted' AND db_name IS NOT NULL AND db_name != ''";
        $args = [];
        if ($onlyTenantId !== null) { $sql .= ' AND id = ?'; $args[] = $onlyTenantId; }
        $st = getControlPdo()->prepare($sql . ' ORDER BY id');
        $st->execute($args);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            // The legacy install may also be registered as a tenant row; it is
            // already covered above under its unprefixed paths.
            if (defined('DB_NAME') && (string)$t['db_name'] === (string)DB_NAME) continue;

            // decryptTenantSecret() THROWS when the key itself is missing — that
            // must fail this target, not abort the run for everyone after it.
            try {
                $pass = decryptTenantSecret((string)$t['db_password_encrypted']);
            } catch (Throwable $e) {
                $pass = null;
            }
            $prefix = 't' . (int)$t['id'] . '/';
            $targets[] = [
                'key' => 't' . (int)$t['id'],
                'label' => (string)$t['company_name'],
                'tenant_id' => (int)$t['id'], 'subdomain' => (string)$t['subdomain'],
                'db' => ['host' => (string)$t['db_host'], 'user' => (string)$t['db_username'],
                         'pass' => $pass, 'name' => (string)$t['db_name']],
                'prefix' => $prefix, 'dir' => $base . $prefix, 'uploads' => true,
                'error' => $pass === null ? 'could not decrypt stored credentials (check TENANT_CRED_KEY)' : null,
            ];
        }

        // The control database — the registry of every company. Database only;
        // kept in a folder no tenant's Backup page can list.
        if ($onlyTenantId === null) {
            $c = controlDbSettings();
            $targets[] = [
                'key' => 'control', 'label' => 'Control database (' . $c['name'] . ')',
                'tenant_id' => null, 'subdomain' => null,
                'db' => ['host' => $c['host'], 'user' => $c['user'], 'pass' => $c['pass'], 'name' => $c['name']],
                'prefix' => '', 'dir' => $base . '_platform/', 'uploads' => false, 'error' => null,
            ];
        }

        return $targets;
    }
}

if (!function_exists('bmsBackupVerifyZip')) {
    /**
     * Re-open a written backup and confirm it is whole: the archive passes a
     * consistency check, has a manifest, and its dump ends with the closing
     * line bms_write_dump() writes last (a truncated dump would not).
     *
     * @throws Exception describing the first problem found.
     */
    function bmsBackupVerifyZip(string $zipPath): void
    {
        $z = new ZipArchive();
        if ($z->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new Exception('written ZIP fails the consistency check');
        }
        try {
            if ($z->locateName('manifest.json') === false) throw new Exception('written ZIP has no manifest.json');
            $stat = $z->statName('database.sql');
            if ($stat === false || $stat['size'] <= 0) throw new Exception('written ZIP has no database.sql');

            $fp = $z->getStream('database.sql');
            if (!$fp) throw new Exception('cannot read database.sql back');
            // Keep only the tail while streaming; dumps can be large.
            $tail = '';
            while (!feof($fp)) $tail = substr($tail . fread($fp, 65536), -256);
            fclose($fp);
            if (substr(rtrim($tail), -strlen('SET FOREIGN_KEY_CHECKS=1;')) !== 'SET FOREIGN_KEY_CHECKS=1;') {
                throw new Exception('database.sql is truncated (missing closing line)');
            }
        } finally {
            $z->close();
        }
    }
}

if (!function_exists('bmsBackupRunTarget')) {
    /**
     * Back up one target.
     *
     * @param string $kind 'full' | 'snapshot'
     * @return array{key:string, ok:bool, file:?string, bytes:int, files_count:int, ms:int, error:?string}
     */
    function bmsBackupRunTarget(array $target, string $kind): array
    {
        $started = microtime(true);
        $out = ['key' => $target['key'], 'ok' => false, 'file' => null, 'bytes' => 0,
                'files_count' => 0, 'ms' => 0, 'error' => null];

        if ($kind !== 'full' && $kind !== 'snapshot') {
            $out['error'] = "unknown backup kind '$kind'";
            return $out;
        }
        if ($target['error'] !== null) {
            $out['error'] = $target['error'];
            return $out;
        }

        $root     = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $filename = ($kind === 'full' ? 'auto_backup_' : 'auto_snapshot_') . date('Y-m-d_H-i-s') . '.zip';
        $zipPath  = $target['dir'] . $filename;

        try {
            bmsEnsureDirGuard($target['dir']);

            $db  = $target['db'];
            $pdo = new PDO(
                'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4',
                $db['user'], (string)$db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec("SET time_zone = '+03:00'");
            // One consistent view of every table for the whole dump.
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            try {
                $stats = bms_write_zip_backup(
                    $pdo, $zipPath, rtrim($root, '/\\') . '/uploads', $target['prefix'],
                    $kind === 'full' && $target['uploads']
                );
            } finally {
                try { $pdo->exec('COMMIT'); } catch (Throwable $e) {}
            }
            $pdo = null;

            bmsBackupVerifyZip($zipPath);
            @chmod($zipPath, 0640);

            // Keep the Backup page's own once-a-day fallback from running a
            // duplicate on top of a scheduled full backup.
            if ($kind === 'full' && $target['key'] !== 'control') {
                @file_put_contents($target['dir'] . '.last_auto_backup', time());
            }

            $out['ok']          = true;
            $out['file']        = $filename;
            $out['bytes']       = (int)filesize($zipPath);
            $out['files_count'] = (int)$stats['files_count'];
        } catch (Throwable $e) {
            if (is_file($zipPath)) @unlink($zipPath);
            // A PDO connection error names host/db/user, never the password.
            $out['error'] = $e->getMessage();
        }

        $out['ms'] = (int)round((microtime(true) - $started) * 1000);
        return $out;
    }
}

if (!function_exists('bmsBackupLogSchema')) {
    /**
     * The control-database table that records every scheduled backup attempt.
     * Called from scripts/setup_control_db.php; also created on demand by
     * bmsBackupLogWrite() so a host that has not re-run setup still logs.
     *
     * @return string[] table names ensured
     */
    function bmsBackupLogSchema(PDO $pdo, string $db): array
    {
        // No FK: a tenant can be deleted long after its backup history matters.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `{$db}`.`tenant_backup_log` (
                `id`          BIGINT AUTO_INCREMENT PRIMARY KEY,
                `target_key`  VARCHAR(32)  NOT NULL,
                `tenant_id`   INT          NULL,
                `subdomain`   VARCHAR(63)  NULL,
                `kind`        ENUM('full','snapshot') NOT NULL,
                `status`      ENUM('ok','failed') NOT NULL,
                `file_name`   VARCHAR(191) NULL,
                `bytes`       BIGINT       NOT NULL DEFAULT 0,
                `files_count` INT          NOT NULL DEFAULT 0,
                `duration_ms` INT          NOT NULL DEFAULT 0,
                `message`     TEXT         NULL,
                `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `idx_backuplog_target` (`target_key`, `created_at`),
                KEY `idx_backuplog_status` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        ");
        return ['tenant_backup_log'];
    }
}

if (!function_exists('bmsBackupLogWrite')) {
    /** Best-effort audit row. A logging failure must never fail a backup. */
    function bmsBackupLogWrite(array $target, string $kind, array $result): void
    {
        if (!controlDbReady()) return;
        try {
            $cpdo = getControlPdo();
            static $ensured = false;
            if (!$ensured) {
                try { bmsBackupLogSchema($cpdo, controlDbName()); } catch (Throwable $e) {}
                $ensured = true;
            }
            $cpdo->prepare("
                INSERT INTO tenant_backup_log
                    (target_key, tenant_id, subdomain, kind, status, file_name, bytes, files_count, duration_ms, message)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $target['key'], $target['tenant_id'], $target['subdomain'], $kind,
                $result['ok'] ? 'ok' : 'failed', $result['file'], $result['bytes'],
                $result['files_count'], $result['ms'],
                $result['error'] === null ? null : substr($result['error'], 0, 2000),
            ]);
        } catch (Throwable $e) {
            error_log('tenant_backup_log write failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('bmsBackupCronFile')) {
    /**
     * Content of the /etc/cron.d file that schedules this host's backups.
     *
     * The schedule is written in Africa/Dar_es_Salaam time (what the business
     * thinks in) and converted to the server clock, which is UTC on production
     * (a file PHP named 11-52 showed 08:52 in `ls`). Debian/Ubuntu cron ignores
     * CRON_TZ, so the conversion has to happen here.
     *
     *   full     00:30            (DB + each owner's uploads)
     *   snapshot 06:30 12:30 18:30 (DB only — 00:30 is already the full run)
     *
     * $offsetMinutes staggers a second host on the same server so two runs never
     * compete for the disk and database at the same moment.
     *
     * No output redirection to a file: if cron's shell cannot open a redirect
     * target the command never starts, silently skipping every backup. The
     * script writes its own log (backups/_platform/backup.log).
     */
    function bmsBackupCronFile(string $host, string $appRoot, string $phpBin, int $serverUtcOffsetMinutes, int $offsetMinutes = 0): string
    {
        $eatOffset = 180;   // Africa/Dar_es_Salaam is UTC+03:00, no DST
        $toServer  = function (int $eatMinuteOfDay) use ($eatOffset, $serverUtcOffsetMinutes, $offsetMinutes): array {
            $m = ($eatMinuteOfDay - $eatOffset + $serverUtcOffsetMinutes + $offsetMinutes) % 1440;
            if ($m < 0) $m += 1440;
            return [intdiv($m, 60), $m % 60];
        };

        [$fh, $fm] = $toServer(30);                       // 00:30
        $snaps = array_map($toServer, [390, 750, 1110]);  // 06:30 12:30 18:30
        $snapMinutes = array_unique(array_column($snaps, 1));
        $snapHours   = array_column($snaps, 0);
        sort($snapHours);

        $script = rtrim($appRoot, '/') . '/cron/backup_all_tenants.php';
        $sign   = $serverUtcOffsetMinutes < 0 ? '-' : '+';
        $abs    = abs($serverUtcOffsetMinutes);
        $tzText = sprintf('UTC%s%02d:%02d', $sign, intdiv($abs, 60), $abs % 60);

        $lines = [
            "# BMS scheduled backups for {$host}.",
            '# Written by scripts/install_backup_cron.php on every deploy — edits here are overwritten.',
            "# Times are server time ({$tzText}); in Africa/Dar_es_Salaam: full 00:30, snapshots 06:30 12:30 18:30"
                . ($offsetMinutes ? " (+{$offsetMinutes} min stagger)." : '.'),
            '# Log: backups/_platform/backup.log  ·  History: bms_control.tenant_backup_log',
            'MAILTO=""',
            'SHELL=/bin/sh',
            "{$fm} {$fh} * * * www-data {$phpBin} {$script} --kind=full >/dev/null 2>&1",
        ];
        // The three snapshot times share one minute unless the offsets are odd;
        // fall back to one line each so no time is ever dropped.
        if (count($snapMinutes) === 1) {
            $lines[] = $snapMinutes[0] . ' ' . implode(',', $snapHours) . " * * * www-data {$phpBin} {$script} --kind=snapshot >/dev/null 2>&1";
        } else {
            foreach ($snaps as [$h, $m]) {
                $lines[] = "{$m} {$h} * * * www-data {$phpBin} {$script} --kind=snapshot >/dev/null 2>&1";
            }
        }
        return implode("\n", $lines) . "\n";
    }
}

if (!function_exists('bmsBackupCronFileName')) {
    /** /etc/cron.d file name for a host. Cron skips names containing '.', so dots become '-'. */
    function bmsBackupCronFileName(string $host): string
    {
        return 'bms-backup-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $host);
    }
}

if (!function_exists('bmsBackupRunAll')) {
    /**
     * Back up every target, one after another. Never throws for a single
     * target's failure.
     *
     * @param callable|null $log     fn(string $line) — progress lines
     * @param array|null    $targets explicit targets (tests); default bmsBackupTargets()
     * @return array{ran:bool, reason:?string, kind:string, ok:int, failed:int, results:array}
     */
    function bmsBackupRunAll(string $kind, ?int $onlyTenantId = null, ?callable $log = null, ?array $targets = null): array
    {
        $log = $log ?? function (string $line): void {};
        $out = ['ran' => false, 'reason' => null, 'kind' => $kind, 'ok' => 0, 'failed' => 0, 'results' => []];

        $targets = $targets ?? bmsBackupTargets($onlyTenantId);
        if (!$targets) {
            $out['reason'] = $onlyTenantId !== null ? "no live tenant with id {$onlyTenantId}" : 'nothing to back up';
            return $out;
        }

        $out['ran'] = true;
        foreach ($targets as $t) {
            $r = bmsBackupRunTarget($t, $kind);
            bmsBackupLogWrite($t, $kind, $r);
            $out['results'][] = $r + ['label' => $t['label']];
            if ($r['ok']) {
                $out['ok']++;
                $size = $r['bytes'] >= 1048576 ? round($r['bytes'] / 1048576, 2) . ' MB' : round($r['bytes'] / 1024, 1) . ' KB';
                $log("OK     {$t['key']} — {$t['label']}: {$r['file']} ($size, files: {$r['files_count']}, {$r['ms']} ms)");
                // Retention only after a NEW backup exists — a failing target
                // never loses its old files.
                $removed = bms_apply_retention($t['dir']);
                if ($removed) $log("       {$t['key']} retention removed " . count($removed) . ': ' . implode(', ', $removed));
            } else {
                $out['failed']++;
                $log("FAILED {$t['key']} — {$t['label']}: {$r['error']}");
            }
        }
        return $out;
    }
}
