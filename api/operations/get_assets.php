<?php
// api/operations/get_assets.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../roots.php';

global $pdo;

try {
    // DataTables parameters
    $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
    $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
    $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;
    
    $category = $_GET['category'] ?? '';
    $status = $_GET['status'] ?? '';
    $search_term = $_GET['search_term'] ?? '';
    
    // Base WHERE clause
    $where = ["1=1"];
    $params = [];
    
    if ($category) {
        $where[] = "category = ?";
        $params[] = $category;
    }
    
    if ($status) {
        $where[] = "status = ?";
        $params[] = $status;
    }
    
    if ($search_term) {
        $where[] = "(asset_name LIKE ? OR asset_code LIKE ? OR location LIKE ?)";
        $params[] = "%$search_term%";
        $params[] = "%$search_term%";
        $params[] = "%$search_term%";
    }
    
    $where_clause = implode(" AND ", $where);
    
    // Count total records
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM assets");
    $total_records = $total_stmt->fetchColumn();
    
    // Count filtered records
    $filtered_stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE $where_clause");
    $filtered_stmt->execute($params);
    $filtered_records = $filtered_stmt->fetchColumn();
    
    // Fetch data
    $data_stmt = $pdo->prepare("SELECT * FROM assets WHERE $where_clause ORDER BY created_at DESC LIMIT $start, $length");
    $data_stmt->execute($params);
    $assets = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch stats
    $stats_stmt = $pdo->query("SELECT 
        COUNT(*) as total_count, 
        SUM(cost) as total_cost,
        COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance_count,
        COUNT(DISTINCT category) as categories_count
        FROM assets");
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch unique categories for filter
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM assets WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => $total_records,
        "recordsFiltered" => $filtered_records,
        "data" => $assets,
        "stats" => [
            "total_count" => $stats['total_count'] ?: 0,
            "total_cost" => $stats['total_cost'] ?: 0,
            "maintenance_count" => $stats['maintenance_count'] ?: 0,
            "categories_count" => $stats['categories_count'] ?: 0
        ],
        "categories" => $categories
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
