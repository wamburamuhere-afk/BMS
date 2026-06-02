<?php
/**
 * api/assets/export_ppe_schedule.php
 *
 * Exports the PPE schedule (Phase 7) as a CSV (Excel-openable): categories
 * across columns, movement lines down rows, with a TOTAL column.
 *
 * GET: fy (int), area ('book'|'tax'). Permission: canView('assets').
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/asset_ppe_schedule_service.php';
require_once __DIR__ . '/../../core/asset_depreciation_run.php';
require_once __DIR__ . '/../../core/asset_settings.php';

if (!isAuthenticated() || !canView('assets')) {
    http_response_code(403);
    exit('Access denied');
}

$settings = getAssetSettings($pdo);
$fy   = isset($_GET['fy']) && $_GET['fy'] !== '' ? (int)$_GET['fy'] : (int)date('Y', strtotime($settings['financial_year_start']));
$area = (isset($_GET['area']) && $_GET['area'] === 'tax') ? 'tax' : 'book';

[$ps, $pe] = fyBoundsForYear($settings, $fy);
$sch    = buildPpeSchedule($pdo, $ps, $pe, $area);
$rows   = $sch['rows'];
$totals = $sch['totals'];

if (function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Exported PPE Schedule', "FY $fy, area $area");
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ppe_schedule_' . $area . '_FY' . $fy . '.csv"');

$out = fopen('php://output', 'w');

// Statutory PPE-note labels (e.g. 01.01.2025 / 31.12.2025).
$openLabel  = date('d.m.Y', strtotime($ps));
$closeLabel = date('d.m.Y', strtotime($pe));
$yearLabel  = date('Y', strtotime($pe));
$asAt       = strtoupper(date('j F Y', strtotime($pe)));

// Statutory layout: asset classes ACROSS the columns, movement lines DOWN the
// rows. Disposals shown as negatives so each section reads as a running total.
fputcsv($out, ['SCHEDULE OF PROPERTY, PLANT AND EQUIPMENT AS AT ' . $asAt . ' (' . ucfirst($area) . ' area, TZS)']);
fputcsv($out, []);

// Header: TZS | each Category | TOTAL.
$header = ['TZS'];
foreach ($rows as $r) $header[] = $r['category'] . ($r['is_depreciable'] ? '' : ' (Land)');
$header[] = 'TOTAL';
fputcsv($out, $header);

$emit = function ($label, $key, $negate = false) use ($out, $rows, $totals) {
    $line = [$label];
    foreach ($rows as $r) $line[] = round($negate ? -$r[$key] : $r[$key], 2) + 0; // +0 avoids "-0"
    $line[] = round($negate ? -$totals[$key] : $totals[$key], 2) + 0;
    fputcsv($out, $line);
};

fputcsv($out, ['COST']);
$emit('At ' . $openLabel,        'cost_opening');
$emit('Additions ' . $yearLabel, 'cost_additions');
$emit('Disposal',                'cost_disposals', true);
$emit('At ' . $closeLabel,       'cost_closing');

fputcsv($out, ['DEPRECIATION']);
$emit('At ' . $openLabel,           'dep_opening');
$emit('Charges for the Year',       'dep_charge');
$emit('Less Acc Depr on Disposal',  'dep_disposal', true);
$emit('At ' . $closeLabel,          'dep_closing');

fputcsv($out, ['NET BOOK VALUE']);
$emit('At ' . $closeLabel, 'nbv');

fclose($out);
