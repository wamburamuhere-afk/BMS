<?php
// api/operations/get_maintenance_logs.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

try {
    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
    $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;
    
    $status = $_GET['status'] ?? '';
    $search_term = $_GET['search_term'] ?? '';
    
    $where = ["1=1"];
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
    
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM maintenance_logs");
    $total_records = $total_stmt->fetchColumn();
    
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_logs m JOIN assets a ON m.asset_id = a.asset_id WHERE $where_clause");
    $count_stmt->execute($params);
    $filtered_records = $count_stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT m.*, a.asset_name, a.asset_code 
                           FROM maintenance_logs m 
                           JOIN assets a ON m.asset_id = a.asset_id 
                           WHERE $where_clause 
                           ORDER BY m.maintenance_date DESC 
                           LIMIT $start, $length");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Stats Summary
    $stats_stmt = $pdo->query("SELECT 
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as progress,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
        SUM(cost) as total_cost
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
