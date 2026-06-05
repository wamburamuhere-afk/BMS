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
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="7" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($reconciliation['status'] === 'pending'): ?>
                    <div class="d-flex justify-content-end mt-3">
                        <button class="btn btn-primary" id="btnFinalizeMatch" onclick="finalizeMatching()" disabled>
                            <i class="bi bi-lock-fill me-1"></i> Finalize Reconciliation
                        </button>
                    </div>
                    <?php elseif ($reconciliation['status'] === 'reconciled'): ?>
                    <div class="alert alert-success mt-3 mb-0 d-flex align-items-center"><i class="bi bi-check-all me-2"></i> This reconciliation is finalized — the matched lines are locked.</div>
                    <?php endif; ?>
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
            if (!res.lines.length) {
                $('#matchTable tbody').html('<tr><td colspan="7" class="text-center text-muted py-4">No bank-statement lines in this account/period.</td></tr>');
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
                html += `<tr class="${l.ignored ? 'opacity-50' : ''}">
                    <td class="text-center"><input type="checkbox" class="form-check-input match-chk" data-id="${l.transaction_id}" ${checked} ${dis}></td>
                    <td>${escH(l.transaction_date)}</td>
                    <td class="small">${escH(l.description)}</td>
                    <td><small class="text-muted">${escH(l.reference_number || '')}</small></td>
                    <td class="text-end text-success">${isIn ? fmtN(l.amount) : ''}</td>
                    <td class="text-end text-danger">${!isIn ? fmtN(l.amount) : ''}</td>
                    <td class="text-center">${badge}</td>
                </tr>`;
            });
            $('#matchTable tbody').html(html);
            $('.match-chk').off('change').on('change', function () {
                const id = $(this).data('id');
                toggleMatch($(this).is(':checked') ? 'match' : 'unmatch', id);
            });
        })
        .fail(function () {
            $('#matchTable tbody').html('<tr><td colspan="7" class="text-center text-danger py-4">Server error loading lines.</td></tr>');
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
</style>

<?php
includeFooter();
ob_end_flush();
?>
