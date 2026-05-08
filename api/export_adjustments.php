<?php
/**
 * API: Export Stock Adjustments
 * Generates a CSV file of stock adjustments for Excel/Download.
 */
require_once __DIR__ . '/../roots.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized access");
}

try {
    // Get filter parameters
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
    $warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
    $adjustment_type = isset($_GET['adjustment_type']) ? $_GET['adjustment_type'] : '';
    $reason = isset($_GET['reason']) ? $_GET['reason'] : '';
    $user_id_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

    // Prepare headers for download
    $filename = "stock_adjustments_export_" . date('Y-m-d_H-i-s') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');

    // CSV Headers
    fputcsv($output, ['Date', 'Reference #', 'SKU', 'Product Name', 'Warehouse', 'Location', 'Type', 'Quantity', 'Unit', 'Unit Cost', 'Total Value', 'Reason', 'Adjusted By', 'Notes']);

    // Build query
    $query = "
        SELECT 
            sm.created_at,
            sm.reference_number,
            p.sku,
            p.product_name,
            w.warehouse_name,
            loc.location_name,
            sm.movement_type,
            sm.quantity,
            sm.unit,
            sm.unit_cost,
            (sm.quantity * sm.unit_cost) as total_value,
            sm.reason,
            u.username as adjusted_by_name,
            sm.notes
        FROM stock_movements sm
        LEFT JOIN products p ON sm.product_id = p.product_id
        LEFT JOIN users u ON sm.created_by = u.user_id
        LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
        LEFT JOIN locations loc ON sm.location_id = loc.location_id
        WHERE sm.movement_type IN ('adjustment_in', 'adjustment_out', 'correction', 'damaged', 'expired', 'found', 'theft', 'adjustment', 'stock_adjustment')
    ";

    $params = [];
    $conditions = [];

    // Apply filters
    if (!empty($search)) {
        $conditions[] = "(p.product_name LIKE :search1 OR p.sku LIKE :search2 OR p.barcode LIKE :search3 OR sm.reference_number LIKE :search4 OR sm.notes LIKE :search5)";
        $params[':search1'] = "%$search%";
        $params[':search2'] = "%$search%";
        $params[':search3'] = "%$search%";
        $params[':search4'] = "%$search%";
        $params[':search5'] = "%$search%";
    }

    if ($product_id > 0) {
        $conditions[] = "sm.product_id = :product_id";
        $params[':product_id'] = $product_id;
    }

    if ($warehouse_id > 0) {
        $conditions[] = "sm.warehouse_id = :warehouse_id";
        $params[':warehouse_id'] = $warehouse_id;
    }

    if (!empty($adjustment_type)) {
        $conditions[] = "sm.movement_type = :adjustment_type";
        $params[':adjustment_type'] = $adjustment_type;
    }

    if (!empty($reason)) {
        $conditions[] = "sm.reason = :reason";
        $params[':reason'] = $reason;
    }

    if ($user_id_filter > 0) {
        $conditions[] = "sm.created_by = :user_id";
        $params[':user_id'] = $user_id_filter;
    }

    if (!empty($date_from)) {
        $conditions[] = "DATE(sm.created_at) >= :date_from";
        $params[':date_from'] = $date_from;
    }

    if (!empty($date_to)) {
        $conditions[] = "DATE(sm.created_at) <= :date_to";
        $params[':date_to'] = $date_to;
    }

    if (!empty($conditions)) {
        $query .= " AND " . implode(" AND ", $conditions);
    }

    $query .= " ORDER BY sm.created_at DESC";

    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit();

} catch (PDOException $e) {
    die("Export Failed: " . $e->getMessage());
}
