<?php
// File: app/bms/grn/dn_view.php
// scope-audit: skip — DN view page; deliveries table has project_id but dn_view uses delivery_id from URL; Phase G-2 will add assertScopeForRecordHtml('deliveries', 'delivery_id', $id)
// View a single Delivery Note — inbound (Record) or outbound (Create).
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/workflow.php';
autoEnforcePermission('dn');
includeHeader();

// Three-approval workflow capabilities
$dn_can_review  = canReview('dn');
$dn_can_approve = canApprove('dn');
$dn_is_admin    = isAdmin();

global $pdo;

$delivery_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($delivery_id <= 0) { echo '<div class="alert alert-danger m-4">Invalid DN ID.</div>'; includeFooter(); exit; }

$stmt = $pdo->prepare("
    SELECT d.*,
           COALESCE(s.supplier_name, sc.supplier_name, cu.customer_name) AS party_name,
           COALESCE(s.company_name, sc.company_name, cu.company_name)    AS party_company,
           COALESCE(s.phone, sc.phone, cu.phone)                        AS party_phone,
           COALESCE(s.address, sc.address, cu.address)                  AS party_address,
           w.warehouse_name, w.location AS warehouse_location,
           p.project_name, p.contract_number AS contract_no,
           u.username  AS created_by_name,
           ab.username AS approved_by_name,
           cl.lpo_number AS lpo_number
    FROM deliveries d
    LEFT JOIN suppliers s        ON d.supplier_id      = s.supplier_id
    LEFT JOIN sub_contractors sc ON d.subcontractor_id = sc.supplier_id
    LEFT JOIN customers cu       ON d.customer_id      = cu.customer_id
    LEFT JOIN customer_lpos cl  ON d.customer_lpo_id  = cl.lpo_id
    LEFT JOIN warehouses w       ON d.warehouse_id     = w.warehouse_id
    LEFT JOIN projects p         ON d.project_id       = p.project_id
    LEFT JOIN users u            ON d.created_by       = u.user_id
    LEFT JOIN users ab           ON d.approved_by      = ab.user_id
    WHERE d.delivery_id = ?
");
$stmt->execute([$delivery_id]);
$dn = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$dn) { echo '<div class="alert alert-danger m-4">Delivery Note not found.</div>'; includeFooter(); exit; }

$items_stmt = $pdo->prepare("
    SELECT di.*, p.product_name, p.sku, p.unit
    FROM delivery_items di
    LEFT JOIN products p ON di.product_id = p.product_id
    WHERE di.delivery_id = ? ORDER BY di.delivery_item_id
");
$items_stmt->execute([$delivery_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Attachments (supplier DN scans)
$att_stmt = $pdo->prepare("SELECT * FROM delivery_attachments WHERE delivery_id = ? ORDER BY attachment_id");
$att_stmt->execute([$delivery_id]);
$attachments = $att_stmt->fetchAll(PDO::FETCH_ASSOC);

// Linked Invoice (outbound customer DNs — created via the "Create Invoice"
// button above, which stamps invoices.delivery_id back to this DN). Boss's
// requirement: show whether it's Paid or just Approved.
$linked_invoice = null;
$inv_stmt = $pdo->prepare("SELECT invoice_id, invoice_number, status, grand_total FROM invoices WHERE delivery_id = ? AND status != 'cancelled' ORDER BY invoice_date DESC LIMIT 1");
$inv_stmt->execute([$delivery_id]);
$linked_invoice = $inv_stmt->fetch(PDO::FETCH_ASSOC);

$is_inbound  = ($dn['dn_type'] ?? 'inbound') !== 'outbound';
$is_subcon   = ($dn['party_type'] ?? 'supplier') === 'subcontractor';
$is_customer = ($dn['party_type'] ?? 'supplier') === 'customer';
$party_label = $is_customer ? 'Customer' : ($is_subcon ? 'Sub-Contractor' : 'Supplier');
$edit_route  = $is_inbound ? 'dn_create' : 'dn_outbound';
$dn_display  = $is_inbound ? ($dn['dn_number'] ?: $dn['delivery_number']) : $dn['delivery_number'];

// Check if any items have damage/expiry (return flow)
$has_return_items = !empty(array_filter($items, fn($i) => ($i['condition'] ?? 'good') !== 'good'));
$can_create_return = $is_inbound && $dn['status'] === 'approved' && $has_return_items && canCreate('dn');

$return_url   = getUrl('delivery_notes') . ($is_inbound ? '' : '?type=outbound');
$status_colors = ['pending'=>'warning','reviewed'=>'primary','approved'=>'info','partially_delivered'=>'warning','dispatched'=>'info','delivered'=>'success','completed'=>'success','cancelled'=>'danger'];
$status_color  = $status_colors[$dn['status']] ?? 'secondary';
$total_qty     = array_sum(array_column($items, 'quantity_delivered'));

// Edit/Delete gating
$dn_can_edit_now   = canEdit('dn')   && canEditDocument($dn['status'], $dn_is_admin);
$dn_can_delete_now = canDelete('dn') && ($dn_is_admin || in_array($dn['status'], ['draft','pending','cancelled']));

// Audit-panel data
$wf = [
    'created_by_name'  => $dn['prepared_by_name'] ?: ($dn['created_by_name'] ?? ''),
    'created_by_role'  => $dn['prepared_by_role'] ?? '',
    'created_at'       => $dn['created_at'] ?? '',
    'reviewed_by_name' => $dn['reviewed_by_name'] ?? '',
    'reviewed_by_role' => $dn['reviewed_by_role'] ?? '',
    'reviewed_at'      => $dn['reviewed_at']      ?? '',
    'approved_by_name' => $dn['approved_by_name'] ?? '',
    'approved_by_role' => $dn['approved_by_role'] ?? '',
    'approved_at'      => $dn['approved_at']      ?? '',
];
?>

<div class="container-fluid mt-3">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3 d-print-none dn-view-sticky-nav">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $return_url ?>">Delivery Notes</a></li>
            <li class="breadcrumb-item active">View DN</li>
        </ol>
    </nav>

    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-start gap-2 mb-4 d-print-none">
        <div>
            <h4 class="fw-bold mb-1">
                <i class="bi <?= $is_inbound ? 'bi-box-arrow-in-down' : 'bi-box-arrow-up-right' ?> text-primary me-2"></i>
                Delivery Note — <span class="text-primary"><?= safe_output($dn_display) ?></span>
                <span class="badge bg-<?= $is_inbound ? 'primary' : 'info' ?>-subtle text-<?= $is_inbound ? 'primary' : 'info' ?> border border-<?= $is_inbound ? 'primary' : 'info' ?> ms-1" style="font-size:.65rem;">
                    <?= $is_inbound ? 'INBOUND' : 'OUTBOUND' ?>
                </span>
                <span class="badge bg-<?= $status_color ?> ms-1" style="font-size:.7rem;"><?= strtoupper($dn['status']) ?></span>
            </h4>
            <p class="text-muted small mb-0">
                <?= $is_inbound ? 'Goods received from' : 'Goods sent to' ?>
                <strong><?= safe_output($dn['party_name']) ?></strong> (<?= $party_label ?>)
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($dn_can_edit_now): ?>
            <a href="<?= getUrl($edit_route) ?>?edit=<?= $delivery_id ?>" class="btn btn-warning btn-sm shadow-sm">
                <i class="bi bi-pencil me-1"></i> Edit
            </a>
            <?php endif; ?>

            <?php
            // Three-approval sequential buttons (parallel — only one active at a time)
            $dn_in_workflow = in_array($dn['status'], ['pending','reviewed'], true);
            if ($dn_in_workflow && $dn_can_review):
                if ($dn['status'] === 'pending'):
            ?>
                <button class="btn btn-primary btn-sm shadow-sm fw-bold" onclick="markReviewedFromView()">
                    <i class="bi bi-check2 me-1"></i> Mark Reviewed
                </button>
            <?php else: ?>
                <button class="btn btn-outline-secondary btn-sm" disabled title="Already reviewed">
                    <i class="bi bi-check2 me-1"></i> Mark Reviewed
                </button>
            <?php
                endif;
            endif;
            if ($dn_in_workflow && $dn_can_approve):
                if ($dn['status'] === 'reviewed'):
            ?>
                <button class="btn btn-success btn-sm shadow-sm fw-bold" onclick="approveDNFromView()">
                    <i class="bi bi-check-circle me-1"></i> Approve DN
                </button>
            <?php else: ?>
                <button class="btn btn-outline-secondary btn-sm" disabled title="Must be reviewed before approval">
                    <i class="bi bi-check-circle me-1"></i> Approve DN
                </button>
            <?php
                endif;
            endif;
            ?>

            <?php if ($is_inbound && in_array($dn['status'], ['approved','partially_delivered'], true) && canCreate('grn')): ?>
            <a href="<?= getUrl('grn_create') ?>?dn=<?= $delivery_id ?>"
               class="btn btn-success btn-sm shadow-sm fw-bold">
                <i class="bi bi-clipboard-plus me-1"></i> Create GRN
            </a>
            <?php endif; ?>

            <?php if (!$is_inbound && $is_customer && $dn['status'] === 'approved' && canCreate('invoices')): ?>
            <a href="<?= getUrl('invoice_create') ?>?delivery=<?= $delivery_id ?>"
               class="btn btn-success btn-sm shadow-sm fw-bold">
                <i class="bi bi-receipt me-1"></i> Create Invoice
            </a>
            <?php endif; ?>

            <?php if ($can_create_return): ?>
            <button class="btn btn-warning btn-sm shadow-sm fw-bold" onclick="createReturnDN(<?= $delivery_id ?>)">
                <i class="bi bi-arrow-return-left me-1"></i> Return Items
            </button>
            <?php endif; ?>

            <?php if ($dn_can_delete_now): ?>
            <button class="btn btn-outline-danger btn-sm" onclick="deleteDN(<?= $delivery_id ?>)">
                <i class="bi bi-trash me-1"></i> Delete
            </button>
            <?php endif; ?>
            <?php if ($is_inbound): ?>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.open('<?= getUrl('print_delivery_note') ?>?id=<?= $delivery_id ?>', '_blank')">
                <i class="bi bi-printer me-1"></i> Print
            </button>
            <?php else: ?>
            <div class="btn-group shadow-sm">
                <button class="btn btn-outline-secondary btn-sm" onclick="printDnDoc()">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Print Template</h6></li>
                    <li><a class="dropdown-item" href="#" onclick="printDnDoc('standard'); return false;"><i class="bi bi-check2 me-2"></i>Standard (default)</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printDnDoc('depot'); return false;">Depot</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printDnDoc('transit'); return false;">Transit</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printDnDoc('custody'); return false;">Custody</a></li>
                </ul>
            </div>
            <?php endif; ?>
            <a href="<?= $return_url ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <!-- Three-approval audit trail (Created / Reviewed / Approved By) -->
    <?php require ROOT_DIR . '/includes/workflow_audit_panel.php'; ?>

    <div class="row g-4">
        <!-- Info -->
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle text-primary me-2"></i>Delivery Note Information</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">DN Number</div>
                                <div class="fw-bold text-primary"><?= safe_output($dn_display) ?></div>
                                <?php if ($is_inbound): ?>
                                <small class="text-muted">System Ref: <?= safe_output($dn['delivery_number']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">DN Date</div>
                                <div class="fw-bold"><?= format_date($dn['delivery_date']) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1"><?= $party_label ?></div>
                                <div class="fw-bold"><?= safe_output($dn['party_name']) ?></div>
                                <?php if (!empty($dn['party_company'])): ?>
                                <small class="text-muted"><?= safe_output($dn['party_company']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Warehouse</div>
                                <div class="fw-bold"><i class="bi bi-building text-primary me-1"></i><?= safe_output($dn['warehouse_name']) ?></div>
                                <?php if (!empty($dn['warehouse_location'])): ?>
                                <small class="text-muted"><?= safe_output($dn['warehouse_location']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($dn['project_name'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Project</div>
                                <div class="fw-bold"><?= safe_output($dn['project_name']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dn['customer_lpo_id'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Customer LPO Reference</div>
                                <a href="<?= getUrl('lpo_view') ?>?id=<?= (int)$dn['customer_lpo_id'] ?>" class="fw-bold text-decoration-none">
                                    <i class="bi bi-file-earmark-text me-1"></i><?= safe_output($dn['lpo_number']) ?>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($linked_invoice): ?>
                        <?php
                        $inv_status_colors = [
                            'paid' => 'success', 'partial' => 'primary', 'sent' => 'info',
                            'overdue' => 'danger', 'draft' => 'secondary', 'pending' => 'warning',
                            'reviewed' => 'info', 'approved' => 'info', 'cancelled' => 'secondary',
                        ];
                        $inv_badge = $inv_status_colors[$linked_invoice['status']] ?? 'secondary';
                        ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Linked Invoice</div>
                                <a href="<?= getUrl('invoice_view') ?>?id=<?= (int)$linked_invoice['invoice_id'] ?>" class="fw-bold text-decoration-none">
                                    <i class="bi bi-receipt me-1"></i><?= safe_output($linked_invoice['invoice_number']) ?>
                                </a>
                                <span class="badge bg-<?= $inv_badge ?> ms-1"><?= strtoupper($linked_invoice['status']) ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dn['contact_person'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Contact Person</div>
                                <div class="fw-bold"><?= safe_output($dn['contact_person']) ?></div>
                                <?php if (!empty($dn['contact_phone'])): ?>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i><?= safe_output($dn['contact_phone']) ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dn['delivery_address'])): ?>
                        <div class="col-sm-6">
                            <div class="border rounded p-3 bg-light h-100">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Delivery Address</div>
                                <div class="fw-bold"><?= safe_output($dn['delivery_address']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($dn['vehicle_number']) || !empty($dn['driver_name']) || !empty($dn['shipping_method'])): ?>
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <div class="text-muted small text-uppercase fw-bold mb-2"><i class="bi bi-truck text-primary me-1"></i>Transport / Carrier Details</div>
                                <div class="row g-2">
                                    <?php if (!empty($dn['vehicle_number'])): ?>
                                    <div class="col-sm-4">
                                        <small class="text-muted d-block">Vehicle / Truck</small>
                                        <span class="fw-bold"><?= safe_output($dn['vehicle_number']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($dn['driver_name'])): ?>
                                    <div class="col-sm-4">
                                        <small class="text-muted d-block">Driver</small>
                                        <span class="fw-bold"><?= safe_output($dn['driver_name']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($dn['shipping_method'])): ?>
                                    <div class="col-sm-4">
                                        <small class="text-muted d-block">Shipping Method</small>
                                        <span class="fw-bold"><?= safe_output($dn['shipping_method']) ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($dn['notes'])): ?>
                        <div class="col-12">
                            <div class="border rounded p-3 bg-light">
                                <div class="text-muted small text-uppercase fw-bold mb-1">Notes</div>
                                <div><?= safe_output($dn['notes']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Items -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-list-task text-primary me-2"></i><?= $is_inbound ? 'Materials Received' : 'Materials Sent' ?></h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-uppercase small fw-bold">
                                <tr>
                                    <th class="ps-3" style="width:50px;">S/NO</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th class="text-center">Quantity</th>
                                    <th>Unit</th>
                                    <th class="text-center">Condition</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $idx => $item):
                                    $cond = $item['condition'] ?? 'good';
                                    $cond_color = ['good'=>'success','damaged'=>'danger','expired'=>'warning'][$cond] ?? 'secondary';
                                ?>
                                <tr>
                                    <td class="ps-3 text-muted fw-bold"><?= $idx + 1 ?></td>
                                    <td><div class="fw-bold"><?= safe_output($item['product_name']) ?></div></td>
                                    <td><code><?= safe_output($item['sku'] ?? 'N/A') ?></code></td>
                                    <td class="text-center fw-bold text-primary fs-6"><?= number_format($item['quantity_delivered'], 3) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= safe_output($item['unit'] ?? 'pcs') ?></span></td>
                                    <td class="text-center"><span class="badge bg-<?= $cond_color ?>"><?= ucfirst($cond) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end ps-3">Total</td>
                                    <td class="text-center text-primary fs-6"><?= number_format($total_qty, 3) ?></td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Attachments (supplier's DN scans) -->
            <?php if ($is_inbound || $attachments): ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip text-primary me-2"></i>Supplier's Delivery Note — Attachments
                        <span class="badge bg-primary ms-1"><?= count($attachments) ?></span>
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($attachments)): ?>
                    <div class="text-muted small text-center py-3"><i class="bi bi-inbox me-1"></i>No attachments on this delivery note.</div>
                    <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($attachments as $a): ?>
                        <a href="<?= getUrl($a['file_path']) ?>" target="_blank"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span class="text-truncate">
                                <i class="bi bi-file-earmark-text text-primary me-2"></i>
                                <strong><?= safe_output($a['file_name']) ?></strong>
                            </span>
                            <span class="badge bg-primary-subtle text-primary border border-primary">
                                <i class="bi bi-eye me-1"></i>View
                            </span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-activity me-2"></i>Status &amp; Audit</h6>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Direction</div>
                        <span class="badge bg-<?= $is_inbound ? 'primary' : 'info' ?>-subtle text-<?= $is_inbound ? 'primary' : 'info' ?> border border-<?= $is_inbound ? 'primary' : 'info' ?> px-3 py-2">
                            <?= $is_inbound ? 'INBOUND — Received' : 'OUTBOUND — Sent' ?>
                        </span>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Current Status</div>
                        <span class="badge bg-<?= $status_color ?> px-3 py-2 fs-6"><?= strtoupper($dn['status']) ?></span>
                    </div>
                    <hr>
                    <div class="mb-2">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Created By</div>
                        <div class="fw-bold"><?= safe_output($dn['created_by_name'] ?? 'N/A') ?></div>
                        <small class="text-muted"><?= format_date($dn['created_at']) ?></small>
                    </div>
                    <?php if ($dn['approved_by']): ?>
                    <div class="mb-2">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Approved By</div>
                        <div class="fw-bold text-success"><?= safe_output($dn['approved_by_name'] ?? 'N/A') ?></div>
                        <small class="text-muted"><?= format_date($dn['approved_at']) ?></small>
                    </div>
                    <?php endif; ?>
                    <hr>
                    <div class="mb-2">
                        <div class="text-muted small fw-bold text-uppercase mb-1">Total Items</div>
                        <div class="fw-bold fs-5 text-primary"><?= count($items) ?></div>
                    </div>
                    <div>
                        <div class="text-muted small fw-bold text-uppercase mb-1">Total Quantity</div>
                        <div class="fw-bold fs-5 text-primary"><?= number_format($total_qty, 3) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const DN_ID = <?= (int)$delivery_id ?>;

const DN_PRINT_TEMPLATES = {
    standard: '<?= getUrl('print_delivery_note') ?>',
    depot:    '<?= getUrl('print_delivery_note_depot') ?>',
    transit:  '<?= getUrl('print_delivery_note_transit') ?>',
    custody:  '<?= getUrl('print_delivery_note_custody') ?>'
};
function printDnDoc(template) {
    const base = DN_PRINT_TEMPLATES[template] || DN_PRINT_TEMPLATES.standard;
    window.open(base + '?id=' + DN_ID, '_blank');
}

function markReviewedFromView() {
    Swal.fire({
        title: 'Mark as Reviewed?',
        text: 'DN will move to Reviewed and become approvable.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#0d6efd', confirmButtonText: 'Yes, mark reviewed'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/review_dn.php') ?>', { delivery_id: DN_ID }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Reviewed!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function approveDNFromView() {
    Swal.fire({
        title: 'Approve Delivery Note?',
        text: 'Once approved, stock movements will fire and a GRN will be created automatically.',
        icon: 'question', showCancelButton: true,
        confirmButtonColor: '#198754', confirmButtonText: 'Yes, approve'
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Approving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.post('<?= buildUrl('api/approve_dn.php') ?>', { delivery_id: DN_ID }, function(res) {
            if (res.success) {
                const msg = res.message + (res.auto_grn_ref ? '<br><small class="text-muted">GRN <strong>' + res.auto_grn_ref + '</strong> created (pending).</small>' : '');
                Swal.fire({ icon: 'success', title: 'Approved!', html: msg, timer: 3000, showConfirmButton: false })
                    .then(() => location.reload());
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function createReturnDN(dnId) {
    Swal.fire({
        title: 'Create Return DN?',
        text: 'A pending outbound DN will be created for all damaged/expired items from this receipt.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#ffc107', confirmButtonText: 'Yes, create return', cancelButtonText: 'Cancel'
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Creating...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.post('<?= buildUrl('api/create_return_dn.php') ?>', { delivery_id: dnId }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Return DN Created!', text: res.message, timer: 2000, showConfirmButton: false })
                    .then(() => { window.location.href = '<?= getUrl('dn_view') ?>?id=' + res.delivery_id; });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

// Legacy helper retained in case any other view links here for non-three-approval
// status changes (dispatched, delivered, cancelled).
function changeDNStatus(id, newStatus) {
    Swal.fire({
        title: 'Update Status?', text: 'Change status to ' + newStatus + '?', icon: 'question',
        showCancelButton: true, confirmButtonColor: '#0d6efd', confirmButtonText: 'Yes'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= getUrl("api/operations/change_dn_status") ?>', { delivery_id: id, status: newStatus }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Updated!', text: res.message, confirmButtonColor: '#0d6efd' })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

function deleteDN(id) {
    Swal.fire({
        title: 'Delete Delivery Note?', text: 'This action cannot be undone.', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete', cancelButtonText: 'Cancel'
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Deleting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.post('<?= getUrl("api/delete_dn") ?>', { delivery_id: id }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, confirmButtonColor: '#0d6efd' })
                    .then(() => { window.location.href = '<?= $return_url ?>'; });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}
</script>

<style>
@media (max-width: 767px) {
    .dn-view-sticky-nav { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 6px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.06); }
    .container-fluid { padding-left: 10px !important; padding-right: 10px !important; }
}
</style>

<?php includeFooter(); ?>
