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
 * Also auto-closes contracts whose end_date has already passed with nobody
 * having manually terminated them: flips the contract to 'expired' and, if
 * the employee has no other draft/active contract, deactivates them via
 * core/employee_status.php::inactivateEmployee() — the same cascade
 * api/change_contract_status.php's terminate action performs — so
 * attendance/payroll/leave/Operations (all keyed off employees.status) stop
 * counting them as employed. Always reversible (reactivateEmployee()) and
 * always notified via 'hr_contract_expired_autoclosed'.
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
require_once __DIR__ . '/../core/employee_status.php';

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
        $summary = ['contracts_scanned' => 0, 'probations_scanned' => 0, 'notifications_created' => 0, 'contracts_autoclosed' => 0];

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

        // ── 3. Active contracts whose end_date has already passed (auto-close) ──
        // Nobody manually terminated these — the date just went by. Flip the
        // contract to 'expired' and, if the employee has no other draft/active
        // contract, deactivate them the same way api/change_contract_status.php's
        // terminate action does, so attendance/payroll/leave/Operations (all keyed
        // off employees.status) stop treating them as employed. Always reversible
        // via reactivateEmployee(); always notified — this changes a real person's
        // status with no human click, so it must never happen silently.
        $expired = $pdo->query("
            SELECT ec.contract_id, ec.employee_id, ec.end_date, ec.contract_type, e.first_name, e.last_name
            FROM employee_contracts ec
            JOIN employees e ON e.employee_id = ec.employee_id
            WHERE ec.status = 'active' AND ec.end_date IS NOT NULL AND ec.end_date < CURDATE()
        ")->fetchAll(PDO::FETCH_ASSOC);

        $expiryUrl = function_exists('getUrl') ? getUrl('employee_contracts') : '/employee_contracts';

        foreach ($expired as $c) {
            $contractId = (int)$c['contract_id'];
            $employeeId = (int)$c['employee_id'];
            $name       = trim($c['first_name'] . ' ' . $c['last_name']);
            $endOn      = date('d M Y', strtotime($c['end_date']));

            try {
                $pdo->beginTransaction();

                $lock = $pdo->prepare("SELECT status FROM employee_contracts WHERE contract_id = ? FOR UPDATE");
                $lock->execute([$contractId]);
                if ($lock->fetchColumn() !== 'active') {
                    // Already handled by a concurrent run/manual action since the outer SELECT.
                    $pdo->rollBack();
                    continue;
                }

                $pdo->prepare("UPDATE employee_contracts SET status = 'expired', updated_by = 0 WHERE contract_id = ?")
                    ->execute([$contractId]);
                logAudit($pdo, 0, 'expire', [
                    'activity_type' => 'system',
                    'entity_type'   => 'employee_contract',
                    'entity_id'     => $contractId,
                    'description'   => "Auto-closed expired {$c['contract_type']} contract for $name (end date $endOn)",
                    'old_values'    => ['status' => 'active'],
                    'new_values'    => ['status' => 'expired'],
                ]);

                $deactivated = false;
                $remaining = $pdo->prepare("SELECT COUNT(*) FROM employee_contracts
                                             WHERE employee_id = ? AND status IN ('draft', 'active') AND contract_id != ?");
                $remaining->execute([$employeeId, $contractId]);
                // Re-check status fresh, not the batch-SELECT snapshot in $c — it may have
                // changed (e.g. a concurrent HR Actions termination) since this run started.
                $freshStatus = $pdo->prepare("SELECT status FROM employees WHERE employee_id = ? FOR UPDATE");
                $freshStatus->execute([$employeeId]);
                if ((int)$remaining->fetchColumn() === 0 && $freshStatus->fetchColumn() === 'active') {
                    $change = inactivateEmployee($pdo, $employeeId, 0, 'terminated',
                        "Contract #$contractId expired on $endOn — no remaining active/draft contract");
                    logAudit($pdo, 0, 'update_status', [
                        'activity_type' => 'system',
                        'entity_type'   => 'employee',
                        'entity_id'     => $employeeId,
                        'description'   => "Employee deactivated — contract #$contractId expired with no remaining contract",
                        'old_values'    => $change['old'],
                        'new_values'    => $change['new'],
                    ]);
                    $deactivated = true;
                }

                logActivity($pdo, 0, 'Auto-close expired employee contract',
                    "auto-closed {$c['contract_type']} contract for \"$name\" (ended $endOn)"
                    . ($deactivated ? ' — employee deactivated, no remaining contract' : ''));

                $pdo->commit();

                $summary['contracts_autoclosed']++;

                $res = dispatchEvent($pdo, 'hr_contract_expired_autoclosed', [
                    'title'         => "Contract auto-closed: $name",
                    'message'       => "{$name}'s {$c['contract_type']} contract ended on {$endOn} and was automatically closed."
                                      . ($deactivated ? ' The employee has been marked inactive — review and reactivate if this was unexpected.' : ''),
                    'entity_type'   => 'employee_contract',
                    'entity_id'     => $contractId,
                    'dedupe_suffix' => 'autoclose',
                    'action_url'    => $expiryUrl,
                ]);
                $summary['notifications_created'] += $res['created'];

                if ($isCli) {
                    echo "  -> contract #{$contractId} ({$name}): expired {$endOn} — auto-closed"
                       . ($deactivated ? ', employee deactivated' : '') . ".\n";
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("check_hr_expiry.php auto-close failed for contract #$contractId: " . $e->getMessage());
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
           . "{$result['contracts_autoclosed']} contract(s) auto-closed, "
           . "{$result['notifications_created']} notification(s) created.\n";
    }
} catch (Throwable $e) {
    error_log('check_hr_expiry.php error: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo 'HR expiry check FAILED: ' . $e->getMessage() . "\n";
        exit(1);
    }
}
