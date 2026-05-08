<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check permissions - Admin, Manager, Purchasing can update suppliers
if (!isAdmin() && !canEdit('suppliers')) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Get POST data
$supplier_id = $_POST['supplier_id'] ?? '';
$status = $_POST['status'] ?? '';

// Validate required fields
if (empty($supplier_id) || empty($status)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier ID and status are required']);
    exit();
}

// Validate status
$valid_statuses = ['active', 'inactive', 'suspended', 'blacklisted'];
if (!in_array($status, $valid_statuses)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

// Get supplier
$stmt = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_id = ? AND status != 'deleted'");
$stmt->execute([$supplier_id]);
$supplier = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    exit();
}

// Check if there are pending orders when trying to deactivate
if (in_array($status, ['inactive', 'suspended', 'blacklisted']) && $supplier['status'] == 'active') {
    $orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ? AND status IN ('pending', 'ordered')");
    $orders_stmt->execute([$supplier_id]);
    $pending_orders = $orders_stmt->fetchColumn();
    
    if ($pending_orders > 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Cannot deactivate supplier with pending orders']);
        exit();
    }
}

// Update supplier status
$update_stmt = $pdo->prepare("
    UPDATE suppliers SET
        status = ?,
        updated_by = ?,
        updated_at = NOW()
    WHERE supplier_id = ?
");

try {
    $update_stmt->execute([$status, $_SESSION['user_id'], $supplier_id]);
    
    // Log Activity
    require_once __DIR__ . '/../helpers.php';
    logActivity($pdo, $_SESSION['user_id'], ucfirst($status) . " supplier: " . $supplier['supplier_name']);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true, 
        'message' => "Supplier status updated to $status"
    ]);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}