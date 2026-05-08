<?php
require_once HEADER_FILE;

// Get customer ID from URL
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch customer data with related information
$stmt = $pdo->prepare("
    SELECT 
        c.*,
        cc.category_name,
        p.project_name as linked_project_name
    FROM customers c
    LEFT JOIN customer_categories cc ON c.category_id = cc.category_id
    LEFT JOIN projects p ON c.project_id = p.project_id
    WHERE c.customer_id = ?
");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    die("Customer not found");
}

// Fetch customer's invoices directly
$inv_stmt = $pdo->prepare("
    SELECT i.*, so.order_number as linked_order_number 
    FROM invoices i 
    LEFT JOIN sales_orders so ON i.order_id = so.sales_order_id 
    WHERE i.customer_id = ? 
    ORDER BY i.invoice_date DESC, i.invoice_id DESC
");
$inv_stmt->execute([$customer_id]);
$customer_invoices = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate financial summary from invoices
$fin_total_billed = 0;
$fin_total_paid   = 0;
$fin_invoice_count = count($customer_invoices);
foreach ($customer_invoices as $inv) {
    $fin_total_billed += (float)$inv['grand_total'];
    $fin_total_paid   += (float)$inv['paid_amount'];
}
$fin_balance_due = $fin_total_billed - $fin_total_paid;

// Fetch sales orders for this customer directly - matching main menu logic
$so_stmt = $pdo->prepare("
    SELECT so.*, 
           (SELECT COUNT(*) FROM sales_order_items WHERE order_id = so.sales_order_id) as total_items,
           CASE 
                WHEN so.status = 'cancelled' THEN 'cancelled'
                WHEN so.status = 'completed' THEN 'completed'
                WHEN so.status = 'delivered' THEN 'delivered'
                WHEN so.total_delivered > 0 AND so.total_delivered < so.total_ordered THEN 'partially_delivered'
                WHEN so.status = 'approved' THEN 'approved'
                WHEN so.status = 'pending' THEN 'pending'
                ELSE 'draft'
            END as display_status
    FROM sales_orders so
    WHERE so.customer_id = ?
    ORDER BY so.order_date DESC, so.sales_order_id DESC
");
$so_stmt->execute([$customer_id]);
$customer_orders = $so_stmt->fetchAll(PDO::FETCH_ASSOC);
$fin_order_count = count($customer_orders);

// Fetch customer's loans with additional fields for payment calculations



// Determine if customer is individual or company
$isCompany = ($customer['customer_type'] === 'business');

// Format names
if ($isCompany && !empty($customer['company_name'])) {
    $customer_name = safe_output($customer['company_name']);
    $representative_name = safe_output($customer['customer_name']); 
} else {
    $customer_name = safe_output($customer['customer_name']);
}

// Check for business attachments (company only)
$companyAttachments = [];
if ($isCompany) {
    $attachmentFields = [
        'incorporation_cert_path' => 'Incorporation Certificate',
        'tin_cert_path' => 'TIN Certificate',
        'vat_cert_path' => 'VAT Certificate',
        'tax_clearance_path' => 'Tax Clearance Certificate',
        'business_license_path' => 'Business License',
        'memart_cert_path' => 'MEMART Certificate',
        'board_resolution_path' => 'Board Resolution',
        'application_letter_path' => 'Application Letter',
        'intro_letter_path' => 'Introduction Letter',
        'bank_statement_path' => 'Bank Statement',
        'financial_statement_path' => 'Financial Statement',
        'lease_agreement_path' => 'Lease Agreement'
    ];
    
    foreach ($attachmentFields as $field => $label) {
        if (!empty($customer[$field])) {
            $companyAttachments[] = [
                'label' => $label,
                'path' => $customer[$field]
            ];
        }
    }
}

// Check for business attachments (individual only)
$businessAttachments = [];
if (!$isCompany) {
    $attachmentFields = [
        'local_gov_letter_path' => 'Letter from Local Government',
        'brela_certificate_path' => 'BRELA Business Name Certificate',
        'tin_certificate_path' => 'Tax Payer Identification Number (TIN) Certificate',
        'tax_clearance_path' => 'Tax Clearance Certificate',
        'business_license_path' => 'Business License',
        'lease_agreement_path' => 'Lease Agreement/Proof of Ownership'
    ];
    
    foreach ($attachmentFields as $field => $label) {
        if (!empty($customer[$field])) {
            $businessAttachments[] = [
                'label' => $label,
                'path' => $customer[$field]
            ];
        }
    }
}

// Check for dynamic attachments
$dynamicAttachments = [];
for ($i = 1; $i <= 4; $i++) {
    $pathField = "other_attachment_{$i}_path";
    $labelField = "other_attachment_{$i}_label";
    
    if (!empty($customer[$pathField]) || !empty($customer[$labelField])) {
        $dynamicAttachments[] = [
            'label' => !empty($customer[$labelField]) ? $customer[$labelField] : "Additional Document {$i}",
            'path' => $customer[$pathField] ?? ''
        ];
    }
}
global $company_name, $company_logo;
?>

<div class="container-fluid mt-4 px-4">
    <!-- Print Header (Only visible when printing) -->
    <!-- Print-only Header -->
    <div class="d-none d-print-block text-center mb-4" style="margin-top: 0 !important; padding-top: 0 !important;">
        
        <h4 class="fw-bold text-dark text-uppercase">CUSTOMER INFORMATION REPORT</h4>
        <h5 class="text-muted"><?= safe_output($customer_name) ?> (ID: <?= $customer_id ?>)</h5>
        <div class="mt-2" style="border-top: 2px solid #0d6efd; width: 150px; margin: 0 auto;"></div>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('customers') ?>">Customers</a></li>
            <li class="breadcrumb-item active"><?= safe_output($customer_name) ?></li>
        </ol>
    </nav>

    <div class="row mb-3 mb-md-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-start flex-nowrap gap-2">
                <div>
                    <h2 class="mb-0 fs-4 fs-md-2 fw-bold"><i class="bi bi-<?= $isCompany ? 'building' : 'person-badge' ?>"></i> <?= $isCompany ? 'Company' : 'Customer' ?> View</h2>
                    <p class="text-muted mb-0 small mt-1 header-desc">
                        Detailed information for <?= safe_output($customer_name) ?> • Code: <code><?= safe_output($customer['customer_code'] ?? 'N/A') ?></code>
                    </p>
                </div>
                <!-- Desktop Actions (Hidden on mobile) -->
                <div class="d-none d-sm-flex gap-2 ms-auto pt-2 flex-shrink-0">
                    <a href="<?= getUrl('customers') ?>" class="btn btn-secondary btn-sm px-2 shadow-sm" title="Back to Customers">
                        <i class="bi bi-arrow-left"></i> Back
                    </a>
                    <button onclick="printDetails()" class="btn btn-info btn-sm px-2 text-white shadow-sm" title="Print Details">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <a href="<?= getUrl('customers/edit') ?>?id=<?= $customer_id ?>" class="btn btn-primary btn-sm px-2 shadow-sm" title="Edit Customer">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                </div>

                <!-- Mobile Actions (Dropdown) -->
                <div class="d-flex d-sm-none ms-auto pt-1 flex-shrink-0">
                    <div class="dropdown">
                        <button class="btn btn-primary btn-sm dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill me-1"></i> Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <li>
                                <a class="dropdown-item py-2" href="<?= getUrl('customers') ?>">
                                    <i class="bi bi-arrow-left text-secondary"></i> Back to Customers
                                </a>
                            </li>
                            <li>
                                <div class="dropdown-header small text-uppercase fw-bold text-muted pb-1">Print Options</div>
                                <button class="dropdown-item py-2" onclick="printDetails('portrait')">
                                    <i class="bi bi-file-earmark-person text-info"></i> Print Portrait
                                </button>
                                <button class="dropdown-item py-2" onclick="printDetails('landscape')">
                                    <i class="bi bi-file-earmark-person text-success" style="transform: rotate(90deg); display: inline-block;"></i> Print Landscape
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item py-2" href="<?= getUrl('customers/edit') ?>?id=<?= $customer_id ?>" onclick="return logEditClick()">
                                    <i class="bi bi-pencil text-primary"></i> Edit Customer
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <hr class="d-md-none mt-2 mb-0 opacity-25">
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= $_SESSION['success_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <script>
    $(document).ready(function() {
        // Log that the details page was viewed
        logReportAction('Viewed Customer Details', 'User viewed detailed information for customer #<?= $customer_id ?> (<?= addslashes($customer_name) ?>)');
        
        // Handle printing with logging and orientation
        window.printDetails = function(orientation) {
            logReportAction('Printed Customer Details', 'User generated a ' + orientation + ' printed report for customer #<?= $customer_id ?> (<?= addslashes($customer_name) ?>)');
            
            // Apply orientation class to body
            $('body').removeClass('print-portrait print-landscape').addClass('print-' + orientation);
            
            window.print();
        };

        // Handle edit click with logging
        window.logEditClick = function() {
            logReportAction('Initiated Customer Edit', 'User clicked edit button for customer #<?= $customer_id ?> (<?= addslashes($customer_name) ?>)');
            return true;
        };
    });
    </script>

    <div class="row">
        <!-- Left Column - Photo and Quick Info -->
        <div class="col-md-4 mb-4">
            <?php if (!empty($customer['logo_path']) && file_exists($customer['logo_path'])): ?>
            <div class="card mb-3 shadow-sm border-0 overflow-hidden">
                <style>
                    .logo-box {
                        height: 140px;
                        background: #fff;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        padding: 15px;
                        border: 1px dashed #e9ecef;
                    }
                    .logo-img {
                        max-width: 100%;
                        max-height: 100%;
                        object-fit: contain;
                        filter: drop-shadow(0 2px 4px rgba(0,0,0,0.05));
                    }
                </style>
                <div class="card-header bg-white border-bottom-0 pt-3 pb-1">
                    <h6 class="mb-0 fw-bold text-primary small text-uppercase" style="letter-spacing: 0.5px;">
                        <i class="bi bi-image me-1"></i> Company Logo
                    </h6>
                </div>
                <div class="card-body p-3">
                    <div class="logo-box rounded-3">
                        <img src="<?= getUrl($customer['logo_path']) ?>" 
                             class="logo-img" 
                             alt="Company Logo">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Customer/Company Photo Card -->
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-camera"></i> <?= $isCompany ? 'Company' : 'Customer' ?> Photo</h6>
                </div>
                <div class="card-body text-center">
                    <?php if (!empty($customer['photo_path']) && file_exists($customer['photo_path'])): ?>
                        <img src="<?= getUrl($customer['photo_path']) ?>" 
                             class="img-fluid rounded" 
                             style="max-height: 300px; width: auto;" 
                             alt="<?= $isCompany ? 'Company' : 'Customer' ?> Photo">
                    <?php else: ?>
                        <div class="text-muted py-4">
                            <i class="bi bi-<?= $isCompany ? 'building' : 'person-circle' ?>" style="font-size: 4rem;"></i>
                            <p class="mt-2 mb-0">No Photo Available</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Information Card -->
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-info-circle"></i> Quick Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless">
                        <tr>
                            <td><strong>Customer ID:</strong></td>
                            <td>#<?= safe_output($customer['customer_id']) ?></td>
                        </tr>
                        <tr>
                            <td><strong><?= $isCompany ? 'Company Name' : 'Name' ?>:</strong></td>
                            <td><?= safe_output($customer_name) ?></td>
                        </tr>
                        <?php if ($isCompany): ?>
                        <tr>
                            <td><strong>Representative:</strong></td>
                            <td><?= safe_output($representative_name) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Type:</strong></td>
                            <td>
                                <span class="badge bg-<?= $isCompany ? 'info' : 'primary' ?>">
                                    <?= $isCompany ? 'Company' : 'Individual' ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Phone:</strong></td>
                            <td><?= safe_output($customer['phone']) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Email:</strong></td>
                            <td><?= safe_output($customer['email']) ?></td>
                        </tr>
                        <tr>
                            <td><strong>Category:</strong></td>
                            <td><?= !empty($customer['category_name']) ? safe_output($customer['category_name']) : 'Uncategorized' ?></td>
                        </tr>
                        <tr>
                            <td><strong>Year:</strong></td>
                            <td><?= !empty($customer['year']) ? safe_output($customer['year']) : '<span class="text-muted">N/A</span>' ?></td>
                        </tr>
                        <tr>
                            <td><strong>Status:</strong></td>
                            <td>
                                <?php
                                $status_class = 'secondary';
                                switch($customer['status'] ?? 'active') {
                                    case 'active': $status_class = 'success'; break;
                                    case 'inactive': $status_class = 'warning'; break;
                                    case 'suspended': $status_class = 'danger'; break;
                                    case 'blacklisted': $status_class = 'dark'; break;
                                }
                                ?>
                                <span class="badge bg-<?= $status_class ?>">
                                    <?= ucfirst($customer['status'] ?? 'active') ?>
                                </span>
                            </td>
                        </tr>
                        <?php if (!$isCompany && !empty($customer['tax_id'])): ?>
                        <tr>
                            <td><strong>Tax ID (TIN):</strong></td>
                            <td><?= safe_output($customer['tax_id']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td><strong>Registered:</strong></td>
                            <td><?= date('M d, Y', strtotime($customer['created_at'])) ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right Column - Detailed Information -->
        <div class="col-md-8">
            <div id="customerMainContent">

                <!-- Financial Summary Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #0d6efd !important;">
                            <div class="card-body py-3">
                                <div class="d-flex align-items-center mb-1">
                                    <div class="rounded-circle bg-primary bg-opacity-10 p-2 me-2">
                                        <i class="bi bi-receipt text-primary"></i>
                                    </div>
                                    <span class="text-muted small fw-semibold">Total Billed</span>
                                </div>
                                <h5 class="mb-0 fw-bold text-dark"><?= number_format($fin_total_billed) ?></h5>
                                <small class="text-muted"><?= $fin_invoice_count ?> Invoice<?= $fin_invoice_count != 1 ? 's' : '' ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #198754 !important;">
                            <div class="card-body py-3">
                                <div class="d-flex align-items-center mb-1">
                                    <div class="rounded-circle bg-success bg-opacity-10 p-2 me-2">
                                        <i class="bi bi-check-circle text-success"></i>
                                    </div>
                                    <span class="text-muted small fw-semibold">Total Paid</span>
                                </div>
                                <h5 class="mb-0 fw-bold text-success"><?= number_format($fin_total_paid) ?></h5>
                                <small class="text-muted"><?= $fin_total_billed > 0 ? round(($fin_total_paid / $fin_total_billed) * 100) : 0 ?>% cleared</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid <?= $fin_balance_due > 0 ? '#dc3545' : '#198754' ?> !important;">
                            <div class="card-body py-3">
                                <div class="d-flex align-items-center mb-1">
                                    <div class="rounded-circle <?= $fin_balance_due > 0 ? 'bg-danger' : 'bg-success' ?> bg-opacity-10 p-2 me-2">
                                        <i class="bi bi-exclamation-circle <?= $fin_balance_due > 0 ? 'text-danger' : 'text-success' ?>"></i>
                                    </div>
                                    <span class="text-muted small fw-semibold">Balance Due</span>
                                </div>
                                <h5 class="mb-0 fw-bold <?= $fin_balance_due > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($fin_balance_due) ?></h5>
                                <small class="text-muted"><?= $fin_balance_due > 0 ? 'Outstanding amount' : 'Fully settled' ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #6f42c1 !important;">
                            <div class="card-body py-3">
                                <div class="d-flex align-items-center mb-1">
                                    <div class="rounded-circle bg-purple bg-opacity-10 p-2 me-2" style="background-color:#6f42c120">
                                        <i class="bi bi-cart-check" style="color:#6f42c1"></i>
                                    </div>
                                    <span class="text-muted small fw-semibold">Sales Orders</span>
                                </div>
                                <h5 class="mb-0 fw-bold text-dark"><?= $fin_order_count ?></h5>
                                <small class="text-muted">Total orders placed</small>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End Financial Summary Cards -->

            <?php if ($isCompany): ?>
            <!-- Company Information -->
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-building"></i> Company Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Company Name</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['company_name']) ?></p>
                        </div>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Company Email</label>
                            <p class="mb-0 fw-semibold fs-7 text-truncate"><?= !empty($customer['company_email']) ? safe_output($customer['company_email']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Phone Number</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['phone']) ?></p>
                        </div>
                        <?php if (!empty($customer['website'])): ?>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Website</label>
                            <p class="mb-0 fw-semibold fs-7 text-truncate">
                                <a href="<?= safe_output($customer['website']) ?>" target="_blank">
                                    <?= safe_output($customer['website']) ?>
                                </a>
                            </p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($customer['registration_number'])): ?>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Reg Number</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['registration_number']) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($customer['tin_number'])): ?>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">TIN Number</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['tin_number']) ?></p>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($customer['vat_number'])): ?>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">VAT Number</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['vat_number']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Representative/Personal Information -->
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-<?= $isCompany ? 'person-badge' : 'person-lines-fill' ?>"></i> <?= $isCompany ? 'Company Representative' : 'Personal' ?> Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Full Name</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['customer_name']) ?></p>
                        </div>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Title</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['contact_title']) ? safe_output($customer['contact_title']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Contact Email</label>
                            <p class="mb-0 fw-semibold fs-7 text-truncate"><?= !empty($customer['email']) ? safe_output($customer['email']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                         <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Mobile</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['mobile']) ?></p>
                        </div>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Phone</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['phone']) ?></p>
                        </div>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Fax</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['fax']) ? safe_output($customer['fax']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-12 col-md-12 mb-3">
                            <label class="form-label text-muted small mb-1">Linked Project</label>
                            <p class="mb-0 fw-semibold fs-7">
                                <?php if (!empty($customer['linked_project_name'])): ?>
                                    <span class="badge bg-primary-soft text-primary border border-primary">
                                        <i class="bi bi-diagram-3 me-1"></i> <?= safe_output($customer['linked_project_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">General / No Project</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Notes -->
            <?php if (!empty($customer['notes'])): ?>
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-journal-text"></i> Additional Notes</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0"><?= nl2br(safe_output($customer['notes'])) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Address Information -->
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-geo-alt"></i> Address Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-12 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Postal Address</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['address']) ? safe_output($customer['address']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-6 col-md-3 mb-3">
                            <label class="form-label text-muted small mb-1">City</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['city']) ? safe_output($customer['city']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-6 col-md-3 mb-3">
                            <label class="form-label text-muted small mb-1">State/Region</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['state']) ? safe_output($customer['state']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-6 col-md-3 mb-3">
                            <label class="form-label text-muted small mb-1">Country</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['country']) ? safe_output($customer['country']) : 'Tanzania' ?></p>
                        </div>
                        <div class="col-6 col-md-3 mb-3">
                            <label class="form-label text-muted small mb-1">Postal Code</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['postal_code']) ? safe_output($customer['postal_code']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial & Banking Information -->
            <div class="card mb-4 financial-details-card">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-cash-stack"></i> Financial & Banking Details</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 col-md-4 mb-3">
                            <label class="form-label text-muted small mb-1">Credit Limit</label>
                            <p class="mb-0 fw-semibold text-primary fs-7"><?= number_format($customer['credit_limit'] ?? 0, 2) ?> <?= safe_output($customer['currency'] ?? 'TZS') ?></p>
                        </div>
                        <div class="col-6 col-md-4 mb-3">
                            <label class="form-label text-muted small mb-1">Payment Terms</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['payment_terms']) ? ucwords(str_replace('_', ' ', $customer['payment_terms'])) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-6 col-md-4 mb-3">
                            <label class="form-label text-muted small mb-1">Currency</label>
                            <p class="mb-0 fw-semibold fs-7"><?= safe_output($customer['currency'] ?? 'TZS') ?></p>
                        </div>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Bank Name</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['bank_name']) ? safe_output($customer['bank_name']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Bank Account</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['bank_account']) ? safe_output($customer['bank_account']) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label text-muted small mb-1">Bank Address</label>
                            <p class="mb-0 fw-semibold fs-7"><?= !empty($customer['bank_address']) ? nl2br(safe_output($customer['bank_address'])) : '<span class="text-muted">N/A</span>' ?></p>
                        </div>
                    </div>
                </div>
            </div>





            <?php if ($isCompany && count($companyAttachments) > 0): ?>
            <!-- Company Documents -->
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-folder-check"></i> Company Documents</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($companyAttachments as $attachment): ?>
                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3 attachment-card">
                                <h6 class="mb-2"><?= safe_output($attachment['label']) ?></h6>
                                <?php if (!empty($attachment['path']) && file_exists($attachment['path'])): ?>
                                    <?php
                                    $file_ext = pathinfo($attachment['path'], PATHINFO_EXTENSION);
                                    if (in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                        <img src="<?= getUrl($attachment['path']) ?>" 
                                             class="img-fluid rounded border mb-2" 
                                             style="max-height: 150px; width: auto;" 
                                             alt="<?= safe_output($attachment['label']) ?>">
                                    <?php elseif (strtolower($file_ext) === 'pdf'): ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">PDF Document</p>
                                        </div>
                                    <?php elseif (in_array(strtolower($file_ext), ['doc', 'docx'])): ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark-word text-primary" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">Word Document</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">Document</p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="text-center mt-2">
                                        <a href="<?= getUrl($attachment['path']) ?>" 
                                           target="_blank" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">File not found</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$isCompany && count($businessAttachments) > 0): ?>
            <!-- Business Attachments (for individuals) -->
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-paperclip"></i> Business Attachments</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($businessAttachments as $attachment): ?>
                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3 attachment-card">
                                <h6 class="mb-2"><?= safe_output($attachment['label']) ?></h6>
                                <?php if (!empty($attachment['path']) && file_exists($attachment['path'])): ?>
                                    <?php
                                    $file_ext = pathinfo($attachment['path'], PATHINFO_EXTENSION);
                                    if (in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                        <img src="<?= getUrl($attachment['path']) ?>" 
                                             class="img-fluid rounded border mb-2" 
                                             style="max-height: 150px; width: auto;" 
                                             alt="<?= safe_output($attachment['label']) ?>">
                                    <?php elseif (strtolower($file_ext) === 'pdf'): ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">PDF Document</p>
                                        </div>
                                    <?php elseif (in_array(strtolower($file_ext), ['doc', 'docx'])): ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark-word text-primary" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">Word Document</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">Document</p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="text-center mt-2">
                                        <a href="<?= getUrl($attachment['path']) ?>" 
                                           target="_blank" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">File not found</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Other Attachments (dynamic) -->
            <?php if (count($dynamicAttachments) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-files"></i> Additional Attachments</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($dynamicAttachments as $attachment): ?>
                        <div class="col-md-6 mb-3">
                            <div class="border rounded p-3 attachment-card">
                                <h6 class="mb-2"><?= safe_output($attachment['label']) ?></h6>
                                <?php if (!empty($attachment['path']) && file_exists($attachment['path'])): ?>
                                    <?php
                                    $file_ext = pathinfo($attachment['path'], PATHINFO_EXTENSION);
                                    if (in_array(strtolower($file_ext), ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                        <img src="<?= getUrl($attachment['path']) ?>" 
                                             class="img-fluid rounded border mb-2" 
                                             style="max-height: 150px; width: auto;" 
                                             alt="<?= safe_output($attachment['label']) ?>">
                                    <?php elseif (strtolower($file_ext) === 'pdf'): ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark-pdf text-danger" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">PDF Document</p>
                                        </div>
                                    <?php elseif (in_array(strtolower($file_ext), ['doc', 'docx'])): ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark-word text-primary" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">Word Document</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center">
                                            <i class="bi bi-file-earmark" style="font-size: 2.5rem;"></i>
                                            <p class="small mb-1">Document</p>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="text-center mt-2">
                                        <a href="<?= getUrl($attachment['path']) ?>" 
                                           target="_blank" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">No file attached</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Sales Order History -->
            <div class="card border-0 shadow-sm mb-4 print-page-break">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-primary">
                        <i class="bi bi-cart-check me-2"></i> Sales Order History 
                        <span class="badge bg-primary ms-2"><?= count($customer_orders) ?> Records</span>
                    </h6>
                    <a href="<?= getUrl('sales/create_order') ?>?customer=<?= $customer_id ?>" class="btn btn-primary btn-sm rounded-pill">
                        <i class="bi bi-plus-circle me-1"></i> Create New Order
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3">Order #</th>
                                    <th>Date</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-center">Items</th>
                                    <th>Status</th>
                                    <th class="text-end pe-3 d-print-none">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($customer_orders)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">No sales orders found for this customer.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($customer_orders as $order):
                                        $os = strtolower($order['display_status'] ?? 'pending');
                                        $ob = 'secondary';
                                        if ($os == 'completed' || $os == 'delivered') { $ob = 'success'; }
                                        elseif ($os == 'processing' || $os == 'approved') { $ob = 'primary'; }
                                        elseif ($os == 'pending') { $ob = 'warning'; }
                                        elseif ($os == 'cancelled') { $ob = 'danger'; }
                                        elseif ($os == 'partially_delivered') { $ob = 'info'; }
                                    ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-primary"><?= safe_output($order['order_number']) ?></td>
                                            <td><?= format_date($order['order_date']) ?></td>
                                            <td class="text-end fw-bold"><?= number_format((float)$order['grand_total']) ?> <?= safe_output($order['currency'] ?? '') ?></td>
                                            <td class="text-center"><span class="badge bg-light text-dark border"><?= (int)$order['total_items'] ?> items</span></td>
                                            <td><span class="badge bg-<?= $ob ?>"><?= strtoupper(str_replace('_',' ',$os)) ?></span></td>
                                            <td class="text-end pe-3 d-print-none">
                                                <a href="<?= getUrl('sales_order_view') ?>?id=<?= $order['sales_order_id'] ?>" class="btn btn-sm btn-outline-primary py-0">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Invoice & Payment History -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-primary">
                        <i class="bi bi-file-earmark-text me-2"></i> Invoice & Payment History
                        <span class="badge bg-success ms-2"><?= count($customer_invoices) ?> Records</span>
                    </h6>
                    <a href="<?= getUrl('invoice/create_invoice') ?>?customer=<?= $customer_id ?>" class="btn btn-success btn-sm rounded-pill">
                        <i class="bi bi-plus-circle me-1"></i> New Invoice
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3">Invoice #</th>
                                    <th>Date</th>
                                    <th class="text-end">Total Amount</th>
                                    <th class="text-end">Paid</th>
                                    <th class="text-end">Balance</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end pe-3 d-print-none">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($customer_invoices)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">No invoices found for this customer.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($customer_invoices as $inv): 
                                        $balance = (float)$inv['grand_total'] - (float)$inv['paid_amount'];
                                        $status = strtolower($inv['status']);
                                        $badge = 'secondary';
                                        
                                        if ($status == 'paid' || $balance <= 0) {
                                            $badge = 'success'; $status = 'PAID';
                                        } elseif ($balance > 0 && (float)$inv['paid_amount'] > 0) {
                                            $badge = 'info'; $status = 'PARTIAL';
                                        } elseif (strtotime($inv['due_date']) < time() && $balance > 0) {
                                            $badge = 'danger'; $status = 'OVERDUE';
                                        } elseif ($status == 'sent') {
                                            $badge = 'primary'; $status = 'SENT';
                                        }
                                    ?>
                                        <tr>
                                            <td class="ps-3 fw-bold text-dark"><?= safe_output($inv['invoice_number']) ?></td>
                                            <td><?= format_date($inv['invoice_date']) ?></td>
                                            <td class="text-end fw-bold"><?= format_number($inv['grand_total']) ?></td>
                                            <td class="text-end text-success"><?= format_number($inv['paid_amount']) ?></td>
                                            <td class="text-end <?= $balance > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= format_number($balance) ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-<?= $badge ?>"><?= $status ?></span>
                                            </td>
                                            <td class="text-end pe-3 d-print-none">
                                                <a href="<?= getUrl('invoice/view_invoice') ?>?id=<?= $inv['invoice_id'] ?>" class="btn btn-sm btn-outline-success py-0">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="card">
                <div class="card-header bg-light border-bottom">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-clock-history"></i> System Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Date Created</label>
                            <p class="mb-0 fw-semibold fs-7"><?= date('M d, Y', strtotime($customer['created_at'])) ?></p>
                        </div>
                        <?php if (!empty($customer['updated_at'])): ?>
                        <div class="col-6 col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Last Updated</label>
                            <p class="mb-0 fw-semibold fs-7"><?= date('M d, Y', strtotime($customer['updated_at'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div> <!-- End customerMainContent -->
        </div> <!-- End col-md-8 -->
    </div> <!-- End outer row -->
</div> <!-- End container-fluid -->
<!-- Include Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">

<style>
.fs-7 {
    font-size: 0.8rem !important;
}

.card {
    border: 1px solid rgba(0,0,0,.125);
    box-shadow: 0 2px 4px rgba(0,0,0,.05);
    border-radius: 8px;
    overflow: hidden;
}

.card-header {
    background-color: #f8f9fa !important;
    padding: 12px 15px !important;
}

.card-header h6 {
    font-weight: 700 !important;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
}

.card-body {
    padding: 20px !important;
}

.table-sm td, .table-sm th {
    padding: 8px !important;
}

.badge {
    padding: 5px 10px;
    font-weight: 500;
}


@media print {
    /* ===== PAGE SETUP & ORIENTATION ===== */
    body.print-portrait {
        @page { size: A4 portrait; margin: 10mm !important; }
    }
    body.print-landscape {
        @page { size: A4 landscape; margin: 5mm !important; }
    }
    
    /* Force visibility and allow natural flow */
    *, *:before, *:after {
        overflow: visible !important;
        height: auto !important;
        min-height: 0 !important;
    }
    
    /* Ensure content columns can break if needed to fit first page */
    .row > .col-md-4, .row > .col-md-8 {
        page-break-inside: auto !important;
        break-inside: auto !important;
        float: left !important;
        display: block !important;
    }
    
    /* Avoid data being hidden by footer and provide safe margins */
    body {
        padding: 0 !important;
        margin: 0 !important;
        background: white !important;
        display: block !important;
    }
    
    .container-fluid, #customerMainContent {
        display: block !important;
        page-break-inside: auto !important;
        break-inside: auto !important;
    }
    
    .bms-print-footer {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        height: 40px !important;
        background: white !important;
        border-top: 0.5pt solid #eee !important;
        font-size: 7pt !important;
        display: flex !important;
        align-items: center;
        justify-content: space-between;
        padding: 0 10mm !important;
    }




    /* ===== BASE ===== */
    html, body {
        background: white !important;
        font-family: 'Inter', Arial, sans-serif !important;
        font-size: 9pt !important;
        color: #111 !important;
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
    }

    /* ===== STRIP ALL CONTAINERS ===== */
    .container-fluid,
    .container,
    .px-4,
    .mt-4 {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        margin-top: 0 !important;
        padding-top: 0 !important;
    }

    /* ===== OUTER ROW: 2-column layout — allow page breaks ===== */
    .row:not(.d-print-none) {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    /* ===== OUTER LEFT SIDEBAR: Photo + Quick Info (25%) ===== */
    .row > .col-md-4 {
        float: left !important;
        width: 25% !important;
        padding: 0 6pt 0 0 !important;
        box-sizing: border-box !important;
    }

    /* ===== OUTER RIGHT DETAILS COLUMN (75%) ===== */
    .row > .col-md-8 {
        float: left !important;
        width: 75% !important;
        padding: 0 0 0 6pt !important;
        box-sizing: border-box !important;
    }

    /* Clearfix for the block row */
    .row:not(.d-print-none):after {
        content: "";
        display: table;
        clear: both;
    }

    /* ===== INNER COLUMNS (inside cards) ===== */
    .col-6, .col-md-6, .col-md-4, .col-md-3 {
        float: left !important;
        box-sizing: border-box !important;
    }
    .col-6, .col-md-6 { width: 50% !important; padding: 0 3pt !important; }
    .col-md-4 { width: 33.33% !important; padding: 0 3pt !important; }
    .col-md-3 { width: 25% !important; padding: 0 3pt !important; }
    .col-12 { width: 100% !important; float: left !important; }

    /* ===== mb-4/mb-3 spacing adjustments ===== */
    .mb-4 { margin-bottom: 6pt !important; }
    .mb-3 { margin-bottom: 5pt !important; }

    /* ===== CARDS: allow breaking between pages ===== */
    .card {
        border: none !important;
        box-shadow: none !important;
        margin-bottom: 7pt !important;
        border-radius: 0 !important;
        overflow: visible !important;
        width: 100% !important;
        page-break-inside: auto !important;
        break-inside: auto !important;
    }

    /* ===== CARD HEADERS: blue underline style ===== */
    .card-header {
        background: none !important;
        border: none !important;
        border-bottom: 1.5pt solid #0d6efd !important;
        padding: 2pt 0 2pt 0 !important;
        margin-bottom: 4pt !important;
    }
    .card-header h6 {
        font-size: 8pt !important;
        font-weight: 800 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
        color: #0d6efd !important;
        margin: 0 !important;
    }

    /* ===== CARD BODY ===== */
    .card-body {
        padding: 4pt 0 0 0 !important;
    }

    /* ===== FIELD LABELS (e.g. "Company Name") ===== */
    .form-label,
    label.form-label {
        font-size: 6.5pt !important;
        color: #777 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.3px !important;
        margin-bottom: 1pt !important;
        display: block !important;
    }

    .header-desc {
        font-size: 0.7rem !important;
        line-height: 1.3 !important;
        margin-top: 2px !important;
    }

    /* ===== FIELD VALUES ===== */
    .fw-semibold,
    p.fw-semibold {
        font-size: 9pt !important;
        font-weight: 600 !important;
        color: #111 !important;
        margin-bottom: 0 !important;
        word-break: break-word !important;
        line-height: 1.3 !important;
    }

    /* ===== TABLES ===== */
    .table, table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 8.5pt !important;
    }
    .table td, .table th {
        padding: 3pt 4pt !important;
        border-bottom: 0.5pt solid #ddd !important;
        word-break: break-word !important;
    }
    .table-borderless td,
    .table-borderless th {
        border: none !important;
        padding: 2pt 3pt !important;
    }

    /* ===== BADGES ===== */
    .badge {
        border: 0.75pt solid #888 !important;
        background: transparent !important;
        color: #111 !important;
        font-weight: 700 !important;
        font-size: 7pt !important;
        padding: 1pt 4pt !important;
    }

    /* ===== PHOTO ===== */
    .col-md-4 .card-body img {
        max-height: 80pt !important;
        width: auto !important;
        display: block !important;
        margin: 0 auto !important;
    }

    /* ===== TEXT COLORS ===== */
    .text-muted  { color: #666 !important; }
    .text-primary { color: #0d6efd !important; }
    .text-success { color: #198754 !important; }
    .text-danger  { color: #dc3545 !important; }
    .text-dark    { color: #111 !important; }

    /* ===== TAB CONTENT (force all tabs to show) ===== */
    .tab-content > .tab-pane {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
    }

    /* ===== ATTACHMENT CARDS ===== */
    .attachment-card {
        border: 0.5pt solid #ccc !important;
        padding: 4pt !important;
        margin-bottom: 4pt !important;
    }

    /* ===== PAGE BREAKS ===== */
    .print-page-break {
        page-break-before: always !important;
        margin-top: 10mm !important;
    }

    /* Move Financial Details to next page ONLY in landscape */
    body.print-landscape .financial-details-card {
        page-break-before: always !important;
        margin-top: 10mm !important;
    }

    /* ===== HIDE NON-PRINTABLE ELEMENTS (FINAL OVERRIDE) ===== */
    .btn, .breadcrumb, .alert, .navbar, footer,
    .d-print-none, nav, .card-header .btn,
    .dropdown, .sidebar, .d-print-none * {
        display: none !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
}
</style>

<?php
include("footer.php");
?>