<?php
/**
 * Petty Cash Management
 */
ob_start();

global $pdo;
require_once __DIR__ . '/../../../roots.php';
includeHeader();
autoEnforcePermission('petty_cash');

// Fetch company settings for print
$c_logo = getSetting('company_logo', '');
$c_name = getSetting('company_name', 'BMS');

require_once __DIR__ . '/../../../core/payment_source.php';   // cashBankAccounts()

try {
    // Fetch categories
    $catStmt = $pdo->query("SELECT * FROM account_categories ORDER BY category_name");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

    // Cash/bank accounts that can FUND a top-up (the money comes OUT of one of these).
    $cash_accounts = cashBankAccounts($pdo);

    // Expense accounts — these are the real "categories" a petty cash expense is
    // booked to (Dr expense / Cr petty cash), matching how QuickBooks/Xero record
    // petty cash spending. Pulled straight from the Chart of Accounts.
    $expense_accounts = expenseAccounts($pdo);

    // Registered petty cash FUNDS (multi-fund / imprest). The page works against
    // one selected fund at a time. The first registered fund is the default.
    $petty_funds   = pettyCashFunds($pdo);
    $default_fund  = !empty($petty_funds) ? (int)$petty_funds[0]['account_id'] : (int)($pc_id ?? 0);

    // The Petty Cash chart account (so it can be edited/re-parented from this page),
    // plus the asset accounts available as its parent and a Cash On Hand default.
    $petty_account = null;
    $pc_id = pettyCashAccountId($pdo);
    if ($pc_id) {
        $pcStmt = $pdo->prepare("SELECT account_id, account_code, account_name, account_type, category_id,
                                        description, opening_balance, status, parent_account_id, is_system
                                   FROM accounts WHERE account_id = ?");
        $pcStmt->execute([$pc_id]);
        $petty_account = $pcStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $pc_parent_accounts = $pdo->query("
        SELECT a.account_id, a.account_code, a.account_name, a.parent_account_id, at.category
          FROM accounts a JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.status = 'active' AND at.category = 'asset'
         ORDER BY a.account_code, a.account_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $pc_account_types = $pdo->query("SELECT type_name, display_name FROM account_types ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
    $cash_accounts = [];
    $petty_account = null;
    $pc_parent_accounts = [];
    $pc_account_types = [];
}
?>

<div class="container-fluid py-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
        
        <h3 style="color: #333 !important; font-weight: 700; text-transform: uppercase; margin: 5px 0; font-size: 18pt; letter-spacing: 1px;">PETTY CASH TRANSACTION REPORT</h3>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 4px solid #0d6efd; margin-top: 15px; margin-bottom: 25px; width: 150px; margin-left: auto; margin-right: auto;"></div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold text-primary"><i class="bi bi-wallet2 me-2"></i> Petty Cash</h2>
                    <p class="text-muted">Manage small daily expenses and cash funds</p>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <!-- Active fund — every transaction below is recorded against this fund -->
                    <div class="d-flex align-items-center bg-white shadow-sm px-2 py-1" style="border:1px solid #dee2e6;border-radius:8px;">
                        <span class="small text-muted me-2"><i class="bi bi-wallet"></i> Fund:</span>
                        <select id="fund_selector" class="form-select form-select-sm border-0 fw-bold" style="min-width:160px;box-shadow:none;" onchange="onFundChange()">
                            <?php foreach ($petty_funds as $f): ?>
                            <option value="<?= (int)$f['account_id'] ?>" <?= (int)$f['account_id'] === $default_fund ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['label']) ?> (<?= htmlspecialchars($f['account_code']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-link text-decoration-none ps-2 pe-1" data-bs-toggle="modal" data-bs-target="#addFundModal" title="Register another petty cash fund">
                            <i class="bi bi-plus-circle"></i>
                        </button>
                    </div>
                    <?php if ($petty_account): ?>
                    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#pettyAccountModal" title="Edit the Petty Cash account (parent / code)">
                        <i class="bi bi-gear me-1"></i> Edit Account
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#depositModal">
                        <i class="bi bi-arrow-down-left me-1"></i> Top Up (Deposit)
                    </button>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#expenseModal">
                        <i class="bi bi-receipt me-1"></i> Record Expense
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function resizeTextToFit() {
            const elements = document.querySelectorAll('.custom-stat-card h4.auto-resize');
            elements.forEach(el => {
                let size = 1.3; // Starting size
                el.style.fontSize = size + 'rem';
                const container = el.closest('.overflow-hidden');
                if (container) {
                    const containerWidth = container.clientWidth;
                    while (el.scrollWidth > containerWidth && size > 0.7) {
                        size -= 0.05;
                        el.style.fontSize = size + 'rem';
                    }
                }
            });
        }
        window.addEventListener('load', resizeTextToFit);
        window.addEventListener('resize', resizeTextToFit);
    </script>

    <!-- Stats Cards -->
    <div class="row mb-4" id="print-stats-cards">
        <div class="col-md-4 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3">
                            <i class="bi bi-wallet"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Current Balance</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat_balance">...</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Expenses (This Month)</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat_expenses">...</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center h-100 overflow-hidden">
                        <div class="stat-icon-circle me-3">
                            <i class="bi bi-list-check"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Total Transactions</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap" id="stat_count">...</h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4 border-0 shadow-sm d-print-none">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 fw-bold"><i class="bi bi-funnel me-2"></i>Filters & Search</h6>
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Period From</label>
                    <input type="date" class="form-control" id="filter_from_date">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase">Period To</label>
                    <input type="date" class="form-control" id="filter_to_date">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Category</label>
                    <select class="form-select select2-static" id="filter_category_id">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Type</label>
                    <select class="form-select" id="filter_type">
                        <option value="">All Types</option>
                        <option value="deposit">Deposit (Top Up)</option>
                        <option value="expense">Expense</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold text-muted text-uppercase">Search</label>
                    <input type="text" class="form-control" id="searchInput" placeholder="Search keywords...">
                </div>
                <div class="col-12 d-flex justify-content-end gap-2 mt-3">
                    <button type="button" class="btn btn-outline-secondary px-4" onclick="clearFilters()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Clear
                    </button>
                    <button type="button" class="btn btn-primary px-4" onclick="applyFilters()">
                        <i class="bi bi-filter me-1"></i> Apply Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printPettyCash()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" id="filter_limit" style="width: 60px; box-shadow: none; background: transparent;" onchange="loadTransactions(1)">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <!-- View toggle — desktop only -->
            <div class="btn-group shadow-sm bg-white d-none d-md-flex" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" id="btn-pc-table-view" class="btn btn-white fw-medium px-3 border-0" onclick="togglePCView('table')" style="background: #e9ecef; color: #000; font-weight:600;">
                    <i class="bi bi-list-task text-primary"></i> <span class="d-none d-xl-inline">List</span>
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" id="btn-pc-card-view" class="btn btn-white fw-medium px-3 border-0" onclick="togglePCView('card')" style="background: #fff; color: #444;">
                    <i class="bi bi-grid-3x3-gap text-primary"></i> <span class="d-none d-xl-inline">Card</span>
                </button>
            </div>
        </div>
        <div>
            <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill" id="total_records_badge">
                <i class="bi bi-check-circle-fill me-1"></i> 0 records
            </span>
        </div>
    </div>

    <div id="pcTableView" class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-bottom d-print-none">
            <h5 class="mb-0 fw-bold">Transactions History</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="transactionsTable">
                <thead class="bg-light">
                    <tr>
                        <th style="width:50px;" class="ps-4 text-center">S/NO</th>
                        <th class="ps-4">Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Reference</th>
                        <th>Received By</th>
                        <th>User</th>
                        <th class="text-end pe-4">Amount</th>
                        <th class="text-center d-print-none">📎</th>
                        <th class="text-end pe-4 d-print-none">Actions</th>
                    </tr>
                </thead>
                <tbody id="transactionsTableBody">
                    <tr><td colspan="11" class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></td></tr>
                </tbody>
            </table>
        </div>
        <!-- Pagination Controls -->
        <div class="card-footer bg-white border-top-0 py-3">
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-end mb-0" id="pagination">
                    <!-- Pagination links will be loaded here -->
                </ul>
            </nav>
        </div>
    </div>

    <!-- Card View (populated by renderTable JS) -->
    <div id="pcCardView" style="display:none;">
        <div class="row g-3" id="pcCardGrid">
            <div class="col-12 text-center py-5 text-muted">Loading...</div>
        </div>
    </div>
</div>

<!-- Add Fund Modal — register another cash account as a petty cash fund -->
<div class="modal fade" id="addFundModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-wallet me-2"></i>Register Petty Cash Fund</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addFundForm">
                <div class="modal-body p-4">
                    <p class="text-muted small">Pick a cash/bank account to use as a separate petty cash float. Each fund tracks its own balance and transactions.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Cash Account <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="account_id" id="fund_account_select" required>
                            <option value="">Select a cash account</option>
                            <?php foreach ($cash_accounts as $ca): ?>
                            <option value="<?= (int)$ca['account_id'] ?>"><?= htmlspecialchars($ca['account_code'] . ' — ' . $ca['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Tip: create "Petty Cash – Branch X" accounts in the Chart of Accounts (Sub Type = Bank/Cash) to appear here.</small>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-bold">Label <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" class="form-control" name="label" id="fund_label" placeholder="e.g. Head Office, Branch B">
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Add Fund</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Deposit Modal -->
<div class="modal fade" id="depositModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold" id="depositModalTitle">
                    <i class="bi bi-arrow-down-left me-2"></i>Top Up Petty Cash
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="depositForm">
                <input type="hidden" name="type" value="deposit">
                <input type="hidden" name="id" id="deposit_id" value="0">
                <input type="hidden" name="fund_account_id" id="deposit_fund_account_id" value="<?= $default_fund ?>">
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">TSh</span>
                            <input type="number" class="form-control" name="amount" id="deposit_amount" step="0.01" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="date" id="deposit_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Funding Account (Bank/Cash) <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="source_account_id" id="deposit_source_account_id" required>
                            <option value="">Select the account the money comes from</option>
                            <?php foreach ($cash_accounts as $ca): ?>
                            <option value="<?= (int)$ca['account_id'] ?>"><?= htmlspecialchars(($ca['account_code'] ? $ca['account_code'] . ' — ' : '') . $ca['account_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><i class="bi bi-arrow-left-right"></i> Money moves OUT of this account and INTO petty cash — posted to the ledger so both balances update.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                        <select class="form-select select2-static" name="category_id" id="deposit_category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Reference</label>
                        <input type="text" class="form-control" name="reference" id="deposit_reference" placeholder="e.g. Bank Withdrawal Slip #">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea class="form-control" name="description" id="deposit_description" rows="2" placeholder="Source of funds..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="depositSubmitBtn">
                        <i class="bi bi-save me-1"></i> Save Deposit
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold" id="expenseModalTitle">
                    <i class="bi bi-receipt me-2"></i>Record Expense
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="expenseForm" enctype="multipart/form-data">
                <input type="hidden" name="type" value="expense">
                <input type="hidden" name="id" id="expense_id" value="0">
                <input type="hidden" name="fund_account_id" id="expense_fund_account_id" value="<?= $default_fund ?>">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Amount & Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">TSh</span>
                                <input type="number" class="form-control" name="amount" id="expense_amount" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date" id="expense_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <!-- Expense Account (category) & Reference -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Expense Account <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="expense_account_id" id="expense_account_id" required>
                                <option value="">Select expense account</option>
                                <?php foreach ($expense_accounts as $ea): ?>
                                <option value="<?= $ea['account_id'] ?>"><?= htmlspecialchars($ea['account_code'] . ' — ' . $ea['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">The cost is booked here (Profit &amp; Loss) and paid from petty cash.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Reference No.</label>
                            <input type="text" class="form-control" name="reference" id="expense_reference" placeholder="e.g. Internal Ref #">
                        </div>
                        <!-- Received By & Department -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Received By <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="received_by" id="expense_received_by" placeholder="Name of person receiving cash" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Department</label>
                            <input type="text" class="form-control" name="department" id="expense_department" placeholder="e.g. Admin, Operations">
                        </div>
                        <!-- Payment Mode -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Payment Mode</label>
                            <select class="form-select" name="payment_mode" id="expense_payment_mode" onchange="toggleChequeField()">
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="cheque_number_group" style="display:none;">
                            <label class="form-label fw-bold">Cheque Number</label>
                            <input type="text" class="form-control" name="cheque_number" id="expense_cheque_number" placeholder="e.g. CHQ-001234">
                        </div>
                        <!-- Supporting Document -->
                        <div class="col-12"><hr class="my-1"><p class="fw-bold small text-muted text-uppercase mb-0">Supporting Document</p></div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Document Type</label>
                            <select class="form-select" name="receipt_type" id="expense_receipt_type">
                                <option value="">-- None --</option>
                                <option value="receipt">Receipt</option>
                                <option value="invoice">Invoice</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Receipt / Invoice No.</label>
                            <input type="text" class="form-control" name="receipt_number" id="expense_receipt_number" placeholder="e.g. RCP-0012">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Attach File <small class="text-muted">(PDF/JPG/PNG, max 5MB)</small></label>
                            <input type="file" class="form-control" name="receipt_file" id="expense_receipt_file" accept=".pdf,.jpg,.jpeg,.png">
                            <div id="current_attachment_info" class="mt-1 small text-muted" style="display:none;"></div>
                        </div>
                        <!-- Description -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Description / Purpose <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="description" id="expense_description" rows="2" placeholder="Details of expense..." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4" id="expenseSubmitBtn">
                        <i class="bi bi-save me-1"></i> Save Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-white py-3 border-0">
                <div class="d-flex align-items-center">
                    <div class="bg-info-soft p-3 rounded-circle me-3">
                        <i class="bi bi-file-earmark-text text-info fs-4"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0">Transaction Voucher</h5>
                        <p class="text-muted small mb-0" id="detail_voucher_no">#PCV-00000</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 pt-0">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="bg-light p-3 rounded-3 h-100">
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Status & Type</label>
                            <div id="detail_type_badge" class="mb-3"></div>
                            
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Transaction Date</label>
                            <p class="fw-bold fs-5 mb-3" id="detail_date"></p>
                            
                            <label class="small text-muted text-uppercase fw-bold d-block mb-1">Category / Project</label>
                            <p class="fw-bold mb-0" id="detail_category"></p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="bg-primary bg-opacity-10 p-4 rounded-3 text-center h-100 d-flex flex-column justify-content-center">
                            <label class="small text-primary text-uppercase fw-bold d-block mb-2">Total Amount</label>
                            <h2 class="fw-bold text-primary mb-2" id="detail_amount">...</h2>
                            <p id="detail_amount_type" class="text-muted small mb-0"></p>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <table class="table table-borderless table-sm mb-0">
                        <tr>
                            <td class="text-muted py-2" style="width: 150px;">Reference No.</td>
                            <td class="fw-semibold py-2" id="detail_reference"></td>
                            <td class="text-muted py-2" style="width: 150px;">Received By</td>
                            <td class="fw-semibold py-2" id="detail_received_by"></td>
                        </tr>
                        <tr>
                            <td class="text-muted py-2">Department</td>
                            <td class="fw-semibold py-2" id="detail_department"></td>
                            <td class="text-muted py-2">Payment Mode</td>
                            <td class="fw-semibold py-2" id="detail_payment_mode"></td>
                        </tr>
                        <tr>
                            <td class="text-muted py-2">Receipt Type</td>
                            <td class="fw-semibold py-2" id="detail_receipt_type"></td>
                            <td class="text-muted py-2">Receipt / Invoice No.</td>
                            <td class="fw-semibold py-2" id="detail_receipt_number"></td>
                        </tr>
                        <tr>
                            <td class="text-muted py-2">Recorded By</td>
                            <td class="fw-semibold py-2" id="detail_user"></td>
                            <td class="text-muted py-2">Timestamp</td>
                            <td class="fw-semibold py-2" id="detail_timestamp"></td>
                        </tr>
                        <tr id="detail_attachment_row">
                            <td class="text-muted py-2">Attachment</td>
                            <td class="py-2" colspan="3" id="detail_attachment"></td>
                        </tr>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-light rounded-3">
                    <label class="small text-muted text-uppercase fw-bold d-block mb-2">Description / Narration</label>
                    <p class="mb-0 fs-6" id="detail_description" style="white-space: pre-wrap;"></p>
                </div>
            </div>
            <div class="modal-footer border-0 bg-light py-3">
                <button type="button" class="btn btn-outline-secondary px-4 fw-semibold" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary px-4 fw-semibold" id="printVoucherBtn">
                    <i class="bi bi-printer me-2"></i>Print Voucher
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentPage = 1;
    let searchQuery = '';

    function togglePCView(viewType) {
        const isMobile = window.innerWidth <= 767;
        if (isMobile) viewType = 'card';
        if (viewType === 'card') {
            document.getElementById('pcTableView').style.display = 'none';
            document.getElementById('pcCardView').style.display = '';
            document.getElementById('btn-pc-table-view').style.cssText = 'background:#fff;color:#444;font-weight:normal;';
            document.getElementById('btn-pc-card-view').style.cssText = 'background:#e9ecef;color:#000;font-weight:600;';
        } else {
            document.getElementById('pcCardView').style.display = 'none';
            document.getElementById('pcTableView').style.display = '';
            document.getElementById('btn-pc-table-view').style.cssText = 'background:#e9ecef;color:#000;font-weight:600;';
            document.getElementById('btn-pc-card-view').style.cssText = 'background:#fff;color:#444;font-weight:normal;';
        }
        if (!isMobile) localStorage.setItem('pcView', viewType);
    }

    document.addEventListener('DOMContentLoaded', function() {
        logReportAction('Viewed Petty Cash', 'User viewed the petty cash transactions list');

        // Init view
        const savedPCView = window.innerWidth <= 767 ? 'card' : (localStorage.getItem('pcView') || 'table');
        togglePCView(savedPCView);
        window.addEventListener('resize', function() { if (window.innerWidth <= 767) togglePCView('card'); });

        // Select2 on filter (outside modal)
        $('#filter_category_id').select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'All Categories' });

        // Select2 on modal selects
        document.getElementById('depositModal').addEventListener('shown.bs.modal', function() {
            $('#deposit_source_account_id').select2({ theme: 'bootstrap-5', dropdownParent: $('#depositModal'), width: '100%', allowClear: true, placeholder: 'Select funding account' });
            $('#deposit_category_id').select2({ theme: 'bootstrap-5', dropdownParent: $('#depositModal'), width: '100%', allowClear: true, placeholder: 'Select Category' });
        });
        document.getElementById('expenseModal').addEventListener('shown.bs.modal', function() {
            $('#expense_account_id').select2({ theme: 'bootstrap-5', dropdownParent: $('#expenseModal'), width: '100%', allowClear: true, placeholder: 'Select expense account' });
        });

        loadTransactions(1);
        
        // Search listener with debounce
        let timeout = null;
        document.getElementById('searchInput').addEventListener('keyup', function() {
            clearTimeout(timeout);
            searchQuery = this.value;
            timeout = setTimeout(() => {
                currentPage = 1;
                loadTransactions(1);
            }, 500);
        });
    });

    function loadTransactions(page) {
        currentPage = page;
        const tbody = document.getElementById('transactionsTableBody');
        const limit = document.getElementById('filter_limit').value;
        const from_date = document.getElementById('filter_from_date').value;
        const to_date = document.getElementById('filter_to_date').value;
        const category_id = document.getElementById('filter_category_id').value;
        const type = document.getElementById('filter_type').value;
        const search = document.getElementById('searchInput').value;
        const fund = (document.getElementById('fund_selector') || {}).value || '';

        if(page === 1) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>';
        }

        const url = `<?= getUrl('api/petty_cash/get_transactions.php') ?>?page=${page}&limit=${limit}&search=${encodeURIComponent(search)}&from_date=${from_date}&to_date=${to_date}&category_id=${category_id}&type=${type}&fund_account_id=${fund}`;

        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderTable(data.transactions);
                    renderPagination(data.pagination);
                    $('#stat_balance').text(data.stats.balance);
                    $('#stat_expenses').text(data.stats.monthly_expenses);
                    $('#stat_count').text(data.stats.total_transactions);
                    setTimeout(resizeTextToFit, 10);
                    document.getElementById('total_records_badge').innerHTML = `<i class="bi bi-check-circle-fill me-1"></i> ${data.pagination.total_records} records`;
                } else {
                    tbody.innerHTML = `<tr><td colspan="8" class="text-center text-danger py-4">${data.message}</td></tr>`;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">Failed to load data</td></tr>';
            });
    }

    function applyFilters() {
        loadTransactions(1);
    }

    // Switching the active fund: point both forms at it and reload the list/balance.
    function onFundChange() {
        const fund = (document.getElementById('fund_selector') || {}).value || '';
        const d = document.getElementById('deposit_fund_account_id');
        const e = document.getElementById('expense_fund_account_id');
        if (d) d.value = fund;
        if (e) e.value = fund;
        loadTransactions(1);
    }

    function clearFilters() {
        document.getElementById('filterForm').reset();
        loadTransactions(1);
    }

    function copyTable() {
        const table = document.querySelector('table');
        const range = document.createRange();
        range.selectNode(table);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        document.execCommand('copy');
        window.getSelection().removeAllRanges();
        logReportAction('Copied Petty Cash List', 'User copied petty cash transaction list to clipboard');
        Swal.fire({ icon: 'success', title: 'Copied!', text: 'Table data copied to clipboard', timer: 1000, showConfirmButton: false });
    }

    function printPettyCash() {
        logReportAction('Printed Petty Cash List', 'User printed the petty cash transaction list');
        window.print();
    }

    function exportExcel() {
        logReportAction('Exported Petty Cash List', 'User exported petty cash transaction list to Excel');
        const table = document.querySelector('table');
        const rows = Array.from(table.querySelectorAll('tr'));
        const csvContent = rows.map(row => {
            const cols = Array.from(row.querySelectorAll('th, td')).slice(0, -1);
            return cols.map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
        }).join('\n');

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.setAttribute('download', 'Petty_Cash_Transactions.csv');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function renderTable(transactions) {
        const tbody = document.getElementById('transactionsTableBody');
        const cardGrid = document.getElementById('pcCardGrid');
        tbody.innerHTML = '';
        cardGrid.innerHTML = '';

        if (transactions.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="11" class="text-center py-5">
                        <div class="text-muted">
                            <i class="bi bi-inbox fs-1 d-block mb-3 opacity-50"></i>
                            <h5>No transactions found</h5>
                            <p>Start by topping up your petty cash fund</p>
                        </div>
                    </td>
                </tr>`;
            cardGrid.innerHTML = '<div class="col-12 text-center py-5 text-muted">No transactions found.</div>';
            return;
        }

        transactions.forEach((t, index) => {
            const sn = index + 1;
            const isDeposit = t.type === 'deposit';
            const amountClass = isDeposit ? 'text-success' : 'text-danger';
            const badgeClass = isDeposit ? 'success' : 'danger';
            const sign = isDeposit ? '+' : '-';
            
            const amount = new Intl.NumberFormat('en-TZ', { style: 'currency', currency: 'TZS' }).format(t.amount).replace('TZS', '').trim();
            const dateStr = new Date(t.transaction_date).toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'});

            const jsonT = JSON.stringify(t).replace(/'/g, "&apos;").replace(/"/g, "&quot;");

            const row = `
                <tr>
                    <td class="ps-4 text-center text-muted small fw-bold">${sn}</td>
                    <td class="ps-4">${dateStr}</td>
                    <td>
                        <span class="badge rounded-pill bg-${badgeClass} bg-opacity-10 text-${badgeClass}">
                            ${t.type.charAt(0).toUpperCase() + t.type.slice(1)}
                        </span>
                    </td>
                    <td>${t.description || '-'}</td>
                    <td>${t.category_name || '-'}</td>
                    <td>${t.reference_number || '-'}</td>
                    <td><small>${t.received_by || '-'}</small></td>
                    <td><small class="text-muted">${t.username || 'System'}</small></td>
                    <td class="text-end fw-bold ${amountClass}">${sign}${amount}</td>
                    <td class="text-center d-print-none">
                        ${t.receipt_file
                            ? `<a href="<?= getUrl('api/petty_cash/get_attachment.php') ?>?id=${t.id}" target="_blank" class="text-warning" title="View Attachment"><i class="bi bi-paperclip fs-5"></i></a>`
                            : '<span class="text-muted small">-</span>'}
                    </td>
                    <td class="text-end pe-4 d-print-none">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li>
                                    <a class="dropdown-item" href="#" onclick='viewTransactionDetails(${jsonT}); return false;'>
                                        <i class="bi bi-eye me-2 text-info"></i> View Details
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="#" onclick='printVoucher(${t.id}); return false;'>
                                        <i class="bi bi-printer me-2 text-secondary"></i> Print Voucher
                                    </a>
                                </li>
                                ${t.receipt_file ? `
                                <li>
                                    <a class="dropdown-item" href="<?= getUrl('api/petty_cash/get_attachment.php') ?>?id=${t.id}" target="_blank">
                                        <i class="bi bi-paperclip me-2 text-warning"></i> View Attachment
                                    </a>
                                </li>` : ''}
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="#" onclick='editTransaction(${jsonT}); return false;'>
                                        <i class="bi bi-pencil me-2 text-primary"></i> Edit
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="#" onclick="deleteTransaction(${t.id}); return false;">
                                        <i class="bi bi-trash me-2"></i> Delete
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            `;
            tbody.innerHTML += row;

            // Card view (reuse isDeposit/amountClass/sign/jsonT declared above)
            cardGrid.innerHTML += `
                <div class="col-xl-3 col-lg-4 col-md-6">
                    <div class="card h-100 border-0 shadow-sm rounded-3">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center py-2 px-3">
                            <div>
                                <div class="fw-bold" style="font-size:0.85rem;">${t.description || '—'}</div>
                                <small class="text-muted">${dateStr}</small>
                            </div>
                            <span class="badge bg-${isDeposit ? 'success' : 'danger'}">${t.type}</span>
                        </div>
                        <div class="card-body py-2 px-3" style="font-size:0.8rem;">
                            <div class="mb-1 fw-bold text-${isDeposit ? 'success' : 'danger'}">${sign}${amount}</div>
                            <div class="mb-1"><i class="bi bi-tag text-muted me-1"></i>${t.category_name || '—'}</div>
                            <div><i class="bi bi-person text-muted me-1"></i>${t.received_by || t.username || '—'}</div>
                        </div>
                        <div class="card-footer bg-white" style="padding:6px 8px;">
                            <div style="display:flex; flex-wrap:nowrap; gap:4px;">
                                <button class="btn btn-sm btn-outline-info" onclick='viewTransactionDetails(${jsonT})' title="View" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-eye"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick='printVoucher(${t.id})' title="Print" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-printer"></i></button>
                                <button class="btn btn-sm btn-outline-primary" onclick='editTransaction(${jsonT})' title="Edit" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteTransaction(${t.id})" title="Delete" style="flex:1;min-width:0;padding:3px 4px;font-size:0.72rem;"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                    </div>
                </div>`;
        });
    }

    function renderPagination(pg) {
        const nav = document.getElementById('pagination');
        nav.innerHTML = '';
        
        if (pg.total_pages <= 1) return;

        nav.innerHTML += `
            <li class="page-item ${pg.current_page === 1 ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="loadTransactions(${pg.current_page - 1}); return false;">Previous</a>
            </li>
        `;

        for (let i = 1; i <= pg.total_pages; i++) {
            if (
                i === 1 || 
                i === pg.total_pages || 
                (i >= pg.current_page - 1 && i <= pg.current_page + 1)
            ) {
                nav.innerHTML += `
                    <li class="page-item ${i === pg.current_page ? 'active' : ''}">
                        <a class="page-link" href="#" onclick="loadTransactions(${i}); return false;">${i}</a>
                    </li>
                `;
            } else if (
                i === pg.current_page - 2 || 
                i === pg.current_page + 2
            ) {
                nav.innerHTML += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
            }
        }

        nav.innerHTML += `
            <li class="page-item ${pg.current_page === pg.total_pages ? 'disabled' : ''}">
                <a class="page-link" href="#" onclick="loadTransactions(${pg.current_page + 1}); return false;">Next</a>
            </li>
        `;
    }

    function updateStats(stats) {
        const fmt = (amt) => new Intl.NumberFormat('en-TZ', { style: 'currency', currency: 'TZS' }).format(amt);
        document.getElementById('stat_balance').innerText = fmt(stats.balance);
        document.getElementById('stat_expenses').innerText = fmt(stats.monthly_expenses);
        document.getElementById('stat_count').innerText = stats.total_transactions;
    }

    function resizeTextToFit() {
        const elements = document.querySelectorAll('.resize-text-to-fit');
        elements.forEach(el => {
            let fontSize = parseFloat(window.getComputedStyle(el).fontSize);
            const parentWidth = el.parentElement.offsetWidth;
            let textWidth = el.scrollWidth;

            while (textWidth > parentWidth && fontSize > 10) { // Minimum font size 10px
                fontSize -= 0.5;
                el.style.fontSize = `${fontSize}px`;
                textWidth = el.scrollWidth;
            }
        });
    }

handleFormSubmit('depositForm', '<?= getUrl('api/petty_cash/save_transaction.php') ?>');
handleFormSubmit('expenseForm', '<?= getUrl('api/petty_cash/save_transaction.php') ?>');

// Register a new petty cash fund, then reload so the selector picks it up.
document.getElementById('addFundForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = this.querySelector('[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Adding...';
    fetch('<?= getUrl('api/petty_cash/add_fund.php') ?>', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Fund added', text: res.message, timer: 1600, showConfirmButton: false })
                    .then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not add fund.' });
                btn.disabled = false; btn.innerHTML = orig;
            }
        })
        .catch(() => { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); btn.disabled = false; btn.innerHTML = orig; });
});

function handleFormSubmit(formId, apiEndpoint) {
    document.getElementById(formId).addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        const transactionId = formData.get('id');
        const type = formData.get('type');
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
        
        fetch(apiEndpoint, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: data.message || 'Transaction recorded successfully',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to save transaction' });
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        })
        .catch(error => {
            console.error(error);
            Swal.fire({ icon: 'error', title: 'Error', text: 'System error occurred' });
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    });
}

function deleteTransaction(id) {
    Swal.fire({
        title: 'Delete Transaction?',
        text: "This action cannot be undone.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('<?= getUrl('api/petty_cash/delete_transaction.php') ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Deleted!', 'Transaction has been deleted.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error!', data.message || 'Failed to delete.', 'error');
                }
            });
        }
    });
}

function editTransaction(data) {
    const isDeposit = data.type === 'deposit';
    const prefix = isDeposit ? 'deposit' : 'expense';
    const modalId = isDeposit ? 'depositModal' : 'expenseModal';

    document.getElementById(prefix + '_id').value = data.id;
    document.getElementById(prefix + '_amount').value = data.amount;
    document.getElementById(prefix + '_date').value = data.transaction_date;
    if (isDeposit) {
        $('#deposit_category_id').val(data.category_id || '').trigger('change.select2');
    } else {
        // Expense "category" is now the expense account it was booked to.
        $('#expense_account_id').val(data.expense_account_id || '').trigger('change.select2');
    }
    document.getElementById(prefix + '_reference').value = data.reference_number || '';
    document.getElementById(prefix + '_description').value = data.description || '';

    if (!isDeposit) {
        document.getElementById('expense_received_by').value    = data.received_by || '';
        document.getElementById('expense_department').value     = data.department || '';
        document.getElementById('expense_payment_mode').value   = data.payment_mode || 'cash';
        document.getElementById('expense_cheque_number').value  = data.cheque_number || '';
        document.getElementById('expense_receipt_type').value   = data.receipt_type || '';
        document.getElementById('expense_receipt_number').value = data.receipt_number || '';
        toggleChequeField();

        const attachInfo = document.getElementById('current_attachment_info');
        if (data.receipt_file) {
            attachInfo.style.display = 'block';
            attachInfo.innerHTML = `<i class="bi bi-paperclip me-1"></i> Current: <a href="<?= getUrl('api/petty_cash/get_attachment.php') ?>?id=${data.id}" target="_blank">View existing attachment</a> — upload a new file to replace it`;
        } else {
            attachInfo.style.display = 'none';
        }
    }

    new bootstrap.Modal(document.getElementById(modalId)).show();
}

function viewTransactionDetails(data) {
    const isDeposit = data.type === 'deposit';
    const amount = new Intl.NumberFormat('en-TZ', { style: 'currency', currency: 'TZS' }).format(data.amount);
    const dateStr = new Date(data.transaction_date).toLocaleDateString('en-GB', {day: 'numeric', month: 'long', year: 'numeric'});
    const badgeClass = isDeposit ? 'success' : 'danger';
    
    document.getElementById('detail_voucher_no').innerText = '#PCV-' + String(data.id).padStart(5, '0');
    document.getElementById('detail_date').innerText = dateStr;
    document.getElementById('detail_type_badge').innerHTML = `<span class="badge rounded-pill bg-${badgeClass} text-uppercase px-3">${data.type}</span>`;
    document.getElementById('detail_category').innerText = data.category_name || 'N/A';
    document.getElementById('detail_amount').innerText = (isDeposit ? '+' : '-') + amount;
    document.getElementById('detail_amount').className = `fw-bold text-${badgeClass} mb-2`;
    document.getElementById('detail_amount_type').innerText = (isDeposit ? 'Deposit' : 'Expense') + ' Amount';
    document.getElementById('detail_reference').innerText    = data.reference_number || 'N/A';
    document.getElementById('detail_received_by').innerText  = data.received_by || 'N/A';
    document.getElementById('detail_department').innerText   = data.department || 'N/A';
    document.getElementById('detail_payment_mode').innerHTML = data.payment_mode
        ? `<span class="badge bg-secondary text-uppercase">${data.payment_mode}${data.cheque_number ? ' — ' + data.cheque_number : ''}</span>`
        : 'N/A';
    document.getElementById('detail_receipt_type').innerText   = data.receipt_type ? data.receipt_type.charAt(0).toUpperCase() + data.receipt_type.slice(1) : 'N/A';
    document.getElementById('detail_receipt_number').innerText = data.receipt_number || 'N/A';
    document.getElementById('detail_description').innerText    = data.description || 'N/A';
    document.getElementById('detail_user').innerText      = data.username || 'System';
    document.getElementById('detail_timestamp').innerText = data.created_at;

    const attachEl = document.getElementById('detail_attachment');
    if (data.receipt_file) {
        attachEl.innerHTML = `<a href="<?= getUrl('api/petty_cash/get_attachment.php') ?>?id=${data.id}" target="_blank" class="btn btn-sm btn-outline-warning"><i class="bi bi-paperclip me-1"></i> View Attachment</a>
        <a href="<?= getUrl('api/petty_cash/get_attachment.php') ?>?id=${data.id}&download=1" class="btn btn-sm btn-outline-secondary ms-2"><i class="bi bi-download me-1"></i> Download</a>`;
    } else {
        attachEl.innerText = 'No attachment';
    }

    document.getElementById('printVoucherBtn').onclick = () => printVoucher(data.id);
    new bootstrap.Modal(document.getElementById('detailsModal')).show();
}

function printVoucher(id) {
    const url = `<?= getUrl('petty_cash_print') ?>?id=${id}`;
    window.open(url, '_blank').focus();
}

function toggleChequeField() {
    const mode = document.getElementById('expense_payment_mode').value;
    document.getElementById('cheque_number_group').style.display = mode === 'cheque' ? 'block' : 'none';
}

// Reset expense modal fields on close
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('expenseModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('expense_id').value = '0';
        document.getElementById('expenseForm').reset();
        document.getElementById('cheque_number_group').style.display = 'none';
        document.getElementById('current_attachment_info').style.display = 'none';
        document.getElementById('expenseModalTitle').innerHTML = '<i class="bi bi-receipt me-2"></i>Record Expense';
    });
});
</script>

<?php if ($petty_account): ?>
<!-- Edit Petty Cash Account Modal (parent + code), shared save_account API -->
<div class="modal fade" id="pettyAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-secondary text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-gear me-2"></i>Edit Petty Cash Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="pettyAccountForm" method="POST">
                <input type="hidden" name="account_id" value="<?= (int)$petty_account['account_id'] ?>">
                <input type="hidden" name="cash_flow_category" value="cash">
                <div class="modal-body p-4">
                    <?php if ((int)$petty_account['is_system'] === 1): ?>
                    <?php if (isAdmin()): ?>
                    <div class="alert alert-info py-2 px-3"><i class="bi bi-shield-lock me-1"></i> System account — you are editing as <strong>admin</strong>. Code, name and type can be changed.</div>
                    <?php else: ?>
                    <div class="alert alert-warning py-2 px-3"><i class="bi bi-lock-fill me-1"></i> This is a <strong>system account</strong> wired to the petty-cash feature. You can re-parent it freely; its code/name/type are protected.</div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Code</label>
                            <div class="input-group">
                                <input type="text" class="form-control<?= ((int)$petty_account['is_system'] === 1 && !isAdmin()) ? ' bg-light' : '' ?>" id="pc_account_code" name="account_code" <?= ((int)$petty_account['is_system'] === 1 && !isAdmin()) ? 'readonly' : '' ?> value="<?= htmlspecialchars($petty_account['account_code']) ?>">
                                <button type="button" class="btn btn-outline-secondary" id="pcRegenBtn" onclick="pcRegenerateCode()" title="Regenerate code from parent"><i class="bi bi-arrow-clockwise"></i></button>
                            </div>
                            <small class="text-muted">Regenerate to match the chosen parent.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Name</label>
                            <input type="text" class="form-control" id="pc_account_name" name="account_name" value="<?= htmlspecialchars($petty_account['account_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Parent Account (group)</label>
                            <div id="pc_parentCascade"></div>
                            <input type="hidden" id="pc_parent_account_id" name="parent_account_id" value="<?= (int)$petty_account['parent_account_id'] ?>">
                            <small class="text-muted">Pick a group, then drill into sub-accounts (▸) to nest under Cash On Hand.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Type</label>
                            <select class="form-select" id="pc_account_type" name="account_type" required>
                                <?php foreach ($pc_account_types as $t): ?>
                                <option value="<?= htmlspecialchars($t['type_name']) ?>" <?= $t['type_name'] === $petty_account['account_type'] ? 'selected' : '' ?>><?= htmlspecialchars($t['display_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= $petty_account['status']==='active'?'selected':'' ?>>Active</option>
                                <option value="inactive" <?= $petty_account['status']==='inactive'?'selected':'' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Opening Balance</label>
                            <input type="number" step="0.01" class="form-control" name="opening_balance" value="<?= htmlspecialchars($petty_account['opening_balance']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($petty_account['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-secondary px-4"><i class="bi bi-save me-1"></i> Save Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= getUrl('assets/js/parent_cascade.js') ?>"></script>
<script>
const PC_CODE_LOCKED = <?= ((int)$petty_account['is_system'] === 1 && !isAdmin()) ? 'true' : 'false' ?>;
const PC_PARENTS = <?= json_encode(array_map(fn($a) => ['id' => (int)$a['account_id'], 'code' => $a['account_code'], 'name' => $a['account_name'], 'parent' => ($a['parent_account_id'] !== null ? (int)$a['parent_account_id'] : null), 'category' => $a['category']], $pc_parent_accounts)) ?>;
const PC_ACCOUNT_ID = <?= (int)$petty_account['account_id'] ?>;
let pcCascade = null;

function pcRegenerateCode() {
    const type = document.getElementById('pc_account_type').value || 'asset';
    const parent = document.getElementById('pc_parent_account_id').value || 0;
    $.getJSON('<?= buildUrl('api/account/get_next_account_code.php') ?>', { account_type: type, parent_account_id: parent })
        .done(res => { if (res && res.success && res.code) document.getElementById('pc_account_code').value = res.code; });
}

// Cascade onChange (fires only on user interaction): offer to renumber, unless the
// code is protected (system account) — then re-parent is allowed but the code stays.
function pcParentChanged() {
    if (PC_CODE_LOCKED) {
        Swal.fire({ icon: 'info', title: 'Re-parent only', text: 'This system account can be re-parented, but its code is protected and will not change.' });
        return;
    }
    Swal.fire({
        icon: 'question', title: 'Renumber to match new parent?',
        text: 'Regenerate the code to match the new parent? Transactions are unaffected (they reference the account, not the code).',
        showCancelButton: true, confirmButtonText: 'Yes, renumber', cancelButtonText: 'Keep current code'
    }).then(r => { if (r.isConfirmed) pcRegenerateCode(); });
}

// Build the cascading parent selector when the Edit Account modal opens.
document.getElementById('pettyAccountModal').addEventListener('shown.bs.modal', function () {
    if (pcCascade) return;
    pcCascade = initParentCascade({
        container: 'pc_parentCascade', hidden: 'pc_parent_account_id',
        accounts: PC_PARENTS, category: 'asset',
        selected: document.getElementById('pc_parent_account_id').value || '',
        excludeId: PC_ACCOUNT_ID, onChange: pcParentChanged
    });
});

document.getElementById('pettyAccountForm').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]'); const orig = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';
    fetch('<?= getUrl("api/accounts/save_account") ?>', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Saved!', text: 'Petty cash account updated', timer: 1500, showConfirmButton: false })
                    .then(() => window.location.reload());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Failed to update account' });
                btn.disabled = false; btn.innerHTML = orig;
            }
        })
        .catch(() => { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); btn.disabled = false; btn.innerHTML = orig; });
});
</script>
<?php endif; ?>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}

.custom-stat-card:hover { 
    transform: translateY(-3px); 
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: #0f5132 !important;
    font-weight: 600;
}

.stat-icon-circle {
    width: 48px;
    height: 48px;
    background: rgba(15, 81, 50, 0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
}

.bg-success-soft {
    background-color: rgba(25, 135, 84, 0.1) !important;
}

.bg-info-soft {
    background-color: rgba(13, 202, 240, 0.1) !important;
}

@media print {
    /* Print stats cards in a single row */
    #print-stats-cards {
        display: flex !important;
        flex-direction: row !important;
        flex-wrap: nowrap !important;
        width: 100% !important;
        gap: 10px !important;
        margin-bottom: 20px !important;
    }
    #print-stats-cards > div {
        flex: 1 1 33.33% !important;
        max-width: 33.33% !important;
        width: 33.33% !important;
        margin-bottom: 0 !important;
    }
    .custom-stat-card {
        border: 1px solid #badbcc !important;
        background-color: #d1e7dd !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        height: auto !important;
    }
    .custom-stat-card .card-body { padding: 10px !important; }
    .stat-icon-circle { display: none !important; } /* Hide icons to save space on print */
}
}
</style>

<?php
includeFooter();
ob_end_flush();
?>
