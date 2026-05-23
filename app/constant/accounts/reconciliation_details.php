<?php
// Include roots configuration
require_once __DIR__ . '/../../../roots.php';


// Enforce permission BEFORE any output
autoEnforcePermission('bank_reconciliation');

// Include the header
includeHeader();

// Get Reconciliation ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    // Redirect or show error
    echo "<script>window.location.href='" . getUrl('bank-reconciliation') . "';</script>";
    exit;
}

$reconciliation_id = $_GET['id'];

// Fetch Reconciliation Details
$stmt = $pdo->prepare("
    SELECT 
        br.*, 
        ba.account_name as bank_account_name,
        ba.account_code as bank_account_code,
        u.username as prepared_by_name
    FROM bank_reconciliations br 
    LEFT JOIN accounts ba ON br.bank_account_id = ba.account_id 
    LEFT JOIN users u ON br.prepared_by = u.user_id 
    WHERE br.reconciliation_id = ?
");
$stmt->execute([$reconciliation_id]);
$reconciliation = $stmt->fetch();

if (!$reconciliation) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Reconciliation record not found. <a href='" . getUrl('bank-reconciliation') . "'>Return to list</a></div></div>";
    includeFooter();
    exit;
}

// Status Badge Helper
function get_rec_status_color($status) {
    return match($status) {
        'reconciled' => 'success',
        'pending' => 'warning',
        'disputed' => 'danger',
        'cancelled' => 'secondary',
        default => 'info'
    };
}

$statusClass = get_rec_status_color($reconciliation['status']);
$diff = $reconciliation['difference'] ?? 0;
$diffClass = $diff == 0 ? 'success' : 'danger';
?>

<div class="container-fluid py-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4" id="printHeader">
      
        <h3 style="color: #333 !important; font-weight: 700; text-transform: uppercase; margin: 5px 0; font-size: 18pt; letter-spacing: 1px;">BANK RECONCILIATION REPORT</h3>
        <p style="color: #6c757d; margin: 0; font-size: 10pt;">
            #<?= htmlspecialchars($reconciliation['reconciliation_number'] ?? $reconciliation['reconciliation_id']) ?> | Generated on: <?= date('F j, Y, g:i a') ?>
        </p>
        <div style="border-bottom: 4px solid #0d6efd; margin-top: 15px; margin-bottom: 25px; width: 150px; margin-left: auto; margin-right: auto;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= getUrl('bank-reconciliation') ?>">Bank Reconciliation</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Reconciliation Details</li>
                </ol>
            </nav>
            <h2 class="fw-bold text-dark">
                Reconciliation #<?= htmlspecialchars($reconciliation['reconciliation_number'] ?? $reconciliation['reconciliation_id']) ?>
            </h2>
        </div>
        <div class="col-auto d-flex gap-2">
            <a href="<?= getUrl('bank-reconciliation') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            <button onclick="printReconciliationReport()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Report
            </button>
        </div>
    </div>

    <div class="row">
        <!-- Main Details -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold text-dark">Reconciliation Information</h5>
                    <span class="badge rounded-pill bg-<?= $statusClass ?> px-3 py-2">
                        <i class="bi bi-circle-fill me-1 small"></i> <?= strtoupper($reconciliation['status']) ?>
                    </span>
                </div>
                <div class="card-body">
                    <!-- Screen View (Original Layout) -->
                    <div class="row g-4 d-print-none">
                        <div class="col-md-12">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Bank Account</label>
                            <p class="fs-5 fw-semibold text-dark mb-0">
                                <?= htmlspecialchars($reconciliation['bank_account_name']) ?> 
                                <span class="text-muted small">(<?= htmlspecialchars($reconciliation['bank_account_code']) ?>)</span>
                            </p>
                        </div>
                        
                        <div class="col-12"><hr class="my-0 opacity-10"></div>

                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Reconciliation Date</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-calendar3 me-2 text-primary"></i><?= date('F d, Y', strtotime($reconciliation['reconciliation_date'])) ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Period Start</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-calendar-range me-2 text-primary"></i><?= date('M d, Y', strtotime($reconciliation['period_start'])) ?></p>
                        </div>
                         <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Period End</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-calendar-range me-2 text-primary"></i><?= date('M d, Y', strtotime($reconciliation['period_end'])) ?></p>
                        </div>

                        <div class="col-12"><hr class="my-0 opacity-10"></div>

                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Statement Balance</label>
                            <p class="fs-5 fw-bold text-dark mb-0">
                                <?= number_format($reconciliation['statement_balance'], 2) ?>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Book Balance</label>
                            <p class="fs-5 fw-bold text-primary mb-0">
                                <?= number_format($reconciliation['book_balance'], 2) ?>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Difference</label>
                            <p class="fs-5 fw-bold text-<?= $diffClass ?> mb-0">
                                <?= number_format($diff, 2) ?>
                            </p>
                        </div>
                    </div>

                    <!-- Print View (Perfect Grid Layout) -->
                    <div class="reconciliation-grid d-none d-print-flex">
                        <!-- Account Info (Full Width) -->
                        <div class="col-12 grid-item border-bottom">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Bank Account</label>
                            <p class="fs-5 fw-bold text-dark mb-0">
                                <?= htmlspecialchars($reconciliation['bank_account_name']) ?> 
                                <span class="text-muted small">(<?= htmlspecialchars($reconciliation['bank_account_code']) ?>)</span>
                            </p>
                        </div>
                        
                        <!-- Dates Row -->
                        <div class="col-4 grid-item border-end">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Reconciliation Date</label>
                            <p class="mb-0 fw-bold text-dark"><?= date('F d, Y', strtotime($reconciliation['reconciliation_date'])) ?></p>
                        </div>
                        <div class="col-4 grid-item border-end">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Period Start</label>
                            <p class="mb-0 fw-bold text-dark"><?= date('M d, Y', strtotime($reconciliation['period_start'])) ?></p>
                        </div>
                        <div class="col-4 grid-item">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Period End</label>
                            <p class="mb-0 fw-bold text-dark"><?= date('M d, Y', strtotime($reconciliation['period_end'])) ?></p>
                        </div>

                        <!-- Balances Row -->
                        <div class="col-4 grid-item border-end no-border-bottom">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Statement Balance</label>
                            <p class="fs-5 fw-bold text-dark mb-0"><?= number_format($reconciliation['statement_balance'], 2) ?></p>
                        </div>
                        <div class="col-4 grid-item border-end no-border-bottom">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Book Balance</label>
                            <p class="fs-5 fw-bold text-primary mb-0"><?= number_format($reconciliation['book_balance'], 2) ?></p>
                        </div>
                        <div class="col-4 grid-item no-border-bottom">
                            <label class="text-muted small text-uppercase fw-bold mb-1 d-block">Status / Difference</label>
                            <p class="fs-5 fw-bold text-<?= $diffClass ?> mb-0">
                                <?= $diff == 0 ? 'BALANCED' : number_format($diff, 2) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($reconciliation['notes'])): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark">Notes & Remarks</h5>
                </div>
                <div class="card-body">
                    <div class="p-3 bg-light rounded border-start border-4 border-primary">
                        <p class="mb-0 text-dark italic"><?= nl2br(htmlspecialchars($reconciliation['notes'])) ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>


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
                        <?php if ($reconciliation['status'] === 'pending'): ?>
                            <button onclick="updateStatus('reconciled')" class="btn btn-success text-start">
                                <i class="bi bi-check-circle me-2"></i> Finalize Reconciliation
                            </button>
                             <button onclick="updateStatus('disputed')" class="btn btn-outline-warning text-start">
                                <i class="bi bi-exclamation-triangle me-2"></i> Mark as Disputed
                            </button>
                             <button onclick="updateStatus('cancelled')" class="btn btn-outline-secondary text-start">
                                <i class="bi bi-x-circle me-2"></i> Cancel Reconciliation
                            </button>
                        <?php endif; ?>
                         <button onclick="deleteReconciliation()" class="btn btn-outline-danger text-start">
                            <i class="bi bi-trash me-2"></i> Delete Record
                        </button>
                    </div>
                </div>
            </div>

            <!-- Financial Summary Card (Stat Card Style) -->
            <div class="card custom-stat-card mb-4">
                 <div class="card-body">
                    <h5 class="card-title h6 text-uppercase text-muted mb-2">Reconciliation Status</h5>
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="mb-0 fw-bold <?= $diff == 0 ? 'text-success' : 'text-danger' ?>">
                             <?= $diff == 0 ? 'Balanced' : 'Unbalanced' ?>
                        </h3>
                        <i class="bi bi-scale fs-1 opacity-50"></i>
                    </div>
                    <?php if ($diff != 0): ?>
                    <p class="mb-0 mt-2 small">
                        Difference of <strong><?= number_format(abs($diff), 2) ?></strong> needs to be resolved.
                    </p>
                    <?php else: ?>
                     <p class="mb-0 mt-2 small text-success">
                        <i class="bi bi-check-all"></i> Books match statement.
                    </p>
                    <?php endif; ?>
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
                            <span class="text-muted">Reconciliation ID</span>
                            <span class="font-monospace fw-bold">#<?= $reconciliation['reconciliation_id'] ?></span>
                        </li>
                         <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Created At</span>
                            <span class="text-dark"><?= date('Y-m-d', strtotime($reconciliation['created_at'])) ?></span>
                        </li>
                         <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Last Updated</span>
                            <span class="text-dark"><?= date('Y-m-d', strtotime($reconciliation['updated_at'])) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
});

function printReconciliationReport() {
    window.print();
}

function updateStatus(newStatus) {
    if (!confirm('Are you sure you want to change the status to ' + newStatus + '?')) return;
    
    // Using existing API
    $.post('/api/account/update_reconciliation_status.php', { 
        reconciliation_id: <?= $reconciliation_id ?>, 
        status: newStatus 
    }, function(response) {
        if (typeof response === 'string') {
            try {
                response = JSON.parse(response);
            } catch (e) {
                console.error('Invalid JSON response', response);
                alert('Server error occurred');
                return;
            }
        }
        if (response.success) {
            location.reload();
        } else {
            alert('Error: ' + (response.message || 'Unknown error'));
        }
    });
}

function deleteReconciliation() {
    if (!confirm('Are you sure you want to delete this reconciliation record?')) return;
    
    $.post('/api/account/delete_reconciliation.php', { 
        reconciliation_id: <?= $reconciliation_id ?> 
    }, function(response) {
        if (typeof response === 'string') {
            try {
                response = JSON.parse(response);
            } catch (e) {
                console.error('Invalid JSON response', response);
                alert('Server error occurred');
                return;
            }
        }
        if (response.success) {
            window.location.href = '<?= getUrl('bank-reconciliation') ?>';
        } else {
            alert('Error: ' + (response.message || 'Unknown error'));
        }
    });
}
</script>

<style>
    .card { border-radius: 12px; }
    .card-header:first-child { border-radius: 12px 12px 0 0; }
    .btn { border-radius: 8px; font-weight: 500; }
    .table thead th { font-size: 0.75rem; letter-spacing: 0.5px; }
    .italic { font-style: italic; }
    
    .custom-stat-card {
        background-color: #d1e7dd !important;
        border-color: #badbcc !important;
    }
    .custom-stat-card .card-title { color: #0f5132; }

    @page { margin: 10mm 8mm 16mm 8mm; }
    @media print {
        .d-print-none, .col-lg-4, .breadcrumb, .btn, .col-auto, nav, .card-header .badge, .card-footer {
            display: none !important;
        }
        .col-lg-8 { 
            width: 100% !important; 
            flex: 0 0 100% !important; 
            max-width: 100% !important; 
        }
        .card { 
            box-shadow: none !important; 
            border: none !important; 
            margin-bottom: 10px !important; 
        }
        .card-header {
            background-color: transparent !important;
            border-bottom: 2px solid #333 !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            margin-bottom: 15px !important;
        }
        .card-header h5 {
            font-size: 14pt !important;
            text-transform: uppercase;
            color: #0d6efd !important;
            -webkit-print-color-adjust: exact;
        }
        .card-body {
            padding: 0 !important;
        }
        .row {
            display: flex !important;
            flex-wrap: wrap !important;
        }
        .col-md-12 { width: 100% !important; }
        .col-md-4 { width: 33.33% !important; flex: 0 0 33.33% !important; }
        
        /* Grid styling for print */
        .reconciliation-grid {
            display: flex !important;
            flex-wrap: wrap !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 8px !important;
            overflow: hidden !important;
        }
        .grid-item {
            padding: 15px !important;
            border-bottom: 1px solid #dee2e6 !important;
        }
        .grid-item.border-end {
            border-right: 1px solid #dee2e6 !important;
        }
        body { 
            background: white !important; 
            padding: 20px;
            margin: 0;
        }
        
        #print-footer-info {
            display: flex !important;
            justify-content: center !important;
            gap: 50px !important;
            margin-top: 50px !important;
            padding-top: 20px !important;
            border-top: 1px solid #dee2e6 !important;
        }
        footer {
            display: none !important;
        }
    }
<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
</style>

    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
<?php
includeFooter();
ob_end_flush();
?>
