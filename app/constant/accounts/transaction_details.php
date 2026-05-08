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

// Get Transaction ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirectTo('transactions');
}

$entry_id = $_GET['id'];

// Fetch Transaction Header
$stmt = $pdo->prepare("
    SELECT je.*, u.username as created_by_name 
    FROM journal_entries je 
    LEFT JOIN users u ON je.created_by = u.user_id 
    WHERE je.entry_id = ?
");
$stmt->execute([$entry_id]);
$transaction = $stmt->fetch();

if (!$transaction) {
    echo "<div class='alert alert-danger'>Transaction not found.</div>";
    includeFooter();
    exit;
}

// Fetch Transaction Items
$stmtItems = $pdo->prepare("
    SELECT jei.*, ca.account_name, ca.account_code, at.type_name as account_type
    FROM journal_entry_items jei 
    LEFT JOIN accounts ca ON jei.account_id = ca.account_id 
    LEFT JOIN account_types at ON ca.account_type_id = at.type_id
    WHERE jei.entry_id = ?
    ORDER BY at.type_name, jei.type DESC
");
$stmtItems->execute([$entry_id]);
$items = $stmtItems->fetchAll();

// Group items by account type
$grouped_items = [];
$total_amount = 0;
foreach ($items as $item) {
    $type = $item['account_type'] ?? 'Uncategorized';
    if (!isset($grouped_items[$type])) {
        $grouped_items[$type] = [];
    }
    $grouped_items[$type][] = $item;
    
    if ($item['type'] === 'debit') {
        $total_amount += $item['amount'];
    }
}


$statusClass = get_status_badge($transaction['status']);
?>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">
        
        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Transaction Details Report</h2>
        <h5 class="text-muted">#<?php echo htmlspecialchars($transaction['reference_number']); ?> | <?php echo date('F d, Y', strtotime($transaction['entry_date'])); ?></h5>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>

    <div class="row mb-4 align-items-center d-print-none">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= getUrl('transactions') ?>">Transactions</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Transaction Details</li>
                </ol>
            </nav>
            <h2>Transaction #<?php echo htmlspecialchars($transaction['reference_number']); ?></h2>
        </div>
        <div class="col-auto">
            <a href="<?= getUrl('transactions') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to List
            </a>
            <button onclick="printDetails()" class="btn btn-primary">
                <i class="bi bi-printer"></i> Print Details
            </button>
        </div>
    </div>

    <div class="row">
        <!-- Main Details -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold text-dark">Transaction Information</h5>
                    <span class="badge rounded-pill bg-<?php echo $statusClass; ?> px-3 py-2">
                        <i class="bi bi-circle-fill me-1 small"></i> <?php echo strtoupper($transaction['status']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-12">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Description</label>
                            <p class="fs-5 fw-semibold text-dark mb-0"><?php echo htmlspecialchars($transaction['description']); ?></p>
                        </div>
                        
                        <div class="col-12"><hr class="my-0 opacity-10"></div>

                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Date</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-calendar3 me-2 text-primary"></i><?php echo date('F d, Y', strtotime($transaction['entry_date'])); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Reference</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-hash me-1 text-primary"></i><?php echo htmlspecialchars($transaction['reference_number'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Total Value</label>
                            <p class="fs-4 fw-bold text-primary mb-0">
                                <?php echo number_format($total_amount, 2); ?>
                            </p>
                        </div>

                        <div class="col-12"><hr class="my-0 opacity-10"></div>

                        <div class="col-md-6">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Recorded By</label>
                            <div class="d-flex align-items-center">
                                <div class="avatar-sm bg-light rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                    <i class="bi bi-person text-primary"></i>
                                </div>
                                <p class="mb-0 fw-medium text-dark"><?php echo htmlspecialchars($transaction['created_by_name'] ?? 'System'); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Last Modified</label>
                            <p class="mb-0 fw-medium text-dark">
                                <i class="bi bi-clock-history me-2 text-info"></i>
                                <?php echo $transaction['updated_at'] ? date('M d, Y H:i', strtotime($transaction['updated_at'])) : 'Never'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Journal Entries Table Grouped -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark">Ledger Entries</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4 py-3 text-uppercase small fw-bold text-muted">Account Details</th>
                                    <th class="py-3 text-uppercase small fw-bold text-muted">Entry Description</th>
                                    <th class="py-3 text-uppercase small fw-bold text-muted text-end">Debit</th>
                                    <th class="py-3 text-uppercase small fw-bold text-muted text-end pe-4">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <span class="badge bg-light text-dark border font-monospace"><?php echo htmlspecialchars($item['account_code'] ?? ''); ?></span>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($item['account_name'] ?? ''); ?></div>
                                                <div class="small text-muted text-uppercase"><?php echo htmlspecialchars($item['account_type'] ?? ''); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 text-muted"><?php echo htmlspecialchars($item['description'] ?? '-'); ?></td>
                                    <td class="py-3 text-end fw-semibold text-danger">
                                        <?php if ($item['type'] === 'debit'): ?>
                                            <?php echo number_format($item['amount'], 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 text-end fw-semibold text-success pe-4">
                                        <?php if ($item['type'] === 'credit'): ?>
                                            <?php echo number_format($item['amount'], 2); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-light fw-bold">
                                <tr>
                                    <td colspan="2" class="ps-4 py-3 text-dark">TOTAL TRANSACTION VALUE</td>
                                    <td class="py-3 text-end text-danger"><?php echo number_format($total_amount, 2); ?></td>
                                    <td class="py-3 text-end text-success pe-4"><?php echo number_format($total_amount, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <?php if (!empty($transaction['notes'])): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark">Internal Notes</h5>
                </div>
                <div class="card-body">
                    <div class="p-3 bg-light rounded border-start border-4 border-primary">
                        <p class="mb-0 text-dark italic"><?php echo nl2br(htmlspecialchars($transaction['notes'])); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Financial Impact / Mini Trial Balance -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up-arrow me-2"></i>Financial Impact</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($items as $item): 
                            // Fetch current balance for this account
                            $accStmt = $pdo->prepare("SELECT current_balance FROM accounts WHERE account_id = ?");
                            $accStmt->execute([$item['account_id']]);
                            $currBal = $accStmt->fetchColumn();
                        ?>
                        <div class="list-group-item py-3">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <span class="fw-bold text-dark small text-uppercase"><?php echo htmlspecialchars($item['account_name']); ?></span>
                                <span class="badge bg-<?php echo $item['type'] == 'debit' ? 'danger' : 'success'; ?>-soft text-<?php echo $item['type'] == 'debit' ? 'danger' : 'success'; ?> small">
                                    <?php echo $item['type'] == 'debit' ? '+ DEBIT' : '+ CREDIT'; ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="text-muted small">Current Balance:</span>
                                <span class="fw-bold text-dark"><?php echo number_format($currBal, 2); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer bg-light border-0 py-3">
                    <a href="#" onclick="alert('Trial Balance integration coming soon');" class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-calculator me-1"></i> View Full Trial Balance
                    </a>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-dark">Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="<?= getUrl('journal/edit') ?>?id=<?php echo $entry_id; ?>" onclick="logReportAction('Initiated Transaction Edit', 'User clicked edit for transaction #<?php echo addslashes($transaction['reference_number']); ?>')" class="btn btn-light text-start border">
                            <i class="bi bi-pencil-square me-2 text-primary"></i> Edit Transaction
                        </a>
                        <form action="/api/reverse_journal.php" method="POST" onsubmit="return confirmAction('reverse', this);">
                            <input type="hidden" name="entry_id" value="<?php echo $entry_id; ?>">
                            <input type="hidden" name="redirect" value="<?= getUrl('transaction/view') ?>?id=<?php echo $entry_id; ?>">
                            <button type="submit" class="btn btn-light text-start border w-100">
                                <i class="bi bi-arrow-counterclockwise me-2 text-warning"></i> Reverse Transaction
                            </button>
                        </form>
                        <form action="/api/void_journal.php" method="POST" onsubmit="return confirmAction('void', this);">
                            <input type="hidden" name="entry_id" value="<?php echo $entry_id; ?>">
                            <input type="hidden" name="redirect" value="<?= getUrl('transaction/view') ?>?id=<?php echo $entry_id; ?>">
                            <button type="submit" class="btn btn-light text-start border w-100">
                                <i class="bi bi-slash-circle me-2 text-danger"></i> Void Transaction
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Audit Info -->
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold text-dark">Audit Information</h6>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">System ID</span>
                            <span class="font-monospace fw-bold">#<?php echo $transaction['entry_id']; ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Created</span>
                            <span class="text-dark"><?php echo date('M d, Y H:i', strtotime($transaction['created_at'])); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Source</span>
                            <span class="badge bg-light text-dark border">Manual Entry</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Log page view
    logReportAction('Viewed Transaction Details', 'User viewed details for transaction #<?php echo addslashes($transaction['reference_number']); ?>');

    window.printDetails = function() {
        logReportAction('Printed Transaction Details', 'User generated a printed report for transaction #<?php echo addslashes($transaction['reference_number']); ?>');
        window.print();
    };
});

function confirmAction(action, form) {
    if (confirm('Are you sure you want to ' + action + ' this transaction?')) {
        logReportAction(action.charAt(0).toUpperCase() + action.slice(1) + 'ed Transaction', 'User ' + action + 'ed transaction #<?php echo addslashes($transaction['reference_number']); ?>');
        return true;
    }
    return false;
}
</script>

<style>
    .bg-danger-soft { background-color: rgba(220, 53, 69, 0.1); }
    .bg-success-soft { background-color: rgba(25, 135, 84, 0.1); }
    .text-danger-soft { color: #dc3545; }
    .text-success-soft { color: #198754; }
    .card { border-radius: 12px; }
    .card-header:first-child { border-radius: 12px 12px 0 0; }
    .btn { border-radius: 8px; font-weight: 500; }
    .table thead th { font-size: 0.75rem; letter-spacing: 0.5px; }
    .avatar-sm { font-size: 1rem; }
    @media print {
        .d-print-none, .col-lg-4, .breadcrumb, .btn, .col-auto, nav, .card-footer { 
            display: none !important; 
        }
        .col-lg-8 { 
            width: 100% !important; 
            flex: 0 0 100% !important; 
            max-width: 100% !important; 
        }
        .card { 
            box-shadow: none !important; 
            border: 1px solid #dee2e6 !important; 
            margin-bottom: 20px !important; 
        }
        .card-header {
            background-color: #f8f9fa !important;
            border-bottom: 2px solid #dee2e6 !important;
        }
        body { 
            background: white !important; 
            padding: 0;
            margin: 0;
        }
        .badge {
            border: 1px solid #333 !important;
            color: #000 !important;
            background: transparent !important;
        }
    }
</style>
</div>

<?php includeFooter(); ?>
