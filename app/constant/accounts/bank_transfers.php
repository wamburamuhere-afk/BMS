<?php
// app/constant/accounts/bank_transfers.php
// Bank / Cash Transfers — move money between two cash/bank accounts through the
// standard pending → reviewed → approved → posted workflow (with optional
// charges). Money moves only at Posted (api/account/update_bank_transfer_status.php).
// Standards: .claude/ui-constants.md (white+blue, DataTable, Select2, gear actions,
// SweetAlert2), .claude/security.md (§23 project scope, CSRF).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';   // cashBankAccounts()
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();
global $pdo;

autoEnforcePermission('bank_transfers');

$can_create = canCreate('bank_transfers');
$can_edit   = canEdit('bank_transfers');
$can_review = canReview('bank_transfers');
$can_approve= canApprove('bank_transfers');

$currency        = get_setting('currency', 'TZS');
$enable_projects = get_setting('enable_projects');

$cash_accounts    = cashBankAccounts($pdo);
$expense_accounts = $pdo->query("SELECT account_id, account_code, account_name FROM accounts
                                  WHERE status = 'active' AND account_type = 'expense' ORDER BY account_name")->fetchAll(PDO::FETCH_ASSOC);
$projects = [];
if ($enable_projects == '1') {
    $projects = $pdo->query("SELECT project_id, project_name FROM projects
                              WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
                              ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

// Transfer list — project-scoped (§23). bank_transfers.project_id is usually NULL,
// so the nullable filter keeps company-wide transfers visible to everyone in scope.
$scope = scopeFilterSqlNullable('project', 'bt');
$transfers = $pdo->query("
    SELECT bt.*, fa.account_name AS from_name, ta.account_name AS to_name, ca.account_name AS charge_name
      FROM bank_transfers bt
      LEFT JOIN accounts fa ON bt.from_account_id   = fa.account_id
      LEFT JOIN accounts ta ON bt.to_account_id     = ta.account_id
      LEFT JOIN accounts ca ON bt.charge_account_id = ca.account_id
     WHERE 1=1 $scope
     ORDER BY bt.transfer_date DESC, bt.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$stat_total = count($transfers);
$stat_pending = 0; $stat_posted = 0; $stat_amount = 0.0;
foreach ($transfers as $t) {
    if (in_array($t['status'], ['pending','reviewed','approved'], true)) $stat_pending++;
    if ($t['status'] === 'posted') { $stat_posted++; $stat_amount += (float)$t['amount']; }
}

function bt_badge(string $s): string {
    $map = [
        'pending'  => ['#e9ecef', '#495057'],
        'reviewed' => ['#bfdbfe', '#1e3a8a'],
        'approved' => ['#0d6efd', '#fff'],
        'posted'   => ['#052c65', '#fff'],
        'rejected' => ['#dc3545', '#fff'],
    ];
    [$bg, $fg] = $map[$s] ?? ['#e9ecef', '#495057'];
    return '<span class="badge-status" style="background:' . $bg . ';color:' . $fg . ';">' . strtoupper($s) . '</span>';
}
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Bank Transfers</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-left-right text-primary me-2"></i>Bank &amp; Cash Transfers</h4>
            <p class="text-muted small mb-0">Move money between cash/bank accounts. Posted only after approval — the ledger and bank statement update automatically.</p>
        </div>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTransferModal">
            <i class="bi bi-plus-circle me-1"></i> New Transfer
        </button>
        <?php endif; ?>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-primary"><?= $stat_total ?></div><div class="small text-muted">Total Transfers</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-warning"><?= $stat_pending ?></div><div class="small text-muted">In Workflow</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold" style="color:#052c65"><?= $stat_posted ?></div><div class="small text-muted">Posted</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-5 fw-bold text-primary"><?= htmlspecialchars($currency) ?> <?= number_format($stat_amount, 2) ?></div><div class="small text-muted">Posted Value</div></div></div>
    </div>

    <!-- Desktop table -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table id="transfersTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Transfer #</th>
                            <th>Date</th>
                            <th>From</th>
                            <th>To</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end">Charges</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transfers as $t): $s = $t['status']; ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= safe_output($t['transfer_number']) ?></td>
                            <td><?= htmlspecialchars(date('d M Y', strtotime($t['transfer_date']))) ?></td>
                            <td><?= safe_output($t['from_name']) ?></td>
                            <td><?= safe_output($t['to_name']) ?></td>
                            <td class="text-end"><?= number_format((float)$t['amount'], 2) ?></td>
                            <td class="text-end"><?= ((float)$t['charges'] > 0) ? number_format((float)$t['charges'], 2) : '—' ?></td>
                            <td class="text-center"><?= bt_badge($s) ?></td>
                            <td class="text-end pe-3">
                                <div class="dropdown d-flex justify-content-end">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="bi bi-gear-fill me-1"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <li><button class="dropdown-item py-2 rounded" onclick='viewTransfer(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)'><i class="bi bi-eye text-primary me-2"></i> View</button></li>
                                        <?php if ($s === 'pending' && $can_review): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick="changeStatus(<?= (int)$t['id'] ?>,'reviewed')"><i class="bi bi-check2 text-primary me-2"></i> Mark Reviewed</button></li>
                                        <?php endif; ?>
                                        <?php if ($s === 'reviewed' && $can_approve): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick="changeStatus(<?= (int)$t['id'] ?>,'approved')"><i class="bi bi-check2-all text-primary me-2"></i> Approve</button></li>
                                        <?php endif; ?>
                                        <?php if ($s === 'approved' && $can_edit): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick="changeStatus(<?= (int)$t['id'] ?>,'posted')"><i class="bi bi-lock-fill text-primary me-2"></i> Post (move money)</button></li>
                                        <?php endif; ?>
                                        <?php if (in_array($s, ['pending','reviewed','approved'], true) && $can_edit): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button class="dropdown-item py-2 rounded text-danger" onclick="changeStatus(<?= (int)$t['id'] ?>,'rejected')"><i class="bi bi-slash-circle text-danger me-2"></i> Reject</button></li>
                                        <?php elseif ($s === 'posted' && $can_edit): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button class="dropdown-item py-2 rounded text-danger" onclick="changeStatus(<?= (int)$t['id'] ?>,'rejected')"><i class="bi bi-x-octagon text-danger me-2"></i> Void</button></li>
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

    <!-- Mobile card view -->
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Add Transfer Modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="addTransferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-1"></i> New Bank / Cash Transfer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addTransferForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Transfer Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="transfer_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Reference</label>
                            <input type="text" class="form-control" name="reference_number" placeholder="Optional bank/cheque ref">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">From Account <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="from_account_id" id="bt_from" required>
                                <option value="">Select source…</option>
                                <?php foreach ($cash_accounts as $a): ?>
                                    <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars($a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">To Account <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="to_account_id" id="bt_to" required>
                                <option value="">Select destination…</option>
                                <?php foreach ($cash_accounts as $a): ?>
                                    <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars($a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="bt_amount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Transfer Charges</label>
                            <input type="number" class="form-control" name="charges" id="bt_charges" step="0.01" min="0" value="0" placeholder="0.00">
                            <div class="form-text text-muted">Bank fee deducted from the source in addition to the amount.</div>
                        </div>
                        <div class="col-md-6" id="bt_charge_acc_block" style="display:none;">
                            <label class="form-label small fw-bold">Charge Account <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="charge_account_id" id="bt_charge_acc">
                                <option value="">Select expense account…</option>
                                <?php foreach ($expense_accounts as $a): ?>
                                    <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars($a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
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
                            <textarea class="form-control" name="description" rows="2" placeholder="Reason for the transfer…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check-circle me-1"></i> Create Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- DataTables + Select2 -->
<link rel="stylesheet" href="/assets/css/dataTables.bootstrap5.min.css">
<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>

<style>
    .badge-status { font-size: .68rem; padding: .35em .6em; border-radius: 6px; }
    #transfersTable thead th { font-size: .72rem; text-transform: uppercase; color: #6c757d; letter-spacing: .3px; }
</style>

<script>
$(function () {
    const POST_URL   = '<?= buildUrl('api/account/add_bank_transfer.php') ?>';
    const STATUS_URL = '<?= buildUrl('api/account/update_bank_transfer_status.php') ?>';
    const CSRF       = '<?= csrf_token() ?>';
    const CURRENCY   = '<?= htmlspecialchars($currency, ENT_QUOTES) ?>';
    const fmt = n => CURRENCY + ' ' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => $('<div>').text(s == null ? '' : s).html();

    if (!$.fn.DataTable.isDataTable('#transfersTable')) {
        $('#transfersTable').DataTable({
            responsive: false, scrollX: true, pageLength: 25, order: [[1, 'desc']],
            dom: 'rtip',
            columnDefs: [{ targets: [4, 5], className: 'text-end' }, { targets: [6, 7], orderable: false }],
            drawCallback: function () { renderCards(this.api().rows({ page: 'current' }).data().toArray()); },
            language: { emptyTable: 'No transfers yet.', zeroRecords: 'No matching transfers.' }
        });
    }

    // Init Select2 in modal
    $('#addTransferModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#addTransferModal'), placeholder: 'Select...', allowClear: true, width: '100%' });
            }
        });
    });

    // Charges → show/require the charge account
    function toggleChargeAcc() {
        const c = parseFloat($('#bt_charges').val()) || 0;
        $('#bt_charge_acc_block').toggle(c > 0);
        $('#bt_charge_acc').prop('required', c > 0);
    }
    $('#bt_charges').on('input', toggleChargeAcc);

    // Mobile view toggle
    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    // Create
    $('#addTransferForm').on('submit', function (e) {
        e.preventDefault();
        const from = $('#bt_from').val(), to = $('#bt_to').val();
        if (from && to && from === to) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Source and destination accounts must be different.' });
            return;
        }
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({
            url: POST_URL, type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addTransferModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Created!', text: res.message, timer: 1800, showConfirmButton: false }).then(() => location.reload());
                } else { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not create the transfer.' }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    window.changeStatus = function (id, status) {
        const verbs = { reviewed: 'mark this transfer reviewed', approved: 'approve this transfer',
                        posted: 'POST this transfer (the money will move between the accounts)', rejected: 'reject / void this transfer' };
        Swal.fire({
            title: 'Are you sure?', text: 'You are about to ' + (verbs[status] || status) + '.', icon: status === 'rejected' ? 'warning' : 'question',
            showCancelButton: true, confirmButtonColor: status === 'rejected' ? '#dc3545' : '#0d6efd',
            confirmButtonText: 'Yes, proceed'
        }).then(r => {
            if (!r.isConfirmed) return;
            Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            $.ajax({
                url: STATUS_URL, type: 'POST', dataType: 'json',
                data: { id: id, status: status, _csrf: CSRF },
                success: function (res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Done!', text: res.message + (res.sig_warning ? '\n\n' + res.sig_warning : ''), timer: 2200, showConfirmButton: false }).then(() => location.reload());
                    } else { Swal.fire({ icon: 'error', title: 'Error', text: res.message }); }
                },
                error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
            });
        });
    };

    window.viewTransfer = function (t) {
        const rows = [
            ['Transfer #', t.transfer_number],
            ['Date', t.transfer_date],
            ['From', t.from_name],
            ['To', t.to_name],
            ['Amount', fmt(t.amount)],
            ['Charges', (+t.charges > 0) ? fmt(t.charges) + (t.charge_name ? ' → ' + esc(t.charge_name) : '') : '—'],
            ['Reference', t.reference_number || '—'],
            ['Status', (t.status || '').toUpperCase()],
            ['Description', t.description || '—'],
        ];
        let html = '<table class="table table-sm mb-0 text-start">';
        rows.forEach(r => html += `<tr><th class="text-muted small" style="width:35%">${r[0]}</th><td>${esc(r[1])}</td></tr>`);
        html += '</table>';
        Swal.fire({ title: 'Transfer ' + esc(t.transfer_number), html: html, confirmButtonColor: '#0d6efd', width: 560 });
    };

    renderCards();
    if (typeof logReportAction === 'function') logReportAction('Viewed Bank Transfers', 'Opened bank transfers page');
});

// Mobile cards rendered from the server-rendered table rows (re-use the DOM).
function renderCards() {
    const $cv = $('#cardView');
    const trs = $('#transfersTable tbody tr');
    if (!trs.length) { $cv.html('<div class="col-12 text-center py-5 text-muted">No transfers</div>'); return; }
    let html = '';
    trs.each(function () {
        const td = $(this).find('td');
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${td.eq(0).text()}</span>${td.eq(6).html()}</div>
                <div class="small text-muted">${td.eq(1).text()}</div>
                <div class="small mt-1">${td.eq(2).text()} <i class="bi bi-arrow-right"></i> ${td.eq(3).text()}</div>
                <div class="small fw-semibold mt-1">Amount: ${td.eq(4).text()}</div>
            </div>
            <div class="card-footer bg-white border-top p-2">${td.eq(7).html()}</div>
        </div></div>`;
    });
    $cv.html(html);
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
