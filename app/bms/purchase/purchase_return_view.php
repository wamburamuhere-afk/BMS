<?php
// File: purchase_return_view.php
require_once __DIR__ . '/../../../roots.php';

// Phase 5a — enforce view permission on purchase return view
autoEnforcePermission('purchase_returns');

includeHeader();

$return_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
?>

<div class="container-fluid mt-4">
    <!-- Breadcrumbs -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('purchase_returns') ?>">Purchase Returns</a></li>
            <li class="breadcrumb-item active">View Return</li>
        </ol>
    </nav>

    <div id="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="mt-2 text-muted">Loading return details...</p>
    </div>

    <div id="content" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-0">Return <span id="returnNumber" class="text-primary"></span></h2>
                <div class="mt-2">
                    <span id="returnStatus" class="badge rounded-pill me-2"></span>
                    <span class="text-muted"><i class="bi bi-calendar-event"></i> <span id="returnDate"></span></span>
                </div>
            </div>
            <div class="d-flex gap-2 d-print-none">
                <a href="<?= getUrl('purchase_returns') ?>" class="btn btn-outline-secondary px-4 shadow-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
                <button onclick="printReturn()" class="btn btn-outline-dark px-4 shadow-sm">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>

        <div class="row g-4">
            <!-- Main Content -->
            <div class="col-lg-8">
                <div class="card shadow-sm border-0 mb-4 h-100">
                    <div class="card-body">
                        <div class="row mb-5">
                            <div class="col-sm-6">
                                <h6 class="text-muted text-uppercase small fw-bold mb-3">Supplier Information</h6>
                                <h5 class="fw-bold mb-1" id="supplierName"></h5>
                                <p class="mb-0 text-muted" id="companyName"></p>
                                <div class="mt-2">
                                    <p class="mb-1 small"><i class="bi bi-geo-alt me-2"></i> <span id="supplierAddress"></span></p>
                                    <p class="mb-1 small"><i class="bi bi-envelope me-2"></i> <span id="supplierEmail"></span></p>
                                    <p class="mb-1 small"><i class="bi bi-telephone me-2"></i> <span id="supplierPhone"></span></p>
                                </div>
                            </div>
                            <div class="col-sm-6 text-sm-end">
                                <h6 class="text-muted text-uppercase small fw-bold mb-3">Return Details</h6>
                                <p class="mb-1"><strong id="referenceLabel">Reference:</strong> <span id="orderReference"></span></p>
                                <p class="mb-1"><strong>Return Reason:</strong> <span id="returnReason" class="text-capitalize"></span></p>
                                <p class="mb-1"><strong>Prepared By:</strong> <span id="createdBy"></span></p>
                                <p class="mb-1"><strong>Last Updated:</strong> <span id="updatedAt"></span></p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle border-top">
                                <thead class="bg-light">
                                    <tr>
                                        <th class="py-3">Product Description</th>
                                        <th class="text-center py-3">Quantity</th>
                                        <th class="text-end py-3">Unit Price</th>
                                        <th class="text-end py-3">Total Amount</th>
                                        <th class="py-3">Item Reason</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTableBody"></tbody>
                                <tfoot class="border-top bg-light">
                                    <tr>
                                        <td colspan="3" class="text-end fw-bold py-3">Grand Total</td>
                                        <td class="text-end fw-bold py-3 text-primary fs-5" id="grandTotal"></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="mt-4 pt-3 border-top">
                            <h6 class="fw-bold mb-2">Detailed Reason:</h6>
                            <p id="reasonDetails" class="text-muted mb-0"></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Side Panel -->
            <div class="col-lg-4">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-header bg-primary text-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2"></i> Additional Notes</h6>
                    </div>
                    <div class="card-body">
                        <div id="returnNotes" class="bg-light p-3 rounded border-start border-4 border-primary">
                            <p class="mb-0 small fst-italic">No additional notes provided for this return.</p>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4 d-print-none" id="returnAttachmentSection" style="display:none;">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i> Attachment</h6>
                    </div>
                    <div class="card-body">
                        <a id="returnAttachmentLink" href="#" target="_blank" class="btn btn-sm btn-outline-primary w-100">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i> View / Download
                        </a>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-4 d-print-none">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2"></i> Return Status</h6>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">Current status for tracking and auditing purposes.</p>
                        <div class="d-grid">
                            <div class="alert mb-0 text-center" id="statusAlert"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .container-fluid { padding: 0 !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table thead th { background-color: #f8f9fa !important; border-bottom: 2px solid #dee2e6 !important; }
}
</style>

<script>
const returnId = <?= $return_id ?>;
const returnNumber = '<?= isset($return_data['return_number']) ? $return_data['return_number'] : '' ?>';

function printReturn() {
    window.open('<?= getUrl('print_purchase_return') ?>?id=' + returnId, '_blank');
}

$(document).ready(function() {
    if (returnId > 0) {
        loadReturnDetails();
    } else {
        Swal.fire('Error', 'Invalid return ID provided.', 'error');
    }
});

function loadReturnDetails() {
    $.ajax({
        url: '<?= buildUrl("api/get_purchase_return.php") ?>',
        data: { id: returnId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderReturn(response.data);
                logReportAction('Viewed Purchase Return Details', 'User viewed details for Purchase Return #' + response.data.return_number);
                $('#loading').hide();
                $('#content').fadeIn();
            } else {
                Swal.fire('Error', response.message, 'error').then(() => {
                    window.location.href = '<?= getUrl("purchase_returns") ?>';
                });
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to load return details. Please check your connection.', 'error');
        }
    });
}

function renderReturn(data) {
    // Header & Status
    $('#returnNumber').text(data.return_number);
    $('#returnDate').text(formatDate(data.return_date));
    
    const status = data.status || 'pending';
    const statusColor = getStatusColor(status);
    $('#returnStatus').text(status.toUpperCase()).addClass('bg-' + statusColor);
    $('#statusAlert').addClass('alert-' + statusColor).text('STATUS: ' + status.toUpperCase());

    // Supplier Info
    $('#supplierName').text(data.supplier_name || 'N/A');
    $('#companyName').text(data.company_name || '');
    $('#supplierAddress').text(data.supplier_address || 'No address provided');
    $('#supplierEmail').text(data.supplier_email || 'No email');
    $('#supplierPhone').text(data.supplier_phone || 'No phone');

    // Return Details Meta
    let refHtml = '';
    if (data.order_number) {
        $('#referenceLabel').text('PO Ref:');
        refHtml = data.order_number;
    } else if (data.project_name) {
        $('#referenceLabel').text('Project:');
        refHtml = `<a href="<?= getUrl('project_view') ?>?id=${data.project_id}" class="text-decoration-none">${data.project_name}</a>`;
    } else {
        $('#referenceLabel').text('Reference:');
        refHtml = `<span class="text-muted fst-italic">No Reference</span>`;
    }
    $('#orderReference').html(refHtml);

    $('#returnReason').text(data.reason.replace(/_/g, ' '));
    $('#createdBy').text(data.created_by_name || 'System');
    $('#updatedAt').text(data.updated_at ? formatDateTime(data.updated_at) : 'N/A');
    $('#reasonDetails').text(data.reason_details || 'No detailed reason provided.');

    // Items Table
    const tbody = $('#itemsTableBody');
    tbody.empty();
    
    let grandTotal = 0;
    
    if (data.items && data.items.length > 0) {
        data.items.forEach(item => {
            const lineTotal = parseFloat(item.quantity) * parseFloat(item.unit_price);
            grandTotal += lineTotal;
            
            tbody.append(`
                <tr>
                    <td class="py-3">
                        <div class="fw-bold">${item.product_name || 'Product'}</div>
                    </td>
                    <td class="text-center py-3">${parseFloat(item.quantity).toLocaleString()}</td>
                    <td class="text-end py-3">TZS ${parseFloat(item.unit_price).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td class="text-end py-3 fw-bold">TZS ${lineTotal.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    <td class="py-3 small text-muted">${item.reason || '-'}</td>
                </tr>
            `);
        });
    } else {
        tbody.append('<tr><td colspan="5" class="text-center py-4 text-muted">No items found for this return</td></tr>');
    }

    $('#grandTotal').text('TZS ' + grandTotal.toLocaleString(undefined, {minimumFractionDigits: 2}));

    // Notes
    if (data.notes && data.notes.trim() !== '') {
        $('#returnNotes').html(`<p class="mb-0 small">${data.notes}</p>`);
    }

    // Attachment
    if (data.attachment) {
        const fname = data.attachment.split('/').pop();
        $('#returnAttachmentSection').show();
        $('#returnAttachmentLink').attr('href', '<?= getUrl('') ?>' + data.attachment).text(fname);
    } else {
        $('#returnAttachmentSection').hide();
    }
}

function getStatusColor(status) {
    const colors = {
        'pending': 'warning',
        'approved': 'primary',
        'completed': 'success',
        'rejected': 'danger',
        'cancelled': 'secondary'
    };
    return colors[status] || 'secondary';
}

function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateStr).toLocaleDateString(undefined, options);
}

function formatDateTime(dateStr) {
    if (!dateStr) return 'N/A';
    const options = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    return new Date(dateStr).toLocaleString(undefined, options);
}
</script>

<?php includeFooter(); ?>
