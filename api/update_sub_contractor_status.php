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

// Check permission
if (!canEdit('suppliers')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to update sub-contractor status']);
    exit();
}

// Get data
$supplier_id = $_POST['supplier_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$supplier_id || !$status) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Sub-Contractor ID and status are required']);
    exit();
}

// Update status
$stmt = $pdo->prepare("UPDATE sub_contractors SET status = ?, updated_at = NOW() WHERE supplier_id = ?");

try {
    $stmt->execute([$status, $supplier_id]);
    
    logActivity($pdo, $_SESSION['user_id'], "Updated sub-contractor (ID: $supplier_id) status to: $status");
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Sub-Contractor status updated successfully']);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
