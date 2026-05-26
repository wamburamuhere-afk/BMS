<?php
// scope-audit: skip — PO vs supplier-invoice reconciliation report; read-only; scope filter pending Phase G-2
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
    WHERE " . implode(' AND ', $where) . "
    ORDER BY po.order_date DESC, po.purchase_order_id DESC
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Status filter applied in PHP (so the same logic matches the UI)
    if ($status !== '') {
        $rows = array_values(array_filter($rows, function ($r) use ($status) {
            $total = (float)$r['grand_total'];
            $inv   = (float)$r['invoiced_total'];
            $key   = ($inv > $total) ? 'over'
                   : (($inv === $total && $total > 0) ? 'fully'
                   : (($inv > 0) ? 'partial' : 'open'));
            return $key === $status;
        }));
    }

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    error_log('po_invoice_report: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
