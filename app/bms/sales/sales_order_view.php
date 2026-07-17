<?php
// Check permissions
if (!isAuthenticated()) {
    header("Location: login.php");
    exit();
}

// Phase 5a — enforce view permission on sales order detail
autoEnforcePermission('sales_orders');

require_once ROOT_DIR . '/core/workflow.php';
require_once ROOT_DIR . '/core/warehouse_scope.php';

// Three-approval workflow capabilities (mirrored to JS below)
$so_can_review  = canReview('sales_orders');
$so_can_approve = canApprove('sales_orders');
$so_is_admin    = isAdmin();

// Get Order ID
$order_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($order_id <= 0) {
    header("Location: " . getUrl('sales_orders') . "?error=Invalid Order ID");
    exit();
}

// Phase C — block viewing sales orders on projects not in user scope (HTML-safe)
assertScopeForRecordHtml('sales_orders', 'sales_order_id', $order_id);

// Fetch Order Details
global $pdo;
$stmt = $pdo->prepare("
    SELECT
        so.*,
        c.customer_name,
        c.company_name,
        c.email as customer_email,
        c.phone as customer_phone,
        c.address as customer_address,
        u.username as salesperson_name,
        u_creator.first_name AS creator_first,
        u_creator.last_name  AS creator_last,
        p.project_name,
        w.warehouse_name,
        COALESCE((SELECT SUM(inv.grand_total) FROM invoices inv WHERE inv.order_id = so.sales_order_id AND inv.status != 'cancelled'), 0) AS total_invoiced_amt,
        COALESCE((SELECT COUNT(*) FROM invoices inv WHERE inv.order_id = so.sales_order_id AND inv.status != 'cancelled'), 0) AS invoice_count,
        COALESCE((SELECT SUM(pmt.amount) FROM payments pmt JOIN invoices inv2 ON pmt.invoice_id = inv2.invoice_id WHERE inv2.order_id = so.sales_order_id AND pmt.status = 'completed' AND inv2.status != 'cancelled'), 0) AS total_paid
    FROM sales_orders so
    LEFT JOIN customers c ON so.customer_id = c.customer_id
    LEFT JOIN users u ON so.salesperson_id = u.user_id
    LEFT JOIN users u_creator ON so.created_by = u_creator.user_id
    LEFT JOIN projects p ON so.project_id = p.project_id
    LEFT JOIN warehouses w ON so.warehouse_id = w.warehouse_id
    WHERE so.sales_order_id = ? AND (so.is_quote = 0 OR so.is_quote IS NULL)
");
$stmt->execute([$order_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header("Location: " . getUrl('sales_orders') . "?error=Order Not Found");
    exit();
}

// Phase 6 (pos_upgrade_plan.md): gate directly on warehouse scope, not just
// project — a user granted only some of a project's warehouses shouldn't be
// able to open a sales order drawn from a different one.
if (!empty($order['warehouse_id']) && !userCan('warehouse', (int)$order['warehouse_id'])) {
    if (!headers_sent()) http_response_code(403);
    die('Access denied: this warehouse is not in your assigned scope.');
}

// Log Activity
$type_label = 'Sales Order';
$action = "View $type_label";
$user_name = $_SESSION['username'] ?? 'User';
$description = "$user_name viewed $type_label #{$order['order_number']}";

logActivity($pdo, $_SESSION['user_id'], $action, $description);

// Fetch Order Items
$stmtItems = $pdo->prepare("
    SELECT * 
    FROM sales_order_items 
    WHERE order_id = ?
");
$stmtItems->execute([$order_id]);
$orderItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Fetch linked invoices for Related Documents card
$invLinkedStmt = $pdo->prepare("
    SELECT invoice_id, invoice_number, status, grand_total, invoice_date
    FROM invoices
    WHERE order_id = ? AND status != 'cancelled'
    ORDER BY invoice_date DESC
");
$invLinkedStmt->execute([$order_id]);
$linked_invoices = $invLinkedStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch source quotation (reverse lookup via converted_to_so_id)
$srcQuoteStmt = $pdo->prepare("SELECT sales_order_id, order_number FROM quotations WHERE converted_to_so_id = ? LIMIT 1");
$srcQuoteStmt->execute([$order_id]);
$source_quote = $srcQuoteStmt->fetch(PDO::FETCH_ASSOC);

// Check projects setting
$enable_projects = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $stmt->execute();
    $enable_projects = $stmt->fetchColumn() ?: 0;
} catch (Exception $e) {}

// Page Title — this page serves Sales Orders only (quotations use quotation_view.php)
$doc_label = 'Sales Order';
$page_title = $doc_label . " #" . $order['order_number'];

// Edit/Delete gating per three_approval.md: once approved, only admin can edit
$so_can_edit_now   = canEdit('sales_orders')   && canEditDocument($order['status'], $so_is_admin);
$so_can_delete_now = canDelete('sales_orders') && ($order['status'] === 'pending' || $so_is_admin);

// Audit-panel data
$creator_name = trim(($order['creator_first'] ?? '') . ' ' . ($order['creator_last'] ?? ''));
if ($creator_name === '') $creator_name = $order['salesperson_name'] ?? '';
$wf = [
    'created_by_name'  => $creator_name,
    'created_by_role'  => '',
    'created_at'       => $order['created_at'] ?? '',
    'reviewed_by_name' => $order['reviewed_by_name'] ?? '',
    'reviewed_by_role' => $order['reviewed_by_role'] ?? '',
    'reviewed_at'      => $order['reviewed_at']      ?? '',
    'approved_by_name' => $order['approved_by_name'] ?? '',
    'approved_by_role' => $order['approved_by_role'] ?? '',
    'approved_at'      => $order['approved_at']      ?? '',
];

require_once 'header.php';
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800"><?= $doc_label ?> Details</h1>
            <p class="text-muted mb-0">View details for <?= $doc_label ?> #<?= safe_output($order['order_number']) ?></p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($enable_projects && !empty($order['project_id'])): ?>
                <a href="<?= getUrl('project_view') ?>?id=<?= $order['project_id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-kanban"></i> Back to Project
                </a>
            <?php endif; ?>
            <a href="<?= getUrl('sales_orders') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            <?php if ($so_can_edit_now): ?>
                <a href="<?= getUrl('sales_order_edit') ?>?id=<?= $order['sales_order_id'] ?>" class="btn btn-outline-info">
                    <i class="bi bi-pencil"></i> Edit
                </a>
            <?php endif; ?>
            <?php if (in_array($order['status'], ['approved', 'processing', 'shipped'], true)): ?>
                <a href="<?= getUrl('dn_outbound') ?>?order=<?= $order['sales_order_id'] ?>" class="btn btn-info text-white">
                    <i class="bi bi-box-arrow-up-right"></i> Create Delivery Note
                </a>
            <?php endif; ?>
            <?php if ($order['status'] === 'approved' || $order['status'] === 'processing'): ?>
                <a href="<?= getUrl('invoice_create') ?>?order=<?= $order['sales_order_id'] ?>" class="btn btn-success">
                    <i class="bi bi-receipt"></i> Create Invoice
                </a>
            <?php endif; ?>
            <div class="btn-group shadow-sm">
                <button onclick="printSalesOrderDoc()" class="btn btn-primary btn-sm px-3">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
                <button type="button" class="btn btn-primary btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Print Template</h6></li>
                    <li><a class="dropdown-item" href="#" onclick="printSalesOrderDoc('standard'); return false;"><i class="bi bi-check2 me-2"></i>Standard (default)</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printSalesOrderDoc('confirmation'); return false;">Confirmation</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printSalesOrderDoc('ledger'); return false;">Ledger</a></li>
                    <li><a class="dropdown-item" href="#" onclick="printSalesOrderDoc('studio'); return false;">Studio</a></li>
                </ul>
            </div>
            <?php if ($so_can_edit_now && in_array($order['status'], ['pending','reviewed','approved','processing'])): ?>
            <button type="button" class="btn btn-outline-danger" onclick="cancelThisOrder()">
                <i class="bi bi-x-circle"></i> Cancel Order
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Alert -->
    <div class="alert alert-<?= get_status_color($order['status']) ?> d-flex align-items-center" role="alert">
        <i class="bi bi-info-circle-fill me-2"></i>
        <div>
            <strong>Current Status:</strong> <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
        </div>
    </div>

    <!-- Three-approval audit trail (Created / Reviewed / Approved By) -->
    <?php require ROOT_DIR . '/includes/workflow_audit_panel.php'; ?>

    <!-- Three-approval sequential action bar (only the next valid step is shown) -->
    <?php
    $show_review  = ($order['status'] === 'pending') && $so_can_review;
    $show_approve = ($order['status'] === 'reviewed') && $so_can_approve;
    if ($show_review || $show_approve):
    ?>
    <div class="d-flex gap-2 mb-4">
        <?php if ($show_review): ?>
            <button type="button" class="btn btn-primary" onclick="reviewThisOrder()">
                <i class="bi bi-check2 me-1"></i> Mark Reviewed
            </button>
        <?php endif; ?>
        <?php if ($show_approve): ?>
            <button type="button" class="btn btn-success" onclick="approveThisOrder()">
                <i class="bi bi-check-circle me-1"></i> Approve Order
            </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    $fin_invoiced    = (float)($order['total_invoiced_amt'] ?? 0);
    $fin_paid        = (float)($order['total_paid']         ?? 0);
    $fin_grand       = (float)($order['grand_total']        ?? 0);
    $fin_outstanding = max(0, $fin_grand - $fin_paid);
    $fin_currency    = $order['currency'] ?? 'TZS';
    $fin_inv_count   = (int)($order['invoice_count']        ?? 0);
    if ($fin_grand > 0 && $fin_paid >= $fin_grand * 0.999) {
        $billing_label = 'Fully Paid';   $billing_color = 'success';
    } elseif ($fin_paid > $fin_grand) {
        $billing_label = 'Overpaid';     $billing_color = 'info';
    } elseif ($fin_grand > 0 && $fin_invoiced >= $fin_grand * 0.999) {
        $billing_label = 'Fully Billed'; $billing_color = 'warning';
    } elseif ($fin_invoiced > 0) {
        $billing_label = 'Part. Billed'; $billing_color = 'warning';
    } else {
        $billing_label = 'Unbilled';     $billing_color = 'secondary';
    }
    ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="row g-0 text-center">
                <div class="col-6 col-md-3 py-2">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Grand Total</div>
                    <div class="fw-bold text-primary font-monospace"><?= $fin_currency ?> <?= number_format($fin_grand, 2) ?></div>
                </div>
                <div class="col-6 col-md-3 border-start py-2">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Invoiced <?php if ($fin_inv_count > 0): ?><span class="badge bg-info bg-opacity-10 text-info border" style="font-size:0.6rem;"><?= $fin_inv_count ?></span><?php endif; ?></div>
                    <div class="fw-bold text-info font-monospace"><?= $fin_currency ?> <?= number_format($fin_invoiced, 2) ?></div>
                </div>
                <div class="col-6 col-md-3 border-start py-2">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Paid</div>
                    <div class="fw-bold text-success font-monospace"><?= $fin_currency ?> <?= number_format($fin_paid, 2) ?></div>
                </div>
                <div class="col-6 col-md-3 border-start py-2">
                    <div class="small text-muted text-uppercase fw-bold mb-1">Outstanding <span class="badge bg-<?= $billing_color ?> bg-opacity-10 text-<?= $billing_color ?> border" style="font-size:0.6rem;"><?= $billing_label ?></span></div>
                    <div class="fw-bold <?= $fin_outstanding > 0 ? 'text-danger' : 'text-muted' ?> font-monospace"><?= $fin_currency ?> <?= number_format($fin_outstanding, 2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Order Info -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0 fw-bold text-primary">Order Items</h5>
                    <div class="d-flex gap-2 flex-wrap">
                        <span class="badge bg-light text-dark border px-2 py-1"><i class="bi bi-box me-1"></i><?= count($orderItems) ?> item<?= count($orderItems) !== 1 ? 's' : '' ?></span>
                        <span class="badge bg-light text-dark border px-2 py-1"><i class="bi bi-layers me-1"></i><?= number_format(array_sum(array_column($orderItems, 'quantity')), 0) ?> units</span>
                        <?php if ((float)($order['tax_amount'] ?? 0) > 0): ?>
                        <span class="badge bg-warning bg-opacity-10 text-warning border px-2 py-1"><i class="bi bi-percent me-1"></i>Tax <?= safe_output($order['currency'] ?? 'TZS') ?> <?= number_format($order['tax_amount'], 2) ?></span>
                        <?php endif; ?>
                        <span class="badge bg-primary bg-opacity-10 text-primary border px-2 py-1 fw-semibold"><i class="bi bi-cash me-1"></i><?= safe_output($order['currency'] ?? 'TZS') ?> <?= number_format($order['grand_total'], 2) ?></span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Product / Description</th>
                                    <th class="text-center">Ordered</th>
                                    <th class="text-center">Delivered</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Tax</th>
                                    <th class="text-end pe-4">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $subtotal = 0;
                                $total_tax = 0;
                                foreach ($orderItems as $item): 
                                    $item_subtotal = $item['quantity'] * $item['unit_price'];
                                    $item_tax = $item_subtotal * ($item['tax_rate'] / 100);
                                    $item_total = $item_subtotal + $item_tax;
                                    
                                    $subtotal += $item_subtotal;
                                    $total_tax += $item_tax;
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?= safe_output($item['product_name']) ?></div>
                                        <?php if($item['sku']): ?>
                                            <small class="text-muted">SKU: <?= safe_output($item['sku']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border"><?= $item['quantity'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $qty_del = (float)($item['quantity_delivered'] ?? 0);
                                        $qty_ord = (float)$item['quantity'];
                                        if ($qty_del >= $qty_ord && $qty_ord > 0) {
                                            $del_cls = 'text-success fw-bold';
                                        } elseif ($qty_del > 0) {
                                            $del_cls = 'text-warning fw-bold';
                                        } else {
                                            $del_cls = 'text-muted';
                                        }
                                        ?>
                                        <span class="<?= $del_cls ?>"><?= number_format($qty_del, 0) ?></span>
                                    </td>
                                    <td class="text-end font-monospace"><?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end text-muted small"><?= $item['tax_rate'] ?>%</td>
                                    <td class="text-end pe-4 fw-bold font-monospace"><?= number_format($item_total, 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <?php
                            $tot_ord_qty = array_sum(array_column($orderItems, 'quantity'));
                            $tot_del_qty = array_sum(array_column($orderItems, 'quantity_delivered'));
                            $del_pct     = $tot_ord_qty > 0 ? min(100, round($tot_del_qty / $tot_ord_qty * 100)) : 0;
                            $del_bar     = $del_pct >= 100 ? 'bg-success' : ($del_pct > 0 ? 'bg-warning' : 'bg-secondary');
                            ?>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="5" class="text-end text-muted">Subtotal:</td>
                                    <td class="text-end pe-4 font-monospace"><?= number_format($subtotal, 2) ?></td>
                                </tr>
                                <?php if ((float)($order['discount_amount'] ?? 0) > 0): ?>
                                <tr>
                                    <td colspan="5" class="text-end text-success">Discount:</td>
                                    <td class="text-end pe-4 font-monospace text-success">- <?= number_format($order['discount_amount'], 2) ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td colspan="5" class="text-end text-muted">VAT (18%):</td>
                                    <td class="text-end pe-4 font-monospace"><?= number_format($total_tax, 2) ?></td>
                                </tr>
                                <tr>
                                    <td colspan="5" class="text-end fw-bold fs-5">Grand Total:</td>
                                    <td class="text-end pe-4 fw-bold fs-5 text-primary font-monospace">
                                        <?= number_format($order['grand_total'], 2) ?>
                                    </td>
                                </tr>
                                <?php if ($tot_ord_qty > 0): ?>
                                <tr>
                                    <td colspan="6" class="pt-2 pb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <small class="text-muted text-nowrap fw-bold">Delivery:</small>
                                            <div class="progress flex-grow-1" style="height:6px;border-radius:3px;">
                                                <div class="progress-bar <?= $del_bar ?>" style="width:<?= $del_pct ?>%"></div>
                                            </div>
                                            <small class="text-muted text-nowrap"><?= number_format($tot_del_qty, 0) ?>/<?= number_format($tot_ord_qty, 0) ?> units (<?= $del_pct ?>%)</small>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Notes Section -->
            <?php if (!empty($order['notes'])): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Order Notes</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted"><?= nl2br(safe_output($order['notes'])) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($order['terms_conditions'])): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-file-text me-1"></i>Terms &amp; Conditions</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted small"><?= nl2br(safe_output($order['terms_conditions'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar Info -->
        <div class="col-lg-4">
            <!-- Customer Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Customer Information</h6>
                </div>
                <div class="card-body">
                    <h5 class="fw-bold mb-1"><?= safe_output($order['customer_name']) ?></h5>
                    <?php if (!empty($order['company_name'])): ?>
                        <div class="text-muted mb-2"><?= safe_output($order['company_name']) ?></div>
                    <?php endif; ?>
                    
                    <hr class="my-3">
                    
                    <?php if (!empty($order['customer_email'])): ?>
                        <div class="d-flex mb-2">
                            <i class="bi bi-envelope text-muted me-2"></i>
                            <span><?= safe_output($order['customer_email']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($order['customer_phone'])): ?>
                        <div class="d-flex mb-2">
                            <i class="bi bi-telephone text-muted me-2"></i>
                            <span><?= safe_output($order['customer_phone']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Order Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Order Information</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Order Date:</span>
                        <span class="fw-medium"><?= date('M d, Y', strtotime($order['order_date'])) ?></span>
                    </div>
                    <?php if ($source_quote): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Source Quote:</span>
                        <a href="<?= getUrl('quotation_view') ?>?id=<?= $source_quote['sales_order_id'] ?>" class="fw-medium text-primary text-decoration-none">
                            <?= safe_output($source_quote['order_number']) ?> <i class="bi bi-box-arrow-up-right" style="font-size:0.7rem;"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['quote_valid_until'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Valid Until:</span>
                        <span class="fw-medium text-danger"><?= date('M d, Y', strtotime($order['quote_valid_until'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['project_name'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Project:</span>
                        <span class="fw-medium text-primary"><?= safe_output($order['project_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['warehouse_name'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Warehouse:</span>
                        <span class="fw-medium text-success"><?= safe_output($order['warehouse_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Salesperson:</span>
                        <span class="fw-medium"><?= safe_output($order['salesperson_name'] ?? 'N/A') ?></span>
                    </div>
                    <?php if (!empty($order['delivery_date'])):
                        $del_today_ts  = strtotime(date('Y-m-d'));
                        $del_due_ts    = strtotime($order['delivery_date']);
                        $del_days_over = (int)(($del_today_ts - $del_due_ts) / 86400);
                        $del_overdue   = $del_due_ts < $del_today_ts && !in_array($order['status'], ['delivered','completed','cancelled']);
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">Delivery Date:</span>
                        <div class="text-end">
                            <?php if ($del_overdue): ?>
                                <span class="badge bg-danger">Overdue <?= $del_days_over ?>d</span>
                                <div class="small text-muted mt-1"><?= date('M d, Y', $del_due_ts) ?></div>
                            <?php else: ?>
                                <span class="fw-medium"><?= date('M d, Y', $del_due_ts) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['payment_terms'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Payment Terms:</span>
                        <span class="fw-medium"><?= safe_output($order['payment_terms']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['reference'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Reference:</span>
                        <span class="fw-medium text-info"><?= safe_output($order['reference']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($order['currency'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Currency:</span>
                        <span class="fw-medium"><?= safe_output($order['currency']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Related Invoices -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-receipt me-1"></i>Related Invoices</h6>
                    <?php if (!empty($linked_invoices)): ?>
                    <span class="badge bg-info bg-opacity-10 text-info border"><?= count($linked_invoices) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($linked_invoices)): ?>
                    <div class="p-3 text-center text-muted small">
                        No invoices yet.
                        <?php if (in_array($order['status'], ['approved','processing','partially_delivered'])): ?>
                        <br><a href="<?= getUrl('invoice_create') ?>?order=<?= $order['sales_order_id'] ?>" class="btn btn-sm btn-outline-success mt-2">
                            <i class="bi bi-plus-circle me-1"></i>Create Invoice
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else:
                        $inv_color_map = ['paid' => 'success', 'overdue' => 'danger', 'partial' => 'warning', 'sent' => 'info', 'draft' => 'secondary'];
                    ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($linked_invoices as $inv):
                            $inv_color = $inv_color_map[$inv['status']] ?? 'secondary';
                        ?>
                        <li class="list-group-item px-3 py-2 d-flex justify-content-between align-items-center">
                            <div>
                                <a href="<?= getUrl('invoice_view') ?>?id=<?= $inv['invoice_id'] ?>" class="fw-medium text-decoration-none small"><?= safe_output($inv['invoice_number']) ?></a>
                                <div class="text-muted" style="font-size:0.7rem;"><?= !empty($inv['invoice_date']) ? date('M d, Y', strtotime($inv['invoice_date'])) : '' ?></div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?= $inv_color ?> bg-opacity-10 text-<?= $inv_color ?> border" style="font-size:0.6rem;"><?= ucfirst($inv['status']) ?></span>
                                <div class="small fw-medium mt-1 font-monospace"><?= number_format($inv['grand_total'], 2) ?></div>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
function get_status_color($status) {
    switch ($status) {
        case 'approved': return 'success';
        case 'reviewed': return 'info';
        case 'pending': return 'warning';
        case 'cancelled': return 'danger';
        default: return 'primary';
    }
}
?>

<script>
const SO_ID = <?= (int)$order['sales_order_id'] ?>;

const SO_PRINT_TEMPLATES = {
    standard:     '<?= getUrl('print_sales_order') ?>',
    confirmation: '<?= getUrl('print_sales_order_confirmation') ?>',
    ledger:       '<?= getUrl('print_sales_order_ledger') ?>',
    studio:       '<?= getUrl('print_sales_order_studio') ?>'
};
function printSalesOrderDoc(template) {
    const base = SO_PRINT_TEMPLATES[template] || SO_PRINT_TEMPLATES.standard;
    window.open(base + '?id=' + SO_ID, '_blank');
}

function reviewThisOrder() {
    Swal.fire({
        title: 'Mark as Reviewed?',
        text: 'This Sales Order will move to "Reviewed" and become approvable.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: 'Yes, mark reviewed',
        cancelButtonText: 'Cancel'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/account/review_sales_order.php') ?>', { sales_order_id: SO_ID }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Reviewed!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}

function cancelThisOrder() {
    Swal.fire({
        title: 'Cancel Sales Order?',
        text: 'This will cancel the order. This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Cancel Order',
        cancelButtonText: 'No, Keep It'
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Cancelling...', didOpen: () => { Swal.showLoading(); } });
        $.post('<?= buildUrl('api/account/update_sales_order_status.php') ?>', { order_id: SO_ID, status: 'cancelled' }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Cancelled', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json').fail(function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Communication with server failed.' });
        });
    });
}

function approveThisOrder() {
    Swal.fire({
        title: 'Approve Sales Order?',
        text: 'Are you sure you want to approve this order?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/account/approve_sales_order.php') ?>', { sales_order_id: SO_ID }, function(res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Approved!', text: res.message, timer: 2000, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json');
    });
}
</script>

<?php include 'footer.php'; ?>
