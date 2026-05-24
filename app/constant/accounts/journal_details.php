<?php
// Start the buffer
ob_start();

// Ensure database connection is available
global $pdo, $pdo_accounts;

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
// Phase 5b — supply explicit page-key; argless call was ineffective.
autoEnforcePermission('journals');

includeHeader();

// Get Journal ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirectTo('journals'); // Use redirectTo for consistency
}

$entry_id = $_GET['id'];

// Fetch Journal Header
$stmt = $pdo->prepare("
    SELECT je.*, u.username as created_by_name 
    FROM journal_entries je 
    LEFT JOIN users u ON je.created_by = u.user_id 
    WHERE je.entry_id = ?
");
$stmt->execute([$entry_id]);
$journal = $stmt->fetch();

if (!$journal) {
    echo "<div class='alert alert-danger'>Journal entry not found.</div>";
    includeFooter();
    exit;
}

// Fetch Journal Items
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


$statusClass = get_status_badge($journal['status']);
?>

<div class="container-fluid mt-4">
    <!-- Print Header -->
    <div class="d-none d-print-block text-center mb-4">
        <?php 
        $c_logo = getSetting('company_logo');
        $c_name = getSetting('company_name', 'BMS');
        if(!empty($c_logo)): ?>
            <div class="mb-3">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= htmlspecialchars($c_name) ?></h1>
        <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">Journal Entry Report</h2>
        <h5 class="text-muted">#<?php echo htmlspecialchars($journal['reference_number']); ?> | <?php echo date('F d, Y', strtotime($journal['entry_date'])); ?></h5>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 10px; margin-bottom: 20px;"></div>
    </div>
    <div class="row mb-4 align-items-center">
        <div class="col">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="/dashboard">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= getUrl('journals') ?>">General Journal</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Journal Details</li>
                </ol>
            </nav>
            <h2>Journal Entry #<?php echo htmlspecialchars($journal['reference_number']); ?></h2>
        </div>
        <div class="col-auto">
            <a href="<?= getUrl('journals') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Journals
            </a>
            <button onclick="printJournalEnty()" class="btn btn-outline-primary">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>

    <div class="row">
        <!-- Main Details -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold text-dark">Journal Information</h5>
                    <span class="badge rounded-pill bg-<?php echo $statusClass; ?> px-3 py-2">
                        <i class="bi bi-circle-fill me-1 small"></i> <?php echo strtoupper($journal['status']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-12">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Description</label>
                            <p class="fs-5 fw-semibold text-dark mb-0"><?php echo htmlspecialchars($journal['description']); ?></p>
                        </div>
                        
                        <div class="col-12"><hr class="my-0 opacity-10"></div>

                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Entry Date</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-calendar3 me-2 text-primary"></i><?php echo date('F d, Y', strtotime($journal['entry_date'])); ?></p>
                        </div>
                        <div class="col-md-4">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Reference</label>
                            <p class="mb-0 fw-medium text-dark"><i class="bi bi-hash me-1 text-primary"></i><?php echo htmlspecialchars($journal['reference_number'] ?? 'N/A'); ?></p>
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
                                <p class="mb-0 fw-medium text-dark"><?php echo htmlspecialchars($journal['created_by_name'] ?? 'System'); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Last Modified</label>
                            <p class="mb-0 fw-medium text-dark">
                                <i class="bi bi-clock-history me-2 text-info"></i>
                                <?php echo $journal['updated_at'] ? date('M d, Y H:i', strtotime($journal['updated_at'])) : 'Never'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ledger Entries Table -->
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
                                    <td colspan="2" class="ps-4 py-3 text-dark">TOTAL JOURNAL VALUE</td>
                                    <td class="py-3 text-end text-danger"><?php echo number_format($total_amount, 2); ?></td>
                                    <td class="py-3 text-end text-success pe-4"><?php echo number_format($total_amount, 2); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <?php if (!empty($journal['notes'])): ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark">Internal Notes</h5>
                </div>
                <div class="card-body">
                    <div class="p-3 bg-light rounded border-start border-4 border-primary">
                        <p class="mb-0 text-dark italic"><?php echo nl2br(htmlspecialchars($journal['notes'])); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Financial Impact -->
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-primary text-white py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up-arrow me-2"></i>Financial Impact</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <?php foreach ($items as $item): 
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
                    <a href="trial_balance.php?as_of_date=<?php echo $journal['entry_date']; ?>" class="btn btn-sm btn-outline-primary w-100">
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
                        <?php if (canEdit('journals')): ?>
                        <a href="<?= getUrl('journal/edit') ?>?id=<?php echo $entry_id; ?>" class="btn btn-light text-start border" onclick="logReportAction('Initiated Journal Edit', 'User clicked edit for journal entry #<?php echo addslashes($journal['reference_number']); ?>')">
                            <i class="bi bi-pencil-square me-2 text-primary"></i> Edit Journal Entry
                        </a>
                        <?php endif; ?>
                        
                        <?php if (canEdit('journals') && $journal['status'] === 'posted'): ?>
                        <form action="/api/reverse_journal.php" method="POST" onsubmit="return confirmJournalAction('reverse', this);">
                            <input type="hidden" name="entry_id" value="<?php echo $entry_id; ?>">
                            <input type="hidden" name="redirect" value="<?= getUrl('journal/view') ?>?id=<?php echo $entry_id; ?>">
                            <button type="submit" class="btn btn-light text-start border w-100">
                                <i class="bi bi-arrow-counterclockwise me-2 text-warning"></i> Reverse Entry
                            </button>
                        </form>
                        <?php endif; ?>
                        
                        <?php if (canEdit('journals') && $journal['status'] !== 'void'): ?>
                        <form action="/api/void_journal.php" method="POST" onsubmit="return confirmJournalAction('void', this);">
                            <input type="hidden" name="entry_id" value="<?php echo $entry_id; ?>">
                            <input type="hidden" name="redirect" value="<?= getUrl('journal/view') ?>?id=<?php echo $entry_id; ?>">
                            <button type="submit" class="btn btn-light text-start border w-100">
                                <i class="bi bi-slash-circle me-2 text-danger"></i> Void Entry
                            </button>
                        </form>
                        <?php endif; ?>
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
                            <span class="font-monospace fw-bold">#<?php echo $journal['entry_id']; ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between py-3">
                            <span class="text-muted">Created</span>
                            <span class="text-dark"><?php echo date('M d, Y H:i', strtotime($journal['created_at'])); ?></span>
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
        logReportAction('Viewed Journal Entry Details', 'User viewed details for journal entry #<?php echo addslashes($journal['reference_number']); ?>');
    });

    function printJournalEnty() {
        logReportAction('Printed Journal Entry', 'User generated a printed journal entry #<?php echo addslashes($journal['reference_number']); ?>');
        window.print();
    }

    function confirmJournalAction(action, form) {
        if (confirm('Are you sure you want to ' + action + ' this entry?')) {
            logReportAction(action.charAt(0).toUpperCase() + action.slice(1) + 'ed Journal Entry', 'User ' + action + 'ed journal entry #<?php echo addslashes($journal['reference_number']); ?>');
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
    @page { margin: 10mm 8mm 16mm 8mm; }
    @media print {
        .col-lg-4, .breadcrumb, .btn-outline-secondary, .Quick-Actions { display: none !important; }
        .col-lg-8 { width: 100% !important; }
        .card { box-shadow: none !important; border: 1px solid #eee !important; }
    }
</style>

<?php includeFooter(); ?>
