<?php
/**
 * Print Compliance Records Report
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
    
    $query .= " ORDER BY category, updated_at DESC";
    
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
    
    // Get statistics
    $totalCount = count($records);
    $expiredCount = count(array_filter($records, fn($r) => $r['status'] === 'Expired'));
    $expiringCount = count(array_filter($records, fn($r) => $r['status'] === 'Expiring Soon'));
    $validCount = count(array_filter($records, fn($r) => $r['status'] === 'Valid'));
    
    // Get company info (if available)
    $companyName = "Business Management System";
    $reportDate = date('F d, Y');
    $reportTime = date('h:i A');
    
} catch (Exception $e) {
    die("Error loading compliance records: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compliance Records Report - <?= $reportDate ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 11pt; }
            .page-break { page-break-after: always; }
        }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .header-section { border-bottom: 3px solid #0d6efd; padding-bottom: 15px; margin-bottom: 20px; }
        .stats-box { border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; margin-bottom: 15px; }
        .record-card { border: 1px solid #dee2e6; border-radius: 6px; padding: 12px; margin-bottom: 10px; }
        .badge-custom { padding: 5px 12px; border-radius: 4px; font-size: 0.85em; }
        .footer-section { margin-top: 30px; padding-top: 15px; border-top: 2px solid #dee2e6; font-size: 0.9em; color: #6c757d; }
    </style>
</head>
<body>
    <div class="container my-4">
        <!-- Print Button -->
        <div class="no-print text-end mb-3">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                Close
            </button>
        </div>

        <!-- Header -->
        <div class="header-section">
            <div class="row align-items-center">
                <div class="col-8">
                    <h2 class="mb-1 fw-bold"><?= $companyName ?></h2>
                    <h4 class="text-primary mb-0">Compliance Records Report</h4>
                </div>
                <div class="col-4 text-end">
                    <p class="mb-0"><strong>Report Date:</strong> <?= $reportDate ?></p>
                    <p class="mb-0"><strong>Generated:</strong> <?= $reportTime ?></p>
                    <?php if ($category): ?>
                        <p class="mb-0"><strong>Category:</strong> <?= htmlspecialchars($category) ?></p>
                    <?php endif; ?>
                    <?php if ($status): ?>
                        <p class="mb-0"><strong>Status Filter:</strong> <?= htmlspecialchars($status) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Statistics Summary -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-box bg-light">
                    <h5 class="text-primary mb-1"><?= $totalCount ?></h5>
                    <small class="text-muted">Total Records</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-box" style="background-color: #fff3cd;">
                    <h5 class="text-warning mb-1"><?= $expiringCount ?></h5>
                    <small class="text-muted">Expiring Soon</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-box" style="background-color: #f8d7da;">
                    <h5 class="text-danger mb-1"><?= $expiredCount ?></h5>
                    <small class="text-muted">Expired</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-box" style="background-color: #d1e7dd;">
                    <h5 class="text-success mb-1"><?= $validCount ?></h5>
                    <small class="text-muted">Valid & Active</small>
                </div>
            </div>
        </div>

        <!-- Records Table -->
        <h5 class="mb-3 fw-bold">Compliance Documents</h5>
        
        <?php if (empty($records)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No compliance records found matching the selected filters.
            </div>
        <?php else: ?>
            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 25%;">Document Title</th>
                        <th style="width: 12%;">Category</th>
                        <th style="width: 15%;">Reference No.</th>
                        <th style="width: 12%;">Expiry Date</th>
                        <th style="width: 10%;">Status</th>
                        <th style="width: 21%;">Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $index => $record): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($record['title']) ?></strong>
                                <br><small class="text-muted">Updated: <?= date('M d, Y', strtotime($record['updated_at'])) ?></small>
                            </td>
                            <td><?= htmlspecialchars($record['category']) ?></td>
                            <td><?= htmlspecialchars($record['ref_no'] ?? 'N/A') ?></td>
                            <td><?= $record['expiry_date'] ? date('M d, Y', strtotime($record['expiry_date'])) : 'No Expiry' ?></td>
                            <td>
                                <?php
                                $badgeClass = 'bg-success';
                                if ($record['status'] === 'Expired') $badgeClass = 'bg-danger';
                                if ($record['status'] === 'Expiring Soon') $badgeClass = 'bg-warning text-dark';
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= $record['status'] ?></span>
                            </td>
                            <td><small><?= htmlspecialchars(substr($record['notes'] ?? '', 0, 100)) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer-section">
            <div class="row">
                <div class="col-6">
                    <p class="mb-0"><strong>Total Records:</strong> <?= $totalCount ?></p>
                    <p class="mb-0"><strong>Report Generated By:</strong> <?= $_SESSION['username'] ?? 'System' ?></p>
                </div>
                <div class="col-6 text-end">
                    <p class="mb-0"><em>This is a computer-generated report</em></p>
                    <p class="mb-0">&copy; <?= date('Y') ?> <?= $companyName ?></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-print on load (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
