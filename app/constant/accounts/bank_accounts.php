<?php
// File: app/constant/accounts/bank_accounts.php
ob_start();

// Ensure database connection is available
global $pdo;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/account_balance.php';
require_once __DIR__ . '/../../../core/payment_source.php';   // bankCashAccountsForDisplay()

// Include the header and authentication
autoEnforcePermission('bank_accounts');

includeHeader();

$bank_accounts = [];
$total_balance = 0;
$active_count = 0;
$error = null;
$banksTableExists = false;
$account_types = [];
$parent_accounts = [];
$default_cash_parent_id = 0;

try {
    // Fetch account types for dropdown
    $typesStmt = $pdo->query("SELECT * FROM account_types ORDER BY type_name");
    $account_types = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Bank/Cash sub-types — the marker that makes an account a bank/cash account
    // (it sets cash_flow_category='cash' on save, so it appears here and in the
    // payment "Paid From" list). Degrades gracefully if the tier isn't migrated.
    $bank_sub_types = [];
    try {
        $bank_sub_types = $pdo->query("
            SELECT st.sub_type_id, st.name, st.code
              FROM account_sub_types st
              JOIN account_types at ON st.type_id = at.type_id
             WHERE at.category = 'asset' AND st.code IN ('bank','cash') AND st.status = 'active'
          ORDER BY st.display_order, st.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $bank_sub_types = [];
    }

    // Asset accounts available as a PARENT (group) for a new bank/cash account,
    // defaulting to "Cash On Hand" so the account nests correctly in the chart tree.
    $parent_accounts = $pdo->query("
        SELECT a.account_id, a.account_code, a.account_name, a.parent_account_id, at.category
          FROM accounts a JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.status = 'active' AND at.category = 'asset'
         ORDER BY a.account_code, a.account_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $default_cash_parent_id = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code = '1-1100' LIMIT 1")->fetchColumn() ?: 0);

    // Bank/cash accounts = the single "bank nature" marker (asset + cash_flow='cash'),
    // the SAME test the payment "Paid From" list uses. Keeps group headers (e.g.
    // "Cash On Hand") so the page shows the chart hierarchy. This guarantees: every
    // account here is in the Chart of Accounts (same row/code), and any chart account
    // tagged Sub Type = Bank/Cash (which sets cash_flow='cash') shows up here too.
    $bank_accounts = bankCashAccountsForDisplay($pdo);

    if (!empty($bank_accounts)) {
        // Ledger-true balances: own (leaf) and rolled-up (group incl. descendants),
        // so a header shows the total of its sub-accounts — exactly like the chart.
        $own    = ledgerBalanceMap($pdo);
        $incl   = ledgerRollupMap($pdo);
        foreach ($bank_accounts as &$ba) {
            $id = (int)$ba['account_id'];
            $ba['type_display']    = 'Bank / Cash';
            $ba['bank_name']       = $ba['account_name'];
            $ba['own_balance']     = $own[$id]  ?? 0.0;
            // Group header → rolled-up total; leaf → own balance.
            $ba['current_balance'] = ((int)$ba['has_children'] === 1) ? ($incl[$id] ?? 0.0) : ($own[$id] ?? 0.0);
        }
        unset($ba);

        // Stats — total across LEAF accounts only, so group subtotals don't double-count.
        $total_balance = 0.0;
        foreach ($bank_accounts as $ba) {
            if ((int)$ba['has_children'] === 0) $total_balance += (float)$ba['own_balance'];
        }
        $active_count = count(array_filter($bank_accounts, fn($a) => (int)$a['has_children'] === 0));
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="container-fluid py-4">
    <!-- Print Header (Branded) -->
    <div class="d-none d-print-block text-center mb-4">
       
        <h4 class="fw-bold text-dark text-uppercase">BANK & CASH ACCOUNTS REPORT</h4>
        <div style="border-bottom: 2px solid #000; width: 100px; margin: 15px auto;"></div>
        <p class="text-dark small">Printed on <?= date('d M, Y h:i A') ?></p>
    </div>

    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold"><i class="bi bi-bank me-2 text-primary"></i> Bank & Cash Accounts</h2>
                    <p class="text-muted">Manage your business bank accounts and cash balances</p>
                </div>
                <div>
                    <?php if (canCreate('bank_accounts')): ?>
                    <button class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                        <i class="bi bi-plus-circle me-1"></i> Add Bank Account
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-2"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

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
                            <i class="bi bi-wallet2"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Total Balance</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= format_currency($total_balance) ?></h4>
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
                            <i class="bi bi-check-circle-fill"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Active Accounts</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= number_format($active_count) ?></h4>
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
                            <i class="bi bi-building"></i>
                        </div>
                        <div class="overflow-hidden flex-grow-1">
                            <p class="small mb-0 opacity-75 text-uppercase text-nowrap overflow-hidden" style="text-overflow: ellipsis;">Total Accounts</p>
                            <h4 class="mb-0 fw-bold auto-resize text-nowrap"><?= count($bank_accounts) ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
            <h5 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2"></i>All Bank & Cash Accounts</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="bankAccountsTable">
                    <thead class="bg-light">
                        <tr>
                            <th style="width:75px;" class="ps-4 fw-bold">S/NO</th>
                            <th class="ps-4 fw-bold">Account Name</th>
                            <th class="fw-bold">Account Code</th>
                            <th class="fw-bold">Type</th>
                            <th class="text-end fw-bold">Balance</th>
                            <th class="text-center fw-bold">Status</th>
                            <th class="text-end pe-4 fw-bold d-print-none">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bank_accounts)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5">
                                <div class="text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-3 opacity-50"></i>
                                    <h5>No accounts found</h5>
                                    <p class="mb-3">Get started by creating your first bank or cash account</p>
                                    <?php if (canCreate('bank_accounts')): ?>
                                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                                        <i class="bi bi-plus-circle me-1"></i> Add Bank Account
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php $sn = 1; foreach ($bank_accounts as $account): ?>
                            <tr>
                                <td class="ps-4 text-center text-muted small fw-bold"><?= $sn++ ?></td>
                                <?php $isGroup = ((int)($account['has_children'] ?? 0) === 1);
                                      $indent = max(0, ((int)($account['level'] ?? 1) - 1)) * 22; ?>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center" style="padding-left: <?= $indent ?>px;">
                                        <div class="<?= $isGroup ? 'bg-warning' : 'bg-primary' ?> bg-opacity-10 rounded-circle p-2 me-3">
                                            <i class="bi <?= $isGroup ? 'bi-folder2 text-warning' : 'bi-bank text-primary' ?>"></i>
                                        </div>
                                        <div>
                                            <div class="<?= $isGroup ? 'fw-semibold' : 'fw-bold' ?> text-dark"><?= htmlspecialchars($account['account_name']) ?><?= !empty($account['is_system']) ? ' <i class="bi bi-lock-fill text-warning" title="System account — protected"></i>' : '' ?><?= $isGroup ? ' <span class="badge bg-light text-muted border ms-1" style="font-size:.6rem;">group</span>' : '' ?></div>
                                            <small class="text-muted"><?= $isGroup ? 'Subtotal incl. sub-accounts' : 'ID: ' . $account['account_id'] ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($account['account_code']) ?></code></td>
                                <td>
                                    <span class="badge bg-info bg-opacity-10 text-info">
                                        <?= htmlspecialchars($account['type_display'] ?: $account['account_type']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <span class="fw-bold fs-6 <?= $account['current_balance'] < 0 ? 'text-danger' : 'text-success' ?>">
                                        <?= format_currency($account['current_balance']) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge rounded-pill bg-<?= $account['status'] == 'active' ? 'success' : 'secondary' ?>">
                                        <?= ucfirst($account['status']) ?>
                                    </span>
                                </td>
                                <td class="text-end pe-4 d-print-none">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('transactions') ?>?account=<?= $account['account_id'] ?>">
                                                    <i class="bi bi-list-ul me-2 text-info"></i> View Transactions
                                                </a>
                                            </li>
                                            <?php if (canEdit('bank_accounts')): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editAccount(<?= $account['account_id'] ?>); return false;">
                                                    <i class="bi bi-pencil me-2" style="color: #0d6efd;"></i> Edit Account
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('accounts/account_details') ?>?account_id=<?= $account['account_id'] ?>">
                                                    <i class="bi bi-eye me-2 text-success"></i> View Details
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <!-- Spacer to prevent data hidden behind fixed footer in print -->
                        <tr class="d-none d-print-table-row" style="height: 100px; border: none !important;">
                            <td colspan="7" style="border: none !important;"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
    </div>
</div>

    

<!-- Professional Add Bank Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-plus-circle me-2"></i>Add New Bank Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addBankAccountForm" method="POST">
                <!-- Bank/cash accounts are tagged so they appear in payment dropdowns. -->
                <input type="hidden" name="cash_flow_category" value="cash">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Code <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control bg-light" id="add_account_code" name="account_code" readonly required placeholder="Auto-generating…">
                                <button type="button" class="btn btn-outline-secondary" onclick="generateBankCode()" title="Regenerate code"><i class="bi bi-arrow-clockwise"></i></button>
                            </div>
                            <small class="text-muted"><i class="bi bi-lock-fill"></i> Auto-generated from the parent — keeps the chart numbering consistent.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="account_name" required placeholder="e.g., CRDB Bank - Main Account">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Parent Account (group)</label>
                            <div id="add_parentCascade"></div>
                            <input type="hidden" id="add_parent_account_id" name="parent_account_id" value="">
                            <small class="text-muted">Leave as “None” for a top-level account, or pick a group and drill into sub-accounts (▸) — e.g. nest a bank under Cash On Hand.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="account_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($account_types as $type): ?>
                                <option value="<?= htmlspecialchars($type['type_name']) ?>" <?= $type['type_name'] == 'asset' ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($type['display_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Sub Type</label>
                            <?php if (!empty($bank_sub_types)): ?>
                            <select class="form-select" name="sub_type_id">
                                <?php foreach ($bank_sub_types as $st): ?>
                                <option value="<?= $st['sub_type_id'] ?>" <?= $st['code'] === 'bank' ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($st['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Bank or Cash — this is what marks it as a bank/cash account everywhere (chart, payments).</small>
                            <?php else: ?>
                            <input type="text" class="form-control bg-light" value="Bank / Cash" readonly>
                            <small class="text-muted">Tagged as a cash account automatically.</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Opening Balance</label>
                            <div class="input-group">
                                <span class="input-group-text">TSh</span>
                                <input type="number" class="form-control" name="opening_balance" step="0.01" value="0.00">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Optional notes about this account"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Professional Edit Bank Account Modal -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-pencil me-2"></i>Edit Account
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="editBankAccountForm" method="POST">
                <input type="hidden" id="edit_account_id" name="account_id">
                <div class="modal-body p-4">
                    <div id="bankSystemLockBanner" class="alert alert-warning py-2 px-3 d-none" role="alert">
                        <i class="bi bi-lock-fill me-1"></i> System account — its code, name and type are protected. You can still edit its description, status and opening balance.
                    </div>
                    <div id="bankAdminBanner" class="alert alert-info py-2 px-3 d-none" role="alert">
                        <i class="bi bi-shield-lock me-1"></i> System account — you are editing as <strong>admin</strong>. Code, name and type can be changed.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Code <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="edit_account_code" name="account_code" required>
                                <button type="button" class="btn btn-outline-secondary d-none" id="editBankRegenBtn" onclick="regenerateBankEditCode()" title="Regenerate code from parent"><i class="bi bi-arrow-clockwise"></i></button>
                            </div>
                            <small class="text-muted">Unique identifier for this account</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_account_name" name="account_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Account Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_account_type" name="account_type" required>
                                <option value="">Select Type</option>
                                <?php foreach ($account_types as $type): ?>
                                <option value="<?= htmlspecialchars($type['type_name']) ?>">
                                    <?= htmlspecialchars($type['display_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Parent Account (group)</label>
                            <div id="edit_parentCascade"></div>
                            <input type="hidden" id="edit_parent_account_id" name="parent_account_id" value="">
                            <small class="text-muted">Pick a group, then drill into sub-accounts (▸) to nest this account.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Sub Type</label>
                            <?php if (!empty($bank_sub_types)): ?>
                            <select class="form-select" id="edit_sub_type_id" name="sub_type_id">
                                <?php foreach ($bank_sub_types as $st): ?>
                                <option value="<?= $st['sub_type_id'] ?>"><?= htmlspecialchars($st['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Bank or Cash — marks it as a bank/cash account everywhere.</small>
                            <?php else: ?>
                            <input type="text" class="form-control bg-light" value="Bank / Cash" readonly>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Opening Balance</label>
                            <div class="input-group">
                                <span class="input-group-text">TSh</span>
                                <input type="number" class="form-control" id="edit_opening_balance" name="opening_balance" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status</label>
                            <select class="form-select" id="edit_status" name="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i> Update Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="<?= getUrl('assets/js/parent_cascade.js') ?>"></script>
<script>
// Asset accounts available as a parent (for the cascading Parent Account selector).
const BANK_PARENTS = <?= json_encode(array_map(fn($a) => ['id' => (int)$a['account_id'], 'code' => $a['account_code'], 'name' => $a['account_name'], 'parent' => ($a['parent_account_id'] !== null ? (int)$a['parent_account_id'] : null), 'category' => $a['category']], $parent_accounts)) ?>;
const BANK_DEFAULT_PARENT = <?= (int)$default_cash_parent_id ?>;
const BANK_IS_ADMIN = <?= json_encode(isAdmin()) ?>;
let addBankCascade = null, editBankCascade = null;

// Handle form submission
document.getElementById('addBankAccountForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Creating...';
    
    fetch('<?= getUrl("api/accounts/save_account") ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Bank account created successfully',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                logReportAction('Created Bank Account', 'User created a new bank account: ' + formData.get('account_name'));
                window.location.href = window.location.pathname + '?refresh=' + new Date().getTime();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'Failed to create account'
            });
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred. Please try again.'
        });
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

// Handle edit form submission
document.getElementById('editBankAccountForm').addEventListener('submit', function(e) {
    e.preventDefault();

    // Re-enable any locked fields so their unchanged values are still submitted
    ['edit_account_code', 'edit_account_name', 'edit_account_type'].forEach(id => { document.getElementById(id).disabled = false; });
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Updating...';
    
    fetch('<?= getUrl("api/accounts/save_account") ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: 'Account updated successfully',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                logReportAction('Updated Bank Account', 'User updated bank account: ' + formData.get('account_name'));
                window.location.href = window.location.pathname + '?refresh=' + new Date().getTime();
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message || 'Failed to update account'
            });
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred. Please try again.'
        });
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

// §UI-6: auto-suggest the next hierarchical account code from the chosen parent.
function generateBankCode() {
    const typeEl = document.querySelector('#addBankAccountForm [name="account_type"]');
    const type = (typeEl && typeEl.value) ? typeEl.value : 'asset';
    const parent = document.getElementById('add_parent_account_id').value || 0;
    $.getJSON('<?= buildUrl('api/account/get_next_account_code.php') ?>', { account_type: type, parent_account_id: parent })
        .done(res => { if (res && res.success && res.code) document.getElementById('add_account_code').value = res.code; });
}

// Edit form: regenerate the code to match a newly-chosen parent.
function regenerateBankEditCode() {
    const typeEl = document.getElementById('edit_account_type');
    const type = (typeEl && typeEl.value) ? typeEl.value : 'asset';
    const parent = document.getElementById('edit_parent_account_id').value || 0;
    $.getJSON('<?= buildUrl('api/account/get_next_account_code.php') ?>', { account_type: type, parent_account_id: parent })
        .done(res => { if (res && res.success && res.code) document.getElementById('edit_account_code').value = res.code; });
}

// Cascade onChange in Edit mode: offer to renumber the code to the new parent.
function bankEditParentChanged() {
    if (document.getElementById('edit_account_code').disabled) return;   // system account — code locked
    Swal.fire({
        icon: 'question',
        title: 'Renumber to match new parent?',
        text: 'Regenerate this account’s code so the number matches its new place in the tree? (Transactions are unaffected — they reference the account, not the code.)',
        showCancelButton: true, confirmButtonText: 'Yes, renumber', cancelButtonText: 'Keep current code'
    }).then(r => { if (r.isConfirmed) regenerateBankEditCode(); });
}

// Initialize DataTable
$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Bank Accounts', 'User viewed the list of bank and cash accounts');

    // Build the Add cascade when the modal opens; suggest a code from the chosen parent.
    document.getElementById('addAccountModal').addEventListener('shown.bs.modal', function () {
        addBankCascade = initParentCascade({
            container: 'add_parentCascade', hidden: 'add_parent_account_id',
            accounts: BANK_PARENTS, category: 'asset', selected: '',   // start at the top — don't force a deep level
            onChange: generateBankCode
        });
        if (!document.getElementById('add_account_code').value) generateBankCode();
    });
    // Account type change still re-suggests the code.
    $('#addBankAccountForm [name="account_type"]').on('change', generateBankCode);

    if (!$.fn.DataTable.isDataTable('#bankAccountsTable')) {
        $('#bankAccountsTable').DataTable({
            "order": [[ 0, "asc" ]],
            "pageLength": 10,
            "responsive": true,
            "destroy": true,
            dom: 'Bfrtip',
            buttons: [
                {
                    text: '<i class="bi bi-printer text-primary me-1"></i> Print List',
                    className: 'btn btn-light border btn-sm mb-3 shadow-sm px-3',
                    action: function (e, dt, node, config) {
                        window.print();
                    }
                }
            ]
        });
    }
});

function setBankFieldsLocked(isSystem) {
    const locked = isSystem && !BANK_IS_ADMIN;
    ['edit_account_code', 'edit_account_name', 'edit_account_type'].forEach(id => {
        document.getElementById(id).disabled = locked;
    });
    document.getElementById('bankSystemLockBanner').classList.toggle('d-none', !isSystem || BANK_IS_ADMIN);
    document.getElementById('bankAdminBanner').classList.toggle('d-none', !isSystem || !BANK_IS_ADMIN);
    document.getElementById('editBankRegenBtn').classList.toggle('d-none', locked);
}

function editAccount(id) {
    logReportAction('Initiated Bank Account Edit', 'User clicked edit for bank account ID #' + id);
    // Fetch account data and show edit modal
    fetch('<?= getUrl("api/accounts/get_account") ?>?account_id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.account) {
                const acc = data.account;
                
                // Populate form fields
                document.getElementById('edit_account_id').value = acc.account_id;
                document.getElementById('edit_account_code').value = acc.account_code;
                document.getElementById('edit_account_name').value = acc.account_name;
                document.getElementById('edit_account_type').value = acc.account_type;
                if (document.getElementById('edit_sub_type_id')) {
                    document.getElementById('edit_sub_type_id').value = acc.sub_type_id || '';
                }
                // Cascading parent selector, pre-drilled to this account's parent chain.
                // Initial render is programmatic (no prompt); user changes fire the renumber prompt.
                editBankCascade = initParentCascade({
                    container: 'edit_parentCascade', hidden: 'edit_parent_account_id',
                    accounts: BANK_PARENTS, category: 'asset',
                    selected: acc.parent_account_id || '', excludeId: acc.account_id,
                    onChange: bankEditParentChanged
                });
                document.getElementById('edit_opening_balance').value = acc.opening_balance;
                document.getElementById('edit_status').value = acc.status;
                document.getElementById('edit_description').value = acc.description || '';

                // System accounts: lock code/name/type (description/status stay editable)
                setBankFieldsLocked(parseInt(acc.is_system, 10) === 1);

                // Show modal
                const modal = new bootstrap.Modal(document.getElementById('editAccountModal'));
                modal.show();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to load account data'
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An error occurred while loading account data'
            });
        });
}

function viewTransactions(accountId) {
    window.location.href = "<?= getUrl('transactions') ?>?account=" + accountId;
}

function viewAccountDetails(accountId) {
    // Show account details in a modal or redirect to details page
    Swal.fire({
        title: 'Account Details',
        html: '<p class="text-muted">Loading account information...</p>',
        icon: 'info',
        showConfirmButton: true,
        confirmButtonText: 'Close'
    });
    
    // You can fetch and display detailed account info here
    fetch('<?= getUrl("api/accounts/get_account") ?>?account_id=' + accountId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.account) {
                const acc = data.account;
                Swal.fire({
                    title: acc.account_name,
                    html: `
                        <div class="text-start">
                            <p><strong>Account Code:</strong> ${acc.account_code}</p>
                            <p><strong>Type:</strong> ${acc.account_type}</p>
                            <p><strong>Current Balance:</strong> <span class="fw-bold ${acc.current_balance >= 0 ? 'text-success' : 'text-danger'}">${formatCurrency(acc.current_balance)}</span></p>
                            <p><strong>Opening Balance:</strong> ${formatCurrency(acc.opening_balance)}</p>
                            <p><strong>Status:</strong> <span class="badge bg-${acc.status === 'active' ? 'success' : 'secondary'}">${acc.status}</span></p>
                            ${acc.description ? `<p><strong>Description:</strong> ${acc.description}</p>` : ''}
                        </div>
                    `,
                    icon: 'info',
                    confirmButtonText: 'Close',
                    width: '600px'
                });
            }
        })
        .catch(error => {
            console.error('Error fetching account details:', error);
        });
}

function formatCurrency(amount) {
    return 'TSh ' + parseFloat(amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
    border-radius: 12px;
}
.custom-stat-card:hover { transform: translateY(-3px); }
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

@media print {
    .d-print-none, .btn, .dropdown, .modal, .toast-container, .card-header {
        display: none !important;
    }

    body {
        margin: 0 !important;
        padding: 0 !important;
        background: white !important;
    }

    .container-fluid {
        width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    /* Print Stats Cards layout */
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
    }
    .custom-stat-card {
        border: 1px solid #badbcc !important;
        background-color: #d1e7dd !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Table Print Styling */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 9pt !important;
    }
    th, td {
        border: 1px solid #333 !important;
        padding: 6px 8px !important;
    }
    thead { display: table-header-group !important; }
    tfoot { display: table-footer-group !important; }

    /* Fixed Branded Footer logic */
    .fixed-print-footer { 
        position: fixed; 
        bottom: 0; 
        left: 0; 
        right: 0; 
        width: 100%;
        text-align: center;
        background: white !important;
        padding-bottom: 10px;
        z-index: 9999;
        border-top: 1px solid #eee;
        display: block !important;
    }

    @page {
        margin: 10mm 10mm 20mm 10mm;
    }
}

.bg-gradient {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
</style>

    

<?php
includeFooter();
ob_end_flush();
?>
