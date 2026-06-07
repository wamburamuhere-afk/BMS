<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
autoEnforcePermission('chart_of_accounts');

// Fetch company settings for print
$c_logo = getSetting('company_logo', '');
$c_name = getSetting('company_name', 'BMS');

includeHeader();

// Fetch necessary data for dropdowns (categories and types)
try {
    // Fetch categories with type and account counts for the sidebar
    $categoriesQuery = "
        SELECT 
            ac.category_id,
            ac.category_name,
            at.type_name as category_type,
            ac.parent_category_id,
            ac.description,
            (SELECT COUNT(*) FROM accounts WHERE category_id = ac.category_id) as account_count
        FROM account_categories ac
        LEFT JOIN account_types at ON ac.account_type_id = at.type_id
        ORDER BY at.type_name, ac.category_name
    ";
    
    $categoriesStmt = $pdo->query($categoriesQuery);
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch account types
    $typesStmt = $pdo->query("SELECT * FROM account_types ORDER BY type_id");
    $accountTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Initial stats (can be updated via AJAX if needed, but keeping simple for now)
    $stats = $pdo->query("SELECT 
        COUNT(*) as total_accounts,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_accounts,
        SUM(current_balance) as total_balance
    FROM accounts")->fetch(PDO::FETCH_ASSOC);
    
    // Fetch all accounts for parent account dropdowns
    $accountsStmt = $pdo->query("SELECT a.account_id, a.account_code, a.account_name, a.level, at.category
                                   FROM accounts a
                                   LEFT JOIN account_types at ON a.account_type_id = at.type_id
                                  ORDER BY a.account_code");
    $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalCategories = $pdo->query("SELECT COUNT(*) FROM account_categories")->fetchColumn();

} catch (Exception $e) {
    error_log($e->getMessage());
    $categories = [];
    $accountTypes = [];
    $stats = ['total_accounts' => 0, 'active_accounts' => 0, 'total_balance' => 0];
    $totalCategories = 0;
}

?>

<div class="container-fluid py-4 px-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
       
        <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Chart of Accounts Report</h2>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">Generated on: <?= date('F j, Y, g:i a') ?></p>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-diagram-3"></i> Chart of Accounts</h2>
                    <p class="text-muted">Manage your accounting categories and accounts</p>
                </div>
                
            </div>
            </div>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Toast Container -->
    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
        <!-- Toast notifications will be inserted here -->
    </div>

    <div class="row">
        <div class="col-md-4">
            <!-- Categories Panel -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Account Categories</h5>
                    <?php if (canCreate('chart_of_accounts')): ?>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="resetCategoryForm()">
                        <i class="bi bi-folder-plus"></i> Add Category
                    </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <input type="text" id="categorySearch" class="form-control" placeholder="Search categories..." onkeyup="filterCategories()">
                    </div>
                    <div id="categoriesTree" class="categories-tree">
                        <?php if (count($categories) > 0): ?>
                            <div class="list-group">
                                <?php foreach ($categories as $category): ?>
                                    <div class="list-group-item category-item type-<?= strtolower($category['category_type'] ?? '') ?>" data-category-id="<?= $category['category_id'] ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-folder me-2"></i>
                                                <span class="category-name"><?= htmlspecialchars($category['category_name']) ?></span>
                                                <span class="badge bg-secondary ms-2 category-badge"><?= $category['account_count'] ?></span>
                                            </div>
                                            <?php if (canEdit('chart_of_accounts') || canDelete('chart_of_accounts')): ?>
                                            <div class="dropdown action-dropdown">
                                                <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-gear-fill"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <?php if (canEdit('chart_of_accounts')): ?>
                                                    <li>
                                                        <a class="dropdown-item" href="#" onclick="editCategory(<?= $category['category_id'] ?>)">
                                                            <i class="bi bi-pencil"></i> Edit Category
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                    <?php if (canDelete('chart_of_accounts')): ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <a class="dropdown-item text-danger" href="#" onclick="deleteCategory(<?= $category['category_id'] ?>, '<?= htmlspecialchars($category['category_name']) ?>')">
                                                            <i class="bi bi-trash"></i> Delete
                                                        </a>
                                                    </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-muted py-3">No categories found</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="card mt-3">
                <div class="card-header">
                    <h6 class="mb-0">Quick Stats</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="border rounded p-2">
                                <div class="h5 mb-1 text-primary" id="stat-total-accounts"><?= number_format($stats['total_accounts']) ?></div>
                                <small class="text-muted">Total Accounts</small>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="border rounded p-2">
                                <div class="h5 mb-1 text-success" id="stat-active-accounts"><?= number_format($stats['active_accounts']) ?></div>
                                <small class="text-muted">Active Accounts</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2">
                                <div class="h5 mb-1 text-info"><?= number_format($totalCategories) ?></div>
                                <small class="text-muted">Categories</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2">
                                <div class="h5 mb-1 text-warning" id="stat-total-balance"><?= number_format($stats['total_balance'], 2) ?></div>
                                <small class="text-muted">Total Balance</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <!-- Accounts Panel -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Accounts</h5>
                    <div>
                        <?php if (canCreate('chart_of_accounts')): ?>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#accountModal" onclick="resetAccountForm()">
                            <i class="bi bi-plus-circle"></i> Add Account
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Actions Bar -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="btn-group dropdown shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                            <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyAccountsTable()" style="background: #fff; color: #444;">
                                <i class="bi bi-clipboard text-info me-1"></i> Copy
                            </button>
                            <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                            <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportAccountsExcel()" style="background: #fff; color: #444;">
                                <i class="bi bi-file-earmark-excel text-success me-1"></i> Excel
                            </button>
                            <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                            <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printAccountsTable()" style="background: #fff; color: #444;">
                                <i class="bi bi-printer text-primary me-1"></i> Print
                            </button>
                        </div>
                    </div>

                    <!-- Type tabs — filter by canonical account_types.category -->
                    <ul class="nav nav-tabs coa-tabs mb-3 flex-nowrap d-print-none" style="overflow-x:auto; overflow-y:hidden; white-space:nowrap;">
                        <li class="nav-item"><a class="nav-link active" href="#" data-category="">All Accounts</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-category="asset">Asset</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-category="liability">Liability</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-category="equity">Equity</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-category="revenue">Income</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-category="cogs">Cost of Sales</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-category="expense">Expense</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-category="finance_cost">Finance Cost</a></li>
                    </ul>

                    <!-- Search and Filters (type now handled by the tabs above) -->
                    <div class="row mb-3 g-2">
                        <div class="col-md-6">
                            <select id="statusFilter" class="form-select form-select-sm">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="input-group input-group-sm">
                                <input type="text" id="customSearch" class="form-control" placeholder="Search accounts...">
                            </div>
                        </div>
                    </div>

                    <!-- Accounts Table -->
                    <div class="table-responsive">
                        <table id="accountsTable" class="table table-striped table-hover align-middle" style="width:100%">
                            <thead>
                                <tr>
                                    <th style="width:20px;"></th> <!-- Control Column -->
                                    <th style="width:50px;">S/NO</th>
                                    <th>Code</th>
                                    <th>Account Name</th>
                                    <th>Type</th>
                                    <th>Category</th>
                                    <th>Balance</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Loaded via DataTables -->
                            </tbody>
                            <tfoot>
                                <!-- Spacer to prevent data hidden behind fixed footer in print -->
                                <tr class="d-none d-print-table-row" style="height: 100px; border: none !important;">
                                    <td colspan="9" style="border: none !important;"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fixed Branded Print Footer (Employees Style) -->
    <div class="fixed-print-footer d-none d-print-block">
        <div class="mx-auto" style="width: 90%; border-top: 1px solid #eee; padding-top: 10px;">
            <p class="mb-0 text-muted" style="font-size: 10pt; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                 This document was Printed by <span class="fw-bold text-dark"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= ucwords($_SESSION['user_role'] ?? 'Staff') ?></span> on <span class="fw-bold text-dark"><?= date('d M, Y \a\t h:i A') ?></span>
            </p>
            <p class="mb-0 fw-bold text-primary" style="font-size: 10pt; letter-spacing: 0.5px; white-space: nowrap;">
                Powered By BJP Technologies  © <?= date('Y') ?>, All Rights Reserved
            </p>
        </div>
    </div>
</div>

<!-- DataTables CSS/JS -->
<link rel="stylesheet" href="/assets/css/dataTables.bootstrap5.min.css">
<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>

<!-- Add/Edit Account Modal -->
<div class="modal fade" id="accountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="accountModalTitle">Add New Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="accountForm" action="/api/account/save_account.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="account_id" name="account_id">

                    <div id="systemLockBanner" class="alert alert-warning py-2 px-3 d-none" role="alert">
                        <i class="bi bi-lock-fill me-1"></i> System account — its code, name and type are protected and cannot be changed. You can still edit its description, status and opening balance.
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="account_code" class="form-label">Account Code *</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" id="account_code" name="account_code" placeholder="Auto-generating…" required>
                                    <button type="button" class="btn btn-outline-secondary" id="btnGenCode" onclick="generateAccountCode()" title="Suggest next code"><i class="bi bi-arrow-clockwise"></i></button>
                                </div>
                                <div class="form-text">Auto-suggested from the class &amp; parent; editable.</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="account_type" class="form-label">Account Type *</label>
                                <select class="form-select select2-static" id="account_type" name="account_type" required>
                                    <option value="">Select Type</option>
                                    <?php foreach ($accountTypes as $type): ?>
                                        <option value="<?= htmlspecialchars($type['type_name']) ?>"><?= htmlspecialchars($type['display_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="account_name" class="form-label">Account Name *</label>
                        <input type="text" class="form-control" id="account_name" name="account_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="category_id" class="form-label">Category</label>
                        <select class="form-select select2-static" id="category_id" name="category_id">
                            <option value="">No Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['category_id'] ?>"><?= htmlspecialchars($category['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="opening_balance" class="form-label">Opening Balance</label>
                                <div class="input-group">
                                    <!-- removed TSh prefix -->
                                    <input type="number" step="0.01" class="form-control" id="opening_balance" name="opening_balance" value="0.00">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select select2-static" id="status" name="status">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="parent_account_id" class="form-label">Parent Account</label>
                                <select class="form-select" id="parent_account_id" name="parent_account_id">
                                    <option value="">— None (top-level account) —</option>
                                    <?php foreach ($accounts as $acc): ?>
                                        <option value="<?= $acc['account_id'] ?>"><?= htmlspecialchars($acc['account_code'] . ' - ' . $acc['account_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Leave as “None” for a top-level account. <span id="levelBadge" class="badge bg-secondary d-none"></span></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label d-block">Normal Balance *</label>
                                <div class="btn-group" role="group" aria-label="Normal balance">
                                    <input type="radio" class="btn-check" name="normal_balance" id="nb_debit" value="debit" autocomplete="off">
                                    <label class="btn btn-outline-primary btn-sm" for="nb_debit">Debit</label>
                                    <input type="radio" class="btn-check" name="normal_balance" id="nb_credit" value="credit" autocomplete="off">
                                    <label class="btn btn-outline-success btn-sm" for="nb_credit">Credit</label>
                                </div>
                                <div class="form-text">Auto-set from the account type; override if needed.</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                        Save Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="categoryModalTitle">Add New Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="categoryForm" action="/api/account/save_category.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" id="category_id" name="category_id">
                    
                    <div class="mb-3">
                        <label for="category_name" class="form-label">Category Name *</label>
                        <input type="text" class="form-control" id="category_name" name="category_name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="category_type" class="form-label">Category Type *</label>
                        <select class="form-control" id="category_type" name="category_type" required>
                            <option value="">Select Type</option>
                            <?php foreach ($accountTypes as $type): ?>
                                <option value="<?= htmlspecialchars($type['type_name']) ?>"><?= htmlspecialchars($type['display_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="category_description" class="form-label">Description</label>
                        <textarea class="form-control" id="category_description" name="category_description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="parent_category_id" class="form-label">Parent Category</label>
                        <select class="form-control" id="parent_category_id" name="parent_category_id">
                            <option value="">No Parent (Top Level)</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                        Save Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalTitle">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="deleteMessage">Are you sure you want to delete this item? This action cannot be undone.</p>
                <div id="deleteDetails" class="alert alert-warning d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteForm" method="POST" style="display: inline;">
                    <input type="hidden" id="delete_id" name="delete_id">
                    <button type="submit" class="btn btn-danger">
                        <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Account View Offcanvas (slide-in quick view) -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="accountViewOffcanvas" aria-labelledby="avTitle" style="width: 480px; max-width: 92vw;">
    <div class="offcanvas-header bg-light border-bottom">
        <div class="text-truncate">
            <h5 class="offcanvas-title mb-0 text-truncate" id="avTitle">Account</h5>
            <small class="text-muted" id="avSubtitle"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <ul class="nav nav-tabs nav-fill mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#avDetails" type="button">Details</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#avChildren" type="button">Sub-Accounts</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#avTxns" type="button">Transactions</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#avBalance" type="button">Balance</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="avDetails"></div>
            <div class="tab-pane fade" id="avChildren"></div>
            <div class="tab-pane fade" id="avTxns"></div>
            <div class="tab-pane fade" id="avBalance"></div>
        </div>
    </div>
</div>

<?php includeFooter(); ?>

<style>
.categories-tree {
    max-height: 500px;
    overflow-y: auto;
}

.categories-tree .list-group-item {
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    margin-bottom: 2px;
}

.categories-tree .list-group-item:hover {
    background-color: #f8f9fa;
}

.categories-tree .category-item {
    cursor: pointer;
    border-left: 3px solid transparent;
    transition: all 0.2s ease;
}

.categories-tree .category-item.active {
    border-left-color: #0d6efd;
    background-color: #e7f1ff;
}

.categories-tree .sub-category {
    margin-left: 1.5rem;
    font-size: 0.9rem;
}

.categories-tree .category-badge {
    font-size: 0.7rem;
}

.account-type-badge {
    font-size: 0.75rem;
}

.balance-positive {
    color: #198754;
    font-weight: bold;
}

.balance-negative {
    color: #dc3545;
    font-weight: bold;
}

.action-column {
    width: 100px;
    text-align: center;
}

.btn-group-sm > .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

/* Loading states */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

/* Type colors */
.type-asset { border-left-color: #0d6efd; }
.type-liability { border-left-color: #ffc107; }
.type-equity { border-left-color: #0dcaf0; }
.type-income { border-left-color: #198754; }
.type-expense { border-left-color: #dc3545; }

/* Responsive adjustments */
@media (max-width: 768px) {
    .categories-tree {
        max-height: 300px;
    }
    
    .card-header .btn {
        margin-top: 0.5rem;
    }
}

@media print {
    /* Hide unwanted elements */
    .d-print-none,
    .col-md-4,
    .btn,
    .card-header,
    .form-control,
    .form-select,
    .input-group,
    .pagination,
    .dataTables_info,
    .dataTables_length,
    .dataTables_paginate,
    .dataTables_processing,
    footer,
    nav,
    .toast-container,
    .modal,
    .dropdown-menu,
    .action-dropdown button,
    .card-header {
        display: none !important;
    }

    /* Show print header */
    .d-print-block {
        display: block !important;
    }

    /* Reset body and container */
    body {
        margin: 0 !important;
        padding: 0 !important;
        padding-top: 0 !important;
        background: white !important;
    }

    .container-fluid {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
    }

    /* Full width for table column */
    .col-md-8 {
        width: 100% !important;
        flex: 0 0 100% !important;
        max-width: 100% !important;
    }
    
    .row {
        margin: 0 !important;
    }
    
    /* Card styling */
    .card {
        border: none !important;
        box-shadow: none !important;
        margin: 0 !important;
    }

    .card-body {
        padding: 0 !important;
    }
    
    /* Table Styling for Print */
    #accountsTable {
        width: 100% !important;
        border-collapse: collapse !important;
        margin-top: 10px;
    }
    
    #accountsTable thead {
        display: table-header-group;
    }
    
    #accountsTable tbody {
        display: table-row-group;
    }

    tfoot {
        display: table-footer-group !important;
    }
    
    table {
        font-size: 9pt !important;
        width: 100% !important;
        border: 1px solid #333 !important;
    }
    
    th, td {
        border: 1px solid #333 !important;
        padding: 6px 8px !important;
        text-align: left;
    }
    
    th {
        background-color: #f8f9fa !important;
        color: #000 !important;
        font-weight: bold !important;
    }

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
</style>

<script>
const userPermissions = {
    canView: <?= canView('chart_of_accounts') ? 'true' : 'false' ?>,
    canEdit: <?= canEdit('chart_of_accounts') ? 'true' : 'false' ?>,
    canDelete: <?= canDelete('chart_of_accounts') ? 'true' : 'false' ?>
};

// Form helpers: account type_name → natural side / category, account_id → level,
// and the account list (with category) for the same-class parent picker.
const ACCOUNT_TYPE_SIDES = <?= json_encode(array_column($accountTypes, 'normal_side', 'type_name')) ?>;
const ACCOUNT_TYPE_CATEGORIES = <?= json_encode(array_column($accountTypes, 'category', 'type_name')) ?>;
const ACCOUNT_LEVELS = <?= json_encode(array_column($accounts, 'level', 'account_id')) ?>;
const ACCOUNTS_LIST = <?= json_encode(array_map(fn($a) => ['id' => (int)$a['account_id'], 'code' => $a['account_code'], 'name' => $a['account_name'], 'category' => $a['category']], $accounts)) ?>;

$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Chart of Accounts', 'User viewed the chart of accounts list');

    // Active type tab → canonical category sent to the API ('' = All Accounts).
    // Declared before the table so the very first AJAX load can read it.
    let currentCategory = '';

    const table = $('#accountsTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: '/api/account/get_chart_of_accounts.php',
            data: function(d) {
                d.category = currentCategory;
                d.status = $('#statusFilter').val();
                d.search.value = $('#customSearch').val();
            }
        },
        responsive: {
            details: {
                type: 'column',
                target: 0
            }
        },
        columns: [
            {
                data: null,
                orderable: false,
                className: 'dtr-control',
                defaultContent: ''
            },
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'text-center text-muted small fw-bold',
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1,
                responsivePriority: 1
            },
            { 
                data: 'account_code', 
                render: data => `<strong>${escapeHtml(data)}</strong>`,
                responsivePriority: 3
            },
            {
                data: 'account_name',
                render: (data, t, row) => {
                    const lvl  = parseInt(row.level || 1, 10);
                    const pad  = (lvl - 1) * 22;                       // indent by tree depth
                    const wt   = lvl === 1 ? 'fw-semibold' : (lvl === 2 ? 'fw-normal' : 'fw-light');
                    const lock = (parseInt(row.is_system, 10) === 1)
                        ? ' <i class="bi bi-lock-fill text-warning ms-1" title="System account — protected"></i>' : '';
                    const desc = row.description ? `<br><small class="text-muted">${escapeHtml(row.description)}</small>` : '';
                    return `<div style="padding-left:${pad}px;"><a href="#" class="text-reset text-decoration-none coa-view-link" onclick="openAccountView(${row.account_id}); return false;"><span class="${wt}">${escapeHtml(data)}</span></a>${lock}${desc}</div>`;
                },
                responsivePriority: 2
            },
            {
                data: 'account_type',
                render: (data, t, row) => {
                    const side = (row.normal_balance || '').toLowerCase();
                    const pill = side === 'credit'
                        ? ' <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:.62rem;">Cr</span>'
                        : (side === 'debit'
                            ? ' <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:.62rem;">Dr</span>'
                            : '');
                    return `<span>${escapeHtml(data || '')}</span>${pill}`;
                },
                responsivePriority: 10
            },
            { 
                data: 'category_name',
                render: data => data ? `<span>${escapeHtml(data)}</span>` : '<span class="text-muted">-</span>',
                responsivePriority: 11
            },
            {
                data: 'current_balance',
                render: (data, t, row) => {
                    const own  = parseFloat(data);
                    const incl = parseFloat(row.balance_incl != null ? row.balance_incl : data);
                    const cls  = incl >= 0 ? 'balance-positive' : 'balance-negative';
                    if (parseInt(row.has_children, 10) === 1) {
                        // Parent row → rolled-up total (incl. sub-accounts), own balance beneath
                        return `<span class="${cls}" title="Includes sub-accounts">${formatCurrency(incl)}</span>`
                             + `<br><small class="text-muted" title="This account's own balance">own: ${formatCurrency(own)}</small>`;
                    }
                    return `<span class="${cls}">${formatCurrency(own)}</span>`;
                },
                responsivePriority: 12
            },
            { 
                data: 'status',
                render: data => `<span>${data.charAt(0).toUpperCase() + data.slice(1)}</span>`,
                responsivePriority: 13
            },
            {
                data: null,
                orderable: false,
                className: 'text-end d-print-none',
                responsivePriority: 100, // Move to the bottom/inside expansion on mobile
                render: (data, t, row) => {
                    const locked = parseInt(row.is_system, 10) === 1;   // system account → no edit/delete
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear-fill"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">`;

                    if (userPermissions.canView) {
                        html += `<li><a class="dropdown-item" href="<?= getUrl('accounts/account_details') ?>?account_id=${row.account_id}"><i class="bi bi-eye"></i> View Details</a></li>`;
                    }

                    if (userPermissions.canEdit) {
                        // Edit stays available for system accounts (to change description/
                        // status); the form + server lock the code/name/type fields.
                        html += `<li><a class="dropdown-item" href="#" onclick="editAccount(${row.account_id})"><i class="bi bi-pencil"></i> Edit Account</a></li>`;
                    }

                    if (userPermissions.canDelete && !locked) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteAccount(${row.account_id}, '${escapeHtml(row.account_name)}')"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }

                    if (locked) {
                        html += `<li><span class="dropdown-item-text text-muted small"><i class="bi bi-lock-fill"></i> System account — protected</span></li>`;
                    }

                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        dom: 'rtip',
        pageLength: 50,
        ordering: false,   // fixed tree order (each account beneath its parent); set server-side
        drawCallback: function() {
            this.api().responsive.recalc();
        }
    });

    // Forced adjustment for responsiveness
    setTimeout(() => { if (table) table.columns.adjust().responsive.recalc(); }, 300);



    // Type tabs → set the active category, then reload the table
    $('.coa-tabs .nav-link').on('click', function (e) {
        e.preventDefault();
        $('.coa-tabs .nav-link').removeClass('active');
        $(this).addClass('active');
        currentCategory = $(this).data('category') || '';
        table.draw();
    });

    // Custom Filters
    $('#statusFilter').on('change', () => table.draw());
    $('#customSearch').on('keyup', () => table.draw());

    // Add/Edit form: auto-set Normal Balance from the chosen Account Type; show level on parent change
    $('#account_type').on('change', function () {
        const tn = $(this).val();
        const side = ACCOUNT_TYPE_SIDES[tn] || '';
        $('#nb_debit').prop('checked', side === 'debit');
        $('#nb_credit').prop('checked', side === 'credit');
        rebuildParentOptions(ACCOUNT_TYPE_CATEGORIES[tn] || '');   // only same-class parents
        if (isAddMode()) generateAccountCode();                    // re-suggest code for the new class
    });
    $('#parent_account_id').on('change', function () {
        updateLevelBadge();
        if (isAddMode()) generateAccountCode();                    // re-suggest code under the new parent
    });

    // §UI-3: Select2 on every DB-backed select in the modal (degrades gracefully).
    $('#accountModal').on('shown.bs.modal', function () {
        if (!$.fn.select2) return;
        const modal = $(this);
        modal.find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: modal, placeholder: 'Select...', allowClear: true, width: '100%' });
            }
        });
        if (!$('#parent_account_id').hasClass('select2-hidden-accessible')) {
            $('#parent_account_id').select2({ theme: 'bootstrap-5', dropdownParent: modal, placeholder: '— None (top-level account) —', allowClear: true, width: '100%' });
        }
        // §UI-6: refresh button on Add only; suggest a code when adding a fresh account
        const adding = isAddMode();
        $('#btnGenCode').toggleClass('d-none', !adding);
        if (adding && !$('#account_code').val()) generateAccountCode();
    });

    // Auto-edit if ID is in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('edit')) {
        const editId = urlParams.get('edit');
        setTimeout(() => editAccount(editId), 500);
    }
    // Add a sub-account under a given parent (from the account detail page)
    if (urlParams.has('add_child')) {
        const parentId = urlParams.get('add_child');
        setTimeout(() => addSubAccountFor(parentId), 400);
    }
});

function copyAccountsTable() {
    logReportAction('Copied Chart of Accounts', 'User copied chart of accounts table to clipboard');
    // Since it's server-side processing, we can only copy visible rows easily or fetch all.
    // Basic implementation for visible rows:
    const table = document.getElementById('accountsTable');
    if (!table) return;
    
    // Create a range to select the table
    const range = document.createRange();
    range.selectNode(table);
    
    // Select the content
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    
    try {
        // Execute copy command
        document.execCommand('copy');
        
        logReportAction('Copied Chart of Accounts Table', 'User copied chart of accounts list to clipboard');
        
        Swal.fire({ 
            icon: 'success', 
            title: 'Copied!', 
            text: 'Table copied to clipboard', 
            timer: 1500, 
            showConfirmButton: false 
        });
    } catch (err) {
        console.error('Copy failed', err);
        Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to copy table' });
    }
    
    // Clear selection
    window.getSelection().removeAllRanges();
}

function exportAccountsExcel() {
    logReportAction('Exported Chart of Accounts', 'User exported chart of accounts list to CSV/Excel');
    // Simple CSV export for visible rows + headers
    let csv = [];
    const table = document.getElementById('accountsTable');
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        let row = [], cols = rows[i].querySelectorAll("td, th");
        
        // Skip specific columns if needed (e.g., Action column is last)
        for (let j = 0; j < cols.length - 1; j++) {
            // Clean content: remove HTML tags, etc.
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        
        csv.push(row.join(","));
    }

    const csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    const downloadLink = document.createElement("a");
    downloadLink.download = "Chart_of_Accounts.csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
}

function printAccountsTable() {
    logReportAction('Printed Chart of Accounts', 'User generated a printed chart of accounts report');
    // Basic window print trigger
    window.print();
}

function getBadgeClass(type) {
    type = type.toLowerCase();
    if (type.includes('asset')) return 'primary';
    if (type.includes('liability')) return 'warning';
    if (type.includes('equity')) return 'info';
    if (type.includes('income') || type.includes('revenue')) return 'success';
    if (type.includes('expense')) return 'danger';
    return 'secondary';
}

function formatCurrency(v) {
    return parseFloat(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(text) {
    return $('<div>').text(text).html();
}

// ── Account View offcanvas (Phase 9) ────────────────────────────────────────
function openAccountView(accountId) {
    logReportAction('Viewed Account Detail', 'User opened the quick-view panel for account #' + accountId);
    const el = document.getElementById('accountViewOffcanvas');
    const oc = bootstrap.Offcanvas.getOrCreateInstance(el);
    ['avDetails', 'avChildren', 'avTxns', 'avBalance'].forEach(id => {
        document.getElementById(id).innerHTML = '<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span> Loading…</div>';
    });
    document.getElementById('avTitle').textContent = 'Account';
    document.getElementById('avSubtitle').textContent = '';
    oc.show();

    fetch('/api/account/get_account_detail.php?account_id=' + accountId)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                document.getElementById('avDetails').innerHTML = `<div class="alert alert-danger">${escapeHtml(data.message || 'Failed to load account')}</div>`;
                return;
            }
            renderAccountView(data);
        })
        .catch(() => {
            document.getElementById('avDetails').innerHTML = '<div class="alert alert-danger">Network error loading account.</div>';
        });
}

function renderAccountView(data) {
    const a = data.account, b = data.balances || {};

    document.getElementById('avTitle').textContent = a.account_name;
    document.getElementById('avSubtitle').textContent = a.account_code + ' · ' + (a.type_display || a.account_type || '');

    // Details
    const sidePill = (a.normal_balance === 'credit')
        ? '<span class="badge bg-success-subtle text-success">Credit</span>'
        : (a.normal_balance === 'debit' ? '<span class="badge bg-primary-subtle text-primary">Debit</span>' : '<span class="text-muted">—</span>');
    const sysPill = (parseInt(a.is_system, 10) === 1) ? ' <span class="badge bg-warning text-dark"><i class="bi bi-lock-fill"></i> System</span>' : '';
    const parent = a.parent_account_id
        ? `<a href="#" onclick="openAccountView(${a.parent_account_id}); return false;">${escapeHtml((a.parent_code || '') + ' ' + (a.parent_name || ''))}</a>`
        : '<span class="text-muted">None (top-level)</span>';
    document.getElementById('avDetails').innerHTML = `
        <table class="table table-sm mb-0">
            <tr><th class="text-muted" style="width:42%">Code</th><td>${escapeHtml(a.account_code)}</td></tr>
            <tr><th class="text-muted">Name</th><td>${escapeHtml(a.account_name)}${sysPill}</td></tr>
            <tr><th class="text-muted">Type</th><td>${escapeHtml(a.type_display || a.account_type || '—')}</td></tr>
            <tr><th class="text-muted">Category</th><td>${escapeHtml(a.category || '—')}</td></tr>
            <tr><th class="text-muted">Level</th><td>${parseInt(a.level || 1, 10)}</td></tr>
            <tr><th class="text-muted">Parent</th><td>${parent}</td></tr>
            <tr><th class="text-muted">Normal balance</th><td>${sidePill}</td></tr>
            <tr><th class="text-muted">Status</th><td>${escapeHtml(a.status)}</td></tr>
            <tr><th class="text-muted">Description</th><td>${a.description ? escapeHtml(a.description) : '<span class="text-muted">—</span>'}</td></tr>
        </table>`;

    // Sub-accounts
    let childHtml;
    if (data.children && data.children.length) {
        childHtml = '<div class="list-group list-group-flush">' + data.children.map(c =>
            `<a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="openAccountView(${c.account_id}); return false;">
                <span>${escapeHtml(c.account_code)} — ${escapeHtml(c.account_name)}</span>
                <span class="fw-semibold">${formatCurrency(parseFloat(c.current_balance || 0))}</span>
             </a>`).join('') + '</div>';
    } else {
        childHtml = '<div class="text-muted py-3 text-center">No sub-accounts.</div>';
    }
    if (userPermissions.canEdit && parseInt(a.is_system, 10) !== 1) {
        childHtml += `<button class="btn btn-sm btn-outline-primary mt-3" onclick="addSubAccountFor(${a.account_id})"><i class="bi bi-plus-circle"></i> Add sub-account</button>`;
    }
    document.getElementById('avChildren').innerHTML = childHtml;

    // Transactions
    let txHtml;
    if (data.transactions && data.transactions.length) {
        txHtml = '<div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Date</th><th>Ref / Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody>' +
            data.transactions.map(t => `<tr>
                <td class="text-nowrap">${escapeHtml(t.entry_date || '')}</td>
                <td>${escapeHtml(t.reference_number || '')}<br><small class="text-muted">${escapeHtml(t.item_desc || t.entry_desc || '')}</small></td>
                <td class="text-end">${t.type === 'debit' ? formatCurrency(parseFloat(t.amount)) : ''}</td>
                <td class="text-end">${t.type === 'credit' ? formatCurrency(parseFloat(t.amount)) : ''}</td>
             </tr>`).join('') + '</tbody></table></div><small class="text-muted">Most recent 50 posted lines.</small>';
    } else {
        txHtml = '<div class="text-muted py-3 text-center">No posted transactions.</div>';
    }
    document.getElementById('avTxns').innerHTML = txHtml;

    // Balance check
    const warn = b.in_sync ? '' : '<div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle"></i> Stored balance differs from the ledger-calculated balance.</div>';
    document.getElementById('avBalance').innerHTML = `
        ${warn}
        <table class="table table-sm mb-0">
            <tr><th class="text-muted">Opening balance</th><td class="text-end">${formatCurrency(b.opening_balance || 0)}</td></tr>
            <tr><th class="text-muted">Total debits (posted)</th><td class="text-end">${formatCurrency(b.total_debit || 0)}</td></tr>
            <tr><th class="text-muted">Total credits (posted)</th><td class="text-end">${formatCurrency(b.total_credit || 0)}</td></tr>
            <tr class="table-light"><th>Calculated balance</th><td class="text-end fw-bold">${formatCurrency(b.calculated_balance || 0)}</td></tr>
            <tr><th class="text-muted">Stored balance</th><td class="text-end">${formatCurrency(b.current_balance || 0)}</td></tr>
        </table>
        <small class="text-muted">Natural side: ${escapeHtml(b.normal_side || '—')}.</small>`;
}

// Open the Add modal pre-filled with this account as the parent
function addSubAccountFor(parentId) {
    bootstrap.Offcanvas.getInstance(document.getElementById('accountViewOffcanvas'))?.hide();
    resetAccountForm();
    $('#parent_account_id').val(parentId).trigger('change');   // Select2-safe; also updates level badge
    new bootstrap.Modal(document.getElementById('accountModal')).show();
}

function filterCategories() {
    const searchTerm = document.getElementById('categorySearch').value.toLowerCase();
    const items = document.querySelectorAll('.category-item');
    
    items.forEach(item => {
        const categoryName = item.querySelector('.category-name').textContent.toLowerCase();
        if (categoryName.includes(searchTerm)) {
            item.style.display = '';
        } else {
            item.style.display = 'none';
        }
    });
}

function updateLevelBadge() {
    const pid = document.getElementById('parent_account_id').value;
    const badge = document.getElementById('levelBadge');
    const lvl = (pid && ACCOUNT_LEVELS[pid]) ? (parseInt(ACCOUNT_LEVELS[pid], 10) + 1) : 1;
    badge.textContent = 'Level ' + lvl;
    badge.classList.remove('d-none');
}

// Add mode = no account_id loaded into the form (Edit loads one).
function isAddMode() {
    return !document.getElementById('account_id').value;
}

// §UI-6: suggest the next Account Code (hierarchical) from the chosen class + parent.
function generateAccountCode() {
    const type   = document.getElementById('account_type').value;
    const parent = document.getElementById('parent_account_id').value;
    if (!type && !parent) return;                       // nothing to base it on yet
    $.getJSON('<?= buildUrl('api/account/get_next_account_code.php') ?>',
        { account_type: type, parent_account_id: parent || 0 })
        .done(res => { if (res.success && res.code) document.getElementById('account_code').value = res.code; });
}

// Rebuild the parent dropdown so it only offers accounts of the SAME class
// (category) — assets under assets, liabilities under liabilities, … This makes
// the picker mirror the same-class rule the server enforces. Keeps the current
// selection if it is still valid for the new class.
function rebuildParentOptions(category) {
    const sel = document.getElementById('parent_account_id');
    const prev = sel.value;
    let html = '<option value="">— None (top-level account) —</option>';
    let prevValid = false;
    ACCOUNTS_LIST.forEach(a => {
        if (!category || a.category === category) {
            html += '<option value="' + a.id + '">' + escapeHtml(a.code + ' - ' + a.name) + '</option>';
            if (String(a.id) === String(prev)) prevValid = true;
        }
    });
    sel.innerHTML = html;
    sel.value = prevValid ? prev : '';
    if ($.fn.select2 && $(sel).hasClass('select2-hidden-accessible')) {
        $(sel).val(sel.value).trigger('change.select2');
    }
    updateLevelBadge();
}

function setAccountFieldsLocked(locked) {
    ['account_code', 'account_name', 'account_type'].forEach(id => {
        document.getElementById(id).disabled = locked;
    });
    document.getElementById('systemLockBanner').classList.toggle('d-none', !locked);
}

function resetAccountForm() {
    document.getElementById('accountForm').reset();
    document.getElementById('account_id').value = '';
    document.getElementById('accountModalTitle').textContent = 'Add New Account';
    document.getElementById('nb_debit').checked = false;
    document.getElementById('nb_credit').checked = false;
    rebuildParentOptions('');                            // all classes until a type is chosen
    document.getElementById('levelBadge').classList.add('d-none');
    setAccountFieldsLocked(false);
}

function resetCategoryForm() {
    document.getElementById('categoryForm').reset();
    document.getElementById('category_id').value = '';
    document.getElementById('categoryModalTitle').textContent = 'Add New Category';
}

function editAccount(accountId) {
    logReportAction('Initiated Account Edit', 'User clicked edit for account ID #' + accountId);
    fetch('/api/account/get_account.php?account_id=' + accountId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const account = data.account;
                resetAccountForm();                       // clean slate: unlock fields, clear radios
                document.getElementById('account_id').value = account.account_id;
                document.getElementById('account_code').value = account.account_code;
                document.getElementById('account_name').value = account.account_name;
                document.getElementById('account_type').value = account.account_type;
                document.getElementById('category_id').value = account.category_id || '';
                document.getElementById('description').value = account.description || '';
                document.getElementById('opening_balance').value = account.opening_balance;
                document.getElementById('status').value = account.status;
                rebuildParentOptions(account.category || '');                                     // same-class parents only
                $('#parent_account_id').val(account.parent_account_id || '').trigger('change');   // Select2-safe

                // Normal balance radio
                if (account.normal_balance === 'credit') document.getElementById('nb_credit').checked = true;
                else if (account.normal_balance === 'debit') document.getElementById('nb_debit').checked = true;

                updateLevelBadge();

                // System accounts: lock code/name/type (description/status stay editable)
                setAccountFieldsLocked(parseInt(account.is_system, 10) === 1);

                document.getElementById('accountModalTitle').textContent = 'Edit Account';
                new bootstrap.Modal(document.getElementById('accountModal')).show();
            }
        });
}

function editCategory(categoryId) {
    logReportAction('Initiated Category Edit', 'User clicked edit for account category ID #' + categoryId);
    fetch('/api/account/get_account_category.php?category_id=' + categoryId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const category = data.category;
                document.getElementById('category_id').value = category.category_id;
                document.getElementById('category_name').value = category.category_name;
                document.getElementById('category_type').value = category.category_type;
                document.getElementById('category_description').value = category.description || '';
                document.getElementById('parent_category_id').value = category.parent_category_id || '';
                
                document.getElementById('categoryModalTitle').textContent = 'Edit Category';
                new bootstrap.Modal(document.getElementById('categoryModal')).show();
            }
        });
}

function deleteAccount(accountId, accountName) {
    logReportAction('Initiated Account Deletion', 'User clicked delete for account ' + accountName);
    document.getElementById('deleteModalTitle').textContent = 'Delete Account';
    document.getElementById('deleteMessage').textContent = `Are you sure you want to delete the account "${accountName}"?`;
    document.getElementById('delete_id').value = accountId;
    document.getElementById('deleteForm').action = '/api/account/delete_account.php';
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function deleteCategory(categoryId, categoryName) {
    logReportAction('Initiated Category Deletion', 'User clicked delete for account category ' + categoryName);
    document.getElementById('deleteModalTitle').textContent = 'Delete Category';
    document.getElementById('deleteMessage').textContent = `Are you sure you want to delete the category "${categoryName}"?`;
    document.getElementById('delete_id').value = categoryId;
    document.getElementById('deleteForm').action = '/api/account/delete_account_category.php';
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

// Handle form submissions via AJAX
document.getElementById('accountForm').addEventListener('submit', function(e) {
    e.preventDefault();
    // Re-enable any locked (disabled) fields so their unchanged values are submitted
    ['account_code', 'account_name', 'account_type'].forEach(id => { document.getElementById(id).disabled = false; });
    const formData = new FormData(this);
    const saveBtn = this.querySelector('button[type="submit"]');
    const spinner = saveBtn.querySelector('.spinner-border');
    
    saveBtn.disabled = true;
    spinner.classList.remove('d-none');
    
    fetch(this.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // §UI-2/4: hide modal + redraw the AJAX table (no full page reload), then SweetAlert
            bootstrap.Modal.getInstance(document.getElementById('accountModal'))?.hide();
            $('#accountsTable').DataTable().ajax.reload(null, false);
            Swal.fire({ icon: 'success', title: 'Saved!', text: data.message || 'Account saved', timer: 2000, showConfirmButton: false });
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Error saving account' });
        }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' }))
    .finally(() => { saveBtn.disabled = false; spinner.classList.add('d-none'); });
});

document.getElementById('categoryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const saveBtn = this.querySelector('button[type="submit"]');
    const spinner = saveBtn.querySelector('.spinner-border');

    saveBtn.disabled = true;
    spinner.classList.remove('d-none');

    fetch(this.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('categoryModal'))?.hide();
            // Category sidebar is server-rendered → refresh after the toast.
            Swal.fire({ icon: 'success', title: 'Saved!', text: data.message || 'Category saved', timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Error saving category' });
            saveBtn.disabled = false;
            spinner.classList.add('d-none');
        }
    })
    .catch(() => { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); saveBtn.disabled = false; spinner.classList.add('d-none'); });
});

document.getElementById('deleteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const deleteBtn = this.querySelector('button[type="submit"]');
    const spinner = deleteBtn.querySelector('.spinner-border');

    deleteBtn.disabled = true;
    spinner.classList.remove('d-none');

    fetch(this.action, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('deleteModal'))?.hide();
            Swal.fire({ icon: 'success', title: 'Deleted!', text: data.message || 'Deleted', timer: 1500, showConfirmButton: false })
                .then(() => location.reload());
        } else {
            Swal.fire({ icon: 'error', title: 'Error', text: data.message || 'Error deleting item' });
            deleteBtn.disabled = false;
            spinner.classList.add('d-none');
        }
    })
    .catch(() => { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); deleteBtn.disabled = false; spinner.classList.add('d-none'); });
});
</script>
