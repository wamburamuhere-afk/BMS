<?php
// GET date_from, date_to (or date), user_id (admins only), lang (sw|en) — the report as
// data, for the downloadable / shareable PDF (includes/field_reports/report_share.php).
// Same rows, columns, summary and wording as the print page and the Excel export.
require_once __DIR__ . '/_common.php';
require_once ROOT_DIR . '/core/field_reports_report.php';

[$from, $to] = frRequestRange($_GET);
$userId = frScopeUserId($_GET['user_id'] ?? null);   // non-admin => always self
$lang = frReportLang($_GET['lang'] ?? null);

try {
    $visits = frFetchVisits($pdo, $from, $to, $userId);
    $day = ($from === $to && $userId !== null) ? frDayStatus($pdo, $userId, $from) : null;
    $subject = frReportSubject($pdo, $userId);
} catch (PDOException $e) {
    error_log('field_reports/report_data: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}

loadLanguage($lang);   // everything below is in the report's language
$columns = frReportColumns($from !== $to, $userId === null, true);
$rows    = frReportRows($visits, $columns);
$summary = frReportSummary(frStats($visits));
$weights = frReportLandscapeWeights();

$company = getSetting('company_name', 'BUSINESS MANAGEMENT SYSTEM');
$logo    = getSetting('company_logo', '');
$printedBy = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: ($_SESSION['username'] ?? 'System');
$role      = ucfirst((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'User'));
$at        = frDateLabel(date('Y-m-d'), $lang) . ' ' . ($lang === 'sw' ? 'saa' : 'at') . ' ' . date('H:i');
$footer    = $lang === 'sw'
    ? 'Ripoti hii imechapishwa na ' . caseFormat($printedBy) . ' — ' . $role . ' ' . $at
    : 'This document was Printed by ' . caseFormat($printedBy) . ' — ' . $role . ' on ' . $at;
$rangeLabel = frRangeLabel($from, $to, $lang);

// WhatsApp / email text: the headline numbers, so the reader knows what is attached.
$lines = ['*' . t('CUSTOMER VISITS REPORT') . '*', t('Staff') . ': ' . $subject, t('Date') . ': ' . $rangeLabel, ''];
foreach ($summary as $label => $value) $lines[] = $label . ': ' . (int)$value;
$lines[] = '';
$lines[] = $company;

$slug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $subject), '_') ?: 'staff';
$filename = ($lang === 'sw' ? 'Ripoti_ya_Ziara_' : 'Customer_Visits_Report_') . $slug . '_' . $from . ($from !== $to ? '_to_' . $to : '') . '.pdf';

frJson([
    'success'  => true,
    'lang'     => $lang,
    'company'  => [
        'name'    => $company,
        'address' => getSetting('company_physical_address', getSetting('company_address', '')),
        'phone'   => getSetting('company_phone', ''),
        'email'   => getSetting('company_email', ''),
        'logo'    => $logo !== '' ? getUrl($logo) : '',
    ],
    'labels'   => [
        'title' => t('CUSTOMER VISITS REPORT'), 'staff' => t('Staff'), 'date' => t('Date'),
        'phone' => t('Phone'), 'email' => t('Email'), 'page' => t('Page'), 'of' => t('of'),
        'empty' => t('No visits recorded for this period.'), 'done' => t('done'),
    ],
    'subject'  => $subject,
    'range'    => $rangeLabel,
    'status'   => ($from === $to && $userId !== null) ? frReportStatusText($day) : '',
    'summary'  => array_map(fn($l, $v) => [$l, (int)$v], array_keys($summary), array_values($summary)),
    'columns'  => array_map(fn($k, $l) => ['key' => $k, 'label' => $l, 'weight' => $weights[$k] ?? 8], array_keys($columns), array_values($columns)),
    'rows'     => array_map('array_values', $rows),
    'footer'   => $footer,
    'brand'    => 'Powered By BJP Technologies © ' . date('Y') . ', All Rights Reserved',
    'filename' => $filename,
    'share_text' => implode("\n", $lines),
]);
