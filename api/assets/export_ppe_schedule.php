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

// Title rows.
fputcsv($out, [strtoupper($area) . ' PPE SCHEDULE — FY ' . $fy . ' (' . $ps . ' to ' . $pe . ')']);
fputcsv($out, []);

// Header: TZS | each category | TOTAL.
$header = ['TZS'];
foreach ($rows as $r) $header[] = $r['category'] . ($r['is_depreciable'] ? '' : ' (Land)');
$header[] = 'TOTAL';
fputcsv($out, $header);

$emit = function ($label, $key) use ($out, $rows, $totals) {
    $line = [$label];
    foreach ($rows as $r) $line[] = round($r[$key], 2);
    $line[] = round($totals[$key], 2);
    fputcsv($out, $line);
};

fputcsv($out, ['COST']);
$emit('  Opening',   'cost_opening');
$emit('  Additions', 'cost_additions');
$emit('  Disposals', 'cost_disposals');
$emit('  Closing',   'cost_closing');

fputcsv($out, ['ACCUMULATED DEPRECIATION']);
$emit('  Opening',          'dep_opening');
$emit('  Charge for year',  'dep_charge');
$emit('  Less on disposal', 'dep_disposal');
$emit('  Closing',          'dep_closing');

fputcsv($out, ['NET BOOK VALUE', ]);
$emit('  Net Book Value', 'nbv');

fclose($out);
