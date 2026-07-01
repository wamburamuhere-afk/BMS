<?php
require_once HEADER_FILE;

// Phase 5a — enforce permission on customer detail view
autoEnforcePermission('customer_details');

// Get customer ID from URL
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

assertScopeForRecordHtml('customers', 'customer_id', $customer_id);

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
// Data needed by the edit modal
$can_edit_customers  = canEdit('customers');
$can_delete_invoices = canDelete('invoices');
$categories = $pdo->query("SELECT * FROM customer_categories WHERE status = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$projects   = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

$can_create_lpos = canCreate('lpo');
$can_edit_lpos   = canEdit('lpo');
$can_delete_lpos = canDelete('lpo');

$customer_lpos    = [];
$lpo_total_amount = 0;
$lpo_open_count   = 0;
try {
    $lpo_stmt = $pdo->prepare("
        SELECT * FROM customer_lpos
        WHERE customer_id = ? AND status != 'deleted'
        ORDER BY issue_date DESC, lpo_id DESC
    ");
    $lpo_stmt->execute([$customer_id]);
    $customer_lpos = $lpo_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($customer_lpos as $lpo_row) {
        $lpo_total_amount += (float)$lpo_row['amount'];
        if ($lpo_row['status'] === 'open') $lpo_open_count++;
    }
} catch (PDOException $e) {
    // table may not exist yet — migration pending
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
                    <?php if ($can_edit_customers): ?>
                    <button type="button" class="btn btn-primary btn-sm px-2 shadow-sm" onclick="editCustomer(<?= $customer_id ?>)" title="Edit Customer">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <?php endif; ?>
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
                                <?php if ($can_edit_customers): ?>
                                <a class="dropdown-item py-2" href="#" onclick="editCustomer(<?= $customer_id ?>)">
                                    <i class="bi bi-pencil text-primary"></i> Edit Customer
                                </a>
                                <?php endif; ?>
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

            <!-- Section Tabs -->
            <ul class="nav nav-pills flex-nowrap overflow-auto gap-1 mb-3 pb-1 no-print" id="customerDetailTabs" role="tablist">
                <li class="nav-item flex-shrink-0" role="presentation">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#pane-orders" type="button" role="tab"><i class="bi bi-cart-check me-1"></i> Sales Orders</button>
                </li>
                <li class="nav-item flex-shrink-0" role="presentation">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-invoices" type="button" role="tab"><i class="bi bi-file-earmark-text me-1"></i> Invoices</button>
                </li>
                <?php if (!empty($customer_lpos) || $can_create_lpos): ?>
                <li class="nav-item flex-shrink-0" role="presentation">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-lpos" type="button" role="tab"><i class="bi bi-file-earmark-check me-1"></i> LPOs</button>
                </li>
                <?php endif; ?>
                <li class="nav-item flex-shrink-0" role="presentation">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#pane-sysinfo" type="button" role="tab"><i class="bi bi-clock-history me-1"></i> System Info</button>
                </li>
            </ul>
            <div class="tab-content" id="customerDetailTabContent">

            <div class="tab-pane fade show active" id="pane-orders" role="tabpanel">
            <!-- Sales Order History -->
            <div class="card border-0 shadow-sm mb-4 print-page-break">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-primary">
                        <i class="bi bi-cart-check me-2"></i> Sales Order History
                        <span class="badge bg-primary ms-2"><?= count($customer_orders) ?> Records</span>
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?= getUrl('sales_order_create') ?>?customer=<?= $customer_id ?>" class="btn btn-primary btn-sm rounded-pill">
                            <i class="bi bi-plus-circle me-1"></i> Create New Order
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="ordersTableView">
                    <div class="table-responsive">
                        <table id="customerOrdersTable" class="table table-hover align-middle mb-0">
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
                            </tbody>
                        </table>
                    </div>
                    </div><!-- #ordersTableView -->
                    <div id="ordersCardGrid" class="row g-3 px-2 px-md-0 d-none mb-4"></div>
                </div>
            </div>
            </div><!-- #pane-orders -->

            <div class="tab-pane fade" id="pane-invoices" role="tabpanel">
            <!-- Invoice & Payment History -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-primary">
                        <i class="bi bi-file-earmark-text me-2"></i> Invoice & Payment History
                        <span class="badge bg-success ms-2"><?= count($customer_invoices) ?> Records</span>
                    </h6>
                    <div class="d-flex align-items-center gap-2">
                        <a href="<?= getUrl('invoice_create') ?>?customer=<?= $customer_id ?>" class="btn btn-success btn-sm rounded-pill">
                            <i class="bi bi-plus-circle me-1"></i> New Invoice
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div id="invoicesTableView">
                    <div class="table-responsive">
                        <table id="customerInvoicesTable" class="table table-hover align-middle mb-0">
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
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-gear-fill me-1"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                    <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('invoice_view') ?>?id=<?= $inv['invoice_id'] ?>"><i class="bi bi-eye text-info me-2"></i> View Details</a></li>
                                                    <?php if ($can_delete_invoices): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item py-2 rounded text-danger" href="#" onclick="deleteInvoice(<?= $inv['invoice_id'] ?>)"><i class="bi bi-trash me-2"></i> Delete</a></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    </div><!-- #invoicesTableView -->
                    <div id="invoicesCardGrid" class="row g-3 px-2 px-md-0 d-none mb-4"></div>
                </div>
            </div>
            </div><!-- #pane-invoices -->

            <?php if (!empty($customer_lpos) || $can_create_lpos): ?>
            <div class="tab-pane fade" id="pane-lpos" role="tabpanel">
            <!-- Customer Purchase Orders (LPO) -->
            <div class="card mb-3">
                <div class="card-header bg-light border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-file-earmark-text"></i> Purchase Orders (LPO)</h6>
                    <?php if ($can_create_lpos): ?>
                    <a href="<?= getUrl('lpo_create') ?>?customer_id=<?= $customer_id ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-circle me-1"></i> Add LPO
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="row g-2 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="card border-0 bg-light text-center p-2">
                                <div class="fs-5 fw-bold text-primary"><?= count($customer_lpos) ?></div>
                                <div class="small text-muted">Total LPOs</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 bg-light text-center p-2">
                                <div class="fs-5 fw-bold text-success"><?= $lpo_open_count ?></div>
                                <div class="small text-muted">Open</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 bg-light text-center p-2">
                                <div class="fs-5 fw-bold text-secondary"><?= count($customer_lpos) - $lpo_open_count ?></div>
                                <div class="small text-muted">Other Status</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card border-0 bg-light text-center p-2">
                                <div class="fs-5 fw-bold text-info"><?= number_format($lpo_total_amount, 0) ?></div>
                                <div class="small text-muted">Total Amount</div>
                            </div>
                        </div>
                    </div>
                    <?php if (empty($customer_lpos)): ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-file-earmark-x fs-3"></i>
                        <p class="mt-2 mb-0">No purchase orders recorded yet.</p>
                    </div>
                    <?php else: ?>
                    <!-- Desktop table -->
                    <div id="lposTableView" class="table-responsive">
                        <table id="customerLposTable" class="table table-hover align-middle w-100">
                            <thead style="background:#fff;border-bottom:2px solid #dee2e6;">
                                <tr>
                                    <th style="color:#212529;">S/NO</th>
                                    <th style="color:#212529;">LPO #</th>
                                    <th style="color:#212529;">Issue Date</th>
                                    <th style="color:#212529;">Expiry Date</th>
                                    <th class="text-end" style="color:#212529;">Amount</th>
                                    <th style="color:#212529;">Status</th>
                                    <?php if ($can_edit_lpos || $can_delete_lpos): ?>
                                    <th class="text-end" style="color:#212529;">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customer_lpos as $lpo_idx => $lpo):
                                    $lpo_badges = ['pending'=>'bg-primary','reviewed'=>'bg-info text-dark','approved'=>'bg-success','open'=>'bg-success','partially_fulfilled'=>'bg-primary','fulfilled'=>'bg-success','cancelled'=>'bg-secondary'];
                                    $lpo_badge  = $lpo_badges[$lpo['status']] ?? 'bg-secondary';
                                    $lpo_label  = ucwords(str_replace('_', ' ', $lpo['status']));
                                ?>
                                <tr>
                                    <td class="text-muted"><?= $lpo_idx + 1 ?></td>
                                    <td class="fw-semibold"><?= safe_output($lpo['lpo_number']) ?></td>
                                    <td><?= date('d M Y', strtotime($lpo['issue_date'])) ?></td>
                                    <td><?= $lpo['expiry_date'] ? date('d M Y', strtotime($lpo['expiry_date'])) : '—' ?></td>
                                    <td class="text-end"><?= safe_output($lpo['currency']) ?> <?= number_format((float)$lpo['amount'], 2) ?></td>
                                    <td><span class="badge <?= $lpo_badge ?>"><?= $lpo_label ?></span></td>
                                    <?php if ($can_edit_lpos || $can_delete_lpos): ?>
                                    <td class="text-end pe-3">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="bi bi-gear-fill me-1"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                                <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('lpo_view') ?>?id=<?= $lpo['lpo_id'] ?>"><i class="bi bi-eye text-info me-2"></i> View Details</a></li>
                                                <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('print_lpo') ?>?id=<?= $lpo['lpo_id'] ?>" target="_blank"><i class="bi bi-printer text-dark me-2"></i> Print</a></li>
                                                <?php if ($can_edit_lpos || $can_delete_lpos): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>
                                                <?php if ($can_edit_lpos): ?>
                                                <li><a class="dropdown-item py-2 rounded" href="<?= getUrl('lpo_create') ?>?edit=<?= $lpo['lpo_id'] ?>"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>
                                                <?php endif; ?>
                                                <?php if ($can_edit_lpos && $can_delete_lpos): ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>
                                                <?php if ($can_delete_lpos): ?>
                                                <li><a class="dropdown-item py-2 rounded text-danger" href="#" onclick="deleteLpo(<?= $lpo['lpo_id'] ?>, '<?= addslashes($lpo['lpo_number']) ?>')"><i class="bi bi-trash me-2"></i> Delete</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Mobile cards -->
                    <div id="lposCardView" class="row g-2">
                        <?php foreach ($customer_lpos as $lpo):
                            $lpo_badges = ['pending'=>'bg-primary','reviewed'=>'bg-info text-dark','approved'=>'bg-success','open'=>'bg-success','partially_fulfilled'=>'bg-primary','fulfilled'=>'bg-success','cancelled'=>'bg-secondary'];
                            $lpo_badge  = $lpo_badges[$lpo['status']] ?? 'bg-secondary';
                            $lpo_label  = ucwords(str_replace('_', ' ', $lpo['status']));
                        ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm" style="border-radius:10px;">
                                <div class="card-body p-3">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <span class="fw-bold text-primary" style="font-size:0.9rem;"><?= safe_output($lpo['lpo_number']) ?></span>
                                        <span class="badge <?= $lpo_badge ?>"><?= $lpo_label ?></span>
                                    </div>
                                    <div style="font-size:0.8rem;color:#555;">
                                        <small class="text-muted">Issue:</small> <?= date('d M Y', strtotime($lpo['issue_date'])) ?>
                                        <?php if ($lpo['expiry_date']): ?>&nbsp;<small class="text-muted">Expiry:</small> <?= date('d M Y', strtotime($lpo['expiry_date'])) ?><?php endif; ?><br>
                                        <small class="text-muted">Amount:</small> <strong><?= safe_output($lpo['currency']) ?> <?= number_format((float)$lpo['amount'], 2) ?></strong>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-top p-0" style="border-radius:0 0 10px 10px;">
                                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                                        <a href="<?= getUrl('lpo_view') ?>?id=<?= $lpo['lpo_id'] ?>" style="flex:1;padding:3px 4px;font-size:0.72rem;" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                        <?php if ($can_edit_lpos): ?>
                                        <a href="<?= getUrl('lpo_create') ?>?edit=<?= $lpo['lpo_id'] ?>" style="flex:1;padding:3px 4px;font-size:0.72rem;" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                        <?php endif; ?>
                                        <?php if ($can_delete_lpos): ?>
                                        <button onclick="deleteLpo(<?= $lpo['lpo_id'] ?>, '<?= addslashes($lpo['lpo_number']) ?>')" style="flex:1;padding:3px 4px;font-size:0.72rem;" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            </div><!-- #pane-lpos -->
            <?php endif; ?>

            <div class="tab-pane fade" id="pane-sysinfo" role="tabpanel">
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
            </div><!-- System Information card -->
            </div><!-- #pane-sysinfo -->
            </div><!-- #customerDetailTabContent -->
        </div> <!-- End col-md-8 -->
    </div> <!-- End outer row -->
</div> <!-- End container-fluid -->
<!-- Include Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">

<style>
#customerDetailTabs .nav-link {
    border: 1px solid #dee2e6;
    color: #495057;
    border-radius: 6px;
    font-size: 0.82rem;
    padding: 6px 14px;
    white-space: nowrap;
}
#customerDetailTabs .nav-link.active,
#customerDetailTabs .nav-link:hover {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}
@media (max-width: 576px) {
    #customerDetailTabs .nav-link { font-size: 0.75rem; padding: 5px 10px; }
}
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


@page { margin: 10mm 8mm 16mm 8mm; }
@media print {
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
    #ordersCardGrid, #invoicesCardGrid { display: none !important; }
    .btn, .breadcrumb, .alert, .navbar, footer,
    .d-print-none, nav, .card-header .btn,
    .dropdown, .sidebar, .d-print-none * {
        display: none !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
}

@media (max-width: 767px) {
    .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }
    #ordersCardGrid .card-footer .btn,
    #invoicesCardGrid .card-footer .btn,
    #ordersCardGrid .card-footer a,
    #invoicesCardGrid .card-footer a {
        flex: 1; min-width: 0; padding: 3px 4px; font-size: 0.72rem;
    }
}
</style>

<script>
// CSRF_TOKEN is declared globally by header.php — declaring it again here
// throws "Identifier 'CSRF_TOKEN' has already been declared" and aborts
// this entire <script> block (silently breaking every onclick / form
// submit / modal on the page).
let dtOrders, dtInvoices;

$(document).ready(function () {
    dtOrders = $('#customerOrdersTable').DataTable({
        pageLength: 10,
        order: [[1, 'desc']],
        responsive: false,
        columnDefs: [{ orderable: false, targets: 5 }],
        language: { emptyTable: 'No sales orders found for this customer.' },
        drawCallback: function () { renderOrdersCards(); }
    });

    dtInvoices = $('#customerInvoicesTable').DataTable({
        pageLength: 10,
        order: [[1, 'desc']],
        responsive: false,
        columnDefs: [{ orderable: false, targets: 6 }],
        language: { emptyTable: 'No invoices found for this customer.' },
        drawCallback: function () { renderInvoicesCards(); }
    });

    checkDetailsResponsiveView();
    $(window).on('resize.detailsView', function () { checkDetailsResponsiveView(); });
});

function checkDetailsResponsiveView() {
    const isMobile = window.innerWidth <= 767;
    toggleOrdersView(isMobile ? 'card' : 'table');
    toggleInvoicesView(isMobile ? 'card' : 'table');
}

function toggleOrdersView(mode) {
    if (window.innerWidth <= 767) mode = 'card';
    if (mode === 'card') {
        $('#ordersTableView').addClass('d-none');
        $('#ordersCardGrid').removeClass('d-none');
        renderOrdersCards();
    } else {
        $('#ordersTableView').removeClass('d-none');
        $('#ordersCardGrid').addClass('d-none');
    }
}

function toggleInvoicesView(mode) {
    if (window.innerWidth <= 767) mode = 'card';
    if (mode === 'card') {
        $('#invoicesTableView').addClass('d-none');
        $('#invoicesCardGrid').removeClass('d-none');
        renderInvoicesCards();
    } else {
        $('#invoicesTableView').removeClass('d-none');
        $('#invoicesCardGrid').addClass('d-none');
    }
}

function extractHref(html) {
    const m = String(html).match(/href="([^"]+)"/);
    return m ? m[1] : '#';
}

function renderOrdersCards() {
    const grid = $('#ordersCardGrid');
    if (grid.hasClass('d-none')) return;
    grid.empty();
    if (!dtOrders) return;
    const rows = dtOrders.rows({ page: 'current' }).data();
    if (!rows.length) {
        grid.append('<div class="col-12"><p class="text-center text-muted py-4">No sales orders found.</p></div>');
        return;
    }
    rows.each(function (row) {
        const orderNum = $('<div>').html(row[0]).text().trim();
        const date     = $('<div>').html(row[1]).text().trim();
        const amount   = $('<div>').html(row[2]).text().trim();
        const items    = row[3];
        const status   = row[4];
        const viewUrl  = extractHref(row[5]);
        grid.append(`
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius:10px;">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <span class="fw-bold text-primary" style="font-size:0.9rem;">${orderNum}</span>
                            ${status}
                        </div>
                        <div style="font-size:0.8rem;color:#555;">
                            <small class="text-muted">Date:</small> ${date} &nbsp;
                            <small class="text-muted">Amount:</small> <strong>${amount}</strong> &nbsp;
                            <small class="text-muted">Items:</small> ${items}
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top p-0" style="border-radius:0 0 10px 10px;">
                        <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                            <a href="${viewUrl}" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" class="btn btn-sm btn-outline-primary text-center"><i class="bi bi-eye"></i></a>
                        </div>
                    </div>
                </div>
            </div>`);
    });
}

function deleteInvoice(id) {
    Swal.fire({
        title: 'Delete Invoice?', text: 'This will permanently remove the invoice and its items.', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Deleting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.post('<?= buildUrl('api/account/delete_invoice.php') ?>', { invoice_id: id }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not delete invoice.' });
            }
        }, 'json').fail(function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' });
        });
    });
}

function renderInvoicesCards() {
    const grid = $('#invoicesCardGrid');
    if (grid.hasClass('d-none')) return;
    grid.empty();
    if (!dtInvoices) return;
    const rows = dtInvoices.rows({ page: 'current' }).data();
    if (!rows.length) {
        grid.append('<div class="col-12"><p class="text-center text-muted py-4">No invoices found.</p></div>');
        return;
    }
    rows.each(function (row) {
        const invNum  = $('<div>').html(row[0]).text().trim();
        const date    = $('<div>').html(row[1]).text().trim();
        const total   = $('<div>').html(row[2]).text().trim();
        const paid    = $('<div>').html(row[3]).text().trim();
        const balance = $('<div>').html(row[4]).text().trim();
        const status  = row[5];
        const viewUrl  = extractHref(row[6]);
        const invIdMatch = viewUrl.match(/[?&]id=(\d+)/);
        const invId    = invIdMatch ? invIdMatch[1] : '';
        const canDel   = <?= json_encode($can_delete_invoices) ?>;
        const delBtn   = canDel && invId ? `<a href="#" onclick="deleteInvoice(${invId})" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" class="btn btn-sm btn-outline-danger text-center"><i class="bi bi-trash"></i></a>` : '';
        grid.append(`
            <div class="col-12">
                <div class="card border-0 shadow-sm" style="border-radius:10px;">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-1">
                            <span class="fw-bold text-dark" style="font-size:0.9rem;">${invNum}</span>
                            ${status}
                        </div>
                        <div style="font-size:0.8rem;color:#555;">
                            <small class="text-muted">Date:</small> ${date}<br>
                            <small class="text-muted">Total:</small> <strong>${total}</strong> &nbsp;
                            <small class="text-muted">Paid:</small> <span class="text-success">${paid}</span> &nbsp;
                            <small class="text-muted">Balance:</small> ${balance}
                        </div>
                    </div>
                    <div class="card-footer bg-white border-top p-0" style="border-radius:0 0 10px 10px;">
                        <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                            <a href="${viewUrl}" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;" class="btn btn-sm btn-outline-success text-center"><i class="bi bi-eye"></i></a>
                            ${delBtn}
                        </div>
                    </div>
                </div>
            </div>`);
    });
}

// ── LPO section ───────────────────────────────────────────────────────────────
<?php if (!empty($customer_lpos)): ?>
$(document).ready(function () {
    const dtLpos = $('#customerLposTable').DataTable({
        pageLength: 10,
        order: [[2, 'desc']],
        responsive: false,
        columnDefs: [
            { orderable: false, targets: [0, -1] },
        ],
        language: { emptyTable: 'No purchase orders found.' }
    });
    function checkLposView() {
        const m = window.innerWidth <= 767;
        $('#lposTableView').toggleClass('d-none', m);
        $('#lposCardView').toggleClass('d-none', !m);
    }
    checkLposView();
    $(window).on('resize.lposView', checkLposView);
});
<?php endif; ?>

// Re-adjust DataTable columns when a tab is shown (fixes hidden-pane rendering)
$('#customerDetailTabs button[data-bs-toggle="pill"]').on('shown.bs.tab', function () {
    ['#customerOrdersTable', '#customerInvoicesTable', '#customerLposTable'].forEach(function (sel) {
        if ($.fn.DataTable.isDataTable(sel)) {
            $(sel).DataTable().columns.adjust();
        }
    });
});

function deleteLpo(lpoId, lpoNumber) {
    Swal.fire({
        title: 'Delete LPO?',
        text: 'Remove LPO "' + (lpoNumber || lpoId) + '"? This cannot be undone.',
        icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/customer/delete_lpo.php') ?>', { lpo_id: lpoId, _csrf: CSRF_TOKEN }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not delete.' });
            }
        }, 'json').fail(() => Swal.fire('Error', 'Server error.', 'error'));
    });
}

</script>

<?php if ($can_edit_customers): ?>
<!-- Edit Customer Modal — same form as customers.php Actions > Edit Customer -->
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
                        <!-- Basic Info -->
                        <div class="tab-pane fade show active" id="edit-basic" role="tabpanel">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <label for="edit_customer_name" class="form-label">Customer Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit_customer_name" name="customer_name" required placeholder="Enter customer name">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_company_name" class="form-label">Company Name</label>
                                    <input type="text" class="form-control" id="edit_company_name" name="company_name" placeholder="Company name (if different)">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_acronym" class="form-label">Acronym</label>
                                    <input type="text" class="form-control" id="edit_acronym" name="acronym" placeholder="Enter acronym">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_logo" class="form-label">Company Logo</label>
                                    <input type="file" class="form-control" id="edit_logo" name="logo" accept="image/*">
                                    <div id="logo_container" class="mt-2" style="display:none;">
                                        <img id="edit_logo_preview" src="" alt="Logo" class="img-thumbnail" style="height:50px;">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="$('#edit_logo_preview').attr('src','');$('#logo_container').hide();$('#remove_logo').val('1');"><i class="bi bi-trash"></i></button>
                                        <input type="hidden" id="remove_logo" name="remove_logo" value="0">
                                    </div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_category_id" class="form-label">Category</label>
                                    <select class="form-select select2-static" id="edit_category_id" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?= $cat['category_id'] ?>"><?= safe_output($cat['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_customer_type" class="form-label">Customer Type</label>
                                    <select class="form-select" id="edit_customer_type" name="customer_type">
                                        <option value="individual">Individual</option>
                                        <option value="business">Business</option>
                                        <option value="government">Government</option>
                                        <option value="ngo">NGO</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_status" class="form-label">Status</label>
                                    <select class="form-select" id="edit_status" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_year" class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" id="edit_year" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php $cy = date('Y'); for ($y = $cy; $y >= $cy - 10; $y--): ?>
                                        <option value="<?= $y ?>"><?= $y ?></option>
                                        <?php endfor; ?>
                                        <option value="other">Other...</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_project_id" class="form-label">Linked Project (Optional)</label>
                                    <select class="form-select select2-static" id="edit_project_id" name="project_id">
                                        <option value="">-- No Project --</option>
                                        <?php foreach ($projects as $proj): ?>
                                        <option value="<?= $proj['project_id'] ?>"><?= safe_output($proj['project_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_credit_limit" class="form-label">Credit Limit</label>
                                    <input type="number" class="form-control" id="edit_credit_limit" name="credit_limit" step="0.01" placeholder="0.00">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_description" class="form-label">Description</label>
                                    <textarea class="form-control" id="edit_description" name="description" rows="2" placeholder="Customer description or notes"></textarea>
                                </div>
                            </div>
                        </div>
                        <!-- Contact Details -->
                        <div class="tab-pane fade" id="edit-contact" role="tabpanel">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <label for="edit_contact_person" class="form-label">Contact Person</label>
                                    <input type="text" class="form-control" id="edit_contact_person" name="contact_person" placeholder="Primary contact person">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_contact_title" class="form-label">Contact Title</label>
                                    <input type="text" class="form-control" id="edit_contact_title" name="contact_title" placeholder="e.g., Manager, Director">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_email" class="form-label">Contact Email</label>
                                    <input type="email" class="form-control" id="edit_email" name="email" placeholder="contact@example.com">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_company_email" class="form-label">Company Email</label>
                                    <input type="email" class="form-control" id="edit_company_email" name="company_email" placeholder="company@example.com">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="edit_phone" name="phone" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_mobile" class="form-label">Mobile Number</label>
                                    <input type="text" class="form-control" id="edit_mobile" name="mobile" placeholder="+255 123 456 789">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_fax" class="form-label">Fax Number</label>
                                    <input type="text" class="form-control" id="edit_fax" name="fax" placeholder="Fax number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_website" class="form-label">Website</label>
                                    <input type="url" class="form-control" id="edit_website" name="website" placeholder="https://www.example.com">
                                </div>
                            </div>
                        </div>
                        <!-- Address -->
                        <div class="tab-pane fade" id="edit-address" role="tabpanel">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <label for="edit_country" class="form-label">Country</label>
                                    <input type="text" class="form-control" id="edit_country" name="country" placeholder="Country">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_state" class="form-label">Region</label>
                                    <input type="text" class="form-control" id="edit_state" name="state" placeholder="Region">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_city" class="form-label">District</label>
                                    <input type="text" class="form-control" id="edit_city" name="city" placeholder="District">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_council" class="form-label">Council</label>
                                    <input type="text" class="form-control" id="edit_council" name="council" placeholder="Council">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_ward" class="form-label">Ward</label>
                                    <input type="text" class="form-control" id="edit_ward" name="ward" placeholder="Ward">
                                </div>
                                <div class="col-6 mb-3">
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
                        <!-- Financial -->
                        <div class="tab-pane fade" id="edit-financial" role="tabpanel">
                            <div class="row">
                                <div class="col-6 mb-3">
                                    <label for="edit_tax_id" class="form-label">Tax ID (TIN)</label>
                                    <input type="text" class="form-control" id="edit_tax_id" name="tax_id" placeholder="Tax Identification Number">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_vat_number" class="form-label">VAT Number</label>
                                    <input type="text" class="form-control" id="edit_vat_number" name="vat_number" placeholder="VAT registration number">
                                </div>
                                <div class="col-6 mb-3">
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
                                <div class="col-6 mb-3">
                                    <label for="edit_currency" class="form-label">Currency</label>
                                    <select class="form-select" id="edit_currency" name="currency">
                                        <option value="TZS">Tanzanian Shilling (TZS)</option>
                                        <option value="USD">US Dollar (USD)</option>
                                        <option value="EUR">Euro (EUR)</option>
                                        <option value="GBP">British Pound (GBP)</option>
                                        <option value="KES">Kenyan Shilling (KES)</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_bank_name" class="form-label">Bank Name</label>
                                    <input type="text" class="form-control" id="edit_bank_name" name="bank_name" placeholder="Bank name">
                                </div>
                                <div class="col-6 mb-3">
                                    <label for="edit_bank_account" class="form-label">Bank Account</label>
                                    <input type="text" class="form-control" id="edit_bank_account" name="bank_account" placeholder="Bank account number">
                                </div>
                                <div class="col-12 mb-3">
                                    <label for="edit_bank_address" class="form-label">Bank Address</label>
                                    <textarea class="form-control" id="edit_bank_address" name="bank_address" rows="2" placeholder="Bank address details"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Update Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function () {
    // Edit customer form submit
    $('#editCustomerForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const originalHtml = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Updating...');
        $.ajax({
            url: '<?= buildUrl('api/process_edit_customer.php') ?>',
            type: 'POST',
            data: new FormData(this),
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Updated!', text: res.message || 'Customer updated successfully.', timer: 2000, showConfirmButton: false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message || 'Failed to update customer.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'A server error occurred.', 'error');
            },
            complete: function () {
                btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    // Select2 on editCustomerModal shown
    $('#editCustomerModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
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
});

function editCustomer(customerId) {
    if (!customerId) return;
    $.ajax({
        url: '<?= buildUrl('api/account/get_customer.php') ?>',
        type: 'GET',
        data: { id: customerId },
        dataType: 'json',
        success: function (response) {
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
                $('#edit_category_id').trigger('change');
                $('#edit_project_id').trigger('change');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('editCustomerModal')).show();
                setTimeout(() => {
                    const tab = document.querySelector('#edit-basic-tab');
                    if (tab) bootstrap.Tab.getOrCreateInstance(tab).show();
                }, 200);
            } else {
                Swal.fire('Error', 'Error loading customer: ' + (response.message || 'Unknown error'), 'error');
            }
        },
        error: function () {
            Swal.fire('Error', 'Could not load customer data.', 'error');
        }
    });
}
</script>
<?php endif; ?>

<?php
include("footer.php");
?>