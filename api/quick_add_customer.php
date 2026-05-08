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

try {
    global $pdo;
    
    $customer_name = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    
    if (empty($customer_name)) {
        throw new Exception("Customer name is required");
    }
    
    // Generate customer code
    $customer_code = 'CUST-' . date('Ymd') . '-' . mt_rand(100, 999);
    
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
