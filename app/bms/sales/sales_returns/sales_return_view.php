<?php
// File: app/bms/sales/sales_returns/sales_return_view.php
// scope-audit: skip — sales_returns has no direct project_id; scope enforced via parent list (sales_returns.php filters by so.project_id)
require_once __DIR__ . '/../../../../roots.php';
autoEnforcePermission('sales_returns');

includeHeader();

$return_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($return_id <= 0) {
    echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Invalid Return ID</div></div>';
    includeFooter();
    exit;
}

global $pdo;

// Fetch return details
// Using the correct column names derived from debug: sales_return_id as PK, total_amount as grand_total
$stmt = $pdo->prepare("
    SELECT
        sr.sales_return_id as return_id,
        sr.return_number,
        sr.return_date,
        sr.total_amount     as subtotal_amount,
        COALESCE(sr.total_tax, 0)   as total_tax,
        COALESCE(sr.grand_total, sr.total_amount) as grand_total,
        sr.reason,
        sr.status,
        sr.sales_order_id,
        so.order_number,
        c.customer_name,
        c.company_name,
        c.email as customer_email,
        c.phone as customer_phone,
        u.username as created_by_name
    FROM sales_returns sr
    LEFT JOIN sales_orders so ON sr.sales_order_id = so.sales_order_id
    LEFT JOIN customers c ON sr.customer_id = c.customer_id
    LEFT JOIN users u ON sr.created_by = u.user_id
    WHERE sr.sales_return_id = ?
");
$stmt->execute([$return_id]);
$return = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$return) {
    echo '<div class="container-fluid mt-4"><div class="alert alert-danger">Return not found</div></div>';
    includeFooter();
    exit;
}

// Log Activity
require_once __DIR__ . '/../../../../helpers.php';
$user_name = $_SESSION['username'] ?? 'User';
logActivity($pdo, $_SESSION['user_id'], 'View Sales Return', "$user_name viewed Sales Return #{$return['return_number']}");

// Fetch items
$stmtItems = $pdo->prepare("
    SELECT 
        sri.*, 
        COALESCE(p.product_name, 'Unknown Product') as product_name,
        COALESCE(p.sku, 'N/A') as sku
    FROM sales_return_items sri
    LEFT JOIN products p ON sri.product_id = p.product_id
    WHERE sri.sales_return_id = ?
");
$stmtItems->execute([$return_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// Status Badge Helper
$status_colors = [
    'pending'  => 'warning',
    'reviewed' => 'info',
    'approved' => 'primary',
    'refunded' => 'success',
    'rejected' => 'secondary'
];
$status_color = $status_colors[$return['status']] ?? 'secondary';

// Three-approval permissions — used to gate Review/Approve button visibility.
$can_review_sr  = canReview('sales_returns');
$can_approve_sr = canApprove('sales_returns');

// Phase 1H — Credit Note linkage. Once an approved return has a credit note, the
// note carries the refund recognition (replaces the legacy "Mark Refunded").
$existing_cn = null;
try {
    $cnq = $pdo->prepare("SELECT credit_note_id, credit_note_number FROM credit_notes
                           WHERE sales_return_id = ? AND status NOT IN ('deleted','rejected','cancelled')
                           ORDER BY credit_note_id DESC LIMIT 1");
    $cnq->execute([$return_id]);
    $existing_cn = $cnq->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) { $existing_cn = null; }
$can_create_cn = canCreate('credit_notes');

?>

<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Return #<?= safe_output($return['return_number']) ?></h1>
            <div class="mt-2">
                <span class="badge bg-<?= $status_color ?> fs-6"><?= ucfirst($return['status']) ?></span>
                <span class="text-muted ms-2">Created on <?= date('d M, Y', strtotime($return['return_date'])) ?></span>
            </div>
        </div>
        <div>
            <?php if ($return['status'] == 'pending' && $can_review_sr): ?>
                <button onclick="sendForReview(<?= $return['return_id'] ?>)" class="btn btn-warning me-2">
                    <i class="bi bi-send-check"></i> Send for Review
                </button>
            <?php endif; ?>
            <?php if ($return['status'] == 'reviewed' && $can_approve_sr): ?>
                <button onclick="approveReturn(<?= $return['return_id'] ?>)" class="btn btn-success me-2">
                    <i class="bi bi-check-circle"></i> Approve
                </button>
            <?php endif; ?>
            <?php if ($return['status'] == 'approved'): ?>
                <?php if ($existing_cn): ?>
                    <a href="<?= getUrl('credit_note_view') ?>?id=<?= (int)$existing_cn['credit_note_id'] ?>" class="btn btn-primary me-2">
                        <i class="bi bi-receipt"></i> View Credit Note <?= safe_output($existing_cn['credit_note_number']) ?>
                    </a>
                <?php else: ?>
                    <?php if ($can_create_cn): ?>
                    <a href="<?= getUrl('credit_note_create') ?>?sales_return_id=<?= $return['return_id'] ?>" class="btn btn-primary me-2">
                        <i class="bi bi-receipt"></i> Create Credit Note
                    </a>
                    <?php endif; ?>
                    <button onclick="markRefunded(<?= $return['return_id'] ?>)" class="btn btn-outline-primary me-2">
                        <i class="bi bi-cash-coin"></i> Mark Refunded
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            
            <button onclick="printReturn(<?= $return['return_id'] ?>)" class="btn btn-secondary me-2">
                <i class="bi bi-printer"></i> Print
            </button>
            
            <a href="<?= getUrl('sales_returns') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="row">
        <!-- Main Details -->
        <div class="col-lg-8">
            <!-- Items Table -->
            <div class="card shadow mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Returned Items</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Item Details</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end pe-4">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?= safe_output($item['product_name']) ?></div>
                                        <div class="small text-muted">SKU: <?= safe_output($item['sku']) ?></div>
                                    </td>
                                    <td class="text-center"><?= format_number($item['quantity'], 2) ?></td>
                                    <td class="text-end"><?= number_format($item['unit_price'], 2) ?></td>
                                    <td class="text-end pe-4"><?= number_format($item['total_amount'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light">
                                <tr>
                                    <td colspan="3" class="text-end text-muted pt-3">Subtotal:</td>
                                    <td class="text-end pe-4 pt-3 font-monospace">
                                        <?= number_format($return['subtotal_amount'], 2) ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="3" class="text-end text-muted">VAT (18%):</td>
                                    <td class="text-end pe-4 font-monospace">
                                        <?= number_format($return['total_tax'], 2) ?>
                                    </td>
                                </tr>
                                <tr class="border-top border-2">
                                    <td colspan="3" class="text-end fw-bold">Total Refund Amount:</td>
                                    <td class="text-end fw-bold pe-4 text-danger">
                                        <?= number_format($return['grand_total'], 2) ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Reason / Notes -->
            <?php if ($return['reason']): ?>
            <div class="card shadow mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-secondary">Return Reason</h6>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted"><?= nl2br(safe_output($return['reason'])) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Customer Info -->
            <div class="card shadow mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-info">Customer Details</h6>
                </div>
                <div class="card-body">
                    <h5 class="h6 fw-bold mb-1"><?= safe_output($return['customer_name']) ?></h5>
                    <?php if ($return['company_name']): ?>
                        <p class="text-muted small mb-2"><?= safe_output($return['company_name']) ?></p>
                    <?php endif; ?>
                    
                    <hr class="my-3">
                    
                    <?php if ($return['customer_email']): ?>
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-envelope me-2 text-muted"></i>
                            <span><?= safe_output($return['customer_email']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($return['customer_phone']): ?>
                        <div class="d-flex align-items-center">
                            <i class="bi bi-phone me-2 text-muted"></i>
                            <span><?= safe_output($return['customer_phone']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Order Info -->
            <div class="card shadow mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-secondary">Order Reference</h6>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Original Order:</span>
                        <a href="<?= getUrl('sales_order_view') ?>?id=<?= $return['sales_order_id'] ?>" class="text-decoration-none fw-bold">
                            #<?= safe_output($return['order_number']) ?>
                        </a>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>Processed By:</span>
                        <span class="text-muted"><?= safe_output($return['created_by_name']) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// markRefunded is the ONLY direct status-change action on this page.
// Status is hardcoded to 'refunded' here so no caller can use this to jump
// arbitrarily. All other transitions (pending->reviewed->approved) go
// through the canonical Send-for-Review / Approve buttons.
function markRefunded(id) {
    Swal.fire({
        title: 'Mark as Refunded?',
        text: 'This will mark the approved return as refunded.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Mark Refunded',
        confirmButtonColor: '#0d6efd'
    }).then((result) => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });

        $.ajax({
            url: '<?= buildUrl('api/sales/update_return_status.php') ?>',
            type: 'POST',
            data: { return_id: id, status: 'refunded' },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            },
            error: function(xhr) {
                var msg = 'Server error.';
                try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch (e) {}
                Swal.fire('Error', msg, 'error');
            }
        });
    });
}

function printReturn(id) {
    window.open('print_sales_return?id=' + id, '_blank');
}

// ── Three-approval workflow action handlers (returns three-approval slice) ──
function sendForReview(id) {
    Swal.fire({
        title: 'Send for Review?',
        text: 'This will mark the return as reviewed and capture your e-signature.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, send for review',
        confirmButtonColor: '#ffc107'
    }).then((result) => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });
        $.post('<?= buildUrl("api/sales/review_return.php") ?>',
            { return_id: id },
            function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json'
        ).fail(function(xhr) {
            var msg = 'Server error.';
            try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch (e) {}
            Swal.fire('Error', msg, 'error');
        });
    });
}

function approveReturn(id) {
    Swal.fire({
        title: 'Approve Sales Return?',
        text: 'This will capture your e-signature as the approver.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, approve',
        confirmButtonColor: '#198754'
    }).then((result) => {
        if (!result.isConfirmed) return;
        Swal.fire({ title: 'Processing...', didOpen: () => Swal.showLoading() });
        $.post('<?= buildUrl("api/sales/approve_return.php") ?>',
            { return_id: id },
            function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    Swal.fire('Error', response.message, 'error');
                }
            }, 'json'
        ).fail(function(xhr) {
            var msg = 'Server error.';
            try { var r = JSON.parse(xhr.responseText); if (r && r.message) msg = r.message; } catch (e) {}
            Swal.fire('Error', msg, 'error');
        });
    });
}
</script>

<?php includeFooter(); ?>
