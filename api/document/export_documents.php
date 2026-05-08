<?php
/**
 * Export Document Library Records to CSV
 */
require_once __DIR__ . '/../../roots.php';

try {
    // Get parameters
    $category_id = $_GET['category_id'] ?? '';
    $file_type = $_GET['file_type'] ?? '';
    $access_level = $_GET['access_level'] ?? '';
    $search = $_GET['search'] ?? '';

    // Base query
    $query = "SELECT d.*, 
                     u.username as uploaded_by_name,
                     c.category_name
              FROM documents d
              LEFT JOIN users u ON d.uploaded_by = u.user_id
              LEFT JOIN document_categories c ON d.category_id = c.id
              WHERE 1=1";
    
    $params = [];
    
    if (!empty($category_id)) {
        $query .= " AND d.category_id = ?";
        $params[] = $category_id;
    }
    
    if (!empty($file_type)) {
        $query .= " AND d.file_type = ?";
        $params[] = $file_type;
    }
    
    if (!empty($access_level)) {
        $query .= " AND d.access_level = ?";
        $params[] = $access_level;
    }

    if (!empty($search)) {
        $query .= " AND (d.document_name LIKE ? OR d.description LIKE ? OR c.category_name LIKE ?)";
        $s = "%$search%";
        $params[] = $s; $params[] = $s; $params[] = $s;
    }
    
    $query .= " ORDER BY d.uploaded_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    $filename = 'Document_Library_' . date('Y-m-d_His') . '.csv';
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
        'Document Name',
        'Category',
        'Description',
        'File Type',
        'File Size (Bytes)',
        'Downloads',
        'Access Level',
        'Uploaded By',
        'Uploaded At'
    ]);
    
    // CSV Data
    foreach ($records as $record) {
        fputcsv($output, [
            $record['id'],
            $record['document_name'],
            $record['category_name'] ?? 'General',
            $record['description'] ?? '',
            $record['file_type'],
            $record['file_size'],
            $record['download_count'],
            ucfirst($record['access_level']),
            $record['uploaded_by_name'] ?? 'System',
            $record['uploaded_at']
        ]);
    }
    
    fclose($output);
    exit();
    
} catch (Exception $e) {
    http_response_code(500);
    echo "Error exporting document library: " . $e->getMessage();
    exit();
}
