<?php
// File: purchase_order_details.php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
?>

<div class="container-fluid mt-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('purchase_orders') ?>">Purchase Orders</a></li>
            <li class="breadcrumb-item active">View Order</li>
        </ol>
    </nav>

    <!-- Print Header (Visible only when printing) -->
    <div class="d-none d-print-block text-center mb-4">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        $c_email = getSetting('company_email', '');
        $c_web = getSetting('company_website', '');
        $c_tin = getSetting('company_tin', '');
        $c_vrn = getSetting('company_vrn', '');
        ?>
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3 text-center">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
        <p class="text-dark mb-1 small text-uppercase text-center">
            <?php 
            $web_email = [];
            if (!empty($c_web)) $web_email[] = "Web: " . safe_output($c_web);
            if (!empty($c_email)) $web_email[] = "Email: " . safe_output($c_email);
            if (!empty($web_email)) echo implode(" | ", $web_email);
            ?>
        </p>

        <p class="text-dark mb-1 small text-uppercase text-center">
            <?php 
            $tin_vrn = [];
            if (!empty($c_tin)) $tin_vrn[] = "TIN: " . safe_output($c_tin);
            if (!empty($c_vrn)) $tin_vrn[] = "VRN: " . safe_output($c_vrn);
            if (!empty($tin_vrn)) echo implode(" | ", $tin_vrn);
            ?>
        </p>

        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Purchase Order</h2>
            <h4 style="color: #6c757d; margin: 0; font-size: 12pt;">Order #<span class="orderNumberPrint"></span></h4>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <div id="content" style="display: none;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-0">Purchase Order <span id="orderNumber" class="text-primary"></span></h2>
                <span id="orderStatus" class="badge rounded-pill mt-2 px-3 py-2"></span>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <!-- Back Button -->
                <a href="<?= getUrl('purchase_orders') ?>" class="btn btn-blue-touch shadow-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>

                <!-- ── WORKFLOW ACTION BUTTONS ── -->
                <div id="workflowActions" class="d-flex gap-2">
                    <!-- Buttons injected by JS based on status/permissions -->
                </div>
                <!-- ── END WORKFLOW ── -->

                <a href="#" id="editLink" class="btn btn-outline-primary shadow-sm">
                    <i class="bi bi-pencil me-1"></i> Edit
                </a>
                <button onclick="printOrder(<?= $order_id ?>)" class="btn btn-blue-touch shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
            </div>
        </div>

        <div class="row g-4">
            <!-- Order Info -->
            <div class="col-md-8">
                <div class="card shadow-sm border-0 mb-4 h-100">
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-sm-6">
                                <h6 class="text-muted text-uppercase small fw-bold">Supplier</h6>
                                <h5 class="fw-bold mb-1" id="supplierName"></h5>
                                <p class="mb-0" id="supplierAddress"></p>
                                <p class="mb-0"><a href="#" id="supplierEmail" class="text-decoration-none"></a></p>
                                <p class="mb-0" id="supplierPhone"></p>
                            </div>
                            <div class="col-sm-6 text-sm-end">
                                <h6 class="text-muted text-uppercase small fw-bold">Order Details</h6>
                                <p class="mb-1"><strong>Date:</strong> <span id="orderDate"></span></p>
                                <p class="mb-1" id="projectRow" style="display:none;"><strong>Project:</strong> <span id="projectName" class="text-primary fw-bold"></span></p>
                                <p class="mb-1" id="warehouseRow" style="display:none;"><strong>Warehouse:</strong> <span id="warehouseName" class="text-success fw-bold"></span></p>
                                <p class="mb-1"><strong>Expected Delivery:</strong> <span id="expectedDate"></span></p>
                                <p class="mb-1"><strong>Created By:</strong> <span id="createdBy"></span></p>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Tax</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTableBody"></tbody>
                                <tfoot class="border-top">
                                    <tr>
                                        <td colspan="4" class="text-end text-muted">Subtotal</td>
                                        <td class="text-end fw-bold" id="subtotal"></td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="text-end text-muted">Tax</td>
                                        <td class="text-end fw-bold" id="taxTotal"></td>
                                    </tr>
                                    <tr>
                                        <td colspan="4" class="text-end text-muted">Shipping</td>
                                        <td class="text-end fw-bold" id="shipping"></td>
                                    </tr>
                                    <tr class="bg-light">
                                        <td colspan="4" class="text-end fw-bold fs-5">Grand Total</td>
                                        <td class="text-end fw-bold fs-5 text-primary" id="grandTotal"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Side Panel -->
            <div class="col-md-4">
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i> Notes</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-1">Internal Notes:</p>
                        <p id="internalNotes" class="mb-3 fst-italic">No notes provided.</p>
                        
                        <hr>
                        
                        <p class="text-muted small mb-1">Terms & Conditions:</p>
                        <p id="termsConditions" class="mb-3 small">No terms provided.</p>

                        <div id="attachmentsSection" style="display: none;">
                            <hr>
                            <p class="text-muted small mb-2 fw-bold text-uppercase">Documents & Attachments</p>
                            <div id="attachmentsList" class="d-grid gap-2">
                                <!-- Attachments will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <style>
    /* Blue on touch styling */
    .btn-blue-touch {
        background-color: #0d6efd !important;
        border-color: #0d6efd !important;
        color: #fff !important;
        transition: all 0.2s ease;
    }
    .btn-blue-touch:hover, .btn-blue-touch:active, .btn-blue-touch:focus {
        background-color: #0b5ed7 !important;
        border-color: #0a58ca !important;
        color: #fff !important;
        box-shadow: 0 0 0 0.25rem rgba(49, 132, 253, 0.5) !important;
    }

    @media print {
        body { background: white !important; }
        .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
        nav[aria-label="breadcrumb"], .breadcrumb, .btn, .d-print-none, #loading, .no-print { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
        .table { width: 100% !important; border: 1px solid #dee2e6 !important; }
        .table th { background-color: #f8f9fa !important; color: black !important; }
        .badge { border: 1px solid #ddd !important; background: transparent !important; color: black !important; }
        .text-primary { color: #0d6efd !important; }
        .card-header { background-color: #f8f9fa !important; border-bottom: 2px solid #dee2e6 !important; }
    }
    </style>
</div>

<script>
const orderId = <?= $order_id ?>;

$(document).ready(function() {
    loadOrderDetails();
});

function loadOrderDetails() {
    $.ajax({
        url: '<?= buildUrl("api/account/get_purchase_order_details.php") ?>',
        data: { id: orderId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderOrder(response.data);
                logReportAction('Viewed Purchase Order Details', 'User viewed details for PO #' + response.data.order.order_number);
                $('#loading').hide();
                $('#content').fadeIn();
            } else {
                Swal.fire('Error', response.message, 'error').then(() => {
                    window.location.href = '<?= getUrl("purchase_orders") ?>';
                });
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to load order details.', 'error');
        }
    });
}

function renderOrder(data) {
    const o = data.order;
    const items = data.items;
    const currency = o.currency || 'TZS';

    // Header
    $('#orderNumber').text(o.order_number);
    $('.orderNumberPrint').text(o.order_number);
    
    const statusEl = $('#orderStatus');
    statusEl.text(o.status.replace('_', ' ').toUpperCase())
            .removeClass(function(index, className) { return (className.match(/(^|\s)bg-\S+/g) || []).join(' '); })
            .addClass('bg-' + getStatusColor(o.status));

    // Workflow Actions
    const workflow = $('#workflowActions');
    workflow.empty();
    
    // Check permissions (we'll assume they are available via helper or similar if we had them in JS, 
    // but for now we'll check status and let the API handle the hard check)
    if (o.status === 'pending' || o.status === 'draft') {
        workflow.append(`
            <button class="btn btn-blue-touch shadow-sm" onclick="submitForReview()">
                <i class="bi bi-eye-fill me-1"></i> Review
            </button>
        `);
    } else if (o.status === 'review') {
        workflow.append(`
            <button class="btn btn-success shadow-sm" onclick="approvePO()">
                <i class="bi bi-check-circle-fill me-1"></i> Approve Order
            </button>
        `);
    }

    if (o.status !== 'pending' && o.status !== 'draft') {
        $('#editLink').hide();
    } else {
        $('#editLink').attr('href', '<?= getUrl("purchase_order_create") ?>?edit=' + o.purchase_order_id)
            .on('click', function() {
                logReportAction('Initiated Purchase Order Edit', 'User clicked edit for PO #' + o.order_number);
            });
    }

    // Supplier
    $('#supplierName').text(o.supplier_name);
    $('#supplierAddress').text(o.supplier_address || '');
    $('#supplierEmail').text(o.supplier_email || '').attr('href', 'mailto:' + o.supplier_email);
    $('#supplierPhone').text(o.supplier_phone || '');

    // Info
    $('#orderDate').text(o.order_date);
    if (o.project_name) {
        $('#projectName').text(o.project_name);
        $('#projectRow').show();
    }
    if (o.warehouse_name) {
        $('#warehouseName').text(o.warehouse_name);
        $('#warehouseRow').show();
    }
    $('#expectedDate').text(o.expected_delivery_date || o.expected_date || 'N/A');
    $('#createdBy').text(o.created_by_name);

    // Items
    const tbody = $('#itemsTableBody');
    tbody.empty();
    let calculatedSubtotal = 0;
    
    items.forEach(item => {
        const lineTotal = parseFloat(item.quantity) * parseFloat(item.unit_price);
        calculatedSubtotal += lineTotal;
        
        tbody.append(`
            <tr>
                <td>
                    <div class="fw-bold">${item.product_name}</div>
                    <small class="text-muted">${item.sku || ''}</small>
                </td>
                <td class="text-center">${parseFloat(item.quantity)} ${item.unit || ''}</td>
                <td class="text-end">${formatCurrency(item.unit_price, currency)}</td>
                <td class="text-end">${item.tax_name || '-'}</td>
                <td class="text-end fw-bold">${formatCurrency(lineTotal, currency)}</td>
            </tr>
        `);
    });

    // Totals
    const taxTotal = parseFloat(o.tax_amount || 0);
    const shippingCost = parseFloat(o.shipping_cost || 0);

    $('#subtotal').text(formatCurrency(calculatedSubtotal, currency));
    $('#taxTotal').text(formatCurrency(taxTotal, currency));
    $('#shipping').text(formatCurrency(shippingCost, currency));
    $('#grandTotal').text(formatCurrency(calculatedSubtotal + taxTotal + shippingCost, currency));

    // Notes
    if (o.notes) $('#internalNotes').text(o.notes).removeClass('fst-italic');
    if (o.terms_conditions) $('#termsConditions').text(o.terms_conditions);

    // Attachments
    const attachments = data.attachments || [];
    if (attachments.length > 0) {
        const list = $('#attachmentsList');
        list.empty();
        attachments.forEach(att => {
            list.append(`
                <a href="<?= buildUrl('') ?>${att.file_path}" target="_blank" class="btn btn-sm btn-outline-primary d-flex align-items-center justify-content-between p-2">
                    <span class="text-truncate me-2"><i class="bi bi-file-earmark-pdf me-1"></i> ${att.file_name}</span>
                    <i class="bi bi-eye"></i>
                </a>
            `);
        });
        $('#attachmentsSection').show();
    }
}

function getStatusColor(status) {
    switch(status) {
        case 'ordered':
        case 'approved': return 'info';
        case 'review': return 'primary';
        case 'pending': return 'warning';
        case 'cancelled': return 'danger';
        case 'received':
        case 'completed': return 'success';
        default: return 'secondary';
    }
}

function submitForReview() {
    Swal.fire({
        title: 'Submit for Review?',
        text: 'This Purchase Order will be sent for review and will no longer be editable.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: 'Yes, Submit',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.post('<?= getUrl('api/review_purchase_order') ?>', { purchase_order_id: orderId }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Submitted!', text: res.message }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

function approvePO() {
    Swal.fire({
        title: 'Approve Purchase Order?',
        text: 'Are you sure you want to approve this order?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.post('<?= getUrl('api/approve_purchase_order') ?>', { purchase_order_id: orderId }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Approved!', text: res.message }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

function formatCurrency(amount, currency) {
    return currency + ' ' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2});
}

function printOrder(id) {
    logReportAction('Printed Purchase Order Details', 'User printed purchase order details for PO ID #' + id);
    window.open('<?= getUrl('print_purchase_order') ?>?id=' + id, '_blank');
}
</script>

<?php includeFooter(); ?>
