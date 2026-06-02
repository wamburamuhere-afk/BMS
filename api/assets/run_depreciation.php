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
// Preview is read-only (canView); posting requires canEdit.
$mode = ($_POST['mode'] ?? 'post') === 'preview' ? 'preview' : 'post';
if ($mode === 'post' && !canEdit('assets')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}
if ($mode === 'preview' && !canView('assets')) {
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

$fy_year     = isset($_POST['fy_year']) && $_POST['fy_year'] !== '' ? (int)$_POST['fy_year'] : (int)date('Y');
$scope_type  = in_array($_POST['scope_type'] ?? 'all', ['all', 'category', 'asset'], true) ? $_POST['scope_type'] : 'all';
$scope_value = isset($_POST['scope_value']) && $_POST['scope_value'] !== '' ? $_POST['scope_value'] : null;
// Back-compat: a bare asset_id still scopes to that asset.
if ($scope_type === 'all' && isset($_POST['asset_id']) && $_POST['asset_id'] !== '') {
    $scope_type = 'asset'; $scope_value = (int)$_POST['asset_id'];
}

if ($fy_year < 2000 || $fy_year > 2100) {
    echo json_encode(['success' => false, 'message' => 'Invalid financial year']);
    exit;
}

try {
    global $pdo;

    if ($mode === 'preview') {
        // Read-only proposal — nothing written, no GL, no audit.
        $proposal = previewDepreciation($pdo, $fy_year, ['type' => $scope_type, 'value' => $scope_value]);
        echo json_encode(['success' => true, 'mode' => 'preview', 'proposal' => $proposal]);
        exit;
    }

    // Post: translate scope into the engine's filters.
    $only_asset    = $scope_type === 'asset'    ? (int)$scope_value : null;
    $only_category = $scope_type === 'category' ? (string)$scope_value : null;
    $summary = runDepreciation($pdo, $fy_year, (int)($_SESSION['user_id'] ?? 0), $only_asset, $only_category);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Ran Depreciation',
        "FY {$fy_year} (scope {$scope_type}): {$summary['periods_written']} written, {$summary['periods_skipped_posted']} already posted, {$summary['assets']} assets");

    echo json_encode([
        'success' => true,
        'mode'    => 'post',
        'message' => "Depreciation posted for FY {$fy_year}: {$summary['periods_written']} period(s) posted, {$summary['periods_skipped_posted']} already posted.",
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    error_log('run_depreciation error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Engine error: ' . $e->getMessage()]);
}
