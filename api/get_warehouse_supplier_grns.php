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
    $supplier_id = isset($_GET['supplier_id']) ? intval($_GET['supplier_id']) : 0;

    if ($warehouse_id <= 0 || $supplier_id <= 0) {
        throw new Exception("Missing parameters");
    }
    // Found 2026-07-18: normal UI flow always pre-scopes warehouse_id via
    // warehousesForSelect(), but nothing stopped a crafted request supplying
    // any warehouse_id from returning that warehouse's GRNs regardless.
    if (!userCan('warehouse', $warehouse_id)) {
        http_response_code(403);
        throw new Exception('Access denied: this warehouse is not in your scope');
    }

    // Found 2026-07-18: status='completed' is a legacy status from before the
    // GRN workflow moved to pending -> reviewed -> approved; 'approved' is
    // now the terminal, stock-posted status (approve_grn.php performs the
    // same stock-posting side effect 'completed' used to represent) — no
    // current code path ever sets a GRN to 'completed' anymore.
    $stmt = $pdo->prepare("
        SELECT receipt_id, receipt_number, receipt_date
        FROM purchase_receipts
        WHERE warehouse_id = ? AND supplier_id = ? AND status IN ('approved', 'completed')
        ORDER BY receipt_date DESC
    ");
    $stmt->execute([$warehouse_id, $supplier_id]);
    $grns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $grns]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
