<?php
// File: purchase_order_details.php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Load delivery notes for this PO (screen only — not used in print)
$dn_list = [];
$po_items_list = [];
$dn_overall_status = null;
$dn_delivered_by_product = [];

if ($order_id) {
    $dnStmt = $pdo->prepare("
        SELECT d.delivery_id, d.delivery_number, d.delivery_date, d.status,
               d.notes, d.received_by, d.created_at,
               u.username AS created_by_name
        FROM deliveries d
        LEFT JOIN users u ON d.created_by = u.user_id
        WHERE d.purchase_order_id = ? AND d.status != 'cancelled'
        ORDER BY d.created_at ASC
    ");
    $dnStmt->execute([$order_id]);
    $dn_list = $dnStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($dn_list as &$dn) {
        $diStmt = $pdo->prepare("SELECT * FROM delivery_items WHERE delivery_id = ? ORDER BY delivery_item_id ASC");
        $diStmt->execute([$dn['delivery_id']]);
        $dn['items'] = $diStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dn['items'] as $item) {
            $pid = $item['product_id'];
            $dn_delivered_by_product[$pid] = ($dn_delivered_by_product[$pid] ?? 0) + (float)$item['quantity_delivered'];
        }
    }
    unset($dn);

    $poiStmt = $pdo->prepare("
        SELECT product_id, COALESCE(product_name, item_name) AS product_name,
               quantity, unit_of_measure
        FROM purchase_order_items WHERE purchase_order_id = ?
    ");
    $poiStmt->execute([$order_id]);
    $po_items_list = $poiStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($dn_list)) {
        if (empty($po_items_list)) {
            $dn_overall_status = 'partial';
        } else {
            $all_complete = true;
            $any_delivered = false;
            foreach ($po_items_list as $poi) {
                $delivered = $dn_delivered_by_product[$poi['product_id']] ?? 0;
                if ($delivered > 0) $any_delivered = true;
                if ($delivered < (float)$poi['quantity']) $all_complete = false;
            }
            $dn_overall_status = $all_complete ? 'complete' : ($any_delivered ? 'partial' : 'partial');
        }
    }
}
?>

<div class="container-fluid mt-4">
    <nav aria-label="breadcrumb" class="mb-3 po-sticky-nav">
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
        <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center mb-4 gap-2">
            <div>
                <h2 class="fw-bold mb-0">Purchase Order <span id="orderNumber" class="text-primary"></span></h2>
                <span id="orderStatus" class="badge rounded-pill mt-2 px-3 py-2"></span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <!-- Back Button -->
                <a href="<?= getUrl('purchase_orders') ?>" class="btn btn-blue-touch shadow-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>

                <!-- ── WORKFLOW ACTION BUTTONS ── -->
                <div id="workflowActions" class="d-flex flex-wrap gap-2">
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

                        <div class="table-responsive d-none d-md-block">
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
                        <!-- Mobile card view for order items -->
                        <div id="mobile-items-cards" class="d-md-none px-1 pt-1"></div>
                    </div>
                </div>
            </div>

            <!-- Side Panel -->
            <div class="col-md-4">
                <!-- Workflow Trail -->
                <div class="card shadow-sm border-0 mb-3" id="workflowCard" style="display:none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2"></i> Authorization Trail</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush small">
                            <div class="list-group-item">
                                <div class="text-muted small">Prepared By:</div>
                                <div id="preparedByDisplay" class="fw-bold"></div>
                            </div>
                            <div class="list-group-item" id="reviewedByRow" style="display:none;">
                                <div class="text-muted small">Reviewed By:</div>
                                <div id="reviewedByDisplay" class="fw-bold"></div>
                                <div id="reviewedAtDisplay" class="text-muted" style="font-size:0.75rem;"></div>
                            </div>
                            <div class="list-group-item" id="approvedByRow" style="display:none;">
                                <div class="text-muted small">Approved By:</div>
                                <div id="approvedByDisplay" class="fw-bold"></div>
                                <div id="approvedAtDisplay" class="text-muted" style="font-size:0.75rem;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i> Notes</h6>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-1">Internal Notes:</p>
                        <p id="internalNotes" class="mb-3 fst-italic">No notes provided.</p>

                        <hr>

                        <p class="text-muted small mb-1">Terms & Conditions:</p>
                        <p id="termsConditions" class="mb-0 small">No terms provided.</p>
                    </div>
                </div>

                <div id="attachmentsSection" class="card shadow-sm border-0 mb-3 d-print-none" style="display: none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i> Documents & Attachments</h6>
                    </div>
                    <div class="card-body">
                        <div id="attachmentsList" class="d-grid gap-2">
                            <!-- Attachments will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($dn_list)): ?>
        <!-- ── DELIVERY NOTES SECTION — screen only, hidden on print ── -->
        <div class="d-print-none mt-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold mb-0">
                    <i class="bi bi-truck me-2 text-primary"></i>Delivery Note
                </h5>
                <small class="text-muted"><?= count($dn_list) ?> delivery note<?= count($dn_list) > 1 ? 's' : '' ?></small>
            </div>

            <?php foreach ($dn_list as $dn): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-light py-2">
                    <span class="fw-bold"><i class="bi bi-file-earmark-text me-1"></i><?= htmlspecialchars($dn['delivery_number']) ?></span>
                    <span class="ms-3 text-muted small">
                        <i class="bi bi-calendar2 me-1"></i><?= htmlspecialchars($dn['delivery_date'] ?: date('Y-m-d', strtotime($dn['created_at']))) ?>
                    </span>
                    <?php if ($dn['received_by']): ?>
                    <span class="ms-3 text-muted small">
                        <i class="bi bi-person-check me-1"></i>Received by: <?= htmlspecialchars($dn['received_by']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($dn['items'])): ?>
                    <div class="table-responsive d-none d-md-block">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">Product</th>
                                    <th class="text-center">Qty Delivered</th>
                                    <th class="text-center">PO Qty</th>
                                    <th class="text-center">Unit</th>
                                    <th class="text-center">Condition</th>
                                    <th class="text-center">Coverage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dn['items'] as $di):
                                    $po_qty = 0;
                                    foreach ($po_items_list as $poi) {
                                        if ($poi['product_id'] == $di['product_id']) {
                                            $po_qty = (float)$poi['quantity'];
                                            break;
                                        }
                                    }
                                    $pct = $po_qty > 0 ? min(100, round(($dn_delivered_by_product[$di['product_id']] / $po_qty) * 100)) : null;
                                    $condClass = $di['condition'] === 'good' ? 'text-success' : ($di['condition'] === 'damaged' ? 'text-danger' : 'text-warning');
                                ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-bold small"><?= htmlspecialchars($di['product_name']) ?></div>
                                        <?php if ($di['sku']): ?><small class="text-muted"><?= htmlspecialchars($di['sku']) ?></small><?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold"><?= number_format((float)$di['quantity_delivered'], 2) ?></td>
                                    <td class="text-center text-muted"><?= $po_qty > 0 ? number_format($po_qty, 2) : '—' ?></td>
                                    <td class="text-center small"><?= htmlspecialchars($di['unit'] ?? '') ?></td>
                                    <td class="text-center small <?= $condClass ?>">
                                        <i class="bi bi-<?= $di['condition'] === 'good' ? 'check-circle' : ($di['condition'] === 'damaged' ? 'exclamation-triangle' : 'x-circle') ?> me-1"></i>
                                        <?= ucfirst($di['condition'] ?? '') ?>
                                    </td>
                                    <td class="text-center" style="min-width:120px;">
                                        <?php if ($pct !== null): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height:8px;">
                                                <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : 'bg-warning' ?>" style="width:<?= $pct ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?= $pct ?>%</small>
                                        </div>
                                        <?php else: ?>
                                        <small class="text-muted">—</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Mobile card view for delivery note items -->
                    <div class="d-md-none px-2 py-2">
                        <?php foreach ($dn['items'] as $di):
                            $dpo_qty = 0;
                            foreach ($po_items_list as $poi) {
                                if ($poi['product_id'] == $di['product_id']) { $dpo_qty = (float)$poi['quantity']; break; }
                            }
                            $dpct       = $dpo_qty > 0 ? min(100, round(($dn_delivered_by_product[$di['product_id']] / $dpo_qty) * 100)) : null;
                            $dcondClass = $di['condition'] === 'good' ? 'text-success' : ($di['condition'] === 'damaged' ? 'text-danger' : 'text-warning');
                            $dcondIcon  = $di['condition'] === 'good' ? 'check-circle' : ($di['condition'] === 'damaged' ? 'exclamation-triangle' : 'x-circle');
                        ?>
                        <div class="border rounded mb-2 p-2 bg-white" style="font-size:0.82rem;">
                            <div class="fw-bold"><?= htmlspecialchars($di['product_name']) ?></div>
                            <?php if ($di['sku']): ?><small class="text-muted d-block mb-1"><?= htmlspecialchars($di['sku']) ?></small><?php endif; ?>
                            <div class="d-flex flex-wrap gap-2 mt-1 align-items-center">
                                <span class="text-muted">Delivered: <strong><?= number_format((float)$di['quantity_delivered'], 2) ?></strong></span>
                                <?php if ($dpo_qty > 0): ?><span class="text-muted">PO Qty: <strong><?= number_format($dpo_qty, 2) ?></strong></span><?php endif; ?>
                                <?php if (!empty($di['unit'])): ?><span class="text-muted"><?= htmlspecialchars($di['unit']) ?></span><?php endif; ?>
                                <span class="<?= $dcondClass ?>"><i class="bi bi-<?= $dcondIcon ?> me-1"></i><?= ucfirst($di['condition'] ?? '') ?></span>
                                <?php if ($dpct !== null): ?>
                                <div class="d-flex align-items-center gap-1" style="min-width:90px;">
                                    <div class="progress flex-grow-1" style="height:6px;"><div class="progress-bar <?= $dpct >= 100 ? 'bg-success' : 'bg-warning' ?>" style="width:<?= $dpct ?>%"></div></div>
                                    <small class="text-muted"><?= $dpct ?>%</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted small p-3 mb-0">No items recorded on this delivery note.</p>
                    <?php endif; ?>
                    <?php if ($dn['notes']): ?>
                    <div class="px-3 pb-3 pt-2 border-top">
                        <small class="text-muted"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($dn['notes']) ?></small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <!-- ── END DELIVERY NOTES SECTION ── -->

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

    /* Mobile: sticky breadcrumb nav */
    @media (max-width: 767px) {
        .po-sticky-nav {
            position: sticky;
            top: 0;
            z-index: 1020;
            background: #fff;
            padding: 8px 0 4px;
            margin-bottom: 0.5rem !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
        /* Order info: stack supplier + details vertically */
        .row .col-sm-6.text-sm-end {
            text-align: left !important;
            margin-top: 1rem;
        }
        /* Make card bodies less padded */
        #content .card-body {
            padding: 0.75rem !important;
        }
        /* Mobile item cards & delivery note cards */
        #mobile-items-cards .border,
        .d-md-none .border {
            border-color: #dee2e6 !important;
        }
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
const dnOverallStatus = <?= json_encode($dn_overall_status) ?>;

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
    const displayStatus = (dnOverallStatus && o.status === 'approved') ? dnOverallStatus : o.status;
    const displayColor  = displayStatus === 'complete' ? 'success' : (displayStatus === 'partial' ? 'warning' : getStatusColor(o.status));
    statusEl.text(displayStatus.replace('_', ' ').toUpperCase())
            .removeClass(function(index, className) { return (className.match(/(^|\s)bg-\S+/g) || []).join(' '); })
            .addClass('bg-' + displayColor)
            .toggleClass('text-dark', displayStatus === 'partial');

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

    // Populate Workflow Trail
    $('#workflowCard').show();
    $('#preparedByDisplay').html(`${o.prepared_by_name || o.created_by_name || 'N/A'} <br><small class="text-muted fw-normal">${o.prepared_by_role || 'Staff'}</small>`);
    
    if (o.reviewed_by_name) {
        $('#reviewedByRow').show();
        $('#reviewedByDisplay').html(`${o.reviewed_by_name} <br><small class="text-muted fw-normal">${o.reviewed_by_role}</small>`);
        $('#reviewedAtDisplay').text(o.reviewed_at);
    }
    
    if (o.approved_by_name) {
        $('#approvedByRow').show();
        $('#approvedByDisplay').html(`${o.approved_by_name} <br><small class="text-muted fw-normal">${o.approved_by_role}</small>`);
        $('#approvedAtDisplay').text(o.approved_at);
    }

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
    const grandTotalAmt = calculatedSubtotal + taxTotal + shippingCost;

    $('#subtotal').text(formatCurrency(calculatedSubtotal, currency));
    $('#taxTotal').text(formatCurrency(taxTotal, currency));
    $('#shipping').text(formatCurrency(shippingCost, currency));
    $('#grandTotal').text(formatCurrency(grandTotalAmt, currency));

    // Mobile card view for items
    const $mobileItems = $('#mobile-items-cards').empty();
    items.forEach(item => {
        const lineTotal = parseFloat(item.quantity) * parseFloat(item.unit_price);
        $mobileItems.append(`
            <div class="border rounded mb-2 p-2 bg-white" style="font-size:0.82rem;">
                <div class="fw-bold">${item.product_name}${item.sku ? '<br><small class="text-muted">' + item.sku + '</small>' : ''}</div>
                <div class="d-flex flex-wrap gap-2 mt-1 align-items-center">
                    <span class="text-muted">Qty: <strong>${parseFloat(item.quantity)} ${item.unit || ''}</strong></span>
                    <span class="text-muted">Unit: <strong>${formatCurrency(item.unit_price, currency)}</strong></span>
                    ${item.tax_name ? `<span class="text-muted">Tax: <strong>${item.tax_name}</strong></span>` : ''}
                    <span class="text-danger fw-bold">${formatCurrency(lineTotal, currency)}</span>
                </div>
            </div>
        `);
    });
    $mobileItems.append(`
        <div class="border rounded p-2 bg-light mt-1" style="font-size:0.82rem;">
            <div class="d-flex justify-content-between mb-1"><span class="text-muted">Subtotal</span><strong>${formatCurrency(calculatedSubtotal, currency)}</strong></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-muted">Tax</span><strong>${formatCurrency(taxTotal, currency)}</strong></div>
            <div class="d-flex justify-content-between mb-1"><span class="text-muted">Shipping</span><strong>${formatCurrency(shippingCost, currency)}</strong></div>
            <div class="d-flex justify-content-between fw-bold text-primary border-top pt-1"><span>Grand Total</span><span>${formatCurrency(grandTotalAmt, currency)}</span></div>
        </div>
    `);

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
