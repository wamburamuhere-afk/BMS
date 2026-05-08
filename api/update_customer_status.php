<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check permission
if (!canEdit('customers')) {
     echo json_encode(['success' => false, 'message' => 'Permission denied']);
     exit;
}

// Get POST data
$customer_id = $_POST['customer_id'] ?? '';
$status = $_POST['status'] ?? '';

// Validate required fields
if (empty($customer_id) || empty($status)) {
    echo json_encode(['success' => false, 'message' => 'Customer ID and status are required']);
    exit();
}

// Validate status
$valid_statuses = ['active', 'inactive', 'suspended', 'blacklisted'];
if (!in_array($status, $valid_statuses)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

// Get customer
$stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ? AND status != 'deleted'");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Customer not found']);
    exit();
}

// Update customer status
$update_stmt = $pdo->prepare("
    UPDATE customers SET
        status = ?,
        updated_by = ?,
        updated_at = NOW()
    WHERE customer_id = ?
");

try {
    $update_stmt->execute([$status, $_SESSION['user_id'], $customer_id]);
    
    // Log Activity (assuming helpers.php logActivity is available via roots.php)
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], ucfirst($status) . " customer: " . $customer['customer_name']);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => "Customer status updated to " . ucfirst($status)
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
