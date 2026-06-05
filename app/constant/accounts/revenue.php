<?php
// app/constant/accounts/revenue.php
// Standalone Revenue / Other Income — record non-sales income (interest, grants,
// asset disposal, misc) through pending → reviewed → approved → posted. Money is
// received only at Posted (api/account/update_revenue_status.php → postInflow +
// bank-register deposit). Standards: .claude/ui-constants.md, .claude/security.md.
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';   // cashBankAccounts()
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();
global $pdo;

autoEnforcePermission('revenue');

$can_create  = canCreate('revenue');
$can_edit    = canEdit('revenue');
$can_review  = canReview('revenue');
$can_approve = canApprove('revenue');

$currency        = get_setting('currency', 'TZS');
$enable_projects = get_setting('enable_projects');

$cash_accounts   = cashBankAccounts($pdo);
$income_accounts = $pdo->query("SELECT account_id, account_code, account_name FROM accounts
                                 WHERE status = 'active' AND account_type = 'income' ORDER BY account_name")->fetchAll(PDO::FETCH_ASSOC);

// Flatten the revenue category tree (parent › child) for the picker.
$catRows = $pdo->query("SELECT id, parent_id, name FROM revenue_categories WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$catFlat = [];
$flatten = function ($parentId, $depth) use (&$flatten, $catRows, &$catFlat) {
    foreach ($catRows as $c) {
        $p = ($c['parent_id'] === null || $c['parent_id'] === '') ? null : (int)$c['parent_id'];
        if ($p === $parentId) {
            $catFlat[] = ['id' => (int)$c['id'], 'label' => str_repeat('— ', $depth) . $c['name']];
            $flatten((int)$c['id'], $depth + 1);
        }
    }
};
$flatten(null, 0);

$projects = [];
if ($enable_projects == '1') {
    $projects = $pdo->query("SELECT project_id, project_name FROM projects
                              WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
                              ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Revenue list — project-scoped (§23).
$scope = scopeFilterSqlNullable('project', 'r');
$revenues = $pdo->query("
    SELECT r.*, rc.name AS category_name, ia.account_name AS income_name, ba.account_name AS bank_name
      FROM revenues r
      LEFT JOIN revenue_categories rc ON r.category_id       = rc.id
      LEFT JOIN accounts ia           ON r.income_account_id = ia.account_id
      LEFT JOIN accounts ba           ON r.bank_account_id   = ba.account_id
     WHERE 1=1 $scope
     ORDER BY r.revenue_date DESC, r.revenue_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$stat_total = count($revenues);
$stat_pending = 0; $stat_posted = 0; $stat_amount = 0.0;
foreach ($revenues as $r) {
    if (in_array($r['status'], ['pending','reviewed','approved'], true)) $stat_pending++;
    if ($r['status'] === 'posted') { $stat_posted++; $stat_amount += (float)$r['amount']; }
}

function rev_badge(string $s): string {
    $map = ['pending'=>['#e9ecef','#495057'],'reviewed'=>['#bfdbfe','#1e3a8a'],'approved'=>['#0d6efd','#fff'],'posted'=>['#052c65','#fff'],'rejected'=>['#dc3545','#fff']];
    [$bg,$fg] = $map[$s] ?? ['#e9ecef','#495057'];
    return '<span class="badge-status" style="background:'.$bg.';color:'.$fg.';">'.strtoupper($s).'</span>';
}
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Revenue</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-cash-coin text-primary me-2"></i>Revenue &amp; Other Income</h4>
            <p class="text-muted small mb-0">Record non-sales income. Posted only after approval — the ledger, bank statement and P&amp;L update automatically.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('revenue_categories') ?>" class="btn btn-outline-primary"><i class="bi bi-diagram-3-fill me-1"></i> Categories</a>
            <?php if ($can_create): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRevenueModal"><i class="bi bi-plus-circle me-1"></i> New Revenue</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-primary"><?= $stat_total ?></div><div class="small text-muted">Total Records</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-warning"><?= $stat_pending ?></div><div class="small text-muted">In Workflow</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold" style="color:#052c65"><?= $stat_posted ?></div><div class="small text-muted">Posted</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold text-primary"><?= htmlspecialchars($currency) ?> <?= number_format($stat_amount, 2) ?></div><div class="small text-muted">Posted Income</div></div></div>
    </div>

    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table id="revenueTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Revenue #</th>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Income Account</th>
                            <th>Received Into</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($revenues as $r): $s = $r['status']; ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= safe_output($r['revenue_number']) ?></td>
                            <td><?= htmlspecialchars(date('d M Y', strtotime($r['revenue_date']))) ?></td>
                            <td><?= safe_output($r['category_name'] ?? '—', '—') ?></td>
                            <td><?= safe_output($r['income_name'] ?? '—', '—') ?></td>
                            <td><?= safe_output($r['bank_name'] ?? '—', '—') ?></td>
                            <td class="text-end"><?= number_format((float)$r['amount'], 2) ?></td>
                            <td class="text-center"><?= rev_badge($s) ?></td>
                            <td class="text-end pe-3">
                                <div class="dropdown d-flex justify-content-end">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill me-1"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <li><button class="dropdown-item py-2 rounded" onclick='viewRevenue(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'><i class="bi bi-eye text-primary me-2"></i> View</button></li>
                                        <?php if ($s === 'pending' && $can_review): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick="changeStatus(<?= (int)$r['revenue_id'] ?>,'reviewed')"><i class="bi bi-check2 text-primary me-2"></i> Mark Reviewed</button></li>
                                        <?php endif; ?>
                                        <?php if ($s === 'reviewed' && $can_approve): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick="changeStatus(<?= (int)$r['revenue_id'] ?>,'approved')"><i class="bi bi-check2-all text-primary me-2"></i> Approve</button></li>
                                        <?php endif; ?>
                                        <?php if ($s === 'approved' && $can_edit): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick="changeStatus(<?= (int)$r['revenue_id'] ?>,'posted')"><i class="bi bi-lock-fill text-primary me-2"></i> Post (receive money)</button></li>
                                        <?php endif; ?>
                                        <?php if (in_array($s, ['pending','reviewed','approved'], true) && $can_edit): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button class="dropdown-item py-2 rounded text-danger" onclick="changeStatus(<?= (int)$r['revenue_id'] ?>,'rejected')"><i class="bi bi-slash-circle text-danger me-2"></i> Reject</button></li>
                                        <?php elseif ($s === 'posted' && $can_edit): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button class="dropdown-item py-2 rounded text-danger" onclick="changeStatus(<?= (int)$r['revenue_id'] ?>,'rejected')"><i class="bi bi-x-octagon text-danger me-2"></i> Void</button></li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<?php if ($can_create): ?>
<div class="modal fade" id="addRevenueModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i> New Revenue / Other Income</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addRevenueForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Revenue Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="revenue_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Category</label>
                            <select class="form-select select2-static" name="category_id">
                                <option value="">— Select category (optional) —</option>
                                <?php foreach ($catFlat as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= safe_output($c['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Income Account <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="income_account_id" required>
                                <option value="">Select income account…</option>
                                <?php foreach ($income_accounts as $a): ?>
                                    <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars($a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">The income (revenue) account credited when posted.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Received Into <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="bank_account_id" required>
                                <option value="">Select cash/bank account…</option>
                                <?php foreach ($cash_accounts as $a): ?>
                                    <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars($a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Payer / Source</label>
                            <input type="text" class="form-control" name="payer_name" placeholder="Who paid (optional)">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Reference</label>
                            <input type="text" class="form-control" name="reference_number" placeholder="Optional bank/receipt ref">
                        </div>
                        <?php if ($enable_projects == '1'): ?>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Project</label>
                            <select class="form-select select2-static" name="project_id">
                                <option value="">Company-wide (no project)</option>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Description</label>
                            <textarea class="form-control" name="description" rows="2" placeholder="What is this income for…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check-circle me-1"></i> Create Revenue</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<link rel="stylesheet" href="/assets/css/dataTables.bootstrap5.min.css">
<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>

<style>
    .badge-status { font-size: .68rem; padding: .35em .6em; border-radius: 6px; }
    #revenueTable thead th { font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
</style>

<script>
$(function () {
    const POST_URL   = '<?= buildUrl('api/account/add_revenue.php') ?>';
    const STATUS_URL = '<?= buildUrl('api/account/update_revenue_status.php') ?>';
    const CSRF       = '<?= csrf_token() ?>';
    const CURRENCY   = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const fmt = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => $('<div>').text(s == null ? '' : s).html();

    if (!$.fn.DataTable.isDataTable('#revenueTable')) {
        $('#revenueTable').DataTable({
            responsive: false, scrollX: true, pageLength: 25, order: [[1, 'desc']], dom: 'rtip',
            columnDefs: [{ targets: [5], className: 'text-end' }, { targets: [6, 7], orderable: false }],
            drawCallback: function () { renderCards(); },
            language: { emptyTable: 'No revenue records yet.', zeroRecords: 'No matching records.' }
        });
    }

    $('#addRevenueModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#addRevenueModal'), placeholder: 'Select...', allowClear: true, width: '100%' });
            }
        });
    });

    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    $('#addRevenueForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({
            url: POST_URL, type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addRevenueModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Created!', text: res.message, timer: 1800, showConfirmButton: false }).then(() => location.reload());
                } else { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not create the revenue.' }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    window.changeStatus = function (id, status) {
        const verbs = { reviewed: 'mark this revenue reviewed', approved: 'approve this revenue',
                        posted: 'POST this revenue (the money will be received into the account)', rejected: 'reject / void this revenue' };
        Swal.fire({
            title: 'Are you sure?', text: 'You are about to ' + (verbs[status] || status) + '.',
            icon: status === 'rejected' ? 'warning' : 'question', showCancelButton: true,
            confirmButtonColor: status === 'rejected' ? '#dc3545' : '#0d6efd', confirmButtonText: 'Yes, proceed'
        }).then(r => {
            if (!r.isConfirmed) return;
            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.ajax({
                url: STATUS_URL, type: 'POST', dataType: 'json', data: { id: id, status: status, _csrf: CSRF },
                success: function (res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Done!', text: res.message + (res.sig_warning ? '\n\n' + res.sig_warning : ''), timer: 2200, showConfirmButton: false }).then(() => location.reload());
                    } else { Swal.fire({ icon: 'error', title: 'Error', text: res.message }); }
                },
                error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
            });
        });
    };

    window.viewRevenue = function (r) {
        const rows = [
            ['Revenue #', r.revenue_number], ['Date', r.revenue_date],
            ['Category', r.category_name || '—'], ['Income Account', r.income_name || '—'],
            ['Received Into', r.bank_name || '—'], ['Amount', fmt(r.amount)],
            ['Payer', r.payer_name || '—'], ['Reference', r.reference_number || '—'],
            ['Status', (r.status || '').toUpperCase()], ['Description', r.description || '—'],
        ];
        let html = '<table class="table table-sm mb-0 text-start">';
        rows.forEach(x => html += `<tr><th class="text-muted small" style="width:35%">${x[0]}</th><td>${esc(x[1])}</td></tr>`);
        html += '</table>';
        Swal.fire({ title: 'Revenue ' + esc(r.revenue_number), html: html, confirmButtonColor: '#0d6efd', width: 560 });
    };

    renderCards();
    if (typeof logReportAction === 'function') logReportAction('Viewed Revenue', 'Opened revenue page');
});

function renderCards() {
    const $cv = $('#cardView');
    const trs = $('#revenueTable tbody tr');
    if (!trs.length || (trs.length === 1 && $(trs[0]).find('td').length === 1)) { $cv.html('<div class="col-12 text-center py-5 text-muted">No revenue records</div>'); return; }
    let html = '';
    trs.each(function () {
        const td = $(this).find('td');
        if (td.length < 8) return;
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${td.eq(0).text()}</span>${td.eq(6).html()}</div>
                <div class="small text-muted">${td.eq(1).text()} · ${td.eq(2).text()}</div>
                <div class="small mt-1">Into: ${td.eq(4).text()}</div>
                <div class="small fw-semibold mt-1">Amount: ${td.eq(5).text()}</div>
            </div>
            <div class="card-footer bg-white border-top p-2">${td.eq(7).html()}</div>
        </div></div>`;
    });
    $cv.html(html);
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
