<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output (Using suppliers permission as blueprint)
autoEnforcePermission('suppliers');

// Include the header
includeHeader();

// Get ID
$supplier_id = $_GET['id'] ?? '';
if (empty($supplier_id)) {
    header("Location: sub_contractors?error=ID required");
    exit();
}

// Get sub-contractor details
$stmt = $pdo->prepare("
    SELECT s.*,
           sc.category_name,
           p.project_name,
           p.contract_sum as project_contract_sum,
           p.status as project_status,
           u1.username as created_by_name,
           u2.username as updated_by_name
    FROM sub_contractors s
    LEFT JOIN supplier_categories sc ON s.category_id = sc.category_id
    LEFT JOIN users u1 ON s.created_by = u1.user_id
    LEFT JOIN users u2 ON s.updated_by = u2.user_id
    LEFT JOIN projects p ON s.project_id = p.project_id
    WHERE s.supplier_id = ? AND s.status != 'deleted'
");
$stmt->execute([$supplier_id]);
$sc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sc) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Sub-Contractor not found</div></div>";
    includeFooter();
    exit();
}

// Fetch categories and projects for edit modal dropdowns
$categories = $pdo->query("SELECT * FROM supplier_categories WHERE status = 'active' ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);
$projects = $pdo->query("SELECT project_id, project_name FROM projects WHERE status = 'active' ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);

// Get related information (Assuming shared tables for now, or link via project)
$purchase_orders = [];
if ($sc['project_id']) {
    $orders_stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE project_id = ? ORDER BY created_at DESC LIMIT 10");
    $orders_stmt->execute([$sc['project_id']]);
    $purchase_orders = $orders_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$payments = [];
// Statistics
$total_projects = $sc['project_id'] ? 1 : 0;
$contract_value = $sc['project_contract_sum'] ?? 0;
$milestones_count = 0; // Placeholder until milestone table is confirmed
$paid_amount = 0;

?>

<div class="container-fluid mt-2 mt-md-4 px-2 px-md-4 mb-5">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('sub_contractors') ?>">Sub-Contractors</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($sc['supplier_name']) ?></li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="row mb-3 mb-md-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-start flex-nowrap gap-2">
                <div>
                    <h2 class="mb-0 fs-4 fs-md-2 fw-bold"><i class="bi bi-person-workspace text-info"></i> Sub-Contractor View</h2>
                    <p class="text-muted mb-0 small mt-1 header-desc">
                        Detailed information about <?= htmlspecialchars($sc['supplier_name']) ?> 
                        <?php if($sc['company_name']): ?> • <?= htmlspecialchars($sc['company_name']) ?><?php endif; ?>
                        • Code: <code><?= htmlspecialchars($sc['supplier_code']) ?></code>
                    </p>
                </div>
                <div class="d-flex gap-2 ms-auto pt-2">
                    <a href="<?= getUrl('sub_contractors') ?>" class="btn btn-secondary btn-sm px-2 shadow-sm">
                        <i class="bi bi-arrow-left text-white"></i> Back
                    </a>
                    <button onclick="window.print()" class="btn btn-info btn-sm px-2 text-white shadow-sm">
                        <i class="bi bi-printer"></i> Print
                    </button>
                    <button onclick="editSC(<?= $sc['supplier_id'] ?>)" class="btn btn-primary btn-sm px-2 shadow-sm">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4 g-3">
        <div class="col-6 col-md-3">
            <div class="card h-100 stat-card-green">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 fw-bold"><?= $total_projects ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold">Total Projects</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 stat-card-green">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 fw-bold"><?= format_currency($contract_value) ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold">Contract Value</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 stat-card-green">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 fw-bold"><?= $milestones_count ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold">Milestones</p>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card h-100 stat-card-green">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-3">
                    <h4 class="mb-1 fw-bold"><?= format_currency($paid_amount) ?></h4>
                    <p class="mb-0 text-muted small text-uppercase fw-bold">Paid Amount</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Basic & Bank Info -->
        <div class="col-md-4">
            <!-- Basic Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-info-circle"></i> Basic Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm border-0">
                        <tr><td class="text-muted border-0">Status:</td><td class="border-0"><span class="badge bg-<?= get_status_badge($sc['status']) ?>"><?= ucfirst($sc['status']) ?></span></td></tr>
                        <tr><td class="text-muted border-0">Type:</td><td class="border-0"><?= htmlspecialchars($sc['supplier_type'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Category:</td><td class="border-0"><?= htmlspecialchars($sc['category_name'] ?? 'General') ?></td></tr>
                        <tr><td class="text-muted border-0">Year:</td><td class="border-0"><?= htmlspecialchars($sc['year'] ?? 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Linked Project:</td><td class="border-0 text-primary fw-bold"><?= htmlspecialchars($sc['project_name'] ?? 'General') ?></td></tr>
                        <tr><td class="text-muted border-0">TIN:</td><td class="border-0"><?= htmlspecialchars($sc['tax_id'] ?? 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">VAT:</td><td class="border-0"><?= htmlspecialchars($sc['vat_number'] ?? 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Credit Limit:</td><td class="border-0"><?= format_currency($sc['credit_limit'] ?? 0) ?></td></tr>
                    </table>
                </div>
            </div>

            <!-- Bank Information -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-bank"></i> Bank Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm border-0">
                        <tr><td class="text-muted border-0">Bank Name:</td><td class="border-0"><?= htmlspecialchars($sc['bank_name'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Account:</td><td class="border-0"><code><?= htmlspecialchars($sc['bank_account'] ?: 'N/A') ?></code></td></tr>
                        <tr><td class="text-muted border-0">Currency:</td><td class="border-0"><?= htmlspecialchars($sc['currency'] ?: 'TZS') ?></td></tr>
                        <tr><td class="text-muted border-0">Payment Terms:</td><td class="border-0"><?= htmlspecialchars($sc['payment_terms'] ?: 'N/A') ?></td></tr>
                    </table>
                    <?php if($sc['bank_address']): ?>
                    <p class="mb-0 small text-muted mt-2"><strong>Bank Address:</strong><br><?= nl2br(htmlspecialchars($sc['bank_address'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Middle Column: Contact & Description -->
        <div class="col-md-4">
            <!-- Contact Details -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-person-lines-fill"></i> Contact Details</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm border-0">
                        <tr><td class="text-muted border-0">Contact Person:</td><td class="border-0"><?= htmlspecialchars($sc['contact_person'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Title:</td><td class="border-0"><?= htmlspecialchars($sc['contact_title'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Email:</td><td class="border-0"><a href="mailto:<?= htmlspecialchars($sc['email']) ?>"><?= htmlspecialchars($sc['email'] ?: 'N/A') ?></a></td></tr>
                        <tr><td class="text-muted border-0">Phone:</td><td class="border-0"><?= htmlspecialchars($sc['phone'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Mobile:</td><td class="border-0"><?= htmlspecialchars($sc['mobile'] ?: 'N/A') ?></td></tr>
                        <tr><td class="text-muted border-0">Website:</td><td class="border-0"><a href="<?= htmlspecialchars($sc['website']) ?>" target="_blank"><?= htmlspecialchars($sc['website'] ?: 'N/A') ?></a></td></tr>
                    </table>
                </div>
            </div>

            <!-- Description -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-justify-left"></i> Description & Notes</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted"><?= nl2br(htmlspecialchars($sc['description'] ?: 'No additional notes provided.')) ?></p>
                </div>
            </div>
        </div>

        <!-- Right Column: Address -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-geo-alt"></i> Address Information</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <p class="mb-1 text-muted small fw-bold uppercase">Physical Address</p>
                        <p class="mb-0"><?= nl2br(htmlspecialchars($sc['address'] ?: 'N/A')) ?></p>
                    </div>
                    <div class="mb-3">
                        <p class="mb-1 text-muted small fw-bold uppercase">Postal Address</p>
                        <p class="mb-0"><?= htmlspecialchars($sc['postal_address'] ?: 'N/A') ?></p>
                    </div>
                    <hr class="opacity-10">
                    <div class="row g-2">
                        <div class="col-6"><p class="mb-0 text-muted small"><strong>District:</strong> <?= htmlspecialchars($sc['city'] ?: 'N/A') ?></p></div>
                        <div class="col-6"><p class="mb-0 text-muted small"><strong>Region:</strong> <?= htmlspecialchars($sc['state'] ?: 'N/A') ?></p></div>
                        <div class="col-6"><p class="mb-0 text-muted small"><strong>Ward:</strong> <?= htmlspecialchars($sc['ward'] ?: 'N/A') ?></p></div>
                        <div class="col-6"><p class="mb-0 text-muted small"><strong>Zip:</strong> <?= htmlspecialchars($sc['postal_code'] ?: 'N/A') ?></p></div>
                        <div class="col-12"><p class="mb-0 text-muted small"><strong>Country:</strong> <?= htmlspecialchars($sc['country'] ?: 'Tanzania') ?></p></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Related Tables -->
    <div class="row mt-4">
        <!-- Recent Purchase Orders -->
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-cart-check"></i> Recent Purchase Orders</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>PO Number</th>
                                    <th>Date</th>
                                    <th>Total Amount</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($purchase_orders)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted small">No recent purchase orders found.</td></tr>
                                <?php else: ?>
                                <?php foreach($purchase_orders as $po): ?>
                                <tr>
                                    <td><span class="fw-bold"><?= htmlspecialchars($po['order_number']) ?></span></td>
                                    <td><?= format_date($po['order_date']) ?></td>
                                    <td><?= format_currency($po['grand_total']) ?></td>
                                    <td><span class="badge bg-<?= get_status_badge($po['status']) ?>"><?= ucfirst($po['status']) ?></span></td>
                                    <td class="text-end"><a href="<?= getUrl('purchase_orders/view') ?>?id=<?= $po['purchase_order_id'] ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="col-12 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-cash-stack"></i> Recent Payments</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th>Voucher NO</th>
                                    <th>Payment Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="5" class="text-center py-4 text-muted small">No recent payment history found.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.stat-card-green {
    background-color: #d1e7dd;
    border: 1px solid #a3cfbb;
    border-radius: 12px;
}
.stat-card-green h4 { color: #0f5132; }
.header-desc { font-size: 0.85rem; }
.card-header { border-bottom: 1px solid rgba(0,0,0,0.05); }
.uppercase { text-transform: uppercase; letter-spacing: 0.5px; }
</style>

<!-- Edit Sub-Contractor Modal -->
<div class="modal fade" id="editSCModal" tabindex="-1" aria-labelledby="editSCModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editSCModalLabel"><i class="bi bi-pencil"></i> Edit Sub-Contractor</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editSCForm" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="supplier_id" id="edit_sc_id">
                <div class="modal-body">
                    <div id="edit-sc-message" class="mb-3"></div>
                    <ul class="nav nav-tabs mb-3" id="editSCTabs" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-edit-basic" type="button"><i class="bi bi-info-circle me-1"></i>Basic Info</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-edit-contact" type="button"><i class="bi bi-person-lines-fill me-1"></i>Contact Details</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-edit-address" type="button"><i class="bi bi-geo-alt me-1"></i>Address</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-edit-financial" type="button"><i class="bi bi-wallet2 me-1"></i>Financial</button></li>
                    </ul>
                    <div class="tab-content">
                        <!-- Basic Info -->
                        <div class="tab-pane fade show active" id="tab-edit-basic">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Sub-Contractor Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_sc_name" name="supplier_name" required></div>
                                <div class="col-6 mb-3"><label class="form-label">Company Name</label><input type="text" class="form-control" id="edit_company_name" name="company_name"></div>
                                <div class="col-6 mb-3"><label class="form-label">Acronym</label><input type="text" class="form-control" id="edit_acronym" name="acronym"></div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Company Logo</label>
                                    <input type="file" class="form-control" id="edit_logo" name="logo" accept="image/*">
                                    <div id="current_logo_display" class="mt-2"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Sub-Contractor Type</label>
                                    <select class="form-select" id="edit_sc_type" name="supplier_type">
                                        <option value="">Select Type</option>
                                        <option value="Manufacturer">Manufacturer</option>
                                        <option value="Distributor">Distributor</option>
                                        <option value="Wholesaler">Wholesaler</option>
                                        <option value="Retailer">Retailer</option>
                                        <option value="Service Provider">Service Provider</option>
                                        <option value="Contractor">Contractor</option>
                                        <option value="Consultant">Consultant</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Year <span class="text-danger">*</span></label>
                                    <select class="form-select" id="edit_sc_year" name="year" required>
                                        <option value="">Select Year</option>
                                        <?php $cy = date('Y'); for ($y = $cy; $y >= $cy - 10; $y--) echo "<option value='$y'>$y</option>"; ?>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_sc_year_other_wrap" class="mt-2" style="display:none;"><input type="text" class="form-control" id="edit_sc_year_other" name="year_other" placeholder="Enter year"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Category</label>
                                    <select class="form-select" id="edit_category_id" name="category_id">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?><option value="<?= $cat['category_id'] ?>"><?= safe_output($cat['category_name']) ?></option><?php endforeach; ?>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_category_id_other_wrap" class="mt-2" style="display:none;"><input type="text" class="form-control" id="edit_category_id_other" name="category_other" placeholder="Enter category"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" id="edit_status" name="status">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                        <option value="suspended">Suspended</option>
                                        <option value="blacklisted">Blacklisted</option>
                                    </select>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Linked Project (Optional)</label>
                                    <select class="form-select" id="edit_project_id" name="project_id">
                                        <option value="">-- General Sub-Contractor (No Project) --</option>
                                        <?php foreach ($projects as $proj): ?><option value="<?= $proj['project_id'] ?>"><?= safe_output($proj['project_name']) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6 mb-3"><label class="form-label">Credit Limit</label><input type="number" class="form-control" id="edit_credit_limit" name="credit_limit" step="0.01"></div>
                                <div class="col-12 mb-3"><label class="form-label">Description</label><textarea class="form-control" id="edit_description" name="description" rows="2"></textarea></div>
                            </div>
                        </div>
                        <!-- Contact Details -->
                        <div class="tab-pane fade" id="tab-edit-contact">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Contact Person</label><input type="text" class="form-control" id="edit_contact_person" name="contact_person"></div>
                                <div class="col-6 mb-3"><label class="form-label">Contact Title</label><input type="text" class="form-control" id="edit_contact_title" name="contact_title"></div>
                                <div class="col-6 mb-3"><label class="form-label">Contact Email</label><input type="email" class="form-control" id="edit_email" name="email"></div>
                                <div class="col-6 mb-3"><label class="form-label">Company Email</label><input type="email" class="form-control" id="edit_company_email" name="company_email"></div>
                                <div class="col-6 mb-3"><label class="form-label">Phone Number</label><input type="text" class="form-control" id="edit_phone" name="phone"></div>
                                <div class="col-6 mb-3"><label class="form-label">Mobile Number</label><input type="text" class="form-control" id="edit_mobile" name="mobile"></div>
                                <div class="col-6 mb-3"><label class="form-label">Fax Number</label><input type="text" class="form-control" id="edit_fax" name="fax"></div>
                                <div class="col-md-12 mb-3"><label class="form-label">Website</label><input type="url" class="form-control" id="edit_website" name="website"></div>
                            </div>
                        </div>
                        <!-- Address -->
                        <div class="tab-pane fade" id="tab-edit-address">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Country</label><input type="text" class="form-control" id="edit_country" name="country"></div>
                                <div class="col-6 mb-3"><label class="form-label">Region</label><input type="text" class="form-control" id="edit_state" name="state"></div>
                                <div class="col-6 mb-3"><label class="form-label">District</label><input type="text" class="form-control" id="edit_city" name="city"></div>
                                <div class="col-6 mb-3"><label class="form-label">Council</label><input type="text" class="form-control" id="edit_council" name="council"></div>
                                <div class="col-6 mb-3"><label class="form-label">Ward</label><input type="text" class="form-control" id="edit_ward" name="ward"></div>
                                <div class="col-6 mb-3"><label class="form-label">Zip Code</label><input type="text" class="form-control" id="edit_postal_code" name="postal_code"></div>
                                <div class="col-12 mb-3"><label class="form-label">Physical Address</label><textarea class="form-control" id="edit_address" name="address" rows="2"></textarea></div>
                                <div class="col-12 mb-3"><label class="form-label">Postal Address</label><input type="text" class="form-control" id="edit_postal_address" name="postal_address"></div>
                            </div>
                        </div>
                        <!-- Financial -->
                        <div class="tab-pane fade" id="tab-edit-financial">
                            <div class="row">
                                <div class="col-6 mb-3"><label class="form-label">Tax ID (TIN)</label><input type="text" class="form-control" id="edit_tax_id" name="tax_id"></div>
                                <div class="col-6 mb-3"><label class="form-label">VAT Number</label><input type="text" class="form-control" id="edit_vat_number" name="vat_number"></div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Payment Terms</label>
                                    <select class="form-select" id="edit_payment_terms" name="payment_terms">
                                        <option value="">Select...</option>
                                        <option value="Cash">Cash</option>
                                        <option value="Net 15">Net 15</option>
                                        <option value="Net 30">Net 30</option>
                                        <option value="Net 60">Net 60</option>
                                        <option value="Due on Receipt">Due on Receipt</option>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_payment_terms_other_wrap" class="mt-2" style="display:none;"><input type="text" class="form-control" id="edit_payment_terms_other" name="payment_terms_other" placeholder="Enter terms"></div>
                                </div>
                                <div class="col-6 mb-3">
                                    <label class="form-label">Currency</label>
                                    <select class="form-select" id="edit_currency" name="currency">
                                        <option value="">Select...</option>
                                        <option value="TZS">TZS</option>
                                        <option value="USD">USD</option>
                                        <option value="KES">KES</option>
                                        <option value="EUR">EUR</option>
                                        <option value="GBP">GBP</option>
                                        <option value="other">Other...</option>
                                    </select>
                                    <div id="edit_currency_other_wrap" class="mt-2" style="display:none;"><input type="text" class="form-control" id="edit_currency_other" name="currency_other" placeholder="Enter currency"></div>
                                </div>
                                <div class="col-6 mb-3"><label class="form-label">Bank Name</label><input type="text" class="form-control" id="edit_bank_name" name="bank_name"></div>
                                <div class="col-6 mb-3"><label class="form-label">Bank Account</label><input type="text" class="form-control" id="edit_bank_account" name="bank_account"></div>
                                <div class="col-12 mb-3"><label class="form-label">Bank Address</label><textarea class="form-control" id="edit_bank_address" name="bank_address" rows="2"></textarea></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update Sub-Contractor</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editSC(id) {
    $.getJSON('../../../api/get_sub_contractor.php', { id: id }, function(res) {
        if (res.success) {
            const d = res.data;
            $('#edit_sc_id').val(d.supplier_id);
            $('#edit_sc_name').val(d.supplier_name);
            $('#edit_company_name').val(d.company_name);
            $('#edit_acronym').val(d.acronym);
            $('#edit_sc_type').val(d.supplier_type);
            $('#edit_sc_year').val(d.year);
            $('#edit_category_id').val(d.category_id);
            $('#edit_status').val(d.status);
            $('#edit_project_id').val(d.project_id);
            $('#edit_credit_limit').val(d.credit_limit);
            $('#edit_description').val(d.description);
            $('#edit_contact_person').val(d.contact_person);
            $('#edit_contact_title').val(d.contact_title);
            $('#edit_email').val(d.email);
            $('#edit_company_email').val(d.company_email);
            $('#edit_phone').val(d.phone);
            $('#edit_mobile').val(d.mobile);
            $('#edit_fax').val(d.fax);
            $('#edit_website').val(d.website);
            $('#edit_country').val(d.country);
            $('#edit_state').val(d.state);
            $('#edit_city').val(d.city);
            $('#edit_council').val(d.council);
            $('#edit_ward').val(d.ward);
            $('#edit_postal_code').val(d.postal_code);
            $('#edit_address').val(d.address);
            $('#edit_postal_address').val(d.postal_address);
            $('#edit_tax_id').val(d.tax_id);
            $('#edit_vat_number').val(d.vat_number);
            $('#edit_payment_terms').val(d.payment_terms);
            $('#edit_currency').val(d.currency);
            $('#edit_bank_name').val(d.bank_name);
            $('#edit_bank_account').val(d.bank_account);
            $('#edit_bank_address').val(d.bank_address);
            if (d.logo_path) {
                $('#current_logo_display').html('<img src="' + d.logo_path + '" style="max-height:50px;" class="img-thumbnail">');
            } else {
                $('#current_logo_display').empty();
            }
            $('#editSCModal').modal('show');
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    });
}

$('#editSCForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    $.ajax({
        url: '../../../api/update_sub_contractor.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                Swal.fire('Updated', res.message, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
});
</script>

<?php
includeFooter();
?>
