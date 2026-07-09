<?php
/**
 * BMS — Leave application rules.
 *
 * Shared by app/bms/pos/leaves.php, api/apply_leave.php and api/update_leave.php
 * so the form, the create path and the update path enforce the same limits.
 *
 * Before this existed, `max_days_per_year` / `max_consecutive_days` were only a
 * client-side hint: the server accepted whatever was posted.
 */

// A standard working day, used by leaveDaysFor()'s half-day calculations.
if (!defined('WORKING_DAY_HOURS')) {
    define('WORKING_DAY_HOURS', 8);
}

if (!function_exists('leaveTypeForApply')) {
    /**
     * Load an ACTIVE leave type by id. Throws if it does not exist or is inactive,
     * so a stale dropdown (or a hand-crafted POST) cannot book against a retired type.
     */
    function leaveTypeForApply(PDO $pdo, $leave_type_id): array
    {
        $id = (int)$leave_type_id;
        if ($id <= 0) {
            throw new InvalidArgumentException('Please choose a leave type.');
        }
        $st = $pdo->prepare("SELECT * FROM leave_types WHERE type_id = ? AND status = 'active'");
        $st->execute([$id]);
        $type = $st->fetch(PDO::FETCH_ASSOC);
        if (!$type) {
            throw new InvalidArgumentException('That leave type no longer exists or has been deactivated.');
        }
        return $type;
    }
}

if (!function_exists('normaliseHalfDay')) {
    /**
     * Validate the Half Day selection.
     *
     * @return array{half_day:string, leave_hours:?float}
     */
    function normaliseHalfDay(array $post): array
    {
        $allowed  = ['none', 'first_half', 'second_half'];
        $half_day = $post['half_day'] ?? 'none';
        if ($half_day === '') $half_day = 'none';
        if (!in_array($half_day, $allowed, true)) {
            throw new InvalidArgumentException('Invalid half-day selection.');
        }

        return ['half_day' => $half_day, 'leave_hours' => null];
    }
}

if (!function_exists('leaveDaysFor')) {
    /**
     * Days actually consumed by this leave, given the half-day selection.
     *
     * @param ?float $leave_hours  Unused — kept in the signature for call-site
     *                             compatibility (leaves.leave_hours column and its
     *                             callers still exist, always null now).
     */
    function leaveDaysFor(string $start_date, string $end_date, string $half_day, ?float $leave_hours): float
    {
        $start = new DateTime($start_date);
        $end   = new DateTime($end_date);
        if ($end < $start) {
            throw new InvalidArgumentException('The end date cannot be before the start date.');
        }
        $days = (int)$start->diff($end)->days + 1;

        if ($half_day === 'first_half' || $half_day === 'second_half') {
            return max(0.5, $days - 0.5);
        }
        return (float)$days;
    }
}

if (!function_exists('assertLeaveWithinTypeLimits')) {
    /**
     * Enforce the type's limits server-side.
     *
     * @param int|null $exclude_leave_id  Ignore this leave when summing the year's
     *                                    usage (an edit re-books the same days).
     */
    function assertLeaveWithinTypeLimits(
        PDO $pdo, array $type, int $employee_id, string $start_date,
        float $days, ?int $exclude_leave_id = null
    ): void {
        $max_consecutive = (int)$type['max_consecutive_days'];
        if ($max_consecutive > 0 && $days > $max_consecutive) {
            throw new InvalidArgumentException(
                "{$type['type_name']} allows at most $max_consecutive consecutive day(s); this request is $days."
            );
        }

        $year = (new DateTime($start_date))->format('Y');

        $sql = "SELECT COALESCE(SUM(days_count), 0)
                  FROM leaves
                 WHERE employee_id = ?
                   AND leave_type_id = ?
                   AND YEAR(start_date) = ?
                   AND status IN ('pending', 'approved')";
        $params = [$employee_id, (int)$type['type_id'], $year];
        if ($exclude_leave_id !== null) {
            $sql .= " AND leave_id != ?";
            $params[] = $exclude_leave_id;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $already = (float)$st->fetchColumn();

        $max_year = (int)$type['max_days_per_year'];
        if ($max_year > 0 && ($already + $days) > $max_year) {
            $left = max(0, $max_year - $already);
            throw new InvalidArgumentException(
                "{$type['type_name']} is capped at $max_year day(s) per year. "
                . "This employee has already used/booked $already in $year, so only $left day(s) remain."
            );
        }
    }
}

if (!function_exists('leaveTypeIdForEnum')) {
    /**
     * Resolve a legacy ENUM value ('annual', 'sick', …) to its leave_types row id,
     * matching on the first word of type_name — the same rule the backfill
     * migration used.
     *
     * For the writers that still speak ENUM (ESS apply, CSV import, project-side
     * leave) so the rows they create carry the FK and resolve on the list and
     * detail pages. Returns null for 'other' / unknown, which renders as an em dash.
     */
    function leaveTypeIdForEnum(PDO $pdo, ?string $enumValue): ?int
    {
        $enumValue = trim((string)$enumValue);
        if ($enumValue === '' || strtolower($enumValue) === 'other') return null;

        $st = $pdo->prepare(
            "SELECT type_id FROM leave_types
              WHERE LOWER(SUBSTRING_INDEX(type_name, ' ', 1)) = LOWER(?)
                AND status = 'active'
              ORDER BY type_id LIMIT 1"
        );
        $st->execute([$enumValue]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int)$id;
    }
}

if (!function_exists('legacyLeaveTypeEnum')) {
    /**
     * The legacy `leaves.leave_type` ENUM value for a type, for dual-write.
     *
     * The ENUM is still read by leave_reports.php, export_leaves.php and
     * project_view.php. A type with no matching member (e.g. 'Compassionate
     * Leave') falls back to 'other' — which is exactly why leave_type_id exists.
     * The column is dropped once every reader is migrated.
     */
    function legacyLeaveTypeEnum(array $type): string
    {
        static $valid = ['annual', 'sick', 'maternity', 'paternity', 'study', 'unpaid', 'other'];
        $first = strtolower(explode(' ', trim($type['type_name']))[0]);
        return in_array($first, $valid, true) ? $first : 'other';
    }
}
