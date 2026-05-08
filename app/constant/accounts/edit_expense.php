<?php
require_once __DIR__ . '/../../../roots.php';
includeHeader();

// Get Expense ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: expenses.php");
    exit;
}

$expense_id = $_GET['id'];

// Fetch Expense Details
$stmt = $pdo->prepare("SELECT * FROM expenses WHERE expense_id = ?");
$stmt->execute([$expense_id]);
$expense = $stmt->fetch();

if (!$expense) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Expense not found. <a href='expenses.php'>Return to list</a></div></div>";
    includeFooter();
    exit;
}

// Fetch Categories
$categories = $pdo->query("SELECT * FROM expense_categories WHERE status = 'active' ORDER BY category_name")->fetchAll();

// Payment Method Labels
$method_labels = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'mobile_money' => 'Mobile Money',
    'cheque' => 'Cheque',
    'card' => 'Credit/Debit Card'
];
?>

<div class="container-fluid mt-4">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="expenses.php">Expenses</a></li>
                    <li class="breadcrumb-item"><a href="expense_details.php?id=<?php echo $expense_id; ?>">Details</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit Expense</li>
                </ol>
            </nav>
            <h2 class="fw-bold text-dark">Edit Expense #<?php echo str_pad($expense['expense_id'], 5, '0', STR_PAD_LEFT); ?></h2>
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
            <a href="expense_details.php?id=<?php echo $expense_id; ?>" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i> Cancel
            </a>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-pencil-square me-2 text-primary"></i>Update Expense Details</h5>
                </div>
                <div class="card-body p-4">
                    <form id="editExpenseForm">
                        <input type="hidden" name="expense_id" value="<?php echo $expense_id; ?>">
                        
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Expense Date</label>
                                <input type="date" class="form-control form-control-lg" name="expense_date" value="<?php echo $expense['expense_date']; ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Category / Account</label>
                                <select class="form-select form-select-lg" name="category_id" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['category_id']; ?>" <?php echo $cat['category_id'] == $expense['category_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Amount</label>
                                <div class="input-group input-group-lg">
                                    <!-- removed TSh prefix -->
                                    <input type="number" class="form-control border-start-0" name="amount" step="0.01" value="<?php echo $expense['amount']; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Payment Method</label>
                                <select class="form-select form-select-lg" name="payment_method" required>
                                    <?php foreach ($method_labels as $val => $label): ?>
                                        <option value="<?php echo $val; ?>" <?php echo $val == $expense['payment_method'] ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small text-uppercase text-muted">Description</label>
                                <input type="text" class="form-control form-control-lg" name="description" value="<?php echo htmlspecialchars($expense['description']); ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Vendor / Payee</label>
                                <input type="text" class="form-control form-control-lg" name="vendor" value="<?php echo htmlspecialchars($expense['vendor'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Reference Number</label>
                                <input type="text" class="form-control form-control-lg" name="reference_number" value="<?php echo htmlspecialchars($expense['reference_number'] ?? ''); ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold small text-uppercase text-muted">Internal Notes</label>
                                <textarea class="form-control" name="notes" rows="4"><?php echo htmlspecialchars($expense['notes'] ?? ''); ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Status</label>
                                <select class="form-select form-select-lg" name="status" required>
                                    <option value="pending" <?php echo $expense['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $expense['status'] == 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="paid" <?php echo $expense['status'] == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="rejected" <?php echo $expense['status'] == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-5 d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary btn-lg px-5">
                                <i class="bi bi-check-circle me-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    logReportAction('Viewed Edit Expense Page', 'User viewed edit page for expense #<?php echo $expense_id; ?>');
});

document.getElementById('editExpenseForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Saving...';

    fetch('api/update_expense.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            logReportAction('Updated Expense', 'User updated expense #<?php echo $expense_id; ?>');
            window.location.href = 'expense_details.php?id=<?php echo $expense_id; ?>&success=1';
        } else {
            alert('Error: ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while saving.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});
</script>

<style>
    .card { border-radius: 15px; }
    .form-control-lg, .form-select-lg { border-radius: 10px; font-size: 1rem; }
    .btn-lg { border-radius: 10px; font-weight: 600; }
</style>

<?php includeFooter(); ?>
