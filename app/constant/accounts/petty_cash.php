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

try {
    // Fetch categories
    $catStmt = $pdo->query("SELECT * FROM account_categories ORDER BY category_name");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = $e->getMessage();
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
                <div>
                    <button class="btn btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#depositModal">
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
                    <select class="form-select" id="filter_category_id">
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
        </div>
        <div>
            <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill" id="total_records_badge">
                <i class="bi bi-check-circle-fill me-1"></i> 0 records
            </span>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
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
                        <label class="form-label fw-bold">Category (Source) <span class="text-danger">*</span></label>
                        <select class="form-select" name="category_id" id="deposit_category_id" required>
                            <option value="">Select Source/Category</option>
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
                        <!-- Category & Reference -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="category_id" id="expense_category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['category_id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
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

    document.addEventListener('DOMContentLoaded', function() {
        logReportAction('Viewed Petty Cash', 'User viewed the petty cash transactions list');
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
        
        if(page === 1) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>';
        }

        const url = `<?= getUrl('api/petty_cash/get_transactions.php') ?>?page=${page}&limit=${limit}&search=${encodeURIComponent(search)}&from_date=${from_date}&to_date=${to_date}&category_id=${category_id}&type=${type}`;

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
        tbody.innerHTML = '';

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
    document.getElementById(prefix + '_category_id').value = data.category_id || '';
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
