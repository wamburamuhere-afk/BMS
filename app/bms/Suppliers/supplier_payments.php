<?php
// scope-audit: skip — supplier payments page; data scoped via purchase_orders on user's projects; detailed scope gating deferred to Phase G-2
ob_start();
$page_title = 'Supplier Payments';
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';
require_once HEADER_FILE;

$ps_cash_accounts = cashBankAccounts($pdo);   // Paid-From source list
// Active WHT rates for the withholding dropdown (subtractive taxes).
$ps_wht_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage
                               FROM tax_rates WHERE tax_kind = 'wht' AND status = 'active'
                           ORDER BY rate_percentage")->fetchAll(PDO::FETCH_ASSOC);

$can_view   = canView('supplier_payments');
$can_create = canCreate('supplier_payments');
$can_edit   = canEdit('supplier_payments');
$can_delete = canDelete('supplier_payments');

if (!$can_view) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

// Filters
$filter_supplier_id    = $_GET['id'] ?? '';
$filter_date_from      = $_GET['date_from'] ?? '';
$filter_date_to        = $_GET['date_to'] ?? '';
$filter_payment_method = $_GET['payment_method'] ?? '';

// Build query
$query = "
    SELECT sp.*,
           s.supplier_name, s.company_name,
           po.order_number,
           u.username as created_by_name
    FROM supplier_payments sp
    LEFT JOIN suppliers s ON sp.supplier_id = s.supplier_id
    LEFT JOIN purchase_orders po ON sp.purchase_order_id = po.purchase_order_id
    LEFT JOIN users u ON sp.created_by = u.user_id
    WHERE 1=1
";
$params = [];
if (!empty($filter_supplier_id))    { $query .= " AND sp.supplier_id = ?";      $params[] = $filter_supplier_id; }
if (!empty($filter_date_from))      { $query .= " AND sp.payment_date >= ?";    $params[] = $filter_date_from; }
if (!empty($filter_date_to))        { $query .= " AND sp.payment_date <= ?";    $params[] = $filter_date_to; }
if (!empty($filter_payment_method)) { $query .= " AND sp.payment_method = ?";   $params[] = $filter_payment_method; }
$query .= " ORDER BY sp.payment_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$total_count  = count($payments);
$total_amount = array_sum(array_column($payments, 'amount'));
$this_month   = date('Y-m');
$month_payments = array_filter($payments, fn($p) => strpos($p['payment_date'], $this_month) === 0);
$month_count  = count($month_payments);
$month_amount = array_sum(array_column($month_payments, 'amount'));

// Company info for payment slip
$company_name    = getSetting('company_name',    'Company Name');
$company_address = getSetting('company_address', '');
$company_phone   = getSetting('company_phone',   '');
$company_email   = getSetting('company_email',   '');
$company_logo    = getSetting('company_logo',    '');

// Suppliers for filter + modal
$suppliers = $pdo->query("SELECT supplier_id, supplier_name, default_wht_rate_id FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// Breadcrumb supplier name
$breadcrumb_supplier = '';
if (!empty($filter_supplier_id)) {
    $sn = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE supplier_id = ?");
    $sn->execute([$filter_supplier_id]);
    $breadcrumb_supplier = $sn->fetchColumn() ?: '';
}
?>

<div class="container-fluid mt-4">

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('suppliers') ?>">Suppliers</a></li>
            <?php if (!empty($breadcrumb_supplier)): ?>
            <li class="breadcrumb-item">
                <a href="<?= getUrl('suppliers/view') ?>?id=<?= (int)$filter_supplier_id ?>"><?= safe_output($breadcrumb_supplier) ?></a>
            </li>
            <?php endif; ?>
            <li class="breadcrumb-item active">Payments</li>
        </ol>
    </nav>

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Supplier Payments</h4>
        <div class="d-flex gap-2">
            <?php if ($can_create): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-circle me-1"></i> Add Payment
            </button>
            <?php endif; ?>
            <button class="btn btn-outline-success" onclick="exportPayments()">
                <i class="bi bi-download me-1"></i> Export
            </button>
        </div>
    </div>

    <!-- Statistics cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background-color:#d1e7dd;">
                <div class="fs-4 fw-bold text-primary"><?= $total_count ?></div>
                <div class="small text-muted">Total Payments</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background-color:#d1e7dd;">
                <div class="fs-4 fw-bold text-success"><?= format_currency($total_amount) ?></div>
                <div class="small text-muted">Total Amount</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background-color:#d1e7dd;">
                <div class="fs-4 fw-bold text-warning"><?= $month_count ?></div>
                <div class="small text-muted">This Month</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background-color:#d1e7dd;">
                <div class="fs-4 fw-bold text-info"><?= format_currency($month_amount) ?></div>
                <div class="small text-muted">Month Amount</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3 col-12">
                    <label class="form-label small mb-1">Supplier</label>
                    <select class="form-select select2-static" name="id" id="filter_supplier">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['supplier_id'] ?>" <?= $filter_supplier_id == $s['supplier_id'] ? 'selected' : '' ?>>
                            <?= safe_output($s['supplier_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label small mb-1">Method</label>
                    <select class="form-select select2-static" name="payment_method" id="filter_method">
                        <option value="">All Methods</option>
                        <option value="cash"          <?= $filter_payment_method === 'cash'          ? 'selected' : '' ?>>Cash</option>
                        <option value="bank_transfer" <?= $filter_payment_method === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        <option value="cheque"        <?= $filter_payment_method === 'cheque'        ? 'selected' : '' ?>>Cheque</option>
                        <option value="mobile_money"  <?= $filter_payment_method === 'mobile_money'  ? 'selected' : '' ?>>Mobile Money</option>
                        <option value="credit_card"   <?= $filter_payment_method === 'credit_card'   ? 'selected' : '' ?>>Credit Card</option>
                    </select>
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label small mb-1">Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= safe_output($filter_date_from) ?>">
                </div>
                <div class="col-md-2 col-6">
                    <label class="form-label small mb-1">Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= safe_output($filter_date_to) ?>">
                </div>
                <div class="col-md-3 col-6 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="bi bi-funnel me-1"></i> Filter
                    </button>
                    <a href="<?= getUrl('suppliers/payments') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Desktop table view -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="paymentsTable" class="table table-hover align-middle w-100 mb-0">
                        <thead style="background-color:#fff;border-bottom:2px solid #dee2e6;">
                            <tr>
                                <th style="color:#333;">S/No</th>
                                <th style="color:#333;">Date</th>
                                <th style="color:#333;">Supplier</th>
                                <th style="color:#333;">Reference</th>
                                <th style="color:#333;">Order #</th>
                                <th style="color:#333;">Amount</th>
                                <th style="color:#333;">Method</th>
                                <th style="color:#333;">Notes</th>
                                <th class="text-end d-print-none" style="color:#333;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $sno = 0; foreach ($payments as $payment): $sno++; ?>
                            <tr data-payment-id="<?= $payment['payment_id'] ?>">
                                <td><?= $sno ?></td>
                                <td><?= safe_output(format_date($payment['payment_date'])) ?></td>
                                <td>
                                    <div class="fw-bold"><?= safe_output($payment['supplier_name']) ?></div>
                                    <?php if (!empty($payment['company_name'])): ?>
                                    <small class="text-muted"><?= safe_output($payment['company_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= safe_output($payment['reference_number'] ?: '—') ?></code></td>
                                <td>
                                    <?php if (!empty($payment['order_number'])): ?>
                                    <a href="<?= getUrl('purchase_order_details') ?>?id=<?= $payment['purchase_order_id'] ?>" class="text-decoration-none">
                                        <?= safe_output($payment['order_number']) ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold text-success"><?= safe_output(format_currency($payment['amount'])) ?></span>
                                    <br><small class="text-muted"><?= safe_output($payment['currency']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-info text-dark">
                                        <?= safe_output(ucfirst(str_replace('_', ' ', $payment['payment_method']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php if (!empty($payment['notes'])): ?>
                                            <?= safe_output(mb_substr($payment['notes'], 0, 45)) ?><?= mb_strlen($payment['notes']) > 45 ? '…' : '' ?>
                                        <?php else: ?>—<?php endif; ?>
                                    </small>
                                </td>
                                <td class="text-end d-print-none">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear-fill me-1"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                            <li><a class="dropdown-item py-2 rounded" href="#" onclick="viewPayment(<?= $payment['payment_id'] ?>)"><i class="bi bi-eye text-info me-2"></i> View Details</a></li>
                                            <?php if ($can_edit): ?>
                                            <li><a class="dropdown-item py-2 rounded" href="#" onclick="editPayment(<?= $payment['payment_id'] ?>)"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item py-2 rounded text-danger" href="#" onclick="deletePayment(<?= $payment['payment_id'] ?>)"><i class="bi bi-trash me-2"></i> Delete</a></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile card view -->
    <div id="cardView" class="row g-2 d-none"></div>

</div><!-- /container-fluid -->

<?php if ($can_create): ?>
<!-- Add Payment Modal -->
<div class="modal fade" id="addModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i> Add Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="add-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="supplier_id" id="add_supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['supplier_id'] ?>" <?= $filter_supplier_id == $s['supplier_id'] ? 'selected' : '' ?>>
                                    <?= safe_output($s['supplier_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase Order <span class="text-muted small">(Optional)</span></label>
                            <select class="form-select select2-static" name="purchase_order_id" id="add_po_id">
                                <option value="">Select Order</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" id="add_payment_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="add_amount" step="0.01" min="0.01" required placeholder="0.00" oninput="recalcAddNet()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Withholding Tax (WHT)</label>
                            <select class="form-select" name="wht_rate_id" id="add_wht_rate" onchange="recalcAddNet()">
                                <option value="" data-rate="0">No withholding tax</option>
                                <?php foreach ($ps_wht_rates as $w): $pct = rtrim(rtrim(number_format((float)$w['rate_percentage'], 2), '0'), '.'); ?>
                                <option value="<?= (int)$w['rate_id'] ?>" data-rate="<?= htmlspecialchars($w['rate_percentage']) ?>"><?= safe_output($w['rate_name']) ?> (<?= $pct ?>%)</option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Withheld from the supplier and remitted to TRA.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Withheld (−)</label>
                            <input type="text" class="form-control" id="add_wht_amount" readonly value="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Net to Pay</label>
                            <input type="text" class="form-control fw-bold text-primary" id="add_net" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Currency</label>
                            <select class="form-select select2-static" name="currency">
                                <option value="TZS" selected>Tanzanian Shilling (TZS)</option>
                                <option value="USD">US Dollar (USD)</option>
                                <option value="EUR">Euro (EUR)</option>
                                <option value="GBP">British Pound (GBP)</option>
                                <option value="KES">Kenyan Shilling (KES)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="payment_method" required>
                                <option value="">Select Method</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="credit_card">Credit Card</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Paid From <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="paid_from_account_id" required>
                                <option value="">Select account…</option>
                                <?php foreach ($ps_cash_accounts as $acc): ?>
                                <option value="<?= (int)$acc['account_id'] ?>"><?= safe_output($acc['account_name'] . ($acc['account_code'] ? ' (' . $acc['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Cash/bank account the money is paid from.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" placeholder="Transaction ID, cheque number, etc.">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Payment notes or description"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_edit): ?>
<!-- Edit Payment Modal -->
<div class="modal fade" id="editModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Edit Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="payment_id" id="edit_payment_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="edit-message" class="mb-2"></div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="supplier_id" id="edit_supplier_id" required>
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['supplier_id'] ?>"><?= safe_output($s['supplier_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Purchase Order <span class="text-muted small">(Optional)</span></label>
                            <select class="form-select select2-static" name="purchase_order_id" id="edit_po_id">
                                <option value="">Select Order</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="payment_date" id="edit_payment_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="edit_amount" step="0.01" min="0.01" required oninput="recalcEditNet()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Withholding Tax (WHT)</label>
                            <select class="form-select" name="wht_rate_id" id="edit_wht_rate" onchange="recalcEditNet()">
                                <option value="" data-rate="0">No withholding tax</option>
                                <?php foreach ($ps_wht_rates as $w): $pct = rtrim(rtrim(number_format((float)$w['rate_percentage'], 2), '0'), '.'); ?>
                                <option value="<?= (int)$w['rate_id'] ?>" data-rate="<?= htmlspecialchars($w['rate_percentage']) ?>"><?= safe_output($w['rate_name']) ?> (<?= $pct ?>%)</option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Withheld from the supplier and remitted to TRA.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Withheld (−)</label>
                            <input type="text" class="form-control" id="edit_wht_amount" readonly value="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Net to Pay</label>
                            <input type="text" class="form-control fw-bold text-primary" id="edit_net" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Currency</label>
                            <select class="form-select select2-static" name="currency" id="edit_currency">
                                <option value="TZS">Tanzanian Shilling (TZS)</option>
                                <option value="USD">US Dollar (USD)</option>
                                <option value="EUR">Euro (EUR)</option>
                                <option value="GBP">British Pound (GBP)</option>
                                <option value="KES">Kenyan Shilling (KES)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="payment_method" id="edit_payment_method" required>
                                <option value="">Select Method</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="credit_card">Credit Card</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Paid From <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="paid_from_account_id" id="edit_paid_from" required>
                                <option value="">Select account…</option>
                                <?php foreach ($ps_cash_accounts as $acc): ?>
                                <option value="<?= (int)$acc['account_id'] ?>"><?= safe_output($acc['account_name'] . ($acc['account_code'] ? ' (' . $acc['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Cash/bank account the money is paid from.</small>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reference Number</label>
                            <input type="text" class="form-control" name="reference_number" id="edit_reference">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Update Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Payment Slip View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light d-print-none">
                <h5 class="modal-title"><i class="bi bi-receipt me-1"></i> Payment Voucher</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="paymentSlipContent" style="padding:28px 32px;">

                    <!-- Company Header — print only, hidden when viewing -->
                    <div class="slip-print-only text-center mb-3 pb-3 border-bottom">
                        <?php if (!empty($company_logo)): ?>
                        <img src="<?= getUrl($company_logo) ?>" height="60" class="mb-2 d-block mx-auto">
                        <?php endif; ?>
                        <div class="fw-bold" style="font-size:1.1rem;text-transform:uppercase;"><?= safe_output($company_name) ?></div>
                        <?php if (!empty($company_address)): ?>
                        <div style="font-size:0.8rem;color:#555;"><?= safe_output($company_address) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($company_phone) || !empty($company_email)): ?>
                        <div style="font-size:0.8rem;color:#555;">
                            <?= !empty($company_phone) ? 'Tel: '.safe_output($company_phone) : '' ?>
                            <?= !empty($company_email) ? ' &nbsp;|&nbsp; '.safe_output($company_email) : '' ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Slip Title + Number (always visible) -->
                    <div class="text-center mb-4">
                        <h5 class="fw-bold text-uppercase" style="letter-spacing:2px;">Payment Voucher</h5>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <div><span class="text-muted small">Voucher No:</span> <strong id="slip_number" class="text-primary"></strong></div>
                        <div><span class="text-muted small">Date:</span> <strong id="slip_date"></strong></div>
                    </div>

                    <!-- Payment Details Table (always visible) -->
                    <table class="table table-bordered table-sm mb-4" style="font-size:0.9rem;">
                        <tbody>
                            <tr>
                                <th class="bg-light" style="width:38%;">Paid To (Supplier)</th>
                                <td><strong id="slip_supplier"></strong><br><span id="slip_company" class="text-muted small"></span></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Amount</th>
                                <td><span id="slip_amount" class="fw-bold text-success" style="font-size:1.1rem;"></span> <span id="slip_currency" class="text-muted small"></span></td>
                            </tr>
                            <tr>
                                <th class="bg-light">Payment Method</th>
                                <td id="slip_method"></td>
                            </tr>
                            <tr id="slip_ref_row">
                                <th class="bg-light">Reference No.</th>
                                <td id="slip_ref"></td>
                            </tr>
                            <tr id="slip_po_row">
                                <th class="bg-light">Purchase Order</th>
                                <td id="slip_po"></td>
                            </tr>
                            <tr id="slip_notes_row">
                                <th class="bg-light">Notes / Description</th>
                                <td id="slip_notes"></td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Signature Section (always visible) -->
                    <div class="row mt-5 pt-3">
                        <div class="col-4 text-center">
                            <div class="border-top pt-2 mx-2">
                                <div class="small text-muted">Prepared By</div>
                                <div class="small fw-bold mt-1" id="slip_prepared_by"></div>
                            </div>
                        </div>
                        <div class="col-4 text-center">
                            <div class="border-top pt-2 mx-2">
                                <div class="small text-muted">Approved By</div>
                                <div class="small fw-bold mt-1">&nbsp;</div>
                            </div>
                        </div>
                        <div class="col-4 text-center">
                            <div class="border-top pt-2 mx-2">
                                <div class="small text-muted">Received By</div>
                                <div class="small fw-bold mt-1">&nbsp;</div>
                            </div>
                        </div>
                    </div>

                    <!-- Standard print footer — hidden on screen, shown on print -->
                    <div class="slip-print-only">
                        <?php require ROOT_DIR . '/includes/print_footer_html.php'; ?>
                    </div>

                </div>
            </div>
            <div class="modal-footer d-print-none">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle me-1"></i> Close</button>
                <button type="button" class="btn btn-primary" onclick="printSlip()"><i class="bi bi-printer me-1"></i> Print</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Print-only elements hidden during screen view */
.slip-print-only { display: none; }

@media print {
    /* Show only the payment slip, hide everything else */
    body * { visibility: hidden !important; }
    #paymentSlipContent, #paymentSlipContent * { visibility: visible !important; }
    #paymentSlipContent {
        position: fixed !important;
        top: 0; left: 0;
        width: 100%;
        padding: 30px !important;
        background: #fff;
    }
    /* Reveal print-only sections (header + footer) */
    .slip-print-only { display: block !important; visibility: visible !important; }
    .slip-print-only * { visibility: visible !important; }
}
</style>
<?php require ROOT_DIR . '/includes/print_footer_css.php'; ?>

<script>
function safeOutput(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}

let dtPayments;

$(document).ready(function () {

    // DataTable init
    dtPayments = $('#paymentsTable').DataTable({
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [[1, 'desc']],
        dom: 'rtipB',
        buttons: [
            { extend: 'excelHtml5', className: 'd-none', filename: 'supplier_payments_<?= date('Y-m-d') ?>', exportOptions: { columns: ':not(:last-child)' } }
        ],
        language: { emptyTable: 'No payments found.' },
        drawCallback: function () { renderCards(this.api()); }
    });

    // Auto view switch
    function applyView() {
        if (window.innerWidth < 768) {
            $('#tableView').addClass('d-none');
            $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none');
            $('#cardView').addClass('d-none');
        }
        renderCards(dtPayments);
    }
    applyView();
    $(window).on('resize', applyView);

    // Select2 for filter bar
    $('#filter_supplier, #filter_method').each(function () {
        $(this).select2({ theme: 'bootstrap-5', allowClear: true, width: '100%' });
    });

    // Select2 in modals
    $('#addModal, #editModal').on('shown.bs.modal', function () {
        const modal = $(this);
        modal.find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: modal, placeholder: 'Select...', allowClear: true, width: '100%' });
            }
        });
    });

    // Supplier → default WHT rate, for auto-fill when a supplier is chosen (Add modal).
    const SUPPLIER_WHT = <?= json_encode(array_column($suppliers, 'default_wht_rate_id', 'supplier_id'), JSON_FORCE_OBJECT) ?>;

    // Load POs on supplier change — Add modal (also auto-fills the supplier's default WHT)
    $('#add_supplier_id').on('change', function () {
        loadPOs($(this).val(), '#add_po_id', null);
        const w = SUPPLIER_WHT[$(this).val()];
        $('#add_wht_rate').val(w ? String(w) : '');
        recalcAddNet();
    });

    // Load POs on supplier change — Edit modal (keeps the payment's recorded WHT as loaded)
    $('#edit_supplier_id').on('change', function () {
        loadPOs($(this).val(), '#edit_po_id', null);
    });

    // Add form submit
    $('#addForm').on('submit', function (e) {
        e.preventDefault();
        submitForm(this, '<?= buildUrl('api/add_supplier_payment.php') ?>', function () { location.reload(); });
    });

    // Edit form submit
    $('#editForm').on('submit', function (e) {
        e.preventDefault();
        submitForm(this, '<?= buildUrl('api/update_supplier_payment.php') ?>', function () { location.reload(); });
    });

    // Reset modals on close
    $('.modal').on('hidden.bs.modal', function () {
        $(this).find('form')[0]?.reset();
        $(this).find('[id$="-message"]').html('');
        $(this).find('.select2-hidden-accessible').each(function () {
            $(this).val(null).trigger('change');
        });
    });
});

// Shared AJAX submit helper
function submitForm(form, url, onSuccess) {
    const btn  = $(form).find('[type="submit"]');
    const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
    $.ajax({
        url: url, type: 'POST',
        data: new FormData(form), contentType: false, processData: false, dataType: 'json',
        success: function (res) {
            if (res.success) {
                const modalEl = $(form).closest('.modal')[0];
                const bsModal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
                if (bsModal) bsModal.hide();
                Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, showConfirmButton: true }).then(() => onSuccess());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
        complete: function () { btn.prop('disabled', false).html(orig); }
    });
}

// Load purchase orders for a supplier
function loadPOs(supplierId, targetSel, selectedId) {
    const $sel = $(targetSel);
    $sel.empty().append('<option value="">Select Order</option>');
    if (!supplierId) return;
    $.getJSON('<?= buildUrl('api/get_supplier_orders.php') ?>', { supplier_id: supplierId }, function (res) {
        if (res.success && res.data && res.data.length) {
            res.data.forEach(function (o) {
                $sel.append(new Option(o.order_number + ' — ' + o.status, o.purchase_order_id, false, o.purchase_order_id == selectedId));
            });
        }
        if ($sel.hasClass('select2-hidden-accessible')) $sel.trigger('change.select2');
    });
}

// View payment slip
function viewPayment(id) {
    Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.ajax({
        url: '<?= buildUrl('api/get_supplier_payment.php') ?>',
        type: 'GET',
        data: { id: id },
        dataType: 'json',
        success: function (res) {
            Swal.close();
            if (res.success) {
                const p = res.data;
                const method = p.payment_method
                    ? p.payment_method.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
                    : '—';
                // Populate slip fields
                $('#slip_number').text(p.payment_number || '—');
                $('#slip_date').text(p.payment_date || '—');
                $('#slip_supplier').text(p.supplier_name || '—');
                $('#slip_company').text(p.company_name || '');
                $('#slip_amount').text(Number(p.amount).toLocaleString('en-US', {minimumFractionDigits: 2}));
                $('#slip_currency').text(p.currency || 'TZS');
                $('#slip_method').text(method);
                $('#slip_prepared_by').text(p.created_by_name || '');

                // Reference
                if (p.reference_number) {
                    $('#slip_ref').text(p.reference_number);
                    $('#slip_ref_row').show();
                } else { $('#slip_ref_row').hide(); }

                // Purchase Order
                if (p.order_number) {
                    $('#slip_po').text(p.order_number);
                    $('#slip_po_row').show();
                } else { $('#slip_po_row').hide(); }

                // Notes
                if (p.notes) {
                    $('#slip_notes').text(p.notes);
                    $('#slip_notes_row').show();
                } else { $('#slip_notes_row').hide(); }

                new bootstrap.Modal(document.getElementById('viewModal')).show();
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not load payment.' });
            }
        },
        error: function (xhr) {
            const msg = xhr.status === 404
                ? 'API endpoint not found. Check api/get_supplier_payment.php exists.'
                : 'Request failed (HTTP ' + xhr.status + '): ' + (xhr.responseText || '').substring(0, 150);
            Swal.fire({ icon: 'error', title: 'Error', text: msg });
        }
    });
}

function printSlip() {
    window.print();
}

// WHT live preview — base is the entered payment amount (ad-hoc, no VAT split).
function whtRecalc(prefix) {
    const amt  = parseFloat($('#' + prefix + '_amount').val()) || 0;
    const rate = parseFloat($('#' + prefix + '_wht_rate').find(':selected').data('rate')) || 0;
    const wht  = +(amt * rate / 100).toFixed(2);
    const f = n => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    $('#' + prefix + '_wht_amount').val(f(wht));
    $('#' + prefix + '_net').val(f(amt - wht));
}
function recalcAddNet()  { whtRecalc('add'); }
function recalcEditNet() { whtRecalc('edit'); }

// Edit payment — load data into modal
function editPayment(id) {
    $.getJSON('<?= buildUrl('api/get_supplier_payment.php') ?>', { id: id }, function (res) {
        if (res.success) {
            const p = res.data;
            $('#edit_payment_id').val(p.payment_id);
            $('#edit_payment_date').val(p.payment_date);
            $('#edit_amount').val(p.amount);
            $('#edit_wht_rate').val(p.wht_rate_id || '');
            $('#edit_reference').val(p.reference_number);
            $('#edit_notes').val(p.notes);
            // Set supplier first, then load POs
            $('#edit_supplier_id').val(p.supplier_id).trigger('change');
            $('#edit_currency').val(p.currency).trigger('change');
            $('#edit_payment_method').val(p.payment_method).trigger('change');
            $('#edit_paid_from').val(p.paid_from_account_id || '').trigger('change');
            recalcEditNet();
            loadPOs(p.supplier_id, '#edit_po_id', p.purchase_order_id);
            new bootstrap.Modal(document.getElementById('editModal')).show();
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not load payment.' });
        }
    });
}

// Delete payment
function deletePayment(id) {
    Swal.fire({
        title: 'Delete Payment?',
        text: 'This will remove the record and reverse the linked PO paid amount.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Deleting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.post('<?= buildUrl('api/delete_supplier_payment.php') ?>', { payment_id: id }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, showConfirmButton: true }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json').fail(function () {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' });
        });
    });
}

// Export via DataTable Excel button
function exportPayments() {
    dtPayments.button(0).trigger();
}

// Mobile card renderer
function renderCards(api) {
    const grid = $('#cardView');
    if (grid.hasClass('d-none')) return;
    grid.empty();
    const nodes = api.rows({ page: 'current' }).nodes();
    if (!nodes.length) {
        grid.html('<div class="col-12 text-center py-5 text-muted">No payments found.</div>');
        return;
    }
    const canEdit = <?= json_encode($can_edit) ?>;
    const canDel  = <?= json_encode($can_delete) ?>;
    let html = '';
    $(nodes).each(function () {
        const id       = $(this).data('payment-id');
        const cells    = $(this).find('td');
        const date     = cells.eq(1).text().trim();
        const supplier = cells.eq(2).find('.fw-bold').text().trim();
        const ref      = cells.eq(3).text().trim();
        const amount   = cells.eq(5).find('.fw-bold').text().trim();
        const currency = cells.eq(5).find('small').text().trim();
        const method   = cells.eq(6).text().trim();

        const viewBtn = `<button onclick="viewPayment(${id})" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></button>`;
        const editBtn = canEdit ? `<button onclick="editPayment(${id})" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></button>` : '';
        const delBtn  = canDel  ? `<button onclick="deletePayment(${id})" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>` : '';

        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <span class="fw-bold" style="font-size:0.9rem">${safeOutput(supplier)}</span>
                        <span class="fw-bold text-success" style="font-size:0.9rem">${safeOutput(amount)} <small class="text-muted">${safeOutput(currency)}</small></span>
                    </div>
                    <div style="font-size:0.8rem;color:#555;">
                        <small class="text-muted">Date:</small> ${safeOutput(date)} &nbsp;
                        <small class="text-muted">Method:</small> ${safeOutput(method)}
                        ${ref && ref !== '—' ? '<br><small class="text-muted">Ref:</small> ' + safeOutput(ref) : ''}
                    </div>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                        ${viewBtn}${editBtn}${delBtn}
                    </div>
                </div>
            </div>
        </div>`;
    });
    grid.html(html);
}
</script>

<?php
require_once FOOTER_FILE;
ob_end_flush();
?>
