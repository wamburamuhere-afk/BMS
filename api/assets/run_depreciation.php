<?php
/**
 * api/assets/run_depreciation.php
 *
 * Triggers the depreciation engine for a financial year (Asset Register & PPE
 * Schedule, Phase 4). Posts/updates depreciation_entries for every depreciable
 * asset up to the chosen FY. Idempotent — already-posted periods are skipped.
 *
 * POST: fy_year (int, e.g. 2026); optional asset_id to restrict to one asset.
 * Permission: canEdit('assets').
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/asset_depreciation_run.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('assets')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$fy_year   = isset($_POST['fy_year']) && $_POST['fy_year'] !== '' ? (int)$_POST['fy_year'] : (int)date('Y');
$asset_id  = isset($_POST['asset_id']) && $_POST['asset_id'] !== '' ? (int)$_POST['asset_id'] : null;

if ($fy_year < 2000 || $fy_year > 2100) {
    echo json_encode(['success' => false, 'message' => 'Invalid financial year']);
    exit;
}

try {
    global $pdo;
    $summary = runDepreciation($pdo, $fy_year, (int)($_SESSION['user_id'] ?? 0), $asset_id);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Ran Depreciation',
        "FY {$fy_year}: {$summary['periods_written']} written, {$summary['periods_skipped_posted']} already posted, {$summary['assets']} assets");

    echo json_encode([
        'success' => true,
        'message' => "Depreciation run for FY {$fy_year}: {$summary['periods_written']} period(s) posted, {$summary['periods_skipped_posted']} already posted.",
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    error_log('run_depreciation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Engine error: ' . $e->getMessage()]);
}
