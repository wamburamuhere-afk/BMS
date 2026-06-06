<?php
// app/bms/pos/salary_components.php
// Master list of reusable salary components (allowance / deduction / bonus) used to
// build an employee's salary structure and an itemised payslip. Standards:
// .claude/ui-constants.md (white/blue, DataTable, Select2, gear actions, SweetAlert2,
// CSRF, mobile cards).
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('payroll');
includeHeader();
global $pdo;

$can_edit   = isAdmin() || canEdit('payroll');
$can_delete = isAdmin() || canDelete('payroll');

$rows = $pdo->query("SELECT * FROM salary_components WHERE status != 'deleted' ORDER BY component_type, component_name")->fetchAll(PDO::FETCH_ASSOC);

$stat_allow = 0; $stat_deduct = 0; $stat_bonus = 0;
foreach ($rows as $r) {
    if ($r['component_type'] === 'allowance') $stat_allow++;
    elseif ($r['component_type'] === 'deduction') $stat_deduct++;
    elseif ($r['component_type'] === 'bonus') $stat_bonus++;
}

function sc_type_badge(string $t): string {
    $map = ['allowance' => ['#0d6efd', '#fff'], 'deduction' => ['#dc3545', '#fff'], 'bonus' => ['#052c65', '#fff']];
    [$bg, $fg] = $map[$t] ?? ['#e9ecef', '#495057'];
    return '<span class="badge-status" style="background:' . $bg . ';color:' . $fg . ';">' . strtoupper($t) . '</span>';
}
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('payroll') ?>" class="text-decoration-none">Payroll</a></li>
            <li class="breadcrumb-item active">Salary Components</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-sliders text-primary me-2"></i>Salary Components</h4>
            <p class="text-muted small mb-0">Reusable allowances, deductions &amp; bonuses used to build each employee's salary structure.</p>
        </div>
        <?php if ($can_edit): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-circle me-1"></i> New Component</button>
        <?php endif; ?>
    </div>

    <!-- Stat cards (§UI-1 colour convention) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-primary"><?= count($rows) ?></div><div class="small text-muted">Total</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold" style="color:#0d6efd"><?= $stat_allow ?></div><div class="small text-muted">Allowances</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-danger"><?= $stat_deduct ?></div><div class="small text-muted">Deductions</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold" style="color:#052c65"><?= $stat_bonus ?></div><div class="small text-muted">Bonuses</div></div></div>
    </div>

    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table id="scTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Component</th>
                            <th>Type</th>
                            <th>Calculation</th>
                            <th class="text-end">Default</th>
                            <th class="text-center">Taxable</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= safe_output($r['component_name']) ?></td>
                            <td><?= sc_type_badge($r['component_type']) ?></td>
                            <td><span class="text-capitalize"><?= safe_output($r['calculation_type']) ?></span><?= $r['calculation_type'] === 'percentage' ? ' of basic' : '' ?></td>
                            <td class="text-end"><?= $r['calculation_type'] === 'percentage' ? number_format((float)$r['default_amount'], 2) . '%' : number_format((float)$r['default_amount'], 2) ?></td>
                            <td class="text-center"><?= $r['tax_applicable'] ? '<i class="bi bi-check-circle-fill text-primary"></i>' : '<span class="text-muted">—</span>' ?></td>
                            <td class="text-center"><span class="badge-status" style="background:<?= $r['status'] === 'active' ? '#0d6efd' : '#6c757d' ?>;color:#fff;"><?= strtoupper($r['status']) ?></span></td>
                            <td class="text-end pe-3">
                                <div class="dropdown d-flex justify-content-end">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <?php if ($can_edit): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick='editComponent(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'><i class="bi bi-pencil text-primary me-2"></i> Edit</button></li>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button class="dropdown-item py-2 rounded text-danger" onclick="confirmDelete(<?= (int)$r['component_id'] ?>)"><i class="bi bi-trash text-danger me-2"></i> Delete</button></li>
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

<!-- Add / Edit modal (§UI-1: blue header) -->
<?php if ($can_edit): ?>
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-sliders me-1"></i> <span id="modalTitle">New Salary Component</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="scForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="component_id" id="f-id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold">Component Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="component_name" id="f-name" required placeholder="e.g. Housing Allowance">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Type <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="component_type" id="f-type" required>
                                <option value="allowance">Allowance (adds to pay)</option>
                                <option value="deduction">Deduction (subtracts)</option>
                                <option value="bonus">Bonus (adds to pay)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Calculation <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="calculation_type" id="f-calc" required>
                                <option value="fixed">Fixed amount</option>
                                <option value="percentage">Percentage of basic</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Default Value <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="default_amount" id="f-amount" step="0.01" min="0" required placeholder="0.00">
                                <span class="input-group-text" id="f-unit">amount</span>
                            </div>
                            <div class="form-text text-muted">A fixed figure, or a % of basic salary.</div>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" id="f-tax" name="tax_applicable" value="1">
                                <label class="form-check-label small fw-bold" for="f-tax">Taxable</label>
                            </div>
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

<link rel="stylesheet" href="/assets/css/dataTables.bootstrap5.min.css">
<script src="/assets/js/jquery.dataTables.min.js"></script>
<script src="/assets/js/dataTables.bootstrap5.min.js"></script>

<style>
    .badge-status { font-size:.68rem; padding:.35em .6em; border-radius:6px; }
    #scTable thead th { font-size:.72rem; text-transform:uppercase; color:#6c757d; letter-spacing:.3px; }
</style>

<script>
$(function () {
    const SAVE_URL = '<?= buildUrl('api/pos/save_salary_component.php') ?>';
    const DEL_URL  = '<?= buildUrl('api/pos/delete_salary_component.php') ?>';
    const CSRF     = '<?= csrf_token() ?>';

    if (!$.fn.DataTable.isDataTable('#scTable')) {
        $('#scTable').DataTable({
            responsive:false, scrollX:true, pageLength:25, order:[[1,'asc']], dom:'rtip',
            columnDefs:[{ targets:[3], className:'text-end' }, { targets:[4,5,6], orderable:false }],
            drawCallback: renderCards,
            language:{ emptyTable:'No salary components yet.', zeroRecords:'No matching components.' }
        });
    }

    // §UI-3 Select2 inside modal + the percentage/amount unit hint
    $('#addModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#addModal'), width:'100%' });
        });
    });
    $('#f-calc').on('change', function () { $('#f-unit').text($(this).val() === 'percentage' ? '%' : 'amount'); });

    // §UI-7 mobile card view
    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    // §UI-4 SweetAlert flow: hide modal + reload before the toast
    $('#scForm').on('submit', function (e) {
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
        $('#scForm')[0].reset(); $('#f-id').val(''); $('#f-tax').prop('checked', false);
        $('#modalTitle').text('New Salary Component'); $('#f-unit').text('amount');
        $('.select2-static', this).val(null).trigger('change');
    });

    window.editComponent = function (c) {
        $('#f-id').val(c.component_id);
        $('#f-name').val(c.component_name);
        $('#f-type').val(c.component_type).trigger('change');
        $('#f-calc').val(c.calculation_type).trigger('change');
        $('#f-amount').val(c.default_amount);
        $('#f-desc').val(c.description || '');
        $('#f-tax').prop('checked', c.tax_applicable == 1);
        $('#f-unit').text(c.calculation_type === 'percentage' ? '%' : 'amount');
        $('#modalTitle').text('Edit Salary Component');
        new bootstrap.Modal(document.getElementById('addModal')).show();
    };

    window.confirmDelete = function (id) {
        Swal.fire({ title:'Delete this component?', text:'It will no longer be available for new salary structures.', icon:'warning',
            showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Yes, delete' })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url:DEL_URL, type:'POST', dataType:'json', data:{ component_id:id, _csrf:CSRF },
                success:function(res){ if(res.success){ location.reload(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    };

    renderCards();
});

function renderCards() {
    const $cv = $('#cardView'); const trs = $('#scTable tbody tr');
    if (!trs.length || (trs.length === 1 && $(trs[0]).find('td').length === 1)) { $cv.html('<div class="col-12 text-center py-5 text-muted">No components</div>'); return; }
    let html = '';
    trs.each(function () {
        const td = $(this).find('td'); if (td.length < 7) return;
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${td.eq(0).text()}</span>${td.eq(1).html()}</div>
                <div class="small text-muted">${td.eq(2).text()} · Default ${td.eq(3).text()}</div>
            </div>
            <div class="card-footer bg-white border-top p-2">${td.eq(6).html()}</div>
        </div></div>`;
    });
    $cv.html(html);
}
</script>

<?php includeFooter(); ?>
