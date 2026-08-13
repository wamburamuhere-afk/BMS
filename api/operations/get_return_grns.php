<?php
// File: api/operations/get_return_grns.php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/project_scope.php';
global $pdo;

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$warehouse_id = intval($_GET['warehouse_id'] ?? 0);
$supplier_id = intval($_GET['supplier_id'] ?? 0);
$project_id = intval($_GET['project_id'] ?? 0);

if (!$warehouse_id || !$supplier_id) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}
if ($project_id > 0 && !userCan('project', $project_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this project is not in your scope']);
    exit();
}
// Found 2026-07-18: same gap as get_warehouse_supplier_grns.php — normal UI
// flow pre-scopes warehouse_id, but nothing checked it server-side.
if (!userCan('warehouse', $warehouse_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied: this warehouse is not in your scope']);
    exit();
}

try {
    // Select GRNs for this warehouse and supplier. When called from a project
    // screen, scope to that project's receipts (+ untagged) using the same rule
    // as get_return_suppliers.php — otherwise the supplier dropdown and the GRN
    // dropdown disagree, and a return raised for one project could be booked
    // against another project's GRN.
    $sql = "
        SELECT receipt_id, receipt_number, receipt_date
        FROM purchase_receipts
        WHERE warehouse_id = ? AND supplier_id = ? AND status != 'cancelled'
    ";
    $params = [$warehouse_id, $supplier_id];
    if ($project_id > 0) {
        $sql .= " AND (project_id = ? OR project_id IS NULL)";
        $params[] = $project_id;
    }
    $sql .= " ORDER BY receipt_date DESC, receipt_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $grns]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
