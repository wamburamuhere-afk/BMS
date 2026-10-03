<?php
/**
 * core/field_reports.php — Field Reports (marketing) module helpers.
 *
 * Access rule (product owner, 2026-10-03): every user sees and changes ONLY
 * their own visits; admins (isAdmin()) see and change everyone's — but another
 * staff member's day only after it was submitted (frSubmittedOnlySql). No role,
 * whatever it is granted, can see another staff member's visits — so every
 * query goes through frScopeUserId().
 */

if (!function_exists('frBusinessTypes')) {
    /** code => English label (translate with t() at render time). */
    function frBusinessTypes(): array
    {
        return [
            'retail'      => 'Retail shop',
            'wholesale'   => 'Wholesale shop',
            'restaurant'  => 'Restaurant / Hotel',
            'salon'       => 'Salon / Beauty',
            'pharmacy'    => 'Pharmacy',
            'hardware'    => 'Hardware / Building materials',
            'fashion'     => 'Clothing / Fashion',
            'electronics' => 'Electronics / Phones',
            'fuel'        => 'Fuel station',
            'agent'       => 'Mobile money / Banking agent',
            'services'    => 'Services',
            'other'       => 'Other',
        ];
    }

    function frInterestLabels(): array
    {
        return [
            'interested'     => 'Interested',
            'thinking'       => 'Will think about it',
            'not_interested' => 'Not interested',
        ];
    }

    /** Digits only, Tanzanian numbers to 255XXXXXXXXX, so 0712…/+255712…/712… match. */
    function frNormalizePhone(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone);
        if (strlen($d) === 10 && $d[0] === '0') return '255' . substr($d, 1);
        if (strlen($d) === 9 && in_array($d[0], ['6', '7'], true)) return '255' . $d;
        return $d;
    }

    function frValidDate($s): bool
    {
        if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
        [$y, $m, $d] = array_map('intval', explode('-', $s));
        return checkdate($m, $d, $y);
    }

    /**
     * Whose visits a request may read. Non-admins: always themselves, whatever
     * was asked. Admins: the requested user, or null (= every staff member).
     */
    function frScopeUserId($requested): ?int
    {
        $me = (int)($_SESSION['user_id'] ?? 0);
        if (!isAdmin()) return $me;
        $requested = (int)$requested;
        return $requested > 0 ? $requested : null;
    }

    /**
     * Admins see another staff member's visits only once that day's report has been
     * submitted (product owner, 2026-10-03); their own visits always. Appends the
     * condition for visits aliased $a and pushes its parameter. '' for non-admins —
     * they are already limited to their own visits by frScopeUserId().
     */
    function frSubmittedOnlySql(string $a, array &$params): string
    {
        if (!isAdmin()) return '';
        $params[] = (int)($_SESSION['user_id'] ?? 0);
        return " AND ($a.user_id = ? OR EXISTS (SELECT 1 FROM field_report_days frd
                                                  WHERE frd.user_id = $a.user_id AND frd.report_date = $a.visit_date))";
    }

    /** Owner or admin. */
    function frCanTouch(array $visit): bool
    {
        return isAdmin() || (int)$visit['user_id'] === (int)($_SESSION['user_id'] ?? 0);
    }

    function frGetVisit(PDO $pdo, int $id): ?array
    {
        $s = $pdo->prepare("SELECT * FROM field_visits WHERE visit_id = ? AND status = 'active'");
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    function frStaffName(array $r): string
    {
        $n = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        return $n !== '' ? $n : (string)($r['username'] ?? '');
    }

    /** Visits in [from, to] for $userId (null = all — callers pass frScopeUserId()). */
    function frFetchVisits(PDO $pdo, string $from, string $to, ?int $userId): array
    {
        $sql = "SELECT v.*, u.first_name, u.last_name, u.username
                FROM field_visits v
                LEFT JOIN users u ON u.user_id = v.user_id
                WHERE v.status = 'active' AND v.visit_date BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($userId !== null) { $sql .= " AND v.user_id = ?"; $params[] = $userId; }
        $sql .= frSubmittedOnlySql('v', $params);
        $sql .= " ORDER BY v.visit_date ASC, v.visit_time ASC, v.visit_id ASC";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['staff_name'] = frStaffName($r);
        unset($r);
        return $rows;
    }

    /** Counts for a set of visits. A client (same phone) counts once for people/joined. */
    function frStats(array $rows): array
    {
        $people = $places = $joined = [];
        $cards = $trials = $trainings = 0;
        foreach ($rows as $r) {
            $key = $r['phone_normalized'] !== '' ? $r['phone_normalized'] : 'n:' . mb_strtolower(trim($r['client_name']));
            $people[$key] = true;
            $places[mb_strtolower(trim(preg_replace('/\s+/', ' ', $r['location'])))] = true;
            $cards     += (int)$r['gave_business_card'];
            $trials    += (int)$r['gave_trial_link'];
            $trainings += (int)$r['gave_training'];
            if ((int)$r['joined'] === 1) $joined[$key] = true;
        }
        return [
            'visits'    => count($rows),
            'people'    => count($people),
            'places'    => count($places),
            'cards'     => $cards,
            'trials'    => $trials,
            'trainings' => $trainings,
            'joined'    => count($joined),
        ];
    }

    /** Admin summary: one line per staff member who has visits in the range. */
    function frStaffSummary(array $rows): array
    {
        $by = [];
        foreach ($rows as $r) $by[(int)$r['user_id']][] = $r;
        $out = [];
        foreach ($by as $uid => $list) {
            $out[] = ['user_id' => $uid, 'staff_name' => $list[0]['staff_name']] + frStats($list);
        }
        usort($out, fn($a, $b) => $b['visits'] <=> $a['visits'] ?: strcasecmp($a['staff_name'], $b['staff_name']));
        return $out;
    }

    function frDayStatus(PDO $pdo, int $userId, string $date): ?array
    {
        $s = $pdo->prepare("SELECT * FROM field_report_days WHERE user_id = ? AND report_date = ?");
        $s->execute([$userId, $date]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** A visit on an already-submitted day changed: flag the day so the report says so. */
    function frMarkChanged(PDO $pdo, int $userId, string $date): void
    {
        $pdo->prepare("UPDATE field_report_days SET changed_after_submit = 1, last_changed_at = NOW()
                       WHERE user_id = ? AND report_date = ?")->execute([$userId, $date]);
    }

    /** "Ijumaa, 2 Oktoba 2026" / "Friday, 2 October 2026". */
    function frDateLabel(string $date, string $lang): string
    {
        $ts = strtotime($date);
        if ($lang === 'sw') {
            $days   = ['Jumapili', 'Jumatatu', 'Jumanne', 'Jumatano', 'Alhamisi', 'Ijumaa', 'Jumamosi'];
            $months = ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba'];
            return $days[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
        }
        return date('l, j F Y', $ts);
    }

    function frRangeLabel(string $from, string $to, string $lang): string
    {
        return $from === $to ? frDateLabel($from, $lang) : frDateLabel($from, $lang) . ' – ' . frDateLabel($to, $lang);
    }

    function frBusinessLabel(array $r): string
    {
        $types = frBusinessTypes();
        if (($r['business_type'] ?? 'other') === 'other' && trim((string)($r['business_other'] ?? '')) !== '') {
            return trim($r['business_other']);
        }
        return t($types[$r['business_type']] ?? 'Other');
    }

    /** A position captured with ±100 m or better (or with no accuracy reported). */
    function frGpsVerified(array $r): bool
    {
        return $r['latitude'] !== null && $r['longitude'] !== null
            && ($r['gps_accuracy_m'] === null || (int)$r['gps_accuracy_m'] <= 100);
    }

    /**
     * Follow-ups due by $asOf (today or overdue), not done, client not joined — for
     * $userId (null = every staff member; callers pass frScopeUserId()).
     */
    function frDueFollowUps(PDO $pdo, ?int $userId, string $asOf): array
    {
        $sql = "SELECT v.visit_id, v.user_id, v.client_name, v.client_phone, v.location, v.visit_date, v.follow_up_date,
                       v.business_type, v.business_other, u.first_name, u.last_name, u.username
                  FROM field_visits v LEFT JOIN users u ON u.user_id = v.user_id
                 WHERE v.status = 'active' AND v.follow_up_date IS NOT NULL AND v.follow_up_date <= ?
                   AND v.follow_up_done_at IS NULL AND v.joined = 0";
        $params = [$asOf];
        if ($userId !== null) { $sql .= " AND v.user_id = ?"; $params[] = $userId; }
        $sql .= frSubmittedOnlySql('v', $params);
        $sql .= " ORDER BY v.follow_up_date ASC, v.visit_id ASC LIMIT 200";
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'visit_id'       => (int)$r['visit_id'],
                'client_name'    => $r['client_name'],
                'client_phone'   => $r['client_phone'],
                'location'       => $r['location'],
                'business_label' => frBusinessLabel($r),
                'visit_date'     => $r['visit_date'],
                'follow_up_date' => $r['follow_up_date'],
                'days_overdue'   => (int)floor((strtotime($asOf) - strtotime($r['follow_up_date'])) / 86400),
                'staff_name'     => frStaffName($r),
                'can_edit'       => frCanTouch($r) && canEdit('field_visits'),
            ];
        }
        return $out;
    }

    /** Reads the date range from a request; defaults to today, swaps if reversed. */
    function frRequestRange(array $src): array
    {
        $today = date('Y-m-d');
        $from = frValidDate($src['date_from'] ?? $src['date'] ?? null) ? ($src['date_from'] ?? $src['date']) : $today;
        $to   = frValidDate($src['date_to'] ?? null) ? $src['date_to'] : $from;
        if ($to < $from) [$from, $to] = [$to, $from];
        return [$from, $to];
    }
}
