<?php
/**
 * core/leave_balance.php
 * ----------------------
 * Plan H3 — leave balance & entitlement engine.
 *
 * Bridges the `leaves.leave_type` enum (annual / sick / unpaid …) to the rich
 * `leave_types` config (entitlement, paid flag, accrual, carry-over), and computes a
 * DRIFT-PROOF balance: entitlement + carried-over come from leave_balances; "used" is
 * summed live from approved leaves, so available can never drift.
 *
 *   available = entitled + carried_over − used
 */

if (!function_exists('leaveNormalizeEnum')) {
    /**
     * Normalise any leave-type label to the canonical `leaves.leave_type` enum value.
     * Accepts the enum itself ('annual'), or a config type_name ('Annual Leave'), or a
     * loose label — returns one of the known enum keys (defaults to 'other').
     */
    function leaveNormalizeEnum(string $value): string {
        $v = strtolower(trim($value));
        $enums = ['annual','sick','maternity','paternity','study','unpaid','other'];
        if (in_array($v, $enums, true)) return $v;
        // Match the first enum keyword that appears in the label.
        foreach ($enums as $e) { if ($e !== 'other' && strpos($v, $e) !== false) return $e; }
        // Common synonyms mapped to 'other'.
        if (strpos($v, 'compassionate') !== false || strpos($v, 'emergency') !== false) return 'other';
        $first = strtolower(explode(' ', trim($value))[0]);
        return in_array($first, $enums, true) ? $first : 'other';
    }
}

if (!function_exists('leaveTypeIdFromEnum')) {
    /**
     * Resolve a `leaves.leave_type` enum value to a `leave_types.type_id`.
     * Matches the enum keyword against the type_name (e.g. 'annual' → 'Annual Leave').
     * Returns null when no active leave type matches (the caller then skips enforcement).
     */
    function leaveTypeIdFromEnum(PDO $pdo, string $enumValue): ?int {
        $enumValue = strtolower(trim($enumValue));
        if ($enumValue === '') return null;
        static $cache = [];
        if (array_key_exists($enumValue, $cache)) return $cache[$enumValue];

        // Direct keyword match against the configured type names.
        $stmt = $pdo->prepare("SELECT type_id FROM leave_types WHERE status = 'active' AND LOWER(type_name) LIKE ? ORDER BY type_id LIMIT 1");
        $stmt->execute(['%' . $enumValue . '%']);
        $id = $stmt->fetchColumn();
        $cache[$enumValue] = $id ? (int)$id : null;
        return $cache[$enumValue];
    }
}

if (!function_exists('leaveTypeIsPaid')) {
    /** Whether a configured leave type is paid (defaults to paid when unknown). */
    function leaveTypeIsPaid(PDO $pdo, int $leaveTypeId): bool {
        $s = $pdo->prepare("SELECT is_paid FROM leave_types WHERE type_id = ?");
        $s->execute([$leaveTypeId]);
        $v = $s->fetchColumn();
        return ($v === false) ? true : (bool)$v;
    }
}

if (!function_exists('leaveUsedDays')) {
    /** Approved leave days for an employee + leave-type enum in a year (live, drift-proof). */
    function leaveUsedDays(PDO $pdo, int $employeeId, string $enumValue, int $year, ?int $excludeLeaveId = null): float {
        $sql = "SELECT COALESCE(SUM(total_days), 0) FROM leaves
                 WHERE employee_id = ? AND leave_type = ? AND status = 'approved' AND YEAR(start_date) = ?";
        $params = [$employeeId, $enumValue, $year];
        if ($excludeLeaveId) { $sql .= " AND leave_id <> ?"; $params[] = $excludeLeaveId; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (float)$st->fetchColumn();
    }
}

if (!function_exists('ensureLeaveBalanceRow')) {
    /**
     * Make sure a leave_balances row exists for (employee, type, year), seeding the
     * entitlement from leave_types.max_days_per_year. Returns ['entitled','carried_over'].
     */
    function ensureLeaveBalanceRow(PDO $pdo, int $employeeId, int $leaveTypeId, int $year): array {
        $sel = $pdo->prepare("SELECT entitled, carried_over FROM leave_balances WHERE employee_id = ? AND leave_type_id = ? AND year = ?");
        $sel->execute([$employeeId, $leaveTypeId, $year]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['entitled' => (float)$row['entitled'], 'carried_over' => (float)$row['carried_over']];

        $ent = (float)($pdo->query("SELECT COALESCE(max_days_per_year,0) FROM leave_types WHERE type_id = " . (int)$leaveTypeId)->fetchColumn() ?: 0);
        // INSERT IGNORE guards against a concurrent seed (unique key).
        $pdo->prepare("INSERT IGNORE INTO leave_balances (employee_id, leave_type_id, year, entitled, carried_over, created_at, updated_at)
                       VALUES (?, ?, ?, ?, 0, NOW(), NOW())")
            ->execute([$employeeId, $leaveTypeId, $year, $ent]);
        return ['entitled' => $ent, 'carried_over' => 0.0];
    }
}

if (!function_exists('leaveBalanceFor')) {
    /**
     * The drift-proof balance for an employee + leave-type enum in a year.
     * @return array{leave_type_id:?int, is_paid:bool, entitled:float, carried_over:float,
     *               used:float, available:float, tracked:bool}
     */
    function leaveBalanceFor(PDO $pdo, int $employeeId, string $enumValue, ?int $year = null, ?int $excludeLeaveId = null): array {
        $year = $year ?: (int)date('Y');
        $enumValue = leaveNormalizeEnum($enumValue);   // accept enum OR type_name
        $typeId = leaveTypeIdFromEnum($pdo, $enumValue);
        if ($typeId === null) {
            // No configured type → not tracked; caller allows (degrade-safe).
            return ['leave_type_id' => null, 'is_paid' => true, 'entitled' => 0.0, 'carried_over' => 0.0,
                    'used' => leaveUsedDays($pdo, $employeeId, $enumValue, $year, $excludeLeaveId),
                    'available' => 0.0, 'tracked' => false];
        }
        $seed = ensureLeaveBalanceRow($pdo, $employeeId, $typeId, $year);
        $used = leaveUsedDays($pdo, $employeeId, $enumValue, $year, $excludeLeaveId);
        $available = round($seed['entitled'] + $seed['carried_over'] - $used, 2);
        return [
            'leave_type_id' => $typeId,
            'is_paid'       => leaveTypeIsPaid($pdo, $typeId),
            'entitled'      => $seed['entitled'],
            'carried_over'  => $seed['carried_over'],
            'used'          => $used,
            'available'     => $available,
            'tracked'       => true,
        ];
    }
}

if (!function_exists('leaveYearRollover')) {
    /**
     * Seed a year's leave_balances for every active employee + leave type: set the
     * entitlement from leave_types.max_days_per_year and carry over the prior year's
     * UNUSED days (capped at the type's carry_over_days). Idempotent — re-running just
     * refreshes the seeded entitlement/carry-over (it never touches 'used', which is
     * always computed live). Returns a count summary.
     */
    function leaveYearRollover(PDO $pdo, ?int $year = null): array {
        $year = $year ?: (int)date('Y');
        $prev = $year - 1;
        $types = $pdo->query("SELECT type_id, type_name, COALESCE(max_days_per_year,0) AS maxd, COALESCE(carry_over_days,0) AS carry FROM leave_types WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
        $emps  = $pdo->query("SELECT employee_id FROM employees WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        $seeded = 0;
        $up = $pdo->prepare("INSERT INTO leave_balances (employee_id, leave_type_id, year, entitled, carried_over, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                             ON DUPLICATE KEY UPDATE entitled = VALUES(entitled), carried_over = VALUES(carried_over), updated_at = NOW()");
        foreach ($emps as $emp) {
            $emp = (int)$emp;
            foreach ($types as $t) {
                $carry = 0.0;
                if ((float)$t['carry'] > 0) {
                    // Prior-year available = prior entitled + prior carried − prior used.
                    $prevBal = leaveBalanceFor($pdo, $emp, (string)$t['type_name'], $prev);
                    $carry = max(0.0, min((float)$t['carry'], $prevBal['available']));
                }
                $up->execute([$emp, (int)$t['type_id'], $year, (float)$t['maxd'], round($carry, 2)]);
                $seeded++;
            }
        }
        return ['year' => $year, 'rows' => $seeded, 'employees' => count($emps), 'types' => count($types)];
    }
}

if (!function_exists('unpaidLeaveDaysInPeriod')) {
    /** Approved UNPAID leave days that fall in a 'Y-m' pay period (for payroll deduction). */
    function unpaidLeaveDaysInPeriod(PDO $pdo, int $employeeId, string $period): float {
        // Unpaid = the 'unpaid' enum, or any leave whose configured type is is_paid = 0.
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(l.total_days), 0)
              FROM leaves l
             WHERE l.employee_id = ?
               AND l.status = 'approved'
               AND DATE_FORMAT(l.start_date, '%Y-%m') = ?
               AND ( l.leave_type = 'unpaid'
                     OR EXISTS (SELECT 1 FROM leave_types lt
                                 WHERE lt.status = 'active' AND lt.is_paid = 0
                                   AND LOWER(lt.type_name) LIKE CONCAT('%', LOWER(l.leave_type), '%')) )
        ");
        $st->execute([$employeeId, $period]);
        return (float)$st->fetchColumn();
    }
}
