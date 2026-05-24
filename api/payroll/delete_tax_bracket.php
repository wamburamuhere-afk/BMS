<?php
require_once __DIR__ . '/../../roots.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// canDelete('payroll') admin-bypasses internally; replaces legacy hard-coded
// role-string check so future non-admin roles can be delegated via user_roles.php.
if (!canDelete('payroll')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied: you do not have permission to delete tax brackets']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }
    
    $tax_bracket_id = $_POST['tax_bracket_id'] ?? 0;
    
    if (empty($tax_bracket_id)) {
        throw new Exception('Tax bracket ID is required');
    }
    
    // Soft delete by setting is_active to 0
    $stmt = $pdo->prepare("UPDATE tax_brackets SET is_active = 0 WHERE tax_bracket_id = ?");
    $stmt->execute([$tax_bracket_id]);
    
    if ($stmt->rowCount() > 0) {
        logActivity($pdo, $_SESSION['user_id'], "Deleted Tax Bracket", "Tax Bracket ID: $tax_bracket_id (soft delete)");
        echo json_encode([
            'success' => true,
            'message' => 'Tax bracket deleted successfully'
        ]);
    } else {
        throw new Exception('Tax bracket not found');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
