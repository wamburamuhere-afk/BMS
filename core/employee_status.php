<?php
/**
 * Employee status — direct inactivate/reactivate actions.
 *
 * Phase 1 of employee_inactivation_plan.md (decision D1): these are the
 * quick, direct actions available straight from the employees list and
 * inactive_employees.php — distinct from core/lifecycle_effects.php, which
 * applies the effect of an APPROVED HR Action event (a formal, audited
 * approval workflow). Both converge on the same status='inactive' signal.
 *
 * Never deletes any row. Payroll, attendance, leaves and lifecycle-event
 * history all stay linked by employee_id and remain fully visible via their
 * own (unfiltered) views/detail pages regardless of the employee's status.
 */

if (!function_exists('inactivateEmployee')) {
    /**
     * @param string $outcome 'terminated' | 'resigned' | 'failed_probation'
     *                        (failed_probation maps to employment_status
     *                        'terminated' — D4: a reason, not a distinct
     *                        mechanism)
     * @return array{old: array, new: array} for audit logging
     */
    function inactivateEmployee(PDO $pdo, int $employee_id, int $actor, string $outcome): array
    {
        $employmentStatus = ($outcome === 'resigned') ? 'resigned' : 'terminated';

        $cur = $pdo->prepare("SELECT status, employment_status FROM employees WHERE employee_id = ?");
        $cur->execute([$employee_id]);
        $before = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            throw new InvalidArgumentException('Employee not found.');
        }

        $pdo->prepare("UPDATE employees SET status = 'inactive', employment_status = ?, updated_by = ? WHERE employee_id = ?")
            ->execute([$employmentStatus, $actor, $employee_id]);

        return [
            'old' => ['status' => $before['status'], 'employment_status' => $before['employment_status']],
            'new' => ['status' => 'inactive', 'employment_status' => $employmentStatus],
        ];
    }
}

if (!function_exists('reactivateEmployee')) {
    /**
     * D3: auto-sets both fields to 'active', no prompt for the new
     * employment_status.
     *
     * @return array{old: array, new: array} for audit logging
     */
    function reactivateEmployee(PDO $pdo, int $employee_id, int $actor): array
    {
        $cur = $pdo->prepare("SELECT status, employment_status FROM employees WHERE employee_id = ?");
        $cur->execute([$employee_id]);
        $before = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            throw new InvalidArgumentException('Employee not found.');
        }

        $pdo->prepare("UPDATE employees SET status = 'active', employment_status = 'active', updated_by = ? WHERE employee_id = ?")
            ->execute([$actor, $employee_id]);

        return [
            'old' => ['status' => $before['status'], 'employment_status' => $before['employment_status']],
            'new' => ['status' => 'active', 'employment_status' => 'active'],
        ];
    }
}
