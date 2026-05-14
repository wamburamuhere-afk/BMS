<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
autoEnforcePermission();

includeHeader();

// Get Expense ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirectTo('expenses');
}

$expense_id = $_GET['id'];

// Fetch Expense Details
$stmt = $pdo->prepare("
    SELECT 
        e.*, 
        ea.account_name as expense_account_name, 
        ba.account_name as bank_account_name,
        p.project_name,
        u.username as created_by_name, 
        u2.username as updated_by_name,
        u3.username as reviewed_by_name,
        u4.username as approved_by_name,
        CASE 
            WHEN e.paid_to_type = 'supplier' THEN (SELECT supplier_name FROM suppliers WHERE supplier_id = e.paid_to_id)
            WHEN e.paid_to_type = 'staff' THEN (SELECT CONCAT(first_name, ' ', last_name) FROM employees WHERE employee_id = e.paid_to_id)
            WHEN e.paid_to_type = 'sub_contractor' THEN (SELECT supplier_name FROM sub_contractors WHERE supplier_id = e.paid_to_id)
            ELSE e.vendor 
        END as paid_to_name
    FROM expenses e 
    LEFT JOIN accounts ea ON e.expense_account_id = ea.account_id 
    LEFT JOIN accounts ba ON e.bank_account_id = ba.account_id 
    LEFT JOIN projects p ON e.project_id = p.project_id
    LEFT JOIN users u ON e.created_by = u.user_id 
    LEFT JOIN users u2 ON e.updated_by = u2.user_id
    LEFT JOIN users u3 ON e.reviewed_by = u3.user_id
    LEFT JOIN users u4 ON e.approved_by = u4.user_id
    WHERE e.expense_id = ?
");
$stmt->execute([$expense_id]);
$expense = $stmt->fetch();

if (!$expense) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Expense not found. <a href='" . getUrl('expenses') . "'>Return to list</a></div></div>";
    includeFooter();
    exit;
}

// Fetch Multiple Categories
$cat_stmt = $pdo->prepare("
    SELECT ec.name as category_name, ec.id as category_id 
    FROM expense_category_map ecm
    JOIN expense_categories ec ON ecm.category_id = ec.id
    WHERE ecm.expense_id = ?
");
$cat_stmt->execute([$expense_id]);
$expense['categories'] = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Status Badge Helper
function get_expense_status_badge($status) {
    return match($status) {
        'paid' => 'success',
        'approved' => 'primary',
        'reviewed' => 'info',
        'pending' => 'warning',
        'rejected' => 'danger',
        default => 'secondary'
    };
}

$statusClass = get_expense_status_badge($expense['status']);

// Build absolute logo URL for JS voucher
$_proto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$_host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_c_logo     = getSetting('company_logo', '');
$_c_name     = getSetting('company_name', 'BMS');
$_logo_url   = !empty($_c_logo) ? $_proto . '://' . $_host . '/' . ltrim($_c_logo, '/') : '';
$_pv_logo    = !empty($_logo_url)
    ? '<img src="' . htmlspecialchars($_logo_url) . '" alt="' . htmlspecialchars($_c_name) . '" style="max-height:70px;width:auto;display:block;margin-bottom:4px;">'
    : '';
$_pv_logo_js = addslashes($_pv_logo);

global $company_name, $company_logo;
?>

<div class="container-fluid mt-4">

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= getUrl('expenses') ?>">Expenses</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Expense Details</li>
                </ol>
            </nav>
            <h2 class="fw-bold text-dark">Expense Voucher #<?php echo str_pad($expense['expense_id'], 5, '0', STR_PAD_LEFT); ?></h2>
        </div>
        <div class="col-auto d-flex gap-2">
            <?php 
            $enable_projects = 0;
            try {
                $stmt_s = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'enable_projects'");
                $stmt_s->execute();
                $enable_projects = $stmt_s->fetchColumn() ?: 0;
            } catch (Exception $e) {}

            if ($enable_projects && !empty($expense['project_id'])): ?>
                <a href="<?= getUrl('project_view') ?>?id=<?= $expense['project_id'] ?>" class="btn btn-outline-primary">
                    <i class="bi bi-kanban"></i> Back to Project
                </a>
            <?php endif; ?>
            <a href="<?= getUrl('expenses') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            <button onclick="printVoucher()" class="btn btn-light border shadow-sm">
                <i class="bi bi-printer text-primary me-1"></i> Print Voucher
            </button>
        </div>
    </div>

    <div class="row">
            <!-- Main Info Card -->
            <div class="card shadow-sm mb-4 border-0 overflow-hidden">
                <div class="card-header bg-primary text-white py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-info-circle me-2"></i>Voucher Details</h5>
                        <span class="badge bg-white text-<?php echo $statusClass; ?> px-3 py-2 fw-bold text-uppercase">
                            <i class="bi bi-dot me-1"></i> <?php echo $expense['status']; ?>
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <!-- Description Header -->
                    <div class="p-4 bg-light-subtle border-bottom">
                        <label class="text-muted small text-uppercase fw-bold mb-1">Description / Subject</label>
                        <h4 class="fw-bold text-dark mb-0"><?php echo htmlspecialchars($expense['description']); ?></h4>
                    </div>
                    
                    <div class="row g-0">
                        <!-- Left Side: Transaction Data -->
                        <div class="col-md-6 border-end p-4">
                            <h6 class="fw-bold text-primary text-uppercase mb-3 small letter-spacing-1"><i class="bi bi-cash-stack me-2"></i>SUBJECT INFO</h6>
                            
                            <div class="mb-3">
                                <label class="text-muted small d-block mb-1">Expense Date</label>
                                <span class="fw-semibold text-dark"><?php echo date('D, M d, Y', strtotime($expense['expense_date'])); ?></span>
                            </div>

                            <?php if (!empty($expense['expense_type'])): ?>
                            <div class="mb-3">
                                <label class="text-muted small d-block mb-1">Expense Type</label>
                                <span class="badge bg-secondary-soft text-secondary border border-secondary text-uppercase" style="font-size: 0.7rem;"><?php echo htmlspecialchars($expense['expense_type']); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($expense['categories'])): ?>
                            <div class="mb-3">
                                <label class="text-muted small d-block mb-1">Expense Categories</label>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($expense['categories'] as $cat): ?>
                                        <span class="badge bg-info-soft text-info border border-info" style="font-size: 0.7rem;">
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($enable_projects && !empty($expense['project_name'])): ?>
                            <div class="mb-3">
                                <label class="text-muted small d-block mb-1">Linked Project</label>
                                <span class="badge bg-primary-soft text-primary border border-primary"><?php echo htmlspecialchars($expense['project_name']); ?></span>
                            </div>
                            <?php endif; ?>

                            <div class="mb-0">
                                <label class="text-muted small d-block mb-1">Total Amount</label>
                                <h3 class="text-primary fw-bold mb-0"><?php echo number_format($expense['amount'], 2); ?> <small class="fs-6 font-monospace">TSh</small></h3>
                            </div>
                        </div>

                        <!-- Right Side: Payment Data -->
                        <div class="col-md-6 p-4 bg-white">
                            <h6 class="fw-bold text-info text-uppercase mb-3 small letter-spacing-1"><i class="bi bi-credit-card me-2"></i>PAYMENT INFO</h6>

                            <div class="mb-3">
                                <label class="text-muted small d-block mb-1">Vendor / Payee</label>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold text-dark"><?php echo htmlspecialchars($expense['paid_to_name'] ?? $expense['vendor'] ?? 'N/A'); ?></span>
                                    <?php if (!empty($expense['paid_to_type'])): ?>
                                        <span class="badge bg-info-soft text-info border border-info small" style="font-size: 0.65rem;"><?php echo strtoupper(str_replace('_', ' ', $expense['paid_to_type'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>



                            <?php if ($expense['status'] === 'paid' && !empty($expense['bank_account_name'])): ?>
                            <div class="mb-3">
                                <label class="text-muted small d-block mb-1">Paid From</label>
                                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($expense['bank_account_name']); ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($expense['reference_number'])): ?>
                            <div class="mb-0">
                                <label class="text-muted small d-block mb-1">Reference No.</label>
                                <span class="badge bg-light text-dark border px-2 py-1 fw-medium"><?php echo htmlspecialchars($expense['reference_number']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>




            <?php if (!empty($expense['notes'])): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark">Notes & Remarks</h5>
                </div>
                <div class="card-body">
                    <div class="p-3 bg-light rounded border-start border-4 border-primary">
                        <p class="mb-0 text-dark italic"><?php echo nl2br(htmlspecialchars($expense['notes'])); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Audit Trail -->
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3 d-flex align-items-center">
                    <h6 class="mb-0 fw-bold d-flex align-items-center">
                        <i class="bi bi-clock-history me-2 text-primary"></i>
                        Action History
                    </h6>
                </div>
                <div class="card-body">
                    <div class="timeline-simple">
                        <div class="d-flex justify-content-between mb-3 border-start border-3 border-success ps-3">
                            <div>
                                <div class="fw-bold text-dark">Submission</div>
                                <div class="small text-muted">Prepared by <?php echo htmlspecialchars($expense['created_by_name'] ?? 'System'); ?></div>
                            </div>
                            <div class="text-end small text-muted font-monospace">
                                <?php echo date('M d, Y', strtotime($expense['created_at'])); ?><br>
                                <?php echo date('H:i', strtotime($expense['created_at'])); ?>
                            </div>
                        </div>
                        <?php if ($expense['updated_at'] && $expense['updated_at'] != $expense['created_at']): ?>
                        <div class="d-flex justify-content-between border-start border-3 border-primary ps-3">
                            <div>
                                <div class="fw-bold text-dark">Last Modification</div>
                                <div class="small text-muted">Updated by <?php echo htmlspecialchars($expense['updated_by_name'] ?? 'System'); ?></div>
                            </div>
                            <div class="text-end small text-muted font-monospace">
                                <?php echo date('M d, Y', strtotime($expense['updated_at'])); ?><br>
                                <?php echo date('H:i', strtotime($expense['updated_at'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- System Message (Print ONLY) -->
            <div class="d-none d-print-block mt-4 text-center small text-muted border-top pt-3">
                System Generated Voucher | Printed on: <?php echo date('Y-m-d H:i:s'); ?>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Quick Actions -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-dark">Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <?php if ($expense['status'] === 'pending' && canEdit('expenses')): ?>
                            <button onclick="updateStatus('reviewed')" class="btn btn-info text-white text-start">
                                <i class="bi bi-search me-2"></i> Mark as Reviewed
                            </button>
                        <?php elseif ($expense['status'] === 'reviewed' && canEdit('expenses')): ?>
                            <button onclick="updateStatus('approved')" class="btn btn-primary text-start">
                                <i class="bi bi-check-circle me-2"></i> Approve Expense
                            </button>
                            <button onclick="updateStatus('rejected')" class="btn btn-outline-danger text-start">
                                <i class="bi bi-x-circle me-2"></i> Reject Expense
                            </button>
                        <?php elseif ($expense['status'] === 'approved' && canEdit('expenses')): ?>
                            <button onclick="updateStatus('paid')" class="btn btn-success text-start">
                                <i class="bi bi-cash-coin me-2"></i> Mark as Paid
                            </button>
                        <?php endif; ?>

                        <?php if (canEdit('expenses') && ($expense['status'] === 'pending' || $expense['status'] === 'reviewed')): ?>
                        <a href="#" onclick="editExpense(<?php echo $expense_id; ?>)" class="btn btn-light text-start border">
                            <i class="bi bi-pencil-square me-2 text-primary"></i> Edit Details
                        </a>
                        <?php endif; ?>
                        
                        <?php if (canDelete('expenses')): ?>
                        <button onclick="deleteExpense()" class="btn btn-light text-start border text-danger">
                            <i class="bi bi-trash me-2"></i> Delete Expense
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Financial Impact Summary -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-down-arrow me-2"></i>Financial Impact</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted small text-uppercase d-block mb-1">Account Category</label>
                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($expense['expense_account_name'] ?? ''); ?></span>
                        <div class="small text-muted mt-1">This will be debited as an expense.</div>
                    </div>
                    <div class="mb-0">
                        <label class="text-muted small text-uppercase d-block mb-1">Payment Source</label>
                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($expense['bank_account_name'] ?? ''); ?></span>
                        <div class="small text-muted mt-1">Funds will be credited from your <?php echo strtolower(htmlspecialchars($expense['bank_account_name'] ?? '')); ?> account.</div>
                    </div>
                </div>
            </div>

            <!-- System Info -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-dark">System Metadata</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Internal ID</span>
                            <span class="font-monospace fw-bold">#EXP-<?php echo $expense['expense_id']; ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Database Record</span>
                            <span class="text-dark">Row #<?php echo $expense['expense_id']; ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Fetch accounts for the modal
$expense_accounts = $pdo->query("SELECT account_id, account_name FROM accounts WHERE status = 'active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%expense%') ORDER BY account_name ASC")->fetchAll(PDO::FETCH_ASSOC);
// Fetch bank/cash accounts for the quick expense modal
$bank_accounts = $pdo->query("SELECT account_id, account_name FROM accounts WHERE status = 'active' AND account_type_id IN (SELECT type_id FROM account_types WHERE type_name LIKE '%Asset%' OR type_name LIKE '%Bank%' OR type_name LIKE '%Cash%') ORDER BY account_name ASC")->fetchAll(PDO::FETCH_ASSOC);

global $company_name, $company_logo;
?>

<!-- Edit Expense Modal -->
<div class="modal fade" id="editExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-pencil-square"></i> Edit Expense Voucher
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editExpenseForm">
                <input type="hidden" name="expense_id" value="<?php echo $expense_id; ?>">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Expense Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="expense_date" value="<?php echo $expense['expense_date']; ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Expense Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="expense_type" required>
                                <option value="operating" <?php echo $expense['expense_type'] == 'operating' ? 'selected' : ''; ?>>Operating</option>
                                <option value="fixed" <?php echo $expense['expense_type'] == 'fixed' ? 'selected' : ''; ?>>Fixed</option>
                                <option value="administrative" <?php echo $expense['expense_type'] == 'administrative' ? 'selected' : ''; ?>>Administrative</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" step="0.01" min="0" value="<?php echo $expense['amount']; ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Description <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="description" value="<?php echo htmlspecialchars($expense['description']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"><?php echo htmlspecialchars($expense['notes'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Status</label>
                            <select class="form-select" name="status">
                                <option value="pending" <?php echo $expense['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="reviewed" <?php echo $expense['status'] == 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                                <option value="approved" <?php echo $expense['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="paid" <?php echo $expense['status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="rejected" <?php echo $expense['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="bi bi-check-circle"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Select2 Assets -->
<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="/assets/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Expense Details', 'User viewed expense voucher #<?= str_pad($expense['expense_id'], 5, '0', STR_PAD_LEFT) ?> (<?= addslashes($expense['description']) ?>)');

    window.printVoucher = function() {
        logReportAction('Printed Expense Voucher', 'User generated a printed report for expense voucher #<?= str_pad($expense['expense_id'], 5, '0', STR_PAD_LEFT) ?>');

        // ── Amount in words helper ──────────────────────────────────────
        function numToWords(n) {
            const a = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                       'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                       'Seventeen','Eighteen','Nineteen'];
            const b = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
            n = Math.floor(parseFloat(n) || 0);
            if (n === 0) return 'Zero';
            if (n < 20)  return a[n];
            if (n < 100) return b[Math.floor(n/10)] + (n%10 ? ' ' + a[n%10] : '');
            if (n < 1000) return a[Math.floor(n/100)] + ' Hundred' + (n%100 ? ' ' + numToWords(n%100) : '');
            if (n < 1000000) return numToWords(Math.floor(n/1000)) + ' Thousand' + (n%1000 ? ' ' + numToWords(n%1000) : '');
            return numToWords(Math.floor(n/1000000)) + ' Million' + (n%1000000 ? ' ' + numToWords(n%1000000) : '');
        }

        const amount    = <?= (float)$expense['amount'] ?>;
        const amtWords  = numToWords(amount) + ' Only';
        const fmtAmt    = amount.toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
        const voucherNo = 'PV-<?= str_pad($expense['expense_id'], 5, '0', STR_PAD_LEFT) ?>';
        const date      = '<?= date('d F Y', strtotime($expense['expense_date'])) ?>';
        const paidTo    = '<?= addslashes(htmlspecialchars($expense['paid_to_name'] ?? $expense['vendor'] ?? '-')) ?>';
        const paidType  = '<?= addslashes(htmlspecialchars($expense['paid_to_type'] ?? '')) ?>';
        const desc      = '<?= addslashes(htmlspecialchars($expense['description'] ?? '-')) ?>';
        const expType   = '<?= addslashes(htmlspecialchars($expense['expense_type'] ?? '-')) ?>';
        const project   = '<?= addslashes(htmlspecialchars($expense['project_name'] ?? '')) ?>';
        const categories = <?= json_encode($expense['categories'] ?? []) ?>;
        const items     = <?= !empty($expense['expense_items']) ? $expense['expense_items'] : '[]' ?>;
        const expAcct   = '<?= addslashes(htmlspecialchars($expense['expense_account_name'] ?? '-')) ?>';
        const bankAcct  = '<?= addslashes(htmlspecialchars($expense['bank_account_name'] ?? '')) ?>';
        const refNo     = '<?= addslashes(htmlspecialchars($expense['reference_number'] ?? '')) ?>';
        const notes     = '<?= addslashes(htmlspecialchars($expense['notes'] ?? '')) ?>';
        const status    = '<?= addslashes($expense['status']) ?>';
        const preparedBy= '<?= addslashes(htmlspecialchars($expense['created_by_name'] ?? '-')) ?>';
        const reviewedBy= '<?= addslashes(htmlspecialchars($expense['reviewed_by_name'] ?? '')) ?>';
        const approvedBy= '<?= addslashes(htmlspecialchars($expense['approved_by_name'] ?? '')) ?>';
        const logoHtml  = '<?= $_pv_logo_js ?>';
        const cName     = '<?= addslashes(htmlspecialchars($_c_name)) ?>';
        const printedBy = '<?= addslashes(htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) ?>';
        const printedRole = '<?= addslashes(htmlspecialchars($_SESSION['user_role'] ?? 'User')) ?>';
        const now       = new Date();
        const printDate = now.getDate().toString().padStart(2,'0') + ' ' +
            ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][now.getMonth()] +
            ' ' + now.getFullYear() + ' at ' +
            now.getHours().toString().padStart(2,'0') + ':' +
            now.getMinutes().toString().padStart(2,'0') + ':' +
            now.getSeconds().toString().padStart(2,'0');
        const statusMap = { pending:'#856404,#fff3cd,#ffc107', approved:'#0f5132,#d1e7dd,#198754', paid:'#084298,#cfe2ff,#0d6efd', rejected:'#842029,#f8d7da,#dc3545' };
        const [sColor, sBg, sBorder] = (statusMap[status] || '##333,#eee,#aaa').split(',');

        const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>Payment Voucher - ${voucherNo}</title>
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family: Arial, sans-serif; font-size:10pt; color:#222; background:#fff; padding:15mm 15mm 20mm 15mm; }
            .pv-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #0d6efd; padding-bottom:10px; margin-bottom:14px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .pv-logo-area { display:flex; flex-direction:column; gap:4px; }
            .pv-company { font-size:16pt; font-weight:800; color:#0d6efd; text-transform:uppercase; }
            .pv-title-area { text-align:right; }
            .pv-title { font-size:14pt; font-weight:800; text-transform:uppercase; color:#333; letter-spacing:2px; }
            .pv-voucher-no { font-size:9pt; color:#666; margin-top:4px; }
            .pv-date { font-size:9pt; color:#333; font-weight:600; margin-top:2px; }
            .pv-amount-box { background:#f0f7ff; border:2px solid #0d6efd; border-radius:6px; padding:10px 16px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .pv-amount-label { font-size:8pt; text-transform:uppercase; color:#555; }
            .pv-amount-value { font-size:20pt; font-weight:900; color:#0d6efd; }
            .pv-amount-words { font-size:8.5pt; color:#333; font-style:italic; text-align:right; }
            .pv-table { width:100%; border-collapse:collapse; margin-bottom:14px; }
            .pv-table tr { border-bottom:1px solid #eee; }
            .pv-table td { padding:6px 8px; vertical-align:top; font-size:9.5pt; }
            .pv-table td:first-child { width:35%; font-weight:700; color:#555; text-transform:uppercase; font-size:8.5pt; }
            .pv-status { display:inline-block; padding:2px 10px; border-radius:20px; font-size:8pt; font-weight:700; text-transform:uppercase; color:${sColor}; background:${sBg}; border:1px solid ${sBorder}; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .pv-signatures { display:flex; justify-content:space-between; margin-top:24px; gap:20px; }
            .pv-sig-block { flex:1; text-align:center; }
            .pv-sig-line { border-top:1px solid #333; margin-bottom:4px; margin-top:30px; }
            .pv-sig-label { font-size:8pt; text-transform:uppercase; color:#555; font-weight:700; }
            .pv-sig-name { font-size:8pt; color:#333; margin-top:2px; }
            .pv-note { background:#fffbee; border-left:3px solid #ffc107; padding:6px 10px; font-size:8.5pt; color:#555; margin-bottom:14px; border-radius:0 4px 4px 0; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .pv-footer { position:fixed; bottom:0; left:0; right:0; padding:3mm 15mm; border-top:1px solid #ccc; background:#fff; text-align:center; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            .pv-footer p { font-size:10pt; margin:0; line-height:1.4; }
            .pv-powered { color:#0d6efd; font-weight:700; }
            .pv-spacer { height:15mm; }
            @media print { body { padding:10mm 12mm 18mm 12mm; } .pv-footer { position:fixed; bottom:0; } }
        </style></head><body>
        <div class="pv-header">
            <div class="pv-logo-area">${logoHtml}<span class="pv-company">${cName}</span></div>
            <div class="pv-title-area">
                <div class="pv-title">Payment Voucher</div>
                <div class="pv-voucher-no">Voucher No: <strong>${voucherNo}</strong></div>
                <div class="pv-date">Date: <strong>${date}</strong></div>
            </div>
        </div>
        <div class="pv-amount-box">
            <div><div class="pv-amount-label">Amount Paid</div><div class="pv-amount-value">${fmtAmt}</div></div>
            <div class="pv-amount-words"><div style="font-size:7.5pt;color:#888;margin-bottom:2px;">In Words:</div><strong>${amtWords}</strong></div>
        </div>
        <table class="pv-table">
            <tr><td>Paid To</td><td><strong>${paidTo}</strong>${paidType ? ' <span style="font-size:8pt;color:#888;">('+paidType.toUpperCase()+')</span>' : ''}</td></tr>
            <tr><td>Description</td><td>${desc}</td></tr>
            <tr><td>Expense Account</td><td>${expAcct}</td></tr>
            ${expType && expType !== '-' ? `<tr><td>Expense Type</td><td><span style="text-transform:capitalize;">${expType}</span></td></tr>` : ''}
            ${categories && categories.length > 0 ? `<tr><td>Categories</td><td>${categories.map(c => c.category_name).join(', ')}</td></tr>` : ''}
            ${project ? `<tr><td>Linked Project</td><td><strong>${project}</strong></td></tr>` : ''}
            ${bankAcct ? `<tr><td>Paid From (Bank)</td><td>${bankAcct}</td></tr>` : ''}
            ${refNo ? `<tr><td>Reference No.</td><td>${refNo}</td></tr>` : ''}
            ${notes ? '<tr><td>Notes</td><td>'+notes+'</td></tr>' : ''}
            <tr><td>Status</td><td><span class="pv-status">${status.charAt(0).toUpperCase()+status.slice(1)}</span></td></tr>
            <tr><td>Prepared By</td><td>${preparedBy}</td></tr>
        </table>

        <!-- ITEMS BREAKDOWN TABLE -->
        ${items && items.length > 0 ? `
        <div style="margin-top:14px; margin-bottom:14px;">
            <div style="font-size:9pt; font-weight:bold; text-transform:uppercase; color:#333; margin-bottom:6px; border-bottom:1px solid #ddd; padding-bottom:4px;">Expense Breakdown</div>
            <table style="width:100%; border-collapse:collapse; font-size:9pt;">
                <thead>
                    <tr style="background:#f8f9fa; border-bottom:2px solid #ddd;">
                        <th style="padding:6px; text-align:left; width:40px;">S/N</th>
                        <th style="padding:6px; text-align:left;">Description</th>
                        <th style="padding:6px; text-align:center; width:60px;">Qty</th>
                        <th style="padding:6px; text-align:right; width:90px;">Price</th>
                        <th style="padding:6px; text-align:center; width:60px;">Tax %</th>
                        <th style="padding:6px; text-align:right; width:100px;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map((item, i) => {
                        const q = parseFloat(item.qty)||1;
                        const p = parseFloat(item.price)||0;
                        const t = parseFloat(item.tax_pct)||0;
                        const tot = q * p * (1 + t/100);
                        return `
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:6px;">${i+1}</td>
                            <td style="padding:6px;">${item.description||'-'}</td>
                            <td style="padding:6px; text-align:center;">${q.toFixed(2)}</td>
                            <td style="padding:6px; text-align:right;">${p.toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                            <td style="padding:6px; text-align:center;">${t}%</td>
                            <td style="padding:6px; text-align:right; font-weight:bold;">${tot.toLocaleString(undefined, {minimumFractionDigits:2})}</td>
                        </tr>`;
                    }).join('')}
                </tbody>
                <tfoot>
                    <tr style="background:#f8f9fa; font-weight:bold; border-top:2px solid #ddd;">
                        <td colspan="5" style="padding:8px; text-align:right;">Grand Total:</td>
                        <td style="padding:8px; text-align:right; color:#0d6efd; font-size:11pt;">${fmtAmt}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        ` : ''}
        <div class="pv-note"><strong>Note:</strong> This is a computer-generated payment voucher. Please verify all details before processing payment.</div>
        <div class="pv-signatures">
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">Created By</div>
                <div class="pv-sig-name">${preparedBy}</div>
            </div>
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">Reviewed By</div>
                <div class="pv-sig-name">${reviewedBy || '&nbsp;'}</div>
            </div>
            <div class="pv-sig-block">
                <div class="pv-sig-line"></div>
                <div class="pv-sig-label">Approved By</div>
                <div class="pv-sig-name">${approvedBy || '&nbsp;'}</div>
            </div>
        </div>
        <div class="pv-spacer"></div>
        <div class="pv-footer">
            <p>This document was <strong>Printed</strong> by <strong>${printedBy} - ${printedRole}</strong> on ${printDate}</p>
            <p class="pv-powered">Powered by BJP Technologies &copy; ${now.getFullYear()}, All Rights Reserved.</p>
        </div>
        <script>window.onload = function() { window.print(); };<\/script>
        </body></html>`;

        const win = window.open('', '_blank', 'width=850,height=650');
        win.document.write(html);
        win.document.close();
    };
});

function updateStatus(newStatus) {
    Swal.fire({
        title: 'Are you sure?',
        text: 'Do you want to change the status to ' + newStatus + '?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, change it!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('expense_id', '<?php echo $expense_id; ?>');
            formData.append('status', newStatus);

            fetch('/api/update_expense_status.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    logReportAction('Updated Expense Status', 'User updated status of expense voucher #<?= $expense_id ?> to ' + newStatus);
                    Swal.fire({
                        title: 'Success!',
                        text: 'Status has been updated to ' + newStatus + '.',
                        icon: 'success'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to update status', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred while updating status.', 'error');
            });
        }
    });
}

function deleteExpense() {
    Swal.fire({
        title: 'Are you sure?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('expense_id', '<?php echo $expense_id; ?>');

            fetch('/api/delete_expense.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    logReportAction('Deleted Expense Voucher', 'User deleted expense voucher #<?= $expense_id ?> (<?= addslashes($expense['description']) ?>)');
                    Swal.fire({
                        title: 'Deleted!',
                        text: 'Expense has been deleted.',
                        icon: 'success'
                    }).then(() => {
                        window.location.href = '<?= getUrl('expenses') ?>';
                    });
                } else {
                    Swal.fire('Error', data.message || 'Failed to delete expense', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error', 'An error occurred while deleting the expense.', 'error');
            });
        }
    });
}

function editExpense(expenseId) {
    logReportAction('Initiated Expense Edit', 'User clicked edit for expense voucher #<?= $expense_id ?>');
    $('#editExpenseModal').modal('show');
    // Initialize Select2 if not already done
    $('.select2-modal').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#editExpenseModal'),
        width: '100%'
    });
}

// Handle Form Submission
$('#editExpenseForm').on('submit', function(e) {
    e.preventDefault();
    const $form = $(this);
    const $btn = $form.find('button[type="submit"]');
    
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Updating...');

    $.ajax({
        url: '/api/update_expense.php',
        type: 'POST',
        data: $form.serialize(),
        success: response => {
            if (response.success) {
                Swal.fire({
                    title: 'Updated!',
                    text: response.message || 'Expense updated successfully.',
                    icon: 'success'
                }).then(() => {
                    location.reload();
                });
            } else {
                Swal.fire('Error', response.message || 'Update failed', 'error');
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Changes');
            }
        },
        error: () => {
            Swal.fire('Error', 'Server error occurred', 'error');
            $btn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Changes');
        }
    });
});
</script>

<style>
    .card { border-radius: 12px; border: 1px solid rgba(0,0,0,0.05); }
    .card-header:first-child { border-radius: 12px 12px 0 0; }
    .btn { border-radius: 8px; font-weight: 600; transition: all 0.2s; }
    .btn:hover { transform: translateY(-1px); }
    .table thead th { font-size: 0.7rem; letter-spacing: 0.5px; text-transform: uppercase; }
    .italic { font-style: italic; }
    .letter-spacing-1 { letter-spacing: 1px; }
    .bg-primary-soft { background-color: rgba(13, 110, 253, 0.08); }
    .bg-light-subtle { background-color: #fcfcfd; }
    
    @media print {
        @page { margin: 1cm; }
        body { background: white !important; font-size: 11pt; -webkit-print-color-adjust: exact; color: #000 !important; }
        .container, .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
        .btn, .sidebar, nav, .d-print-none, .breadcrumb, header, footer, .modal { display: none !important; }
        
        /* Ensure all text is dark and visible */
        .text-muted, .small, label, span, div, p, h1, h2, h3, h4, h5, h6 { 
            color: #000 !important; 
            opacity: 1 !important;
        }

        .card {
            border: 1px solid #000 !important;
            box-shadow: none !important;
            margin-bottom: 30px !important;
            border-radius: 0 !important;
        }
        .card-header {
            background-color: #f0f0f0 !important;
            border-bottom: 2px solid #000 !important;
            color: #000 !important;
            padding: 10px !important;
        }
        .card-header .badge { border: 1px solid #000 !important; color: #000 !important; background: transparent !important; }
        .col-lg-8 { width: 100% !important; flex: 0 0 100%; max-width: 100%; }
        .col-md-6 { width: 50% !important; float: left; }
        .text-primary, .text-info { color: #000 !important; font-weight: bold !important; }
        .bg-primary { background-color: #f0f0f0 !important; color: #000 !important; }
        .border-end { border-right: 1px solid #000 !important; }
        .border-start { border-left: 4px solid #000 !important; }
        .row.g-0 { border-bottom: 1px solid #000; overflow: hidden; }
        .p-4 { padding: 1.5rem !important; }
        .font-monospace { font-weight: bold !important; }
    }
</style>

<?php includeFooter(); ?>
