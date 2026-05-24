<?php
require_once __DIR__ . '/../../roots.php';
header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
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

    // Confirm warehouse belongs to this project before deleting
    $check = $pdo->prepare("SELECT warehouse_id, warehouse_name FROM warehouses WHERE warehouse_id = ? AND project_id = ?");
    $check->execute([$warehouse_id, $project_id]);
    $wh = $check->fetch(PDO::FETCH_ASSOC);

    if (!$wh) throw new Exception('Warehouse not found in this project.');

    $pdo->prepare("DELETE FROM warehouses WHERE warehouse_id = ? AND project_id = ?")
        ->execute([$warehouse_id, $project_id]);

    // Phase 3c — warehouse delete affects all linked stock/movements.
    logActivity($pdo, $_SESSION['user_id'] ?? 0, "Deleted Warehouse", "Warehouse ID: $warehouse_id ({$wh['warehouse_name']}), project: $project_id");

    echo json_encode(['success' => true, 'message' => 'Warehouse deleted successfully.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
