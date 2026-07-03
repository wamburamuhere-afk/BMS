<?php
/**
 * Admin-only trigger: re-sync the location reference tables from the
 * configured provider. This is how new administrative areas enter the
 * system — update the dataset files (data/locations/tz) or add a newer
 * provider, then hit this endpoint. No source-code change, no deploy
 * needed for pure dataset refreshes on the same provider.
 */
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/Location/bootstrap.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: admin only']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

try {
    set_time_limit(300); // full dataset pass can take a couple of minutes

    $sync   = new LocationSyncService($pdo);
    $report = $sync->sync(new MtaaCsvProvider(), (int)$_SESSION['user_id']);

    logActivity($pdo, $_SESSION['user_id'],
        "Location sync ({$report['provider']}): +{$report['wards_inserted']} wards, +{$report['villages_inserted']} villages");

    echo json_encode(['success' => true, 'message' => 'Location sync complete.', 'report' => $report]);
} catch (Throwable $e) {
    error_log('location sync error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Sync failed: ' . $e->getMessage()]);
}
