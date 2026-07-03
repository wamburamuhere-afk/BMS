<?php
/**
 * BMS — HR Expiry Notification Engine (Tier 2, Phase 2.3, D13)
 *
 * Scans active employee contracts nearing their end_date (milestones
 * 60/30/14/7/1 days) and employees on probation nearing probation_end_date
 * (milestones 14/7/1 days), and dispatches through the shared notification
 * engine (core/notify.php) using the 'hr_contract_expiry' / 'hr_probation_end'
 * events registered in Phase 2.1 (migrations/2026_07_02_hr_compliance_permissions.php).
 *
 * Recipients + dedupe + logging are handled by dispatchEvent() — no new alert
 * plumbing here, same lever as the D8 document-expiry hookup.
 *
 * It runs in two ways:
 *   - Automatically once per day, included (throttled) from header.php.
 *   - Manually or via a server cron job:  php cron/check_hr_expiry.php
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/notify.php';

if (!function_exists('run_hr_expiry_check')) {
    /**
     * Execute the scan. Safe to call repeatedly — dispatchEvent()'s own
     * per-milestone dedupe key means each milestone notifies at most once.
     *
     * @return array Summary counts.
     */
    function run_hr_expiry_check(PDO $pdo): array
    {
        $isCli = (php_sapi_name() === 'cli');
        $contractMilestones  = [60, 30, 14, 7, 1];
        $probationMilestones = [14, 7, 1];
        $summary = ['contracts_scanned' => 0, 'probations_scanned' => 0, 'notifications_created' => 0];

        // ── 1. Active contracts expiring within 60 days ─────────────────────────
        $contracts = $pdo->query("
            SELECT ec.contract_id, ec.employee_id, ec.end_date, e.first_name, e.last_name,
                   DATEDIFF(ec.end_date, CURDATE()) AS days_remaining
            FROM employee_contracts ec
            JOIN employees e ON e.employee_id = ec.employee_id
            WHERE ec.status = 'active' AND ec.end_date IS NOT NULL
              AND ec.end_date >= CURDATE() AND DATEDIFF(ec.end_date, CURDATE()) <= 60
        ")->fetchAll(PDO::FETCH_ASSOC);
        $summary['contracts_scanned'] = count($contracts);

        $contractUrl = function_exists('getUrl') ? getUrl('employee_contracts') : '/employee_contracts';

        foreach ($contracts as $c) {
            $days = (int)$c['days_remaining'];
            $reached = array_filter($contractMilestones, fn($m) => $days <= $m);
            if (empty($reached)) continue;
            $milestone = min($reached); // nearest threshold crossed so far
            $name = trim($c['first_name'] . ' ' . $c['last_name']);
            $expOn = date('d M Y', strtotime($c['end_date']));

            $res = dispatchEvent($pdo, 'hr_contract_expiry', [
                'title'         => "Contract expiring: $name",
                'message'       => "{$name}'s contract ends on {$expOn} ({$days} day(s) remaining). Please review or renew it.",
                'entity_type'   => 'employee_contract',
                'entity_id'     => (int)$c['contract_id'],
                'dedupe_suffix' => 'm' . $milestone,
                'action_url'    => $contractUrl,
            ]);
            $summary['notifications_created'] += $res['created'];
            if ($isCli && $res['created'] > 0) {
                echo "  -> contract #{$c['contract_id']} ({$name}): {$days}d left — notified {$res['created']} user(s).\n";
            }
        }

        // ── 2. Employees on probation nearing probation_end_date ───────────────
        $probations = $pdo->query("
            SELECT employee_id, first_name, last_name, probation_end_date,
                   DATEDIFF(probation_end_date, CURDATE()) AS days_remaining
            FROM employees
            WHERE employment_status = 'probation'
              AND probation_end_date IS NOT NULL
              AND (status IS NULL OR status != 'deleted')
              AND probation_end_date >= CURDATE()
              AND DATEDIFF(probation_end_date, CURDATE()) <= 14
        ")->fetchAll(PDO::FETCH_ASSOC);
        $summary['probations_scanned'] = count($probations);

        $empUrl = function_exists('getUrl') ? getUrl('employees') : '/employees';

        foreach ($probations as $p) {
            $days = (int)$p['days_remaining'];
            $reached = array_filter($probationMilestones, fn($m) => $days <= $m);
            if (empty($reached)) continue;
            $milestone = min($reached);
            $name = trim($p['first_name'] . ' ' . $p['last_name']);
            $endOn = date('d M Y', strtotime($p['probation_end_date']));

            $res = dispatchEvent($pdo, 'hr_probation_end', [
                'title'         => "Probation ending: $name",
                'message'       => "{$name}'s probation period ends on {$endOn} ({$days} day(s) remaining).",
                'entity_type'   => 'employee',
                'entity_id'     => (int)$p['employee_id'],
                'dedupe_suffix' => 'm' . $milestone,
                'action_url'    => $empUrl,
            ]);
            $summary['notifications_created'] += $res['created'];
            if ($isCli && $res['created'] > 0) {
                echo "  -> probation ending #{$p['employee_id']} ({$name}): {$days}d left — notified {$res['created']} user(s).\n";
            }
        }

        return $summary;
    }
}

// ── Run ────────────────────────────────────────────────────────────────────
try {
    if (function_exists('save_setting')) {
        save_setting('hr_expiry_last_run', date('Y-m-d'));
    }

    $result = run_hr_expiry_check($pdo);

    if (php_sapi_name() === 'cli') {
        echo "HR expiry check complete: "
           . "{$result['contracts_scanned']} contract(s) scanned, "
           . "{$result['probations_scanned']} probation(s) scanned, "
           . "{$result['notifications_created']} notification(s) created.\n";
    }
} catch (Throwable $e) {
    error_log('check_hr_expiry.php error: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'HR expiry check FAILED: ' . $e->getMessage() . "\n";
        exit(1);
    }
}
