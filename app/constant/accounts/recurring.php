<?php
// app/constant/accounts/recurring.php
// Recurring Documents — define a repeating expense template + schedule once; the
// system auto-creates the (pending) expense each period. Generated documents move
// no money until approved & paid. Standards: .claude/ui-constants.md (white/blue,
// DataTable, Select2, gear actions, SweetAlert2, CSRF).
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';   // cashBankAccounts()
require_once __DIR__ . '/../../../core/project_scope.php';
includeHeader();
global $pdo;

autoEnforcePermission('expenses');
$can_create = canCreate('expenses');
$can_edit   = canEdit('expenses');

$currency        = get_setting('currency', 'TZS');
$enable_projects = get_setting('enable_projects');

$cash_accounts    = cashBankAccounts($pdo);
$expense_accounts = expenseAccounts($pdo);   // canonical: active expense + finance_cost
$projects = [];
if ($enable_projects == '1') {
    $projects = $pdo->query("SELECT project_id, project_name FROM projects
                              WHERE (status != 'archived' OR status IS NULL) " . scopeFilterSql('project', 'projects') . "
                              ORDER BY project_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$profiles = $pdo->query("SELECT * FROM recurring_profiles ORDER BY status='ended', next_run_date ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$stat_active = 0; $stat_paused = 0;
foreach ($profiles as $p) { if ($p['status'] === 'active') $stat_active++; elseif ($p['status'] === 'paused') $stat_paused++; }

function rec_badge(string $s): string {
    $map = ['active'=>['#0d6efd','#fff'], 'paused'=>['#e9ecef','#495057'], 'ended'=>['#6c757d','#fff']];
    [$bg,$fg] = $map[$s] ?? ['#e9ecef','#495057'];
    return '<span class="badge-status" style="background:'.$bg.';color:'.$fg.';">'.strtoupper($s).'</span>';
}
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Recurring Documents</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-repeat text-primary me-2"></i>Recurring Documents</h4>
            <p class="text-muted small mb-0">Define a repeating expense once; the system creates it (as pending) each period — no money moves until you approve &amp; pay it.</p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($can_edit): ?>
            <button class="btn btn-outline-primary" id="btnRunNow"><i class="bi bi-play-circle me-1"></i> Run Due Now</button>
            <?php endif; ?>
            <?php if ($can_create): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProfileModal"><i class="bi bi-plus-circle me-1"></i> New Recurring</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#d1e7dd;"><div class="fs-4 fw-bold text-primary"><?= count($profiles) ?></div><div class="small text-muted">Profiles</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#d1e7dd;"><div class="fs-4 fw-bold" style="color:#0d6efd"><?= $stat_active ?></div><div class="small text-muted">Active</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#d1e7dd;"><div class="fs-4 fw-bold text-warning"><?= $stat_paused ?></div><div class="small text-muted">Paused</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3" style="background:#d1e7dd;"><div class="fs-5 fw-bold text-muted"><?= htmlspecialchars($currency) ?></div><div class="small text-muted">Currency</div></div></div>
    </div>

    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table id="recTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">S/NO</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Frequency</th>
                            <th class="text-end">Amount</th>
                            <th>Next Run</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($profiles as $i => $p):
                            $tpl = json_decode($p['template_json'], true) ?: []; ?>
                        <tr>
                            <td class="ps-3"><?= $i + 1 ?></td>
                            <td class="fw-semibold"><?= safe_output($p['name']) ?></td>
                            <td><span class="text-capitalize"><?= safe_output($p['doc_type']) ?></span></td>
                            <td>Every <?= (int)$p['interval_count'] ?> <?= safe_output($p['frequency']) ?></td>
                            <td class="text-end"><?= number_format((float)($tpl['amount'] ?? 0), 2) ?></td>
                            <td><?= $p['status'] === 'ended' ? '—' : htmlspecialchars(date('d M Y', strtotime($p['next_run_date']))) ?></td>
                            <td class="text-center"><?= rec_badge($p['status']) ?></td>
                            <td class="text-end pe-3">
                                <div class="dropdown d-flex justify-content-end">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <?php if ($can_edit && $p['status'] === 'active'): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick="setStatus(<?= (int)$p['id'] ?>,'pause')"><i class="bi bi-pause-circle text-primary me-2"></i> Pause</button></li>
                                        <?php elseif ($can_edit && $p['status'] === 'paused'): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick="setStatus(<?= (int)$p['id'] ?>,'resume')"><i class="bi bi-play-circle text-primary me-2"></i> Resume</button></li>
                                        <?php endif; ?>
                                        <?php if ($can_edit && $p['status'] !== 'ended'): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button class="dropdown-item py-2 rounded text-danger" onclick="setStatus(<?= (int)$p['id'] ?>,'end')"><i class="bi bi-x-octagon text-danger me-2"></i> End</button></li>
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
<div class="modal fade" id="addProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-1"></i> New Recurring Expense</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addProfileForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <h6 class="fw-bold text-primary small text-uppercase mb-2">Schedule</h6>
                    <div class="row g-3 mb-2">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold">Profile Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" placeholder="e.g. Office Rent" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Frequency <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="frequency">
                                <option value="weekly">Weekly</option>
                                <option value="monthly" selected>Monthly</option>
                                <option value="quarterly">Quarterly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Every</label>
                            <input type="number" class="form-control" name="interval_count" value="1" min="1">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold">Occurrences</label>
                            <input type="number" class="form-control" name="occurrences_left" min="1" placeholder="∞">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">End Date (optional)</label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary small text-uppercase mb-2 mt-3">Expense Template</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Expense Account <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="expense_account_id" required>
                                <option value="">Select expense account…</option>
                                <?php foreach ($expense_accounts as $a): ?>
                                    <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars($a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Default Paid-From (optional)</label>
                            <select class="form-select select2-static" name="bank_account_id">
                                <option value="">— None —</option>
                                <?php foreach ($cash_accounts as $a): ?>
                                    <option value="<?= (int)$a['account_id'] ?>"><?= htmlspecialchars($a['account_name'] . ($a['account_code'] ? ' (' . $a['account_code'] . ')' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($enable_projects == '1'): ?>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Project (optional)</label>
                            <select class="form-select select2-static" name="project_id">
                                <option value="">Company-wide</option>
                                <?php foreach ($projects as $p): ?>
                                    <option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Description</label>
                            <input type="text" class="form-control" name="description" placeholder="What is this recurring charge for…">
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small"><i class="bi bi-info-circle text-primary me-1"></i> Each generated expense is created as <strong>pending</strong>. No money moves until it is approved and marked Paid.</div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check-circle me-1"></i> Create Profile</button>
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
    .badge-status { font-size:.68rem; padding:.35em .6em; border-radius:6px; }
    #recTable thead th { font-size:.72rem; text-transform:uppercase; color:#6c757d; letter-spacing:.3px; }
</style>

<script>
$(function () {
    const SAVE_URL   = '<?= buildUrl('api/account/save_recurring_profile.php') ?>';
    const STATUS_URL = '<?= buildUrl('api/account/update_recurring_status.php') ?>';
    const RUN_URL    = '<?= buildUrl('api/account/run_recurring_now.php') ?>';
    const CSRF       = '<?= csrf_token() ?>';

    if (!$.fn.DataTable.isDataTable('#recTable')) {
        $('#recTable').DataTable({ responsive:false, scrollX:true, pageLength:25, order:[[4,'asc']], dom:'rtip',
            columnDefs:[{ targets:[3], className:'text-end' }, { targets:[5,6], orderable:false }],
            drawCallback: renderCards, language:{ emptyTable:'No recurring profiles yet.', zeroRecords:'No matching profiles.' } });
    }

    $('#addProfileModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#addProfileModal'), width:'100%' });
        });
    });

    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    $('#addProfileForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:SAVE_URL, type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
            success:function(res){
                if (res.success){ bootstrap.Modal.getInstance(document.getElementById('addProfileModal')).hide();
                    Swal.fire({ icon:'success', title:'Created!', text:res.message, timer:1800, showConfirmButton:false }).then(()=>location.reload()); }
                else { Swal.fire({ icon:'error', title:'Error', text:res.message || 'Could not create.' }); }
            },
            error:function(){ Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); },
            complete:function(){ btn.prop('disabled',false).html(orig); }
        });
    });

    window.setStatus = function (id, action) {
        const verbs = { pause:'pause', resume:'resume', end:'permanently end' };
        Swal.fire({ title:'Are you sure?', text:'You are about to ' + verbs[action] + ' this recurring profile.',
            icon: action==='end'?'warning':'question', showCancelButton:true,
            confirmButtonColor: action==='end'?'#dc3545':'#0d6efd', confirmButtonText:'Yes' })
        .then(r=>{ if(!r.isConfirmed) return;
            $.ajax({ url:STATUS_URL, type:'POST', dataType:'json', data:{ id:id, action:action, _csrf:CSRF },
                success:function(res){ if(res.success){ location.reload(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    };

    $('#btnRunNow').on('click', function () {
        Swal.fire({ title:'Generate due documents now?', text:'Any profiles due today (or overdue) will create their pending expense.',
            icon:'question', showCancelButton:true, confirmButtonColor:'#0d6efd', confirmButtonText:'Yes, run' })
        .then(r=>{ if(!r.isConfirmed) return;
            Swal.fire({ title:'Running...', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
            $.ajax({ url:RUN_URL, type:'POST', dataType:'json', data:{ _csrf:CSRF },
                success:function(res){ if(res.success){ Swal.fire({icon:'success',title:'Done',text:res.message,timer:2200,showConfirmButton:false}).then(()=>location.reload()); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    });

    renderCards();
    if (typeof logReportAction === 'function') logReportAction('Viewed Recurring Documents', 'Opened recurring page');
});

function renderCards() {
    const $cv = $('#cardView'); const trs = $('#recTable tbody tr');
    if (!trs.length || (trs.length===1 && $(trs[0]).find('td').length===1)) { $cv.html('<div class="col-12 text-center py-5 text-muted">No recurring profiles</div>'); return; }
    let html = '';
    trs.each(function () {
        const td = $(this).find('td'); if (td.length < 7) return;
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${td.eq(0).text()}</span>${td.eq(5).html()}</div>
                <div class="small text-muted">${td.eq(2).text()} · Next: ${td.eq(4).text()}</div>
                <div class="small fw-semibold mt-1">Amount: ${td.eq(3).text()}</div>
            </div>
            <div class="card-footer bg-white border-top p-2">${td.eq(6).html()}</div>
        </div></div>`;
    });
    $cv.html(html);
}
</script>

<?php includeFooter(); ob_end_flush(); ?>
