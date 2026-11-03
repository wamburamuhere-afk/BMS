<?php
/**
 * core/backup_health.php
 * ----------------------
 * Is every company's data actually backed up? Read side of the scheduled
 * backups (backup_plan.md PR 3): the superadmin Backups page
 * (app/superadmin/backups.php) and the daily alert raised at the end of every
 * backup run (cron/backup_all_tenants.php).
 *
 * Sources — nothing new is recorded here:
 *   bms_control.tenant_backup_log           every backup attempt (core/tenant_backups.php)
 *   backups/_platform/offsite_status.json   last off-site upload (cron/offsite_backup.php)
 *
 * A target is a PROBLEM when its latest attempt failed, or its last good full
 * backup is older than 26 hours (one missed night + slack), or it has never
 * been backed up (a company created in the last 26 hours is only "pending").
 * Off-site is a problem when the last run failed, is older than 26 hours, never
 * ran, or is not configured.
 *
 * tenant-audit: skip — a platform-wide read like core/tenant_backups.php: it
 * lists every database by name (main install via DB_NAME, so a main install
 * also registered as a tenant row is not reported twice) and never connects
 * to any tenant database.
 *
 * Public API:
 *   bmsBackupHealth(): array
 *   bmsBackupHealthOffsite(?string $statusFile = null): array
 *   bmsBackupHealthAlert(array $health, ?string $markerFile = null): bool
 */

require_once __DIR__ . '/control_db.php';

if (!function_exists('bmsBackupHealthStaleMinutes')) {
    /** A good full backup older than this is overdue: one missed night + 2 h slack. */
    function bmsBackupHealthStaleMinutes(): int { return 26 * 60; }
}

if (!function_exists('bmsBackupHealthTargets')) {
    /**
     * What the backup run covers (same rules as bmsBackupTargets(), but without
     * decrypting any credentials).
     *
     * @return array<int, array{key:string, label:string, tenant_id:?int, subdomain:?string, age_min:?int}>
     *   age_min = minutes since the company was created (null for main/control)
     */
    function bmsBackupHealthTargets(PDO $cpdo): array
    {
        $out = [];
        if (defined('DB_NAME')) {
            $out[] = ['key' => 'legacy', 'label' => 'Main install (' . DB_NAME . ')', 'tenant_id' => null, 'subdomain' => null, 'age_min' => null];
        }
        $rows = $cpdo->query("
            SELECT id, company_name, subdomain, db_name, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age_min
              FROM tenants
             WHERE status != 'deleted' AND db_name IS NOT NULL AND db_name != ''
             ORDER BY id
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $t) {
            if (defined('DB_NAME') && (string)$t['db_name'] === (string)DB_NAME) continue;
            $out[] = ['key' => 't' . (int)$t['id'], 'label' => (string)$t['company_name'], 'tenant_id' => (int)$t['id'],
                      'subdomain' => (string)$t['subdomain'], 'age_min' => $t['age_min'] === null ? null : (int)$t['age_min']];
        }
        $out[] = ['key' => 'control', 'label' => 'Control database (' . controlDbName() . ')', 'tenant_id' => null, 'subdomain' => null, 'age_min' => null];
        return $out;
    }
}

if (!function_exists('bmsBackupHealthClassify')) {
    /**
     * State of one target from its latest log rows.
     *
     * @param array $last  ['full_ok'=>row|null, 'full_failed'=>row|null, 'snapshot_ok'=>row|null,
     *                      'snapshot_failed'=>row|null]; each row has id, age_min, message …
     * @return string ok | failed | overdue | never | pending
     */
    function bmsBackupHealthClassify(array $last, ?int $targetAgeMin): string
    {
        $newest = null;
        foreach ($last as $row) {
            if ($row !== null && ($newest === null || (int)$row['id'] > (int)$newest['id'])) $newest = $row;
        }
        if ($newest === null) {
            return ($targetAgeMin !== null && $targetAgeMin < bmsBackupHealthStaleMinutes()) ? 'pending' : 'never';
        }
        if ($newest === $last['full_failed'] || $newest === $last['snapshot_failed']) return 'failed';
        if ($last['full_ok'] === null || (int)$last['full_ok']['age_min'] > bmsBackupHealthStaleMinutes()) {
            return ($last['full_ok'] === null && $targetAgeMin !== null && $targetAgeMin < bmsBackupHealthStaleMinutes()) ? 'pending' : 'overdue';
        }
        return 'ok';
    }
}

if (!function_exists('bmsBackupHealthOffsite')) {
    /**
     * Last off-site upload, from the status file cron/offsite_backup.php writes.
     *
     * @return array{state:string, problem:?string, data:?array, age_min:?int}
     *   state: ok | failed | overdue | not_configured | never
     */
    function bmsBackupHealthOffsite(?string $statusFile = null): array
    {
        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $statusFile = $statusFile ?? rtrim($root, '/\\') . '/backups/_platform/offsite_status.json';

        $data = is_file($statusFile) ? json_decode((string)@file_get_contents($statusFile), true) : null;
        if (!is_array($data)) {
            return ['state' => 'never', 'problem' => 'Off-site copy has never run (Google Drive not set up yet — backup_plan.md §4).', 'data' => null, 'age_min' => null];
        }
        $ts  = strtotime((string)($data['finished_at'] ?? ''));
        $age = $ts ? (int)floor((time() - $ts) / 60) : null;
        $status = (string)($data['status'] ?? '');

        if ($status === 'not_configured') {
            return ['state' => 'not_configured', 'problem' => 'Off-site copy is not configured: ' . ($data['error'] ?? ''), 'data' => $data, 'age_min' => $age];
        }
        if ($status !== 'ok') {
            return ['state' => 'failed', 'problem' => 'Off-site upload failed: ' . ($data['error'] ?? 'unknown error'), 'data' => $data, 'age_min' => $age];
        }
        if ($age === null || $age > bmsBackupHealthStaleMinutes()) {
            return ['state' => 'overdue', 'problem' => 'Off-site upload has not succeeded for over 26 hours.', 'data' => $data, 'age_min' => $age];
        }
        return ['state' => 'ok', 'problem' => null, 'data' => $data, 'age_min' => $age];
    }
}

if (!function_exists('bmsBackupHealth')) {
    /**
     * The whole picture.
     *
     * @return array{ready:bool, targets:array, offsite:array, problems:string[],
     *               counts:array{total:int, ok:int, problem:int, pending:int}}
     *   targets[] = target + state + full_ok/full_failed/snapshot_ok/snapshot_failed rows
     */
    function bmsBackupHealth(): array
    {
        $out = ['ready' => false, 'targets' => [], 'offsite' => bmsBackupHealthOffsite(), 'problems' => [],
                'counts' => ['total' => 0, 'ok' => 0, 'problem' => 0, 'pending' => 0]];
        if (!controlDbReady()) return $out;

        $cpdo = getControlPdo();
        $targets = bmsBackupHealthTargets($cpdo);

        // Latest row per (target, kind, status) in the last 35 days.
        $latest = [];
        try {
            $rows = $cpdo->query("
                SELECT l.id, l.target_key, l.kind, l.status, l.file_name, l.bytes, l.files_count, l.message, l.created_at,
                       TIMESTAMPDIFF(MINUTE, l.created_at, NOW()) AS age_min
                  FROM tenant_backup_log l
                  JOIN (SELECT MAX(id) AS id FROM tenant_backup_log
                         WHERE created_at >= NOW() - INTERVAL 35 DAY
                         GROUP BY target_key, kind, status) m ON m.id = l.id
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) $latest[$r['target_key']][$r['kind'] . '_' . $r['status']] = $r;
        } catch (Throwable $e) {
            // No log table yet (setup_control_db.php not re-run): every target reads as never.
            error_log('bmsBackupHealth: ' . $e->getMessage());
        }
        $out['ready'] = true;

        foreach ($targets as $t) {
            $last = [];
            foreach (['full_ok', 'full_failed', 'snapshot_ok', 'snapshot_failed'] as $k) $last[$k] = $latest[$t['key']][$k] ?? null;
            $state = bmsBackupHealthClassify($last, $t['age_min']);
            $out['targets'][] = $t + $last + ['state' => $state];
            $out['counts']['total']++;

            if ($state === 'ok') { $out['counts']['ok']++; continue; }
            if ($state === 'pending') { $out['counts']['pending']++; continue; }
            $out['counts']['problem']++;
            $name = $t['label'] . ($t['subdomain'] ? " ({$t['subdomain']})" : '');
            $failedRow = $last['full_failed'];
            if ($last['snapshot_failed'] !== null && ($failedRow === null || (int)$last['snapshot_failed']['id'] > (int)$failedRow['id'])) {
                $failedRow = $last['snapshot_failed'];
            }
            $out['problems'][] = match ($state) {
                'failed'  => "$name: last backup FAILED — " . substr((string)($failedRow['message'] ?? ''), 0, 200),
                'overdue' => "$name: no good full backup for over 26 hours",
                default   => "$name: never backed up",
            };
        }

        if ($out['offsite']['problem'] !== null) $out['problems'][] = $out['offsite']['problem'];
        return $out;
    }
}

if (!function_exists('bmsBackupHealthAlert')) {
    /**
     * Raise the daily alert when anything needs attention: one bell notification
     * in the superadmin panel + one email to every superadmin, at most once per
     * calendar day (marker file), so four runs a day never repeat it.
     *
     * Tests define BACKUP_ALERT_CAPTURE_FILE to record the email instead of sending.
     *
     * @return bool true when an alert was raised now
     */
    function bmsBackupHealthAlert(array $health, ?string $markerFile = null): bool
    {
        if (!$health['problems']) return false;

        $root = defined('ROOT_DIR') ? ROOT_DIR : dirname(__DIR__);
        $markerFile = $markerFile ?? rtrim($root, '/\\') . '/backups/_platform/.backup_alert_day';
        $today = date('Y-m-d');
        if (is_file($markerFile) && trim((string)@file_get_contents($markerFile)) === $today) return false;
        // Claim the day first: a failing mailer must not turn into an email per run.
        @file_put_contents($markerFile, $today, LOCK_EX);

        $n     = count($health['problems']);
        $title = "Backups need attention — $n problem" . ($n === 1 ? '' : 's');
        $body  = implode(' · ', array_slice($health['problems'], 0, 3)) . ($n > 3 ? ' · and ' . ($n - 3) . ' more' : '');

        try {
            getControlPdo()->prepare("
                INSERT INTO superadmin_notifications (type, title, body, tenant_id, tenant_name, subdomain)
                VALUES ('backup_problem', ?, ?, NULL, NULL, NULL)
            ")->execute([$title, mb_substr($body, 0, 500)]);
        } catch (Throwable $e) {
            error_log('bmsBackupHealthAlert notification: ' . $e->getMessage());
        }

        $items = '';
        foreach ($health['problems'] as $p) $items .= '<li>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</li>';
        $html = '<p>The scheduled backup check found ' . $n . ' problem' . ($n === 1 ? '' : 's') . ':</p><ul>' . $items . '</ul>'
              . '<p>Open <strong>Platform Administration → Backups</strong> for details. '
              . 'Logs on the server: <code>backups/_platform/backup.log</code> and <code>offsite.log</code>.</p>';

        if (defined('BACKUP_ALERT_CAPTURE_FILE')) {
            file_put_contents(BACKUP_ALERT_CAPTURE_FILE, json_encode(['subject' => $title, 'html' => $html]) . "\n", FILE_APPEND);
            return true;
        }
        try {
            require_once __DIR__ . '/platform_settings.php';
            require_once __DIR__ . '/mailer.php';
            $mailer = platformMailerOpts();
            if ($mailer['configured']) {
                foreach (getControlPdo()->query("SELECT email FROM superadmins")->fetchAll(PDO::FETCH_COLUMN) as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) sendEmail($email, 'BMS: ' . $title, $html, $mailer['opts']);
                }
            }
        } catch (Throwable $e) {
            error_log('bmsBackupHealthAlert email: ' . $e->getMessage());
        }
        return true;
    }
}
