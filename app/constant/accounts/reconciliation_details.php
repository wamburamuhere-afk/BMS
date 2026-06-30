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

                        <div class="col-md-3">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Opening Balance</label>
                            <p class="fs-5 fw-bold text-secondary mb-0">
                                <?= number_format($reconciliation['opening_balance'] ?? 0, 2) ?>
                            </p>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Statement Balance</label>
                            <p class="fs-5 fw-bold text-dark mb-0">
                                <?= number_format($reconciliation['statement_balance'], 2) ?>
                            </p>
                        </div>
                        <div class="col-md-3">
                            <label class="text-muted small text-uppercase fw-bold mb-2 d-block">Book Balance</label>
                            <p class="fs-5 fw-bold text-primary mb-0">
                                <?= number_format($reconciliation['book_balance'], 2) ?>
                            </p>
                        </div>
                        <div class="col-md-3">
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

            <?php
            // ── Two-column reconciliation statement (print view) ───────────────
            // Query uncleared lines for the bank-side column
            $unclearedLines = $pdo->prepare(
                "SELECT transaction_type, SUM(amount) AS total
                   FROM bank_transactions
                  WHERE bank_account_id = ?
                    AND transaction_date BETWEEN ? AND ?
                    AND COALESCE(matching_status,'unmatched') NOT IN ('matched','manual','reconciled','ignored')
                  GROUP BY transaction_type"
            );
            $unclearedLines->execute([
                $reconciliation['bank_account_id'],
                $reconciliation['period_start'],
                $reconciliation['period_end'],
            ]);
            $unclearedDeposits = 0.0; $unclearedWithdrawals = 0.0;
            foreach ($unclearedLines->fetchAll(PDO::FETCH_ASSOC) as $ul) {
                if ($ul['transaction_type'] === 'deposit')    $unclearedDeposits    = (float)$ul['total'];
                else                                          $unclearedWithdrawals = (float)$ul['total'];
            }
            $adjBankBalance = round((float)$reconciliation['statement_balance'] + $unclearedDeposits - $unclearedWithdrawals, 2);

            // Query adjustments for the book-side column
            $adjRows = $pdo->prepare(
                "SELECT type, SUM(amount) AS total FROM bank_reconciliation_adjustments
                  WHERE reconciliation_id = ? GROUP BY type"
            );
            $adjRows->execute([$reconciliation_id]);
            $adjAdds = 0.0; $adjDeducts = 0.0;
            $addTypes = ['interest_earned','other_in'];
            foreach ($adjRows->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                if (in_array($ar['type'], $addTypes, true)) $adjAdds    += (float)$ar['total'];
                else                                        $adjDeducts += (float)$ar['total'];
            }
            $adjBookBalance = round((float)$reconciliation['book_balance'] + $adjAdds - $adjDeducts, 2);
            ?>
            <!-- Two-column Reconciliation Statement (print-only) -->
            <div class="card border-0 mb-4 d-none d-print-block">
                <div class="card-header bg-white border-bottom py-2">
                    <h5 class="mb-0 fw-bold text-dark text-uppercase" style="font-size:11pt;letter-spacing:1px;">Reconciliation Statement</h5>
                </div>
                <div class="card-body p-0">
                    <div class="row g-0">
                        <!-- Bank Side -->
                        <div class="col-6 border-end p-3">
                            <p class="fw-bold text-uppercase mb-2" style="font-size:9pt;letter-spacing:.5px;color:#052c65;">Bank Statement Side</p>
                            <table class="table table-sm mb-0 small">
                                <tr><td>Statement Balance</td><td class="text-end fw-semibold"><?= number_format($reconciliation['statement_balance'], 2) ?></td></tr>
                                <?php if ($unclearedDeposits > 0): ?>
                                <tr><td class="text-muted ps-3">Add: Deposits in Transit</td><td class="text-end text-success">+<?= number_format($unclearedDeposits, 2) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($unclearedWithdrawals > 0): ?>
                                <tr><td class="text-muted ps-3">Less: Outstanding Cheques</td><td class="text-end text-danger">(<?= number_format($unclearedWithdrawals, 2) ?>)</td></tr>
                                <?php endif; ?>
                                <tr class="border-top"><td class="fw-bold">Adjusted Bank Balance</td><td class="text-end fw-bold"><?= number_format($adjBankBalance, 2) ?></td></tr>
                            </table>
                        </div>
                        <!-- Book Side -->
                        <div class="col-6 p-3">
                            <p class="fw-bold text-uppercase mb-2" style="font-size:9pt;letter-spacing:.5px;color:#052c65;">Book (GL) Side</p>
                            <table class="table table-sm mb-0 small">
                                <tr><td>Book Balance (GL)</td><td class="text-end fw-semibold"><?= number_format($reconciliation['book_balance'], 2) ?></td></tr>
                                <?php if ($adjAdds > 0): ?>
                                <tr><td class="text-muted ps-3">Add: Interest / Credits</td><td class="text-end text-success">+<?= number_format($adjAdds, 2) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($adjDeducts > 0): ?>
                                <tr><td class="text-muted ps-3">Less: Charges / Debits</td><td class="text-end text-danger">(<?= number_format($adjDeducts, 2) ?>)</td></tr>
                                <?php endif; ?>
                                <tr class="border-top"><td class="fw-bold">Adjusted Book Balance</td><td class="text-end fw-bold"><?= number_format($adjBookBalance, 2) ?></td></tr>
                            </table>
                        </div>
                    </div>
                    <div class="p-2 text-center border-top <?= abs($adjBankBalance - $adjBookBalance) < 0.01 ? 'bg-success' : 'bg-danger' ?> text-white small fw-bold">
                        <?php if (abs($adjBankBalance - $adjBookBalance) < 0.01): ?>
                            <i class="bi bi-check-circle me-1"></i> BALANCED — Adjusted Bank Balance equals Adjusted Book Balance (<?= number_format($adjBookBalance, 2) ?>)
                        <?php else: ?>
                            <i class="bi bi-exclamation-triangle me-1"></i> UNBALANCED — Difference: <?= number_format($adjBankBalance - $adjBookBalance, 2) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Matching Worksheet (Bank Statement line matching) -->
            <div class="card shadow-sm border-0 mb-4 d-print-none" id="matchCard" style="border-radius:12px;">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-check2-square text-primary me-2"></i>Matching Worksheet</h5>
                    <span class="small text-muted">Tick each line that has cleared the bank statement</span>
                </div>
                <div class="card-body">
                    <!-- Live reconciliation maths -->
                    <div class="row g-2 mb-3" id="matchSummary">
                        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center" style="border-color:#b6ccfe!important;"><div class="small text-muted text-uppercase fw-bold" style="font-size:.62rem;">Statement</div><div class="fw-bold" id="m-statement">—</div></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center" style="border-color:#b6ccfe!important;"><div class="small text-muted text-uppercase fw-bold" style="font-size:.62rem;">Book</div><div class="fw-bold text-primary" id="m-book">—</div></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center" style="border-color:#b6ccfe!important;"><div class="small text-muted text-uppercase fw-bold" style="font-size:.62rem;">Uncleared</div><div class="fw-bold" id="m-uncleared">—</div></div></div>
                        <div class="col-6 col-md-3"><div class="border rounded p-2 text-center" id="m-diff-card" style="background:#e7f0ff;border:1px solid #b6ccfe;"><div class="small text-muted text-uppercase fw-bold" style="font-size:.62rem;">Difference</div><div class="fw-bold" id="m-diff" style="color:#052c65;">—</div></div></div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="matchTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width:46px;">Clear</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Reference</th>
                                    <th class="text-end">Money In</th>
                                    <th class="text-end">Money Out</th>
                                    <th class="text-center">Status</th>
                                    <?php if ($reconciliation['status'] === 'pending'): ?>
                                    <th class="text-center" style="width:60px;"></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="8" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($reconciliation['status'] === 'pending'): ?>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <button class="btn btn-outline-primary btn-sm" onclick="openAddAdjustment()">
                            <i class="bi bi-plus-circle me-1"></i> Add Adjustment
                        </button>
                        <button class="btn btn-primary" id="btnFinalizeMatch" onclick="finalizeMatching()" disabled>
                            <i class="bi bi-lock-fill me-1"></i> Finalize Reconciliation
                        </button>
                    </div>
                    <?php elseif ($reconciliation['status'] === 'reconciled'): ?>
                    <div class="alert alert-success mt-3 mb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <span><i class="bi bi-check-all me-2"></i> This reconciliation is finalized — the matched lines are locked.</span>
                        <?php if (canApprove('bank_reconciliation')): ?>
                        <button class="btn btn-sm btn-outline-danger" onclick="openUnreconcile()">
                            <i class="bi bi-unlock me-1"></i> Unreconcile
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bank Adjustments panel -->
            <div class="card shadow-sm border-0 mb-4 d-print-none" id="adjCard" style="border-radius:12px;">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-journal-plus text-warning me-2"></i>Bank Adjustments</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="adjTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Memo</th>
                                    <th class="text-end">Amount</th>
                                    <th class="text-center small text-muted">JE#</th>
                                </tr>
                            </thead>
                            <tbody id="adjTableBody">
                                <tr><td colspan="5" class="text-center text-muted py-3 small">No adjustments yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

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
const REC_ID    = <?= (int)$reconciliation_id ?>;
const REC_STATUS = '<?= addslashes($reconciliation['status']) ?>';
const LINES_URL = '<?= buildUrl('api/account/get_reconciliation_lines.php') ?>';
const MATCH_URL = '<?= buildUrl('api/account/toggle_reconciliation_match.php') ?>';
const CSRF      = '<?= csrf_token() ?>';
const fmtN = n => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const escH = s => $('<div>').text(s == null ? '' : s).html();

$(document).ready(function () { loadMatchLines(); });

function printReconciliationReport() { window.print(); }

// ── Matching worksheet ────────────────────────────────────────────────────
function loadMatchLines() {
    $.getJSON(LINES_URL, { reconciliation_id: REC_ID })
        .done(function (res) {
            if (!res || !res.success) {
                $('#matchTable tbody').html('<tr><td colspan="7" class="text-center text-danger py-4">Could not load lines.</td></tr>');
                return;
            }
            $('#m-statement').text(fmtN(res.reconciliation.statement_balance));
            $('#m-book').text(fmtN(res.reconciliation.book_balance));
            $('#m-uncleared').text(fmtN(res.summary.uncleared_movement));
            $('#m-diff').text(fmtN(res.summary.difference));
            const $c = $('#m-diff-card');
            if (res.summary.balanced) { $c.css({ background:'#d1e7dd', borderColor:'#badbcc' }); $('#m-diff').css('color','#0f5132'); $('#btnFinalizeMatch').prop('disabled', false); }
            else { $c.css({ background:'#e7f0ff', borderColor:'#b6ccfe' }); $('#m-diff').css('color','#052c65'); $('#btnFinalizeMatch').prop('disabled', true); }

            const locked = (res.reconciliation.status !== 'pending');
            const cols = locked ? 7 : 8;
            if (!res.lines.length) {
                $(`#matchTable tbody`).html(`<tr><td colspan="${cols}" class="text-center text-muted py-4">No bank-statement lines in this account/period.</td></tr>`);
                loadAdjustments();
                return;
            }
            let html = '';
            res.lines.forEach(l => {
                const isIn = l.transaction_type === 'deposit';
                const checked = l.matched ? 'checked' : '';
                const dis = (locked || l.ignored) ? 'disabled' : '';
                const badge = l.ignored
                    ? '<span class="badge bg-secondary">Ignored</span>'
                    : (l.matched ? '<span class="badge" style="background:#052c65;">Cleared</span>'
                                 : '<span class="badge bg-light text-dark border">Uncleared</span>');
                const createBtn = (!locked && !l.matched && !l.ignored)
                    ? `<button class="btn btn-xs btn-outline-warning py-0 px-1 create-from-line" title="Create GL entry for this line"
                          data-id="${l.transaction_id}" data-amt="${l.amount}" data-type="${l.transaction_type}" data-date="${l.transaction_date}" data-desc="${escH(l.description)}" style="font-size:0.68rem;">
                          <i class="bi bi-journal-plus"></i></button>`
                    : '';
                html += `<tr class="${l.ignored ? 'opacity-50' : ''}">
                    <td class="text-center"><input type="checkbox" class="form-check-input match-chk" data-id="${l.transaction_id}" ${checked} ${dis}></td>
                    <td>${escH(l.transaction_date)}</td>
                    <td class="small">${escH(l.description)}</td>
                    <td><small class="text-muted">${escH(l.reference_number || '')}</small></td>
                    <td class="text-end text-success">${isIn ? fmtN(l.amount) : ''}</td>
                    <td class="text-end text-danger">${!isIn ? fmtN(l.amount) : ''}</td>
                    <td class="text-center">${badge}</td>
                    ${!locked ? `<td class="text-center">${createBtn}</td>` : ''}
                </tr>`;
            });
            $('#matchTable tbody').html(html);
            $('.match-chk').off('change').on('change', function () {
                const id = $(this).data('id');
                toggleMatch($(this).is(':checked') ? 'match' : 'unmatch', id);
            });
            $('.create-from-line').off('click').on('click', function () {
                const btn = $(this);
                $('#cfl_transaction_id').val(btn.data('id'));
                $('#cfl_amount').text(fmtN(btn.data('amt')));
                $('#cfl_type').text(btn.data('type') === 'deposit' ? 'Money In (Deposit)' : 'Money Out (Withdrawal)');
                $('#cfl_date').text(btn.data('date'));
                $('#cfl_desc').text(btn.data('desc'));
                $('#cfl_memo').val(btn.data('desc'));
                $('#cfl_gl_account_id').val('').trigger('change');
                new bootstrap.Modal(document.getElementById('createFromLineModal')).show();
            });
            loadAdjustments();
        })
        .fail(function () {
            $('#matchTable tbody').html('<tr><td colspan="8" class="text-center text-danger py-4">Server error loading lines.</td></tr>');
        });
}

function toggleMatch(action, txnId) {
    $.ajax({
        url: MATCH_URL, type: 'POST', dataType: 'json',
        data: { reconciliation_id: REC_ID, action: action, transaction_id: txnId, _csrf: CSRF },
        success: function (res) {
            if (res.success) { loadMatchLines(); }
            else { Swal.fire({ icon: 'error', title: 'Error', text: res.message }); loadMatchLines(); }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); loadMatchLines(); }
    });
}

function finalizeMatching() {
    Swal.fire({
        title: 'Finalize reconciliation?',
        text: 'The matched lines will be locked and the reconciliation marked reconciled.',
        icon: 'question', showCancelButton: true, confirmButtonColor: '#0d6efd', confirmButtonText: 'Yes, finalize'
    }).then(r => {
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: MATCH_URL, type: 'POST', dataType: 'json',
            data: { reconciliation_id: REC_ID, action: 'finalize', _csrf: CSRF },
            success: function (res) {
                if (res.success) { Swal.fire({ icon: 'success', title: 'Reconciled!', text: 'The reconciliation is balanced and finalized.', timer: 1800, showConfirmButton: false }).then(() => location.reload()); }
                else { Swal.fire({ icon: 'error', title: 'Cannot finalize', text: res.message }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
        });
    });
}

// ── Status / delete (upgraded to SweetAlert2 per ui-constants §UI-4) ───────
function updateStatus(newStatus) {
    Swal.fire({
        title: 'Change status?',
        text: 'Set this reconciliation to "' + newStatus + '".',
        icon: newStatus === 'cancelled' ? 'warning' : 'question',
        showCancelButton: true, confirmButtonColor: newStatus === 'cancelled' ? '#dc3545' : '#0d6efd',
        confirmButtonText: 'Yes, proceed'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/account/update_reconciliation_status.php') ?>', type: 'POST', dataType: 'json',
            data: { reconciliation_id: REC_ID, status: newStatus, _csrf: CSRF },
            success: function (res) {
                if (res && res.success) { location.reload(); }
                else { Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Unknown error' }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
        });
    });
}

function deleteReconciliation() {
    Swal.fire({
        title: 'Delete this reconciliation?',
        text: 'This action cannot be undone.',
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/account/delete_reconciliation.php') ?>', type: 'POST', dataType: 'json',
            data: { reconciliation_id: REC_ID, _csrf: CSRF },
            success: function (res) {
                if (res && res.success) { window.location.href = '<?= getUrl('bank-reconciliation') ?>'; }
                else { Swal.fire({ icon: 'error', title: 'Error', text: (res && res.message) || 'Unknown error' }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
        });
    });
}

// ── Unreconcile (Phase 4) ────────────────────────────────────────────────────
function openUnreconcile() {
    $('#unreconcile_reason').val('');
    new bootstrap.Modal(document.getElementById('unreconcileModal')).show();
}

// ── Bank Adjustments (Phase 2) ────────────────────────────────────────────────
function loadAdjustments() {
    $.getJSON('<?= buildUrl('api/account/get_reconciliation_adjustments.php') ?>', { reconciliation_id: REC_ID })
        .done(function (res) {
            if (!res || !res.adjustments || !res.adjustments.length) {
                $('#adjTableBody').html('<tr><td colspan="5" class="text-center text-muted py-3 small">No adjustments yet.</td></tr>');
                return;
            }
            const labels = { bank_charge:'Bank Charge', interest_earned:'Interest Earned', nsf:'NSF / Bounced', standing_order:'Standing Order', other_out:'Other (Out)', other_in:'Other (In)' };
            let html = '';
            res.adjustments.forEach(a => {
                html += `<tr>
                    <td class="small">${escH(a.adjustment_date)}</td>
                    <td><span class="badge bg-light text-dark border">${labels[a.type] || a.type}</span></td>
                    <td class="small">${escH(a.memo || '—')}</td>
                    <td class="text-end small">${fmtN(a.amount)}</td>
                    <td class="text-center"><small class="text-muted">${a.journal_entry_id ? '#'+a.journal_entry_id : '—'}</small></td>
                </tr>`;
            });
            $('#adjTableBody').html(html);
        });
}

function openAddAdjustment() {
    $('#adj_adjustment_date').val('<?= date('Y-m-d') ?>');
    $('#adj_amount').val('');
    $('#adj_memo').val('');
    $('#adj_gl_account_id').val('').trigger('change');
    new bootstrap.Modal(document.getElementById('addAdjustmentModal')).show();
}

$(document).ready(function () {
    // Init Select2 for GL account pickers in the two modals
    const modalSelect2Init = function (modalId, selectId) {
        $(`#${modalId}`).on('shown.bs.modal', function () {
            if (!$(`#${selectId}`).hasClass('select2-hidden-accessible')) {
                $(`#${selectId}`).select2({
                    theme: 'bootstrap-5', dropdownParent: $(`#${modalId}`),
                    placeholder: 'Search account…', allowClear: true, width: '100%',
                    ajax: {
                        url: '<?= buildUrl('api/account/search_accounts.php') ?>',
                        dataType: 'json', delay: 250, minimumInputLength: 1,
                        data: function (p) { return { q: p.term }; },
                        processResults: function (d) { return { results: d.results || [] }; }
                    }
                });
            }
        });
    };
    modalSelect2Init('addAdjustmentModal', 'adj_gl_account_id');
    modalSelect2Init('createFromLineModal', 'cfl_gl_account_id');

    // ── Add Adjustment form submit ────────────────────────────────────────────
    $('#adjForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Posting…');
        $.ajax({
            url: '<?= buildUrl('api/account/add_reconciliation_adjustment.php') ?>',
            type: 'POST', dataType: 'json',
            data: new FormData(this), contentType: false, processData: false,
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addAdjustmentModal'))?.hide();
                    Swal.fire({ icon: 'success', title: 'Adjustment posted!', timer: 1500, showConfirmButton: false })
                        .then(() => loadMatchLines());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    // ── Unreconcile form submit ───────────────────────────────────────────────
    $('#unreconcileForm').on('submit', function (e) {
        e.preventDefault();
        const reason = $('#unreconcile_reason').val().trim();
        if (reason.length < 10) { Swal.fire({ icon:'warning', title:'Reason required', text:'Please provide at least 10 characters.' }); return; }
        Swal.fire({
            title: 'Unreconcile this period?',
            text: 'This will unlock the reconciliation. Reason: ' + reason,
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, unlock'
        }).then(r => {
            if (!r.isConfirmed) return;
            const btn = $(this).find('[type="submit"]');
            const orig = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');
            $.ajax({
                url: '<?= buildUrl('api/account/unreconcile.php') ?>',
                type: 'POST', dataType: 'json',
                data: new FormData(this), contentType: false, processData: false,
                success: function (res) {
                    if (res.success) {
                        bootstrap.Modal.getInstance(document.getElementById('unreconcileModal'))?.hide();
                        Swal.fire({ icon:'success', title:'Unlocked', text: res.message, timer:1800, showConfirmButton:false }).then(() => location.reload());
                    } else {
                        Swal.fire({ icon:'error', title:'Error', text: res.message });
                    }
                },
                error: function () { Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); },
                complete: function () { btn.prop('disabled', false).html(orig); }
            });
        });
    });

    // ── Create from statement line form submit ────────────────────────────────
    $('#cflForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Posting…');
        $.ajax({
            url: '<?= buildUrl('api/account/create_entry_from_statement_line.php') ?>',
            type: 'POST', dataType: 'json',
            data: new FormData(this), contentType: false, processData: false,
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('createFromLineModal'))?.hide();
                    Swal.fire({ icon: 'success', title: 'Entry posted & line matched!', timer: 1500, showConfirmButton: false })
                        .then(() => loadMatchLines());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });
});
</script>

<!-- Add Adjustment Modal -->
<?php if ($reconciliation['status'] === 'pending' && canEdit('bank_reconciliation')): ?>
<div class="modal fade" id="addAdjustmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#052c65;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-journal-plus me-2"></i>Add Bank Adjustment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="adjForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf"             value="<?= csrf_token() ?>">
                    <input type="hidden" name="reconciliation_id" value="<?= (int)$reconciliation_id ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" id="adj_type" required>
                                <option value="">— select —</option>
                                <option value="bank_charge">Bank Charge / Fee</option>
                                <option value="interest_earned">Interest Earned</option>
                                <option value="nsf">NSF / Bounced Cheque</option>
                                <option value="standing_order">Standing Order / Direct Debit</option>
                                <option value="other_out">Other Withdrawal</option>
                                <option value="other_in">Other Deposit</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="adjustment_date" id="adj_adjustment_date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="amount" id="adj_amount" required placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">GL Account <span class="text-danger">*</span></label>
                            <select class="form-select" name="gl_account_id" id="adj_gl_account_id" required></select>
                            <div class="form-text text-muted small">Contra account (e.g. Bank Charges Expense, Interest Income, A/R)</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Memo</label>
                            <input type="text" class="form-control" name="memo" id="adj_memo" placeholder="Optional description">
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Posting an adjustment creates a balanced journal entry and automatically adds a cleared line to this worksheet.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Post Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Entry from Statement Line Modal -->
<div class="modal fade" id="createFromLineModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="bi bi-journal-arrow-up me-2"></i>Create GL Entry for Unrecorded Line</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="cflForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf"             value="<?= csrf_token() ?>">
                    <input type="hidden" name="reconciliation_id" value="<?= (int)$reconciliation_id ?>">
                    <input type="hidden" name="transaction_id"    id="cfl_transaction_id">

                    <div class="mb-3 p-2 bg-light rounded border small">
                        <div class="row g-1">
                            <div class="col-4 text-muted">Date</div><div class="col-8 fw-semibold" id="cfl_date"></div>
                            <div class="col-4 text-muted">Amount</div><div class="col-8 fw-semibold text-primary" id="cfl_amount"></div>
                            <div class="col-4 text-muted">Type</div><div class="col-8" id="cfl_type"></div>
                            <div class="col-4 text-muted">Description</div><div class="col-8 text-truncate" id="cfl_desc"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contra GL Account <span class="text-danger">*</span></label>
                        <select class="form-select" name="gl_account_id" id="cfl_gl_account_id" required></select>
                        <div class="form-text small text-muted">The account this movement should post to (e.g. Expense, Income, A/R)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Memo (override description)</label>
                        <input type="text" class="form-control" name="memo" id="cfl_memo">
                    </div>
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        This will post a journal entry and auto-match this line to the reconciliation.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle me-1"></i> Post &amp; Match</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Unreconcile Modal (Phase 4) -->
<?php if ($reconciliation['status'] === 'reconciled' && canApprove('bank_reconciliation')): ?>
<div class="modal fade" id="unreconcileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-unlock me-2"></i>Unreconcile Period</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="unreconcileForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf"             value="<?= csrf_token() ?>">
                    <input type="hidden" name="reconciliation_id" value="<?= (int)$reconciliation_id ?>">
                    <div class="alert alert-danger small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <strong>Warning:</strong> Unreconciling will unlock this period. Journal entries dated
                        <?= date('M d, Y', strtotime($reconciliation['period_start'])) ?>–<?= date('M d, Y', strtotime($reconciliation['period_end'])) ?>
                        that touch this bank account can then be edited or voided.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason for unreconciling <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="reason" id="unreconcile_reason" rows="3"
                            placeholder="Provide a detailed reason (min 10 characters)" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-unlock me-1"></i> Unreconcile</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

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
</style>

<?php
includeFooter();
ob_end_flush();
?>
