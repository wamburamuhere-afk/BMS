<?php
// Start the buffer
ob_start();

// Include the header
require_once 'header.php';

// Check user permissions dynamically
$can_view_customers = canView('customers');
$can_create_customers = canCreate('customers');
$can_edit_customers = canEdit('customers');
$can_delete_customers = canDelete('customers');

if (!$can_view_customers) {
    header("Location: unauthorized");
    exit();
}

// Get company type for conditional features
$settings_stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_type'");
$settings_stmt->execute();
$company_type = $settings_stmt->fetchColumn() ?: 'microfinance';

// Display name from global header
$display_company_name = $GLOBALS['DISPLAY_COMPANY_NAME'];

// Fetch company logo and name for printing
$company_logo = getSetting('company_logo');
$company_name = getSetting('company_name') ?: $display_company_name;

// Fetch customers with additional data
$query = "
    SELECT 
        c.*,
        cc.category_name,
        COUNT(DISTINCT so.sales_order_id) as total_orders,
        COUNT(DISTINCT si.invoice_id) as total_invoices,
        -- COUNT(DISTINCT sr.sales_return_id) as total_returns, -- Table missing
        SUM(CASE WHEN so.status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
        SUM(CASE WHEN so.status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        SUM(CASE WHEN si.status = 'unpaid' THEN si.grand_total ELSE 0 END) as total_unpaid,
        SUM(CASE WHEN si.status = 'paid' THEN si.grand_total ELSE 0 END) as total_paid,
        u1.username as created_by_name,
        u2.username as updated_by_name
    FROM customers c
    LEFT JOIN customer_categories cc ON c.category_id = cc.category_id
    LEFT JOIN sales_orders so ON c.customer_id = so.customer_id
    LEFT JOIN invoices si ON c.customer_id = si.customer_id
    -- LEFT JOIN sales_returns sr ON c.customer_id = sr.customer_id -- Table missing
    LEFT JOIN users u1 ON c.created_by = u1.user_id
    LEFT JOIN users u2 ON c.updated_by = u2.user_id
    WHERE c.status != 'deleted'" . scopeFilterSqlNullable('project', 'c') . "
    GROUP BY c.customer_id
    ORDER BY c.customer_name ASC
";
$stmt = $pdo->query($query);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_customers = count($customers);
$active_customers = array_filter($customers, function($customer) {
    return $customer['status'] == 'active';
});
$inactive_customers = array_filter($customers, function($customer) {
    return $customer['status'] == 'inactive';
});
$suspended_customers = array_filter($customers, function($customer) {
    return $customer['status'] == 'suspended';
});
$blacklisted_customers = array_filter($customers, function($customer) {
    return $customer['status'] == 'blacklisted';
});

// Get customer categories
$categories = $pdo->query("SELECT * FROM customer_categories WHERE status = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// Get projects for linking
$projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid mt-4">
    <!-- Print Header (Visible only when printing) -->
    <div class="d-none d-print-block">
        <div style="text-align:center; padding: 20px 0; border-bottom: 3px solid #0d6efd; margin-bottom: 20px;">
            
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Official Customers Report</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('d/m/Y H:i') ?></p>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row mb-3 mb-md-4">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="flex-grow-1">
                    <h2 class="mb-0 fs-4 fs-md-3 fw-bold text-nowrap"><i class="bi bi-people"></i> Customer Management</h2>
                    <p class="text-muted mb-0 d-none d-md-block small mt-1">Manage your customers and client relationships</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($can_create_customers): ?>
                    <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm text-nowrap" style="border-radius: 6px;" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                        <i class="bi bi-plus-circle me-1"></i> Add New Customer
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="d-md-none mt-2 mb-0 opacity-25">
        </div>
    </div>

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

    <style>
        .custom-stat-card { background-color: #d1e7dd !important; }
    </style>

    <!-- Statistics Cards -->
    <div class="row mb-4" id="print-stats-cards">
        <div class="col-6 col-lg-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-people"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Customers</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-total-customers" style="font-size: 1.1rem;"><?= $total_customers ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Active</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-active-customers" style="font-size: 1.1rem;"><?= count($active_customers) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-pause-circle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Inactive</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-inactive-customers" style="font-size: 1.1rem;"><?= count($inactive_customers) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3 mb-3">
            <div class="card custom-stat-card shadow-sm border-0 h-100">
                <div class="card-body py-2 px-2 px-sm-3">
                    <div class="d-flex align-items-center h-100">
                        <div class="stat-icon-circle me-2 me-sm-3 d-none d-sm-flex">
                            <i class="bi bi-x-circle"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis; font-size: 0.65rem;">Blacklisted</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat-blacklisted-customers" style="font-size: 1.1rem;"><?= count($blacklisted_customers) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-funnel"></i> Filters & Search</h6>
                    <button class="btn btn-sm btn-outline-secondary border-0" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </div>
                <div class="collapse show" id="filterCollapse">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6 col-md-3">
                                <label for="statusFilter" class="form-label small fw-bold">Status</label>
                                <select class="form-select" id="statusFilter" name="statusFilter">
                                    <option value="">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="suspended">Suspended</option>
                                    <option value="blacklisted">Blacklisted</option>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="categoryFilter" class="form-label small fw-bold">Category</label>
                                <select class="form-select select2-static" id="categoryFilter" name="categoryFilter">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['category_id'] ?>"><?= safe_output($category['category_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="countryFilter" class="form-label small fw-bold">Country</label>
                                <input type="text" class="form-control" id="countryFilter" name="countryFilter" placeholder="Filter by country" autocomplete="off">
                            </div>
                            <div class="col-6 col-md-3">
                                <label for="cityFilter" class="form-label small fw-bold">City</label>
                                <input type="text" class="form-control" id="cityFilter" name="cityFilter" placeholder="Filter by city" autocomplete="off">
                            </div>
                            <div class="col-md-12 d-flex flex-column flex-sm-row justify-content-end pt-2 gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm px-3" onclick="clearFilters()">
                                    <i class="bi bi-arrow-clockwise"></i> Clear
                                </button>
                                <button type="button" class="btn btn-primary btn-sm px-4" onclick="applyFilters()">
                                    <i class="bi bi-filter"></i> Apply Filters
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div class="d-flex flex-wrap align-items-center gap-2 flex-grow-1">
                    
                    <!-- Action Buttons -->
                    <div class="d-flex flex-wrap shadow-sm bg-white" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; height: 38px;">
                            <i class="bi bi-clipboard text-info me-1"></i> Copy
                        </button>
                        <div class="bg-light d-none d-sm-block" style="width: 1px; height: 38px;"></div>
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="exportCustomers()" style="background: #fff; height: 38px;">
                            <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> CSV
                        </button>
                        <div class="bg-light d-none d-sm-block" style="width: 1px; height: 38px;"></div>
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" onclick="printTable()" style="background: #fff; height: 38px;">
                            <i class="bi bi-printer text-primary me-1"></i> Print
                        </button>
                        <?php if ($can_create_customers): ?>
                        <div class="bg-light d-none d-sm-block" style="width: 1px; height: 38px;"></div>
                        <button type="button" class="btn btn-white btn-sm fw-medium px-3 border-0" data-bs-toggle="modal" data-bs-target="#importCustomersModal" style="background: #fff; height: 38px;">
                            <i class="bi bi-upload text-info me-1"></i> Import
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Toolbar -->
                    <div class="d-flex align-items-center gap-2 flex-grow-1">
                        <div class="d-flex align-items-center bg-white shadow-sm px-2 py-1" style="border: 1px solid #dee2e6; border-radius: 8px; height: 38px;">
                            <span class="small text-muted me-2 text-nowrap">Show:</span>
                            <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 45px; background: transparent;" onchange="$('#customersTable').DataTable().page.len(this.value).draw();">
                                <option value="10">10</option>
                                <option value="25" selected>25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                                <option value="-1">All</option>
                            </select>
                        </div>
                        <div class="input-group input-group-sm shadow-sm flex-grow-1" style="border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6; height: 38px; min-width: 150px; max-width: 350px;">
                            <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" class="form-control border-0" id="searchCustomers" placeholder="Search customers..." onkeyup="quickSearch()">
                        </div>
                    </div>
                </div>
                <div class="d-none d-xl-block">
                    <span class="badge bg-success-soft text-success border border-success px-3 py-2 rounded-pill shadow-sm">
                        <i class="bi bi-check-circle-fill me-1"></i> <?= $total_customers ?> records
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Record Section -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="width: 100% !important;">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark">Customers Records</h5>
                    <div class="btn-group shadow-sm d-none d-md-flex" role="group">
                        <button type="button" class="btn btn-light btn-sm border" onclick="toggleView('table')" id="btn-table-view" title="Table View">
                            <i class="bi bi-table"></i>
                        </button>
                        <button type="button" class="btn btn-light btn-sm border" onclick="toggleView('card')" id="btn-card-view" title="Card View">
                            <i class="bi bi-grid"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="form-message" class="mx-3 mt-3"></div>
                    
                    <?php if (count($customers) > 0): ?>
                        <!-- Table View -->
                        <div id="tableView" class="table-responsive">
                            <table id="customersTable" class="table table-hover align-middle mb-0" style="width: 100% !important;">
                                <thead>
                                    <tr class="bg-light">
                                        <th rowspan="2" class="align-middle ps-3" style="width:55px;">S/NO</th>
                                        <th rowspan="2" class="align-middle ps-3">Code</th>
                                        <th rowspan="2" class="align-middle">Customer Name</th>
                                        <th rowspan="2" class="align-middle">Contact Info</th>
                                        <th rowspan="2" class="align-middle">Address</th>
                                        <th rowspan="2" class="align-middle">Category</th>
                                        <th colspan="3" class="text-center border-bottom">Activity Summary</th>
                                        <th rowspan="2" class="align-middle">Financial Balance</th>
                                        <th rowspan="2" class="align-middle text-center">Status</th>
                                        <th rowspan="2" class="align-middle text-end pe-3">Actions</th>
                                    </tr>
                                    <tr class="bg-light border-top">
                                        <th class="text-center small fw-bold text-uppercase py-1" style="font-size: 0.65rem; color: #666;">Orders</th>
                                        <th class="text-center small fw-bold text-uppercase py-1" style="font-size: 0.65rem; color: #666;">Invoices</th>
                                        <th class="text-center small fw-bold text-uppercase py-1" style="font-size: 0.65rem; color: #666;">Pending</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Loaded via AJAX -->
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Card View (Hidden by default) -->
                        <div id="cardView" class="row g-3 p-3 d-none">
                            <!-- Populated via DataTables drawCallback -->
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-people" style="font-size: 4rem; color: #6c757d;"></i>
                            <h4 class="mt-3 text-muted">No Customers Found</h4>
                            <p class="text-muted">Get started by adding your first customer.</p>
                            <?php if ($can_create_customers): ?>
                            <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                                <i class="bi bi-plus-circle"></i> Add Your First Customer
                            </button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Customer Modal -->
<?php if ($can_create_customers): ?>
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addCustomerModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Customer
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addCustomerForm" autocomplete="off">
                <div class="modal-body">
                    <div id="add-customer-message" class="mb-3"></div>
                    
                    <ul class="nav nav-tabs mb-3" id="customerTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="basic-tab" data-bs-toggle="tab" data-bs-target="#tab-basic" type="button" role="tab">Basic Info</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="contact-tab" data-bs-toggle="tab" data-bs-target="#tab-contact" type="button" role="tab">Contact Details</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="address-tab" data-bs-toggle="tab" data-bs-target="#tab-address" type="button" role="tab">Address</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="financial-tab" data-bs-toggle="tab" data-bs-target="#tab-financial" type="button" role="tab">Financial</button>
                        </li>
                    </ul>
                    
                    <div class="tab-content" id="customerTabContent">
                        <!-- Basic Information Tab -->
                        <div class="tab-pane fade show active" id="tab-basic" role="tabpanel">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="customer_name" class="form-label">Customer Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="customer_name" name="customer_name" required placeholder="Enter customer name">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="company_name" class="form-label">Company Name</label>
                                    <input type="text" class="form-control" id="company_name" name="company_name" placeholder="Company name (if different)">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="acronym" class="form-label">Acromy</label>
                                    <input type="text" class="form-control" id="acronym" name="acronym" placeholder="Enter acronym (e.g. BMS)">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="logo" class="form-label">Company Logo</label>
                                    <input type="file" class="form-control" id="logo" name="logo" accept="image/*">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="category_id" class="form-label">Category</label>
                                    <select class="form-select select2-static" id="category_id" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?= $category['category_id'] ?>"><?= safe_output($category['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="customer_type" class="form-label">Customer Type</label>
                                    <select class="form-select" id="customer_type" name="customer_type">
                                        <option value="individual">Individual</option>
                                        <option value="business" selected>Business</option>
                                        <option value="government">Government</option>
                                        <option value="ngo">NGO</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active" selected>Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="year" class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" id="year" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php 
                                        $current_year = date('Y');
                                        for ($y = $current_year; $y >= $current_year - 10; $y--) {
                                            echo "<option value=\"$y\">$y</option>";
                                        }
                                        ?>
                                        <option value="other">Other...</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="project_id" class="form-label">Linked Project (Optional)</label>
                                    <select class="form-select select2-static" id="project_id" name="project_id">
                                        <option value="">-- No Project --</option>
                                        <?php foreach ($projects as $project): ?>
                                        <option value="<?= $project['project_id'] ?>"><?= safe_output($project['project_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="credit_limit" class="form-label">Credit Limit</label>
                                    <input type="number" class="form-control" id="credit_limit" name="credit_limit" placeholder="0.00" step="0.01">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="add_description" class="form-label">Description</label>
                                    <textarea class="form-control" id="add_description" name="description" rows="2" placeholder="Customer description or notes"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contact Details Tab -->
                        <div class="tab-pane fade" id="tab-contact" role="tabpanel">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="contact_person" class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" id="contact_person" name="contact_person" placeholder="Primary contact person">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="contact_title" class="form-label">Contact Title</label>
                                    <input type="text" class="form-control" id="contact_title" name="contact_title" placeholder="e.g., Manager, Director">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="email" class="form-label">Contact Email</label>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="contact@example.com">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="company_email" class="form-label">Company Email</label>
                                    <input type="email" class="form-control" id="company_email" name="company_email" placeholder="company@example.com">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="phone" name="phone" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="mobile" class="form-label">Mobile Number</label>
                                    <input type="text" class="form-control" id="mobile" name="mobile" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="fax" class="form-label">Fax Number</label>
                                    <input type="text" class="form-control" id="fax" name="fax" placeholder="Fax number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="website" class="form-label">Website</label>
                                    <input type="url" class="form-control" id="website" name="website" placeholder="https://www.example.com">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Address Tab -->
                        <div class="tab-pane fade" id="tab-address" role="tabpanel">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="country" name="country" placeholder="Country" value="Tanzania">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="state" class="form-label">Region</label>
                                    <input type="text" class="form-control" id="state" name="state" placeholder="Region">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="city" class="form-label">District</label>
                                    <input type="text" class="form-control" id="city" name="city" placeholder="District">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="council" class="form-label">Council</label>
                                    <input type="text" class="form-control" id="council" name="council" placeholder="Council">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="ward" class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="ward" name="ward" placeholder="Ward">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="postal_code" class="form-label">Postal Code</label>
                                    <input type="text" class="form-control" id="postal_code" name="postal_code" placeholder="Postal code">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="add_address" class="form-label">Physical Address</label>
                                    <textarea class="form-control" id="add_address" name="address" rows="2" placeholder="Physical / street address"></textarea>
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="add_postal_address" class="form-label">Postal Address</label>
                                    <input type="text" class="form-control" id="add_postal_address" name="postal_address" placeholder="P.O. Box or postal address">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Financial Tab -->
                        <div class="tab-pane fade" id="tab-financial" role="tabpanel">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="tax_id" class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" id="tax_id" name="tax_id" placeholder="Tax Identification Number">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="vat_number" class="form-label">VAT Number</label>
                                    <input type="text" class="form-control" id="vat_number" name="vat_number" placeholder="VAT registration number">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="payment_terms" class="form-label">Payment Terms</label>
                                    <select class="form-select" id="payment_terms" name="payment_terms">
                                        <option value="">Select Terms</option>
                                        <option value="cash">Cash</option>
                                        <option value="7_days">7 Days</option>
                                        <option value="15_days">15 Days</option>
                                        <option value="30_days" selected>30 Days</option>
                                        <option value="60_days">60 Days</option>
                                        <option value="90_days">90 Days</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="currency" class="form-label">Currency</label>
                                    <select class="form-select" id="currency" name="currency">
                                        <option value="TZS" selected>Tanzanian Shilling (TZS)</option>
                                        <option value="USD">US Dollar (USD)</option>
                                        <option value="EUR">Euro (EUR)</option>
                                        <option value="GBP">British Pound (GBP)</option>
                                        <option value="KES">Kenyan Shilling (KES)</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="bank_account" class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" id="bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label for="add_bank_address" class="form-label">Bank Address</label>
                                    <textarea class="form-control" id="add_bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Import Customers Modal -->
<div class="modal fade" id="importCustomersModal" tabindex="-1" aria-labelledby="importCustomersModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="importCustomersModalLabel">
                    <i class="bi bi-upload"></i> Import Customers
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="importCustomersForm" enctype="multipart/form-data" autocomplete="off">
                <div class="modal-body">
                    <div id="import-message" class="mb-3"></div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Import Instructions:</h6>
                        <ul class="mb-0">
                            <li>Download the template file first</li>
                            <li>Fill in the customer data</li>
                            <li>Upload the completed file</li>
                            <li>File must be in CSV format</li>
                            <li>Maximum file size: 5MB</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_file" class="form-label">Select CSV File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="import_file" name="import_file" accept=".csv" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="import_action" class="form-label">Import Action</label>
                        <select class="form-select" id="import_action" name="import_action">
                            <option value="add_new">Add New Customers Only</option>
                            <option value="update_existing">Update Existing Customers</option>
                            <option value="add_update">Add New & Update Existing</option>
                        </select>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="skip_errors" name="skip_errors">
                        <label class="form-check-label" for="skip_errors">
                            Skip rows with errors and continue
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadTemplate()">
                        <i class="bi bi-download"></i> Download Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-upload"></i> Import Customers
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Quick Edit Customer Modal -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editCustomerModalLabel">
                        <i class="bi bi-pencil-square"></i> Edit Customer Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editCustomerForm" autocomplete="off">
                    <div class="modal-body">
                        <div id="edit-customer-message" class="mb-3"></div>
                        <input type="hidden" id="edit_customer_id" name="customer_id">
                        
                        <!-- Nav tabs for Edit -->
                        <ul class="nav nav-tabs mb-3" id="editCustomerTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="edit-basic-tab" data-bs-toggle="tab" data-bs-target="#edit-basic" type="button" role="tab">Basic Info</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="edit-contact-tab" data-bs-toggle="tab" data-bs-target="#edit-contact" type="button" role="tab">Contact Details</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="edit-address-tab" data-bs-toggle="tab" data-bs-target="#edit-address" type="button" role="tab">Address</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="edit-financial-tab" data-bs-toggle="tab" data-bs-target="#edit-financial" type="button" role="tab">Financial</button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="editCustomerTabsContent">
                            <!-- Basic Information Tab -->
                            <div class="tab-pane fade show active" id="edit-basic" role="tabpanel">
                                <div class="row">
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_customer_name" class="form-label">Customer Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="edit_customer_name" name="customer_name" required placeholder="Enter customer name">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_company_name" class="form-label">Company Name</label>
                                        <input type="text" class="form-control" id="edit_company_name" name="company_name" placeholder="Company name (if different)">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_acronym" class="form-label">Acromy</label>
                                        <input type="text" class="form-control" id="edit_acronym" name="acronym" placeholder="Enter acronym">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_logo" class="form-label">Company Logo</label>
                                        <input type="file" class="form-control" id="edit_logo" name="logo" accept="image/*">
                                        <div id="logo_container" class="mt-2" style="display:none;">
                                            <img id="edit_logo_preview" src="" alt="Logo" class="img-thumbnail" style="height: 50px;">
                                            <button type="button" class="btn btn-sm btn-danger remove-logo-btn" onclick="$('#edit_logo_preview').attr('src', ''); $('#logo_container').hide(); $('#remove_logo').val('1');"><i class="bi bi-trash"></i></button>
                                            <input type="hidden" id="remove_logo" name="remove_logo" value="0">
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_category_id" class="form-label">Category</label>
                                        <select class="form-select select2-static" id="edit_category_id" name="category_id">
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $category): ?>
                                            <option value="<?= $category['category_id'] ?>"><?= safe_output($category['category_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_customer_type" class="form-label">Customer Type</label>
                                        <select class="form-select" id="edit_customer_type" name="customer_type">
                                            <option value="individual">Individual</option>
                                            <option value="business">Business</option>
                                            <option value="government">Government</option>
                                            <option value="ngo">NGO</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_status" class="form-label">Status</label>
                                        <select class="form-select" id="edit_status" name="status">
                                            <option value="active">Active</option>
                                            <option value="inactive">Inactive</option>
                                            <option value="suspended">Suspended</option>
                                            <option value="blacklisted">Blacklisted</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_year" class="form-label">Year <span class="text-danger">*</span></label>
                                        <select class="form-select" id="edit_year" name="year" required>
                                            <option value="">Select Year</option>
                                            <?php 
                                            $current_year = date('Y');
                                            for ($y = $current_year; $y >= $current_year - 10; $y--) {
                                                echo "<option value=\"$y\">$y</option>";
                                            }
                                            ?>
                                            <option value="other">Other...</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_project_id" class="form-label">Linked Project (Optional)</label>
                                        <select class="form-select select2-static" id="edit_project_id" name="project_id">
                                            <option value="">-- No Project --</option>
                                            <?php foreach ($projects as $project): ?>
                                            <option value="<?= $project['project_id'] ?>"><?= safe_output($project['project_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_credit_limit" class="form-label">Credit Limit</label>
                                        <input type="number" class="form-control" id="edit_credit_limit" name="credit_limit" step="0.01" placeholder="0.00">
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label for="edit_description" class="form-label">Description</label>
                                        <textarea class="form-control" id="edit_description" name="description" rows="2" placeholder="Customer description or notes"></textarea>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Contact Details Tab -->
                            <div class="tab-pane fade" id="edit-contact" role="tabpanel">
                                <div class="row">
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_contact_person" class="form-label">Contact Person</label>
                                        <input type="text" class="form-control" id="edit_contact_person" name="contact_person" placeholder="Primary contact person">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_contact_title" class="form-label">Contact Title</label>
                                        <input type="text" class="form-control" id="edit_contact_title" name="contact_title" placeholder="e.g., Manager, Director">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_email" class="form-label">Contact Email</label>
                                        <input type="email" class="form-control" id="edit_email" name="email" placeholder="contact@example.com">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_company_email" class="form-label">Company Email</label>
                                        <input type="email" class="form-control" id="edit_company_email" name="company_email" placeholder="company@example.com">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_phone" class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" id="edit_phone" name="phone" placeholder="+255 123 456 789">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_mobile" class="form-label">Mobile Number</label>
                                        <input type="text" class="form-control" id="edit_mobile" name="mobile" placeholder="+255 123 456 789">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_fax" class="form-label">Fax Number</label>
                                        <input type="text" class="form-control" id="edit_fax" name="fax" placeholder="Fax number">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label for="edit_website" class="form-label">Website</label>
                                        <input type="url" class="form-control" id="edit_website" name="website" placeholder="https://www.example.com">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Address Tab -->
                            <div class="tab-pane fade" id="edit-address" role="tabpanel">
                                <div class="row">
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_country" class="form-label">Country</label>
                                        <input type="text" class="form-control" id="edit_country" name="country" placeholder="Country">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_state" class="form-label">Region</label>
                                        <input type="text" class="form-control" id="edit_state" name="state" placeholder="Region">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_city" class="form-label">District</label>
                                        <input type="text" class="form-control" id="edit_city" name="city" placeholder="District">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_council" class="form-label">Council</label>
                                        <input type="text" class="form-control" id="edit_council" name="council" placeholder="Council">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_ward" class="form-label">Ward</label>
                                        <input type="text" class="form-control" id="edit_ward" name="ward" placeholder="Ward">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_postal_code" class="form-label">Postal Code</label>
                                        <input type="text" class="form-control" id="edit_postal_code" name="postal_code" placeholder="Postal code">
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label for="edit_address" class="form-label">Physical Address</label>
                                        <textarea class="form-control" id="edit_address" name="address" rows="2" placeholder="Physical / street address"></textarea>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label for="edit_postal_address" class="form-label">Postal Address</label>
                                        <input type="text" class="form-control" id="edit_postal_address" name="postal_address" placeholder="P.O. Box or postal address">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Financial Tab -->
                            <div class="tab-pane fade" id="edit-financial" role="tabpanel">
                                <div class="row">
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_tax_id" class="form-label">Tax ID (TIN)</label>
                                        <input type="text" class="form-control" id="edit_tax_id" name="tax_id" placeholder="Tax Identification Number">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_vat_number" class="form-label">VAT Number</label>
                                        <input type="text" class="form-control" id="edit_vat_number" name="vat_number" placeholder="VAT registration number">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_payment_terms" class="form-label">Payment Terms</label>
                                        <select class="form-select" id="edit_payment_terms" name="payment_terms">
                                            <option value="">Select Terms</option>
                                            <option value="cash">Cash</option>
                                            <option value="7_days">7 Days</option>
                                            <option value="15_days">15 Days</option>
                                            <option value="30_days">30 Days</option>
                                            <option value="60_days">60 Days</option>
                                            <option value="90_days">90 Days</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_currency" class="form-label">Currency</label>
                                        <select class="form-select" id="edit_currency" name="currency">
                                            <option value="TZS">Tanzanian Shilling (TZS)</option>
                                            <option value="USD">US Dollar (USD)</option>
                                            <option value="EUR">Euro (EUR)</option>
                                            <option value="GBP">British Pound (GBP)</option>
                                            <option value="KES">Kenyan Shilling (KES)</option>
                                        </select>
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_bank_name" class="form-label">Bank Name</label>
                                        <input type="text" class="form-control" id="edit_bank_name" name="bank_name" placeholder="Bank name">
                                    </div>
                                    <div class="col-6 col-md-6 mb-3">
                                        <label for="edit_bank_account" class="form-label">Bank Account</label>
                                        <input type="text" class="form-control" id="edit_bank_account" name="bank_account" placeholder="Bank account number">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label for="edit_bank_address" class="form-label">Bank Address</label>
                                        <textarea class="form-control" id="edit_bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Update Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Include DataTables and other scripts -->
<!-- Custom scripts for this page -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable Safely
    if (!$.fn.DataTable.isDataTable('#customersTable')) {
        // Handle quick action: Add Customer
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('action') === 'add') {
            const addModal = new bootstrap.Modal(document.getElementById('addCustomerModal'));
            addModal.show();
        }

        let table = $('#customersTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '<?= buildUrl('api/get_customers_paged.php') ?>',
                data: function(d) {
                    d.status = $('#statusFilter').val();
                    d.category = $('#categoryFilter').val();
                    d.country = $('#countryFilter').val();
                    d.city = $('#cityFilter').val();
                },
                dataSrc: function(json) {
                    if (json.stats) {
                        $('#stat-total-customers').text(json.stats.total);
                        $('#stat-active-customers').text(json.stats.active);
                        $('#stat-inactive-customers').text(json.stats.inactive);
                        $('#stat-blacklisted-customers').text(json.stats.blacklisted);
                        $('#stat-total-records-badge').html('<i class="bi bi-check-circle-fill me-1"></i> ' + json.stats.total + ' records');
                    }
                    return json.data;
                }
            },
            columns: [
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center text-muted small fw-bold ps-3',
                    render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1,
                    createdCell: (td) => $(td).attr('data-label', 'S/NO')
                },
                { 
                    data: 'customer_code',
                    render: (data) => `<code>${safeOutput(data)}</code>`,
                    createdCell: (td) => $(td).attr('data-label', 'Code')
                },
                { 
                    data: 'customer_name',
                    render: (data) => `<strong>${safeOutput(data)}</strong>`,
                    createdCell: (td) => $(td).attr('data-label', 'Customer Name')
                },
                { 
                    data: null,
                    render: (data, t, row) => `
                        <div class="small text-muted" style="line-height: 1.1;">
                            ${row.contact_person ? '<span class="text-dark fw-bold">' + safeOutput(row.contact_person) + '</span><br>' : ''}
                            ${row.email ? '<i class="bi bi-envelope small"></i> ' + safeOutput(row.email.substring(0, 20)) + (row.email.length > 20 ? '...' : '') + '<br>' : ''}
                            ${row.phone || row.mobile ? '<i class="bi bi-telephone small"></i> ' + safeOutput(row.phone || row.mobile) : ''}
                        </div>
                    `,
                    createdCell: (td) => $(td).attr('data-label', 'Contact Info')
                },
                { 
                    data: 'address',
                    render: (data, t, row) => `
                        <div class="small text-muted" style="max-width: 150px; line-height: 1.2;">
                            ${data ? safeOutput(data.substring(0, 40)) + '<br>' : ''}
                            <span class="fw-bold text-dark">${safeOutput(row.city || '')}${row.country ? ', ' + safeOutput(row.country) : ''}</span>
                        </div>
                    `,
                    createdCell: (td) => $(td).attr('data-label', 'Address')
                },
                { 
                    data: 'category_name',
                    render: (data) => data ? `<span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 px-2">${safeOutput(data)}</span>` : `<span class="text-muted small">N/A</span>`,
                    createdCell: (td) => $(td).attr('data-label', 'Category')
                },
                { 
                    data: 'total_orders',
                    className: 'text-center',
                    render: (data) => `<span class="badge bg-primary shadow-sm" style="min-width: 30px; border-radius: 6px;">${data}</span>`,
                    createdCell: (td) => $(td).attr('data-label', 'Orders')
                },
                { 
                    data: 'total_invoices',
                    className: 'text-center',
                    render: (data) => `<span class="badge bg-info text-white shadow-sm" style="min-width: 30px; border-radius: 6px;">${data}</span>`,
                    createdCell: (td) => $(td).attr('data-label', 'Invoices')
                },
                { 
                    data: 'pending_orders',
                    className: 'text-center',
                    render: (data) => `<span class="badge bg-warning text-dark shadow-sm" style="min-width: 30px; border-radius: 6px;">${data}</span>`,
                    createdCell: (td) => $(td).attr('data-label', 'Pending')
                },
                { 
                    data: null,
                    render: (data, t, row) => `
                        <div class="p-1 px-2 rounded bg-light border border-opacity-10">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <small class="text-muted fw-bold" style="font-size: 0.6rem;">PAID</small>
                                <span class="text-success fw-bold" style="font-size: 0.85rem;">${formatCurrency(row.total_paid).replace('TZS ', '')}</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center gap-3 mt-1 border-top pt-1">
                                <small class="text-muted fw-bold" style="font-size: 0.6rem;">DUE</small>
                                <span class="text-danger fw-bold" style="font-size: 0.85rem;">${formatCurrency(row.total_unpaid).replace('TZS ', '')}</span>
                            </div>
                        </div>
                    `,
                    createdCell: (td) => $(td).attr('data-label', 'Financial Balance')
                },
                { 
                    data: 'status',
                    className: 'text-center',
                    render: (data) => `<span class="badge bg-${getStatusBadge(data)} px-3" style="border-radius: 20px;">${data.charAt(0).toUpperCase() + data.slice(1)}</span>`,
                    createdCell: (td) => $(td).attr('data-label', 'Status')
                },
                {
                    data: null,
                    orderable: false,
                    className: 'text-end',
                    createdCell: (td) => $(td).attr('data-label', 'Actions'),
                    render: (data, t, row) => {
                        let actions = `
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-gear"></i>
                                </button>
                                <ul class="dropdown-menu shadow border-0">
                                    <li><a class="dropdown-item" href="<?= getUrl('customers/view') ?>?id=${row.customer_id}"><i class="bi bi-eye text-info"></i> View Details</a></li>
                        `;
                        if (<?= json_encode($can_edit_customers) ?>) {
                           actions += `<li><a class="dropdown-item" href="#" onclick="editCustomer(${row.customer_id})"><i class="bi bi-pencil text-primary"></i> Edit Customer</a></li>`;
                        }
                        actions += `
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="<?= getUrl('sales_orders') ?>?customer=${row.customer_id}"><i class="bi bi-cart text-success"></i> View Orders</a></li>
                                    <li><a class="dropdown-item" href="<?= getUrl('invoices') ?>?customer=${row.customer_id}"><i class="bi bi-receipt text-warning"></i> View Invoices</a></li>
                        `;
                        if (<?= json_encode($company_type != 'microfinance' && $can_edit_customers) ?>) {
                            actions += `<li><a class="dropdown-item" href="<?= getUrl('sales_order_create') ?>?customer=${row.customer_id}"><i class="bi bi-file-plus text-primary"></i> New Order</a></li>`;
                        }
                        if (<?= json_encode($can_delete_customers) ?>) {
                           actions += `<li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${row.customer_id})"><i class="bi bi-trash"></i> Delete Customer</a></li>`;
                        }
                        return actions + `</ul></div>`;
                    }
                }
            ],
            language: {
                search: "Search customers:",
                lengthMenu: "Show _MENU_ customers per page",
                info: "Showing _START_ to _END_ of _TOTAL_ customers",
                paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
            },
            responsive: false,
            scrollX: true,
            autoWidth: false,
            dom: 'rtipB',
            buttons: [
                { extend: 'copyHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } },
                { extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }
            ],
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            order: [[1, 'asc']],
            drawCallback: function(settings) {
                // Update Card View
                const data = this.api().rows({ page: 'current' }).data();
                let cardHtml = '';
                data.each(function(customer) {
                    cardHtml += `
                        <div class="col-12">
                            <div class="card border-0 shadow-sm" style="border-radius:10px;">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <div class="fw-bold" style="font-size:0.9rem">${safeOutput(customer.customer_name)}</div>
                                            <small class="text-muted">${safeOutput(customer.customer_code)}</small>
                                        </div>
                                        <span class="badge bg-${getStatusBadge(customer.status)}" style="font-size:0.65rem">${customer.status}</span>
                                    </div>
                                    ${customer.email ? '<div class="small text-muted mb-1"><i class="bi bi-envelope me-1"></i>' + safeOutput(customer.email) + '</div>' : ''}
                                    <div class="d-flex justify-content-between small mb-0">
                                        <span class="text-muted">Orders: <strong>${customer.total_orders}</strong></span>
                                        <span class="text-muted">Unpaid: <strong class="text-danger">${formatCurrency(customer.total_unpaid)}</strong></span>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-top p-0" style="border-radius:0 0 10px 10px;">
                                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= getUrl('customers/details') ?>?id=${customer.customer_id}" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" title="View"><i class="bi bi-eye"></i></a>
                                        <?php if ($can_edit_customers): ?>
                                        <button class="btn btn-sm btn-outline-warning" onclick="editCustomer(${customer.customer_id})" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" title="Edit"><i class="bi bi-pencil"></i></button>
                                        <?php endif; ?>
                                        <?php if ($can_delete_customers): ?>
                                        <button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(${customer.customer_id})" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" title="Delete"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
                $('#cardView').html(cardHtml || '<div class="col-12 text-center py-5"><p class="text-muted">No customers found</p></div>');
            }
        });
    }

    // Toggle View preference
    const isMobileInit = window.innerWidth <= 767;
    const savedView = isMobileInit ? 'card' : (localStorage.getItem('customersView') || 'table');
    toggleView(savedView);
    $(window).on('resize', function() { toggleView(window.innerWidth <= 767 ? 'card' : (localStorage.getItem('customersView') || 'table')); });

    // Select2 for categoryFilter (outside modal)
    $('#categoryFilter').select2({
        theme: 'bootstrap-5',
        placeholder: 'All Categories',
        allowClear: true,
        width: '100%'
    });

    // Select2 for Add modal
    $('#addCustomerModal').on('shown.bs.modal', function() {
        $('#category_id, #project_id').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    theme: 'bootstrap-5',
                    dropdownParent: $('#addCustomerModal'),
                    placeholder: 'Select...',
                    allowClear: true,
                    width: '100%'
                });
            }
        });
    });

    // Select2 for Edit modal
    $('#editCustomerModal').on('shown.bs.modal', function() {
        $('#edit_category_id, #edit_project_id').each(function() {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({
                    theme: 'bootstrap-5',
                    dropdownParent: $('#editCustomerModal'),
                    placeholder: 'Select...',
                    allowClear: true,
                    width: '100%'
                });
            }
        });
    });

    // Form Submissions
    $('#addCustomerForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
        const formData = new FormData(this);
        $.ajax({
            url: '<?= buildUrl('api/add_customer.php') ?>',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: res.message || 'Customer added successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', res.message || 'Failed to add customer.', 'error');
                }
            },
            error: function(xhr) {
                console.error('AJAX Error:', xhr);
                Swal.fire('Error', 'A server error occurred while adding the customer.', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    $('#importCustomersForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Importing...');
        $.ajax({
            url: '<?= buildUrl('api/import_customers.php') ?>',
            type: 'POST',
            data: new FormData(this),
            processData: false, contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Imported!',
                        text: res.message || 'Customers imported successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', res.message || 'Import failed.', 'error');
                }
            },
            error: function(xhr) {
                console.error('AJAX Error:', xhr);
                Swal.fire('Error', 'A server error occurred during import.', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    $('#editCustomerForm').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Updating...');
        const formData = new FormData(this);
        $.ajax({
            url: '<?= buildUrl('api/process_edit_customer.php') ?>',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Updated!',
                        text: res.message || 'Customer updated successfully.',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', res.message || 'Update failed.', 'error');
                }
            },
            error: function(xhr) {
                console.error('AJAX Error:', xhr);
                Swal.fire('Error', 'A server error occurred while updating the customer.', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    // Modal Resets
    $('.modal').on('hidden.bs.modal', function() {
        $(this).find('form').each(function() { this.reset(); });
        $('#add-customer-message, #edit-customer-message, #import-message').html('');
    });
});

// Global Helper Functions
function applyFilters() {
    $('#customersTable').DataTable().ajax.reload();
}

function clearFilters() {
    $('#statusFilter, #categoryFilter, #countryFilter, #cityFilter, #searchCustomers').val('');
    $('#customersTable').DataTable().ajax.reload();
}

function getStatusBadge(status) {
    const badges = {
        'active': 'success',
        'inactive': 'secondary',
        'suspended': 'warning',
        'blacklisted': 'danger',
        'deleted': 'dark'
    };
    return badges[status] || 'secondary';
}

function safeOutput(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[m];
    });
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-TZ', { style: 'currency', currency: 'TZS' }).format(amount);
}

function editCustomer(customerId) {
    if (!customerId) return;
    console.log('Loading customer ID:', customerId);

    $.ajax({
        url: '<?= buildUrl('api/account/get_customer.php') ?>',
        type: 'GET',
        data: { id: customerId },
        dataType: 'json',
        success: function(response) {
            console.log('API Response:', response);
            if (response.success && response.data) {
                const c = response.data;
                const mapping = {
                    'customer_id': '#edit_customer_id',
                    'customer_name': '#edit_customer_name',
                    'company_name': '#edit_company_name',
                    'acronym': '#edit_acronym',
                    'category_id': '#edit_category_id',
                    'customer_type': '#edit_customer_type',
                    'status': '#edit_status',
                    'credit_limit': '#edit_credit_limit',
                    'notes': '#edit_description',
                    'contact_person': '#edit_contact_person',
                    'contact_title': '#edit_contact_title',
                    'email': '#edit_email',
                    'company_email': '#edit_company_email',
                    'phone': '#edit_phone',
                    'mobile': '#edit_mobile',
                    'fax': '#edit_fax',
                    'website': '#edit_website',
                    'address': '#edit_address',
                    'city': '#edit_city',
                    'state': '#edit_state',
                    'country': '#edit_country',
                    'council': '#edit_council',
                    'ward': '#edit_ward',
                    'postal_code': '#edit_postal_code',
                    'postal_address': '#edit_postal_address',
                    'tax_id': '#edit_tax_id',
                    'vat_number': '#edit_vat_number',
                    'payment_terms': '#edit_payment_terms',
                    'currency': '#edit_currency',
                    'bank_name': '#edit_bank_name',
                    'bank_account': '#edit_bank_account',
                    'bank_address': '#edit_bank_address',
                    'year': '#edit_year',
                    'project_id': '#edit_project_id'
                };

                // Handle Logo Preview
                if (c.logo_path) {
                   $('#edit_logo_preview').attr('src', '<?= buildUrl('') ?>' + c.logo_path);
                   $('#logo_container').show();
                   $('#remove_logo').val('0');
                } else {
                   $('#edit_logo_preview').attr('src', '');
                   $('#logo_container').hide();
                   $('#remove_logo').val('0');
                }

                for (const [key, selector] of Object.entries(mapping)) {
                    const value = (c[key] !== null && c[key] !== undefined) ? c[key] : '';
                    $(selector).val(value);
                }

                const modalEl = document.getElementById('editCustomerModal');
                bootstrap.Modal.getOrCreateInstance(modalEl).show();

                setTimeout(() => {
                    const firstTabTriggerEl = document.querySelector('#edit-basic-tab');
                    if (firstTabTriggerEl) {
                        bootstrap.Tab.getOrCreateInstance(firstTabTriggerEl).show();
                    }
                }, 200);
            } else {
                Swal.fire('Error', 'Error loading customer: ' + (response.message || 'Unknown error'), 'error');
            }
        },
        error: function(xhr) {
            console.error('Fetch Error:', xhr);
            Swal.fire('Error', 'Server error loading customer data.', 'error');
        }
    });
}

function updateStatus(customerId, status) {
    Swal.fire({
        title: 'Update Status?',
        text: `Are you sure you want to update this customer's status to ${status}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Update',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl('api/update_customer_status.php') ?>',
                type: 'POST',
                data: { customer_id: customerId, status: status },
                dataType: 'json',
                success: function(res) { 
                    if (res.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Updated!',
                            text: 'Status updated successfully.',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            $('#customersTable').DataTable().ajax.reload();
                        });
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'A server error occurred.', 'error');
                }
            });
        }
    });
}

function confirmDelete(customerId) {
    Swal.fire({
        title: 'Delete Customer?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= buildUrl('api/delete_customer.php') ?>',
                method: 'POST',
                data: { customer_id: customerId },
                dataType: 'json',
                success: function(res) { 
                    if (res.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Customer has been deleted.',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            $('#customersTable').DataTable().ajax.reload();
                        });
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'A server error occurred.', 'error');
                }
            });
        }
    });
}

function toggleView(viewType) {
    const isMobile = window.innerWidth <= 767;
    if (isMobile) viewType = 'card';
    if (viewType === 'table') {
        $('#tableView').removeClass('d-none');
        $('#cardView').addClass('d-none');
        $('#btn-table-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-card-view').removeClass('btn-primary text-white').addClass('btn-light');
    } else {
        $('#tableView').addClass('d-none');
        $('#cardView').removeClass('d-none');
        $('#btn-card-view').addClass('btn-primary text-white').removeClass('btn-light');
        $('#btn-table-view').removeClass('btn-primary text-white').addClass('btn-light');
    }
    if (!isMobile) localStorage.setItem('customersView', viewType);
}


function exportCustomers() {
    logReportAction('Exported Customers list', 'Exported customer records to Excel/CSV file');
    $('#customersTable').DataTable().button('.buttons-excel').trigger();
}

function downloadTemplate() {
    logReportAction('Downloaded Customer Template', 'Downloaded the CSV template for customer imports');
    const headers = [
        'customer_name', 'company_name', 'acronym', 'customer_type', 'contact_person', 'contact_title',
        'email', 'phone', 'mobile', 'fax', 'website', 'address', 'city',
        'state', 'country', 'postal_code', 'tax_id', 'vat_number',
        'payment_terms', 'credit_limit', 'currency', 'bank_name', 'bank_account',
        'bank_address', 'description', 'status'
    ];
    const csvContent = "data:text/csv;charset=utf-8," + headers.join(',') + "\nJohn Doe,ABC Company,JD,business,John Doe,Manager,john@abc.com,+255123456789,+255987654321,,http://abc.com,123 Street,Dar es Salaam,Dar es Salaam,Tanzania,12345,TIN123,VAT123,30_days,1000000,TZS,Example Bank,123456789,123 Bank Street,Good customer,active";
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "customers_import_template.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function quickSearch() {
    $('#customersTable').DataTable().search($('#searchCustomers').val()).draw();
}

function copyTable() {
    logReportAction('Copied Customers list', 'Copied customer records to clipboard');
    $('#customersTable').DataTable().button('.buttons-copy').trigger();
    Swal.fire({
        icon: 'success',
        title: 'Copied!',
        text: 'Table data copied to clipboard',
        timer: 1500,
        showConfirmButton: false,
        position: 'center'
    });
}

function printTable() {
    logReportAction('Printed Customers list', 'Generated a printed report of the customers list');
    const status = $('#statusFilter').val();
    const category = $('#categoryFilter').val();
    const search = $('#searchCustomers').val();
    
    const url = '<?= getUrl("print-customers") ?>?s=' + status + '&c=' + category + '&q=' + encodeURIComponent(search) + '&a=1';
    window.open(url, '_blank');
}



$(document).on('keyup', '#searchCustomers', function(e) {
    if (e.keyCode === 13) quickSearch();
});
</script>

<style>
    /* 📱 MOBILE RESPONSIVE REFINEMENTS */
    @media (max-width: 767px) {
        .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
        .container-fluid { padding-top: 0 !important; margin-top: 0 !important; }
        .btn { padding: 0.25rem 0.5rem !important; font-size: 0.75rem !important; }
        
        /* Sticky Page Heading */
        .row.mb-3.mb-md-4:first-child {
            position: sticky;
            top: 55px;
            z-index: 1050;
            background: #fff;
            margin-bottom: 10px !important;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .row.mb-3.mb-md-4:first-child h2 { font-size: 1.1rem !important; margin-bottom: 0 !important; }
        .row.mb-3.mb-md-4:first-child p { display: none; }

        /* Card-based Mobile Table */
        #customersTable, #customersTable thead, #customersTable tbody, #customersTable th, #customersTable td, #customersTable tr { 
            display: block; 
        }
        #customersTable thead { display: none; }
        #customersTable tr {
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            padding: 10px;
        }
        #customersTable td {
            border: none;
            position: relative;
            padding-left: 50% !important;
            text-align: right !important;
            min-height: 35px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            border-bottom: 1px solid #f8f9fa;
        }
        #customersTable td:last-child { border-bottom: none; }
        #customersTable td:before {
            content: attr(data-label);
            position: absolute;
            left: 15px;
            width: 45%;
            padding-right: 10px;
            white-space: nowrap;
            text-align: left;
            font-weight: 700;
            color: #6c757d;
            font-size: 0.75rem;
            text-transform: uppercase;
        }
        #customersTable td[data-label="Customer Name"] {
            padding-left: 15px !important;
            text-align: left !important;
            display: block;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 10px;
        }
        #customersTable td[data-label="Customer Name"]:before { display: none; }
        #customersTable td[data-label="Actions"] { justify-content: center; padding-left: 15px !important; }
        #customersTable td[data-label="Actions"]:before { display: none; }
    }
</style>



<?php
// Include the footer
include("footer.php");

// Flush the buffer
ob_end_flush();
?>