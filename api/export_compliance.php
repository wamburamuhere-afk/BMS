<?php
/**
 * Export Compliance Records to Excel
 */
require_once __DIR__ . '/../roots.php';

try {
    // Get filters
    $category = $_GET['category'] ?? '';
    $status = $_GET['status'] ?? '';
    
    $query = "SELECT * FROM compliance_records WHERE 1=1";
    $params = [];
    
    if (!empty($category)) {
        $query .= " AND category = ?";
        $params[] = $category;
    }
    
    // Status filtering
    $today = date('Y-m-d');
    $warningDate = date('Y-m-d', strtotime('+30 days'));
    
    if ($status === 'Expired') {
        $query .= " AND expiry_date < ? AND expiry_date IS NOT NULL";
        $params[] = $today;
    } elseif ($status === 'Expiring Soon') {
        $query .= " AND expiry_date BETWEEN ? AND ? AND expiry_date IS NOT NULL";
        $params[] = $today;
        $params[] = $warningDate;
    } elseif ($status === 'Valid') {
        $query .= " AND (expiry_date >= ? OR expiry_date IS NULL)";
        $params[] = $today;
    }
    
    $query .= " ORDER BY updated_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate status for each record
    foreach ($records as &$row) {
        if (!$row['expiry_date']) {
            $row['status'] = 'Valid';
        } elseif ($row['expiry_date'] < $today) {
            $row['status'] = 'Expired';
        } elseif ($row['expiry_date'] <= $warningDate) {
            $row['status'] = 'Expiring Soon';
        } else {
            $row['status'] = 'Valid';
        }
    }
    
    // Set headers for Excel download
    $filename = 'Compliance_Records_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV Headers
    fputcsv($output, [
        'ID',
        'Document Title',
        'Category',
        'Reference Number',
        'Expiry Date',
        'Status',
        'Notes',
        'Created By',
        'Created At',
        'Updated At'
    ]);
    
    // CSV Data
    foreach ($records as $record) {
        fputcsv($output, [
            $record['id'],
            $record['title'],
            $record['category'],
            $record['ref_no'] ?? 'N/A',
            $record['expiry_date'] ?? 'No Expiry',
            $record['status'],
            $record['notes'] ?? '',
            $record['created_by'] ?? 'System',
            $record['created_at'],
            $record['updated_at']
        ]);
    }
    
    fclose($output);
    exit();
    
} catch (Exception $e) {
    http_response_code(500);
    echo "Error exporting compliance records: " . $e->getMessage();
    exit();
}
?>
