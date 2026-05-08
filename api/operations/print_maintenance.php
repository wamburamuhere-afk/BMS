<?php
// api/operations/print_maintenance.php
require_once __DIR__ . '/../../roots.php';

// Enforce basic authentication
if (!isset($_SESSION['user_id'])) {
    die("Access Denied: Please log in to view this document.");
}

// Standard Header (Includes Global Print Header with Logo & Blue Company Name)
includeHeader();

global $pdo;

// Fetch Data using short keys
$status = $_GET['s'] ?? '';
$search = $_GET['q'] ?? '';

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
    
    $stmt = $pdo->prepare("SELECT m.*, a.asset_name, a.asset_code 
                           FROM maintenance_logs m 
                           JOIN assets a ON m.asset_id = a.asset_id 
                           WHERE $where_clause ORDER BY m.maintenance_date DESC");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Calculate Statistics
$pending = 0; $in_progress = 0; $completed = 0; $total_cost_m = 0;
foreach($logs as $l) {
    $total_cost_m += $l['cost'];
    if($l['status'] == 'pending') $pending++;
    elseif($l['status'] == 'in_progress') $in_progress++;
    elseif($l['status'] == 'completed') $completed++;
}
?>

<div class="container-fluid">
    
    <!-- Page-Specific Black Heading (Visible only on Print) -->
    <h3 class="text-center fw-bold d-none d-print-block mb-3" style="color: #000; text-transform: uppercase;">
        Maintenance Logs Report
    </h3>

    <!-- Screen-Only Controls -->
    <div class="d-print-none mb-4 d-flex justify-content-between align-items-center bg-light p-3 rounded border">
        <div>
            <h4 class="mb-0">Print Preview: Maintenance Logs</h4>
            <p class="text-muted small mb-0">Review the report below before printing.</p>
        </div>
        <div>
            <button class="btn btn-secondary me-2" onclick="window.close()">
                <i class="bi bi-arrow-left"></i> Back to Logs
            </button>
            <button class="btn btn-primary px-4" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Now
            </button>
        </div>
    </div>

    <!-- Summary Section (Visible on Print) -->
    <div class="row g-2 mb-4">
        <div class="col-3">
            <div class="border p-2 text-center rounded">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Pending</small>
                <span class="fw-bold fs-5"><?= $pending ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">In Progress</small>
                <span class="fw-bold fs-5"><?= $in_progress ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Completed</small>
                <span class="fw-bold fs-5"><?= $completed ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Total Costs</small>
                <span class="fw-bold fs-5"><?= number_format($total_cost_m, 2) ?></span>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <table class="table table-bordered align-middle">
        <thead class="table-light text-uppercase small fw-bold">
            <tr>
                <th class="text-center" style="width: 50px;">S/N</th>
                <th>Asset Details</th>
                <th>Date</th>
                <th>Type</th>
                <th>Performed By</th>
                <th class="text-end">Cost (TZS)</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sno = 1;
            foreach ($logs as $log): 
            ?>
                <tr>
                    <td class="text-center"><?= $sno++ ?></td>
                    <td>
                        <div class="fw-bold"><?= htmlspecialchars($log['asset_name']) ?></div>
                        <small class="text-muted font-monospace"><?= htmlspecialchars($log['asset_code']) ?></small>
                    </td>
                    <td><?= date('d M Y', strtotime($log['maintenance_date'])) ?></td>
                    <td><?= ucfirst($log['maintenance_type']) ?></td>
                    <td><?= htmlspecialchars($log['performed_by']) ?></td>
                    <td class="text-end fw-bold"><?= number_format($log['cost'], 2) ?></td>
                    <td class="text-center text-uppercase small fw-bold"><?= str_replace('_', ' ', $log['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
            <tr>
                <td colspan="5" class="text-end text-uppercase">Aggregate Maintenance Costs:</td>
                <td class="text-end"><?= number_format($total_cost_m, 2) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>

<script>
    // Automatically trigger print on load if requested
    window.onload = function() {
        if (window.location.search.indexOf('a=1') > -1) {
            window.print();
        }
    };
</script>

<?php
// Standard Footer (Includes Global Print Footer)
includeFooter();
?>
