<?php
// scope-audit: skip — warehouse delete API; write-side gate; project scope not needed for delete operation
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!canDelete('warehouses')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to delete warehouses']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

try {
    $warehouse_id = intval($_POST['warehouse_id'] ?? 0);
    $project_id   = intval($_POST['project_id']   ?? 0);

    if ($warehouse_id <= 0) throw new Exception('Warehouse ID is required.');
    if ($project_id   <= 0) throw new Exception('Project ID is required.');

    // Found during the 2026-07-17 warehouse-scope sweep: this endpoint had no
    // project-scope check at all — any user with delete permission could
    // delete any project's warehouse just by supplying that project's id.
    if (!userCan('project', $project_id)) {
        throw new Exception('Access denied: this project is not in your assigned scope.');
    }

    // Confirm warehouse belongs to this project before deleting
    $check = $pdo->prepare("SELECT warehouse_id, warehouse_name FROM warehouses WHERE warehouse_id = ? AND project_id = ?");
    $check->execute([$warehouse_id, $project_id]);
    $wh = $check->fetch(PDO::FETCH_ASSOC);

    if (!$wh) throw new Exception('Warehouse not found in this project.');

    $pdo->prepare("DELETE FROM warehouses WHERE warehouse_id = ? AND project_id = ?")
        ->execute([$warehouse_id, $project_id]);

    // Phase 3c — warehouse delete affects all linked stock/movements.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Delete warehouse", "deleted warehouse \"{$wh['warehouse_name']}\" with id $warehouse_id (project $project_id)");

    echo json_encode(['success' => true, 'message' => 'Warehouse deleted successfully.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
