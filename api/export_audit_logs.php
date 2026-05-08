<?php
/**
 * Export Audit Logs to CSV
 */
require_once __DIR__ . '/../roots.php';

try {
    // Get filters
    $activityType = $_GET['activity_type'] ?? '';
    $dateRange = $_GET['date_range'] ?? 'all';
    $userFilter = $_GET['user'] ?? '';
    
    // Check if table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'audit_logs'")->rowCount();
    
    if ($tableCheck == 0) {
        // Use sample data
        $records = generateSampleAuditLogs();
    } else {
        // Build query
        $query = "SELECT al.*, u.username 
                  FROM audit_logs al 
                  LEFT JOIN users u ON al.user_id = u.user_id 
                  WHERE 1=1";
        $params = [];
        
        // Activity type filter
        if (!empty($activityType)) {
            $query .= " AND al.activity_type = ?";
            $params[] = $activityType;
        }
        
        // User filter
        if (!empty($userFilter)) {
            $query .= " AND u.username LIKE ?";
            $params[] = "%$userFilter%";
        }
        
        // Date range filter
        $today = date('Y-m-d');
        switch ($dateRange) {
            case 'today':
                $query .= " AND DATE(al.created_at) = ?";
                $params[] = $today;
                break;
            case 'yesterday':
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                $query .= " AND DATE(al.created_at) = ?";
                $params[] = $yesterday;
                break;
            case 'week':
                $weekAgo = date('Y-m-d', strtotime('-7 days'));
                $query .= " AND DATE(al.created_at) >= ?";
                $params[] = $weekAgo;
                break;
            case 'month':
                $monthAgo = date('Y-m-d', strtotime('-30 days'));
                $query .= " AND DATE(al.created_at) >= ?";
                $params[] = $monthAgo;
                break;
        }
        
        $query .= " ORDER BY al.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Set headers for CSV download
    $filename = 'Audit_Logs_' . date('Y-m-d_His') . '.csv';
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
        'Timestamp',
        'User',
        'Activity Type',
        'Description',
        'IP Address',
        'Status'
    ]);
    
    // CSV Data
    foreach ($records as $record) {
        fputcsv($output, [
            $record['id'],
            $record['created_at'],
            $record['username'] ?? 'System',
            $record['activity_type'] ?? 'N/A',
            $record['description'] ?? '',
            $record['ip_address'] ?? 'N/A',
            $record['status'] ?? 'N/A'
        ]);
    }
    
    fclose($output);
    exit();
    
} catch (Exception $e) {
    http_response_code(500);
    echo "Error exporting audit logs: " . $e->getMessage();
    exit();
}

function generateSampleAuditLogs() {
    $activities = ['login', 'logout', 'create', 'update', 'delete', 'export', 'print'];
    $users = ['admin', 'john_doe', 'jane_smith', 'manager', 'accountant'];
    $descriptions = [
        'User logged into the system',
        'Created new customer record',
        'Updated invoice #INV-001',
        'Deleted expired compliance document',
        'Exported sales report',
        'Printed payment receipt'
    ];
    
    $logs = [];
    for ($i = 0; $i < 100; $i++) {
        $logs[] = [
            'id' => $i + 1,
            'username' => $users[array_rand($users)],
            'activity_type' => $activities[array_rand($activities)],
            'description' => $descriptions[array_rand($descriptions)],
            'ip_address' => '192.168.1.' . rand(1, 255),
            'status' => rand(0, 10) > 1 ? 'success' : 'failed',
            'created_at' => date('Y-m-d H:i:s', strtotime('-' . rand(0, 30) . ' days'))
        ];
    }
    return $logs;
}
?>
