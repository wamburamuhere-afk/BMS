<?php
// File: api/get_customer_dn_sources.php
// Lists the Sales Orders and Customer LPOs eligible to be referenced (both
// optional) when creating an outbound Delivery Note for a given customer —
// feeds dn_outbound.php's two in-form "Sales Order (Optional)" / "Customer
// LPO (Optional)" dropdowns. Same eligibility rules as the existing
// ?order=/?lpo_id= URL-arrival paths in dn_outbound.php itself.
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$customer_id = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;
if ($customer_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer']);
    exit;
}

try {
    global $pdo;

    // Found 2026-07-18: this picker had no project/warehouse scoping at all —
    // same class of bug as the GRN PO-picker fix, just on the sales side.
    $soStmt = $pdo->prepare("
        SELECT sales_order_id, order_number, order_date
        FROM sales_orders
        WHERE customer_id = ? AND status IN ('approved', 'processing', 'shipped')
        " . scopeFilterSqlNullable('project') . scopeFilterSqlNullable('warehouse') . "
        ORDER BY order_date DESC
    ");
    $soStmt->execute([$customer_id]);
    $sales_orders = $soStmt->fetchAll(PDO::FETCH_ASSOC);

    $lpoStmt = $pdo->prepare("
        SELECT lpo_id, lpo_number, issue_date
        FROM customer_lpos
        WHERE customer_id = ? AND status IN ('approved', 'partially_fulfilled')
        " . scopeFilterSqlNullable('project') . scopeFilterSqlNullable('warehouse') . "
        ORDER BY issue_date DESC
    ");
    $lpoStmt->execute([$customer_id]);
    $lpos = $lpoStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => ['sales_orders' => $sales_orders, 'lpos' => $lpos]]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
