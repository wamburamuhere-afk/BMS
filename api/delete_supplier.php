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

// Phase E — project-scope gate
if (function_exists('assertScopeForRecord')) {
    assertScopeForRecord('suppliers', 'supplier_id', (int)$supplier_id);
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

// 2026-09-17 fix (same bug found and fixed on the Customer side): this used
// to hard-DELETE the row whenever the supplier had no purchase_orders/
// supplier_payments — which is the default for nearly every Simple POS
// supplier (Quick Restock's outflow never even links to a supplier_id, per
// post_principle.md's "assume already paid" instruction), in direct
// violation of this codebase's own "never hard-DELETE" standard
// (.claude/security.md §12). Always soft-delete now, no exceptions.
// suppliers.status already correctly includes 'deleted' in its enum (unlike
// customers.status before its own 2026-09-17 migration), so no schema change
// is needed here.
$orders_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
$orders_stmt->execute([$supplier_id]);
$hasOrders = $orders_stmt->fetchColumn() > 0;

$payments_stmt = $pdo->prepare("SELECT COUNT(*) FROM supplier_payments WHERE supplier_id = ?");
$payments_stmt->execute([$supplier_id]);
$hasPayments = $payments_stmt->fetchColumn() > 0;

$delete_stmt = $pdo->prepare("UPDATE suppliers SET status = 'deleted', updated_by = ?, updated_at = NOW() WHERE supplier_id = ?");

try {
    $delete_stmt->execute([$_SESSION['user_id'], $supplier_id]);

    logActivity($pdo, $_SESSION['user_id'], "Delete supplier", "deleted supplier \"" . $supplier['supplier_name'] . "\" with id $supplier_id");

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => ($hasOrders || $hasPayments)
            ? 'Supplier marked as deleted. Their purchase orders and payment history are preserved and still trackable.'
            : 'Supplier marked as deleted.'
    ]);

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}