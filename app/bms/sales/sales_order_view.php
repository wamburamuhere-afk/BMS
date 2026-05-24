<?php
// Check permissions
if (!isAuthenticated()) {
    header("Location: login.php");
    exit();
}

require_once ROOT_DIR . '/core/workflow.php';

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
        w.warehouse_name
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
            <?php if ($order['status'] === 'approved' || $order['status'] === 'processing'): ?>
                <a href="<?= getUrl('invoice_create') ?>?id=<?= $order['sales_order_id'] ?>" class="btn btn-success">
                    <i class="bi bi-receipt"></i> Create Invoice
                </a>
            <?php endif; ?>
            <a href="<?= getUrl('print_sales_order') ?>?id=<?= $order['sales_order_id'] ?>" target="_blank" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print
            </a>
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

    <div class="row">
        <!-- Main Order Info -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-primary">Order Items</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Product / Description</th>
                                    <th class="text-center">Qty</th>
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
                                    <td class="text-end font-monospace"><?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end text-muted small"><?= $item['tax_rate'] ?>%</td>
                                    <td class="text-end pe-4 fw-bold font-monospace"><?= number_format($item_total, 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="4" class="text-end text-muted">Subtotal:</td>
                                    <td class="text-end pe-4 font-monospace"><?= number_format($subtotal, 2) ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end text-muted">Tax:</td>
                                    <td class="text-end pe-4 font-monospace"><?= number_format($total_tax, 2) ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end fw-bold fs-5">Grand Total:</td>
                                    <td class="text-end pe-4 fw-bold fs-5 text-primary font-monospace">
                                        <?= number_format($subtotal + $total_tax, 2) ?>
                                    </td>
                                </tr>
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
