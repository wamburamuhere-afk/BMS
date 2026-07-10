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
     * @param string  $outcome 'terminated' | 'resigned' | 'failed_probation'
     *                         (failed_probation maps to employment_status
     *                         'terminated' — D4: a reason, not a distinct
     *                         mechanism)
     * @param ?string $reason  free-text note, shown on inactive_employees.php
     * @return array{old: array, new: array} for audit logging
     */
    function inactivateEmployee(PDO $pdo, int $employee_id, int $actor, string $outcome, ?string $reason = null): array
    {
        $employmentStatus = ($outcome === 'resigned') ? 'resigned' : 'terminated';
        $reason = ($reason !== null && trim($reason) !== '') ? trim($reason) : null;

        $cur = $pdo->prepare("SELECT status, employment_status, inactivation_reason FROM employees WHERE employee_id = ?");
        $cur->execute([$employee_id]);
        $before = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            throw new InvalidArgumentException('Employee not found.');
        }

        $pdo->prepare("UPDATE employees SET status = 'inactive', employment_status = ?, inactivation_reason = ?, updated_by = ? WHERE employee_id = ?")
            ->execute([$employmentStatus, $reason, $actor, $employee_id]);

        return [
            'old' => ['status' => $before['status'], 'employment_status' => $before['employment_status'], 'inactivation_reason' => $before['inactivation_reason']],
            'new' => ['status' => 'inactive', 'employment_status' => $employmentStatus, 'inactivation_reason' => $reason],
        ];
    }
}

if (!function_exists('assertEmployeeActive')) {
    /**
     * Phase 3 — server-side enforcement. UI dropdowns already exclude
     * inactive employees, but that's not a safe boundary on its own (a
     * crafted/direct POST bypasses it entirely) — every write endpoint that
     * acts FOR or ON BEHALF OF an employee (marking their attendance,
     * applying for their leave, naming them as a handover contact) must
     * re-check status itself.
     *
     * @param string $label  used in the error message, e.g. "Employee" or
     *                       "Handover contact"
     * @throws InvalidArgumentException if not found or not active
     */
    function assertEmployeeActive(PDO $pdo, int $employee_id, string $label = 'Employee'): void
    {
        $stmt = $pdo->prepare("SELECT status FROM employees WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new InvalidArgumentException("$label not found.");
        }
        if ($status !== 'active') {
            throw new InvalidArgumentException("$label is inactive and cannot be selected for this action.");
        }
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

        $pdo->prepare("UPDATE employees SET status = 'active', employment_status = 'active', inactivation_reason = NULL, updated_by = ? WHERE employee_id = ?")
            ->execute([$actor, $employee_id]);

        return [
            'old' => ['status' => $before['status'], 'employment_status' => $before['employment_status']],
            'new' => ['status' => 'active', 'employment_status' => 'active'],
        ];
    }
}
