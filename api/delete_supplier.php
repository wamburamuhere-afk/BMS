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
    echo json_encode(['success' => false, 'message' => 'Access Denied: You do not have permission to delete suppliers']);
    exit();
}

// Get POST data
$supplier_id = $_POST['supplier_id'] ?? '';

if (empty($supplier_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier ID is required']);
    exit();
}

// Get supplier details for logging
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    exit();
}

// Check if supplier has associated records
$orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
$orders_stmt->execute([$supplier_id]);
$order_count = $orders_stmt->fetchColumn();

$payments_stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_payments WHERE supplier_id = ?");
$payments_stmt->execute([$supplier_id]);
$payment_count = $payments_stmt->fetchColumn();

if ($order_count > 0 || $payment_count > 0) {
    // Soft delete (change status to deleted)
    $delete_stmt = $pdo->prepare("UPDATE suppliers SET status = 'deleted', updated_by = ?, updated_at = NOW() WHERE supplier_id = ?");
    
    try {
        $delete_stmt->execute([$_SESSION['user_id'], $supplier_id]);

        logActivity($pdo, $_SESSION['user_id'], "Soft Deleted Supplier", "Supplier: " . $supplier['supplier_name'] . " (ID: $supplier_id) — had associated records");

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Supplier marked as deleted (soft delete due to associated records)'
        ]);
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    // Hard delete (remove from database)
    $delete_stmt = $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?");
    
    try {
        $delete_stmt->execute([$supplier_id]);

        logActivity($pdo, $_SESSION['user_id'], "Deleted Supplier", "Supplier: " . $supplier['supplier_name'] . " (ID: $supplier_id)");

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Supplier permanently deleted'
        ]);
        
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}