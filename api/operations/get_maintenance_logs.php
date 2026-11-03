<?php
// api/operations/get_maintenance_logs.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../roots.php';
require_once __DIR__ . '/../../core/dt_order.php';

global $pdo;

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}
if (!canView('assets')) {   // same key app/bms/operations/maintenance.php enforces
    http_response_code(403);
    echo json_encode(["error" => "Permission denied"]);
    exit;
}

// Header sorting; S/No = the normal newest-first order (core/dt_order.php).
$order_sql = dtOrderSql($_GET, [
    'asset_name'       => 'a.asset_name',
    'maintenance_date' => 'm.maintenance_date',
    'maintenance_type' => 'm.maintenance_type',
    'performed_by'     => 'm.performed_by',
    'cost'             => 'm.cost',
    'status'           => 'm.status',
], 'm.maintenance_date DESC, m.log_id DESC', 'm.maintenance_date ASC, m.log_id ASC', 'm.log_id');

try {
    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
    $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $length = dt_page_length($_GET['length'] ?? null, 10);
    
    $status = $_GET['status'] ?? '';
    $search_term = $_GET['search_term'] ?? '';
    
    $where = ["m.status != 'deleted'"];
    $params = [];
    
    if ($status) {
        $where[] = "m.status = ?";
        $params[] = $status;
    }
    
    if ($search_term) {
        $where[] = "(a.asset_name LIKE ? OR a.asset_code LIKE ? OR m.description LIKE ? OR m.performed_by LIKE ?)";
        $s = "%$search_term%";
        $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    }
    
    $where_clause = implode(" AND ", $where);
    
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM maintenance_logs WHERE status != 'deleted'");
    $total_records = $total_stmt->fetchColumn();
    
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_logs m JOIN assets a ON m.asset_id = a.asset_id WHERE $where_clause");
    $count_stmt->execute($params);
    $filtered_records = $count_stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT m.*, a.asset_name, a.asset_code 
                           FROM maintenance_logs m 
                           JOIN assets a ON m.asset_id = a.asset_id 
                           WHERE $where_clause 
                           ORDER BY $order_sql 
                           LIMIT $start, $length");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Stats Summary
    $stats_stmt = $pdo->query("SELECT
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as progress,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid,
        SUM(CASE WHEN status != 'deleted' THEN cost ELSE 0 END) as total_cost
        FROM maintenance_logs");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $filtered_records,
        "data" => $data,
        "stats" => $stats
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
