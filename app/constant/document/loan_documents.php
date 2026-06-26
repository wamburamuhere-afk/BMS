<?php
// scope-audit: skip — loan documents page; document access scoped via customer scope; no independent project_id
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('loan_documents');
}
$loan_id = isset($_GET['loan_id']) ? (int)$_GET['loan_id'] : 0;

// Handle actions (Delete)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['doc_id'])) {
    $doc_id = (int)$_GET['doc_id'];
    $stmt = $pdo->prepare("SELECT file_path FROM loan_documents WHERE id = ? AND loan_id = ?");
    $stmt->execute([$doc_id, $loan_id]);
    $doc = $stmt->fetch();
    if ($doc) {
        if (file_exists($doc['file_path'])) unlink($doc['file_path']);
        $pdo->prepare("DELETE FROM loan_documents WHERE id = ?")->execute([$doc_id]);
        // Activity Log feed (audit_log.md) BEFORE the redirect (which exits).
        if (function_exists('logActivity') && !empty($_SESSION['user_id'])) {
            logActivity($pdo, (int)$_SESSION['user_id'], 'Delete document',
                "deleted loan document with id {$doc_id} (loan id {$loan_id})");
        }
        redirectTo("loans/documents?loan_id=$loan_id&msg=deleted");
    }
}
// DEPRECATED: Using AJAX Search
// $loans = ...
// Fetch loan info if selected
$loan = null;
if ($loan_id > 0) {
    $stmt = $pdo->prepare("
        SELECT l.*, CONCAT(c.first_name, ' ', c.last_name) AS customer_name, c.customer_id
        FROM loans l 
        LEFT JOIN customers c ON l.customer_id = c.customer_id 
        WHERE l.loan_id = ?
    ");
    $stmt->execute([$loan_id]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
}

// DEPRECATED: Using AJAX Search
// $loans = ...
?>

<!-- Add dependencies -->
<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/sweetalert2.all.min.js"></script>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-file-earmark-lock"></i> Loan Documents</h2>
                    <p class="text-muted mb-0">Manage and verify documents associated specialized for loan accounts</p>
                </div>
                <div>
                    <?php if ($loan_id > 0): ?>
                    <div class="dropdown">
                        <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-upload"></i> Manage Uploads
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalLoanDoc"><i class="bi bi-file-earmark-text me-2"></i> Loan Document</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalCollateral"><i class="bi bi-shield-check me-2"></i> Collateral Document</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalDisbursement"><i class="bi bi-cash-coin me-2"></i> Disbursement Document</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Loan Selector Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-search"></i> Select Loan Account</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Search Loan Account (Reference or Customer Name)</label>
                    <select class="form-select select2-loan" name="loan_id" id="loan_id_select">
                        <option value="">Choose loan...</option>
                        <?php if ($loan): ?>
                            <option value="<?= $loan['loan_id'] ?>" selected>
                                <?= htmlspecialchars($loan['reference_number']) ?> - <?= htmlspecialchars($loan['customer_name']) ?>
                            </option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <a href="<?= getUrl('loans/documents') ?>" class="btn btn-outline-secondary w-100">Clear Selection</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($loan_id > 0 && $loan): ?>
    <!-- Loan Summary Card -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card bg-primary text-white shadow-sm">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-3">
                            <small class="text-white-50 d-block">Loan Reference</small>
                            <h5 class="mb-0"><?= htmlspecialchars($loan['reference_number']) ?></h5>
                        </div>
                        <div class="col-md-3">
                            <small class="text-white-50 d-block">Customer</small>
                            <h5 class="mb-0"><?= htmlspecialchars($loan['customer_name']) ?></h5>
                        </div>
                        <div class="col-md-3">
                            <small class="text-white-50 d-block">Amount</small>
                            <h5 class="mb-0">TZS <?= number_format($loan['amount'], 2) ?></h5>
                        </div>
                        <div class="col-md-3 text-md-end">
                            <span class="badge bg-white text-primary px-3 py-2 text-uppercase">
                                <?= $loan['status'] ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

 
    <!-- Collateral Documents Table -->
    <div class="card mb-4 text-dark shadow-sm">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Collateral Documents</h5>
            <span class="badge bg-success" id="collateral-doc-count">0 files</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="collateralDocsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th>Collateral Type</th>
                            <th>Description</th>
                            <th>File Name</th>
                            <th>Size</th>
                            <th>Uploaded At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <!-- Loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>


       <!-- Loan Documents Table -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Loan Document Files</h5>
            <span class="badge bg-primary" id="doc-count">0 files</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="loanDocsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th>Document Name</th>
                            <th>Type</th>
                            <th>Original File</th>
                            <th>Size</th>
                            <th>Uploaded At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <!-- Loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- Edit Document Modal -->
<div class="modal fade" id="modalEditDoc" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" id="editDocForm">
            <input type="hidden" name="doc_id" id="edit_doc_id">
            <input type="hidden" name="loan_id" value="<?= $loan_id ?>">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Loan Document</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Document Type</label>
                    <select class="form-select" name="document_type" id="edit_doc_type" required>
                        <option value="ID">Identity Proof</option>
                        <option value="Agreement">Loan Agreement</option>
                        <option value="Security">Security/Collateral</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Display Name</label>
                    <input type="text" class="form-control" name="document_name" id="edit_doc_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description (Optional)</label>
                    <textarea class="form-control" name="description" id="edit_doc_desc" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-warning">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 1. Loan Document Modal (loan_documents table) -->
<div class="modal fade" id="modalLoanDoc" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content upload-form" action="/api/upload_loan_doc">
            <input type="hidden" name="loan_id" value="<?= $loan_id ?>">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Upload Loan Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Document Type</label>
                    <select class="form-select" name="document_type" required>
                        <option value="Agreement">Loan Agreement</option>
                        <option value="ID">Identity Proof</option>
                        <option value="Security">Security/Collateral</option>
                        <option value="Approval">Approval Letter</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Document Name</label>
                    <input type="text" class="form-control" name="document_name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description (Optional)</label>
                    <textarea class="form-control" name="description" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Select File</label>
                    <input type="file" class="form-control" name="document_file" required>
                </div>
            </div>
            <div class="modal-footer bg-light text-center">
                <button type="submit" class="btn btn-primary w-100">Upload Loan Document</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Collateral Document Modal (loan_collateral table) -->
<div class="modal fade" id="modalCollateral" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content upload-form" action="/api/upload_collateral_doc">
            <input type="hidden" name="loan_id" value="<?= $loan_id ?>">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Upload Collateral Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Collateral Type</label>
                    <select class="form-select" name="collateral_type" required>
                        <option value="Property">Property/Real Estate</option>
                        <option value="Vehicle">Vehicle</option>
                        <option value="Equipment">Equipment/Machinery</option>
                        <option value="Inventory">Inventory/Stock</option>
                        <option value="Other">Other Assets</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Collateral Value (TZS)</label>
                    <input type="number" step="0.01" class="form-control" name="collateral_value" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="2" placeholder="Describe the collateral..." required></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Select File</label>
                    <input type="file" class="form-control" name="document_file" required>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="submit" class="btn btn-success w-100">Upload Collateral Document</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Disbursement Document Modal (loan_disbursements table) -->
<div class="modal fade" id="modalDisbursement" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content upload-form" action="/api/upload_disbursement_doc">
            <input type="hidden" name="loan_id" value="<?= $loan_id ?>">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Upload Disbursement Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Disbursement Method</label>
                    <select class="form-select" name="disbursement_method" required>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Cash">Cash</option>
                        <option value="Check">Check</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Disbursement Amount (TZS)</label>
                    <input type="number" step="0.01" class="form-control" name="disbursement_amount" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reference Number</label>
                    <input type="text" class="form-control" name="reference_number" placeholder="Transaction/Check reference">
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes (Optional)</label>
                    <textarea class="form-control" name="notes" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Select File</label>
                    <input type="file" class="form-control" name="document_file" required>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="submit" class="btn btn-dark w-100">Upload Disbursement Document</button>
            </div>
        </form>
    </div>
</div>

<!-- Select2 JS -->
<script src="<?= getUrl('assets/js/select2.min.js') ?>"></script>
<!-- DataTables JS is handled by footer.php -->

<script>
// Define user permissions for JavaScript
const userPermissions = {
    canDelete: <?php echo canDelete('loan_documents') ? 'true' : 'false'; ?>
};

$(document).ready(function() {
    // Initialize Select2 with AJAX
    $('.select2-loan').select2({
        theme: 'bootstrap-5',
        ajax: {
            url: `${APP_URL}/api/search_loans`,
            type: 'POST',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { search: params.term };
            },
            processResults: function(data) {
                return {
                    results: data.loans.map(l => ({
                        id: l.id,
                        text: l.text,
                        entity_type: l.entity_type
                    }))
                };
            },
            cache: true
        },
        minimumInputLength: 1,
        templateResult: formatLoanResult
    }).on('select2:select', function(e) {
        window.location.href = '/loans/documents?loan_id=' + e.params.data.id;
    });

    function formatLoanResult(l) {
        if (l.loading) return l.text;
        let badgeClass = l.entity_type === 'company' ? 'bg-secondary' : 'bg-info';
        let badgeLabel = l.entity_type === 'company' ? 'Company' : 'Individual';
        return $(`<div>${escapeHtml(l.text)} <span class="badge ${badgeClass} float-end">${badgeLabel}</span></div>`);
    }

    <?php if ($loan_id > 0): ?>
    const table = $('#loanDocsTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/get_loan_documents`,
            data: { loan_id: <?= $loan_id ?> },
            dataSrc: json => {
                $('#doc-count').text((json.recordsTotal || 0) + ' files');
                return json.data || [];
            }
        },
        columns: [
            { 
                data: 'document_name',
                render: (data, t, row) => `<strong>${escapeHtml(data)}</strong><br><small class="text-muted">${escapeHtml(row.description || '')}</small>`
            },
            { data: 'document_type', render: data => `<span class="badge bg-light text-dark border">${data}</span>` },
            { data: 'original_filename', render: (d, t, r) => `<span class="small text-muted">${escapeHtml(d)}</span>` },
            { data: 'file_size', render: d => d ? (d/1024).toFixed(1) + ' KB' : '0 KB' },
            { data: 'uploaded_at', render: d => d ? d.split(' ')[0] : 'N/A' },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => {
                    let viewPath = row.file_path ? row.file_path.replace(/^\.\.\//, '') : '#';
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><a class="dropdown-item" href="${APP_URL}/${viewPath}" target="_blank"><i class="bi bi-eye"></i> View Document</a></li>
                            <li><a class="dropdown-item" href="#" onclick="editLoanDoc(${JSON.stringify(row).replace(/"/g, '&quot;')})"><i class="bi bi-pencil text-warning"></i> Edit Details</a></li>`;
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="confirmDeleteLoanDoc(${row.id})"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ]
    });

    const collateralTable = $('#collateralDocsTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/get_collateral_documents`,
            data: { loan_id: <?= $loan_id ?> },
            dataSrc: json => {
                $('#collateral-doc-count').text((json.recordsTotal || 0) + ' files');
                return json.data || [];
            }
        },
        columns: [
            { 
                data: 'collateral_type',
                render: (data, t, row) => `<strong>${escapeHtml(data || 'N/A')}</strong>`
            },
            { data: 'collateral_desc', render: data => `<span class="small text-muted">${escapeHtml(data || '')}</span>` },
            { data: 'original_name', render: (d, t, r) => `<span class="small text-muted">${escapeHtml(d)}</span>` },
            { data: 'file_size', render: d => d ? (d/1024).toFixed(1) + ' KB' : '0 KB' },
            { data: 'uploaded_at', render: d => d ? d.split(' ')[0] : 'N/A' },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => {
                    let viewPath = row.file_path ? row.file_path.replace(/^\.\.\//, '') : '#';
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><a class="dropdown-item" href="${APP_URL}/${viewPath}" target="_blank"><i class="bi bi-eye"></i> View Document</a></li>`;
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteCollateralDoc(${row.id})"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ]
    });

    // Unified AJAX handler for all 3 upload forms
    $('.upload-form').on('submit', function(e) {
        e.preventDefault();
        let form = $(this);
        let formData = new FormData(this);
        let btn = form.find('button[type="submit"]');
        let originalText = btn.text();
        
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Uploading...');

        $.ajax({
            url: form.attr('action'),
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    logReportAction('Uploaded Loan Document', 'User uploaded document: ' + (formData.get('document_name') || formData.get('collateral_type') || formData.get('disbursement_method')) + ' for Loan ID: <?= $loan_id ?>');
                    form.closest('.modal').modal('hide');
                    table.ajax.reload();
                    collateralTable.ajax.reload();
                    Swal.fire('Success', 'Document uploaded successfully', 'success');
                    form[0].reset();
                } else {
                    Swal.fire('Error', res.message || 'Upload failed', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Server error occurred', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Handle edit
    $('#editDocForm').on('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        let btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: `${APP_URL}/api/update_loan_document`,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    logReportAction('Updated Loan Document', 'User updated document details: ' + formData.get('document_name') + ' (ID: ' + formData.get('doc_id') + ')');
                    $('#modalEditDoc').modal('hide');
                    table.ajax.reload();
                    Swal.fire('Updated', 'Record saved', 'success');
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            complete: () => btn.prop('disabled', false).text('Save Changes')
        });
    });
    <?php endif; ?>
});

function editLoanDoc(doc) {
    $('#edit_doc_id').val(doc.id);
    $('#edit_doc_type').val(doc.document_type);
    $('#edit_doc_name').val(doc.document_name);
    $('#edit_doc_desc').val(doc.description);
    $('#modalEditDoc').modal('show');
}

function deleteCollateralDoc(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: `${APP_URL}/api/delete_collateral_document`,
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        logReportAction('Deleted Collateral Document', 'User deleted collateral document ID: ' + id);
                        $('#collateralDocsTable').DataTable().ajax.reload();
                        Swal.fire('Deleted!', res.message, 'success');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

function confirmDeleteLoanDoc(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You want to delete this loan document?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `${APP_URL}/loans/documents?action=delete&doc_id=${id}&loan_id=<?= $loan_id ?>`;
        }
    });
}

function escapeHtml(text) { return text ? $('<div>').text(text).html() : ''; }
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }

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
.select2-loan { height: calc(3.5rem + 2px); }
</style>

<?php
includeFooter();
ob_end_flush();
?>