<?php
// File: invoice_view.php
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../core/workflow.php';
require_once __DIR__ . '/../../../core/warehouse_scope.php';

// Check permissions
if (!isAuthenticated()) {
    header("Location: " . getUrl('login'));
    exit();
}

// Phase 5a — enforce view permission on invoice detail
autoEnforcePermission('invoices');

// Three-approval workflow capabilities (mirrored to JS below)
$inv_can_review  = canReview('invoices');
$inv_can_approve = canApprove('invoices');
$inv_is_admin    = isAdmin();

// Get Invoice ID
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Phase C — block viewing invoices on projects not in user scope (HTML-safe)
assertScopeForRecordHtml('invoices', 'invoice_id', $invoice_id);

if ($invoice_id <= 0) {
    header("Location: " . getUrl('invoices') . "?error=Invalid Invoice ID");
    exit();
}

// Fetch Invoice Details
global $pdo;
$stmt = $pdo->prepare("
    SELECT
        i.*,
        c.customer_name,
        c.company_name,
        c.email as customer_email,
        c.phone as customer_phone,
        c.address as customer_address,
        p.project_name,
        w.warehouse_name,
        u.username as created_by_name,
        CONCAT_WS(' ', u.first_name, u.last_name) as created_by_full_name,
        u.user_role as created_by_role
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.customer_id
    LEFT JOIN projects p ON i.project_id = p.project_id
    LEFT JOIN warehouses w ON i.warehouse_id = w.warehouse_id
    LEFT JOIN users u ON i.created_by = u.user_id
    WHERE i.invoice_id = ?
");
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header("Location: " . getUrl('invoices') . "?error=Invoice Not Found");
    exit();
}

// Phase 6 (pos_upgrade_plan.md): gate directly on warehouse scope, not just
// project — a user granted only some of a project's warehouses shouldn't be
// able to open an invoice drawn from a different one.
if (!empty($invoice['warehouse_id']) && !userCan('warehouse', (int)$invoice['warehouse_id'])) {
    if (!headers_sent()) http_response_code(403);
    die('Access denied: this warehouse is not in your assigned scope.');
}

// Edit/Delete gating per three_approval.md: once approved, only admin can edit
$inv_can_edit_now   = canEdit('invoices')   && canEditDocument($invoice['status'], $inv_is_admin);
$inv_can_delete_now = canDelete('invoices') && ($invoice['status'] === 'pending' || $inv_is_admin);

// Audit-panel data
$creator_label = trim($invoice['created_by_full_name'] ?? '');
if ($creator_label === '') $creator_label = $invoice['created_by_name'] ?? '';
$wf = [
    'created_by_name'  => $creator_label,
    'created_by_role'  => $invoice['created_by_role'] ?? '',
    'created_at'       => $invoice['created_at'] ?? '',
    'reviewed_by_name' => $invoice['reviewed_by_name'] ?? '',
    'reviewed_by_role' => $invoice['reviewed_by_role'] ?? '',
    'reviewed_at'      => $invoice['reviewed_at']      ?? '',
    'approved_by_name' => $invoice['approved_by_name'] ?? '',
    'approved_by_role' => $invoice['approved_by_role'] ?? '',
    'approved_at'      => $invoice['approved_at']      ?? '',
];

// Fetch Invoice Items
$stmtItems = $pdo->prepare("
    SELECT * 
    FROM invoice_items 
    WHERE invoice_id = ?
");
$stmtItems->execute([$invoice_id]);
$invoiceItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Fetch Payments (join accounts to show specific received-into account name)
$stmtPayments = $pdo->prepare("
    SELECT p.*,
           a.account_name AS received_account_name,
           a.account_code AS received_account_code
    FROM payments p
    LEFT JOIN accounts a ON a.account_id = p.received_into_account_id
    WHERE p.invoice_id = ?
    ORDER BY p.payment_date DESC
");
$stmtPayments->execute([$invoice_id]);
$payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

// Sprint 4: Fetch Payment Attachments
$stmtAtt = $pdo->prepare("
    SELECT pa.*, p.payment_date, p.reference_number
    FROM payment_attachments pa
    JOIN payments p ON pa.payment_id = p.payment_id
    WHERE p.invoice_id = ?
    ORDER BY p.payment_date DESC, pa.uploaded_at DESC
");
$stmtAtt->execute([$invoice_id]);
$paymentAttachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

// Fetch linked SO order number for sidebar
$so_order_number = null;
if (!empty($invoice['order_id'])) {
    $soNumStmt = $pdo->prepare("SELECT order_number FROM sales_orders WHERE sales_order_id = ?");
    $soNumStmt->execute([$invoice['order_id']]);
    $so_order_number = $soNumStmt->fetchColumn() ?: null;
}

// Fetch linked outbound DN / Customer LPO reference numbers (optional traceability)
$dn_reference_number = null;
if (!empty($invoice['delivery_id'])) {
    $dnNumStmt = $pdo->prepare("SELECT delivery_number FROM deliveries WHERE delivery_id = ?");
    $dnNumStmt->execute([$invoice['delivery_id']]);
    $dn_reference_number = $dnNumStmt->fetchColumn() ?: null;
}
$lpo_reference_number = null;
if (!empty($invoice['customer_lpo_id'])) {
    $lpoNumStmt = $pdo->prepare("SELECT lpo_number FROM customer_lpos WHERE lpo_id = ?");
    $lpoNumStmt->execute([$invoice['customer_lpo_id']]);
    $lpo_reference_number = $lpoNumStmt->fetchColumn() ?: null;
}

// Check projects setting
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

// Page Title
$page_title = "Invoice #" . $invoice['invoice_number'];
includeHeader();
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Invoice Details</h1>
            <p class="text-muted mb-0">View details for Invoice #<?= safe_output($invoice['invoice_number']) ?></p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('invoices') ?>" class="btn btn-outline-primary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            <?php if ($enable_projects && !empty($invoice['project_id'])): ?>
                <a href="<?= getUrl('project_view') ?>?id=<?= $invoice['project_id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-kanban"></i> Back to Project
                </a>
            <?php endif; ?>
            <?php if ($inv_can_edit_now): ?>
                <a href="<?= getUrl('invoice_edit') ?>?id=<?= $invoice['invoice_id'] ?>" class="btn btn-primary">
                    <i class="bi bi-pencil"></i> Edit Invoice
                </a>
            <?php endif; ?>
            <div class="btn-group shadow-sm">
                <button onclick="printInvoiceDoc()" class="btn btn-outline-primary btn-sm px-3">
                    <i class="bi bi-printer me-1"></i> Print Invoice
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Print Template</h6></li>
                    <li><a class="dropdown-item" href="#" onclick="printInvoiceDoc('standard'); return false;"><i class="bi bi-check2 me-2"></i>Standard (default)</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printInvoiceDoc('summit'); return false;">Summit</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printInvoiceDoc('wave'); return false;">Wave</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printInvoiceDoc('onyx'); return false;">Onyx</a></li>
                </ul>
            </div>
            <?php
            // Three-approval sequential action buttons (parallel — only the
            // one matching current status is active; the other is disabled).
            $inv_in_workflow = in_array($invoice['status'], ['pending','reviewed'], true);
            if ($inv_in_workflow && $inv_can_review):
                if ($invoice['status'] === 'pending'):
            ?>
                <button type="button" id="btnReviewInvoice" class="btn btn-primary fw-bold" onclick="reviewThisInvoice()">
                    <i class="bi bi-check2 me-1"></i> Mark Reviewed
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-outline-secondary" disabled title="Already reviewed">
                    <i class="bi bi-check2 me-1"></i> Mark Reviewed
                </button>
            <?php
                endif;
            endif;
            if ($inv_in_workflow && $inv_can_approve):
                if ($invoice['status'] === 'reviewed'):
            ?>
                <button type="button" id="btnApproveInvoice" class="btn btn-success fw-bold" onclick="approveThisInvoice()">
                    <i class="bi bi-check-circle me-1"></i> Approve
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-outline-secondary" disabled title="Must be reviewed before approval">
                    <i class="bi bi-check-circle me-1"></i> Approve
                </button>
            <?php
                endif;
            endif;
            ?>
            <?php if (in_array($invoice['status'], ['approved','overdue','partial'], true) && $invoice['balance_due'] > 0): ?>
                <a href="<?= getUrl('payment_create') ?>?invoice=<?= $invoice['invoice_id'] ?>" class="btn btn-success">
                    <i class="bi bi-cash-coin"></i> Record Payment
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Three-approval audit trail (Created / Reviewed / Approved By) -->
    <?php require ROOT_DIR . '/includes/workflow_audit_panel.php'; ?>

    <!-- Status Summary Card -->
    <?php
    $status_colors = [
        'paid' => ['bg' => 'success', 'hex' => '#198754'],
        'partial' => ['bg' => 'primary', 'hex' => '#0d6efd'],
        'sent' => ['bg' => 'info', 'hex' => '#0dcaf0'],
        'overdue' => ['bg' => 'danger', 'hex' => '#dc3545'],
        'draft' => ['bg' => 'secondary', 'hex' => '#6c757d'],
        'pending' => ['bg' => 'warning', 'hex' => '#ffc107']
    ];
    $curr_status = $invoice['status'];
    $st = isset($status_colors[$curr_status]) ? $status_colors[$curr_status] : ['bg' => 'warning', 'hex' => '#ffc107'];
    ?>
    <div class="card shadow-sm mb-4 border-0" style="border-left: 6px solid <?= $st['hex'] ?> !important;">
        <div class="card-body py-3">
            <div class="row align-items-center">
                <div class="col-md-6 border-end">
                    <div class="d-flex align-items-center">
                        <div class="bg-<?= $st['bg'] ?> bg-opacity-10 p-2 rounded-circle me-3">
                            <i class="bi bi-info-circle-fill text-<?= $st['bg'] ?> fs-4"></i>
                        </div>
                        <div>
                            <p class="text-muted small mb-0 text-uppercase fw-bold">Invoice Status</p>
                            <h4 class="fw-bold mb-0 text-<?= $st['bg'] ?>"><?= strtoupper(str_replace('_', ' ', $invoice['status'])) ?></h4>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 ps-md-4">
                    <?php
                    $inv_gt  = floatval($invoice['grand_total']);
                    $inv_pa  = floatval($invoice['paid_amount']);
                    $inv_bd  = floatval($invoice['balance_due']);
                    $inv_pct = $inv_gt > 0 ? min(100, round($inv_pa / $inv_gt * 100)) : 0;
                    if ($inv_pct >= 100)     { $pay_chip = '<span class="badge bg-success">Fully Paid</span>';        $pay_bar = 'bg-success'; }
                    elseif ($inv_pct > 0)   { $pay_chip = '<span class="badge bg-warning text-dark">Partial</span>'; $pay_bar = 'bg-warning'; }
                    else                    { $pay_chip = '<span class="badge bg-danger">Unpaid</span>';              $pay_bar = 'bg-danger';  }
                    ?>
                    <div class="d-flex align-items-start gap-3">
                        <div class="bg-primary bg-opacity-10 p-2 rounded-circle flex-shrink-0">
                            <i class="bi bi-cash-stack text-primary fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <p class="text-muted small mb-0 text-uppercase fw-bold">Payment</p>
                                <?= $pay_chip ?>
                            </div>
                            <div class="progress mb-1" style="height:6px;">
                                <div class="progress-bar <?= $pay_bar ?>" style="width:<?= $inv_pct ?>%"></div>
                            </div>
                            <div class="d-flex justify-content-between" style="font-size:0.72rem;">
                                <span class="text-success font-monospace fw-semibold"><?= number_format($inv_pa, 2) ?> collected</span>
                                <span class="<?= $inv_bd > 0 ? 'text-danger' : 'text-success' ?> font-monospace fw-semibold"><?= number_format($inv_bd, 2) ?> left</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Invoice Info -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white py-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0 fw-bold text-success"><i class="bi bi-list-check"></i> Invoice Items</h5>
                        <div class="d-flex gap-1 flex-wrap align-items-center">
                            <?php
                            $kpi_count = count($invoiceItems);
                            $kpi_units = array_sum(array_column($invoiceItems, 'quantity'));
                            ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:0.68rem;"><?= $kpi_count ?> item<?= $kpi_count != 1 ? 's' : '' ?></span>
                            <span class="badge bg-info bg-opacity-10 text-info border" style="font-size:0.68rem;"><?= number_format($kpi_units, 0) ?> units</span>
                            <?php if (floatval($invoice['tax_amount']) > 0): ?>
                            <span class="badge bg-warning bg-opacity-10 text-warning border font-monospace" style="font-size:0.68rem;">Tax <?= number_format($invoice['tax_amount'], 2) ?></span>
                            <?php endif; ?>
                            <span class="badge bg-success bg-opacity-10 text-success border font-monospace" style="font-size:0.68rem;"><?= safe_output($invoice['currency']) ?> <?= number_format($invoice['grand_total'], 2) ?></span>
                        </div>
                        <span class="text-muted small">Issued: <?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-secondary small text-uppercase">
                                <tr>
                                    <th class="ps-4 py-3">Item Description</th>
                                    <th class="text-center py-3">Qty</th>
                                    <th class="text-end py-3">Unit Price</th>
                                    <th class="text-end py-3">Tax</th>
                                    <th class="text-end pe-4 py-3">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $subtotal = 0;
                                $total_tax = 0;
                                foreach ($invoiceItems as $item): 
                                    $item_subtotal = $item['quantity'] * $item['unit_price'];
                                    $item_tax = $item_subtotal * ($item['tax_rate'] / 100);
                                    $item_total = $item_subtotal + $item_tax;
                                    
                                    $subtotal += $item_subtotal;
                                    $total_tax += $item_tax;
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark"><?= safe_output($item['product_name']) ?></div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border"><?= $item['quantity'] ?></span>
                                        <small class="text-muted"><?= safe_output($item['unit']) ?></small>
                                    </td>
                                    <td class="text-end font-monospace"><?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end text-muted small"><?= $item['tax_rate'] ?>%</td>
                                    <td class="text-end pe-4 fw-bold font-monospace"><?= number_format($item_total, 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="4" class="text-end text-muted pt-3">Subtotal:</td>
                                    <td class="text-end pe-4 font-monospace pt-3"><?= number_format($subtotal, 2) ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end text-muted">VAT (18%):</td>
                                    <td class="text-end pe-4 font-monospace"><?= number_format($invoice['tax_amount'], 2) ?></td>
                                </tr>
                                <?php if (floatval($invoice['discount_amount']) > 0): ?>
                                <tr>
                                    <td colspan="4" class="text-end text-muted">Discount:</td>
                                    <td class="text-end pe-4 font-monospace text-danger">-<?= number_format($invoice['discount_amount'], 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (floatval($invoice['shipping_cost']) > 0): ?>
                                <tr>
                                    <td colspan="4" class="text-end text-muted">Shipping:</td>
                                    <td class="text-end pe-4 font-monospace"><?= number_format($invoice['shipping_cost'], 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr class="border-top border-2">
                                    <td colspan="4" class="text-end fw-bold fs-5 text-dark">Grand Total:</td>
                                    <td class="text-end pe-4 fw-bold fs-5 text-success font-monospace">
                                        <?= number_format($invoice['grand_total'], 2) ?> <?= safe_output($invoice['currency']) ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Payment History -->
            <?php if (count($payments) > 0): ?>
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-clock-history"></i> Payment History</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                       <table class="table table-sm table-hover mb-0">
                           <thead class="bg-light">
                               <tr>
                                   <th class="ps-4">Date</th>
                                   <th>Reference</th>
                                   <th>Received Method</th>
                                   <th class="text-end">Amount</th>
                                   <th class="text-end pe-4">Balance After</th>
                               </tr>
                           </thead>
                           <tbody>
                               <?php
                               $running_bal   = floatval($invoice['grand_total']);
                               $total_pmt_sum = 0;
                               foreach (array_reverse($payments) as $payment):
                                   $running_bal   -= floatval($payment['amount']);
                                   $total_pmt_sum += floatval($payment['amount']);
                               ?>
                               <?php
                               $pmt_method_labels = [
                                   'cash'         => 'Cash',
                                   'bank_transfer'=> 'Bank Account',
                                   'check'        => 'Cheque',
                                   'mobile_money' => 'Mobile Money',
                                   'credit_card'  => 'Credit Card',
                                   'credit'       => 'Credit',
                               ];
                               if ($payment['payment_method'] === 'bank_transfer' && !empty($payment['received_account_name'])) {
                                   $pmt_method_display = $payment['received_account_name'];
                               } else {
                                   $pmt_method_display = $pmt_method_labels[$payment['payment_method']] ?? ucfirst(str_replace('_', ' ', $payment['payment_method']));
                               }
                               ?>
                               <tr>
                                   <td class="ps-4"><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                   <td><?= safe_output($payment['reference_number'] ?? '-') ?></td>
                                   <td><?= safe_output($pmt_method_display) ?></td>
                                   <td class="text-end font-monospace text-success fw-bold"><?= number_format($payment['amount'], 2) ?></td>
                                   <td class="text-end pe-4 font-monospace <?= $running_bal <= 0 ? 'text-success fw-bold' : 'text-warning fw-semibold' ?>">
                                       <?= number_format(max(0, $running_bal), 2) ?>
                                   </td>
                               </tr>
                               <?php endforeach; ?>
                           </tbody>
                           <tfoot class="bg-light">
                               <tr>
                                   <td colspan="3" class="ps-4 text-end fw-bold text-muted">Total Paid:</td>
                                   <td class="text-end font-monospace fw-bold text-success"><?= number_format($total_pmt_sum, 2) ?></td>
                                   <td class="pe-4"></td>
                               </tr>
                           </tfoot>
                       </table> 
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Notes Section -->
            <?php if (!empty($invoice['notes'])): ?>
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Notes</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted"><?= nl2br(safe_output($invoice['notes'])) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Terms & Conditions -->
            <?php if (!empty($invoice['terms_conditions'])): ?>
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-file-text me-1"></i>Terms &amp; Conditions</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted small"><?= nl2br(safe_output($invoice['terms_conditions'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Info -->
        <div class="col-lg-4">
            <!-- Customer Info -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-building"></i> Customer Information</h6>
                </div>
                <div class="card-body">
                    <h5 class="fw-bold mb-1 text-dark"><?= safe_output($invoice['customer_name']) ?></h5>
                    <?php if (!empty($invoice['company_name'])): ?>
                        <div class="text-muted mb-2"><?= safe_output($invoice['company_name']) ?></div>
                    <?php endif; ?>
                    
                    <hr class="my-3">
                    
                    <?php if (!empty($invoice['customer_email'])): ?>
                        <div class="d-flex mb-2 align-items-center">
                            <i class="bi bi-envelope text-muted me-2"></i>
                            <span class="text-truncate"><?= safe_output($invoice['customer_email']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($invoice['customer_phone'])): ?>
                        <div class="d-flex mb-2 align-items-center">
                            <i class="bi bi-telephone text-muted me-2"></i>
                            <span><?= safe_output($invoice['customer_phone']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($invoice['customer_address'])): ?>
                        <div class="d-flex mb-2">
                            <i class="bi bi-geo-alt text-muted me-2 mt-1"></i>
                            <span><?= nl2br(safe_output($invoice['customer_address'])) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Invoice Info -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-info-circle"></i> Invoice Info</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted">Invoice Date:</span>
                        <span class="fw-medium"><?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></span>
                    </div>
                    
                    <?php
                    $due_ts      = strtotime($invoice['due_date']);
                    $today_ts    = strtotime(date('Y-m-d'));
                    $inv_overdue = $due_ts < $today_ts && floatval($invoice['balance_due']) > 0 && !in_array($invoice['status'], ['paid','cancelled']);
                    $overdue_days = $inv_overdue ? (int)(($today_ts - $due_ts) / 86400) : 0;
                    ?>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted">Due Date:</span>
                        <span>
                            <span class="fw-medium <?= $inv_overdue ? 'text-danger' : '' ?>"><?= date('M d, Y', $due_ts) ?></span>
                            <?php if ($inv_overdue): ?>
                            <span class="badge bg-danger ms-1" style="font-size:0.6rem;">Overdue <?= $overdue_days ?>d</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if (!empty($invoice['order_id'])): ?>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted">Sales Order:</span>
                        <a href="<?= getUrl('sales_order_view') ?>?id=<?= $invoice['order_id'] ?>" class="text-decoration-none fw-medium">
                            <?= safe_output($so_order_number ?: 'View Order') ?> <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['delivery_id'])): ?>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted">DN Ref:</span>
                        <a href="<?= getUrl('dn_view') ?>?id=<?= $invoice['delivery_id'] ?>" class="text-decoration-none fw-medium">
                            <?= safe_output($dn_reference_number ?: 'View DN') ?> <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['customer_lpo_id'])): ?>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted">LPO Ref:</span>
                        <a href="<?= getUrl('lpo_view') ?>?id=<?= $invoice['customer_lpo_id'] ?>" class="text-decoration-none fw-medium">
                            <?= safe_output($lpo_reference_number ?: 'View LPO') ?> <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['project_name'])): ?>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted">Project:</span>
                        <span class="fw-medium text-primary"><?= safe_output($invoice['project_name']) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['warehouse_name'])): ?>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted">Warehouse:</span>
                        <span class="fw-medium text-success"><?= safe_output($invoice['warehouse_name']) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['payment_terms'])): ?>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted">Payment Terms:</span>
                        <span class="fw-medium"><?= safe_output(ucwords(str_replace('_', ' ', $invoice['payment_terms']))) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-0">
                        <span class="text-muted">Created By:</span>
                        <span class="fw-medium"><?= safe_output($invoice['created_by_name'] ?? 'N/A') ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Summary Stats -->
            <div class="card shadow-sm border-0 bg-light">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Amount:</span>
                        <span class="fw-bold"><?= number_format($invoice['grand_total'], 2) ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2 text-success">
                        <span>Paid Amount:</span>
                        <span class="fw-bold">-<?= number_format($invoice['paid_amount'], 2) ?></span>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between mb-0 fs-5 pb-1">
                        <span class="fw-bold text-danger">Balance Due:</span>
                        <span class="fw-bold text-danger"><?= number_format($invoice['balance_due'], 2) ?></span>
                    </div>
                </div>
            </div>

            <!-- Payment Attachments (Sprint 4) - d-print-none for printing -->
            <?php if (count($paymentAttachments) > 0): ?>
            <div class="card shadow-sm mt-4 border-0 d-print-none">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-paperclip"></i> Payment Documents</h6>
                </div>
                <div class="card-body p-2">
                    <div class="d-grid gap-2">
                        <?php foreach ($paymentAttachments as $att): ?>
                            <a href="<?= buildUrl($att['file_path']) ?>" target="_blank" 
                               class="btn btn-sm btn-outline-primary d-flex align-items-center justify-content-between p-2 text-decoration-none shadow-sm text-start">
                                <div class="text-truncate me-2">
                                    <i class="bi bi-file-earmark-pdf me-1"></i> 
                                    <span class="fw-bold small"><?= safe_output($att['file_name']) ?></span>
                                    <div class="text-muted" style="font-size: 0.7rem;">
                                        Ref: <?= safe_output($att['reference_number'] ?: date('d/m/Y', strtotime($att['payment_date']))) ?>
                                    </div>
                                </div>
                                <i class="bi bi-eye"></i>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Invoice Details', 'User viewed invoice #<?= $invoice['invoice_number'] ?> for customer <?= addslashes($invoice['customer_name']) ?>');
    
    // Add logging to action links
    $('.btn-info').on('click', function() {
        logReportAction('Initiated Invoice Edit', 'User clicked edit for invoice #<?= $invoice['invoice_number'] ?>');
    });
    
    $('.btn-secondary').on('click', function() {
        if ($(this).find('.bi-printer').length) {
            logReportAction('Printed Invoice', 'User generated a printed report for invoice #<?= $invoice['invoice_number'] ?>');
        }
    });

    $('.btn-success').on('click', function() {
        if ($(this).find('.bi-cash-coin').length) {
            logReportAction('Initiated Payment Record', 'User clicked record payment for invoice #<?= $invoice['invoice_number'] ?>');
        }
    });
});

const INV_ID = <?= (int)$invoice['invoice_id'] ?>;

const INV_PRINT_TEMPLATES = {
    standard: '<?= getUrl('invoice_print') ?>',
    summit:   '<?= getUrl('invoice_print_summit') ?>',
    wave:     '<?= getUrl('invoice_print_wave') ?>',
    onyx:     '<?= getUrl('invoice_print_onyx') ?>'
};
function printInvoiceDoc(template) {
    const base = INV_PRINT_TEMPLATES[template] || INV_PRINT_TEMPLATES.standard;
    window.open(base + '?id=' + INV_ID, '_blank');
}

function reviewThisInvoice() {
    Swal.fire({ title: 'Mark as Reviewed?', text: 'Invoice will move to "Reviewed" and become approvable.', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, mark reviewed', confirmButtonColor: '#0d6efd' }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post('<?= buildUrl('api/account/review_invoice.php') ?>', { invoice_id: INV_ID }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Reviewed!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}

function approveThisInvoice() {
    Swal.fire({ title: 'Approve this Invoice?', text: 'Status will change to Approved.', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, approve it!', confirmButtonColor: '#198754' }).then(function(result) {
        if (!result.isConfirmed) return;
        $.post('<?= buildUrl('api/account/approve_invoice.php') ?>', { invoice_id: INV_ID }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Approved!', text: res.message, timer: 2000, showConfirmButton: false })
                    .then(() => location.reload());
            } else { Swal.fire('Error', res.message, 'error'); }
        }, 'json');
    });
}
</script>

<?php
function get_status_color($status) {
    switch ($status) {
        case 'paid': return 'success';
        case 'partial': return 'primary';
        case 'sent': return 'info';
        case 'overdue': return 'danger';
        case 'draft': return 'secondary';
        default: return 'warning';
    }
}
includeFooter(); 
?>
