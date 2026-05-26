<?php
require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/permissions.php';

// Check permissions
if (!canView('purchase_orders')) {
    header("HTTP/1.1 403 Forbidden");
    echo "Access Denied: You do not have permission to export purchase orders.";
    exit();
}

// Simplistic Excel Export
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="purchase_orders_export_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

try {
    global $pdo;

    // Get filter parameters (same as get_purchase_orders)
    $supplier_id = $_GET['supplier'] ?? '';
    $status = $_GET['status'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';

    $query = "
        SELECT po.order_number, s.supplier_name, po.order_date, po.total_amount, po.currency, po.status, u1.username as created_by
        FROM purchase_orders po
        LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
        LEFT JOIN users u1 ON po.created_by = u1.user_id
        WHERE 1=1
    ";

    $params = [];
    if (!empty($supplier_id)) { $query .= " AND po.supplier_id = ?"; $params[] = $supplier_id; }
    if (!empty($status)) { $query .= " AND po.status = ?"; $params[] = $status; }
    if (!empty($date_from)) { $query .= " AND po.order_date >= ?"; $params[] = $date_from; }
    if (!empty($date_to)) { $query .= " AND po.order_date <= ?"; $params[] = $date_to; }
    $query .= scopeFilterSqlNullable('project', 'po');
    $query .= " ORDER BY po.order_date DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1'>";
    echo "<tr><th>Order #</th><th>Supplier</th><th>Date</th><th>Amount</th><th>Currency</th><th>Status</th><th>Created By</th></tr>";
    
    foreach ($orders as $row) {
        echo "<tr>";
        echo "<td>{$row['order_number']}</td>";
        echo "<td>{$row['supplier_name']}</td>";
        echo "<td>{$row['order_date']}</td>";
        echo "<td>{$row['total_amount']}</td>";
        echo "<td>{$row['currency']}</td>";
        echo "<td>{$row['status']}</td>";
        echo "<td>{$row['created_by']}</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "Error exporting data: " . $e->getMessage();
}
