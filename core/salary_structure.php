<?php
/**
 * core/salary_structure.php
 * -------------------------
 * Plan H1 — resolve an employee's assigned salary components into payslip figures.
 *
 * Returns the allowance / deduction totals AND a per-component breakdown (for the
 * itemised payslip via payroll_items). A percentage component resolves against the
 * employee's basic salary. Bonuses count as earnings (added to allowances).
 *
 * If the employee has NO active components, has_components is false and the caller
 * keeps its existing legacy behaviour unchanged — this helper never alters the
 * legacy path.
 */

if (!function_exists('resolveEmployeeSalaryComponents')) {
    /**
     * @return array{
     *   has_components: bool,
     *   allowances: float,       // earnings from components (allowance + bonus)
     *   deductions: float,       // deduction components
     *   items: array<int,array{item_type:string,item_name:string,amount:float,tax_applicable:int}>
     * }
     */
    function resolveEmployeeSalaryComponents(PDO $pdo, int $employeeId, float $basicSalary): array
    {
        $out = ['has_components' => false, 'allowances' => 0.0, 'deductions' => 0.0, 'items' => []];

        $stmt = $pdo->prepare("
            SELECT esc.amount, sc.component_name, sc.component_type, sc.calculation_type, sc.tax_applicable
              FROM employee_salary_components esc
              JOIN salary_components sc ON esc.component_id = sc.component_id
             WHERE esc.employee_id = ?
               AND esc.status = 'active'
               AND sc.status = 'active'
          ORDER BY sc.component_type, sc.component_name
        ");
        $stmt->execute([$employeeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return $out;

        $out['has_components'] = true;
        foreach ($rows as $r) {
            // Resolve the value: fixed amount, or a percentage of basic salary.
            $value = ($r['calculation_type'] === 'percentage')
                ? round($basicSalary * (float)$r['amount'] / 100, 2)
                : round((float)$r['amount'], 2);
            if ($value <= 0) continue;

            $type = $r['component_type'];   // allowance | deduction | bonus
            if ($type === 'deduction') {
                $out['deductions'] += $value;
            } else {
                $out['allowances'] += $value;   // allowance + bonus are earnings
            }

            $out['items'][] = [
                'item_type'       => $type,
                'item_name'       => (string)$r['component_name'],
                'amount'          => $value,
                'tax_applicable'  => (int)$r['tax_applicable'],
            ];
        }

        $out['allowances'] = round($out['allowances'], 2);
        $out['deductions'] = round($out['deductions'], 2);
        return $out;
    }
}

if (!function_exists('writePayrollItems')) {
    /**
     * Persist the per-component breakdown for a generated payslip. Idempotent: clears
     * any prior items for the payroll first, then inserts the supplied breakdown.
     */
    function writePayrollItems(PDO $pdo, int $payrollId, array $items): void
    {
        if ($payrollId <= 0) return;
        $pdo->prepare("DELETE FROM payroll_items WHERE payroll_id = ?")->execute([$payrollId]);
        if (!$items) return;
        $ins = $pdo->prepare("INSERT INTO payroll_items (payroll_id, item_type, item_name, amount, tax_applicable, created_at)
                              VALUES (?, ?, ?, ?, ?, NOW())");
        foreach ($items as $it) {
            $ins->execute([$payrollId, $it['item_type'], $it['item_name'], $it['amount'], (int)($it['tax_applicable'] ?? 0)]);
        }
    }
}
