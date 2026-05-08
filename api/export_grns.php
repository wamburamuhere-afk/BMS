<?php
/**
 * API: Export Goods Received Notes (GRN)
 * Generates a CSV file of GRNs for Excel/Download.
 */
require_once __DIR__ . '/../roots.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

try {
    // Get filter parameters
    $status_filter = $_GET['status'] ?? '';
    $supplier_filter = isset($_GET['supplier']) ? intval($_GET['supplier']) : 0;
    $warehouse_filter = isset($_GET['warehouse']) ? intval($_GET['warehouse']) : 0;
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $po_filter = isset($_GET['po']) ? intval($_GET['po']) : 0;

    // Prepare headers for download
    $filename = "grn_export_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, ['DN Number', 'GRN Number', 'Date', 'Supplier', 'PO Number', 'Warehouse', 'Items Count', 'Total Value', 'Received By', 'Status', 'Notes']);

    // Build query with filters
    $query = "
        SELECT 
            pr.delivery_note,
            pr.receipt_number,
            pr.receipt_date,
            s.supplier_name,
            po.order_number,
            w.warehouse_name,
            COUNT(ri.receipt_item_id) as total_items,
            SUM(ri.quantity_received * ri.unit_price) as total_value,
            u.username as received_by_name,
            pr.status,
            pr.notes
        FROM purchase_receipts pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        LEFT JOIN warehouses w ON pr.warehouse_id = w.warehouse_id
        LEFT JOIN receipt_items ri ON pr.receipt_id = ri.receipt_id
        LEFT JOIN users u ON pr.received_by = u.user_id
        WHERE 1=1
    ";

    $params = [];

    if (!empty($status_filter)) {
        $query .= " AND pr.status = ?";
        $params[] = $status_filter;
    }
    if ($supplier_filter > 0) {
        $query .= " AND pr.supplier_id = ?";
        $params[] = $supplier_filter;
    }
    if ($warehouse_filter > 0) {
        $query .= " AND pr.warehouse_id = ?";
        $params[] = $warehouse_filter;
    }
    if ($po_filter > 0) {
        $query .= " AND pr.purchase_order_id = ?";
        $params[] = $po_filter;
    }
    if (!empty($date_from)) {
        $query .= " AND pr.receipt_date >= ?";
        $params[] = $date_from;
    }
    if (!empty($date_to)) {
        $query .= " AND pr.receipt_date <= ?";
        $params[] = $date_to;
    }

    $query .= " GROUP BY pr.receipt_id ORDER BY pr.receipt_date DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    // Log Audit
    logAudit($pdo, $_SESSION['user_id'], "export", [
        'activity_type' => 'export',
        'entity_type' => 'grn',
        'description' => "Exported Goods Received Notes (GRN) to Excel"
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();

} catch (Exception $e) {
    die("Export Failed: " . $e->getMessage());
}
