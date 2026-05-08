<?php
include 'includes/config.php';
$per_page = 25;
$offset = 0;
$warehouse_id = 5; // Main Warehouse
$adjustment_type = '';
$search = '';
$product_id = 0;
$user_id_filter = 0;
$date_from = '';
$date_to = '';
$reason = '';

$query = "
    SELECT 
        sm.*,
        p.product_id,
        p.product_name,
        p.sku,
        p.barcode,
        u.username as adjusted_by_name,
        w.warehouse_name,
        loc.location_name,
        sm.quantity * sm.unit_cost as total_value,
        sm.stock_after - sm.stock_before as stock_change
    FROM stock_movements sm
    LEFT JOIN products p ON sm.product_id = p.product_id
    LEFT JOIN users u ON sm.created_by = u.user_id
    LEFT JOIN warehouses w ON sm.warehouse_id = w.warehouse_id
    LEFT JOIN locations loc ON sm.location_id = loc.location_id
    WHERE sm.movement_type IN ('adjustment_in', 'adjustment_out', 'correction', 'damaged', 'expired', 'found', 'theft')
";

$params = [];
$conditions = [];

if ($warehouse_id > 0) {
    $conditions[] = "sm.warehouse_id = :warehouse_id";
    $params[':warehouse_id'] = $warehouse_id;
}

if (!empty($conditions)) {
    $query .= " AND " . implode(" AND ", $conditions);
}

$paged_query = $query . " ORDER BY sm.created_at DESC LIMIT :limit OFFSET :offset";

try {
    $stmt = $pdo->prepare($paged_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Results found: " . count($adjustments) . "\n";
    
    $stats_query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN sm.movement_type IN ('adjustment_in', 'found') THEN sm.quantity ELSE 0 END) as qty_in,
            SUM(CASE WHEN sm.movement_type NOT IN ('adjustment_in', 'found') THEN sm.quantity ELSE 0 END) as qty_out,
            SUM(CASE WHEN sm.movement_type IN ('adjustment_in', 'found') THEN sm.quantity * sm.unit_cost ELSE -(sm.quantity * sm.unit_cost) END) as net_value
        FROM stock_movements sm 
        LEFT JOIN products p ON sm.product_id = p.product_id 
        WHERE sm.movement_type IN ('adjustment_in', 'adjustment_out', 'correction', 'damaged', 'expired', 'found', 'theft')
    ";
    
    if (!empty($conditions)) {
        $stats_query .= " AND " . implode(" AND ", $conditions);
    }
    
    $stats_stmt = $pdo->prepare($stats_query);
    foreach ($params as $key => $value) {
        $stats_stmt->bindValue($key, $value);
    }
    $stats_stmt->execute();
    $stats_data = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    echo "Total stats count: " . $stats_data['total'] . "\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
