<?php
/**
 * core/field_reports_report.php — the report's columns and rows, shared by the
 * print page and the Excel (CSV) export so both always say the same thing.
 * Call after loadLanguage($reportLang): every label goes through t().
 */
require_once __DIR__ . '/field_reports.php';

if (!function_exists('frReportColumns')) {
    /** [key => label]; Date only for multi-day ranges, Staff only for an all-staff report. */
    function frReportColumns(bool $multiDay, bool $allStaff): array
    {
        $c = ['sno' => t('S/No')];
        if ($multiDay) $c['date'] = t('Date');
        $c['time'] = t('Time');
        if ($allStaff) $c['staff'] = t('Staff');
        $c += [
            'location' => t('Place visited'),
            'client'   => t('Client name'),
            'phone'    => t('Phone'),
            'business' => t('Business'),
            'card'     => t('Business card'),
            'trial'    => t('Free trial link'),
            'training' => t('Training'),
            'interest' => t('Response'),   // same word as the page ("Mwitikio")
            'joined'   => t('Joined'),
            'follow_up' => t('Follow-up'),
            'notes'    => t('Notes'),
        ];
        return $c;
    }

    /** Plain-text cells per column key (the print page escapes them). */
    function frReportRows(array $visits, array $columns): array
    {
        $yes = t('Yes'); $no = t('No');
        $interest = frInterestLabels();
        $out = [];
        foreach (array_values($visits) as $i => $v) {
            $cells = [
                'sno'      => (string)($i + 1),
                'date'     => date('d/m/Y', strtotime($v['visit_date'])),
                'time'     => $v['visit_time'] ? substr($v['visit_time'], 0, 5) : '—',
                'staff'    => $v['staff_name'] ?? '',
                // "(GPS)" = position captured with ±100 m or better (customer_visits_ux_plan 3.4)
                'location' => $v['location'] . (frGpsVerified($v) ? ' (GPS ✓)' : ''),
                'client'   => $v['client_name'],
                'phone'    => $v['client_phone'],
                'business' => frBusinessLabel($v),
                'card'     => (int)$v['gave_business_card'] ? $yes : $no,
                'trial'    => (int)$v['gave_trial_link'] ? $yes : $no,
                'training' => (int)$v['gave_training'] ? $yes : $no,
                'interest' => $v['interest'] ? t($interest[$v['interest']] ?? '') : '—',
                'joined'   => (int)$v['joined'] ? $yes : $no,
                'follow_up' => !empty($v['follow_up_date'])
                    ? date('d/m/Y', strtotime($v['follow_up_date'])) . (!empty($v['follow_up_done_at']) ? ' ✓' : '')
                    : '—',
                'notes'    => trim((string)($v['notes'] ?? '')) !== '' ? trim($v['notes']) : '—',
            ];
            $out[] = array_intersect_key($cells, $columns);
        }
        return $out;
    }

    /** Summary line items [label => value] in report order. */
    function frReportSummary(array $stats): array
    {
        return [
            t('Visits')               => $stats['visits'],
            t('People visited')       => $stats['people'],
            t('Places')               => $stats['places'],
            t('Business cards given') => $stats['cards'],
            t('Trial links given')    => $stats['trials'],
            t('Trainings given')      => $stats['trainings'],
            t('Joined our system')    => $stats['joined'],
        ];
    }

    /** Who the report is about: "Asha Juma" or "All staff". */
    function frReportSubject(PDO $pdo, ?int $userId): string
    {
        if ($userId === null) return t('All staff');
        $s = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE user_id = ?");
        $s->execute([$userId]);
        $u = $s->fetch(PDO::FETCH_ASSOC);
        return $u ? frStaffName($u) : '—';
    }

    /** Report language from the request: 'sw' | 'en', else the user's own language. */
    function frReportLang($requested): string
    {
        $requested = is_string($requested) ? strtolower($requested) : '';
        if (in_array($requested, ['sw', 'en'], true)) return $requested;
        return function_exists('currentLanguage') ? (currentLanguage() === 'sw' ? 'sw' : 'en') : 'en';
    }
}
