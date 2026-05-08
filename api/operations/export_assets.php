<?php
// api/operations/export_assets.php
require_once __DIR__ . '/../../roots.php';

global $pdo;

$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';

try {
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
    
    $where_clause = implode(" AND ", $where);
    
    $stmt = $pdo->prepare("SELECT asset_code, asset_name, category, location, purchase_date, cost, status, description FROM assets WHERE $where_clause ORDER BY created_at DESC");
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $filename = "assets_export_" . date('Ymd_His') . ".csv";
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, ['Asset Code', 'Asset Name', 'Category', 'Location', 'Purchase Date', 'Cost', 'Status', 'Description']);
    
    foreach ($assets as $asset) {
        fputcsv($output, $asset);
    }
    
    fclose($output);
    exit;

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
