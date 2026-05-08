<?php
// api/operations/print_customers.php
require_once __DIR__ . '/../../roots.php';

// Enforce basic authentication
if (!isset($_SESSION['user_id'])) {
    die("Access Denied: Please log in to view this document.");
}

// Standard Header
includeHeader();

global $pdo;

// Fetch Data using short keys
$status = $_GET['s'] ?? '';
$category = $_GET['c'] ?? '';
$search = $_GET['q'] ?? '';

try {
    $where = ["c.status != 'deleted'"];
    $params = [];
    
    if ($status) {
        $where[] = "c.status = ?";
        $params[] = $status;
    }
    
    if ($category) {
        $where[] = "c.category_id = ?";
        $params[] = $category;
    }
    
    if ($search) {
        $where[] = "(c.customer_name LIKE ? OR c.customer_code LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)";
        $s = "%$search%";
        $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    }
    
    $where_clause = implode(" AND ", $where);
    
    $query = "
        SELECT 
            c.*,
            cc.category_name,
            COUNT(DISTINCT so.sales_order_id) as total_orders,
            COUNT(DISTINCT si.invoice_id) as total_invoices,
            SUM(CASE WHEN si.status = 'unpaid' THEN si.grand_total ELSE 0 END) as total_unpaid,
            SUM(CASE WHEN si.status = 'paid' THEN si.grand_total ELSE 0 END) as total_paid
        FROM customers c
        LEFT JOIN customer_categories cc ON c.category_id = cc.category_id
        LEFT JOIN sales_orders so ON c.customer_id = so.customer_id
        LEFT JOIN invoices si ON c.customer_id = si.customer_id
        WHERE $where_clause
        GROUP BY c.customer_id
        ORDER BY c.customer_name ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Stats Calculation
$total_unpaid_all = 0;
$count_total = count($customers);
$count_active = 0;
$count_inactive = 0;
$count_blacklisted = 0;

foreach($customers as $c) {
    $total_unpaid_all += $c['total_unpaid'];
    if ($c['status'] == 'active') $count_active++;
    if ($c['status'] == 'inactive') $count_inactive++;
    if ($c['status'] == 'blacklisted') $count_blacklisted++;
}
?>

<style>
    /* Printing Style Overrides */
    @media print {
        @page { 
            size: auto; 
            margin: 5mm 5mm 1cm 5mm; 
        }
        
        /* Reset body and container to ensure top-alignment */
        body { 
            margin: 0 !important; 
            padding: 0 !important; 
            background: #fff !important;
        }
        
        .container-fluid {
            margin-top: 0 !important;
            padding-top: 0 !important;
            width: 100% !important;
        }

        /* Hide any system elements that might still be taking space */
        header, footer, nav, .navbar, .breadcrumb, .btn {
            display: none !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .stat-card-box {
            background-color: #fff !important;
            border: 1px solid #000 !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            margin-top: 0 !important;
        }
        .stat-card-box span { color: #000 !important; }
        .stat-card-box small { color: #000 !important; font-weight: bold !important; }

        /* Ensure table can break and starts correctly */
        table {
            page-break-inside: auto !important;
            width: 100% !important;
            font-size: 8pt !important;
        }
        tr { page-break-inside: avoid !important; page-break-after: auto !important; }
    }
</style>

<div class="container-fluid">
    
    <!-- Page-Specific Black Heading -->
    <h3 class="text-center fw-bold d-none d-print-block mb-3" style="color: #000; text-transform: uppercase;">
        Official Customers Registry Report
    </h3>

    <!-- Screen-Only Controls -->
    <div class="d-print-none mb-4 d-flex justify-content-between align-items-center bg-light p-3 rounded border">
        <div>
            <h4 class="mb-0">Print Preview: Customers Registry</h4>
            <p class="text-muted small mb-0">Full database of active clients and financial summaries.</p>
        </div>
        <div>
            <button class="btn btn-secondary me-2" onclick="window.location.href='<?= getUrl('customers') ?>'">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </button>
            <button class="btn btn-primary px-4" onclick="window.print()">
                <i class="bi bi-printer"></i> Print Now
            </button>
        </div>
    </div>

    <!-- Compact Summary Row: Status Metrics Only -->
    <div class="row g-2 mb-4 align-items-stretch">
        <div class="col-3">
            <div class="border p-2 text-center rounded stat-card-box h-100 d-flex flex-column justify-content-center">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Total Clients</small>
                <span class="fw-bold" style="font-size: 1.2rem;"><?= $count_total ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded stat-card-box h-100 d-flex flex-column justify-content-center">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Active</small>
                <span class="fw-bold text-success" style="font-size: 1.2rem;"><?= $count_active ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded stat-card-box h-100 d-flex flex-column justify-content-center">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Inactive</small>
                <span class="fw-bold text-secondary" style="font-size: 1.2rem;"><?= $count_inactive ?></span>
            </div>
        </div>
        <div class="col-3">
            <div class="border p-2 text-center rounded stat-card-box h-100 d-flex flex-column justify-content-center">
                <small class="text-muted text-uppercase d-block" style="font-size: 7pt; font-weight: 700;">Blacklisted</small>
                <span class="fw-bold text-danger" style="font-size: 1.2rem;"><?= $count_blacklisted ?></span>
            </div>
        </div>
    </div>

    <!-- Data Table -->
    <table class="table table-bordered align-middle">
        <thead class="table-light text-uppercase small fw-bold">
            <tr>
                <th class="text-center" style="width: 40px;">S/N</th>
                <th>Code</th>
                <th>Customer Name & Category</th>
                <th>Contact Details</th>
                <th class="text-center">Activity</th>
                <th class="text-end">Paid (TZS)</th>
                <th class="text-end">Unpaid (TZS)</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sno = 1;
            foreach ($customers as $row): 
            ?>
                <tr>
                    <td class="text-center"><?= $sno++ ?></td>
                    <td class="font-monospace small"><?= htmlspecialchars($row['customer_code']) ?></td>
                    <td>
                        <div class="fw-bold"><?= htmlspecialchars($row['customer_name']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($row['category_name'] ?? 'General') ?></small>
                    </td>
                    <td class="small">
                        <i class="bi bi-person text-muted me-1"></i> <?= htmlspecialchars($row['contact_person'] ?: 'N/A') ?><br>
                        <i class="bi bi-telephone text-muted me-1"></i> <?= htmlspecialchars($row['phone'] ?: 'N/A') ?><br>
                        <i class="bi bi-envelope text-muted me-1"></i> <?= htmlspecialchars($row['email'] ?: 'N/A') ?>
                    </td>
                    <td class="text-center small">
                        Orders: <?= $row['total_orders'] ?><br>
                        Invoices: <?= $row['total_invoices'] ?>
                    </td>
                    <td class="text-end fw-bold text-success"><?= number_format($row['total_paid'], 2) ?></td>
                    <td class="text-end fw-bold text-danger"><?= number_format($row['total_unpaid'], 2) ?></td>
                    <td class="text-center text-uppercase small fw-bold"><?= htmlspecialchars($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
    window.onload = function() {
        if (window.location.search.indexOf('a=1') > -1) {
            window.print();
        }
    };
</script>

<?php
// Standard Footer
includeFooter();
?>
