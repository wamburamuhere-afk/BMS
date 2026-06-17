<?php
// app/constant/accounts/bank_statement.php
// Per-account bank-statement register with running balance.
// Standards: .claude/ui-constants.md (§UI-1 colours, §UI-2 DataTable, §UI-3 Select2,
//            §UI-4 SweetAlert2, §UI-7 mobile cards, §UI-8 Bootstrap Icons).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';   // cashBankAccounts()
includeHeader();
global $pdo;

require_once __DIR__ . '/../../../helpers.php';
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'View Bank Statement',
    ($_SESSION['username'] ?? 'User') . ' opened the Bank Account Statement');

$accounts = cashBankAccounts($pdo);
$currency = get_setting('currency', 'TZS');
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3 d-print-none">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Bank Statement</li>
        </ol>
    </nav>

    <!-- Page header — sticky §UI-7 -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 d-print-none"
         style="position:sticky;top:0;z-index:1020;background:#fff;padding:8px 0;">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-card-list text-primary me-2"></i>Bank Account Statement</h4>
            <p class="text-muted small mb-0">Recorded cash movements with a running balance.</p>
        </div>
        <button class="btn btn-outline-primary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print
        </button>
    </div>

    <!-- Filter card -->
    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-bold text-muted">Bank / Cash Account</label>
                    <!-- §UI-3: DB-backed select must use Select2 -->
                    <select id="acct" class="form-select select2-static">
                        <option value="">— Select account —</option>
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= (int)$a['account_id'] ?>">
                                <?= htmlspecialchars((!empty($a['account_code']) ? $a['account_code'] . ' — ' : '') . $a['account_name']) ?>
                            </option>
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
                    <button id="btnLoad" class="btn btn-primary w-100">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary cards §UI-1: #e7f0ff / #b6ccfe -->
    <div class="row g-3 mb-3" id="summaryCards" style="display:none;">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-primary" id="s_count">0</div>
                <div class="small text-muted">Movements</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-success" id="s_in">0.00</div>
                <div class="small text-muted">Money In</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-danger" id="s_out">0.00</div>
                <div class="small text-muted">Money Out</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-5 fw-bold text-primary" id="s_close">0.00</div>
                <div class="small text-muted">Closing Balance</div>
            </div>
        </div>
    </div>

    <!-- Desktop table §UI-2 -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table id="stmtTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:50px;">S/No</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Reference</th>
                            <th class="text-end">Money In</th>
                            <th class="text-end">Money Out</th>
                            <th class="text-end">Balance</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Mobile card view §UI-7 -->
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- DataTable assets §UI-2 -->
<link rel="stylesheet" href="/assets/css/dataTables.bootstrap5.min.css">
<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>

<style>
    #stmtTable thead th { font-size:.72rem; text-transform:uppercase; color:#6c757d; letter-spacing:.3px; }
    @media print {
        .d-print-none { display:none !important; }
        #stmtTable { width:100% !important; }
        .dataTables_wrapper { width:100% !important; }
    }
</style>

<script>
const CURRENCY   = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
const STMT_URL   = '<?= buildUrl('api/account/get_bank_statement.php') ?>';

function money(v) {
    return CURRENCY + ' ' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

// §UI-2 — DataTable init
// ordering disabled: running balance is cumulative and must stay in server sequence
const table = $('#stmtTable').DataTable({
    responsive: false,
    scrollX: false,
    pageLength: 25,
    ordering: false,
    dom: 'rtipB',
    buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
    language: { emptyTable: 'Select an account to view its statement.', zeroRecords: 'No movements found.' },
    columns: [
        { data: null,              className: 'ps-3',            render: (d, t, r, meta) => meta.row + 1 },
        { data: 'transaction_date' },
        { data: 'description',     render: d => esc(d) },
        { data: 'reference_number',render: d => `<small class="text-muted">${esc(d || '—')}</small>` },
        { data: null,              className: 'text-end text-success fw-semibold',
          render: (d, t, r) => r.transaction_type === 'deposit'    ? money(r.amount) : '' },
        { data: null,              className: 'text-end text-danger fw-semibold',
          render: (d, t, r) => r.transaction_type === 'withdrawal' ? money(r.amount) : '' },
        { data: 'balance_after',   className: 'text-end fw-bold',  render: d => money(d) },
        { data: 'status',          className: 'text-center',
          render: d => {
            // §UI-1 blue-scale status badges
            const map = {
                cleared:   ['#052c65','#fff'],
                pending:   ['#e9ecef','#495057'],
                cancelled: ['#6c757d','#fff'],
            };
            const [bg, fg] = map[d] || ['#cfe2ff','#084298'];
            return `<span class="badge-status" style="background:${bg};color:${fg};font-size:.68rem;padding:.35em .6em;border-radius:6px;">${esc(d ? d.toUpperCase() : '')}</span>`;
          }
        },
    ],
    drawCallback: function () {
        renderCards(this.api().rows({ page: 'current' }).data().toArray());
    }
});

// §UI-3 — Select2 for account dropdown
$('#acct').select2({
    theme: 'bootstrap-5',
    placeholder: '— Select account —',
    allowClear: true,
    width: '100%'
});

// §UI-7 — view toggle
function applyView() {
    if (window.innerWidth < 768) {
        $('#tableView').addClass('d-none');
        $('#cardView').removeClass('d-none');
    } else {
        $('#tableView').removeClass('d-none');
        $('#cardView').addClass('d-none');
    }
}
$(window).on('resize', applyView);
applyView();

// §UI-7 — mobile card renderer using DataTable row data
function renderCards(rows) {
    const $cv = $('#cardView');
    if (!rows.length) {
        $cv.html('<div class="col-12 text-center py-5 text-muted">No movements for this account / period.</div>');
        return;
    }
    let html = '';
    rows.forEach((r, i) => {
        const isIn = r.transaction_type === 'deposit';
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <span class="fw-bold small">${esc(r.description)}</span>
                    <span class="badge bg-light text-dark border ms-2 flex-shrink-0">${esc(r.status || '')}</span>
                </div>
                <div class="small text-muted">${esc(r.transaction_date)} &middot; ${esc(r.reference_number || '—')}</div>
                <div class="row g-1 mt-2 small">
                    <div class="col-4 text-center">
                        <div class="text-muted" style="font-size:.7rem;">MONEY IN</div>
                        <div class="fw-bold text-success">${isIn ? money(r.amount) : '—'}</div>
                    </div>
                    <div class="col-4 text-center">
                        <div class="text-muted" style="font-size:.7rem;">MONEY OUT</div>
                        <div class="fw-bold text-danger">${!isIn ? money(r.amount) : '—'}</div>
                    </div>
                    <div class="col-4 text-center">
                        <div class="text-muted" style="font-size:.7rem;">BALANCE</div>
                        <div class="fw-bold text-primary">${money(r.balance_after)}</div>
                    </div>
                </div>
            </div>
        </div></div>`;
    });
    $cv.html(html);
}

// Load statement
function loadStatement() {
    const acct = $('#acct').val();
    if (!acct) {
        table.clear().draw();
        $('#summaryCards').hide();
        return;
    }
    // §UI-4 — loading spinner
    Swal.fire({ title: 'Loading...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    $.getJSON(STMT_URL, {
        account_id: acct,
        date_from:  $('#from').val(),
        date_to:    $('#to').val()
    }, function (res) {
        Swal.close();
        if (!res.success) {
            // §UI-4 — error via SweetAlert2
            Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed to load statement.' });
            return;
        }
        const rows = res.data || [];
        // §UI-2 — never innerHTML; always clear().rows.add().draw()
        table.clear().rows.add(rows).draw();

        if (rows.length && res.summary) {
            const s = res.summary;
            $('#s_count').text(s.count);
            $('#s_in').text(money(s.total_in));
            $('#s_out').text(money(s.total_out));
            $('#s_close').text(money(s.closing_balance));
            $('#summaryCards').show();
        } else {
            $('#summaryCards').hide();
        }
    }).fail(function () {
        Swal.close();
        // §UI-4
        Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' });
    });
}

$('#btnLoad').on('click', loadStatement);
$('#acct').on('change', loadStatement);

if (typeof logReportAction === 'function') {
    logReportAction('Viewed Bank Statement', 'Opened bank statement page');
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
