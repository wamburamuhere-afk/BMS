<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
global $pdo;

// Check if user is logged in
if (!isAuthenticated()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Get ID
$id = $_GET['id'] ?? null;

if (!$id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'ID is required']);
    exit();
}

// Fetch sub-contractor data (include project name for Select2 pre-selection)
$stmt = $pdo->prepare("
    SELECT sc.*, p.project_name
    FROM sub_contractors sc
    LEFT JOIN projects p ON sc.project_id = p.project_id
    WHERE sc.supplier_id = ? AND sc.status != 'deleted'
");
$stmt->execute([$id]);
$sub_contractor = $stmt->fetch(PDO::FETCH_ASSOC);

if ($sub_contractor) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $sub_contractor]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Sub-Contractor not found']);
}
