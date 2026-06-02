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

// Title + grouped, transposed layout (asset classes down rows) matching the
// statutory PPE schedule.
fputcsv($out, ['SCHEDULE OF PROPERTY, PLANT AND EQUIPMENT AS AT ' . $asAt . ' (' . ucfirst($area) . ' area, TZS)']);
fputcsv($out, []);
fputcsv($out, ['Asset Class', 'COST', '', '', '', 'DEPRECIATION', '', '', '', 'Net Book Value']);
fputcsv($out, ['', 'At ' . $openLabel, 'Additions ' . $yearLabel, 'Disposal', 'At ' . $closeLabel,
               'At ' . $openLabel, 'Charge for the Year', 'Less Acc Depr on Disposal', 'At ' . $closeLabel, $closeLabel]);

$emitRow = function ($label, $src) use ($out) {
    fputcsv($out, [
        $label,
        round($src['cost_opening'], 2), round($src['cost_additions'], 2), round($src['cost_disposals'], 2), round($src['cost_closing'], 2),
        round($src['dep_opening'], 2),  round($src['dep_charge'], 2),     round($src['dep_disposal'], 2),    round($src['dep_closing'], 2),
        round($src['nbv'], 2),
    ]);
};

foreach ($rows as $r) {
    $emitRow($r['category'] . ($r['is_depreciable'] ? '' : ' (Land)'), $r);
}
$emitRow('TOTAL', $totals);

fclose($out);
