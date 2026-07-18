<?php
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

// Include the header
includeHeader();

// Enforce permission (if applicable)
if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('documents');
}

// Fetch categories for filters (if needed)
$signature_types = ['uploaded', 'drawn', 'typed'];
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-pen-fill"></i> Electronic Signatures</h2>
                    <p class="text-muted mb-0">Manage your digital signatures and sign documents electronically</p>
                </div>
                <div>
                    <?php if (canCreate('documents')): ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openUploadSignatureModal()">
                        <i class="bi bi-cloud-upload"></i> Upload Signature
                    </button>
                    <button type="button" class="btn btn-success btn-sm" onclick="openDrawSignatureModal()">
                        <i class="bi bi-pencil"></i> Draw Signature
                    </button>
                    <button type="button" class="btn btn-info btn-sm" onclick="location.href='select_document_add_esignature'">
                        <i class="bi bi-file-earmark-check"></i> Sign Document
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-my-signatures">0</h4>
                            <p class="mb-0">My Signatures</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-signature" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-pending-signatures">0</h4>
                            <p class="mb-0">Pending Signatures</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-clock-history" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-signed-documents">0</h4>
                            <p class="mb-0">Signed Documents</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-check-circle-fill" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-history">0</h4>
                            <p class="mb-0">Total History</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-archive" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" id="signatureTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="my-signatures-tab" data-bs-toggle="tab" data-bs-target="#my-signatures" type="button" role="tab">
                <i class="bi bi-signature"></i> My Signatures
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab">
                <i class="bi bi-clock-history"></i> Pending Signatures <span class="badge bg-warning text-dark" id="pending-count">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                <i class="bi bi-archive"></i> Signature History
            </button>
        </li>
    </ul>

    <!-- Tab Content -->
    <div class="tab-content" id="signatureTabContent">
        <!-- My Signatures Tab -->
        <div class="tab-pane fade show active" id="my-signatures" role="tabpanel">
            <div class="card">
                <div class="card-header custom-table-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">My Saved Signatures</h5>
                        <span class="badge bg-light text-dark" id="stat-signatures-count">0 signatures</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="signaturesTable" class="table table-hover align-middle" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Preview</th>
                                    <th>Type</th>
                                    <th>Created At</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <!-- Data loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Signatures Tab -->
        <div class="tab-pane fade" id="pending" role="tabpanel">
            <div class="card">
                <div class="card-header custom-table-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Documents Awaiting Your Signature</h5>
                        <span class="badge bg-light text-dark" id="stat-pending-count">0 documents</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="pendingTable" class="table table-hover align-middle" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Document</th>
                                    <th>Requested By</th>
                                    <th>Customer</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <!-- Data loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Signature History Tab -->
        <div class="tab-pane fade" id="history" role="tabpanel">
            <div class="card">
                <div class="card-header custom-table-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Signature History</h5>
                        <span class="badge bg-light text-dark" id="stat-history-count">0 records</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="historyTable" class="table table-hover align-middle" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Document</th>
                                    <th>Customer</th>
                                    <th>Signed At</th>
                                    <th>IP Address</th>
                                    <th>Position</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <!-- Data loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Signature Modal -->
<div class="modal fade" id="uploadSignatureModal" tabindex="-1" aria-labelledby="uploadSignatureModalLabel">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadSignatureModalLabel"><i class="bi bi-cloud-upload"></i> Upload Signature</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="uploadSignatureForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="signature_file" class="form-label">Signature Image</label>
                        <input type="file" class="form-control" id="signature_file" name="signature_file"
                               accept=".png,.jpg,.jpeg" required>
                        <div class="form-text">
                            Upload a clear image of your signature. Supported formats: PNG, JPG. Max size: 2&nbsp;MB.
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 
                        For best results, use a white background and black ink signature.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnUploadSignature">Upload Signature</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Draw Signature Modal -->
<div class="modal fade" id="drawSignatureModal" tabindex="-1" aria-labelledby="drawSignatureModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="drawSignatureModalLabel"><i class="bi bi-pencil"></i> Draw Your Signature</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="drawSignatureForm">
                <input type="hidden" name="signature_data" id="signatureData">
                <div class="modal-body">
                    <div class="signature-pad-container mb-3">
                        <canvas id="signaturePad" width="700" height="200" 
                                style="border: 2px solid #0d6efd; border-radius: 8px; cursor: crosshair; width: 100%; height: 200px; min-height: 200px; background: repeating-linear-gradient(45deg, #f8f9fa, #f8f9fa 10px, #ffffff 10px, #ffffff 20px); display: block; margin: 0 auto;"></canvas>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="clearSignature()">
                            <i class="bi bi-eraser"></i> Clear
                        </button>
                        <div>
                            <small class="text-muted">Draw your signature in the box above</small>
                        </div>
                    </div>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i> 
                        By saving this signature, you acknowledge it as your legally binding electronic signature.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="clearSignature()">Clear</button>
                    <button type="submit" class="btn btn-primary" id="saveSignatureBtn" disabled>Save Signature</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Apply Signature Modal -->
<div class="modal fade" id="applySignatureModal" tabindex="-1" aria-labelledby="applySignatureModalLabel">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="applySignatureModalLabel"><i class="bi bi-pen"></i> Sign Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="applySignatureForm">
                <input type="hidden" name="document_id" id="applyDocumentId">
                <input type="hidden" name="signature_id" id="applySignatureId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Your Signature</label>
                        <div id="signatureSelection" class="row">
                            <!-- Signatures will be loaded here -->
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Signature Position</label>
                        <select class="form-select" name="signature_position" id="signaturePosition">
                            <option value="bottom_right">Bottom Right</option>
                            <option value="bottom_left">Bottom Left</option>
                            <option value="bottom_center">Bottom Center</option>
                            <option value="custom">Custom Position</option>
                        </select>
                    </div>
                    <div class="alert alert-info">
                        <i class="bi bi-shield-check"></i> 
                        This action will apply your electronic signature to the document. This is legally binding.
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="legalAgreement" required>
                        <label class="form-check-label" for="legalAgreement">
                            I agree that this electronic signature is legally binding and equivalent to my handwritten signature.
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="applySignatureBtn" disabled>Apply Signature</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Sign Document Modal -->
<div class="modal fade" id="signDocumentModal" tabindex="-1" aria-labelledby="signDocumentModalLabel">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="signDocumentModalLabel"><i class="bi bi-file-earmark-check"></i> Sign Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Step Indicator -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="step-item active" id="step1-indicator">
                                <div class="step-circle">1</div>
                                <div class="step-label">Select Document</div>
                            </div>
                            <div class="step-line"></div>
                            <div class="step-item" id="step2-indicator">
                                <div class="step-circle">2</div>
                                <div class="step-label">Choose Signature</div>
                            </div>
                            <div class="step-line"></div>
                            <div class="step-item" id="step3-indicator">
                                <div class="step-circle">3</div>
                                <div class="step-label">Download</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 1: Select Document -->
                <div id="step1-content" class="step-content">
                    <ul class="nav nav-tabs mb-3" id="documentSelectTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="existing-doc-tab" data-bs-toggle="tab" data-bs-target="#existing-doc" type="button" role="tab">
                                <i class="bi bi-folder2-open"></i> Select Existing Document
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="upload-doc-tab" data-bs-toggle="tab" data-bs-target="#upload-doc" type="button" role="tab">
                                <i class="bi bi-cloud-upload"></i> Upload New Document
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="documentSelectTabContent">
                        <!-- Existing Documents Tab -->
                        <div class="tab-pane fade show active" id="existing-doc" role="tabpanel">
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <input type="text" class="form-control" id="docSearchInput" placeholder="Search documents...">
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" id="docCategoryFilter">
                                        <option value="">All Categories</option>
                                        <?php 
                                        $categories = $pdo->query("SELECT * FROM document_categories ORDER BY category_name ASC")->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($categories as $cat): 
                                        ?>
                                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="button" class="btn btn-primary w-100" onclick="loadDocuments()">
                                        <i class="bi bi-search"></i> Search
                                    </button>
                                </div>
                            </div>
                            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                <table class="table table-hover" id="documentsSelectTable">
                                    <thead class="bg-light sticky-top">
                                        <tr>
                                            <th width="50">Select</th>
                                            <th>Document Name</th>
                                            <th>Category</th>
                                            <th>Size</th>
                                            <th>Uploaded</th>
                                        </tr>
                                    </thead>
                                    <tbody id="documentsSelectBody">
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">
                                                <i class="bi bi-search"></i> Click search to load documents
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Upload New Document Tab -->
                        <div class="tab-pane fade" id="upload-doc" role="tabpanel">
                            <form id="quickUploadForm">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="quick_document_name" class="form-label">Document Title *</label>
                                        <input type="text" class="form-control" id="quick_document_name" name="document_name" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="quick_category_id" class="form-label">Category</label>
                                        <select class="form-select" id="quick_category_id" name="category_id">
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12">
                                        <label for="quick_description" class="form-label">Description</label>
                                        <textarea class="form-control" id="quick_description" name="description" rows="2"></textarea>
                                    </div>
                                    <div class="col-12">
                                        <label for="quick_document_file" class="form-label">File Selection *</label>
                                        <input type="file" class="form-control" id="quick_document_file" name="document_file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg">
                                        <div class="form-text">PDF, Word, Excel, Images. Max size: 50MB</div>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary" id="btnQuickUpload">
                                            <i class="bi bi-cloud-upload"></i> Upload & Continue
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Choose Signature -->
                <div id="step2-content" class="step-content" style="display: none;">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Selected Document: <strong id="selectedDocName">-</strong>
                    </div>
                    <h6 class="mb-3">Select Your Signature</h6>
                    <div class="row" id="signatureSelectionGrid">
                        <div class="col-12 text-center text-muted">
                            <i class="bi bi-hourglass-split"></i> Loading signatures...
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Signature Position</label>
                        <select class="form-select" id="finalSignaturePosition">
                            <option value="bottom_right">Bottom Right</option>
                            <option value="bottom_left">Bottom Left</option>
                            <option value="bottom_center">Bottom Center</option>
                        </select>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="finalLegalAgreement" required>
                        <label class="form-check-label" for="finalLegalAgreement">
                            I agree that this electronic signature is legally binding and equivalent to my handwritten signature.
                        </label>
                    </div>
                </div>

                <!-- Step 3: Download -->
                <div id="step3-content" class="step-content" style="display: none;">
                    <div class="text-center py-5">
                        <div id="processingIndicator">
                            <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                                <span class="visually-hidden">Processing...</span>
                            </div>
                            <h5>Processing your signed document...</h5>
                            <p class="text-muted">Please wait while we apply your signature</p>
                        </div>
                        <div id="downloadReady" style="display: none;">
                            <i class="bi bi-check-circle-fill text-success" style="font-size: 4rem;"></i>
                            <h5 class="mt-3">Document Signed Successfully!</h5>
                            <p class="text-muted">Your document is ready for download</p>
                            <button type="button" class="btn btn-success btn-lg mt-3" id="btnDownloadSigned">
                                <i class="bi bi-download"></i> Download Signed Document
                            </button>
                            <div class="mt-3">
                                <small class="text-muted">File: <span id="signedFileName">-</span></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-secondary" id="btnPrevStep" style="display: none;" onclick="previousStep()">
                    <i class="bi bi-arrow-left"></i> Previous
                </button>
                <button type="button" class="btn btn-primary" id="btnNextStep" onclick="nextStep()" disabled>
                    Next <i class="bi bi-arrow-right"></i>
                </button>
                <button type="button" class="btn btn-success" id="btnApplySignature" style="display: none;" onclick="applySignatureToDocument()" disabled>
                    <i class="bi bi-pen"></i> Apply Signature
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts Section -->
<!-- DataTables JS is handled by footer.php -->

<script>
// Signature Pad functionality
let signaturePad = null;
let isDrawing = false;
let selectedSignatureId = null;

$(document).ready(function() {
    // Audit Log for Page View
    logReportAction('Viewed E-Signatures', 'User viewed the electronic signatures dashboard');

    const userPermissions = {
        canEdit: <?= canEdit('documents') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('documents') ? 'true' : 'false' ?>
    };

    // Initialize My Signatures Table
    const signaturesTable = $('#signaturesTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/get_user_signatures.php`,
            dataSrc: json => {
                $('#stat-my-signatures').text(json.recordsTotal);
                $('#stat-signatures-count').text(json.recordsTotal + ' signatures');
                return json.data;
            }
        },
        columns: [
            { 
                data: null, 
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1 
            },
            { 
                data: null,
                orderable: false,
                render: (data, t, row) => {
                    if (row.thumbnail_path) {
                        return `<img src="${APP_URL}/${row.thumbnail_path.replace(/^\//, '')}" class="signature-preview" style="max-height: 60px; max-width: 120px; border: 1px solid #dee2e6; border-radius: 4px; padding: 5px; background: #f8f9fa;">`;
                    }
                    return `<div class="signature-placeholder" style="width: 120px; height: 60px; background: #f8f9fa; display: flex; align-items: center; justify-content: center; border-radius: 4px; border: 1px solid #dee2e6;">
                        <i class="bi bi-${getSignatureTypeIcon(row.signature_type)} fs-3 text-muted"></i>
                    </div>`;
                }
            },
             { 
                data: 'signature_type',
                render: data => {
                    let val = data || 'typed';
                    let color = val === 'uploaded' ? 'primary' : (val === 'drawn' ? 'success' : 'info');
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-2 text-uppercase">
                        <i class="bi bi-${getSignatureTypeIcon(val)}"></i> ${val}
                    </span>`;
                }
            },
            { 
                data: 'created_at',
                render: data => data ? new Date(data).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'}) : 'N/A'
            },
            { 
                data: 'status',
                render: data => data === 'active' ? 
                    '<span class="badge bg-success-subtle text-success border border-success-subtle px-3"><i class="bi bi-check-circle"></i> Active</span>' : 
                    '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3"><i class="bi bi-x-circle"></i> Inactive</span>'
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => {
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" onclick="selectSignature(${row.id}, this)"><i class="bi bi-check"></i> Select</a></li>
                            <li><a class="dropdown-item" href="${APP_URL}/${row.file_path.replace(/^\//, '')}" target="_blank"><i class="bi bi-eye"></i> View Full Size</a></li>`;
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="confirmDeleteSignature(${row.id})"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        order: [[3, 'desc']]
    });

    // Initialize Pending Signatures Table
    const pendingTable = $('#pendingTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/get_pending_signatures.php`,
            dataSrc: json => {
                $('#stat-pending-signatures').text(json.recordsTotal);
                $('#pending-count').text(json.recordsTotal);
                $('#stat-pending-count').text(json.recordsTotal + ' documents');
                return json.data;
            }
        },
        columns: [
            { 
                data: null, 
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1 
            },
            { 
                data: 'document_name',
                render: (data, t, row) => `<strong>${escapeHtml(data)}</strong><br><small class="text-muted">${escapeHtml(row.document_type || 'N/A')}</small>`
            },
            { data: 'requested_by_name' },
            { 
                data: 'customer_name',
                render: data => data ? escapeHtml(data) : '<span class="text-muted">N/A</span>'
            },
            { 
                data: 'due_date',
                render: data => {
                    if (!data) return '<span class="text-muted">No due date</span>';
                    const dueDate = new Date(data);
                    const today = new Date();
                    const isOverdue = dueDate < today;
                    const color = isOverdue ? 'danger' : 'warning';
                    return `<span class="badge bg-${color}-subtle text-${color}">${dueDate.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'})}</span>`;
                }
            },
            { 
                data: 'status',
                render: data => {
                    let color = data === 'pending' ? 'warning' : (data === 'signed' ? 'success' : 'danger');
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-3 text-uppercase">${data}</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => row.signer_type === 'external'
                    // This row is a request WE sent to an outside party — we
                    // can't sign it ourselves, just wait for them.
                    ? `<span class="badge bg-secondary-subtle text-secondary border px-3">Awaiting external signer</span>
                       <button class="btn btn-sm btn-outline-secondary" onclick="previewDocument(${row.document_id})">
                           <i class="bi bi-eye"></i> Preview
                       </button>`
                    : `<button class="btn btn-sm btn-primary" onclick="signDocument(${row.document_id}, ${row.id})">
                           <i class="bi bi-pen"></i> Sign
                       </button>
                       <button class="btn btn-sm btn-outline-secondary" onclick="previewDocument(${row.document_id})">
                           <i class="bi bi-eye"></i> Preview
                       </button>`
            }
        ],
        order: [[3, 'asc']]
    });

    // Initialize Signature History Table
    const historyTable = $('#historyTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/get_signature_history.php`,
            dataSrc: json => {
                $('#stat-signed-documents').text(json.stats?.signedDocuments || 0);
                $('#stat-total-history').text(json.recordsTotal);
                $('#stat-history-count').text(json.recordsTotal + ' records');
                return json.data;
            }
        },
        columns: [
            { 
                data: null, 
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1 
            },
            { 
                data: 'document_name',
                render: (data, t, row) => `<strong>${escapeHtml(data)}</strong><br><small class="text-muted">${escapeHtml(row.document_type || 'N/A')}</small>`
            },
            { 
                data: 'customer_name',
                render: data => data ? escapeHtml(data) : '<span class="text-muted">N/A</span>'
            },
            { 
                data: 'signed_at',
                render: data => new Date(data).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit'})
            },
            { 
                data: 'ip_address',
                render: data => `<code class="small">${escapeHtml(data)}</code>`
            },
            { 
                data: 'signature_position',
                render: data => `<span class="badge bg-secondary-subtle text-secondary">${data.replace('_', ' ')}</span>`
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => `
                    <button class="btn btn-sm btn-outline-secondary" onclick="viewSignedDocument(${row.document_id})">
                        <i class="bi bi-file-earmark-check"></i> View
                    </button>
                `
            }
        ],
        order: [[2, 'desc']]
    });

    // Legal agreement checkbox
    $('#legalAgreement').on('change', function() {
        $('#applySignatureBtn').prop('disabled', !this.checked || !selectedSignatureId);
    });
});

// Signature Pad Initialization
let canvas, ctx;
let lastX = 0, lastY = 0;

function initSignaturePad() {
    canvas = document.getElementById('signaturePad');
    if (!canvas) {
        console.error('Signature canvas not found');
        return;
    }
    
    // Remove existing event listeners to prevent duplicates
    const newCanvas = canvas.cloneNode(true);
    canvas.parentNode.replaceChild(newCanvas, canvas);
    canvas = newCanvas;
    
    ctx = canvas.getContext('2d');
    
    // Set up high-DPI canvas with proper dimensions
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    
    // Set actual canvas size (for drawing)
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    
    // Scale context to match device pixel ratio
    ctx.scale(dpr, dpr);
    
    // Clear canvas with transparent background (NO WHITE FILL for transparency)
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Configure drawing style
    ctx.strokeStyle = '#000000';
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    
    // Mouse events
    canvas.addEventListener('mousedown', startDrawing);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', stopDrawing);
    canvas.addEventListener('mouseleave', stopDrawing);
    
    // Touch events for mobile
    canvas.addEventListener('touchstart', handleTouchStart);
    canvas.addEventListener('touchmove', handleTouchMove);
    canvas.addEventListener('touchend', stopDrawing);
    
    // Disable save button initially
    $('#saveSignatureBtn').prop('disabled', true);
    
    console.log('Signature pad initialized successfully');
}

function getCoordinates(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    
    if (e.touches && e.touches.length > 0) {
        return {
            x: (e.touches[0].clientX - rect.left) * scaleX / (window.devicePixelRatio || 1),
            y: (e.touches[0].clientY - rect.top) * scaleY / (window.devicePixelRatio || 1)
        };
    }
    
    return {
        x: (e.clientX - rect.left) * scaleX / (window.devicePixelRatio || 1),
        y: (e.clientY - rect.top) * scaleY / (window.devicePixelRatio || 1)
    };
}

function startDrawing(e) {
    e.preventDefault();
    isDrawing = true;
    
    const coords = getCoordinates(e);
    lastX = coords.x;
    lastY = coords.y;
    
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
}

function draw(e) {
    if (!isDrawing) return;
    e.preventDefault();
    
    const coords = getCoordinates(e);
    
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(coords.x, coords.y);
    ctx.stroke();
    
    lastX = coords.x;
    lastY = coords.y;
    
    // Enable save button
    $('#saveSignatureBtn').prop('disabled', false);
}

function stopDrawing(e) {
    if (isDrawing) {
        e.preventDefault();
        isDrawing = false;
        ctx.beginPath();
    }
}

function handleTouchStart(e) {
    e.preventDefault();
    startDrawing(e);
}

function handleTouchMove(e) {
    e.preventDefault();
    draw(e);
}

function clearSignature() {
    if (!canvas || !ctx) return;
    // Clear with transparency instead of white fill
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    $('#saveSignatureBtn').prop('disabled', true);
}

function openUploadSignatureModal() {
    logReportAction('Opened Upload Signature Modal', 'User clicked to upload a new signature image');
    const modal = new bootstrap.Modal(document.getElementById('uploadSignatureModal'));
    modal.show();
}

function openDrawSignatureModal() {
    logReportAction('Opened Draw Signature Modal', 'User clicked to draw a new signature');
    const modal = new bootstrap.Modal(document.getElementById('drawSignatureModal'));
    modal.show();
    // Initialize signature pad after modal is fully shown
    setTimeout(() => {
        initSignaturePad();
    }, 300);
}

// Upload Signature Form
$('#uploadSignatureForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const btn = $('#btnUploadSignature');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Uploading...');

    $.ajax({
        url: `${APP_URL}/api/document/upload_signature.php`,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                logReportAction('Uploaded Signature', 'User successfully uploaded an electronic signature image');
                const modal = bootstrap.Modal.getInstance(document.getElementById('uploadSignatureModal'));
                if (modal) modal.hide();
                $('#signaturesTable').DataTable().ajax.reload();
                showAlert('success', 'Signature uploaded successfully!');
                $('#uploadSignatureForm')[0].reset();
            } else {
                showAlert('error', res.message || 'Failed to upload signature');
            }
        },
        error: function(xhr, status, error) {
            console.error('Upload error:', xhr.responseText);
            let errorMsg = 'Failed to upload signature';
            try {
                const res = JSON.parse(xhr.responseText);
                errorMsg = res.message || errorMsg;
            } catch(e) {}
            showAlert('error', errorMsg);
        },
        complete: () => btn.prop('disabled', false).html('Upload Signature')
    });
});

// Draw Signature Form
$('#drawSignatureForm').on('submit', function(e) {
    e.preventDefault();

    if (!canvas) {
        showAlert('error', 'Signature canvas not found');
        return;
    }

    const signatureData = canvas.toDataURL('image/png');
    
    const btn = $('#saveSignatureBtn');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');

    $.ajax({
        url: `${APP_URL}/ajax/save_drawn_signature.php`,
        type: 'POST',
        data: { signature_data: signatureData },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                logReportAction('Saved Drawn Signature', 'User successfully saved a hand-drawn electronic signature');
                const modal = bootstrap.Modal.getInstance(document.getElementById('drawSignatureModal'));
                if (modal) modal.hide();
                $('#signaturesTable').DataTable().ajax.reload();
                showAlert('success', 'Signature saved successfully!');
                clearSignature();
            } else {
                showAlert('error', res.message || 'Failed to save signature');
            }
        },
        error: function(xhr, status, error) {
            console.error('Save error:', xhr.responseText);
            let errorMsg = 'Failed to save signature';
            try {
                const res = JSON.parse(xhr.responseText);
                errorMsg = res.message || errorMsg;
            } catch(e) {}
            showAlert('error', errorMsg);
        },
        complete: function() {
            btn.prop('disabled', false).html('Save Signature');
        }
    });
});

function selectSignature(signatureId, el) {
    selectedSignatureId = signatureId;

    // Clear previous highlight
    $('#signaturesTable tbody tr').removeClass('table-success selected-sig-row');
    $('#signaturesTable .select-check-icon').remove();

    // Highlight the chosen row and mark the menu item
    const $row = $(el).closest('tr');
    $row.addClass('table-success selected-sig-row');
    $(el).prepend('<i class="bi bi-check-circle-fill text-success select-check-icon me-1"></i>');

    showAlert('success', 'Signature #' + signatureId + ' selected — use it to sign any pending document.');
}

function signDocument(documentId, signatureDocId) {
    if (!selectedSignatureId) {
        showAlert('warning', 'Please select a signature first from "My Signatures" tab.');
        return;
    }
    
    $('#applyDocumentId').val(documentId);
    $('#applySignatureId').val(selectedSignatureId);
    
    // Load user signatures for selection
    $.get(`${APP_URL}/api/document/get_user_signatures_list.php`, function(signatures) {
        let html = '';
        signatures.forEach(sig => {
            const selected = sig.id == selectedSignatureId ? 'border-primary' : '';
            html += `<div class="col-md-4 mb-2">
                <div class="card signature-select-card ${selected}" onclick="selectSignatureForDoc(${sig.id}, this)" style="cursor: pointer;">
                    <div class="card-body text-center p-2">
                        ${sig.thumbnail_path ? 
                            `<img src="${sig.thumbnail_path}" style="max-height: 50px; max-width: 100%;">` :
                            `<i class="bi bi-signature fs-3"></i>`
                        }
                        <small class="d-block mt-1">${sig.signature_type}</small>
                    </div>
                </div>
            </div>`;
        });
        $('#signatureSelection').html(html);
    });
    
    $('#applySignatureModal').modal('show');
}

function selectSignatureForDoc(sigId, el) {
    selectedSignatureId = sigId;
    $('#applySignatureId').val(sigId);
    $('.signature-select-card').removeClass('border-primary');
    $(el).addClass('border-primary');
    
    if ($('#legalAgreement').is(':checked')) {
        $('#applySignatureBtn').prop('disabled', false);
    }
}

// Apply Signature Form
$('#applySignatureForm').on('submit', function(e) {
    e.preventDefault();
    const formData = $(this).serialize();
    const btn = $('#applySignatureBtn');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Applying...');

    $.post(`${APP_URL}/api/document/apply_signature.php`, formData, function(res) {
        if (res.success) {
            logReportAction('Applied E-Signature', 'User applied an e-signature to document ID: ' + $('#applyDocumentId').val());
            $('#applySignatureModal').modal('hide');
            $('#pendingTable').DataTable().ajax.reload();
            $('#historyTable').DataTable().ajax.reload();
            showAlert('success', 'Signature applied successfully!');
        } else {
            showAlert('error', res.message);
        }
    }, 'json').fail(() => showAlert('error', 'Failed to apply signature'))
    .always(() => btn.prop('disabled', false).html('Apply Signature'));
});

function confirmDeleteSignature(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This signature will be permanently removed!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(`${APP_URL}/api/document/delete_signature.php`, { id: id }, function(res) {
                if (res.success) {
                    logReportAction('Deleted E-Signature', 'User deleted electronic signature ID: ' + id);
                    $('#signaturesTable').DataTable().ajax.reload();
                    Swal.fire('Deleted!', 'Signature has been removed.', 'success');
                } else {
                    Swal.fire('Error!', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function previewDocument(docId) {
    logReportAction('Previewed Document (Signing)', 'User opened preview for document ID: ' + docId + ' before signing');
    window.open('document_preview.php?id=' + docId, '_blank');
}

function viewSignedDocument(docId) {
    logReportAction('Viewed Signed Document', 'User viewed the signed version of document ID: ' + docId);
    window.open('signed_document.php?id=' + docId, '_blank');
}

function getSignatureTypeIcon(type) {
    const icons = {
        uploaded: 'upload',
        drawn: 'pencil',
        typed: 'fonts'
    };
    return icons[type] || 'signature';
}

function escapeHtml(text) {
    return text ? $('<div>').text(text).html() : '';
}

function showAlert(type, message) {
    Swal.fire({
        icon: type,
        title: type.charAt(0).toUpperCase() + type.slice(1),
        text: message,
        timer: 3000,
        showConfirmButton: false,
        toast: true,
        position: 'top-end'
    });
}

// Sign Document Modal Logic
let currentStep = 1;
let selectedDocId = null;
let selectedSignatureForSign = null;

function openSignDocumentModal() {
    currentStep = 1;
    selectedDocId = null;
    selectedSignatureForSign = null;
    updateStepUI();
    $('#signDocumentModal').modal('show');
}

function updateStepUI() {
    $('.step-content').hide();
    $(`#step${currentStep}-content`).show();
    
    $('.step-item').removeClass('active completed');
    for(let i=1; i<currentStep; i++) $(`#step${i}-indicator`).addClass('completed');
    $(`#step${currentStep}-indicator`).addClass('active');

    // Button states
    $('#btnPrevStep').toggle(currentStep > 1 && currentStep < 3);
    $('#btnNextStep').toggle(currentStep < 2);
    $('#btnApplySignature').toggle(currentStep === 2);
    
    $('#btnNextStep').prop('disabled', !selectedDocId);
    $('#btnApplySignature').prop('disabled', !selectedSignatureForSign || !$('#finalLegalAgreement').is(':checked'));
}

function nextStep() {
    if (currentStep < 3) {
        currentStep++;
        if (currentStep === 2) loadSignaturesForSelect();
        updateStepUI();
    }
}

function previousStep() {
    if (currentStep > 1) {
        currentStep--;
        updateStepUI();
    }
}

function loadDocuments() {
    const search = $('#docSearchInput').val();
    const category = $('#docCategoryFilter').val();
    const tbody = $('#documentsSelectBody');
    
    tbody.html('<tr><td colspan="5" class="text-center"><div class="spinner-border spinner-border-sm text-primary"></div> Searching...</td></tr>');
    
    $.get(`${APP_URL}/api/get_documents.php`, { search: { value: search }, category_id: category, length: 100 }, function(res) {
        if (!res.data || res.data.length === 0) {
            tbody.html('<tr><td colspan="5" class="text-center text-muted">No documents found</td></tr>');
            return;
        }
        
        let html = '';
        res.data.forEach(doc => {
            html += `<tr onclick="selectDocument(${doc.id}, '${escapeHtml(doc.document_name)}')" style="cursor: pointer;">
                <td>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="docSelect" id="doc_${doc.id}" value="${doc.id}" ${selectedDocId == doc.id ? 'checked' : ''}>
                    </div>
                </td>
                <td><strong>${escapeHtml(doc.document_name)}</strong></td>
                <td>${escapeHtml(doc.category_name || 'General')}</td>
                <td>${formatFileSize(doc.file_size)}</td>
                <td>${new Date(doc.uploaded_at).toLocaleDateString()}</td>
            </tr>`;
        });
        tbody.html(html);
    });
}

function selectDocument(id, name) {
    selectedDocId = id;
    $(`#doc_${id}`).prop('checked', true);
    $('#selectedDocName').text(name);
    $('#btnNextStep').prop('disabled', false);
}

function loadSignaturesForSelect() {
    const grid = $('#signatureSelectionGrid');
    grid.html('<div class="col-12 text-center text-muted"><i class="bi bi-hourglass-split"></i> Loading signatures...</div>');
    
    $.get(`${APP_URL}/api/document/get_user_signatures_list.php`, function(signatures) {
        if (!signatures || signatures.length === 0) {
            grid.html('<div class="col-12 text-center p-4"><p>No signatures found.</p><button class="btn btn-sm btn-outline-primary" onclick="$(\'#signDocumentModal\').modal(\'hide\'); openDrawSignatureModal();">Draw One Now</button></div>');
            return;
        }
        
        let html = '';
        signatures.forEach(sig => {
            const selected = sig.id == selectedSignatureForSign ? 'border-primary' : '';
            html += `<div class="col-6 col-md-4 mb-3">
                <div class="card h-100 signature-select-card ${selected}" onclick="finalSelectSignature(${sig.id}, this)" style="cursor: pointer; border-width: 2px;">
                    <div class="card-body text-center p-2">
                        ${sig.thumbnail_path ? 
                            `<img src="${sig.thumbnail_path}" style="max-height: 80px; max-width: 100%; object-fit: contain;">` :
                            `<div class="py-3"><i class="bi bi-signature fs-1 text-muted"></i></div>`
                        }
                        <div class="mt-2 small text-uppercase fw-bold">${sig.signature_type}</div>
                    </div>
                </div>
            </div>`;
        });
        grid.html(html);
    });
}

function finalSelectSignature(id, el) {
    selectedSignatureForSign = id;
    $('.signature-select-card').removeClass('border-primary');
    $(el).addClass('border-primary');
    $('#btnApplySignature').prop('disabled', !$('#finalLegalAgreement').is(':checked'));
}

$('#finalLegalAgreement').on('change', function() {
    $('#btnApplySignature').prop('disabled', !selectedSignatureForSign || !this.checked);
});

// Quick Upload Form
$('#quickUploadForm').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const btn = $('#btnQuickUpload');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Uploading...');

    $.ajax({
        url: `${APP_URL}/api/document/quick_upload_document.php`,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                logReportAction('Quick Uploaded Document', 'User quick-uploaded document: ' + res.document_name + ' for signing');
                showAlert('success', 'Document uploaded successfully!');
                selectDocument(res.document_id, res.document_name);
                nextStep();
            } else {
                showAlert('error', res.message);
            }
        },
        error: () => showAlert('error', 'Upload failed'),
        complete: () => btn.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i> Upload & Continue')
    });
});

function applySignatureToDocument() {
    const position = $('#finalSignaturePosition').val();
    
    currentStep = 3;
    updateStepUI();
    $('#processingIndicator').show();
    $('#downloadReady').hide();
    
    $.post(`${APP_URL}/api/document/apply_signature.php`, {
        document_id: selectedDocId,
        signature_id: selectedSignatureForSign,
        signature_position: position
    }, function(res) {
        if (res.success) {
            setTimeout(() => {
                $('#processingIndicator').hide();
                $('#downloadReady').show();
                $('#signedFileName').text($('#selectedDocName').text());
                
                // Set up download button
                $('#btnDownloadSigned').off('click').on('click', function() {
                    logReportAction('Downloaded Signed Document', 'User downloaded the signed document ID: ' + selectedDocId);
                    window.location.href = `document_library.php?action=download&document_id=${selectedDocId}`;
                });
                
                logReportAction('Applied E-Signature (Wizard)', 'User applied an e-signature to document ID: ' + selectedDocId + ' via wizard');
                showAlert('success', 'Signature applied successfully!');
            }, 1500);
        } else {
            showAlert('error', res.message);
            previousStep();
        }
    }, 'json').fail(() => {
        showAlert('error', 'Failed to apply signature');
        previousStep();
    });
}

</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { 
    transform: translateY(-5px); 
    box-shadow: 0 8px 15px rgba(0,0,0,0.1); 
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-table-header { 
    border-bottom: 2px solid #e9ecef; 
}

.signature-preview {
    object-fit: contain;
}

.signature-placeholder {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
}

#signaturePad {
    touch-action: none;
}

.signature-select-card {
    transition: all 0.2s;
    border: 2px solid transparent;
}

.signature-select-card:hover {
    border-color: #0d6efd !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.signature-select-card.border-primary {
    border-color: #0d6efd !important;
    background-color: #e7f1ff;
}

@media (max-width: 768px) {
    #signaturePad {
        width: 100% !important;
        height: 150px !important;
    }
}

/* Step Wizard Styles */
.step-item {
    text-align: center;
    position: relative;
    z-index: 1;
    flex: 1;
}
.step-circle {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: #e9ecef;
    color: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 8px;
    font-weight: bold;
    border: 2px solid #dee2e6;
    transition: all 0.3s;
}
.step-label {
    font-size: 0.85rem;
    color: #6c757d;
    font-weight: 500;
}
.step-line {
    flex: 1;
    height: 2px;
    background: #e9ecef;
    margin-top: -25px;
}
.step-item.active .step-circle {
    background: #0d6efd;
    color: white;
    border-color: #0d6efd;
    box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.25);
}
.step-item.active .step-label { color: #0d6efd; }
.step-item.completed .step-circle {
    background: #198754;
    color: white;
    border-color: #198754;
}
.step-item.completed .step-circle::after {
    content: '\F633';
    font-family: bi-bootstrap-icons;
    font-size: 1.2rem;
}
.step-item.completed .step-circle { content: ''; } /* Hide number when completed */
.step-item.completed .step-label { color: #198754; }

.signature-select-card {
    transition: transform 0.2s, border-color 0.2s;
}
.signature-select-card:hover { transform: scale(1.02); }
.signature-select-card.border-primary { background-color: #f8fbff; }
</style>

<?php
// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>