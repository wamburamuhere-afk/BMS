<?php
// app/bms/pos/company_calendar.php
// Working Days + Public Holidays — the calendar leave day-counting reads when a
// leave type has "Count only working days" turned on (core/company_calendar.php,
// core/leave_rules.php::leaveDaysFor()). Before this page, public_holidays was a
// table that existed in the schema with zero rows and zero code referencing it
// anywhere — this is its first real consumer. Standards: .claude/ui-constants.md.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/company_calendar.php';

autoEnforcePermission('company_calendar');
includeHeader();
global $pdo;

$can_edit   = isAdmin() || canEdit('company_calendar');
$can_delete = isAdmin() || canDelete('company_calendar');

$working_days = companyWorkingDays();

$holidays = $pdo->query("SELECT * FROM public_holidays ORDER BY holiday_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$stat_active = 0; $stat_recurring = 0;
foreach ($holidays as $h) {
    if ($h['status'] === 'active') $stat_active++;
    if ((int)$h['recurring'] === 1) $stat_recurring++;
}

$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
?>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= getUrl('leaves') ?>" class="text-decoration-none">Leaves</a></li>
            <li class="breadcrumb-item active">Working Days &amp; Holidays</li>
        </ol>
    </nav>

    <div class="mb-3">
        <h4 class="mb-0 fw-bold"><i class="bi bi-calendar-week text-primary me-2"></i>Working Days &amp; Holidays</h4>
        <p class="text-muted small mb-0">The company calendar. Feeds any leave type with "Count only working days" turned on (Leave Types page) — such a leave type deducts business days instead of calendar days.</p>
    </div>

    <!-- Working Days -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0"><i class="bi bi-calendar-range text-primary me-1"></i> Working Days</h5>
        </div>
        <div class="card-body">
            <?php if ($can_edit): ?>
            <form id="workingDaysForm">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div class="d-flex flex-wrap gap-3 mb-3">
                    <?php foreach ($weekdays as $iso => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="working_days[]" value="<?= $iso ?>"
                               id="wd_<?= $iso ?>" <?= in_array($iso, $working_days, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="wd_<?= $iso ?>"><?= $label ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary btn-sm px-4"><i class="bi bi-check-circle me-1"></i> Save Working Days</button>
                <span id="wd-saved-msg" class="ms-2 text-success small d-none"><i class="bi bi-check-circle-fill"></i> Saved</span>
            </form>
            <?php else: ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($weekdays as $iso => $label): ?>
                <span class="badge <?= in_array($iso, $working_days, true) ? 'bg-primary' : 'bg-light text-muted' ?>"><?= $label ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Public Holidays -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="mb-0"><i class="bi bi-flag text-primary me-1"></i> Public Holidays</h5>
        <?php if ($can_edit): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-circle me-1"></i> New Holiday</button>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-primary"><?= count($holidays) ?></div><div class="small text-muted">Total</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-success"><?= $stat_active ?></div><div class="small text-muted">Active</div></div></div>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm text-center p-3"><div class="fs-4 fw-bold text-info"><?= $stat_recurring ?></div><div class="small text-muted">Recurring Yearly</div></div></div>
    </div>

    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table id="holTable" class="table table-hover align-middle w-100 mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-center">Recurring</th>
                            <th>Location</th>
                            <th class="text-center">Status</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holidays as $h): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= safe_output($h['holiday_name']) ?></td>
                            <td><?= date('d M Y', strtotime($h['holiday_date'])) ?></td>
                            <td><span class="text-capitalize"><?= safe_output($h['holiday_type'], 'national') ?></span></td>
                            <td class="text-center"><?= (int)$h['recurring'] === 1 ? '<i class="bi bi-arrow-repeat text-info" title="Repeats every year"></i>' : '<span class="text-muted">—</span>' ?></td>
                            <td><?= safe_output(trim(($h['region'] ? $h['region'] . ', ' : '') . $h['country']), '—') ?></td>
                            <td class="text-center"><span class="badge-status" style="background:<?= $h['status'] === 'active' ? '#198754' : '#6c757d' ?>;color:#fff;"><?= strtoupper($h['status']) ?></span></td>
                            <td class="text-end pe-3">
                                <div class="dropdown d-flex justify-content-end">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                                        <?php if ($can_edit): ?>
                                        <li><button class="dropdown-item py-2 rounded" onclick='editRow(<?= htmlspecialchars(json_encode($h), ENT_QUOTES) ?>)'><i class="bi bi-pencil text-primary me-2"></i> Edit</button></li>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><button class="dropdown-item py-2 rounded" onclick="toggleStatus(<?= (int)$h['holiday_id'] ?>, '<?= $h['status'] === 'active' ? 'inactive' : 'active' ?>')">
                                            <i class="bi bi-<?= $h['status'] === 'active' ? 'slash-circle text-secondary' : 'check-circle text-success' ?> me-2"></i> <?= $h['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
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
                <h5 class="modal-title"><i class="bi bi-flag me-1"></i> <span id="modalTitle">New Holiday</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="holForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="holiday_id" id="f-id">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="holiday_name" id="f-name" required placeholder="e.g. Union Day">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="holiday_date" id="f-date" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Type</label>
                            <select class="form-select select2-static" name="holiday_type" id="f-type">
                                <option value="national">National</option>
                                <option value="regional">Regional</option>
                                <option value="religious">Religious</option>
                                <option value="company">Company</option>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="recurring" id="f-recurring" value="1" checked>
                                <label class="form-check-label" for="f-recurring">Repeats every year</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Country</label>
                            <input type="text" class="form-control" name="country" id="f-country" value="Tanzania">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Region</label>
                            <input type="text" class="form-control" name="region" id="f-region" placeholder="Optional — leave blank for nationwide">
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
    #holTable thead th { font-size:.72rem; text-transform:uppercase; color:#6c757d; letter-spacing:.3px; }
</style>

<script>
$(function () {
    const SAVE_URL     = '<?= buildUrl('api/pos/save_holiday.php') ?>';
    const STATUS_URL   = '<?= buildUrl('api/pos/toggle_holiday_status.php') ?>';
    const WD_SAVE_URL  = '<?= buildUrl('api/pos/save_working_days.php') ?>';
    const CSRF         = '<?= csrf_token() ?>';

    if (!$.fn.DataTable.isDataTable('#holTable')) {
        $('#holTable').DataTable({
            responsive:false, scrollX:true, pageLength:25, order:[[1,'desc']], dom:'rtip',
            columnDefs:[{ targets:[3,4,5,6], orderable:false }],
            drawCallback: renderCards,
            language:{ emptyTable:'No public holidays yet.', zeroRecords:'No matching holidays.' }
        });
    }

    $('#addModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme:'bootstrap-5', dropdownParent:$('#addModal'), width:'100%' });
        });
    });

    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView(); $(window).on('resize', applyView);

    $('#workingDaysForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({ url:WD_SAVE_URL, type:'POST', data:$(this).serialize(), dataType:'json',
            success:function (res) {
                if (res.success) { $('#wd-saved-msg').removeClass('d-none').delay(2000).fadeOut(); }
                else { Swal.fire({ icon:'error', title:'Error', text:res.message || 'Could not save.' }); }
            },
            error:function(){ Swal.fire({ icon:'error', title:'Error', text:'Server error.' }); },
            complete:function(){ btn.prop('disabled', false).html(orig); $('#wd-saved-msg').show(); }
        });
    });

    $('#holForm').on('submit', function (e) {
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
        $('#holForm')[0].reset(); $('#f-id').val('');
        $('#modalTitle').text('New Holiday');
        $('#f-country').val('Tanzania');
        $('#f-recurring').prop('checked', true);
        $('.select2-static', this).val('national').trigger('change');
    });

    window.editRow = function (h) {
        $('#f-id').val(h.holiday_id);
        $('#f-name').val(h.holiday_name);
        $('#f-date').val(h.holiday_date);
        $('#f-type').val(h.holiday_type || 'national').trigger('change');
        $('#f-recurring').prop('checked', String(h.recurring) === '1');
        $('#f-country').val(h.country || 'Tanzania');
        $('#f-region').val(h.region || '');
        $('#f-desc').val(h.description || '');
        $('#modalTitle').text('Edit Holiday');
        new bootstrap.Modal(document.getElementById('addModal')).show();
    };

    window.toggleStatus = function (id, newStatus) {
        const verb = newStatus === 'active' ? 'activate' : 'deactivate';
        Swal.fire({ title: `${verb.charAt(0).toUpperCase()+verb.slice(1)} this holiday?`,
            text: newStatus === 'inactive' ? "It will no longer be excluded from working-day leave counts." : 'It will be excluded from working-day leave counts again.',
            icon:'warning', showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:`Yes, ${verb}` })
        .then(r => { if (!r.isConfirmed) return;
            $.ajax({ url:STATUS_URL, type:'POST', dataType:'json', data:{ holiday_id:id, status:newStatus, _csrf:CSRF },
                success:function(res){ if(res.success){ location.reload(); } else { Swal.fire({icon:'error',title:'Error',text:res.message}); } },
                error:function(){ Swal.fire({icon:'error',title:'Error',text:'Server error.'}); } });
        });
    };

    renderCards();
});

function renderCards() {
    const $cv = $('#cardView'); const trs = $('#holTable tbody tr');
    if (!trs.length || (trs.length === 1 && $(trs[0]).find('td').length === 1)) { $cv.html('<div class="col-12 text-center py-5 text-muted">No holidays</div>'); return; }
    let html = '';
    trs.each(function () {
        const td = $(this).find('td'); if (td.length < 7) return;
        html += `<div class="col-12"><div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between"><span class="fw-bold">${td.eq(0).text()}</span>${td.eq(5).html()}</div>
                <div class="small text-muted">${td.eq(1).text()} · ${td.eq(2).text()}</div>
            </div>
            <div class="card-footer bg-white border-top p-2">${td.eq(6).html()}</div>
        </div></div>`;
    });
    $cv.html(html);
}
</script>

<?php includeFooter(); ?>
