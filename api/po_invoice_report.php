<?php
// PO vs supplier-invoice reconciliation report — read-only.
// Scope: project-aware via scopeFilterSqlNullable('project','po').
header('Content-Type: application/json');
require_once __DIR__ . '/../roots.php';
global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!canView('received_invoices')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

$supplier_id = intval($_GET['supplier_id'] ?? 0);
$status      = trim($_GET['status'] ?? '');
$from        = trim($_GET['from']   ?? '');
$to          = trim($_GET['to']     ?? '');

$where  = ["po.status NOT IN ('cancelled')"];
$params = [];

if ($supplier_id) { $where[] = 'po.supplier_id = ?'; $params[] = $supplier_id; }
if ($from)        { $where[] = 'po.order_date >= ?'; $params[] = $from; }
if ($to)          { $where[] = 'po.order_date <= ?'; $params[] = $to;   }

// Status filter at SQL level via HAVING (uses ≤1 TZS tolerance for "fully").
$having = '';
if ($status === 'over') {
    $having = ' HAVING (invoiced_total - po.grand_total) > 1';
} elseif ($status === 'fully') {
    $having = ' HAVING ABS(invoiced_total - po.grand_total) <= 1 AND po.grand_total > 0';
} elseif ($status === 'partial') {
    $having = ' HAVING invoiced_total > 0 AND (invoiced_total - po.grand_total) <= 1 AND ABS(invoiced_total - po.grand_total) > 1';
} elseif ($status === 'open') {
    $having = ' HAVING invoiced_total = 0';
}

$sql = "
    SELECT
        po.purchase_order_id,
        po.order_number,
        po.order_date,
        po.grand_total,
        s.supplier_name,
        COALESCE(inv.invoiced_total, 0)  AS invoiced_total,
        COALESCE(inv.invoice_count, 0)   AS invoice_count,
        (po.grand_total - COALESCE(inv.invoiced_total, 0)) AS remaining
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
    LEFT JOIN (
        SELECT po_id,
               SUM(amount) AS invoiced_total,
               COUNT(*)    AS invoice_count
        FROM supplier_invoices
        WHERE status != 'deleted' AND po_id IS NOT NULL
        GROUP BY po_id
    ) inv ON inv.po_id = po.purchase_order_id
    WHERE " . implode(' AND ', $where);

// Project-scope filter (Phase G-2)
if (function_exists('scopeFilterSqlNullable')) {
    $sql .= ' ' . scopeFilterSqlNullable('project', 'po');
}

$sql .= $having . " ORDER BY po.order_date DESC, po.purchase_order_id DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    error_log('po_invoice_report: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
