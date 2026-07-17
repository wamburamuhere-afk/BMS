<?php
// File: lpo_view.php
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('lpo');

require_once __DIR__ . '/../../../../core/workflow.php';
includeHeader();

$lpo_can_review  = canReview('lpo');
$lpo_can_approve = canApprove('lpo');
$lpo_is_admin    = isAdmin();

$lpo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

assertScopeForRecordHtml('customer_lpos', 'lpo_id', $lpo_id);

// Load linked outbound Delivery Notes for this LPO (screen only — not used in print)
$dn_list = [];
$lpo_items_list = [];
$fulfillment_status = null;
$dn_delivered_by_product = [];

if ($lpo_id) {
    $dnStmt = $pdo->prepare("
        SELECT d.delivery_id, d.delivery_number, d.delivery_date, d.status, d.notes, d.received_by, d.created_at,
               u.username AS created_by_name
        FROM deliveries d
        LEFT JOIN users u ON d.created_by = u.user_id
        WHERE d.customer_lpo_id = ? AND d.status != 'cancelled'
        ORDER BY d.created_at ASC
    ");
    $dnStmt->execute([$lpo_id]);
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

    $loiStmt = $pdo->prepare("SELECT product_id, product_name, quantity FROM customer_lpo_items WHERE lpo_id = ?");
    $loiStmt->execute([$lpo_id]);
    $lpo_items_list = $loiStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($dn_list) && !empty($lpo_items_list)) {
        $all_complete = true;
        $any_delivered = false;
        foreach ($lpo_items_list as $loi) {
            $delivered = $dn_delivered_by_product[$loi['product_id']] ?? 0;
            if ($delivered > 0) $any_delivered = true;
            if ($delivered < (float)$loi['quantity']) $all_complete = false;
        }
        $fulfillment_status = $all_complete ? 'complete' : ($any_delivered ? 'partial' : null);
    }
}
?>

<div class="container-fluid mt-4">
    <nav aria-label="breadcrumb" class="mb-3 lpo-sticky-nav">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('lpos') ?>">Customer LPOs</a></li>
            <li class="breadcrumb-item active">View LPO</li>
        </ol>
    </nav>

    <div id="content" style="display: none;">
        <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center mb-4 gap-2">
            <div>
                <h2 class="fw-bold mb-0">LPO <span id="lpoNumber" class="text-primary"></span></h2>
                <span id="lpoStatus" class="badge rounded-pill mt-2 px-3 py-2"></span>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <a href="<?= getUrl('lpos') ?>" class="btn btn-blue-touch shadow-sm">
                    <i class="bi bi-arrow-left me-1"></i> Back
                </a>
                <div id="workflowActions" class="d-flex flex-wrap gap-2"></div>
                <a href="#" id="editLink" class="btn btn-outline-primary shadow-sm">
                    <i class="bi bi-pencil me-1"></i> Edit
                </a>
                <button onclick="printLpo(<?= $lpo_id ?>)" class="btn btn-blue-touch shadow-sm">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-8">
                <div class="card shadow-sm border-0 mb-4 h-100">
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-sm-6">
                                <h6 class="text-muted text-uppercase small fw-bold">Customer</h6>
                                <h5 class="fw-bold mb-1" id="customerName"></h5>
                                <p class="mb-0" id="customerAddress"></p>
                                <p class="mb-0"><a href="#" id="customerEmail" class="text-decoration-none"></a></p>
                                <p class="mb-0" id="customerPhone"></p>
                            </div>
                            <div class="col-sm-6 text-sm-end">
                                <h6 class="text-muted text-uppercase small fw-bold">LPO Details</h6>
                                <p class="mb-1"><strong>Issue Date:</strong> <span id="issueDate"></span></p>
                                <p class="mb-1" id="expiryRow" style="display:none;"><strong>Expiry Date:</strong> <span id="expiryDate"></span></p>
                                <p class="mb-1" id="projectRow" style="display:none;"><strong>Project:</strong> <span id="projectName" class="text-primary fw-bold"></span></p>
                                <p class="mb-1" id="warehouseRow" style="display:none;"><strong>Warehouse:</strong> <span id="warehouseName" class="text-primary fw-bold"></span></p>
                                <p class="mb-1"><strong>Created By:</strong> <span id="createdBy"></span></p>
                            </div>
                        </div>

                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-hover align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Ordered</th>
                                        <th class="text-center">Delivered</th>
                                        <th class="text-center">Outstanding</th>
                                        <th class="text-end">Unit Price</th>
                                        <th class="text-end">Tax</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTableBody"></tbody>
                                <tfoot class="border-top">
                                    <tr>
                                        <td colspan="6" class="text-end text-muted">Subtotal</td>
                                        <td class="text-end fw-bold" id="subtotal"></td>
                                    </tr>
                                    <tr>
                                        <td colspan="6" class="text-end text-muted">Total Tax</td>
                                        <td class="text-end fw-bold" id="taxTotal"></td>
                                    </tr>
                                    <tr class="bg-light">
                                        <td colspan="6" class="text-end fw-bold fs-5">Grand Total</td>
                                        <td class="text-end fw-bold fs-5 text-primary" id="grandTotal"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div id="mobile-items-cards" class="d-md-none px-1 pt-1"></div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
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
                        <p class="text-muted small mb-1">Description:</p>
                        <p id="descriptionText" class="mb-3 fst-italic">No description provided.</p>
                        <hr>
                        <p class="text-muted small mb-1">Internal Notes:</p>
                        <p id="internalNotes" class="mb-0 small">No notes provided.</p>
                    </div>
                </div>

                <div id="attachmentsSection" class="card shadow-sm border-0 mb-3 d-print-none" style="display: none;">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-2"></i> Attachments</h6>
                    </div>
                    <div class="card-body">
                        <div id="attachmentsList" class="d-grid gap-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($dn_list)): ?>
        <!-- ── FULFILLMENT SECTION (linked outbound DNs) — screen only ── -->
        <div class="d-print-none mt-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h5 class="fw-bold mb-0"><i class="bi bi-truck me-2 text-primary"></i>Delivery Notes (Outbound)</h5>
                <small class="text-muted"><?= count($dn_list) ?> delivery note<?= count($dn_list) > 1 ? 's' : '' ?></small>
            </div>
            <?php foreach ($dn_list as $dn): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-light py-2">
                    <a href="<?= getUrl('dn_view') ?>?id=<?= $dn['delivery_id'] ?>" class="fw-bold text-decoration-none"><i class="bi bi-file-earmark-text me-1"></i><?= htmlspecialchars($dn['delivery_number']) ?></a>
                    <span class="ms-3 text-muted small"><i class="bi bi-calendar2 me-1"></i><?= htmlspecialchars($dn['delivery_date'] ?: date('Y-m-d', strtotime($dn['created_at']))) ?></span>
                    <span class="ms-3 badge bg-secondary text-uppercase"><?= htmlspecialchars($dn['status']) ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (!empty($dn['items'])): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th class="ps-3">Product</th><th class="text-center">Qty Delivered</th><th class="text-center">Unit</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dn['items'] as $di): ?>
                                <tr>
                                    <td class="ps-3"><?= htmlspecialchars($di['product_name']) ?></td>
                                    <td class="text-center fw-bold"><?= number_format((float)$di['quantity_delivered'], 2) ?></td>
                                    <td class="text-center small"><?= htmlspecialchars($di['unit'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="text-muted small p-3 mb-0">No items recorded on this delivery note.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <style>
    .btn-blue-touch { background-color: #0d6efd !important; border-color: #0d6efd !important; color: #fff !important; transition: all 0.2s ease; }
    .btn-blue-touch:hover, .btn-blue-touch:active, .btn-blue-touch:focus { background-color: #0b5ed7 !important; border-color: #0a58ca !important; color: #fff !important; box-shadow: 0 0 0 0.25rem rgba(49, 132, 253, 0.5) !important; }
    @media (max-width: 767px) {
        .lpo-sticky-nav { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 8px 0 4px; margin-bottom: 0.5rem !important; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
        .row .col-sm-6.text-sm-end { text-align: left !important; margin-top: 1rem; }
        #content .card-body { padding: 0.75rem !important; }
    }
    @media print {
        body { background: white !important; }
        .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
        nav[aria-label="breadcrumb"], .breadcrumb, .btn, .d-print-none { display: none !important; }
        .card { border: none !important; box-shadow: none !important; }
    }
    </style>
</div>

<script>
const lpoId = <?= $lpo_id ?>;
const fulfillmentStatus = <?= json_encode($fulfillment_status) ?>;
const LPO_CAN_REVIEW  = <?= $lpo_can_review  ? 'true' : 'false' ?>;
const LPO_CAN_APPROVE = <?= $lpo_can_approve ? 'true' : 'false' ?>;
const LPO_IS_ADMIN    = <?= $lpo_is_admin    ? 'true' : 'false' ?>;
const LPO_CAN_CREATE_DN = <?= json_encode(canCreate('dn')) ?>;

$(document).ready(function() { loadLpoDetails(); });

function loadLpoDetails() {
    $.ajax({
        url: '<?= buildUrl("api/customer/get_lpo.php") ?>',
        data: { lpo_id: lpoId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                renderLpo(response.data);
                logReportAction('Viewed LPO Details', 'User viewed details for LPO ' + response.data.lpo_number);
                $('#content').fadeIn();
            } else {
                Swal.fire('Error', response.message, 'error').then(() => { window.location.href = '<?= getUrl("lpos") ?>'; });
            }
        },
        error: function() { Swal.fire('Error', 'Failed to load LPO details.', 'error'); }
    });
}

function renderLpo(o) {
    const items = o.items || [];
    const currency = o.currency || 'TZS';

    $('#lpoNumber').text(o.lpo_number);

    const statusEl = $('#lpoStatus');
    const displayStatus = (fulfillmentStatus && (o.status === 'approved' || o.status === 'partially_fulfilled')) ? fulfillmentStatus : o.status;
    const displayColor = displayStatus === 'complete' ? 'success' : (displayStatus === 'partial' ? 'warning' : getStatusColor(o.status));
    statusEl.text(String(displayStatus).replace(/_/g, ' ').toUpperCase())
            .removeClass(function(index, className) { return (className.match(/(^|\s)bg-\S+/g) || []).join(' '); })
            .addClass('bg-' + displayColor)
            .toggleClass('text-dark', displayStatus === 'partial');

    // Workflow actions — sequential per three_approval.md
    const workflow = $('#workflowActions');
    workflow.empty();

    if (o.status === 'pending' && LPO_CAN_REVIEW) {
        workflow.append(`<button class="btn btn-blue-touch shadow-sm" onclick="submitForReview()"><i class="bi bi-check2 me-1"></i> Mark Reviewed</button>`);
    } else if (o.status === 'reviewed' && LPO_CAN_APPROVE) {
        workflow.append(`<button class="btn btn-success shadow-sm" onclick="approveLpo()"><i class="bi bi-check-circle-fill me-1"></i> Approve LPO</button>`);
    }

    if ((o.status === 'approved' || o.status === 'partially_fulfilled') && LPO_CAN_CREATE_DN) {
        workflow.append(`<a href="<?= getUrl('dn_outbound') ?>?lpo_id=${o.lpo_id}" class="btn btn-outline-info shadow-sm"><i class="bi bi-truck me-1"></i> Create DN (Outbound)</a>`);
    }

    if (!['pending', 'reviewed', 'approved'].includes(o.status) && !LPO_IS_ADMIN) {
        // Once past approval, editing is admin-only (mirrors PO behavior)
    }
    const canEditNow = (o.status === 'pending' || o.status === 'reviewed') || LPO_IS_ADMIN;
    if (!canEditNow) {
        $('#editLink').hide();
    } else {
        $('#editLink').show().attr('href', '<?= getUrl("lpo_create") ?>?edit=' + o.lpo_id);
    }

    // Customer
    $('#customerName').text(o.customer_display_name || '');
    $('#customerAddress').text(o.customer_address || '');
    $('#customerEmail').text(o.customer_email || '').attr('href', 'mailto:' + (o.customer_email || ''));
    $('#customerPhone').text(o.customer_phone || '');

    $('#issueDate').text(o.issue_date);
    if (o.expiry_date) { $('#expiryDate').text(o.expiry_date); $('#expiryRow').show(); }
    if (o.project_name) { $('#projectName').text(o.project_name); $('#projectRow').show(); }
    if (o.warehouse_name) { $('#warehouseName').text(o.warehouse_name); $('#warehouseRow').show(); }
    $('#createdBy').text(o.created_by_name || '');

    // Authorization trail
    $('#workflowCard').show();
    $('#preparedByDisplay').html(`${o.prepared_by_name || o.created_by_name || 'N/A'} <br><small class="text-muted fw-normal">${o.prepared_by_role || 'Staff'}</small>`);
    if (o.reviewed_by_name) {
        $('#reviewedByRow').show();
        $('#reviewedByDisplay').html(`${o.reviewed_by_name} <br><small class="text-muted fw-normal">${o.reviewed_by_role}</small>`);
        $('#reviewedAtDisplay').text(o.reviewed_at || '');
    }
    if (o.approved_by_name) {
        $('#approvedByRow').show();
        $('#approvedByDisplay').html(`${o.approved_by_name} <br><small class="text-muted fw-normal">${o.approved_by_role}</small>`);
        $('#approvedAtDisplay').text(o.approved_at || '');
    }

    // Items
    const tbody = $('#itemsTableBody');
    tbody.empty();
    let calculatedSubtotal = 0, calculatedTax = 0;
    items.forEach(item => {
        const qty = parseFloat(item.quantity);
        const price = parseFloat(item.unit_price);
        const tax = parseFloat(item.tax_rate) || 0;
        const lineSubtotal = qty * price;
        const lineTax = lineSubtotal * (tax / 100);
        calculatedSubtotal += lineSubtotal;
        calculatedTax += lineTax;

        tbody.append(`
            <tr>
                <td><div class="fw-bold">${item.product_name}</div></td>
                <td class="text-center">${qty}</td>
                <td class="text-center text-muted">—</td>
                <td class="text-center">—</td>
                <td class="text-end">${formatCurrency(price, currency)}</td>
                <td class="text-end">${tax}%</td>
                <td class="text-end fw-bold">${formatCurrency(item.total, currency)}</td>
            </tr>
        `);
    });

    $('#subtotal').text(formatCurrency(calculatedSubtotal, currency));
    $('#taxTotal').text(formatCurrency(calculatedTax, currency));
    $('#grandTotal').text(formatCurrency(o.amount, currency));

    const $mobileItems = $('#mobile-items-cards').empty();
    items.forEach(item => {
        $mobileItems.append(`
            <div class="border rounded mb-2 p-2 bg-white" style="font-size:0.82rem;">
                <div class="fw-bold">${item.product_name}</div>
                <div class="d-flex flex-wrap gap-2 mt-1 align-items-center">
                    <span class="text-muted">Qty: <strong>${item.quantity}</strong></span>
                    <span class="text-muted">Unit: <strong>${formatCurrency(item.unit_price, currency)}</strong></span>
                    <span class="text-danger fw-bold">${formatCurrency(item.total, currency)}</span>
                </div>
            </div>
        `);
    });

    if (o.description) $('#descriptionText').text(o.description).removeClass('fst-italic');
    if (o.notes) $('#internalNotes').text(o.notes);

    const attachments = o.attachments || [];
    if (attachments.length > 0) {
        const list = $('#attachmentsList');
        list.empty();
        attachments.forEach(att => {
            list.append(`
                <a href="${att.download_url}" target="_blank" class="btn btn-sm btn-outline-primary d-flex align-items-center justify-content-between p-2">
                    <span class="text-truncate me-2"><i class="bi bi-file-earmark-pdf me-1"></i> ${att.original_name}</span>
                    <i class="bi bi-eye"></i>
                </a>
            `);
        });
        $('#attachmentsSection').show();
    }
}

function getStatusColor(status) {
    switch (status) {
        case 'approved':
        case 'open': return 'info';
        case 'reviewed': return 'primary';
        case 'pending': return 'warning';
        case 'cancelled': return 'danger';
        case 'fulfilled': return 'success';
        case 'partially_fulfilled': return 'primary';
        default: return 'secondary';
    }
}

function submitForReview() {
    Swal.fire({
        title: 'Submit for Review?', text: 'This LPO will be sent for review.',
        icon: 'question', showCancelButton: true, confirmButtonColor: '#0d6efd',
        confirmButtonText: 'Yes, Submit', cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.post('<?= getUrl('api/review_lpo') ?>', { lpo_id: lpoId }, function(res) {
            if (res.success) { Swal.fire({ icon: 'success', title: 'Submitted!', text: res.message }).then(() => location.reload()); }
            else { Swal.fire({ icon: 'error', title: 'Error', text: res.message }); }
        }, 'json');
    });
}

function approveLpo() {
    Swal.fire({
        title: 'Approve LPO?', text: 'Are you sure you want to approve this LPO?',
        icon: 'question', showCancelButton: true, confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Approve', cancelButtonText: 'Cancel'
    }).then(result => {
        if (!result.isConfirmed) return;
        $.post('<?= getUrl('api/approve_lpo') ?>', { lpo_id: lpoId }, function(res) {
            if (res.success) { Swal.fire({ icon: 'success', title: 'Approved!', text: res.message }).then(() => location.reload()); }
            else { Swal.fire({ icon: 'error', title: 'Error', text: res.message }); }
        }, 'json');
    });
}

function formatCurrency(amount, currency) {
    return currency + ' ' + parseFloat(amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
}

function printLpo(id) {
    logReportAction('Printed LPO', 'User printed LPO details for LPO ID #' + id);
    window.open('<?= getUrl('print_lpo') ?>?id=' + id, '_blank');
}
</script>

<?php includeFooter(); ?>
