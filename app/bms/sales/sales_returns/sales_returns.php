<?php
// File: app/bms/sales/sales_returns/sales_returns.php
require_once __DIR__ . '/../../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('sales_returns');

includeHeader();

global $pdo;

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$customer_filter = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Check if table exists (Temporary safety check)
try {
    $check = $pdo->query("SELECT 1 FROM sales_returns LIMIT 1");
} catch (Exception $e) {
    // If table doesn't exist, we might render an empty state or error
    $table_exists = false;
}

$returns = [];
$stats = [
    'total_returns' => 0,
    'pending' => 0,
    'approved' => 0,
    'total_refunded' => 0
];

if (isset($table_exists) && $table_exists === false) {
    // Table missing, show empty
} else {
    // Build query for returns
    $query = "
        SELECT 
            sr.sales_return_id as return_id,
            sr.return_number,
            sr.sales_order_id,
            sr.customer_id,
            sr.return_date,
            sr.total_amount as grand_total,
            sr.status,
            c.customer_name,
            c.company_name,
            so.order_number as original_order_number,
            u.username as created_by_name,
            (SELECT COUNT(*) FROM sales_return_items sri WHERE sri.sales_return_id = sr.sales_return_id) as total_items
        FROM sales_returns sr
        LEFT JOIN customers c ON sr.customer_id = c.customer_id
        LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
        LEFT JOIN users u ON sr.created_by = u.user_id
        WHERE 1=1
    ";
    $query .= scopeFilterSqlNullable('project', 'so');

    $params = [];

    if (!empty($status_filter)) {
        $query .= " AND sr.status = ?";
        $params[] = $status_filter;
    }

    if ($customer_filter > 0) {
        $query .= " AND sr.customer_id = ?";
        $params[] = $customer_filter;
    }

    if (!empty($date_from)) {
        $query .= " AND sr.return_date >= ?";
        $params[] = $date_from;
    }

    if (!empty($date_to)) {
        $query .= " AND sr.return_date <= ?";
        $params[] = $date_to;
    }

    $query .= " ORDER BY sr.return_date DESC, sr.created_at DESC";

    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $returns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stats
        $stats = [
            'total_returns' => count($returns),
            'pending' => count(array_filter($returns, fn($r) => $r['status'] == 'pending')),
            'approved' => count(array_filter($returns, fn($r) => $r['status'] == 'approved')),
            'total_refunded' => array_sum(array_column($returns, 'grand_total'))
        ];
    } catch (Exception $e) {
        // Handle gracefully
        $returns = [];
    }
}

// Get customers for filter
$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

?>
<style>
.sales-returns-dashboard { background: #ffffff; min-height: 100vh; }
.custom-stat-card { background-color: #d1e7dd !important; border-color: #badbcc !important; transition: transform 0.2s; border-radius: 12px; }
.custom-stat-card:hover { transform: translateY(-3px); }
.custom-stat-card h4, .custom-stat-card small { color: #0f5132 !important; font-weight: 600; }
.stats-icon { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin-right: 1.25rem; background: rgba(15, 81, 50, 0.1); color: #0f5132 !important; }
.bg-success-soft { background-color: rgba(25, 135, 84, 0.1) !important; }

/* Status Badge Styles */
.status-completed { color: #157347 !important; background-color: #d1e7dd !important; }
.status-warning { color: #997404 !important; background-color: #fff3cd !important; }
.status-danger { color: #bb2d3b !important; background-color: #f8d7da !important; }
.status-secondary { color: #41464b !important; background-color: #e2e3e5 !important; }

    /* Prevent horizontal scroll globally for this page */
    html, body {
        overflow-x: hidden !important;
        width: 100vw !important;
        position: relative;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* Main Wrapper stability */
    .sales-returns-dashboard {
        background: #ffffff;
        min-height: 100vh;
        overflow-x: hidden !important;
        width: 100% !important;
        max-width: 100vw !important;
        padding-left: 10px !important;
        padding-right: 10px !important;
    }

    /* Sticky Dashboard Header - adjusted for potential main navbar */
    .sticky-dashboard-header {
        position: sticky;
        top: 0;
        z-index: 1010;
        background: #fff;
        padding-top: 15px;
        padding-bottom: 10px;
        margin: 0 -10px 20px -10px !important; 
        padding-left: 10px;
        padding-right: 10px;
        border-bottom: 1px solid #f1f1f1;
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
    }

    @media screen and (max-width: 768px) {
        .sales-returns-dashboard {
            padding: 1rem !important;
        }
        
        /* Table responsive card view */
        .table-responsive {
            overflow: hidden !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        #returnsTable {
            border: 0 !important;
            margin: 0 !important;
            background: transparent !important;
            width: 100% !important;
            display: block !important;
        }
        #returnsTable thead {
            display: none !important;
        }
        #returnsTable tbody {
            display: block !important;
            width: 100% !important;
            padding: 10px !important;
        }
        #returnsTable tr {
            display: block !important;
            width: 100% !important;
            margin-bottom: 1.2rem !important;
            background: #ffffff !important;
            border: 1px solid #eef0f2 !important;
            border-radius: 10px !important;
            padding: 10px !important;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
        }
        #returnsTable td {
            text-align: right !important;
            padding: 8px 0 !important;
            position: relative !important;
            border: none !important;
            min-height: 35px !important;
            display: flex !important;
            justify-content: flex-end !important;
            align-items: center !important;
            font-size: 0.85rem !important;
            width: 100% !important;
            box-sizing: border-box !important;
        }
        #returnsTable td:before {
            content: attr(data-label);
            position: absolute;
            left: 0;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.65rem;
            color: #64748b;
        }
        #returnsTable td.ps-4 {
            padding-left: 0 !important;
        }
        #returnsTable td.text-center, #returnsTable td.text-end {
            justify-content: flex-end !important;
        }
        #returnsTable td.action-cell {
            justify-content: center !important;
            border-top: 1px solid #f1f3f5 !important;
            margin-top: 10px !important;
            padding: 12px 5px !important;
            width: 100% !important;
            display: flex !important;
            background: #fafbfc !important;
            flex-wrap: nowrap !important;
        }
        #returnsTable td.action-cell .btn-sm {
            width: 22px !important;
            height: 22px !important;
            padding: 0 !important;
            font-size: 0.65rem !important;
            line-height: 1 !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            margin: 0 1px !important;
            border-radius: 4px !important;
            flex-shrink: 0 !important;
        }
        #returnsTable td.action-cell .bi {
            font-size: 0.65rem !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        #returnsTable td.action-cell:before {
            display: none;
        }
    }

    @media print {
        /* Hide unwanted elements */
        .d-print-none, .btn, .btn-group, .pagination, .breadcrumb, .dropdown, .card-header, #filterForm, .sticky-dashboard-header {
            display: none !important;
        }
        /* ... existing print styles ... */

        body {
            margin: 0 !important;
            padding: 0 !important;
            background: white !important;
        }

        .sales-returns-dashboard {
            background: white !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        /* Print Stats Cards layout */
        .sales-returns-dashboard .row.g-4.mb-5 {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            width: 100% !important;
            gap: 10px !important;
            margin-bottom: 20px !important;
        }
        .sales-returns-dashboard .row.g-4.mb-5 > div {
            flex: 1 1 25% !important;
            max-width: 25% !important;
            width: 25% !important;
        }
        .custom-stat-card {
            border: 1px solid #badbcc !important;
            background-color: #d1e7dd !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            padding: 10px !important;
        }

        /* Table Print Styling */
        table {
            width: 100% !important;
            border-collapse: collapse !important;
            font-size: 9pt !important;
        }
        th, td {
            border: 1px solid #333 !important;
            padding: 6px 8px !important;
        }
        thead { display: table-header-group !important; }
        tfoot { display: table-footer-group !important; }

        /* Fixed Branded Footer logic */
        .fixed-print-footer { 
            position: fixed; 
            bottom: 0; 
            left: 0; 
            right: 0; 
            width: 100%;
            text-align: center;
            background: white !important;
            padding-bottom: 10px;
            z-index: 9999;
            border-top: 1px solid #eee;
            display: block !important;
        }

        @page {
            margin: 10mm 10mm 20mm 10mm;
        }
    }
</style>


    <script>
        function resizeTextToFit() {
            const elements = document.querySelectorAll('.custom-stat-card h4.auto-resize');
            elements.forEach(el => {
                let size = 1.3; // Starting size
                el.style.fontSize = size + 'rem';
                const container = el.closest('.overflow-hidden');
                if (container) {
                    const containerWidth = container.clientWidth;
                    while (el.scrollWidth > containerWidth && size > 0.7) {
                        size -= 0.05;
                        el.style.fontSize = size + 'rem';
                    }
                }
            });
        }
        window.addEventListener('load', resizeTextToFit);
        window.addEventListener('resize', resizeTextToFit);
    </script>

<div class="sales-returns-dashboard">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
       
        
    

        <div class="mt-3">
            <h3 class="text-uppercase text-dark fw-bold mb-1">SALES RETURNS REPORT</h3>
            <p class="text-dark">Printed on <?= date('F j, Y h:i A') ?></p>
        </div>
        <hr>
    </div>

    <div class="sticky-dashboard-header d-print-none">
        <!-- Breadcrumbs -->
        <nav aria-label="breadcrumb" class="mb-3 px-2">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Sales Returns</li>
            </ol>
        </nav>

        <!-- Page Header -->
        <div class="row mb-4 px-2">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="fw-bold mb-1">
                            <i class="bi bi-arrow-counterclockwise text-primary"></i> Sales Returns
                        </h2>
                        <p class="text-muted small mb-0">Manage and track customer return records</p>
                    </div>
                    <div>
                        <a href="sales_return_create" class="btn btn-primary shadow-sm px-4">
                            <i class="bi bi-plus-circle me-1"></i> New Return
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row g-2 mb-2 px-1">
            <div class="col-md-3 col-6 mb-2">
                <div class="card custom-stat-card h-100 shadow-sm border-0">
                    <div class="card-body p-2 d-flex flex-column justify-content-center">
                        <div class="d-flex align-items-center">
                            <div class="stats-icon sm-icon"><i class="bi bi-list-ul"></i></div>
                            <div class="flex-grow-1 overflow-hidden">
                                <small class="text-uppercase opacity-75 d-block" style="font-size: 0.65rem;">Total Returns</small>
                                <h5 class="mb-0 fw-bold" style="font-size: 1rem;"><?= number_format($stats['total_returns']) ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card custom-stat-card h-100 shadow-sm border-0">
                    <div class="card-body p-2 d-flex flex-column justify-content-center">
                        <div class="d-flex align-items-center">
                            <div class="stats-icon sm-icon"><i class="bi bi-clock-history"></i></div>
                            <div class="flex-grow-1 overflow-hidden">
                                <small class="text-uppercase opacity-75 d-block" style="font-size: 0.65rem;">Pending</small>
                                <h5 class="mb-0 fw-bold" style="font-size: 1rem;"><?= number_format($stats['pending']) ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card custom-stat-card h-100 shadow-sm border-0">
                    <div class="card-body p-2 d-flex flex-column justify-content-center">
                        <div class="d-flex align-items-center">
                            <div class="stats-icon sm-icon"><i class="bi bi-check-circle"></i></div>
                            <div class="flex-grow-1 overflow-hidden">
                                <small class="text-uppercase opacity-75 d-block" style="font-size: 0.65rem;">Approved</small>
                                <h5 class="mb-0 fw-bold" style="font-size: 1rem;"><?= number_format($stats['approved']) ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6 mb-2">
                <div class="card custom-stat-card h-100 shadow-sm border-0">
                    <div class="card-body p-2 d-flex flex-column justify-content-center">
                        <div class="d-flex align-items-center">
                            <div class="stats-icon sm-icon"><i class="bi bi-currency-dollar"></i></div>
                            <div class="flex-grow-1 overflow-hidden">
                                <small class="text-uppercase opacity-75 d-block" style="font-size: 0.65rem;">Refunded</small>
                                <h5 class="mb-0 fw-bold" style="font-size: 1rem;">TZS <?= number_format($stats['total_refunded'], 0) ?></h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Card -->
    <div class="card mb-5 border-0 shadow-sm d-print-none">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filters & Search</h6>
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Customer</label>
                    <select name="customer" class="form-select border-0 bg-light" id="filter_customer">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['customer_id'] ?>">
                                <?= safe_output($c['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                    <select name="status" class="form-select border-0 bg-light" id="filter_status">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="refunded">Refunded</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Date From</label>
                    <input type="date" name="date_from" id="filter_date_from" class="form-control border-0 bg-light">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Date To</label>
                    <input type="date" name="date_to" id="filter_date_to" class="form-control border-0 bg-light">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm shadow-sm px-4 fw-bold me-2">
                        <i class="bi bi-filter me-1"></i> Apply
                    </button>
                    <button type="button" id="resetFilters" class="btn btn-outline-secondary btn-sm px-4">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="mb-3 d-print-none text-start">
        <span class="badge bg-white text-dark border border-light-subtle px-3 py-2 fs-6 rounded-2 shadow-sm">
            <i class="bi bi-arrow-counterclockwise text-success me-1"></i> Sales Return Records
        </span>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportToExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="window.print()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" id="pageSize" style="width: 60px; box-shadow: none; background: transparent;">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Returns Table Card -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="returnsTable" style="width:100%">
                <thead class="bg-light text-uppercase small fw-bold">
                    <tr>
                        <th style="width:75px;" class="ps-4">S/NO</th>
                        <th class="ps-4">Return #</th>
                        <th>Date</th>
                        <th>Original Order</th>
                        <th>Customer</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Refund Amount</th>
                        <th class="text-center">Status</th>
                        <th class="text-center pe-4 d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody id="returnsTableBody">
                    </tbody>
                    <tfoot>
                        <!-- Spacer to prevent data hidden behind fixed footer in print -->
                        <tr class="d-none d-print-table-row" style="height: 100px; border: none !important;">
                            <td colspan="9" style="border: none !important;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Pagination Controls -->
            <div class="d-flex justify-content-between align-items-center p-3 border-top d-print-none">
                <div class="text-muted small" id="paginationInfo">
                    Showing 1 to 10 of 0 entries
                </div>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="paginationLinks">
                        <!-- Links generated by JS -->
                    </ul>
                </nav>
            </div>
        </div>
        </div>
    </div>

    

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// State
let currentPage = 1;
let currentLimit = 10;
let currentFilters = {};

// Initial Load
$(document).ready(function() {
    logReportAction('Viewed Sales Returns List', 'User viewed the list of customer sales returns');
    loadDisplayData();

    // Event Listeners
    $('#pageSize').on('change', function() {
        currentLimit = $(this).val();
        currentPage = 1; // Reset to page 1
        loadDisplayData();
    });

    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        currentFilters = {
            status: $('#filter_status').val(),
            customer: $('#filter_customer').val(),
            date_from: $('#filter_date_from').val(),
            date_to: $('#filter_date_to').val()
        };
        currentPage = 1;
        loadDisplayData();
    });

    $('#resetFilters').on('click', function() {
        $('#filterForm')[0].reset();
        $('#filter_customer').trigger('change.select2'); // keep Select2 display in sync after reset
        currentFilters = {};
        currentPage = 1;
        loadDisplayData();
    });

    // §UI-3 — searchable Select2 on the DB-backed Customer filter. No client-side
    // DataTable is added: this list is already server-paginated via AJAX
    // (api/sales/get_returns_paged.php), which a DataTable would conflict with.
    if ($('#filter_customer').length && !$('#filter_customer').hasClass('select2-hidden-accessible')) {
        $('#filter_customer').select2({ theme: 'bootstrap-5', placeholder: 'All Customers', allowClear: true, width: '100%' });
    }
});

function loadDisplayData() {
    const tableBody = $('#returnsTableBody');
    tableBody.html('<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-danger" role="status"></div></td></tr>');

    const params = {
        page: currentPage,
        limit: currentLimit,
        ...currentFilters
    };

    $.ajax({
        url: '<?= buildUrl('api/sales/get_returns_paged.php') ?>',
        data: params,
        dataType: 'json',
        success: function(response) {
            renderTable(response.data);
            renderPagination(response.pagination);
        },
        error: function() {
            tableBody.html('<tr><td colspan="8" class="text-center text-danger py-4">Error loading data. Please try again.</td></tr>');
        }
    });
}

function renderTable(data) {
    const tableBody = $('#returnsTableBody');
    tableBody.empty();

    if (data.length === 0) {
        tableBody.html(`
            <tr>
                <td colspan="8" class="text-center py-5">
                    <div class="text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                        <p class="mb-0">No sales returns found.</p>
                    </div>
                </td>
            </tr>
        `);
        return;
    }

    const statusClasses = {
        'pending': 'status-warning',
        'approved': 'status-completed',
        'refunded': 'status-completed',
        'rejected': 'status-danger'
    };

    data.forEach(function(r, index) {
        const sn = (currentPage - 1) * currentLimit + index + 1;
        const statusLabel = r.status.charAt(0).toUpperCase() + r.status.slice(1);
        const badgeClass = statusClasses[r.status] || 'status-secondary';
        const viewLink = `sales_return_view?id=${r.return_id}`;
        const editLink = `sales_return_edit?id=${r.return_id}`;
        
        const row = `
            <tr>
                <td data-label="S/NO" class="ps-4 text-muted small fw-bold">${sn}</td>
                <td data-label="Return #" class="ps-4 fw-bold text-primary">${r.return_number}</td>
                <td data-label="Date">${r.formatted_date}</td>
                <td data-label="Original Order">
                    <a href="sales_order_view?id=${r.sales_order_id}" class="text-decoration-none fw-bold">
                        ${r.original_order_number || 'N/A'}
                    </a>
                </td>
                <td data-label="Customer">
                    <div class="fw-bold">${r.customer_name || 'Walk-in Customer'}</div>
                    ${r.company_name ? `<small class="text-muted text-truncate d-block" style="max-width: 200px;">${r.company_name}</small>` : ''}
                </td>
                <td data-label="Items" class="text-center">
                    <span class="badge bg-light text-dark border rounded-pill">${r.total_items}</span>
                </td>
                <td data-label="Refund Amount" class="text-end fw-bold">
                    TZS ${r.formatted_total}
                </td>
                <td data-label="Status" class="text-center">
                    <span class="badge rounded-pill ${badgeClass} bg-opacity-10 py-2 px-3" style="min-width: 100px; color: currentcolor !important;">${statusLabel.toUpperCase()}</span>
                </td>
                <td class="text-center pe-4 d-print-none action-cell">
                    <div class="d-none d-md-block">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li>
                                    <a class="dropdown-item" href="${viewLink}">
                                        <i class="bi bi-eye text-primary me-2"></i> View Details
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="${editLink}">
                                        <i class="bi bi-pencil text-info me-2"></i> Edit Return
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="javascript:void(0)" onclick="changeStatus(${r.return_id}, '${r.status}')">
                                        <i class="bi bi-arrow-repeat text-warning me-2"></i> Update Status
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="javascript:void(0)" onclick="printReturn(${r.return_id})">
                                        <i class="bi bi-printer text-secondary me-2"></i> Print Receipt
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteReturn(${r.return_id})">
                                        <i class="bi bi-trash me-2"></i> Delete Return
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="d-flex d-md-none justify-content-center w-100" style="gap: 4px;">
                        <a href="${viewLink}" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye"></i></a>
                        <a href="${editLink}" class="btn btn-outline-info btn-sm"><i class="bi bi-pencil"></i></a>
                        <button onclick="printReturn(${r.return_id})" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i></button>
                        <button onclick="deleteReturn(${r.return_id})" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>
        `;
        tableBody.append(row);
    });
}

function renderPagination(meta) {
    const info = $('#paginationInfo');
    const links = $('#paginationLinks');
    
    // Update Info
    const start = (meta.current_page - 1) * meta.per_page + 1;
    const end = Math.min(meta.current_page * meta.per_page, meta.total);
    info.text(`Showing ${start} to ${end} of ${meta.total} entries`);

    // Update Links
    links.empty();
    
    // Prev
    links.append(`
        <li class="page-item ${meta.current_page === 1 ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="changePage(${meta.current_page - 1}); return false;">Previous</a>
        </li>
    `);

    // Pages (Simple Logic for now: 1..Last)
    for (let i = 1; i <= meta.last_page; i++) {
        links.append(`
            <li class="page-item ${i === meta.current_page ? 'active' : ''}">
                <a class="page-link" href="#" onclick="changePage(${i}); return false;">${i}</a>
            </li>
        `);
    }

    // Next
    links.append(`
        <li class="page-item ${meta.current_page === meta.last_page ? 'disabled' : ''}">
            <a class="page-link" href="#" onclick="changePage(${meta.current_page + 1}); return false;">Next</a>
        </li>
    `);
}

function changePage(page) {
    if (page < 1) return;
    currentPage = page;
    loadDisplayData();
}

function changeStatus(id, currentStatus) {
    const statuses = {
        'pending': 'Pending',
        'approved': 'Approved',
        'refunded': 'Refunded',
        'rejected': 'Rejected'
    };

    let options = '';
    for (const [val, label] of Object.entries(statuses)) {
        options += `<option value="${val}" ${val === currentStatus ? 'selected' : ''}>${label}</option>`;
    }

    Swal.fire({
        title: 'Update Return Status',
        html: `
            <div class="text-start mb-3">
                <label class="form-label fw-bold">Select Status:</label>
                <select id="swal-status" class="form-select">
                    ${options}
                </select>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Update Status',
        confirmButtonColor: '#dc3545',
        preConfirm: () => {
            return document.getElementById('swal-status').value;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            updateStatus(id, result.value);
        }
    });
}

function updateStatus(id, status) {
    Swal.fire({
        title: 'Updating...',
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: '<?= buildUrl('api/sales/update_return_status.php') ?>',
        type: 'POST',
        data: { return_id: id, status: status },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Status Updated',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => {
                    loadDisplayData(); // Refresh via Ajax, no full reload
                });
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Communication with server failed', 'error');
        }
    });
}

function deleteReturn(id) {
    Swal.fire({
        title: 'Delete Return?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#d33'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl('api/sales/delete_return.php') ?>',
                type: 'POST',
                data: { return_id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire('Deleted', 'Return record removed successfully', 'success').then(() => {
                            loadDisplayData(); // Refresh via Ajax
                        });
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                }
            });
        }
    });
}

function printReturn(id) {
    window.open('print_sales_return?id=' + id, '_blank');
}

function copyTable() {
    const table = document.getElementById('returnsTable');
    const range = document.createRange();
    range.selectNode(table);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand('copy');
    window.getSelection().removeAllRanges();
    logReportAction('Copied Sales Returns Table', 'User copied sales returns table to clipboard');
    Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1500, showConfirmButton: false });
}

function exportToExcel() {
    let table = document.getElementById('returnsTable');
    let rows = table.querySelectorAll('tr');
    let csv = [];
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length - 1; j++) { // Skip actions
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(row.join(','));
    }
    let csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
    let encodedUri = encodeURI(csvContent);
    let link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "sales_returns_report.csv");
    document.body.appendChild(link);
    logReportAction('Exported Sales Returns', 'User exported sales returns list to CSV');
    link.click();
}
</script>

<?php includeFooter(); ?>
