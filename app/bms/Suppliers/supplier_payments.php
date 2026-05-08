<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('supplier_payments');

// Include the header
includeHeader();

// Permission flags
$can_view_payments = isAdmin() || canView('supplier_payments');
$can_edit_payments = isAdmin() || canEdit('supplier_payments');


// Get filter parameters
$supplier_id = $_GET['id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$payment_method = $_GET['payment_method'] ?? '';

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

if (!empty($supplier_id)) {
    $query .= " AND sp.supplier_id = ?";
    $params[] = $supplier_id;
}

if (!empty($date_from)) {
    $query .= " AND sp.payment_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND sp.payment_date <= ?";
    $params[] = $date_to;
}

if (!empty($payment_method)) {
    $query .= " AND sp.payment_method = ?";
    $params[] = $payment_method;
}

$query .= " ORDER BY sp.payment_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get suppliers for filter
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_payments = count($payments);
$total_amount = array_sum(array_column($payments, 'amount'));
$this_month = date('Y-m');
$this_month_payments = array_filter($payments, function($payment) use ($this_month) {
    return date('Y-m', strtotime($payment['payment_date'])) == $this_month;
});
$this_month_amount = array_sum(array_column($this_month_payments, 'amount'));

// Helper functions removed, now in helpers.php
?>

<div class="container mt-4">
    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('suppliers') ?>">Suppliers</a></li>
            <?php if (!empty($supplier_id)): 
                $supplier_name = '';
                foreach ($suppliers as $s) {
                    if ($s['supplier_id'] == $supplier_id) {
                        $supplier_name = $s['supplier_name'];
                        break;
                    }
                }
            ?>
            <li class="breadcrumb-item"><a href="<?= getUrl('suppliers/details') ?>?id=<?= $supplier_id ?>"><?= htmlspecialchars($supplier_name) ?></a></li>
            <?php endif; ?>
            <li class="breadcrumb-item active">Payments</li>
        </ol>
    </nav>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-cash"></i> Supplier Payments</h2>
                    <p class="text-muted mb-0">Track and manage supplier payments</p>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($can_edit_payments): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                        <i class="bi bi-plus-circle"></i> Add Payment
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success btn-sm" onclick="exportPayments()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body text-center">
                    <h4 class="mb-0"><?= $total_payments ?></h4>
                    <p class="mb-0">Total Payments</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body text-center">
                    <h4 class="mb-0"><?= format_currency($total_amount) ?></h4>
                    <p class="mb-0">Total Amount</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body text-center">
                    <h4 class="mb-0"><?= count($this_month_payments) ?></h4>
                    <p class="mb-0">This Month</p>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-dark"><div class="card-body text-center">
                    <h4 class="mb-0"><?= format_currency($this_month_amount) ?></h4>
                    <p class="mb-0">Month Amount</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <?php if (!empty($supplier_id)): ?>
                <input type="hidden" name="id" value="<?= $supplier_id ?>">
                <?php endif; ?>
                
                <div class="col-md-3">
                    <label class="form-label">Supplier</label>
                    <select class="form-select" name="id" onchange="this.form.submit()">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= $supplier['supplier_id'] ?>" <?= $supplier_id == $supplier['supplier_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($supplier['supplier_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Payment Method</label>
                    <select class="form-select" name="payment_method" onchange="this.form.submit()">
                        <option value="">All Methods</option>
                        <option value="cash" <?= $payment_method == 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="bank_transfer" <?= $payment_method == 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        <option value="cheque" <?= $payment_method == 'cheque' ? 'selected' : '' ?>>Cheque</option>
                        <option value="mobile_money" <?= $payment_method == 'mobile_money' ? 'selected' : '' ?>>Mobile Money</option>
                        <option value="credit_card" <?= $payment_method == 'credit_card' ? 'selected' : '' ?>>Credit Card</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>" onchange="this.form.submit()">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>" onchange="this.form.submit()">
                </div>
                
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-filter"></i> Apply Filters
                    </button>
                    <a href="<?= getUrl('suppliers/payments') ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="card">
        <div class="card-header bg-success text-white">
            <h5 class="mb-0">Payment History</h5>
        </div>
        <div class="card-body">
            <?php if (count($payments) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Supplier</th>
                                <th>Reference</th>
                                <th>Order #</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Notes</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>
                                    <strong><?= format_date($payment['payment_date']) ?></strong>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($payment['supplier_name']) ?></strong>
                                    <?php if (!empty($payment['company_name'])): ?>
                                    <br>
                                    <small class="text-muted"><?= htmlspecialchars($payment['company_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?= htmlspecialchars($payment['reference_number']) ?></code>
                                </td>
                                <td>
                                    <?php if (!empty($payment['order_number'])): ?>
                                    <a href="<?= getUrl('purchase_order_details') ?>?id=<?= $payment['purchase_order_id'] ?>">
                                        <?= htmlspecialchars($payment['order_number']) ?>
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="text-success"><?= format_currency($payment['amount']) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= htmlspecialchars($payment['currency']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= ucfirst(str_replace('_', ' ', $payment['payment_method'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($payment['notes'])): ?>
                                    <small><?= htmlspecialchars(substr($payment['notes'], 0, 50)) ?>...</small>
                                    <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($payment['created_by_name']) ?>
                                    <br>
                                    <small class="text-muted"><?= format_date($payment['created_at']) ?></small>
                                </td>
                                <td>
                                    <div class="dropdown action-dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="viewPayment(<?= $payment['payment_id'] ?>)">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <?php if ($can_edit_payments): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editPayment(<?= $payment['payment_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDeletePayment(<?= $payment['payment_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete
                                                </a>
                                            </li>
                                            <?php endif; ?>
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
                    <i class="bi bi-cash" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Payments Found</h4>
                    <p class="text-muted">No payment records found for the selected filters.</p>
                    <?php if ($can_edit_payments): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                        <i class="bi bi-plus-circle"></i> Add Payment
                    </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Payment Modal -->
<?php if ($can_edit_payments): ?>
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-labelledby="addPaymentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addPaymentModalLabel">
                    <i class="bi bi-plus-circle"></i> Add Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addPaymentForm">
                <div class="modal-body">
                    <div id="add-payment-message" class="mb-3"></div>
                    
                    <div class="mb-3">
                        <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select class="form-select" id="supplier_id" name="supplier_id" required>
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= $supplier['supplier_id'] ?>" <?= $supplier_id == $supplier['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($supplier['supplier_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="purchase_order_id" class="form-label">Purchase Order (Optional)</label>
                        <select class="form-select" id="purchase_order_id" name="purchase_order_id">
                            <option value="">Select Order (Optional)</option>
                            <!-- Will be populated via AJAX based on selected supplier -->
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_date" class="form-label">Payment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="currency" class="form-label">Currency</label>
                            <select class="form-select" id="currency" name="currency">
                                <option value="TZS" selected>Tanzanian Shilling (TZS)</option>
                                <option value="USD">US Dollar (USD)</option>
                                <option value="EUR">Euro (EUR)</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="">Select Method</option>
                                <option value="cash">Cash</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="credit_card">Credit Card</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reference_number" class="form-label">Reference Number</label>
                        <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="Transaction ID, cheque number, etc.">
                    </div>
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Payment notes or description"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Save Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
$(document).ready(function() {
    // Add payment form submission
    $('#addPaymentForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');

        $.ajax({
            url: 'api/add_supplier_payment.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#add-payment-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-payment-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Payment');
                }
            },
            error: function() {
                $('#add-payment-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Payment');
            }
        });
    });

    // Load purchase orders when supplier is selected
    $('#supplier_id').on('change', function() {
        const supplierId = $(this).val();
        const orderSelect = $('#purchase_order_id');
        
        if (supplierId) {
            $.ajax({
                url: 'api/get_supplier_orders.php',
                type: 'GET',
                data: { supplier_id: supplierId },
                dataType: 'json',
                success: function(response) {
                    orderSelect.empty().append('<option value="">Select Order (Optional)</option>');
                    
                    if (response.success && response.data.length > 0) {
                        response.data.forEach(function(order) {
                            orderSelect.append(new Option(
                                order.order_number + ' - ' + format_currency(order.total_amount) + ' (' + order.status + ')',
                                order.purchase_order_id
                            ));
                        });
                    }
                }
            });
        } else {
            orderSelect.empty().append('<option value="">Select Order (Optional)</option>');
        }
    });

    // Reset form when modal is closed
    $('#addPaymentModal').on('hidden.bs.modal', function() {
        $('#addPaymentForm')[0].reset();
    });
});

function viewPayment(paymentId) {
    $.ajax({
        url: 'api/get_supplier_payment.php',
        type: 'GET',
        data: { id: paymentId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                const p = response.data;
                let info = `
                    <div class="text-start">
                        <p><strong>Payment Number:</strong> ${p.payment_number}</p>
                        <p><strong>Supplier:</strong> ${p.supplier_name}</p>
                        <p><strong>Date:</strong> ${p.payment_date}</p>
                        <p><strong>Amount:</strong> ${p.amount} ${p.currency}</p>
                        <p><strong>Method:</strong> ${p.payment_method}</p>
                        ${p.order_number ? '<p><strong>Order:</strong> ' + p.order_number + '</p>' : ''}
                        ${p.reference_number ? '<p><strong>Reference:</strong> ' + p.reference_number + '</p>' : ''}
                        ${p.notes ? '<p><strong>Notes:</strong> ' + p.notes + '</p>' : ''}
                    </div>
                `;
                Swal.fire({
                    title: 'Payment Details',
                    html: info,
                    icon: 'info',
                    confirmButtonColor: '#0d6efd'
                });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: response.message });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error loading payment details.' });
        }
    });
}

function editPayment(paymentId) {
    Swal.fire({
        icon: 'info',
        title: 'Coming Soon',
        text: 'Edit functionality is coming soon. Please delete and re-add for now.',
        confirmButtonColor: '#0d6efd'
    });
}

function confirmDeletePayment(paymentId) {
    Swal.fire({
        title: 'Delete Payment?',
        text: 'Are you sure you want to delete this payment? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, keep it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/delete_supplier_payment.php',
                method: 'POST',
                data: { payment_id: paymentId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Payment Deleted!',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => { location.reload(); });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Error deleting payment: ' + response.message });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error deleting payment. Please try again.' });
                }
            });
        }
    });
}

function exportPayments() {
    // Build export URL with filters
    let exportUrl = 'api/export_supplier_payments.php?';
    const params = new URLSearchParams(window.location.search);
    exportUrl += params.toString();
    window.location.href = exportUrl;
}
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 150px;
}

.badge {
    font-size: 0.75em;
}

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.table thead th {
    background-color: #f8f9fa !important;
}
</style>
</style>

<?php
include("footer.php");
ob_end_flush();
?>