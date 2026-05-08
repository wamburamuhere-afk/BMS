<?php
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('customer_documents');
}

$customer_id = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

// Fetch customer details if provided
$customer = null;
if ($customer_id > 0) {
    $stmt = $pdo->prepare("SELECT *, CONCAT(first_name, ' ', last_name) as full_name FROM customers WHERE customer_id = ?");
    $stmt->execute([$customer_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch all customers for selector - DEPRECATED for AJAX search
// $customers = $pdo->query("SELECT customer_id, CONCAT(first_name, ' ', last_name) as full_name FROM customers ORDER BY first_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Handle Actions (Delete)
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['doc_id'])) {
    $doc_id = $_GET['doc_id'];
    $source = $_GET['source'] ?? 'customer_attachments';
    
    if ($source === 'customer_attachments') {
        $stmt = $pdo->prepare("SELECT file_path FROM customer_attachments WHERE id = ? AND customer_id = ?");
        $stmt->execute([(int)$doc_id, $customer_id]);
        $doc = $stmt->fetch();
        if ($doc) {
            if (file_exists($doc['file_path'])) unlink($doc['file_path']);
            $pdo->prepare("DELETE FROM customer_attachments WHERE id = ?")->execute([(int)$doc_id]);
        }
    } elseif ($source === 'customer_additional_attachments') {
        $stmt = $pdo->prepare("SELECT attachment_path FROM customer_additional_attachments WHERE attachment_id = ? AND customer_id = ?");
        $stmt->execute([(int)$doc_id, $customer_id]);
        $doc = $stmt->fetch();
        if ($doc) {
            if (file_exists($doc['attachment_path'])) unlink($doc['attachment_path']);
            $pdo->prepare("DELETE FROM customer_additional_attachments WHERE attachment_id = ?")->execute([(int)$doc_id]);
        }
    } elseif ($source === 'customer_profile') {
        // For profile columns, we just CLEAR the path in the customers table
        $allowed_fields = ['photo_path', 'id_attachment_path', 'incorporation_cert_path', 'tin_cert_path', 'vat_cert_path', 'tax_clearance_path', 'business_license_path', 'memart_cert_path', 'board_resolution_path', 'bank_statement_path', 'financial_statement_path', 'lease_agreement_path', 'brela_certificate_path', 'tin_certificate_path', 'local_gov_letter_path'];
        if (in_array($doc_id, $allowed_fields)) {
            $pdo->prepare("UPDATE customers SET $doc_id = NULL WHERE customer_id = ?")->execute([$customer_id]);
        }
    }
    
    header("Location: /customers/documents?customer_id=" . $customer_id . "&msg=deleted");
    exit();
}
?>

<!-- Add Select2 CSS -->
<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-person-badge"></i> Customer Documents</h2>
                    <p class="text-muted mb-0">Manage KYC, identity, and personal documents for customers</p>
                </div>
                <div>
                    <?php if ($customer_id > 0): ?>
                    <div class="dropdown">
                        <button class="btn btn-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-upload"></i> Manage Uploads
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalKYC"><i class="bi bi-person-vcard me-2"></i> KYC Document</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalAdditional"><i class="bi bi-plus-circle me-2"></i> Additional Attachment</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#modalProfile"><i class="bi bi-person-lines-fill me-2"></i> Profile Certificate/Doc</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Selector Card -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-9">
                    <label class="form-label">Search Customer</label>
                    <select class="form-select select2-cust" name="customer_id" id="customer_id_select">
                        <option value="">Choose customer...</option>
                        <?php if ($customer): ?>
                            <option value="<?= $customer['customer_id'] ?>" selected>
                                <?= htmlspecialchars($customer['full_name'] ?? '') ?>
                            </option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <a href="/customers/documents" class="btn btn-outline-secondary w-100">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($customer_id > 0 && $customer): ?>
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm overflow-hidden">
                <div class="card-body p-0">
                    <div class="row g-0">
                        <div class="col-md-4 bg-primary p-4 text-white d-flex align-items-center justify-content-center">
                            <div class="text-center">
                                <div class="avatar-lg bg-white-50 p-3 rounded-circle mb-3 mx-auto">
                                    <i class="bi bi-person-circle fs-1"></i>
                                </div>
                                <h4 class="mb-1"><?= htmlspecialchars($customer['full_name']) ?></h4>
                                <p class="mb-0 text-white-50">ID: <?= $customer['customer_id'] ?></p>
                            </div>
                        </div>
                        <div class="col-md-8 p-4">
                            <div class="row g-3">
                                <div class="col-6">
                                    <p class="text-muted small mb-1">Email</p>
                                    <h6><?= htmlspecialchars($customer['email'] ?? 'N/A') ?></h6>
                                </div>
                                <div class="col-6">
                                    <p class="text-muted small mb-1">Phone</p>
                                    <h6><?= htmlspecialchars($customer['phone_number'] ?? 'N/A') ?></h6>
                                </div>
                                <div class="col-12 mt-4">
                                    <div class="d-flex gap-2">
                                        <div class="px-3 py-2 bg-light rounded text-center">
                                            <h5 class="mb-0" id="stat-total-docs">0</h5>
                                            <small class="text-muted">Documents</small>
                                        </div>
                                        <div class="px-3 py-2 bg-light rounded text-center">
                                            <h5 class="mb-0 text-danger" id="stat-expired-docs">0</h5>
                                            <small class="text-muted">Expired</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Documents List Table -->
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0">KYC & Supporting Documents</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table id="custDocsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th>Document Name</th>
                            <th>Type</th>
                            <th>File Info</th>
                            <th>Document Date</th>
                            <th>Status</th>
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
    <?php else: ?>
    <div class="text-center py-5 border rounded bg-light">
        <i class="bi bi-person-lines-fill fs-1 text-muted mb-3 d-block"></i>
        <h4>No Customer Selected</h4>
        <p class="text-muted">Select a customer above to manage their KYC documents.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Edit Document Modal -->
<div class="modal fade" id="modalEditDoc" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" id="editDocForm">
            <input type="hidden" name="doc_id" id="edit_doc_id">
            <input type="hidden" name="source" id="edit_doc_source">
            <input type="hidden" name="customer_id" value="<?= $customer_id ?>">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Document Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Document Name/Label</label>
                    <input type="text" class="form-control" name="document_name" id="edit_doc_name" required>
                </div>
                <div class="mb-3" id="edit_type_container">
                    <label class="form-label">Document Type</label>
                    <select class="form-select" name="document_type" id="edit_doc_type">
                        <option value="ID Document">ID Document</option>
                        <option value="Passport Photo">Passport Photo</option>
                        <option value="Proof of Address">Proof of Address</option>
                        <option value="Income Proof">Income Proof</option>
                        <option value="Standard">Standard</option>
                        <option value="Additional">Additional</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-warning">Update Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- 1. KYC Document Modal (customer_attachments) -->
<div class="modal fade" id="modalKYC" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content upload-form" action="/ajax/upload_kyc.php">
            <input type="hidden" name="customer_id" value="<?= $customer_id ?>">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Upload KYC Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Document Category</label>
                    <select class="form-select" name="file_type" required>
                        <option value="ID Document">National ID / ID Document</option>
                        <option value="Passport Photo">Passport Photo</option>
                        <option value="Proof of Address">Proof of Address</option>
                        <option value="Income Proof">Income Proof / Bank Statement</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Select File</label>
                    <input type="file" class="form-control" name="document_file" required>
                </div>
            </div>
            <div class="modal-footer bg-light text-center">
                <button type="submit" class="btn btn-primary w-100">Upload to KYC List</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Additional Attachment Modal (customer_additional_attachments) -->
<div class="modal fade" id="modalAdditional" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content upload-form" action="/ajax/upload_additional.php">
            <input type="hidden" name="customer_id" value="<?= $customer_id ?>">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">Add Extra Attachment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Attachment Label (e.g. Guarantee Letter)</label>
                    <input type="text" class="form-control" name="attachment_type" placeholder="What is this document?" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Select File</label>
                    <input type="file" class="form-control" name="document_file" required>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="submit" class="btn btn-success w-100">Upload as Additional</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Profile Column Modal (customers table columns) -->
<div class="modal fade" id="modalProfile" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content upload-form" action="/ajax/upload_profile_doc.php">
            <input type="hidden" name="customer_id" value="<?= $customer_id ?>">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Update Profile Certificates</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Select Target Certificate/Column</label>
                    <select class="form-select" name="column_name" required>
                        <option value="tin_certificate_path">TIN Certificate</option>
                        <option value="vat_cert_path">VAT Certificate</option>
                        <option value="brela_certificate_path">BRELA Certificate</option>
                        <option value="incorporation_cert_path">Incorporation Cert</option>
                        <option value="business_license_path">Business License</option>
                        <option value="tax_clearance_path">Tax Clearance</option>
                        <option value="bank_statement_path">Bank Statement (Profile)</option>
                        <option value="local_gov_letter_path">Local Gov Letter</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Select File</label>
                    <input type="file" class="form-control" name="document_file" required>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="submit" class="btn btn-dark w-100">Update Profile Field</button>
            </div>
        </form>
    </div>
</div>
<!-- Select2 JS -->
<script src="<?= getUrl('/assets/js/select2.min.js') ?>"></script>
<!-- DataTables JS is handled by footer.php -->

<script>
$(document).ready(function() {
    const userPermissions = {
        canEdit: <?= canEdit('customers') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('customers') ? 'true' : 'false' ?>
    };

    // Initialize Select2 with AJAX
    $('.select2-cust').select2({
        theme: 'bootstrap-5',
        ajax: {
            url: `${APP_URL}/ajax/search_customers.php`,
            type: 'POST',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return { search: params.term };
            },
            processResults: function(data) {
                return {
                    results: data.customers.map(c => ({
                        id: c.id,
                        text: c.text,
                        entity_type: c.entity_type
                    }))
                };
            },
            cache: true
        },
        minimumInputLength: 1,
        templateResult: formatCustomerResult
    }).on('select2:select', function(e) {
        window.location.href = 'customer_documents.php?customer_id=' + e.params.data.id;
    });

    function formatCustomerResult(c) {
        if (c.loading) return c.text;
        let badgeClass = c.entity_type === 'company' ? 'bg-secondary' : 'bg-info';
        let badgeLabel = c.entity_type === 'company' ? 'Company' : 'Individual';
        return $(`<div>${escapeHtml(c.text)} <span class="badge ${badgeClass} float-end">${badgeLabel}</span></div>`);
    }

    <?php if ($customer_id > 0): ?>
    let table = $('#custDocsTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/get_customer_documents.php`,
            data: { customer_id: <?= $customer_id ?> },
            dataSrc: function(json) {
                if (!json || json.error) {
                    console.error('DataTables error:', json ? json.error : 'Invalid response');
                    return [];
                }
                $('#stat-total-docs').text(json.recordsTotal);
                $('#stat-expired-docs').text(json.stats ? json.stats.expiredCount : 0);
                return json.data;
            }
        },
        columns: [
            { 
                data: 'document_name',
                render: function(data, t, row) { 
                    return `<strong>${escapeHtml(data)}</strong><br><small class="text-muted">${escapeHtml(row.original_filename)}</small>`;
                }
            },
            { data: 'document_type', render: data => `<span class="badge bg-light text-dark border">${escapeHtml(data)}</span>` },
            { data: 'file_size', render: data => data ? (data/1024).toFixed(1) + ' KB' : '0 KB' },
            { data: 'document_date', render: data => data || 'N/A' },
            { 
                data: 'expiry_date',
                render: (data, t, row) => {
                    if (!data) return '<span class="text-muted small">N/A</span>';
                    let expiry = new Date(data);
                    let now = new Date();
                    if (expiry < now) return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3"><i class="bi bi-x-circle"></i> Expired</span>';
                    return '<span class="badge bg-success-subtle text-success border border-success-subtle px-3"><i class="bi bi-check-circle"></i> Valid</span>';
                }
            },
            {
                data: null,
                className: 'text-end',
                render: (data, t, row) => {
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="${APP_URL}/${row.file_path}" target="_blank"><i class="bi bi-eye"></i> View Document</a></li>
                            <li><a class="dropdown-item" href="#" onclick="editDocument(${JSON.stringify(row).replace(/"/g, '&quot;')})"><i class="bi bi-pencil text-warning"></i> Edit Details</a></li>`;
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="confirmDeleteCustDoc(${row.id}, '${row.source}')"><i class="bi bi-trash"></i> Delete</a></li>`;
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
                    logReportAction('Uploaded Customer Document', 'User uploaded document: ' + (formData.get('document_name') || formData.get('attachment_type') || formData.get('column_name')) + ' for Customer ID: <?= $customer_id ?>');
                    form.closest('.modal').modal('hide');
                    table.ajax.reload();
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
    // Handle edit form
    $('#editDocForm').on('submit', function(e) {
        e.preventDefault();
        let formData = new FormData(this);
        let btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).text('Updating...');

        $.ajax({
            url: `${APP_URL}/ajax/update_customer_document.php`,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    logReportAction('Updated Customer Document', 'User updated document details: ' + formData.get('document_name') + ' (ID: ' + formData.get('doc_id') + ')');
                    $('#modalEditDoc').modal('hide');
                    table.ajax.reload();
                    Swal.fire('Updated', 'Changes saved successfully', 'success');
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            },
            complete: function() {
                btn.prop('disabled', false).text('Update Changes');
            }
        });
    });
    <?php endif; ?>
});

function editDocument(doc) {
    $('#edit_doc_id').val(doc.id);
    $('#edit_doc_source').val(doc.source);
    $('#edit_doc_name').val(doc.document_name);
    $('#edit_doc_type').val(doc.document_type);
    
    // Disable type editing for profile columns
    if (doc.source === 'customer_profile') {
        $('#edit_type_container').hide();
    } else {
        $('#edit_type_container').show();
    }
    
    $('#modalEditDoc').modal('show');
}

function confirmDeleteCustDoc(id, source) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You won't be able to revert this document deletion!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `${APP_URL}/customers/documents?customer_id=<?= $customer_id ?>&action=delete&doc_id=${id}&source=${source}`;
            // The actual logging happens on the next page load usually, but we could make it AJAX later.
            // For now, let's keep the redirect but handle the confirm nicely.
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
</style>

<?php
includeFooter();
ob_end_flush();
?>