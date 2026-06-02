<?php
// api/operations/save_maintenance.php
//
// Records a maintenance event against an asset (Asset Register & PPE Schedule,
// Phase 6.2). Writes asset_maintenance with an optional next-due reminder date.
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/asset_audit_service.php';

global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canEdit('assets')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: you do not have permission to log maintenance']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
csrf_check();

$asset_id         = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
$maintenance_date = trim($_POST['maintenance_date'] ?? '');
$description      = trim($_POST['description'] ?? '');
$cost             = isset($_POST['cost']) && $_POST['cost'] !== '' ? (float)$_POST['cost'] : 0.0;
$performed_by     = trim($_POST['performed_by'] ?? '');
$next_due_date    = trim($_POST['next_due_date'] ?? '');

if (!$asset_id) { echo json_encode(['success' => false, 'message' => 'Asset ID is required']); exit; }
if (!DateTime::createFromFormat('Y-m-d', $maintenance_date)) {
    echo json_encode(['success' => false, 'message' => 'A valid maintenance date is required']); exit;
}
if ($next_due_date !== '' && !DateTime::createFromFormat('Y-m-d', $next_due_date)) {
    echo json_encode(['success' => false, 'message' => 'Next due date must be a valid date or blank']); exit;
}

try {
    // Confirm the asset exists and isn't deleted.
    $chk = $pdo->prepare("SELECT asset_name FROM assets WHERE asset_id = ? AND status != 'deleted'");
    $chk->execute([$asset_id]);
    $name = $chk->fetchColumn();
    if ($name === false) { echo json_encode(['success' => false, 'message' => 'Asset not found']); exit; }

    $pdo->prepare("
        INSERT INTO asset_maintenance
            (asset_id, maintenance_date, description, cost, performed_by, next_due_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $asset_id, $maintenance_date, ($description !== '' ? $description : null),
        $cost, ($performed_by !== '' ? $performed_by : null),
        ($next_due_date !== '' ? $next_due_date : null),
        $_SESSION['user_id'] ?? null,
    ]);

    logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Logged Asset Maintenance',
        "Asset ID: $asset_id ($name), cost: $cost");
    logAssetAudit($pdo, $asset_id, 'maintenance', null, null,
        "Maintenance on $maintenance_date" . ($cost ? " (cost $cost)" : ''), (int)($_SESSION['user_id'] ?? 0));

    echo json_encode(['success' => true, 'message' => 'Maintenance recorded.']);
} catch (PDOException $e) {
    error_log('save_maintenance error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
