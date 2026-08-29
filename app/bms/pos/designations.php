<?php
// app/bms/pos/designations.php
// Lookup management for designations / job titles. Previously the ONLY way a row
// could exist here was as a side-effect of typing something into the Employee
// wizard's "Other (specify)" box — no admin page existed to view, rename, link to
// a department, or deactivate one. Standards: .claude/ui-constants.md.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/project_scope.php';

autoEnforcePermission('designations');
includeHeader();
global $pdo;

$can_edit   = isAdmin() || canEdit('designations');
$can_delete = isAdmin() || canDelete('designations');

$departments = $pdo->query("SELECT department_id, department_name FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);

// §23 — designations are a global lookup (no project_id of their own), but the
// employee_count below counts real employees, which ARE project-scoped: a
// non-admin must only see how many of THEIR employees hold this designation.
$emp_scope = scopeFilterSqlNullable('project', 'e');

$rows = $pdo->query("
    SELECT d.*, dep.department_name,
           COUNT(e.employee_id) AS employee_count
      FROM designations d
      LEFT JOIN departments dep ON dep.department_id = d.department_id
      LEFT JOIN employees e ON e.designation_id = d.designation_id AND e.status = 'active' $emp_scope
     GROUP BY d.designation_id
     ORDER BY d.designation_name
")->fetchAll(PDO::FETCH_ASSOC);

$stat_active = 0; $stat_inactive = 0;
foreach ($rows as $r) { $r['status'] === 'active' ? $stat_active++ : $stat_inactive++; }
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('employees') ?>" class="text-decoration-none">Employees</a></li>
            <li class="breadcrumb-item active">Designations</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge text-primary me-2"></i>Designations</h4>
            <p class="text-muted small mb-0">Job designations / titles used on the Employee wizard, scoped to a department and pay grade.</p>
        </div>
        <?php if ($can_edit): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-circle me-1"></i> New Designation</button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-primary"><?= count($rows) ?></div><div class="small text-muted">Total</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-success"><?= $stat_active ?></div><div class="small text-muted">Active</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-secondary"><?= $stat_inactive ?></div><div class="small text-muted">Inactive</div></div></div>
    </div>

    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table id="desTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Department</th>
                            <th>Pay Grade</th>
                            <th class="text-center">Employees</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= safe_output($r['designation_name']) ?></td>
                            <td><?= safe_output($r['department_name'], 'Unassigned') ?></td>
                            <td><?= safe_output($r['pay_grade'], '—') ?></td>
                            <td class="text-center">
                                <?php if ((int)$r['employee_count'] > 0): ?>
                                <a href="<?= getUrl('employees') ?>?designation_id=<?= (int)$r['designation_id'] ?>" class="badge bg-primary text-decoration-none"><?= (int)$r['employee_count'] ?></a>
                                <?php else: ?><span class="text-muted">0</span><?php endif; ?>
                            </td>
                            <td class="text-center"><span class="badge-status" style="background:<?= $r['status'] === 'active' ? '#198754' : '#6c757d' ?>;color:#fff;"><?= strtoupper($r['status']) ?></span></td>
                            <td class="text-end pe-3">
                                <div class="dropdown d-flex justify-content-end">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <?php if ($can_edit): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick='editRow(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'><i class="bi bi-pencil text-primary me-2"></i> Edit</button></li>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button class="dropdown-item py-2 rounded" onclick="toggleStatus(<?= (int)$r['designation_id'] ?>, '<?= $r['status'] === 'active' ? 'inactive' : 'active' ?>')">
                                            <i class="bi bi-<?= $r['status'] === 'active' ? 'slash-circle text-secondary' : 'check-circle text-success' ?> me-2"></i> <?= $r['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                        </button></li>
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

<?php if ($can_edit): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-badge me-1"></i> <span id="modalTitle">New Designation</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="desForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="designation_id" id="f-id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="designation_name" id="f-name" required placeholder="e.g. Senior Accountant">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Department</label>
                            <select class="form-select select2-static" name="department_id" id="f-dept">
                                <option value="">Unassigned</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>"><?= safe_output($d['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Pay Grade</label>
                            <input type="text" class="form-control" name="pay_grade" id="f-grade" placeholder="e.g. Grade 5">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">Description</label>
                            <input type="text" class="form-control" name="description" id="f-desc" placeholder="Optional">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check-circle me-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .badge-status { font-size:.68rem; padding:.35em .6em; border-radius:6px; }
    #desTable thead th { font-size:.72rem; text-transform:uppercase; color:#6c757d; letter-spacing:.3px; }
</style>

<script>
$(function () {
    const SAVE_URL   = '<?= buildUrl('api/pos/save_designation.php') ?>';
    const STATUS_URL = '<?= buildUrl('api/pos/toggle_designation_status.php') ?>';
    const CSRF       = '<?= csrf_token() ?>';

    if (!$.fn.DataTable.isDataTable('#desTable')) {
        $('#desTable').DataTable({
            responsive:false, scrollX:true, pageLength:25, order:[[0,'asc']], dom:'rtip',
            columnDefs:[{ targets:[3,4,5], orderable:false }],
            drawCallback: renderCards,
            language:{ emptyTable:'No designations yet.', zeroRecords:'No matching designations.' }
        });
    }

    $('#addModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#addModal'), width:'100%', allowClear:true, placeholder:'Unassigned' });
        });
    });

    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    $('#desForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:SAVE_URL, type:'POST', data:new FormData(this), contentType:false, processData:false, dataType:'json',
            success:function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('addModal')).hide();
                    Swal.fire({ icon:'success', title:'Saved!', text:res.message, timer:1800, showConfirmButton:false }).then(()=>location.reload());
                } else { Swal.fire({ icon:'error', title:'Error', text:res.message || 'Could not save.' }); }
            },
            error:function(){ Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); },
            complete:function(){ btn.prop('disabled', false).html(orig); }
        });
    });

    $('#addModal').on('hidden.bs.modal', function () {
        $('#desForm')[0].reset(); $('#f-id').val('');
        $('#modalTitle').text('New Designation');
        $('.select2-static', this).val(null).trigger('change');
    });

    window.editRow = function (r) {
        $('#f-id').val(r.designation_id);
        $('#f-name').val(r.designation_name);
        $('#f-dept').val(r.department_id || '').trigger('change');
        $('#f-grade').val(r.pay_grade || '');
        $('#f-desc').val(r.description || '');
        $('#modalTitle').text('Edit Designation');
        new bootstrap.Modal(document.getElementById('addModal')).show();
    };

    window.toggleStatus = function (id, newStatus) {
        const verb = newStatus === 'active' ? 'activate' : 'deactivate';
        Swal.fire({ title: `${verb.charAt(0).toUpperCase()+verb.slice(1)} this designation?`,
            text: newStatus === 'inactive' ? "It will no longer be selectable on the Employee wizard." : 'It will become selectable again on the Employee wizard.',
            icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:`Yes, ${verb}` })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url:STATUS_URL, type:'POST', dataType:'json', data:{ designation_id:id, status:newStatus, _csrf:CSRF },
                success:function(res){ if(res.success){ location.reload(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    };

    renderCards();
});

function renderCards() {
    const $cv = $('#cardView'); const trs = $('#desTable tbody tr');
    if (!trs.length || (trs.length === 1 && $(trs[0]).find('td').length === 1)) { $cv.html('<div class="col-12 text-center py-5 text-muted">No designations</div>'); return; }
    let html = '';
    trs.each(function () {
        const td = $(this).find('td'); if (td.length < 6) return;
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${td.eq(0).text()}</span>${td.eq(4).html()}</div>
                <div class="small text-muted">${td.eq(1).text()} · ${td.eq(2).text()} · ${td.eq(3).text()} employee(s)</div>
            </div>
            <div class="card-footer bg-white border-top p-2">${td.eq(5).html()}</div>
        </div></div>`;
    });
    $cv.html(html);
}
</script>

<?php includeFooter(); ?>
