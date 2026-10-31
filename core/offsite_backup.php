<?php
/**
 * core/offsite_backup.php
 * -----------------------
 * Off-site copy of the nightly backups: every new auto_backup_*.zip is uploaded,
 * ENCRYPTED, to Google Drive through rclone. Driven by cron/offsite_backup.php.
 * See backup_plan.md §4.
 *
 * Why: after PR 1 every tenant is backed up, but the files live on the same
 * server. If the server is lost, the backups are lost with it.
 *
 * Rules this file enforces:
 *   - Only the daily full backups go off-site (auto_backup_*.zip). Snapshots,
 *     manual, pre-restore and uploaded files stay on the server.
 *   - The rclone remote MUST be a `crypt` remote: files and names are encrypted
 *     on this server before upload. A plain remote is refused, never used.
 *   - Upload never overwrites a file already off-site (--ignore-existing).
 *   - Every uploaded file is verified against the encrypted copy (cryptcheck).
 *   - Off-site retention runs only after a fully successful upload, keeps a
 *     superset of what the server keeps, and always keeps the 3 newest per
 *     folder — a folder whose backups stopped is never emptied.
 *
 * Layout off-site mirrors backups/:  <remote>:<host>/auto_backup_….zip (main
 * install), <remote>:<host>/t29/…, <remote>:<host>/_platform/… (control DB).
 *
 * Runs as root (see bmsBackupCronFile): root alone can read the rclone config,
 * so no web app on the server can read the Google Drive token or the
 * encryption password.
 *
 * Public API:
 *   bmsOffsiteRetentionPlan(array $names, int $now): string[]
 *   bmsOffsiteLocalCandidates(string $backupsRoot, int $now, int $maxAgeDays = 7): string[]
 *   bmsOffsiteRclone(array $cfg, array $args): array{code:int, out:string, err:string}
 *   bmsOffsiteCheck(array $cfg): ?string
 *   bmsOffsiteRun(array $cfg, ?callable $log = null, ?int $now = null): array
 */

require_once __DIR__ . '/backup.php';   // bms_backup_file_info()

if (!function_exists('bmsOffsiteRetentionPlan')) {
    /**
     * Which off-site files of ONE folder to delete.
     *
     * Kept if any rule holds:
     *   newer than 30 days; or the first of its month, newer than 12 months
     *   (+5 days, so the off-site copy always outlives the server's own, which
     *   is judged an hour earlier); or one of the 3 newest — ALWAYS.
     * Only auto_backup_* names are ever returned; anything else is left alone.
     *
     * Superset of the server's rules (7 days / first-of-week 28 days /
     * first-of-month 12 months), and uploads only take files ≤ 7 days old, so a
     * file deleted off-site is never uploaded again the next night.
     *
     * @param string[] $names base names in one folder
     * @return string[] base names to delete, sorted
     */
    function bmsOffsiteRetentionPlan(array $names, int $now): array
    {
        $tz = new DateTimeZone('Africa/Dar_es_Salaam');
        $files = [];
        foreach ($names as $n) {
            $info = bms_backup_file_info((string)$n);
            if ($info !== null && $info['kind'] === 'auto_backup' && str_ends_with((string)$n, '.zip')) {
                $files[(string)$n] = $info['ts'];
            }
        }

        arsort($files);   // newest first
        $keep = array_fill_keys(array_slice(array_keys($files), 0, 3), true);

        $firstOfMonth = [];
        $oldestFirst = $files;
        asort($oldestFirst);
        foreach ($oldestFirst as $n => $ts) {
            $firstOfMonth[(new DateTime('@' . $ts))->setTimezone($tz)->format('Y-m')] ??= $n;
        }
        $monthly = array_flip($firstOfMonth);

        $delete = [];
        foreach ($files as $n => $ts) {
            if (isset($keep[$n])) continue;
            $age = $now - $ts;
            if ($age <= 30 * 86400) continue;
            if (isset($monthly[$n]) && $age <= 370 * 86400) continue;
            $delete[] = $n;
        }
        sort($delete);
        return $delete;
    }
}

if (!function_exists('bmsOffsiteFolderAllowed')) {
    /** Folders under backups/ that hold scheduled backups: '' (main), t{id}, _platform. */
    function bmsOffsiteFolderAllowed(string $folder): bool
    {
        return $folder === '' || $folder === '_platform' || (bool)preg_match('/^t\d+$/', $folder);
    }
}

if (!function_exists('bmsOffsiteLocalCandidates')) {
    /**
     * Daily full backups on this server taken in the last $maxAgeDays days (age
     * read from the file NAME, like the server retention), as paths relative to
     * backups/. A ZIP still being written has a temporary libzip name and never
     * matches.
     *
     * @return string[] e.g. ['auto_backup_….zip', 't29/auto_backup_….zip', '_platform/auto_backup_….zip']
     */
    function bmsOffsiteLocalCandidates(string $backupsRoot, int $now, int $maxAgeDays = 7): array
    {
        $root = rtrim($backupsRoot, '/\\') . '/';
        if (!is_dir($root)) return [];
        $folders = [''];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry !== '' && bmsOffsiteFolderAllowed($entry) && is_dir($root . $entry)) $folders[] = $entry;
        }

        $out = [];
        foreach ($folders as $folder) {
            $dir = $root . ($folder === '' ? '' : $folder . '/');
            foreach (glob($dir . 'auto_backup_*.zip') ?: [] as $path) {
                $name = basename($path);
                $info = bms_backup_file_info($name);
                if ($info === null || $info['kind'] !== 'auto_backup' || !is_file($path)) continue;
                $age = $now - $info['ts'];
                if ($age < 0 || $age > $maxAgeDays * 86400) continue;
                $out[] = ($folder === '' ? '' : $folder . '/') . $name;
            }
        }
        sort($out);
        return $out;
    }
}

if (!function_exists('bmsOffsiteRclone')) {
    /**
     * Run rclone with an argument list — no shell, so no quoting or injection
     * issues. Output goes to temp files, not pipes, so a large listing can never
     * deadlock the call.
     *
     * $cfg: rclone (binary path), config (?string rclone.conf path; null = rclone default)
     */
    function bmsOffsiteRclone(array $cfg, array $args): array
    {
        $cmd = [(string)$cfg['rclone']];
        if (!empty($cfg['config'])) { $cmd[] = '--config'; $cmd[] = (string)$cfg['config']; }
        foreach ($args as $a) $cmd[] = (string)$a;

        $outFile = tempnam(sys_get_temp_dir(), 'bmsrc');
        $errFile = tempnam(sys_get_temp_dir(), 'bmsrc');
        $proc = @proc_open($cmd, [0 => ['file', DIRECTORY_SEPARATOR === '/' ? '/dev/null' : 'NUL', 'r'],
                                  1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        $code = is_resource($proc) ? proc_close($proc) : 127;
        $res  = ['code' => (int)$code, 'out' => (string)@file_get_contents($outFile), 'err' => (string)@file_get_contents($errFile)];
        @unlink($outFile); @unlink($errFile);
        return $res;
    }
}

if (!function_exists('bmsOffsiteErr')) {
    /** Last meaningful line of rclone's stderr, short enough for a log line. */
    function bmsOffsiteErr(array $r): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $r['err'])), 'strlen'));
        $last  = $lines ? end($lines) : 'no error output';
        return 'rclone exit ' . $r['code'] . ': ' . substr($last, 0, 400);
    }
}

if (!function_exists('bmsOffsiteCheck')) {
    /**
     * Is off-site configured and safe to use? null = yes, otherwise the reason.
     * The remote must exist and be of type crypt (encrypted before upload).
     */
    function bmsOffsiteCheck(array $cfg): ?string
    {
        $bin = (string)($cfg['rclone'] ?? '');
        if ($bin === '' || !is_file($bin)) return 'rclone is not installed (expected ' . ($bin ?: '/usr/bin/rclone') . ')';

        $r = bmsOffsiteRclone($cfg, ['config', 'dump']);
        if ($r['code'] !== 0) return 'cannot read the rclone config — ' . bmsOffsiteErr($r);
        $remotes = json_decode($r['out'], true);
        if (!is_array($remotes)) return 'cannot read the rclone config (not JSON)';

        $name = (string)$cfg['remote'];
        if (!isset($remotes[$name])) return "rclone remote '$name' is not configured (run: sudo rclone config)";
        if (($remotes[$name]['type'] ?? '') !== 'crypt') {
            return "rclone remote '$name' is type '" . ($remotes[$name]['type'] ?? '?') . "', not 'crypt' — refusing to upload unencrypted backups";
        }
        return null;
    }
}

if (!function_exists('bmsOffsiteListRemote')) {
    /**
     * Every file under the destination: relative path => size. A destination
     * that does not exist yet (first run) is an empty list.
     *
     * @return array{ok:bool, files:array<string,int>, error:?string}
     */
    function bmsOffsiteListRemote(array $cfg, string $dest): array
    {
        $r = bmsOffsiteRclone($cfg, ['lsf', '-R', '--files-only', '--format', 'ps', '--separator', '|', $dest]);
        if ($r['code'] === 3) return ['ok' => true, 'files' => [], 'error' => null];   // directory not found
        if ($r['code'] !== 0) return ['ok' => false, 'files' => [], 'error' => 'listing off-site failed — ' . bmsOffsiteErr($r)];
        $files = [];
        foreach (explode("\n", trim($r['out'])) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cut = strrpos($line, '|');
            if ($cut === false) continue;
            $files[substr($line, 0, $cut)] = (int)substr($line, $cut + 1);
        }
        return ['ok' => true, 'files' => $files, 'error' => null];
    }
}

if (!function_exists('bmsOffsiteRun')) {
    /**
     * Upload new daily backups, verify them, then apply off-site retention.
     *
     * $cfg: rclone, config (?string), remote (e.g. 'bms-offsite'), host (folder
     *       name off-site), backups (absolute path of backups/)
     *
     * @return array{status:string, uploaded:int, deleted:int, remote_files:int,
     *               remote_bytes:int, error:?string}
     *   status: ok | failed | not_configured
     */
    function bmsOffsiteRun(array $cfg, ?callable $log = null, ?int $now = null): array
    {
        $log = $log ?? function (string $line): void {};
        $now = $now ?? time();
        $out = ['status' => 'failed', 'uploaded' => 0, 'deleted' => 0, 'remote_files' => 0, 'remote_bytes' => 0, 'error' => null];

        $why = bmsOffsiteCheck($cfg);
        if ($why !== null) {
            $out['status'] = 'not_configured';
            $out['error']  = $why;
            return $out;
        }

        $dest  = $cfg['remote'] . ':' . $cfg['host'];
        $local = rtrim((string)$cfg['backups'], '/\\');
        $net   = ['--retries', '3', '--low-level-retries', '10', '--contimeout', '60s', '--timeout', '5m'];

        $before = bmsOffsiteListRemote($cfg, $dest);
        if (!$before['ok']) { $out['error'] = $before['error']; return $out; }

        $toUpload = array_values(array_filter(
            bmsOffsiteLocalCandidates($local, $now),
            fn($p) => !isset($before['files'][$p])
        ));

        if ($toUpload) {
            $list = tempnam(sys_get_temp_dir(), 'bmsul');
            file_put_contents($list, implode("\n", $toUpload) . "\n");
            try {
                $r = bmsOffsiteRclone($cfg, array_merge(
                    ['copy', $local, $dest, '--files-from-raw', $list, '--no-traverse', '--ignore-existing',
                     '--transfers', '2', '--stats', '0'],
                    $net
                ));
                if ($r['code'] !== 0) { $out['error'] = 'upload failed — ' . bmsOffsiteErr($r); return $out; }

                // Compare every uploaded file with its encrypted copy.
                $r = bmsOffsiteRclone($cfg, array_merge(
                    ['cryptcheck', $local, $dest, '--one-way', '--files-from-raw', $list], $net
                ));
                if ($r['code'] !== 0) { $out['error'] = 'verification failed — ' . bmsOffsiteErr($r); return $out; }
            } finally {
                @unlink($list);
            }
            $out['uploaded'] = count($toUpload);
            foreach ($toUpload as $p) $log("uploaded  $p");
        }

        $after = bmsOffsiteListRemote($cfg, $dest);
        if (!$after['ok']) { $out['error'] = $after['error']; return $out; }
        foreach ($toUpload as $p) {
            if (!isset($after['files'][$p])) { $out['error'] = "uploaded file missing off-site: $p"; return $out; }
        }

        // Retention, one folder at a time; unknown folders are never touched.
        $byFolder = [];
        foreach (array_keys($after['files']) as $p) {
            $folder = str_contains($p, '/') ? substr($p, 0, strrpos($p, '/')) : '';
            if (bmsOffsiteFolderAllowed($folder)) $byFolder[$folder][] = basename($p);
        }
        $toDelete = [];
        foreach ($byFolder as $folder => $names) {
            foreach (bmsOffsiteRetentionPlan($names, $now) as $n) {
                $toDelete[] = ($folder === '' ? '' : $folder . '/') . $n;
            }
        }
        if ($toDelete) {
            $list = tempnam(sys_get_temp_dir(), 'bmsdl');
            file_put_contents($list, implode("\n", $toDelete) . "\n");
            try {
                $r = bmsOffsiteRclone($cfg, array_merge(['delete', $dest, '--files-from-raw', $list], $net));
            } finally {
                @unlink($list);
            }
            // The upload already succeeded; a failed clean-up is reported, and
            // simply retried tomorrow.
            if ($r['code'] !== 0) { $out['error'] = 'retention delete failed — ' . bmsOffsiteErr($r); return $out; }
            $out['deleted'] = count($toDelete);
            foreach ($toDelete as $p) $log("deleted   $p (off-site retention)");
            foreach ($toDelete as $p) unset($after['files'][$p]);
        }

        $out['status']       = 'ok';
        $out['remote_files'] = count($after['files']);
        $out['remote_bytes'] = array_sum($after['files']);
        return $out;
    }
}
