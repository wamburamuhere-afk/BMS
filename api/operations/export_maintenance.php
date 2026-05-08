<?php
// api/operations/export_maintenance.php
require_once __DIR__ . '/../../roots.php';

global $pdo;

$status = $_GET['status'] ?? '';
$search = $_GET['search_term'] ?? '';

try {
    $where = ["1=1"];
    $params = [];
    
    if ($status) {
        $where[] = "m.status = ?";
        $params[] = $status;
    }
    
    if ($search) {
        $where[] = "(a.asset_name LIKE ? OR a.asset_code LIKE ? OR m.description LIKE ?)";
        $s = "%$search%";
        $params[] = $s; $params[] = $s; $params[] = $s;
    }
    
    $where_clause = implode(" AND ", $where);
    
    $stmt = $pdo->prepare("SELECT a.asset_name, a.asset_code, m.maintenance_date, m.maintenance_type, m.performed_by, m.cost, m.status, m.description 
                           FROM maintenance_logs m 
                           JOIN assets a ON m.asset_id = a.asset_id 
                           WHERE $where_clause ORDER BY m.maintenance_date DESC");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = "maintenance_export_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Asset Name', 'Asset Code', 'Date', 'Type', 'Performed By', 'Cost', 'Status', 'Description']);
    
    foreach ($logs as $log) {
        fputcsv($output, $log);
    }
    fclose($output);
    exit;
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
