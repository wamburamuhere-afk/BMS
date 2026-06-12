<?php
/**
 * core/attendance_payroll.php
 * ---------------------------
 * Plan H2 — attendance → payroll helpers.
 *
 *  - attendancePayrollMode()    : 'off' (default) | 'on'  — the feature flag.
 *  - attendanceStandardHours()  : the shift standard (default 8) overtime is measured against.
 *  - computeAttendanceOvertime(): overtime hours + amount for one saved day.
 *  - payrollAttendanceSummary() : present / half / absent days + overtime amount for a
 *                                 pay period, used by payroll when the flag is on.
 *
 * With the flag off, payroll never calls the derivation — behaviour is unchanged.
 */

if (!function_exists('attendancePayrollMode')) {
    function attendancePayrollMode(PDO $pdo): string {
        $v = '';
        try {
            $s = $pdo->prepare("SELECT setting_value FROM payroll_settings WHERE setting_key = 'payroll_attendance_mode'");
            $s->execute(); $v = (string)$s->fetchColumn();
        } catch (Throwable $e) { /* table/row absent → off */ }
        return ($v === 'on') ? 'on' : 'off';
    }
}

if (!function_exists('attendanceStandardHours')) {
    function attendanceStandardHours(PDO $pdo): float {
        try {
            $s = $pdo->prepare("SELECT setting_value FROM payroll_settings WHERE setting_key = 'standard_hours_per_day'");
            $s->execute(); $h = (float)$s->fetchColumn();
            if ($h > 0) return $h;
        } catch (Throwable $e) { /* fall through */ }
        return 8.0;
    }
}

if (!function_exists('computeAttendanceOvertime')) {
    /**
     * Overtime for one day = hours beyond the standard, valued at the hourly rate.
     * @return array{overtime_hours:float, overtime_amount:float}
     */
    function computeAttendanceOvertime(?float $totalHours, float $standardHours, float $hourlyRate): array {
        $total = max(0.0, (float)$totalHours);
        $ot = ($total > $standardHours) ? round($total - $standardHours, 2) : 0.0;
        $amt = ($ot > 0 && $hourlyRate > 0) ? round($ot * $hourlyRate, 2) : 0.0;
        return ['overtime_hours' => $ot, 'overtime_amount' => $amt];
    }
}

if (!function_exists('payrollAttendanceSummary')) {
    /**
     * Aggregate a pay period's attendance for one employee.
     *   present_days : full-day-equivalent worked days (present + late)
     *   half_days    : days flagged 'half_day'
     *   absent_days  : days flagged 'absent'
     *   overtime_amount : SUM of attendance.overtime_amount in the period
     *
     * $period is the 'Y-m' month string (matches payroll_period usage elsewhere).
     */
    function payrollAttendanceSummary(PDO $pdo, int $employeeId, string $period): array {
        $stmt = $pdo->prepare("
            SELECT
              SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END)  AS present_days,
              SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END)            AS half_days,
              SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END)              AS absent_days,
              COALESCE(SUM(overtime_amount), 0)                              AS overtime_amount
            FROM attendance
           WHERE employee_id = ?
             AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
        ");
        $stmt->execute([$employeeId, $period]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'present_days'    => (int)($r['present_days'] ?? 0),
            'half_days'       => (int)($r['half_days'] ?? 0),
            'absent_days'     => (int)($r['absent_days'] ?? 0),
            'overtime_amount' => (float)($r['overtime_amount'] ?? 0),
        ];
    }
}
