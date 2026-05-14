<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$supplier_id = intval($_GET['supplier_id'] ?? 0);
if (!$supplier_id) {
    echo json_encode(['success' => false, 'message' => 'Sub-contractor ID required']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT p.project_id, p.project_name, p.status, p.contract_sum,
               scp.assigned_at, u.username as assigned_by_name
        FROM sub_contractor_projects scp
        JOIN projects p ON scp.project_id = p.project_id
        LEFT JOIN users u ON scp.assigned_by = u.user_id
        WHERE scp.supplier_id = ?
        ORDER BY scp.assigned_at DESC
    ");
    $stmt->execute([$supplier_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $projects, 'count' => count($projects)]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
