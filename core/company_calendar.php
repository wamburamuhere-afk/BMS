<?php
/**
 * core/company_calendar.php
 * --------------------------
 * Working-days + public-holidays calendar. Backs the opt-in "count only working
 * days" flag on leave types (core/leave_rules.php::leaveDaysFor()) so a leave
 * type can, if the admin chooses, deduct business days instead of calendar days.
 *
 * Working days are a single company-wide setting (system_settings key
 * 'company_working_days', comma list of ISO weekday numbers 1=Mon..7=Sun,
 * default '1,2,3,4,5'). Holidays are the public_holidays table — a fixed date,
 * or a recurring (`recurring=1`) holiday matched by month+day every year.
 */

if (!function_exists('companyWorkingDays')) {
    /** @return int[] ISO weekday numbers (1=Mon..7=Sun) that count as working days. */
    function companyWorkingDays(): array
    {
        $raw = get_setting('company_working_days', '1,2,3,4,5');
        $days = array_filter(array_map('intval', explode(',', $raw)), fn($d) => $d >= 1 && $d <= 7);
        return $days ?: [1, 2, 3, 4, 5];
    }
}

if (!function_exists('isCompanyWorkingWeekday')) {
    function isCompanyWorkingWeekday(int $isoWeekday, array $workingDays): bool
    {
        return in_array($isoWeekday, $workingDays, true);
    }
}

if (!function_exists('isPublicHoliday')) {
    /** True if $dateYmd ('Y-m-d') is an active holiday — exact date, or a recurring one on the same month/day. */
    function isPublicHoliday(PDO $pdo, string $dateYmd): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $dateYmd);
        if (!$d) return false;
        $monthDay = $d->format('m-d');

        $stmt = $pdo->prepare("
            SELECT 1 FROM public_holidays
             WHERE status = 'active'
               AND (
                     holiday_date = ?
                  OR (recurring = 1 AND DATE_FORMAT(holiday_date, '%m-%d') = ?)
                   )
             LIMIT 1
        ");
        $stmt->execute([$dateYmd, $monthDay]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('publicHolidaysInRange')) {
    /**
     * All holiday occurrences (fixed + recurring, expanded) between $start and
     * $end inclusive. Returns ['Y-m-d' => holiday_name].
     */
    function publicHolidaysInRange(PDO $pdo, string $start, string $end): array
    {
        $rows = $pdo->query("SELECT holiday_name, holiday_date, recurring FROM public_holidays WHERE status = 'active'")
            ->fetchAll(PDO::FETCH_ASSOC);

        $startYear = (int)substr($start, 0, 4);
        $endYear   = (int)substr($end, 0, 4);
        $out = [];

        foreach ($rows as $r) {
            if ((int)$r['recurring'] === 1) {
                $monthDay = substr($r['holiday_date'], 5); // 'MM-DD'
                for ($y = $startYear; $y <= $endYear; $y++) {
                    $occurrence = "$y-$monthDay";
                    if ($occurrence >= $start && $occurrence <= $end) {
                        $out[$occurrence] = $r['holiday_name'];
                    }
                }
            } else {
                if ($r['holiday_date'] >= $start && $r['holiday_date'] <= $end) {
                    $out[$r['holiday_date']] = $r['holiday_name'];
                }
            }
        }
        ksort($out);
        return $out;
    }
}

if (!function_exists('businessDaysBetween')) {
    /** Count of days in [$start, $end] (inclusive) that are a working weekday AND not a public holiday. */
    function businessDaysBetween(PDO $pdo, string $start, string $end): int
    {
        $workingDays = companyWorkingDays();
        $holidays = publicHolidaysInRange($pdo, $start, $end);

        $cursor = new DateTime($start);
        $last   = new DateTime($end);
        $count  = 0;
        while ($cursor <= $last) {
            $ymd = $cursor->format('Y-m-d');
            if (isCompanyWorkingWeekday((int)$cursor->format('N'), $workingDays) && !isset($holidays[$ymd])) {
                $count++;
            }
            $cursor->modify('+1 day');
        }
        return $count;
    }
}
