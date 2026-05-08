<?php
/**
 * Cash Register Shift Details
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';

// Check permissions
autoEnforcePermission('cash_register');

includeHeader();

$shift_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['shift']) ? (int)$_GET['shift'] : 0);

if (!$shift_id) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Invalid Shift ID</div></div>";
    includeFooter();
    exit();
}

// Fetch Shift Info
$stmt = $pdo->prepare("
    SELECT s.*, u.username as cashier_name, c.username as closed_by_name
    FROM cash_register_shifts s 
    LEFT JOIN users u ON s.user_id = u.user_id 
    LEFT JOIN users c ON s.closed_by = c.user_id
    WHERE s.shift_id = ?
");
$stmt->execute([$shift_id]);
$shift = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shift) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Shift not found</div></div>";
    includeFooter();
    exit();
}

// Fetch Transactions for this shift
$tx_stmt = $pdo->prepare("
    SELECT * FROM cash_register_transactions 
    WHERE shift_id = ? 
    ORDER BY created_at DESC
");
$tx_stmt->execute([$shift_id]);
$transactions = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$cash_sales = 0; $cash_in = 0; $cash_out = 0; $refunds = 0;
foreach ($transactions as $tx) {
    if ($tx['transaction_type'] == 'sale' && $tx['payment_method'] == 'cash') $cash_sales += $tx['amount'];
    if ($tx['transaction_type'] == 'cash_in') $cash_in += $tx['amount'];
    if ($tx['transaction_type'] == 'cash_out') $cash_out += $tx['amount'];
    if ($tx['transaction_type'] == 'refund') $refunds += $tx['amount'];
}

$expected_balance = $shift['starting_cash'] + $cash_sales + $cash_in - $cash_out - $refunds;
?>

<div class="container-fluid py-4">
    <!-- Print Header -->
    <div class="d-none d-print-block">
        <div class="text-center mb-4">
            
            <h3 style="color: #333 !important; font-weight: 700; text-transform: uppercase; margin: 5px 0; font-size: 18pt; letter-spacing: 1px;">CASH REGISTER SHIFT REPORT</h3>
            <div style="border-bottom: 4px solid #0d6efd; margin-top: 15px; margin-bottom: 25px; width: 150px; margin-left: auto; margin-right: auto;"></div>
        </div>
    </div>
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="fw-bold mb-0 text-dark"><i class="bi bi-file-earmark-text me-2"></i> Shift Details: <?= $shift['shift_code'] ?></h2>
                    <p class="text-muted mb-0">Full summary and transaction log for this shift</p>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
                    <a href="<?= getUrl('cash_register') ?>" class="btn btn-light border"><i class="bi bi-arrow-left me-1"></i> Back</a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Summary Stats -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold">Shift Summary</h6>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">Status</span>
                            <span class="badge rounded-pill bg-<?= $shift['status'] == 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($shift['status']) ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">Cashier</span>
                            <span class="fw-bold"><?= htmlspecialchars($shift['cashier_name'] ?? '') ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">Started</span>
                            <span><?= date('d M Y, H:i', strtotime($shift['start_time'])) ?></span>
                        </li>
                        <?php if($shift['end_time']): ?>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">Closed At</span>
                            <span><?= date('d M Y, H:i', strtotime($shift['end_time'])) ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">Closed By</span>
                            <span><?= htmlspecialchars($shift['closed_by_name'] ?: 'System') ?></span>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold">Financial Reconciliation</h6>
                </div>
                <div class="card-body">
                    <div class="p-3 bg-light rounded text-center mb-3">
                        <small class="text-muted d-block uppercase mb-1">Total Sales Recorded</small>
                        <h3 class="fw-bold text-success mb-0"><?= format_currency($shift['total_sales']) ?></h3>
                    </div>
                    
                    <div class="reconciliation-row d-flex justify-content-between mb-2">
                        <span>Starting Cash:</span>
                        <span class="fw-bold"><?= format_currency($shift['starting_cash']) ?></span>
                    </div>
                    <div class="reconciliation-row d-flex justify-content-between mb-2">
                        <span>Cash Sales:</span>
                        <span class="fw-bold text-success">+ <?= format_currency($cash_sales) ?></span>
                    </div>
                    <div class="reconciliation-row d-flex justify-content-between mb-2">
                        <span>Cash In:</span>
                        <span class="fw-bold text-success">+ <?= format_currency($cash_in) ?></span>
                    </div>
                    <div class="reconciliation-row d-flex justify-content-between mb-2">
                        <span>Cash Out:</span>
                        <span class="fw-bold text-danger">- <?= format_currency($cash_out) ?></span>
                    </div>
                    <hr>
                    <div class="reconciliation-row d-flex justify-content-between mb-2 h5">
                        <span class="fw-bold text-primary">Expected Cash:</span>
                        <span class="fw-bold text-primary"><?= format_currency($expected_balance) ?></span>
                    </div>
                    
                    <?php if($shift['status'] == 'closed'): ?>
                    <div class="reconciliation-row d-flex justify-content-between mb-2 h5">
                        <span class="fw-bold">Actual Cash:</span>
                        <span class="fw-bold"><?= format_currency($shift['actual_cash']) ?></span>
                    </div>
                    <?php 
                    $diff = $shift['actual_cash'] - $expected_balance;
                    if ($diff != 0):
                    ?>
                    <div class="alert alert-<?= $diff > 0 ? 'success' : 'danger' ?> mt-3 py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Difference:</span>
                            <span class="fw-bold"><?= format_currency($diff) ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if($shift['notes']): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-bold mb-2">Shift Notes:</h6>
                    <p class="text-muted mb-0 small"><?= nl2br(htmlspecialchars($shift['notes'] ?? '')) ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Transactions List -->
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2 text-primary"></i> Transactions Log</h5>
                    <span class="badge bg-light text-dark"><?= count($transactions) ?> Entries</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">TIME</th>
                                    <th>TYPE</th>
                                    <th>REFERENCE</th>
                                    <th>METHOD</th>
                                    <th class="text-end pe-4">AMOUNT</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted">No transactions found for this shift.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $tx): 
                                        $type_class = ($tx['transaction_type'] == 'sale' || $tx['transaction_type'] == 'cash_in') ? 'success' : 'danger';
                                    ?>
                                    <tr>
                                        <td class="ps-4"><?= date('H:i:s', strtotime($tx['created_at'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $type_class ?>-subtle text-<?= $type_class ?> rounded-pill">
                                                <?= ucfirst(str_replace('_', ' ', $tx['transaction_type'])) ?>
                                            </span>
                                        </td>
                                        <td><small class="text-muted"><?= $tx['reference_number'] ?: '-' ?></small></td>
                                        <td><span class="text-uppercase small fw-bold"><?= $tx['payment_method'] ?: 'N/A' ?></span></td>
                                        <td class="text-end pe-4 fw-bold <?= $type_class == 'success' ? 'text-success' : 'text-danger' ?>">
                                            <?= ($type_class == 'success' ? '+' : '-') ?> <?= format_currency($tx['amount']) ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    @page { margin: 1cm; }
    body { background: white !important; font-size: 10pt; -webkit-print-color-adjust: exact; color: #000 !important; }
    .btn, .navbar, .sidebar, .dropdown, header, footer, .d-print-none { display: none !important; }
    .container-fluid { padding: 0 !important; margin: 0 !important; max-width: 100% !important; }
    .card { border: 1px solid #000 !important; box-shadow: none !important; margin-bottom: 20px !important; border-radius: 0 !important; }
    .card-header { background-color: #f8f9fa !important; border-bottom: 1px solid #000 !important; color: #000 !important; }
    .table thead th { background-color: #f8f9fa !important; border-bottom: 2px solid #000 !important; color: #000 !important; }
    .text-success, .text-danger, .text-primary { font-weight: bold !important; }
    .badge { border: 1px solid #000 !important; color: #000 !important; background: transparent !important; }
    .col-md-4 { width: 33.33% !important; float: left; }
    .col-md-8 { width: 66.66% !important; float: left; }
    .row { display: block !important; }
    .row::after { content: ""; display: table; clear: both; }
}
.uppercase { text-transform: uppercase; letter-spacing: 1px; font-size: 0.75rem; }
</style>

<?php
includeFooter();
ob_end_flush();
?>
