<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!canDelete('customers')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$customerId = $_POST['customer_id'] ?? '';

if (empty($customerId)) {
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
    exit;
}

try {
    global $pdo;

    // Phase E — project-scope gate
    if (function_exists('assertScopeForRecord')) {
        assertScopeForRecord('customers', 'customer_id', (int)$customerId);
    }

    // Check if customer exists
    $stmt = $pdo->prepare("SELECT customer_name FROM customers WHERE customer_id = ? AND status != 'deleted'");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }

    // Check for associated records
    $hasDependencies = false;
    
    // Check sales orders
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_orders WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    if ($stmt->fetchColumn() > 0) $hasDependencies = true;

    // Check invoices
    if (!$hasDependencies) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        if ($stmt->fetchColumn() > 0) $hasDependencies = true;
    }

    if ($hasDependencies) {
        // Soft delete
        $stmt = $pdo->prepare("UPDATE customers SET status = 'deleted', updated_by = ? WHERE customer_id = ?");
        $stmt->execute([$_SESSION['user_id'], $customerId]);
        $message = 'Customer marked as deleted (soft delete due to existing records)';
    } else {
        // Hard delete
        $stmt = $pdo->prepare("DELETE FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $message = 'Customer permanently deleted';
    }

    logActivity($pdo, $_SESSION['user_id'], "Deleted customer: {$customer['customer_name']} (ID: $customerId)");

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
