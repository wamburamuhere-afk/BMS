<?php
require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/project_scope.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;
    $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;

    if ($warehouse_id <= 0) {
        throw new Exception("Warehouse not specified");
    }
    // Found 2026-07-18: same gap as get_warehouse_supplier_grns.php (already
    // fixed today) — normal UI pre-scopes warehouse_id, but nothing checked
    // it server-side.
    if (!userCan('warehouse', $warehouse_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this warehouse is not in your scope');
    }

    // Found 2026-07-18: status='completed' is a legacy status from before the
    // GRN workflow moved to pending -> reviewed -> approved; 'approved' is
    // now the terminal, stock-posted status (approve_grn.php performs the
    // same stock-posting side effect 'completed' used to represent) — no
    // current code path ever sets a GRN to 'completed' anymore, so this
    // picker could never show a supplier for any GRN made under the current
    // workflow, for any user, admin included.
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.supplier_id, s.supplier_name
        FROM suppliers s
        INNER JOIN purchase_receipts pr ON s.supplier_id = pr.supplier_id
        WHERE pr.warehouse_id = ? AND pr.status IN ('approved', 'completed') AND s.status = 'active'
        ORDER BY s.supplier_name ASC
    ");
    $stmt->execute([$warehouse_id]);
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $suppliers]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
