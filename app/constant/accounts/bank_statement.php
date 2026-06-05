<?php
// File: app/constant/accounts/bank_statement.php
// Per-account bank-statement register (bank_transactions) with a running
// balance — the auditable cash trail behind reconciliation. Reads the register
// written by the expense Paid step (other cash movements feed it over time).
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';   // cashBankAccounts()

autoEnforcePermission('bank_reconciliation');
includeHeader();
global $pdo;

require_once __DIR__ . '/../../../helpers.php';
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'View Bank Statement',
    ($_SESSION['username'] ?? 'User') . ' opened the Bank Account Statement');

$accounts = cashBankAccounts($pdo);   // active asset/cash accounts ("Paid From" set)
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Bank Statement</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 d-print-none">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-card-list text-primary me-2"></i>Bank Account Statement</h4>
            <p class="text-muted small mb-0">Recorded cash movements with a running balance.</p>
        </div>
        <button class="btn btn-outline-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
    </div>

    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-bold text-muted">Bank / Cash Account</label>
                    <select id="acct" class="form-select">
                        <option value="">— Select account —</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= (int)$a['account_id'] ?>"><?= safe_output($a['account_name']) ?><?= $a['account_code'] ? ' (' . safe_output($a['account_code']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">From</label>
                    <input type="date" id="from" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">To</label>
                    <input type="date" id="to" class="form-control">
                </div>
                <div class="col-md-1">
                    <button id="load" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3" id="summaryCards" style="display:none;">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold text-primary" id="s_count">0</div><div class="small text-muted">Movements</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold text-success" id="s_in">0.00</div><div class="small text-muted">Money In</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold text-danger" id="s_out">0.00</div><div class="small text-muted">Money Out</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold" style="color:#052c65" id="s_close">0.00</div><div class="small text-muted">Running Balance</div></div></div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="stmtTable">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th class="text-end">Money In</th>
                        <th class="text-end">Money Out</th>
                        <th class="text-end">Balance</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody><tr><td colspan="7" class="text-center text-muted py-5">Select an account to view its statement.</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script>
function money(v){ return Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function esc(s){ return $('<div>').text(s==null?'':s).html(); }

function loadStatement(){
    const acct = $('#acct').val();
    const $tb = $('#stmtTable tbody');
    if (!acct) { $tb.html('<tr><td colspan="7" class="text-center text-muted py-5">Select an account to view its statement.</td></tr>'); $('#summaryCards').hide(); return; }
    $tb.html('<tr><td colspan="7" class="text-center py-5"><div class="spinner-border text-primary"></div></td></tr>');
    $.getJSON('<?= buildUrl('api/account/get_bank_statement.php') ?>', { account_id: acct, date_from: $('#from').val(), date_to: $('#to').val() }, function(res){
        if (!res.success){ $tb.html('<tr><td colspan="7" class="text-center text-danger py-4">Error loading statement.</td></tr>'); return; }
        const rows = res.data || [];
        if (!rows.length){ $tb.html('<tr><td colspan="7" class="text-center text-muted py-5">No movements for this account/period.</td></tr>'); $('#summaryCards').hide(); return; }
        let html = '';
        rows.forEach(r => {
            const isIn = r.transaction_type === 'deposit';
            html += `<tr>
                <td>${esc(r.transaction_date)}</td>
                <td>${esc(r.description)}</td>
                <td><small class="text-muted">${esc(r.reference_number||'')}</small></td>
                <td class="text-end text-success">${isIn ? money(r.amount) : ''}</td>
                <td class="text-end text-danger">${!isIn ? money(r.amount) : ''}</td>
                <td class="text-end fw-semibold">${money(r.balance_after)}</td>
                <td class="text-center"><span class="badge bg-light text-dark border">${esc(r.status)}</span></td>
            </tr>`;
        });
        $tb.html(html);
        const s = res.summary;
        $('#s_count').text(s.count); $('#s_in').text(money(s.total_in)); $('#s_out').text(money(s.total_out));
        $('#s_close').text(money(s.closing_balance)); $('#summaryCards').show();
    });
}
$(document).ready(function(){
    $('#acct').select2 && $('#acct').select2({ theme:'bootstrap-5', width:'100%' });
    $('#load').on('click', loadStatement);
    $('#acct').on('change', loadStatement);
});
</script>

<?php includeFooter(); ?>
