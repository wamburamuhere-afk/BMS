<?php
// File: app/bms/sales/quotations/quotation_view.php
// Dedicated quotation details page — reads the `quotations` table.
// Shows the approval workflow (pending -> reviewed -> approved) and the
// status-appropriate action buttons.
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('sales_orders');

global $pdo;

$quotation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($quotation_id <= 0) {
    header("Location: " . getUrl('quotations') . "?error=Invalid Quotation ID");
    exit();
}
assertScopeForRecordHtml('quotations', 'sales_order_id', $quotation_id);

$stmt = $pdo->prepare("
    SELECT q.*,
           c.customer_name, c.company_name,
           COALESCE(NULLIF(TRIM(c.company_email), ''), c.email) AS customer_email,
           c.phone   AS customer_phone,
           c.address AS customer_address,
           u.username AS salesperson_name,
           p.project_name,
           w.warehouse_name,
           TRIM(CONCAT(COALESCE(uc.first_name,''),' ',COALESCE(uc.last_name,''))) AS creator_name,
           TRIM(CONCAT(COALESCE(ur.first_name,''),' ',COALESCE(ur.last_name,''))) AS reviewer_name,
           TRIM(CONCAT(COALESCE(ua.first_name,''),' ',COALESCE(ua.last_name,''))) AS approver_name,
           uc.username AS creator_username,
           ur.username AS reviewer_username,
           ua.username AS approver_username
    FROM quotations q
    LEFT JOIN customers  c ON q.customer_id    = c.customer_id
    LEFT JOIN users      u ON q.salesperson_id = u.user_id
    LEFT JOIN projects   p ON q.project_id     = p.project_id
    LEFT JOIN warehouses w ON q.warehouse_id   = w.warehouse_id
    LEFT JOIN users     uc ON q.created_by     = uc.user_id
    LEFT JOIN users     ur ON q.reviewed_by    = ur.user_id
    LEFT JOIN users     ua ON q.approved_by    = ua.user_id
    WHERE q.sales_order_id = ?
");
$stmt->execute([$quotation_id]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    header("Location: " . getUrl('quotations') . "?error=Quotation Not Found");
    exit();
}

logActivity($pdo, $_SESSION['user_id'], 'View Quotation',
    ($_SESSION['username'] ?? 'User') . " viewed Quotation #{$quote['order_number']}");

$itemStmt = $pdo->prepare("SELECT * FROM quotation_items WHERE order_id = ?");
$itemStmt->execute([$quotation_id]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$enable_projects = 0;
try {
    $s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
    $s->execute();
    $enable_projects = $s->fetchColumn() ?: 0;
} catch (Exception $e) {}

if (!function_exists('quote_status_color')) {
    function quote_status_color($status) {
        switch ($status) {
            case 'approved':  return 'success';
            case 'reviewed':  return 'info';
            case 'pending':   return 'warning';
            case 'cancelled': return 'danger';
            default:          return 'secondary';
        }
    }
}

$status        = $quote['status'] ?: 'pending';
$can_review    = canReview('sales_orders');
$can_approve   = canApprove('sales_orders');
$is_converted  = !empty($quote['converted_to_so_id']);

$creator_label  = trim($quote['creator_name']  ?? '') ?: ($quote['creator_username']  ?? 'Unknown');
$reviewer_label = trim($quote['reviewer_name'] ?? '') ?: ($quote['reviewer_username'] ?? '');
$approver_label = trim($quote['approver_name'] ?? '') ?: ($quote['approver_username'] ?? '');

$page_title = 'Quotation #' . $quote['order_number'];
includeHeader();
?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Quotation Details</h1>
            <p class="text-muted mb-0">View details for Quotation #<?= safe_output($quote['order_number']) ?></p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($enable_projects && !empty($quote['project_id'])): ?>
                <a href="<?= getUrl('project_view') ?>?id=<?= $quote['project_id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-kanban"></i> Back to Project
                </a>
            <?php endif; ?>
            <a href="<?= getUrl('quotations') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            <?php if ($status !== 'approved'): ?>
            <a href="<?= getUrl('quotation_edit') ?>?id=<?= $quote['sales_order_id'] ?>" class="btn btn-info text-white">
                <i class="bi bi-pencil"></i> Edit Quotation
            </a>
            <?php endif; ?>
            <?php if ($status === 'pending' && $can_review): ?>
            <button type="button" class="btn btn-primary" onclick="reviewQuotation(<?= $quote['sales_order_id'] ?>)">
                <i class="bi bi-clipboard-check"></i> Mark as Reviewed
            </button>
            <?php endif; ?>
            <?php if ($status === 'reviewed' && $can_approve): ?>
            <button type="button" class="btn btn-success" onclick="approveQuotation(<?= $quote['sales_order_id'] ?>)">
                <i class="bi bi-check2-circle"></i> Approve
            </button>
            <?php endif; ?>
            <?php if ($status === 'approved' && !$is_converted): ?>
            <button type="button" class="btn btn-success" onclick="convertToOrder(<?= $quote['sales_order_id'] ?>)">
                <i class="bi bi-check-circle"></i> Convert to Order
            </button>
            <?php endif; ?>
            <a href="<?= getUrl('print_quotation') ?>?id=<?= $quote['sales_order_id'] ?>" target="_blank" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print
            </a>
        </div>
    </div>

    <!-- Status Alert -->
    <div class="alert alert-<?= quote_status_color($status) ?> d-flex align-items-center" role="alert">
        <i class="bi bi-info-circle-fill me-2"></i>
        <div>
            <strong>Current Status:</strong> <?= ucfirst($status) ?>
            <?php if ($is_converted): ?>
                &mdash; converted to a Sales Order
            <?php endif; ?>
        </div>
    </div>

    <!-- Approval Workflow Strip -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row text-center g-3">
                <div class="col-md-4">
                    <div class="fw-bold text-uppercase small text-muted mb-1"><i class="bi bi-pencil-square me-1"></i> Created By</div>
                    <div class="fw-bold"><?= safe_output($creator_label) ?></div>
                    <div class="text-muted small"><?= !empty($quote['created_at']) ? date('M d, Y H:i', strtotime($quote['created_at'])) : '—' ?></div>
                </div>
                <div class="col-md-4 border-start">
                    <div class="fw-bold text-uppercase small text-muted mb-1"><i class="bi bi-clipboard-check me-1"></i> Reviewed By</div>
                    <?php if (!empty($quote['reviewed_by'])): ?>
                        <div class="fw-bold text-info"><?= safe_output($reviewer_label) ?></div>
                        <div class="text-muted small"><?= !empty($quote['reviewed_at']) ? date('M d, Y H:i', strtotime($quote['reviewed_at'])) : '' ?></div>
                    <?php else: ?>
                        <div class="text-muted fst-italic">Awaiting review</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 border-start">
                    <div class="fw-bold text-uppercase small text-muted mb-1"><i class="bi bi-check2-circle me-1"></i> Approved By</div>
                    <?php if (!empty($quote['approved_by'])): ?>
                        <div class="fw-bold text-success"><?= safe_output($approver_label) ?></div>
                        <div class="text-muted small"><?= !empty($quote['approved_at']) ? date('M d, Y H:i', strtotime($quote['approved_at'])) : '' ?></div>
                    <?php else: ?>
                        <div class="text-muted fst-italic">Awaiting approval</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Info -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-primary">Quotation Items</h5>
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
                                foreach ($items as $item):
                                    $item_subtotal = $item['quantity'] * $item['unit_price'];
                                    $item_tax = $item_subtotal * ($item['tax_rate'] / 100);
                                    $item_total = $item_subtotal + $item_tax;
                                    $subtotal += $item_subtotal;
                                    $total_tax += $item_tax;
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?= safe_output($item['product_name']) ?></div>
                                        <?php if ($item['sku']): ?>
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
                                <?php if (empty($items)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No items on this quotation.</td></tr>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="4" class="text-end text-muted">Subtotal:</td>
                                    <td class="text-end pe-4 font-monospace"><?= number_format($subtotal, 2) ?></td>
                                </tr>
                                <tr>
                                    <td colspan="4" class="text-end text-muted">VAT (18%):</td>
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

            <?php if (!empty($quote['notes'])): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Quotation Notes</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted"><?= nl2br(safe_output($quote['notes'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Customer Information</h6>
                </div>
                <div class="card-body">
                    <h5 class="fw-bold mb-1"><?= safe_output($quote['customer_name']) ?></h5>
                    <?php if (!empty($quote['company_name'])): ?>
                        <div class="text-muted mb-2"><?= safe_output($quote['company_name']) ?></div>
                    <?php endif; ?>
                    <hr class="my-3">
                    <?php if (!empty($quote['customer_email'])): ?>
                        <div class="d-flex mb-2">
                            <i class="bi bi-envelope text-muted me-2"></i>
                            <span><?= safe_output($quote['customer_email']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($quote['customer_phone'])): ?>
                        <div class="d-flex mb-2">
                            <i class="bi bi-telephone text-muted me-2"></i>
                            <span><?= safe_output($quote['customer_phone']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Quotation Information</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Quotation Date:</span>
                        <span class="fw-medium"><?= date('M d, Y', strtotime($quote['order_date'])) ?></span>
                    </div>
                    <?php if (!empty($quote['quote_valid_until'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Valid Until:</span>
                        <span class="fw-medium text-danger"><?= date('M d, Y', strtotime($quote['quote_valid_until'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($quote['project_name'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Project:</span>
                        <span class="fw-medium text-primary"><?= safe_output($quote['project_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($quote['warehouse_name'])): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Warehouse:</span>
                        <span class="fw-medium text-success"><?= safe_output($quote['warehouse_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Salesperson:</span>
                        <span class="fw-medium"><?= safe_output($quote['salesperson_name'] ?? 'N/A') ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function postWorkflow(url, id, loadingTitle) {
    Swal.fire({ title: loadingTitle, didOpen: () => { Swal.showLoading(); } });
    $.post(url, { quotation_id: id }, function(res) {
        if (res.success) {
            Swal.fire({ icon: 'success', title: 'Done', text: res.message, timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json').fail(function() {
        Swal.fire('Error', 'Communication with server failed', 'error');
    });
}

function reviewQuotation(id) {
    Swal.fire({
        title: 'Mark as Reviewed?',
        text: 'Confirm that you have reviewed this quotation.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Reviewed'
    }).then((result) => {
        if (result.isConfirmed) {
            postWorkflow('<?= buildUrl('api/account/review_quotation.php') ?>', id, 'Submitting review...');
        }
    });
}

function approveQuotation(id) {
    Swal.fire({
        title: 'Approve Quotation?',
        text: 'Approving makes the quotation ready to convert into a sales order.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Approve',
        confirmButtonColor: '#10b981'
    }).then((result) => {
        if (result.isConfirmed) {
            postWorkflow('<?= buildUrl('api/account/approve_quotation.php') ?>', id, 'Approving...');
        }
    });
}

function convertToOrder(id) {
    Swal.fire({
        title: 'Convert to Sales Order?',
        text: 'This will create a new sales order from this approved quotation.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Convert',
        confirmButtonColor: '#10b981'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Converting...', didOpen: () => { Swal.showLoading(); } });
            $.post('<?= buildUrl('api/account/convert_quote_to_order.php') ?>', { id: id }, function(res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Converted!', text: 'A sales order has been created.', timer: 2000, showConfirmButton: false })
                        .then(() => { window.location.href = '<?= getUrl('sales_orders') ?>'; });
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json').fail(function() {
                Swal.fire('Error', 'Communication with server failed', 'error');
            });
        }
    });
}
</script>

<?php includeFooter(); ?>
