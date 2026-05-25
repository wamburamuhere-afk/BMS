<?php
// Start the buffer to capture content
ob_start(); 

// Include the header
require_once HEADER_FILE;

// Phase 5a — enforce permission on edit-customer form
autoEnforcePermission('edit_customer');

// Check if customer ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: customers.php');
    exit();
}

$customer_id = $_GET['id'];

if ($customer_id && !userCan('customer', intval($customer_id))) {
    http_response_code(403);
    echo "<h3>Access denied: this customer is not in your project scope.</h3>";
    exit;
}

// Fetch customer data
$stmt = $pdo->prepare("SELECT * FROM customers WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    header('Location: customers.php');
    exit();
}

// Determine entity type
$isCompany = (($customer['customer_type'] ?? '') === 'business');

// Loan-specific dropdowns removed
$genders = [];
$marital_statuses = [];
$id_types = [];
$education_levels = [];

// Fetch countries
$stmt_countries = $pdo->query("SELECT * FROM countries WHERE is_active=1 ORDER BY country_name");
$countries = $stmt_countries->fetchAll(PDO::FETCH_ASSOC);

// Fetch regions for the customer's country if set
if (!empty($customer['country_id'])) {
    $stmt_regions = $pdo->prepare("SELECT * FROM regions WHERE country_id = ? ORDER BY region_name");
    $stmt_regions->execute([$customer['country_id']]);
    $regions = $stmt_regions->fetchAll(PDO::FETCH_ASSOC);
} else {
    $regions = [];
}

// Fetch districts for the customer's region if set
if (!empty($customer['region_id'])) {
    $stmt_districts = $pdo->prepare("SELECT * FROM districts WHERE region_id = ? ORDER BY district_name");
    $stmt_districts->execute([$customer['region_id']]);
    $districts = $stmt_districts->fetchAll(PDO::FETCH_ASSOC);
} else {
    $districts = [];
}

// Fetch all regions for the dropdown (will be filtered by JavaScript)
$stmt_all_regions = $pdo->query("SELECT * FROM regions ORDER BY region_name");
$all_regions = $stmt_all_regions->fetchAll(PDO::FETCH_ASSOC);

// Fetch all districts for JavaScript filtering
$stmt_all_districts = $pdo->query("SELECT * FROM districts ORDER BY district_name");
$all_districts = $stmt_all_districts->fetchAll(PDO::FETCH_ASSOC);

// Fetch customizable attachment labels from database or use defaults
$stmt_attachment_labels = $pdo->query("SELECT * FROM attachment_labels WHERE entity_type = 'individual' OR entity_type = 'all'");
$attachment_labels_result = $stmt_attachment_labels->fetchAll(PDO::FETCH_ASSOC);

// Organize labels by field name
$custom_labels = [];
foreach ($attachment_labels_result as $label) {
    $custom_labels[$label['field_name']] = $label['display_name'];
}

// Default labels if none are configured
$default_labels = [
    'other_attachment_1' => 'Additional Document 1',
    'other_attachment_2' => 'Additional Document 2',
    'other_attachment_3' => 'Additional Document 3',
    'other_attachment_4' => 'Additional Document 4'
];

// Merge custom labels with defaults
$attachment_labels = array_merge($default_labels, $custom_labels);

// Get dynamic attachment data from separate table if available, or just ignore if columns don't exist
$dynamicAttachments = [];
// Assuming for now they don't exist in the customers table to avoid warnings
// If the separate table is used, we would fetch from there.
/*
$stmt = $pdo->prepare("SELECT * FROM customer_additional_attachments WHERE customer_id = ?");
$stmt->execute([$customer_id]);
$dynamicAttachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
*/
?>

<div class="container mt-4">
    <div class="form-container">
        <h2 class="text-center mb-4">Edit <?= $isCompany ? 'Company' : 'Customer' ?></h2>
        <div id="form-message" class="mb-3"></div>
        
        <!-- Entity Type Display (Read-only) -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-building"></i> Entity Type</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-12">
                        <div class="alert alert-info">
                            <strong>Current Entity Type:</strong> 
                            <span class="badge bg-<?= $isCompany ? 'info' : 'primary' ?>">
                                <?= $isCompany ? 'Company' : 'Individual' ?>
                            </span>
                            <br>
                            <small class="text-muted">Entity type cannot be changed after registration.</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Edit Customer Form -->
        <form id="edit-form" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="customer_id" value="<?= $customer['customer_id'] ?>">
            <input type="hidden" name="customer_type" value="<?= $customer['customer_type'] ?>">
            <input type="hidden" id="total_dynamic_attachments" name="total_dynamic_attachments" value="<?= count($dynamicAttachments) ?>">
            
            <div class="row">
                <!-- Customer/Company Photo Section -->
                <div class="col-md-6 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-person-badge"></i> <?= $isCompany ? 'Company' : 'Customer' ?> Photo</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column align-items-center">
                                <div id="image-preview" class="mb-3" style="width: 200px; height: 200px; border: 2px dashed #ccc; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 8px;">
                                    <?php if (!empty($customer['photo_path'])): ?>
                                        <img src="<?= getUrl($customer['photo_path']) ?>" style="max-width: 100%; max-height: 100%; border-radius: 6px;">
                                    <?php else: ?>
                                        <span class="text-muted">No Photo</span>
                                    <?php endif; ?>
                                </div>
                                <div class="w-100">
                                    <input type="file" name="customer_photo" id="customer_photo" class="form-control" accept="image/*" capture="camera">
                                    <small class="text-muted">Take a photo or upload from your device (Max 5MB)</small>
                                    <div class="d-flex justify-content-center mt-2">
                                        <button type="button" id="open-camera" class="btn btn-sm btn-outline-primary me-2">
                                            <i class="bi bi-camera"></i> Take Photo
                                        </button>
                                        <button type="button" id="remove-photo" class="btn btn-sm btn-outline-danger" style="display: <?= !empty($customer['photo_path']) ? 'block' : 'none' ?>;">
                                            <i class="bi bi-trash"></i> Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ID Attachment Section -->
                <div class="col-md-6 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-file-earmark-text"></i> <?= $isCompany ? 'Representative ID' : 'ID' ?> Attachment</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex flex-column align-items-center">
                                <div id="id-preview" class="mb-3" style="width: 200px; height: 200px; border: 2px dashed #ccc; display: flex; align-items: center; justify-content: center; overflow: hidden; border-radius: 8px;">
                                    <?php if (!empty($customer['id_attachment_path'])): ?>
                                        <?php 
                                        $id_ext = pathinfo($customer['id_attachment_path'], PATHINFO_EXTENSION);
                                        if (in_array(strtolower($id_ext), ['pdf'])): ?>
                                            <div class="text-center p-3">
                                                <i class="bi bi-file-earmark-pdf" style="font-size: 3rem; color: #dc3545;"></i>
                                                <br>
                                                <small>ID Attachment.pdf</small>
                                            </div>
                                        <?php elseif (in_array(strtolower($id_ext), ['doc', 'docx'])): ?>
                                            <div class="text-center p-3">
                                                <i class="bi bi-file-earmark-word" style="font-size: 3rem; color: #0d6efd;"></i>
                                                <br>
                                                <small>ID Attachment.docx</small>
                                            </div>
                                        <?php else: ?>
                                            <img src="<?= getUrl($customer['id_attachment_path']) ?>" style="max-width: 100%; max-height: 100%; border-radius: 6px;">
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No File</span>
                                    <?php endif; ?>
                                </div>
                                <div class="w-100">
                                    <input type="file" name="id_attachment" id="id_attachment" class="form-control" accept="image/*,.pdf,.doc,.docx">
                                    <small class="text-muted">Upload photo, PDF or Word document of ID (Max 10MB)</small>
                                    <div class="d-flex justify-content-center mt-2">
                                        <button type="button" id="remove-id" class="btn btn-sm btn-outline-danger" style="display: <?= !empty($customer['id_attachment_path']) ? 'block' : 'none' ?>;">
                                            <i class="bi bi-trash"></i> Remove
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Camera Modal -->
                <div id="camera-modal" class="modal fade" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title">Take <?= $isCompany ? 'Company' : 'Customer' ?> Photo</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body text-center">
                                <div class="ratio ratio-16x9">
                                    <video id="video" width="100%" autoplay></video>
                                </div>
                                <canvas id="canvas" style="display:none;"></canvas>
                            </div>
                            <div class="modal-footer">
                                <button type="button" id="capture-btn" class="btn btn-primary">
                                    <i class="bi bi-camera"></i> Capture Photo
                                </button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="bi bi-x"></i> Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isCompany): ?>
                <!-- Company Information Section -->
                <div class="col-12 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-building"></i> Company Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-6 col-md-6 mb-3">
                                    <label for="company_name" class="form-label">Company Name <span class="text-danger">*</span></label>
                                    <input type="text" name="company_name" id="company_name" class="form-control" value="<?= htmlspecialchars($customer['company_name'] ?? '') ?>" required>
                                    <div class="invalid-feedback">Please provide a company name</div>
                                </div>

                                <div class="col-6 col-md-6 mb-3">
                                    <label for="registration_number" class="form-label">Reg. Number</label>
                                    <input type="text" name="registration_number" id="registration_number" class="form-control" value="<?= htmlspecialchars($customer['registration_number'] ?? '') ?>">
                                </div>

                                <div class="col-6 col-md-6 mb-3">
                                    <label for="acronym" class="form-label">Acronym</label>
                                    <input type="text" name="acronym" id="acronym" class="form-control" value="<?= htmlspecialchars($customer['acronym'] ?? '') ?>" placeholder="Acronym">
                                </div>

                                <div class="col-6 col-md-6 mb-3">
                                    <label for="tin_number" class="form-label">TIN Number</label>
                                    <input type="text" name="tin_number" id="tin_number" class="form-control" value="<?= htmlspecialchars($customer['tin_number'] ?? '') ?>">
                                </div>

                                <div class="col-6 col-md-6 mb-3">
                                    <label for="vat_number" class="form-label">VAT Number</label>
                                    <input type="text" name="vat_number" id="vat_number" class="form-control" value="<?= htmlspecialchars($customer['vat_number'] ?? '') ?>">
                                </div>

                                <div class="col-6 col-md-6 mb-3">
                                    <label for="website" class="form-label">Website</label>
                                    <div class="input-group">
                                        <span class="input-group-text px-2"><i class="bi bi-globe"></i></span>
                                        <input type="url" name="website" id="website" class="form-control p-2" value="<?= htmlspecialchars($customer['website'] ?? '') ?>" placeholder="URL">
                                    </div>
                                </div>

                                <div class="col-6 col-md-6 mb-3">
                                    <label for="business_type" class="form-label">Bus. Type <span class="text-danger">*</span></label>
                                    <input type="text" name="business_type" id="business_type" class="form-control" value="<?= htmlspecialchars($customer['occupation_business'] ?? '') ?>" required>
                                    <div class="invalid-feedback">Required</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Representative/Personal Information Section -->
                <div class="col-12 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-<?= $isCompany ? 'person-badge' : 'person-lines-fill' ?>"></i> <?= $isCompany ? 'Company Representative' : 'Personal' ?> Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="customer_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="customer_name" id="customer_name" class="form-control" value="<?= htmlspecialchars($customer['customer_name'] ?? '') ?>" required>
                                    <div class="invalid-feedback">Please provide a name</div>
                                </div>

                                <div class="col-6 col-md-6 mb-3">
                                    <label for="phone" class="form-label">Phone <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text px-2"><i class="bi bi-telephone"></i></span>
                                        <input type="tel" name="phone" id="phone" class="form-control p-2" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>" required>
                                    </div>
                                </div>

                                <div class="col-6 col-md-6 mb-3">
                                    <label for="mobile" class="form-label">Mobile</label>
                                    <div class="input-group">
                                        <span class="input-group-text px-2"><i class="bi bi-phone"></i></span>
                                        <input type="tel" name="mobile" id="mobile" class="form-control p-2" value="<?= htmlspecialchars($customer['mobile'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="col-12 col-md-6 mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <div class="input-group">
                                        <span class="input-group-text px-2"><i class="bi bi-envelope"></i></span>
                                        <input type="email" name="email" id="email" class="form-control p-2" value="<?= htmlspecialchars($customer['email'] ?? '') ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Address Information Section -->
                <div class="col-12 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-geo-alt"></i> Address Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="address" class="form-label">Postal Address <span class="text-danger">*</span></label>
                                    <textarea name="address" id="address" class="form-control" rows="2" required><?= htmlspecialchars($customer['address'] ?? '') ?></textarea>
                                    <div class="invalid-feedback">Please provide a postal address</div>
                                </div>

                              

                                <!-- New Address Fields Section -->
                                <div class="col-12">
                                    <div class="row">
                                        <!-- Country -->
                                        <div class="col-6 col-md-4 mb-3">
                                            <label for="country" class="form-label">Country</label>
                                            <input type="text" name="country" id="country" class="form-control" value="<?= htmlspecialchars($customer['country'] ?? 'Tanzania') ?>">
                                        </div>

                                        <!-- State -->
                                        <div class="col-6 col-md-4 mb-3">
                                            <label for="state" class="form-label">State/Region</label>
                                            <input type="text" name="state" id="state" class="form-control" value="<?= htmlspecialchars($customer['state'] ?? '') ?>">
                                        </div>

                                        <!-- City -->
                                        <div class="col-6 col-md-4 mb-3">
                                            <label for="city" class="form-label">City</label>
                                            <input type="text" name="city" id="city" class="form-control" value="<?= htmlspecialchars($customer['city'] ?? '') ?>">
                                        </div>

                                        <!-- Postal Code -->
                                        <div class="col-6 col-md-4 mb-3">
                                            <label for="postal_code" class="form-label">Postal Code</label>
                                            <input type="text" name="postal_code" id="postal_code" class="form-control" value="<?= htmlspecialchars($customer['postal_code'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>





                <?php if ($isCompany): ?>
                <!-- Company Documents Section -->
                <div class="col-12 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-folder-check"></i> Company Documents</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-4">You can update the following company documents if needed.</p>
                            
                            <div class="row">
                                <!-- Incorporation Certificate -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Incorporation Certificate</h6>
                                        <p class="text-muted small">Company registration certificate</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['incorporation_cert_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['incorporation_cert_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="incorporation_cert">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_incorporation_cert" value="<?= $customer['incorporation_cert_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="incorporation_cert" id="incorporation_cert" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="incorporation-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- TIN Certificate -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>TIN Certificate</h6>
                                        <p class="text-muted small">Tax Identification Number certificate</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['tin_cert_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['tin_cert_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="tin_cert">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_tin_cert" value="<?= $customer['tin_cert_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="tin_cert" id="tin_cert" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="tin-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- VAT Certificate -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>VAT Certificate</h6>
                                        <p class="text-muted small">Value Added Tax registration certificate</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['vat_cert_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['vat_cert_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="vat_cert">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_vat_cert" value="<?= $customer['vat_cert_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="vat_cert" id="vat_cert" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="vat-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Tax Clearance Certificate -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Tax Clearance Certificate</h6>
                                        <p class="text-muted small">Most recent tax clearance certificate</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['tax_clearance_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['tax_clearance_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="tax_clearance">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_tax_clearance" value="<?= $customer['tax_clearance_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="tax_clearance" id="tax_clearance" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="tax-clearance-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Business License -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Business License</h6>
                                        <p class="text-muted small">Current business license</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['business_license_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['business_license_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="business_license">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_business_license" value="<?= $customer['business_license_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="business_license" id="business_license" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="business-license-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- MEMART Certificate -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>MEMART Certificate</h6>
                                        <p class="text-muted small">Minerals and Energy certificate</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['memart_cert_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['memart_cert_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="memart_cert">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_memart_cert" value="<?= $customer['memart_cert_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="memart_cert" id="memart_cert" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="memart-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Board Resolution -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Board Resolution</h6>
                                        <p class="text-muted small">Board resolution authorizing loan application</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['board_resolution_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['board_resolution_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="board_resolution">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_board_resolution" value="<?= $customer['board_resolution_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="board_resolution" id="board_resolution" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="board-resolution-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Application Letter -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Application Letter</h6>
                                        <p class="text-muted small">Formal loan application letter</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['application_letter_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['application_letter_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="application_letter">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_application_letter" value="<?= $customer['application_letter_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="application_letter" id="application_letter" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="application-letter-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Introduction Letter -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Introduction Letter</h6>
                                        <p class="text-muted small">Company introduction letter</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['intro_letter_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['intro_letter_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="intro_letter">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_intro_letter" value="<?= $customer['intro_letter_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="intro_letter" id="intro_letter" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="intro-letter-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Bank Statement -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Bank Statement</h6>
                                        <p class="text-muted small">Recent bank statements (6 months)</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['bank_statement_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['bank_statement_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="bank_statement">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_bank_statement" value="<?= $customer['bank_statement_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="bank_statement" id="bank_statement" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="bank-statement-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Financial Statement -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Financial Statement</h6>
                                        <p class="text-muted small">Audited financial statements</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['financial_statement_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['financial_statement_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="financial_statement">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_financial_statement" value="<?= $customer['financial_statement_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="financial_statement" id="financial_statement" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="financial-statement-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Lease Agreement -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Lease Agreement</h6>
                                        <p class="text-muted small">Office/business premises lease agreement</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['lease_agreement_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['lease_agreement_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="lease_agreement">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_lease_agreement" value="<?= $customer['lease_agreement_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="lease_agreement" id="lease_agreement" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="lease-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Business Attachments Section (for individuals) -->
                <div class="col-12 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-paperclip"></i> Business Attachments</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-4">You can update the following business documents if needed.</p>
                            
                            <div class="row">
                                <!-- Letter from Local Government -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Letter from Local Government</h6>
                                        <p class="text-muted small">Official introduction letter from local government authorities</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['local_gov_letter_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['local_gov_letter_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="local_gov_letter">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_local_gov_letter" value="<?= $customer['local_gov_letter_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="local_gov_letter" id="local_gov_letter" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="local-gov-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- BRELA Business Name Registration -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>BRELA Business Name Certificate</h6>
                                        <p class="text-muted small">Business Registration and Licensing Agency (BRELA) certificate</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['brela_certificate_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['brela_certificate_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="brela_certificate">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_brela_certificate" value="<?= $customer['brela_certificate_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="brela_certificate" id="brela_certificate" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="brela-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Tax Payer Identification Number (TIN) -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Tax Payer Identification Number (TIN) Certificate</h6>
                                        <p class="text-muted small">TRA TIN registration certificate</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['tin_certificate_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['tin_certificate_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="tin_certificate">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_tin_certificate" value="<?= $customer['tin_certificate_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="tin_certificate" id="tin_certificate" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="tin-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Tax Clearance Certificate -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Tax Clearance Certificate</h6>
                                        <p class="text-muted small">Most recent tax clearance certificate from TRA</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['tax_clearance_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['tax_clearance_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="tax_clearance">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_tax_clearance" value="<?= $customer['tax_clearance_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="tax_clearance" id="tax_clearance" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="tax-clearance-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Business License -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Business License</h6>
                                        <p class="text-muted small">Current business license from relevant authority</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['business_license_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['business_license_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="business_license">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_business_license" value="<?= $customer['business_license_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="business_license" id="business_license" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="business-license-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Lease Agreement/Proof of Ownership -->
                                <div class="col-md-6 mb-4">
                                    <div class="attachment-item">
                                        <h6>Lease Agreement/Proof of Ownership</h6>
                                        <p class="text-muted small">Office/business premises lease agreement or proof of ownership</p>
                                        <div class="file-upload-container">
                                            <?php if (!empty($customer['lease_agreement_path'])): ?>
                                                <div class="mb-2">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <span>Current file: <?= basename($customer['lease_agreement_path']) ?></span>
                                                        <button type="button" class="btn btn-sm btn-outline-danger remove-existing-file" data-field="lease_agreement">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                    <input type="hidden" name="existing_lease_agreement" value="<?= $customer['lease_agreement_path'] ?>">
                                                </div>
                                            <?php endif; ?>
                                            <input type="file" name="lease_agreement" id="lease_agreement" class="form-control" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div id="lease-preview" class="file-preview-container mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Other Attachments Section -->
                <div class="col-12 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-files"></i> Additional Attachments</h5>
                            <button type="button" class="btn btn-sm btn-light" id="customize-labels-btn" data-bs-toggle="modal" data-bs-target="#customizeModal">
                                <i class="bi bi-pencil"></i> Manage Attachments
                            </button>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-4">Additional supporting documents. Each attachment will appear after you define the previous one.</p>
                            
                            <div class="row" id="dynamic-attachments-container">
                                <!-- This will be populated dynamically with JavaScript -->
                            </div>
                            
                            <!-- Template for dynamic attachment items (hidden) -->
                            <template id="attachment-template">
                                <div class="col-md-6 mb-4 attachment-item-wrapper">
                                    <div class="attachment-item">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <h6 class="attachment-label">Additional Document</h6>
                                            <div>
                                                <small class="badge bg-secondary me-1">Optional</small>
                                                <button type="button" class="btn btn-sm btn-outline-danger remove-attachment-btn" style="display: none;">
                                                    <i class="bi bi-x"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="file-upload-container">
                                            <input type="file" class="form-control attachment-file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                            <small class="text-muted">Max file size: 10MB</small>
                                            <div class="file-preview-container mt-2"></div>
                                        </div>
                                        <input type="hidden" class="attachment-label-input" value="">
                                        <input type="hidden" class="existing-file-input" value="">
                                    </div>
                                </div>
                            </template>
                            
                            <!-- Add New Attachment Button -->
                            <div class="text-center mt-3" id="add-attachment-section">
                                <button type="button" class="btn btn-outline-info" id="add-attachment-btn">
                                    <i class="bi bi-plus-circle"></i> Add Attachment
                                </button>
                                <small class="d-block text-muted mt-2">Click to add another supporting document</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Customize Labels Modal -->
                <div class="modal fade" id="customizeModal" tabindex="-1" aria-labelledby="customizeModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header bg-info text-white">
                                <h5 class="modal-title" id="customizeModalLabel">
                                    <i class="bi bi-pencil"></i> Manage Attachments
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-muted mb-3">Define labels for your attachments. The next attachment will appear after you define a label for the current one.</p>
                                
                                <div id="label-inputs-container">
                                    <!-- Will be populated dynamically -->
                                </div>
                                
                                <div class="alert alert-info mt-3">
                                    <small>
                                        <i class="bi bi-info-circle"></i> 
                                        You can add up to 4 additional attachments. Define a label for each attachment you want to use.
                                    </small>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="save-labels-btn">
                                    <i class="bi bi-check-circle"></i> Apply Labels
                                </button>
                                <button type="button" class="btn btn-outline-danger" id="clear-all-btn">
                                    <i class="bi bi-trash"></i> Clear All
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Submission Buttons -->
                <div class="col-12 mt-4">
                    <div class="d-flex flex-column flex-md-row justify-content-center align-items-stretch align-items-md-center gap-2 px-2">
                        <button type="submit" class="btn btn-primary btn-lg shadow-sm">
                            <i class="bi bi-check-circle"></i> Update <?= $isCompany ? 'Company' : 'Customer' ?>
                        </button>
                        <a href="customer_details.php?id=<?= $customer['customer_id'] ?>" class="btn btn-secondary btn-lg shadow-sm">
                            <i class="bi bi-eye"></i> View Details
                        </a>
                        <a href="customers.php" class="btn btn-outline-secondary btn-lg shadow-sm">
                            <i class="bi bi-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
    .form-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 10px;
        box-shadow: 0 0 20px rgba(0,0,0,0.1);
    }
    
    .card {
        border: none;
        border-radius: 10px;
        transition: transform 0.2s;
    }
    
    .card:hover {
        transform: translateY(-5px);
    }
    
    .card-header {
        border-radius: 10px 10px 0 0 !important;
    }
    
    .btn {
        border-radius: 6px;
        padding: 10px 20px;
        font-weight: 500;
    }
    
    .btn-lg {
        padding: 12px 30px;
    }
    
    .form-control, .form-select {
        border-radius: 6px;
        padding: 10px 15px;
        border: 1px solid #ced4da;
        transition: all 0.3s;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #86b7fe;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }
    
    #image-preview, #id-preview {
        transition: all 0.3s;
    }
    
    #image-preview:hover, #id-preview:hover {
        border-color: #86b7fe;
    }
    
    .progress-bar {
        transition: width 0.3s ease;
    }
    
    .spinner-border-sm {
        width: 1rem;
        height: 1rem;
    }
    
    .attachment-item {
        border-left: 4px solid #0d6efd;
        padding-left: 15px;
        margin-bottom: 20px;
        background-color: #f8f9fa;
        border-radius: 5px;
        padding: 15px;
    }
    
    .attachment-item h6 {
        color: #2c3e50;
        font-weight: 600;
    }
    
    .file-upload-container {
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        margin-bottom: 15px;
        transition: all 0.3s;
        background-color: white;
    }
    
    .file-upload-container:hover {
        border-color: #86b7fe;
        background-color: rgba(13, 110, 253, 0.05);
    }
    
    .file-preview-container {
        min-height: 80px;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: #f8f9fa;
        border-radius: 6px;
        padding: 10px;
    }
    
    .file-preview-container img {
        max-width: 100%;
        max-height: 150px;
        border-radius: 6px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .bg-success {
        background-color: #198754 !important;
    }
    
    .bg-info {
        background-color: #0dcaf0 !important;
    }
    
    .text-danger {
        color: #dc3545 !important;
    }
    
    .small {
        font-size: 0.875em;
    }
    
    /* Spinner animation */
    .spin {
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    /* Additional styles for dynamic attachments */
    .attachment-item-wrapper {
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .label-input.is-valid {
        border-color: #198754;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
    }
    
    .label-input.is-invalid {
        border-color: #dc3545;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
    }
    
    .attachment-item {
        transition: all 0.2s ease;
    }
    
    .attachment-item:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .remove-attachment-btn {
        padding: 2px 6px;
        font-size: 0.75rem;
    }
    
    #add-attachment-btn {
        transition: all 0.2s ease;
    }
    
    #add-attachment-btn:hover {
        transform: scale(1.05);
    }
    
    .form-text.text-info {
        font-size: 0.8rem;
        margin-top: 4px;
    }
    
    .form-text.text-success {
        font-size: 0.8rem;
        margin-top: 4px;
    }
    
    .badge.bg-secondary {
        font-size: 0.7rem;
        font-weight: normal;
    }
    
    .toast {
        min-width: 300px;
        max-width: 350px;
    }
    
    #customize-labels-btn {
        font-size: 0.875rem;
        padding: 5px 10px;
    }
    
    .attachment-item h6 {
        word-break: break-word;
        max-width: 90%;
    }
    
    @media (max-width: 768px) {
        .navbar, .page-top-navbar { position: sticky; top: 0; z-index: 1020; }

        .form-container {
            padding: 15px;
        }

        .btn-lg {
            padding: 10px 20px;
            font-size: 1rem;
        }

        .attachment-item {
            padding-left: 10px;
            padding: 10px;
        }

        .file-upload-container {
            padding: 15px;
        }

        .attachment-item-wrapper {
            width: 100%;
        }
    }

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.table thead th {
    background-color: #f8f9fa !important;
}
</style>
</style>

<?php include("footer.php"); ?>
<script>
$(document).ready(function() {
    // ================== DYNAMIC ATTACHMENTS MANAGEMENT ==================
    
    let attachmentCounter = <?= count($dynamicAttachments) ?>;
    let maxAttachments = 4;
    let attachmentData = <?= json_encode(!empty($dynamicAttachments) ? $dynamicAttachments : (object)[]) ?>;
    let attachmentElements = {};
    
    // Initialize attachments from existing data
    function initializeAttachments() {
        // If no existing attachments, start with one empty
        if (Object.keys(attachmentData).length === 0) {
            attachmentData[1] = {
                path: '',
                label: '',
                fileName: '',
                filePreview: '',
                isDefined: false
            };
        }
        
        renderAttachments();
    }
    
    // Render attachments based on attachmentData
    function renderAttachments() {
        const container = $('#dynamic-attachments-container');
        container.empty();
        
        attachmentElements = {};
        
        Object.keys(attachmentData).sort((a, b) => parseInt(a) - parseInt(b)).forEach((key, index) => {
            const attachment = attachmentData[key];
            const attachmentId = parseInt(key);
            
            if (attachment && (attachment.isDefined || index === 0)) {
                const template = document.getElementById('attachment-template');
                const clone = template.content.cloneNode(true);
                const wrapper = $(clone).find('.attachment-item-wrapper');
                
                // Set unique IDs
                wrapper.attr('data-attachment-id', attachmentId);
                
                // Update label display
                const labelElement = wrapper.find('.attachment-label');
                if (attachment.label) {
                    labelElement.text(attachment.label);
                } else {
                    labelElement.text(`Attachment ${attachmentId}`);
                }
                
                // Update file input
                const fileInput = wrapper.find('.attachment-file');
                fileInput.attr('name', `other_attachment_${attachmentId}`);
                fileInput.attr('id', `other_attachment_${attachmentId}`);
                
                // Update label hidden input
                const labelInput = wrapper.find('.attachment-label-input');
                labelInput.attr('name', `other_attachment_${attachmentId}_label`);
                labelInput.attr('id', `other_attachment_${attachmentId}_label`);
                labelInput.val(attachment.label || '');
                
                // Update existing file input
                const existingFileInput = wrapper.find('.existing-file-input');
                existingFileInput.attr('name', `existing_other_attachment_${attachmentId}`);
                existingFileInput.val(attachment.path || '');
                
                // Update preview container
                const previewContainer = wrapper.find('.file-preview-container');
                previewContainer.attr('id', `other${attachmentId}-preview`);
                
                // Show existing preview if any
                if (attachment.filePreview) {
                    previewContainer.html(attachment.filePreview);
                } else if (attachment.path) {
                    // Show existing file preview
                    const fileName = attachment.path.split('/').pop();
                    const fileExt = fileName.split('.').pop().toLowerCase();
                    
                    let previewHtml = '';
                    if (['jpg', 'jpeg', 'png', 'gif'].includes(fileExt)) {
                        previewHtml = `<img src="${APP_URL}/${attachment.path}" class="img-thumbnail mb-2" style="max-height: 120px;">
                                     <p class="small mb-1 text-truncate"><strong>${fileName}</strong></p>
                                     <p class="small text-muted">Existing file</p>`;
                    } else if (fileExt === 'pdf') {
                        previewHtml = `<i class="bi bi-file-earmark-pdf text-danger" style="font-size: 2.5rem;"></i>
                                     <p class="mt-2 small mb-1 text-truncate"><strong>${fileName}</strong></p>
                                     <p class="small text-muted">Existing file</p>`;
                    } else if (['doc', 'docx'].includes(fileExt)) {
                        previewHtml = `<i class="bi bi-file-earmark-word text-primary" style="font-size: 2.5rem;"></i>
                                     <p class="mt-2 small mb-1 text-truncate"><strong>${fileName}</strong></p>
                                     <p class="small text-muted">Existing file</p>`;
                    }
                    
                    previewContainer.html(previewHtml);
                    attachment.filePreview = previewHtml;
                }
                
                // Show remove button if not the first one
                if (attachmentId > 1) {
                    wrapper.find('.remove-attachment-btn').show();
                }
                
                // Store reference
                attachmentElements[attachmentId] = wrapper;
                
                // Add to container
                container.append(wrapper);
                
                // Attach file change event
                fileInput.change(function() {
                    handleFileChange(this, attachmentId);
                });
                
                // Attach remove event
                wrapper.find('.remove-attachment-btn').click(function() {
                    removeAttachment(attachmentId);
                });
            }
        });
        
        // Update add button visibility
        updateAddButtonVisibility();
        
        // Update total dynamic attachments hidden field
        $('#total_dynamic_attachments').val(Object.keys(attachmentData).length);
    }
    
    // Update add button based on current state
    function updateAddButtonVisibility() {
        const addBtnSection = $('#add-attachment-section');
        const definedCount = Object.values(attachmentData).filter(a => a && a.isDefined).length;
        
        if (definedCount < maxAttachments && Object.keys(attachmentData).length <= definedCount) {
            addBtnSection.show();
        } else {
            addBtnSection.hide();
        }
    }
    
    // Add a new attachment
    function addAttachment() {
        if (Object.keys(attachmentData).length >= maxAttachments) {
            showToast('Maximum of 4 additional attachments allowed', 'warning');
            return;
        }
        
        const newId = Object.keys(attachmentData).length + 1;
        attachmentData[newId] = {
            path: '',
            label: '',
            fileName: '',
            filePreview: '',
            isDefined: false
        };
        
        renderAttachments();
        updateLabelModal();
        saveAttachmentsToStorage();
    }
    
    // Remove an attachment
    function removeAttachment(id) {
        if (id <= 1) {
            showToast('Cannot remove the first attachment', 'warning');
            return;
        }
        
        // Remove the attachment
        delete attachmentData[id];
        
        // Re-index remaining attachments
        const newAttachmentData = {};
        let newIndex = 1;
        Object.values(attachmentData).forEach(attachment => {
            if (attachment) {
                newAttachmentData[newIndex] = attachment;
                newIndex++;
            }
        });
        attachmentData = newAttachmentData;
        
        renderAttachments();
        updateLabelModal();
        saveAttachmentsToStorage();
    }
    
    // Handle file selection
    function handleFileChange(input, attachmentId) {
        const file = input.files[0];
        if (!file) return;
        
        const attachment = attachmentData[attachmentId];
        if (attachment) {
            attachment.fileName = file.name;
            
            // Check file size (10MB max)
            if (file.size > 10 * 1024 * 1024) {
                showToast('File size exceeds 10MB limit', 'warning');
                $(input).val('');
                return;
            }
            
            // Check file type
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 
                                 'application/pdf', 'application/msword',
                                 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            if (!allowedTypes.includes(file.type)) {
                showToast('Invalid file type. Only images, PDF, and Word documents are allowed.', 'warning');
                $(input).val('');
                return;
            }
            
            // Update preview
            let previewHtml = '';
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewHtml = `<img src="${e.target.result}" class="img-thumbnail mb-2" style="max-height: 120px;">
                                 <p class="small mb-1 text-truncate"><strong>${file.name}</strong></p>
                                 <p class="small text-muted">${(file.size / (1024 * 1024)).toFixed(2)} MB</p>`;
                    $(`#other${attachmentId}-preview`).html(previewHtml);
                    attachment.filePreview = previewHtml;
                    attachment.path = ''; // Clear existing path since new file is uploaded
                    saveAttachmentsToStorage();
                };
                reader.readAsDataURL(file);
            } else {
                const icon = file.type === 'application/pdf' ? 
                    'bi-file-earmark-pdf text-danger' : 
                    'bi-file-earmark-word text-primary';
                previewHtml = `<i class="bi ${icon}" style="font-size: 2.5rem;"></i>
                             <p class="mt-2 small mb-1 text-truncate"><strong>${file.name}</strong></p>
                             <p class="small text-muted">${(file.size / (1024 * 1024)).toFixed(2)} MB</p>`;
                $(`#other${attachmentId}-preview`).html(previewHtml);
                attachment.filePreview = previewHtml;
                attachment.path = ''; // Clear existing path since new file is uploaded
                saveAttachmentsToStorage();
            }
            
            // If this is a new file, make sure attachment is defined
            if (!attachment.isDefined && attachment.label) {
                attachment.isDefined = true;
                renderAttachments();
            }
        }
    }
    
    // Update label modal
    function updateLabelModal() {
        const container = $('#label-inputs-container');
        container.empty();
        
        Object.keys(attachmentData).forEach((key, index) => {
            const attachment = attachmentData[key];
            const attachmentId = parseInt(key);
            const isLast = index === Object.keys(attachmentData).length - 1;
            const hasNext = index < maxAttachments - 1;
            
            const inputGroup = `
                <div class="mb-3" data-attachment-id="${attachmentId}">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="label-input-${attachmentId}" class="form-label">
                            Attachment ${attachmentId}
                            ${!attachment.isDefined ? '<span class="text-danger">*</span>' : ''}
                        </label>
                        ${attachmentId > 1 ? 
                            `<button type="button" class="btn btn-sm btn-outline-danger remove-label-btn" data-id="${attachmentId}">
                                <i class="bi bi-x"></i>
                            </button>` : ''
                        }
                    </div>
                    <input type="text" 
                           class="form-control label-input" 
                           id="label-input-${attachmentId}"
                           data-attachment-id="${attachmentId}"
                           value="${attachment.label || ''}"
                           placeholder="e.g., Business Plan, CV, Recommendation Letter">
                    
                    ${!attachment.isDefined && hasNext ? 
                        `<div class="form-text text-info">
                            <i class="bi bi-info-circle"></i>
                            Define a label to enable Attachment ${attachmentId + 1}
                        </div>` : ''
                    }
                    
                    ${isLast && hasNext && attachment.isDefined ? 
                        `<div class="form-text text-success">
                            <i class="bi bi-check-circle"></i>
                            You can now add Attachment ${attachmentId + 1}
                        </div>` : ''
                    }
                </div>
            `;
            
            container.append(inputGroup);
        });
        
        // Attach remove events
        $('.remove-label-btn').click(function() {
            const id = $(this).data('id');
            removeAttachment(id);
        });
        
        // Attach input change events for real-time validation
        $('.label-input').on('input', function() {
            const id = $(this).data('attachment-id');
            const value = $(this).val().trim();
            
            if (value) {
                $(this).removeClass('is-invalid');
                $(this).addClass('is-valid');
            } else {
                $(this).removeClass('is-valid');
            }
        });
    }
    
    // Save labels from modal
    function saveLabelsFromModal() {
        let hasErrors = false;
        
        $('.label-input').each(function() {
            const id = $(this).data('attachment-id');
            const value = $(this).val().trim();
            const attachment = attachmentData[id];
            
            if (attachment) {
                if (value) {
                    attachment.label = value;
                    attachment.isDefined = true;
                    $(this).removeClass('is-invalid');
                    $(this).addClass('is-valid');
                } else if (!attachment.isDefined) {
                    // First attachment requires a label if not already defined
                    if (id === 1 && !attachment.path && !attachment.fileName) {
                        $(this).addClass('is-invalid');
                        hasErrors = true;
                    }
                }
            }
        });
        
        if (hasErrors) {
            Swal.fire({
                icon: 'warning',
                title: 'Missing Labels',
                text: 'Please define labels for required attachments'
            });
            return false;
        }
        
        // Check if we need to add new empty attachments
        const definedCount = Object.values(attachmentData).filter(a => a.isDefined).length;
        if (definedCount === Object.keys(attachmentData).length && definedCount < maxAttachments) {
            // Add next empty attachment
            addAttachment();
        }
        
        renderAttachments();
        saveAttachmentsToStorage();
        $('#customizeModal').modal('hide');
        Swal.fire({
            icon: 'success',
            title: 'Labels Updated',
            text: 'Attachment labels updated successfully!',
            timer: 2000,
            showConfirmButton: false
        });
        return true;
    }
    
    // Clear all attachments (except first)
    function clearAllAttachments() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'Permanently clear all additional attachments? This will remove all labels and files.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Yes, clear all'
        }).then((result) => {
            if (result.isConfirmed) {
                // Keep only the first attachment
                attachmentData = {
                    1: {
                        path: attachmentData[1]?.path || '',
                        label: '',
                        fileName: '',
                        filePreview: '',
                        isDefined: false
                    }
                };
                
                renderAttachments();
                updateLabelModal();
                saveAttachmentsToStorage();
                Swal.fire({
                    icon: 'info',
                    title: 'Cleared',
                    text: 'All attachments cleared',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        });
    }
    
    // Storage functions (for session persistence)
    function saveAttachmentsToStorage() {
        try {
            sessionStorage.setItem('edit_attachments_' + <?= $customer_id ?>, JSON.stringify(attachmentData));
        } catch (e) {
            console.error('Error saving attachments to storage:', e);
        }
    }
    
    function loadAttachmentsFromStorage() {
        try {
            const saved = sessionStorage.getItem('edit_attachments_' + <?= $customer_id ?>);
            if (saved) {
                return JSON.parse(saved);
            }
        } catch (e) {
            console.error('Error loading attachments from storage:', e);
        }
        return null;
    }
    
    // Initialize dynamic attachments
    const savedAttachments = loadAttachmentsFromStorage();
    if (savedAttachments) {
        attachmentData = savedAttachments;
    }
    initializeAttachments();
    
    $('#add-attachment-btn').click(addAttachment);
    $('#save-labels-btn').click(saveLabelsFromModal);
    $('#clear-all-btn').click(clearAllAttachments);
    
    // Handle modal focus management for accessibility
    $('#customizeModal').on('hidden.bs.modal', function () {
        // Blur any focused element inside to prevent aria-hidden focus error
        $(this).find(':focus').blur();
        // Return focus to the trigger button
        $('#customize-labels-btn').focus();
    });

    $('#camera-modal').on('hidden.bs.modal', function () {
        // Blur any focused element inside
        $(this).find(':focus').blur();
        // Return focus to the trigger button
        $('#open-camera').focus();
        
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
    });
    
    // ================== EXISTING FUNCTIONALITY ==================
    
    // Preview customer image when file is selected
    $('#customer_photo').change(function() {
        if (this.files && this.files[0]) {
            var reader = new FileReader();
            
            reader.onload = function(e) {
                $('#image-preview').html('<img src="' + e.target.result + '" style="max-width: 100%; max-height: 100%; border-radius: 6px;">');
                $('#remove-photo').show();
            }
            
            reader.readAsDataURL(this.files[0]);
        }
    });
    
    // Remove customer photo
    $('#remove-photo').click(function() {
        $('#image-preview').html('<span class="text-muted">No Photo</span>');
        $('#customer_photo').val('');
        $(this).hide();
        // Add hidden field to indicate photo removal
        $('#edit-form').append('<input type="hidden" name="remove_photo" value="1">');
    });
    
    // Preview ID attachment when file is selected
    $('#id_attachment').change(function() {
        if (this.files && this.files[0]) {
            var file = this.files[0];
            var reader = new FileReader();
            
            // Check if file is PDF
            if (file.type === 'application/pdf') {
                $('#id-preview').html('<div class="text-center p-3"><i class="bi bi-file-earmark-pdf" style="font-size: 3rem; color: #dc3545;"></i><br>' + 
                                     '<small>' + file.name + '</small></div>');
            } 
            // Check if file is an image
            else if (file.type.match('image.*')) {
                reader.onload = function(e) {
                    $('#id-preview').html('<img src="' + e.target.result + '" style="max-width: 100%; max-height: 100%; border-radius: 6px;">');
                }
                reader.readAsDataURL(file);
            } 
            // Word documents
            else if (file.type.match('application/msword') || file.type.match('application/vnd.openxmlformats-officedocument.wordprocessingml.document')) {
                $('#id-preview').html('<div class="text-center p-3"><i class="bi bi-file-earmark-word" style="font-size: 3rem; color: #0d6efd;"></i><br>' + 
                                     '<small>' + file.name + '</small></div>');
            }
            // Other file types
            else {
                $('#id-preview').html('<div class="text-center p-3"><i class="bi bi-file-earmark" style="font-size: 3rem; color: #6c757d;"></i><br>' + 
                                     '<small>' + file.name + '</small></div>');
            }
            
            $('#remove-id').show();
        }
    });
    
    // Remove ID attachment
    $('#remove-id').click(function() {
        $('#id-preview').html('<span class="text-muted">No File</span>');
        $('#id_attachment').val('');
        $(this).hide();
        // Add hidden field to indicate ID removal
        $('#edit-form').append('<input type="hidden" name="remove_id_attachment" value="1">');
    });
    
    // Remove existing business attachment
    $('.remove-existing-file').click(function() {
        const field = $(this).data('field');
        $(this).closest('.mb-2').remove();
        // Add hidden field to indicate file removal
        $('#edit-form').append(`<input type="hidden" name="remove_${field}" value="1">`);
    });
    
    // Camera functionality
    const cameraModal = new bootstrap.Modal(document.getElementById('camera-modal'));
    let stream = null;
    
    $('#open-camera').click(function() {
        cameraModal.show();
        
        // Access camera
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
                .then(function(mediaStream) {
                    stream = mediaStream;
                    const video = document.getElementById('video');
                    video.srcObject = mediaStream;
                    video.play();
                })
                .catch(function(error) {
                    console.error("Error accessing camera: ", error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Camera Error',
                        text: 'Failed to open camera. Please ensure you allow camera access.'
                    });
                });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Not Supported',
                text: 'Camera is not available or not supported by your browser.'
            });
        }
    });
    
    // Capture photo
    $('#capture-btn').click(function() {
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const context = canvas.getContext('2d');
        
        // Set canvas dimensions to match video
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        // Draw video frame to canvas
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        // Convert canvas to blob and create File object
        canvas.toBlob(function(blob) {
            const file = new File([blob], 'captured_photo_' + new Date().getTime() + '.png', { type: 'image/png' });
            
            // Create a DataTransfer object to simulate file input
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            
            // Set the file input files
            document.getElementById('customer_photo').files = dataTransfer.files;
            
            // Trigger change event to update preview
            $('#customer_photo').trigger('change');
            
            // Close camera and stop stream
            cameraModal.hide();
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
        }, 'image/png');
    });
    
    // Clean up camera when modal is closed
    $('#camera-modal').on('hidden.bs.modal', function() {
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
    });
    
    // ================== ADDRESS CASCADING DROPDOWNS ==================
    
    // Filter regions based on selected country
    $('#country').change(function() {
        const countryId = $(this).val();
        
        if (!countryId) {
            // Reset region and district
            $('#region').val('');
            $('#district').html('<option value="">Select District</option>');
            return;
        }
        
        // Filter regions for this country
        const regionSelect = $('#region');
        const currentRegion = regionSelect.val();
        
        // Hide all options except the empty one
        regionSelect.find('option').each(function() {
            const option = $(this);
            if (option.val() === '') {
                option.show();
            } else if (option.data('country') == countryId) {
                option.show();
            } else {
                option.hide();
                // If current region is not in this country, clear it
                if (option.val() === currentRegion) {
                    regionSelect.val('');
                }
            }
        });
        
        // If current region is not valid for selected country, clear it
        const currentRegionOption = regionSelect.find('option[value="' + currentRegion + '"]');
        if (currentRegion && (!currentRegionOption.length || currentRegionOption.is(':hidden'))) {
            regionSelect.val('');
            // Also clear district
            $('#district').html('<option value="">Select District</option>');
        }
    });
    
    // Filter districts based on selected region
    $('#region').change(function() {
        const regionId = $(this).val();
        
        if (!regionId) {
            // Reset district
            $('#district').html('<option value="">Select District</option>');
            return;
        }
        
        // Get all available districts
        const allDistricts = <?= json_encode($all_districts) ?>;
        const districtSelect = $('#district');
        const currentDistrict = districtSelect.val();
        
        // Clear and rebuild district options
        districtSelect.html('<option value="">Select District</option>');
        
        // Add districts for this region
        allDistricts.forEach(function(district) {
            if (district.region_id == regionId) {
                districtSelect.append(
                    $('<option></option>')
                        .val(district.district_id)
                        .text(district.district_name)
                        .data('region', district.region_id)
                );
            }
        });
        
        // Set the current district if it belongs to this region
        if (currentDistrict) {
            districtSelect.val(currentDistrict);
        }
    });
    
    // Initialize cascading dropdowns with current values
    $(function() {
        // First, filter regions based on selected country
        const selectedCountry = $('#country').val();
        if (selectedCountry) {
            $('#region').find('option').each(function() {
                const option = $(this);
                if (option.val() === '' || option.data('country') == selectedCountry) {
                    option.show();
                } else {
                    option.hide();
                }
            });
        }
        
        // Then, filter districts based on selected region
        const selectedRegion = $('#region').val();
        if (selectedRegion) {
            const allDistricts = <?= json_encode($all_districts) ?>;
            const districtSelect = $('#district');
            
            // Clear and rebuild district options
            districtSelect.html('<option value="">Select District</option>');
            
            // Add districts for this region
            allDistricts.forEach(function(district) {
                if (district.region_id == selectedRegion) {
                    districtSelect.append(
                        $('<option></option>')
                            .val(district.district_id)
                            .text(district.district_name)
                            .data('region', district.region_id)
                    );
                }
            });
            
            // Set the current district
            const currentDistrict = <?= json_encode($customer['district_id'] ?? '') ?>;
            if (currentDistrict) {
                districtSelect.val(currentDistrict);
            }
        }
    });
    
    // File upload preview functionality for business attachments
    $('input[type="file"]').not('.attachment-file').change(function() {
        const file = this.files[0];
        if (!file) {
            // Clear preview if file is removed
            const previewContainer = $(this).siblings('.file-preview-container');
            previewContainer.html('');
            return;
        }
        
        const inputId = $(this).attr('id');
        const previewContainer = $(this).siblings('.file-preview-container');
        const fileType = file.type;
        
        // Clear previous preview
        previewContainer.html('');
        
        // Show file info
        const fileSize = (file.size / (1024 * 1024)).toFixed(2); // Convert to MB
        
        if (fileType.startsWith('image/')) {
            // Image preview
            const reader = new FileReader();
            reader.onload = function(e) {
                previewContainer.html(
                    '<div class="text-center">' +
                        '<img src="' + e.target.result + '" class="img-thumbnail mb-2" style="max-height: 120px;">' +
                        '<p class="small mb-1 text-truncate"><strong>' + file.name + '</strong></p>' +
                        '<p class="small text-muted">' + fileSize + ' MB</p>' +
                    '</div>'
                );
            };
            reader.readAsDataURL(file);
        } else if (fileType === 'application/pdf') {
            // PDF preview placeholder
            previewContainer.html(
                '<div class="text-center p-2">' +
                    '<i class="bi bi-file-earmark-pdf" style="font-size: 2.5rem; color: #dc3545;"></i>' +
                    '<p class="mt-2 small mb-1 text-truncate"><strong>' + file.name + '</strong></p>' +
                    '<p class="small text-muted">' + fileSize + ' MB</p>' +
                '</div>'
            );
        } else if (fileType.includes('word') || fileType.includes('document')) {
            // Word document preview placeholder
            previewContainer.html(
                '<div class="text-center p-2">' +
                    '<i class="bi bi-file-earmark-word" style="font-size: 2.5rem; color: #2b579a;"></i>' +
                    '<p class="mt-2 small mb-1 text-truncate"><strong>' + file.name + '</strong></p>' +
                    '<p class="small text-muted">' + fileSize + ' MB</p>' +
                '</div>'
            );
        } else {
            // Generic file preview
            previewContainer.html(
                '<div class="text-center p-2">' +
                    '<i class="bi bi-file-earmark" style="font-size: 2.5rem; color: #6c757d;"></i>' +
                    '<p class="mt-2 small mb-1 text-truncate"><strong>' + file.name + '</strong></p>' +
                    '<p class="small text-muted">' + fileSize + ' MB</p>' +
                '</div>'
            );
        }
    });
    
    // Form validation and submission
    $('#edit-form').on('submit', function(e) {
        e.preventDefault();

        // Clear previous messages
        $('#form-message').html('');
        $('.is-invalid').removeClass('is-invalid');

        // Validate required fields
        let isValid = true;
        $('[required]').each(function() {
            if (!$(this).val()) {
                $(this).addClass('is-invalid');
                isValid = false;
            }
        });

        // Validate phone number format
        const phoneNumber = $('#phone_number').val();
        if (phoneNumber && !/^[\d\s\-\+\(\)]{10,}$/.test(phoneNumber)) {
            $('#phone_number').addClass('is-invalid');
            isValid = false;
        }

        if (!isValid) {
            Swal.fire({
                icon: 'error',
                title: 'Validation Error',
                text: 'Please fill in all required fields correctly'
            });
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            return;
        }

        // Validate file sizes
        let fileSizeValid = true;
        let largeFiles = [];
        $('input[type="file"]').each(function() {
            if (this.files[0]) {
                const maxSize = this.id === 'customer_photo' ? 5 : 10; // 5MB for photo, 10MB for others
                if (this.files[0].size > maxSize * 1024 * 1024) {
                    $(this).addClass('is-invalid');
                    fileSizeValid = false;
                    largeFiles.push(this.id.replace(/_/g, ' '));
                }
            }
        });
        
        if (!fileSizeValid) {
            Swal.fire({
                icon: 'error',
                title: 'File Too Large',
                text: 'File size exceeded: ' + largeFiles.join(', ') + '. Max 5MB for photo, 10MB for other files.'
            });
            return;
        }

        // Validate file types
        let fileTypeValid = true;
        let invalidFiles = [];
        $('input[type="file"]').each(function() {
            if (this.files[0]) {
                const file = this.files[0];
                const allowedTypes = [
                    'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 
                    'application/pdf', 
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ];
                
                if (!allowedTypes.includes(file.type)) {
                    $(this).addClass('is-invalid');
                    fileTypeValid = false;
                    invalidFiles.push(this.id.replace(/_/g, ' ') + ' (' + file.type + ')');
                }
            }
        });
        
        if (!fileTypeValid) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid File Type',
                text: 'Invalid file type for: ' + invalidFiles.join(', ') + '. Only JPG, PNG, GIF, PDF, and Word documents are allowed.'
            });
            return;
        }

        // Show loading state
        const submitBtn = $(this).find('[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

        // Get form data
        const formData = new FormData(this);

        // Explicitly ensure customer_id is included (failsafe)
        const customerId = <?= intval($customer['customer_id']) ?>;
        formData.set('customer_id', customerId);

        // Add dynamic attachments count for server-side validation
        const totalDynamicAttachments = Object.keys(attachmentData).length;
        formData.set('total_dynamic_attachments', totalDynamicAttachments);

        // AJAX request
        $.ajax({
            url: '<?= buildUrl('api/process_edit_customer.php') ?>',
            type: 'POST',
            data: formData,
            dataType: 'json',
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = '<?= getUrl('customers/view') ?>?id=<?= $customer['customer_id'] ?>';
                    });
                    
                    // Clear session storage
                    sessionStorage.removeItem('edit_attachments_' + <?= $customer_id ?>);
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        text: response.message
                    });
                }
            },
            error: function(xhr, status, error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Server Error',
                    text: 'An error occurred. Please try again. (' + error + ')'
                });
            },
            complete: function() {
                submitBtn.prop('disabled', false).html(originalText);
            }
        });
    });
    
    // Real-time validation
    $('input, select, textarea').on('blur', function() {
        if ($(this).prop('required') && !$(this).val()) {
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });
    

});
</script>

<?php
echo '</body>';
echo '</html>';

// Flush the buffer
ob_end_flush();
?>