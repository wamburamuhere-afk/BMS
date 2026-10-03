<?php
// GET date_from, date_to, user_id (admins only), lang (sw|en) — Excel-ready CSV of the report.
require_once __DIR__ . '/_common.php';
require_once ROOT_DIR . '/core/field_reports_report.php';

[$from, $to] = frRequestRange($_GET);
$userId = frScopeUserId($_GET['user_id'] ?? null);   // non-admin => always self
$lang = frReportLang($_GET['lang'] ?? null);

try {
    $visits = frFetchVisits($pdo, $from, $to, $userId);
} catch (PDOException $e) {
    error_log('field_reports/export: ' . $e->getMessage());
    frJson(['success' => false, 'message' => t('Server error')], 500);
}

loadLanguage($lang);
$columns = frReportColumns($from !== $to, $userId === null);
$rows = frReportRows($visits, $columns);
$subject = frReportSubject($pdo, $userId);

$file = 'customer_visits_' . preg_replace('/[^a-z0-9]+/i', '_', $subject) . '_' . $from . ($from !== $to ? '_to_' . $to : '') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $file . '"');
logActivity($pdo, (int)$_SESSION['user_id'], 'Export field report', "Exported field report $from..$to ($subject)");

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
fputcsv($out, [getSetting('company_name', 'BMS')]);
fputcsv($out, [t('CUSTOMER VISITS REPORT')]);
fputcsv($out, [t('Staff') . ': ' . $subject]);
fputcsv($out, [t('Date') . ': ' . frRangeLabel($from, $to, $lang)]);
fputcsv($out, []);
foreach (frReportSummary(frStats($visits)) as $label => $value) fputcsv($out, [$label, $value]);
fputcsv($out, []);
fputcsv($out, array_values($columns));
// Typed text starting with = + - @ would run as a formula in Excel.
$safe = fn($c) => preg_match('/^[=+\-@\t\r]/', (string)$c) ? "'" . $c : $c;
foreach ($rows as $r) fputcsv($out, array_map($safe, array_values($r)));
if (!$rows) fputcsv($out, [t('No visits recorded for this period.')]);
fclose($out);
