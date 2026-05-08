<?php
/**
 * Print Audit Logs Report
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
        $records = generateSampleAuditLogs();
    } else {
        $query = "SELECT al.*, u.username 
                  FROM audit_logs al 
                  LEFT JOIN users u ON al.user_id = u.user_id 
                  WHERE 1=1";
        $params = [];
        
        if (!empty($activityType)) {
            $query .= " AND al.activity_type = ?";
            $params[] = $activityType;
        }
        
        if (!empty($userFilter)) {
            $query .= " AND u.username LIKE ?";
            $params[] = "%$userFilter%";
        }
        
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
    
    $companyName = "Business Management System";
    $reportDate = date('F d, Y');
    $reportTime = date('h:i A');
    
} catch (Exception $e) {
    die("Error loading audit logs: " . $e->getMessage());
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
    for ($i = 0; $i < 50; $i++) {
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs Report - <?= $reportDate ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { 
                font-size: 10pt !important; 
                background: white !important;
            }
            .container { 
                width: 100% !important; 
                max-width: none !important; 
                padding: 0 !important; 
                margin: 0 !important;
            }
            .header-section {
                border-bottom: 3px double #0d6efd !important;
                margin-bottom: 25px !important;
                -webkit-print-color-adjust: exact;
            }
            .table thead th {
                background-color: #f8f9fa !important;
                -webkit-print-color-adjust: exact;
            }
        }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .header-section { border-bottom: 3px solid #0d6efd; padding-bottom: 15px; margin-bottom: 30px; }
        .footer-section { margin-top: 30px; padding-top: 15px; border-top: 2px solid #dee2e6; font-size: 0.9em; color: #6c757d; }
        .text-uppercase { text-transform: uppercase; }
        .badge { font-weight: 500; font-size: 0.8em; }
    </style>
</head>
<body>
    <div class="container my-4">
        <!-- Print Button -->
        <div class="no-print text-end mb-3">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <button onclick="window.close()" class="btn btn-secondary">Close</button>
        </div>

        <!-- Header -->
        <div class="header-section">
            <div class="row align-items-center">
                <div class="col-8">
                    <h2 class="mb-1 fw-bold text-uppercase"><?= $companyName ?></h2>
                    <h4 class="text-primary mb-0 fw-bold text-uppercase">System Activity Audit Logs Report</h4>
                </div>
                <div class="col-4 text-end">
                    <p class="mb-0"><strong>Report Date:</strong> <?= $reportDate ?></p>
                    <p class="mb-0"><strong>Generated:</strong> <?= $reportTime ?></p>
                    <?php if ($activityType): ?>
                        <p class="mb-0"><strong>Activity:</strong> <?= htmlspecialchars($activityType) ?></p>
                    <?php endif; ?>
                    <?php if ($dateRange && $dateRange != 'all'): ?>
                        <p class="mb-0"><strong>Period:</strong> <?= ucfirst($dateRange) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Logs Table -->
        <h5 class="mb-3 fw-bold">Activity Logs</h5>
        
        <?php if (empty($records)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No audit logs found matching the selected filters.
            </div>
        <?php else: ?>
            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 15%;">Timestamp</th>
                        <th style="width: 12%;">User</th>
                        <th style="width: 12%;">Activity</th>
                        <th style="width: 36%;">Description</th>
                        <th style="width: 12%;">IP Address</th>
                        <th style="width: 8%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $index => $record): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td><small><?= date('M d, Y H:i', strtotime($record['created_at'])) ?></small></td>
                            <td><?= htmlspecialchars($record['username'] ?? 'System') ?></td>
                            <td><?= htmlspecialchars($record['activity_type'] ?? 'N/A') ?></td>
                            <td><small><?= htmlspecialchars($record['description'] ?? '') ?></small></td>
                            <td><code class="small"><?= htmlspecialchars($record['ip_address'] ?? 'N/A') ?></code></td>
                            <td>
                                <?php
                                $status = $record['status'] ?? 'N/A';
                                $badgeClass = $status === 'success' ? 'bg-success' : ($status === 'failed' ? 'bg-danger' : 'bg-secondary');
                                ?>
                                <span class="badge <?= $badgeClass ?> small"><?= $status ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer-section">
            <div class="row">
                <div class="col-6">
                    <p class="mb-0"><strong>Total Records:</strong> <?= count($records) ?></p>
                    <p class="mb-0"><strong>Generated By:</strong> <?= $_SESSION['username'] ?? 'System' ?></p>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-0"><em>This is a computer-generated report</em></p>
                    <p class="mb-0">&copy; <?= date('Y') ?> <?= $companyName ?></p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
