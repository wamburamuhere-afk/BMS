<?php
// api/operations/print_assets.php
require_once __DIR__ . '/../../roots.php';

// Enforce basic authentication for print view
if (!isset($_SESSION['user_id'])) {
    die("Access Denied: Please log in to view this document.");
}

// Standard Header (Includes Global Print Header with Logo & Blue Company Name)
includeHeader();

global $pdo;

// Fetch Data using short keys
$category = $_GET['c'] ?? '';
$status = $_GET['s'] ?? '';
$search = $_GET['q'] ?? '';

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
    
    if ($search) {
        $where[] = "(asset_name LIKE ? OR asset_code LIKE ? OR location LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $where_clause = implode(" AND ", $where);
    
    $stmt = $pdo->prepare("SELECT * FROM assets WHERE $where_clause ORDER BY created_at DESC");
    $stmt->execute($params);
    $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Calculate Statistics
$total_assets = count($assets);
$total_cost_val = 0;
$in_maintenance = 0;
$categories_list = [];
foreach($assets as $a) {
    $total_cost_val += $a['cost'];
    if($a['status'] == 'maintenance') $in_maintenance++;
    $categories_list[] = $a['category'];
}
$total_categories = count(array_unique($categories_list));
?>

<div class="container-fluid">
    
    <!-- Page-Specific Black Heading (Visible only on Print) -->
    <h3 class="text-center fw-bold d-none d-print-block mb-3" style="color: #000; text-transform: uppercase;">
        Assets Management Report
    </h3>

    <!-- Screen-Only Controls -->
    <div class="d-print-none mb-4 d-flex justify-content-between align-items-center bg-light p-3 rounded border">
        <div>
            <h4 class="mb-0">Print Preview: Assets Report</h4>
            <p class="text-muted small mb-0">Review the document below before printing.</p>
        </div>
        <div>
            <button class="btn btn-secondary me-2" onclick="window.close()">
                <i class="bi bi-arrow-left"></i> Back to Assets
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
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Total Assets</small>
                <span class="fw-bold fs-5"><?= $total_assets ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Total Value</small>
                <span class="fw-bold fs-5"><?= number_format($total_cost_val, 2) ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Maintenance</small>
                <span class="fw-bold fs-5"><?= $in_maintenance ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Categories</small>
                <span class="fw-bold fs-5"><?= $total_categories ?></span>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <table class="table table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th class="text-center" style="width: 50px;">S/N</th>
                <th>Asset Details</th>
                <th>Asset Code</th>
                <th>Category</th>
                <th>Purchase Date</th>
                <th class="text-end">Cost (TZS)</th>
                <th class="text-center">Status</th>
                <th>Location</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sno = 1;
            foreach ($assets as $asset): 
            ?>
                <tr>
                    <td class="text-center"><?= $sno++ ?></td>
                    <td class="fw-bold"><?= htmlspecialchars($asset['asset_name']) ?></td>
                    <td class="font-monospace"><?= htmlspecialchars($asset['asset_code']) ?></td>
                    <td><?= htmlspecialchars($asset['category']) ?></td>
                    <td><?= $asset['purchase_date'] ? date('d M Y', strtotime($asset['purchase_date'])) : 'N/A' ?></td>
                    <td class="text-end fw-bold"><?= number_format($asset['cost'], 2) ?></td>
                    <td class="text-center text-uppercase small fw-bold"><?= htmlspecialchars($asset['status']) ?></td>
                    <td><?= htmlspecialchars($asset['location']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
            <tr>
                <td colspan="5" class="text-end">Aggregate Asset Value:</td>
                <td class="text-end"><?= number_format($total_cost_val, 2) ?></td>
                <td colspan="2"></td>
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
// Standard Footer (Includes Global Print Footer with Page Info & Powered By)
includeFooter();
?>
