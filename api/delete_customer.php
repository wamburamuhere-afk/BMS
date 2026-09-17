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

    // 2026-09-17 fix: this used to hard-DELETE the row whenever the customer
    // had no sales_orders/invoices — which meant almost every Simple POS
    // customer (who by definition never has either) got hard-deleted by
    // default, even one still carrying an unpaid POS credit balance, in
    // direct violation of this codebase's own "never hard-DELETE" standard
    // (.claude/security.md §12). Always soft-delete now, no exceptions —
    // the customer_id stays valid for every pos_sales/invoice row that
    // already references it (their own history is never touched), and the
    // record itself can be restored by an admin if deleted by mistake.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE customer_id = ?");
    $stmt->execute([$customerId]);
    $hasPosSales = $stmt->fetchColumn() > 0;

    $stmt = $pdo->prepare("UPDATE customers SET status = 'deleted', updated_by = ? WHERE customer_id = ?");
    $stmt->execute([$_SESSION['user_id'], $customerId]);
    $message = $hasPosSales
        ? 'Customer marked as deleted. Their POS sales history and any credit balance are preserved and still trackable.'
        : 'Customer marked as deleted.';

    logActivity($pdo, $_SESSION['user_id'], "Delete customer", "deleted customer \"{$customer['customer_name']}\" with id $customerId");

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
