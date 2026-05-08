<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';
global $pdo;

// Check if user is logged in
if (!isAuthenticated()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check permission dynamically
if (!canDelete('budgets')) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete budgets']);
    exit();
}

// Get POST data
$budget_id = $_POST['budget_id'] ?? '';

// Validate required fields
if (empty($budget_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Budget ID is required']);
    exit();
}

// Get budget details for logging
$stmt = $pdo->prepare("SELECT * FROM budgets WHERE budget_id = ?");
$stmt->execute([$budget_id]);
$budget = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$budget) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Budget not found']);
    exit();
}

// Check if budget is approved (only users with delete permission can delete approved budgets)
if ($budget['status'] == 'approved' && !isAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Cannot delete approved budget']);
    exit();
}

// Delete budget
$delete_stmt = $pdo->prepare("DELETE FROM budgets WHERE budget_id = ?");

try {
    $delete_stmt->execute([$budget_id]);
    
    // Log the action
    logActivity($pdo, $_SESSION['user_id'], "Deleted budget ID: $budget_id (Category ID: {$budget['category_id']}, Amount: {$budget['allocated_amount']})");
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => 'Budget deleted successfully'
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
