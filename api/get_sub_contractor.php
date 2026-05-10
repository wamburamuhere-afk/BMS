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

// Fetch sub-contractor data
$stmt = $pdo->prepare("SELECT * FROM sub_contractors WHERE supplier_id = ? AND status != 'deleted'");
$stmt->execute([$id]);
$sub_contractor = $stmt->fetch(PDO::FETCH_ASSOC);

if ($sub_contractor) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $sub_contractor]);
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Sub-Contractor not found']);
}
