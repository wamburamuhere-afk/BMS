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

// Check permission dynamically
if (!canDelete('suppliers')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete sub-contractors']);
    exit();
}

// Get ID
$supplier_id = $_POST['supplier_id'] ?? null;

if (!$supplier_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Sub-Contractor ID is required']);
    exit();
}

// Phase E — project-scope gate
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('sub_contractors', 'supplier_id', (int)$supplier_id);
}

// Mark as deleted instead of actual deletion
$stmt = $pdo->prepare("UPDATE sub_contractors SET status = 'deleted', updated_at = NOW() WHERE supplier_id = ?");

try {
    $stmt->execute([$supplier_id]);
    
    logActivity($pdo, $_SESSION['user_id'], "Deleted sub-contractor ID: $supplier_id");
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Sub-Contractor deleted successfully']);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
