<?php
/**
 * API: Export Purchase Returns
 * Generates a CSV file of Purchase Returns for Excel/Download.
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
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

    // Prepare headers for download
    $filename = "purchase_returns_export_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, ['Return Number', 'Date', 'Supplier', 'PO Number', 'Items Count', 'Total Value', 'Reason', 'Status', 'Notes']);

    // Build query with filters
    $query = "
        SELECT 
            pr.return_number,
            pr.return_date,
            s.supplier_name,
            po.order_number,
            (SELECT COUNT(*) FROM purchase_return_items WHERE purchase_return_id = pr.purchase_return_id) as total_items,
            pr.total_amount,
            pr.reason,
            pr.status,
            pr.notes
        FROM purchase_returns pr
        LEFT JOIN suppliers s ON pr.supplier_id = s.supplier_id
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.purchase_order_id
        WHERE 1=1
    ";

    $params = [];

    // Apply filters
    if (!empty($status_filter)) {
        $query .= " AND pr.status = ?";
        $params[] = $status_filter;
    }

    if ($supplier_filter > 0) {
        $query .= " AND pr.supplier_id = ?";
        $params[] = $supplier_filter;
    }

    if (!empty($date_from)) {
        $query .= " AND pr.return_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $query .= " AND pr.return_date <= ?";
        $params[] = $date_to;
    }

    $query .= " ORDER BY pr.return_date DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    // Log Audit
    logAudit($pdo, $_SESSION['user_id'], "export", [
        'activity_type' => 'export',
        'entity_type' => 'purchase_return',
        'description' => "Exported Purchase Returns list to Excel"
    ]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();

} catch (Exception $e) {
    die("Export Failed: " . $e->getMessage());
}
?>
