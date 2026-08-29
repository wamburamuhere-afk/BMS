<?php
// app/bms/pos/departments.php
// Lookup + org-structure management for departments: leadership (manager/assistant,
// already read elsewhere by Reporting To / department leadership APIs), and
// parent/child hierarchy (parent_department_id existed in the schema but was never
// read or written anywhere — this is the first real consumer). Previously the ONLY
// way a department row could exist was as a side-effect of typing something into
// the Employee wizard's "Other (specify)" box. Standards: .claude/ui-constants.md.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/project_scope.php';

autoEnforcePermission('departments');
includeHeader();
global $pdo;

$can_edit   = isAdmin() || canEdit('departments');
$can_delete = isAdmin() || canDelete('departments');

// §23 — departments/designations/employment types are global org-structure lookups
// (no project_id of their own), but the EMPLOYEES they reference are project-scoped.
// A non-admin must only see/pick employees (manager, assistant manager, counts) in
// their own scope — otherwise this page would leak cross-project headcount and let
// a manager assign a department head outside their visibility.
$emp_scope = scopeFilterSqlNullable('project', 'e');

$active_employees = $pdo->query("
    SELECT e.employee_id, CONCAT(e.first_name, ' ', e.last_name) AS full_name
      FROM employees e WHERE e.status = 'active' $emp_scope ORDER BY e.first_name, e.last_name
")->fetchAll(PDO::FETCH_ASSOC);

$rows = $pdo->query("
    SELECT dp.*,
           mgr.first_name AS mgr_first, mgr.last_name AS mgr_last,
           asst.first_name AS asst_first, asst.last_name AS asst_last,
           parent.department_name AS parent_name,
           COUNT(DISTINCT e.employee_id) AS employee_count,
           COUNT(DISTINCT child.department_id) AS child_count
      FROM departments dp
      LEFT JOIN employees mgr    ON mgr.employee_id  = dp.manager_id
      LEFT JOIN employees asst   ON asst.employee_id = dp.assistant_manager_id
      LEFT JOIN departments parent ON parent.department_id = dp.parent_department_id
      LEFT JOIN employees e      ON e.department_id = dp.department_id AND e.status = 'active' $emp_scope
      LEFT JOIN departments child ON child.parent_department_id = dp.department_id
     GROUP BY dp.department_id
     ORDER BY dp.department_name
")->fetchAll(PDO::FETCH_ASSOC);

$stat_active = 0; $stat_inactive = 0;
foreach ($rows as $r) { $r['status'] === 'active' ? $stat_active++ : $stat_inactive++; }
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('employees') ?>" class="text-decoration-none">Employees</a></li>
            <li class="breadcrumb-item active">Departments</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-2 text-primary me-2"></i>Departments</h4>
            <p class="text-muted small mb-0">Company departments, leadership and parent/child hierarchy — feeds the Employee wizard, Reporting To, and Org Chart.</p>
        </div>
        <?php if ($can_edit): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-circle me-1"></i> New Department</button>
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
                <table id="depTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Code</th>
                            <th>Name</th>
                            <th>Parent</th>
                            <th>Manager</th>
                            <th>Assistant Manager</th>
                            <th class="text-center">Employees</th>
                            <th class="text-center">Sub-depts</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r):
                            $mgrName  = trim(($r['mgr_first'] ?? '') . ' ' . ($r['mgr_last'] ?? ''));
                            $asstName = trim(($r['asst_first'] ?? '') . ' ' . ($r['asst_last'] ?? ''));
                        ?>
                        <tr>
                            <td class="ps-3"><?= safe_output($r['department_code'], '—') ?></td>
                            <td class="fw-semibold"><?= safe_output($r['department_name']) ?></td>
                            <td><?= safe_output($r['parent_name'], '—') ?></td>
                            <td><?= $mgrName !== '' ? safe_output($mgrName) : '<span class="text-muted">—</span>' ?></td>
                            <td><?= $asstName !== '' ? safe_output($asstName) : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-center">
                                <?php if ((int)$r['employee_count'] > 0): ?>
                                <a href="<?= getUrl('employees') ?>?department_id=<?= (int)$r['department_id'] ?>" class="badge bg-primary text-decoration-none"><?= (int)$r['employee_count'] ?></a>
                                <?php else: ?><span class="text-muted">0</span><?php endif; ?>
                            </td>
                            <td class="text-center"><?= (int)$r['child_count'] > 0 ? (int)$r['child_count'] : '<span class="text-muted">0</span>' ?></td>
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
                                        <li><button class="dropdown-item py-2 rounded" onclick="toggleStatus(<?= (int)$r['department_id'] ?>, '<?= $r['status'] === 'active' ? 'inactive' : 'active' ?>')">
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
                <h5 class="modal-title"><i class="bi bi-diagram-2 me-1"></i> <span id="modalTitle">New Department</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="depForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="department_id" id="f-id">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="department_name" id="f-name" required placeholder="e.g. Finance">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Code</label>
                            <input type="text" class="form-control" name="department_code" id="f-code" placeholder="e.g. FIN">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Parent Department</label>
                            <select class="form-select select2-static" name="parent_department_id" id="f-parent">
                                <option value="">None (top-level)</option>
                                <?php foreach ($rows as $d): ?>
                                <option value="<?= $d['department_id'] ?>" data-name="<?= safe_output($d['department_name']) ?>"><?= safe_output($d['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-muted">Makes this a sub-department of another.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Manager</label>
                            <select class="form-select select2-static" name="manager_id" id="f-manager">
                                <option value="">Unassigned</option>
                                <?php foreach ($active_employees as $e): ?>
                                <option value="<?= $e['employee_id'] ?>"><?= safe_output($e['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Assistant Manager</label>
                            <select class="form-select select2-static" name="assistant_manager_id" id="f-asst">
                                <option value="">Unassigned</option>
                                <?php foreach ($active_employees as $e): ?>
                                <option value="<?= $e['employee_id'] ?>"><?= safe_output($e['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
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
    #depTable thead th { font-size:.72rem; text-transform:uppercase; color:#6c757d; letter-spacing:.3px; }
</style>

<script>
$(function () {
    const SAVE_URL   = '<?= buildUrl('api/pos/save_department.php') ?>';
    const STATUS_URL = '<?= buildUrl('api/pos/toggle_department_status.php') ?>';
    const CSRF       = '<?= csrf_token() ?>';

    if (!$.fn.DataTable.isDataTable('#depTable')) {
        $('#depTable').DataTable({
            responsive:false, scrollX:true, pageLength:25, order:[[1,'asc']], dom:'rtip',
            columnDefs:[{ targets:[5,6,7,8], orderable:false }],
            drawCallback: renderCards,
            language:{ emptyTable:'No departments yet.', zeroRecords:'No matching departments.' }
        });
    }

    $('#addModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#addModal'), width:'100%', allowClear:true });
        });
    });

    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    $('#depForm').on('submit', function (e) {
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
        $('#depForm')[0].reset(); $('#f-id').val('');
        $('#modalTitle').text('New Department');
        $('.select2-static', this).val(null).trigger('change');
        $('#f-parent option').prop('disabled', false);
    });

    window.editRow = function (r) {
        $('#f-id').val(r.department_id);
        $('#f-name').val(r.department_name);
        $('#f-code').val(r.department_code || '');
        $('#f-desc').val(r.description || '');
        // A department can never become its own parent — disable its own option
        // client-side (the API re-checks server-side against the full ancestor chain).
        $('#f-parent option').prop('disabled', false);
        $('#f-parent option[value="' + r.department_id + '"]').prop('disabled', true);
        $('#f-parent').val(r.parent_department_id || '').trigger('change');
        $('#f-manager').val(r.manager_id || '').trigger('change');
        $('#f-asst').val(r.assistant_manager_id || '').trigger('change');
        $('#modalTitle').text('Edit Department');
        new bootstrap.Modal(document.getElementById('addModal')).show();
    };

    window.toggleStatus = function (id, newStatus) {
        const verb = newStatus === 'active' ? 'activate' : 'deactivate';
        Swal.fire({ title: `${verb.charAt(0).toUpperCase()+verb.slice(1)} this department?`,
            text: newStatus === 'inactive' ? "It will no longer be selectable on the Employee wizard." : 'It will become selectable again on the Employee wizard.',
            icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:`Yes, ${verb}` })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url:STATUS_URL, type:'POST', dataType:'json', data:{ department_id:id, status:newStatus, _csrf:CSRF },
                success:function(res){ if(res.success){ location.reload(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    };

    renderCards();
});

function renderCards() {
    const $cv = $('#cardView'); const trs = $('#depTable tbody tr');
    if (!trs.length || (trs.length === 1 && $(trs[0]).find('td').length === 1)) { $cv.html('<div class="col-12 text-center py-5 text-muted">No departments</div>'); return; }
    let html = '';
    trs.each(function () {
        const td = $(this).find('td'); if (td.length < 9) return;
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${td.eq(1).text()}</span>${td.eq(7).html()}</div>
                <div class="small text-muted">Manager: ${td.eq(3).text()} · ${td.eq(5).text()} employee(s)</div>
            </div>
            <div class="card-footer bg-white border-top p-2">${td.eq(8).html()}</div>
        </div></div>`;
    });
    $cv.html(html);
}
</script>

<?php includeFooter(); ?>
