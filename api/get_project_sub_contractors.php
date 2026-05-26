<?php
// scope-audit: skip — project sub-contractors list; project_id required param — user must have project access
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$project_id = $_GET['project_id'] ?? null;
if (!$project_id) {
    echo json_encode(['success' => false, 'message' => 'Project ID required']);
    exit();
}

$stmt = $pdo->prepare("
    SELECT s.*, sc.category_name,
           u1.username as created_by_name,
           u2.username as updated_by_name,
           scp.assigned_at
    FROM sub_contractor_projects scp
    JOIN sub_contractors s ON scp.supplier_id = s.supplier_id
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    WHERE scp.project_id = ? AND s.status != 'deleted'
    ORDER BY s.supplier_name ASC
");
$stmt->execute([$project_id]);
$sub_contractors = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'data' => $sub_contractors]);
