<?php
/**
 * API: Quick Add Customer for POS
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!canCreate('customers')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied: you do not have permission to add customers']);
    exit();
}

try {
    global $pdo;

    $customer_name = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if (empty($customer_name)) {
        throw new Exception("Customer name is required");
    }
    
    // Company-prefixed sequential customer code (BFS-CUST-0001).
    require_once __DIR__ . '/../core/code_generator.php';
    $customer_code = nextCode($pdo, 'CUST');
    
    // Insert customer
    $stmt = $pdo->prepare("
        INSERT INTO customers (
            customer_code, customer_name, phone, customer_type, status, created_at
        ) VALUES (?, ?, ?, 'individual', 'active', NOW())
    ");
    
    $stmt->execute([$customer_code, $customer_name, $phone]);
    
    $customerId = $pdo->lastInsertId();
    
    // Log Activity
    logActivity($pdo, $_SESSION['user_id'], "Created customer: $customer_name ($customer_code)");
    
    echo json_encode([
        'success' => true,
        'message' => 'Customer added successfully',
        'customer_id' => $customerId
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
