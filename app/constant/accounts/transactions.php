<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
autoEnforcePermission('transactions');

includeHeader();

// Fetch transactions (journal entries) with related data
$stmt = $pdo->query("
    SELECT 
        je.*,
        u.username as created_by_name,
        (SELECT COALESCE(SUM(amount), 0) FROM journal_entry_items WHERE entry_id = je.entry_id AND type = 'debit') as total_amount
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.user_id
    ORDER BY je.entry_date DESC, je.created_at DESC
");
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch accounts for filters and modal
$accounts = $pdo->query("
    SELECT ca.*, at.type_name as account_type 
    FROM accounts ca 
    LEFT JOIN account_types at ON ca.account_type_id = at.type_id 
    WHERE ca.status = 'active' 
    ORDER BY ca.account_name
")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_transactions = 0;
$current_month_total = 0;
$current_year_total = 0;
$current_month = date('Y-m');
$current_year = date('Y');

foreach ($transactions as $transaction) {
    $amount = $transaction['total_amount'];
    $total_transactions += $amount;
    
    if (date('Y-m', strtotime($transaction['entry_date'])) === $current_month) {
        $current_month_total += $amount;
    }
    
    if (date('Y', strtotime($transaction['entry_date'])) === $current_year) {
        $current_year_total += $amount;
    }
}


?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-arrow-left-right"></i> Transactions Management</h2>
                    <p class="text-muted mb-0">Track and manage all financial transactions</p>
                </div>
                <div>
                    <?php if (canCreate('transactions')): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                        <i class="bi bi-plus-circle"></i> Add New Transaction
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    


    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-bold mb-1 opacity-75">Total Volume</div>
                    <div class="h4 mb-0 fw-bold"><?= format_currency($total_transactions) ?></div>
                    <div class="mt-2 small opacity-75">All recorded transactions</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-bold mb-1 opacity-75">This Month</div>
                    <div class="h4 mb-0 fw-bold"><?= format_currency($current_month_total) ?></div>
                    <div class="mt-2 small opacity-75"><?= date('F Y') ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-bold mb-1 opacity-75">This Year</div>
                    <div class="h4 mb-0 fw-bold"><?= format_currency($current_year_total) ?></div>
                    <div class="mt-2 small opacity-75">Fiscal Year <?= date('Y') ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-uppercase small fw-bold mb-1 opacity-75">Total Records</div>
                    <div class="h4 mb-0 fw-bold"><?= number_format(count($transactions)) ?></div>
                    <div class="mt-2 small opacity-75">Transaction count</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="draft">Draft</option>
                        <option value="posted">Posted</option>
                        <option value="void">Void</option>
                        <option value="reversed">Reversed</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" id="dateFromFilter">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" id="dateToFilter">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </button>
                    <button type="button" class="btn btn-primary" onclick="applyFilters()">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions & Table Controls -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <!-- Buttons Group -->
            <div class="btn-group dropdown shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="copyTable()" style="background: #fff; color: #444;">
                    <i class="bi bi-clipboard text-info me-1"></i> Copy
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportExcel()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-excel text-success me-1"></i> Excel
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printTable()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
            </div>
            
            <!-- Page Length -->
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <span class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</span>
                <select class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#transactionsTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>

            <!-- Custom Search -->
            <div class="input-group input-group-sm shadow-sm" style="width: 250px; border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                <span class="input-group-text bg-white border-0"><i class="bi bi-search text-muted"></i></span>
                <input type="text" class="form-control border-0 p-2" id="customTableSearch" placeholder="Search transactions..." onkeyup="$('#transactionsTable').DataTable().search(this.value).draw();">
            </div>
        </div>
        <div>
            <span class="badge bg-success-soft text-success border border-success px-3 py-2 fs-6 rounded-pill">
                <i class="bi bi-check-circle-fill me-1"></i> <?= count($transactions) ?> records
            </span>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">All Transactions</h5>
                <div class="d-flex">
                    <span class="badge bg-light text-dark me-2">
                        <?= count($transactions) ?> records
                    </span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($transactions) > 0): ?>
                <div class="table-responsive">
                    <table id="transactionsTable" class="table table-striped table-hover w-100">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Reference</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td>
                                    <strong><?= date('M d, Y', strtotime($transaction['entry_date'])) ?></strong>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= safe_output($transaction['description']) ?></strong>
                                        <?php if (!empty($transaction['notes'])): ?>
                                        <br>
                                        <small class="text-muted"><?= safe_output(substr($transaction['notes'], 0, 50)) ?>...</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <code><?= safe_output($transaction['reference_number']) ?></code>
                                </td>
                                <td>
                                    <strong><?= format_currency($transaction['total_amount']) ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-<?= get_status_badge($transaction['status']) ?>">
                                        <?= ucfirst($transaction['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= safe_output($transaction['created_by_name']) ?>
                                    <br>
                                    <small class="text-muted"><?= date('M d', strtotime($transaction['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="dropdown action-dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('transaction/view') ?>?id=<?= $transaction['entry_id'] ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <?php if ($transaction['status'] === 'draft'): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editTransaction(<?= $transaction['entry_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit Transaction
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="updateStatus(<?= $transaction['entry_id'] ?>, 'posted')">
                                                    <i class="bi bi-check-circle"></i> Post
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($transaction['status'] === 'posted'): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="updateStatus(<?= $transaction['entry_id'] ?>, 'reversed')">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Reverse
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= $transaction['entry_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-arrow-left-right" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Transactions Found</h4>
                    <p class="text-muted">Get started by recording your first transaction.</p>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addTransactionModal">
                        <i class="bi bi-plus-circle"></i> Add Your First Transaction
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Transaction Modal -->
<div class="modal fade" id="addTransactionModal" tabindex="-1" aria-labelledby="addTransactionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addTransactionModalLabel">
                    <i class="bi bi-plus-circle"></i> Add New Transaction
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addTransactionForm">
                <div class="modal-body">
                    <div id="add-transaction-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="entry_date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="debit_account_id" class="form-label">Debit Account <span class="text-danger">*</span></label>
                            <select class="form-select" id="debit_account_id" name="debit_account_id" required>
                                <option value="">Select Debit Account</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= $account['account_id'] ?>"><?= safe_output($account['account_code'] . ' - ' . $account['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="credit_account_id" class="form-label">Credit Account <span class="text-danger">*</span></label>
                            <select class="form-select" id="credit_account_id" name="credit_account_id" required>
                                <option value="">Select Credit Account</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= $account['account_id'] ?>"><?= safe_output($account['account_code'] . ' - ' . $account['account_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="description" name="description" required placeholder="Brief description of the transaction">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="reference_number" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="Transaction ID, Receipt #, etc.">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Additional notes or details"></textarea>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="draft" selected>Draft</option>
                                <option value="posted">Posted</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Include jQuery, Bootstrap JS, and Bootstrap Icons -->
<script src="/assets/js/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="/assets/css/bootstrap-icons.css">

<script>
$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Transactions List', 'User viewed the transactions management list');

    // Initialize DataTable
    const table = $('#transactionsTable').DataTable({
        language: {
            search: "Search transactions:",
            lengthMenu: "Show _MENU_ records per page",
            info: "Showing _START_ to _END_ of _TOTAL_ transactions",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        dom: 'rtipB', // Removed 'l' and 'f' as we use custom controls
        buttons: [
            {
                extend: 'copy',
                className: 'd-none',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5] }
            },
            {
                extend: 'excel',
                className: 'd-none',
                title: 'Transactions Report - ' + new Date().toLocaleDateString(),
                exportOptions: { columns: [0, 1, 2, 3, 4, 5] }
            },
            {
                extend: 'print',
                className: 'd-none',
                title: '',
                messageTop: '<div class="text-center mb-4"><h2 style="color: #0d6efd; font-weight: bold;">TRANSACTIONS MANAGEMENT REPORT</h2><p>Generated on: ' + new Date().toLocaleString() + '</p></div>',
                exportOptions: { columns: [0, 1, 2, 3, 4, 5] },
                customize: function (win) {
                    $(win.document.body).css('font-size', '10pt').css('padding', '20px');
                    $(win.document.body).find('table')
                        .addClass('compact')
                        .css('font-size', 'inherit')
                        .css('border', '1px solid #dee2e6');
                    $(win.document.body).find('thead th').css({
                        'background-color': '#0d6efd',
                        'color': 'white',
                        'padding': '12px'
                    });
                }
            }
        ]
    });

    // Add transaction form submission
    $('#addTransactionForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: '/api/add_transaction.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#add-transaction-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-transaction-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Transaction');
                }
            },
            error: function() {
                $('#add-transaction-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Transaction');
            }
        });
    });

    // Reset form when modal is closed
    $('#addTransactionModal').on('hidden.bs.modal', function() {
        $('#addTransactionForm')[0].reset();
        $('#add-transaction-message').html('');
        $('#addTransactionForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Transaction');
        
        // Reset edit mode
        $('#addTransactionModalLabel').html('<i class="bi bi-plus-circle"></i> Add New Transaction');
        $('#addTransactionForm').attr('id', 'addTransactionForm');
        $('input[name="entry_id"]').remove();
    });
});

function applyFilters() {
    const table = $('#transactionsTable').DataTable();
    
    // Status filter
    const status = $('#statusFilter').val();
    table.column(4).search(status).draw();
    
    // Date range filter
    const dateFrom = $('#dateFromFilter').val();
    const dateTo = $('#dateToFilter').val();
    
    if (dateFrom || dateTo) {
        $.fn.dataTable.ext.search.push(
            function(settings, data, dataIndex) {
                const date = new Date(data[0]);
                const from = dateFrom ? new Date(dateFrom) : null;
                const to = dateTo ? new Date(dateTo) : null;
                
                if ((from === null && to === null) ||
                    (from === null && date <= to) ||
                    (from <= date && to === null) ||
                    (from <= date && date <= to)) {
                    return true;
                }
                return false;
            }
        );
        table.draw();
        $.fn.dataTable.ext.search.pop();
    }
    
    // Search filter
    const search = $('#searchTransactions').val();
    table.search(search).draw();
}

function clearFilters() {
    $('#statusFilter').val('');
    $('#dateFromFilter').val('');
    $('#dateToFilter').val('');
    $('#searchTransactions').val('');
    
    const table = $('#transactionsTable').DataTable();
    table.search('').columns().search('').draw();
}

function viewTransaction(entryId) {
    logReportAction('Viewed Transaction Details Link', 'User clicked to view details for transaction #' + entryId);
    // Redirect to transaction details page
    window.location.href = '<?= getUrl('transaction/view') ?>?id=' + entryId;
}

function editTransaction(entryId) {
    logReportAction('Initiated Transaction Edit', 'User clicked edit for transaction #' + entryId);
    // Load transaction data and open edit modal
    $.ajax({
        url: '/api/get_transaction.php',
        type: 'GET',
        data: { id: entryId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate form and show modal
                $('#entry_date').val(response.data.entry_date);
                $('#amount').val(response.data.amount);
                $('#debit_account_id').val(response.data.debit_account_id);
                $('#credit_account_id').val(response.data.credit_account_id);
                $('#description').val(response.data.description);
                $('#reference_number').val(response.data.reference_number);
                $('#notes').val(response.data.notes);
                $('#status').val(response.data.status);
                
                // Change modal to edit mode
                $('#addTransactionModalLabel').html('<i class="bi bi-pencil"></i> Edit Transaction');
                $('#addTransactionForm').attr('id', 'editTransactionForm');
                $('#editTransactionForm').append('<input type="hidden" name="entry_id" value="' + entryId + '">');
                $('#editTransactionForm [type="submit"]').html('<i class="bi bi-check-circle"></i> Update Transaction');
                
                // Update form submission for edit
                $('#editTransactionForm').off('submit').on('submit', function(e) {
                    e.preventDefault();
                    updateTransaction(entryId, $(this).serialize());
                });
                
                $('#addTransactionModal').modal('show');
            } else {
                alert('Error loading transaction data: ' + response.message);
            }
        },
        error: function() {
            alert('Error loading transaction data. Please try again.');
        }
    });
}

function updateTransaction(entryId, formData) {
    const submitBtn = $('#editTransactionForm [type="submit"]');
    
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

    $.ajax({
        url: '/api/update_transaction.php',
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#add-transaction-message').html('<div class="alert alert-success">' + response.message + '</div>');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                $('#add-transaction-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Transaction');
            }
        },
        error: function() {
            $('#add-transaction-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
            submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Transaction');
        }
    });
}

function updateStatus(entryId, status) {
    if (!confirm('Are you sure you want to ' + status + ' this transaction?')) {
        return;
    }

    $.ajax({
        url: '/api/update_transaction_status.php',
        type: 'POST',
        data: { 
            entry_id: entryId,
            status: status
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                logReportAction('Updated Transaction Status', 'User updated transaction #' + entryId + ' status to ' + status);
                alert(response.message);
                location.reload();
            } else {
                alert('Error updating status: ' + response.message);
            }
        },
        error: function() {
            alert('Error updating status. Please try again.');
        }
    });
}

function confirmDelete(entryId) {
    if (confirm('Are you sure you want to delete this transaction? This action cannot be undone.')) {
        $.ajax({
            url: '/api/delete_transaction.php',
            method: 'POST',
            data: { entry_id: entryId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    logReportAction('Deleted Transaction Record', 'User deleted transaction record #' + entryId);
                    location.reload();
                } else {
                    alert('Error deleting transaction: ' + response.message);
                }
            },
            error: function() {
                alert('Error deleting transaction. Please try again.');
            }
        });
    }
}

function copyTable() {
    logReportAction('Copied Transactions Table', 'User copied transactions table to clipboard');
    $('#transactionsTable').DataTable().button('.buttons-copy').trigger();
    alert('Table data copied to clipboard');
}

function exportExcel() {
    logReportAction('Exported Transactions List', 'User exported transactions list to Excel');
    $('#transactionsTable').DataTable().button('.buttons-excel').trigger();
}

function printTable() {
    logReportAction('Printed Transactions Table', 'User generated a printed transactions report');
    $('#transactionsTable').DataTable().button('.buttons-print').trigger();
}
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
.custom-stat-card div, .custom-stat-card h4 {
    color: #0f5132 !important;
    font-weight: 600;
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
    border-radius: 12px;
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 180px;
}

.action-dropdown .dropdown-item {
    padding: 0.25rem 1rem;
}

.action-dropdown .dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

.dataTables_length {
    margin-bottom: 1rem;
}

.dataTables_length select {
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    border: 1px solid #dee2e6;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-info,
.card.bg-warning {
    border: none;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    .d-flex.justify-content-between.align-items-center > div:last-child {
        align-self: stretch;
    }
}
</style>

<?php
// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>
