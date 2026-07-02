<?php
/**
 * Shared "New HR Action" modal + JS (Tier 1 — employee lifecycle).
 * Included by hr_actions.php and employee_details.php so the form logic
 * lives in exactly one place.
 *
 * Expects in scope:
 *   $pdo
 *   $lifecycle_preselect  — optional ['employee_id' => int, 'name' => string];
 *                           when set the employee picker is locked to that person.
 *
 * The caller must hold canCreate('employee_lifecycle') before including.
 */
if (!isset($lifecycle_preselect)) $lifecycle_preselect = null;

// Small lookup lists for the "To" selects (static Select2 — short lists)
$lc_designations = $pdo->query("SELECT designation_id, designation_name FROM designations WHERE status = 'active' ORDER BY designation_name")->fetchAll(PDO::FETCH_ASSOC);
$lc_departments  = $pdo->query("SELECT department_id, department_name FROM departments WHERE status = 'active' ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
// Projects: strict scope — a non-admin only ever picks projects they own (§23 rule 1)
$lc_proj_scope   = function_exists('scopeFilterSql') ? scopeFilterSql('project', 'projects') : '';
$lc_projects     = $pdo->query("SELECT project_id, project_name FROM projects WHERE status NOT IN ('cancelled') $lc_proj_scope ORDER BY project_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- New HR Action Modal (shared include) -->
<div class="modal fade" id="lifecycleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-lines-fill me-1"></i> New HR Action</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="lifecycleForm" autocomplete="off" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="lifecycle-message" class="mb-2"></div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Employee <span class="text-danger">*</span></label>
                            <?php if ($lifecycle_preselect): ?>
                            <input type="hidden" name="employee_id" id="lc_employee" value="<?= (int)$lifecycle_preselect['employee_id'] ?>">
                            <input type="text" class="form-control" value="<?= safe_output($lifecycle_preselect['name']) ?>" disabled>
                            <?php else: ?>
                            <select class="form-select" name="employee_id" id="lc_employee" required>
                                <option value="">Type to search...</option>
                            </select>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Action Type <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="event_type" id="lc_type" required>
                                <option value="">-- Select --</option>
                                <option value="promotion">Promotion</option>
                                <option value="transfer">Transfer</option>
                                <option value="award">Award</option>
                                <option value="warning">Warning</option>
                                <option value="complaint">Complaint</option>
                                <option value="resignation">Resignation</option>
                                <option value="termination">Termination</option>
                            </select>
                        </div>
                    </div>

                    <!-- Current ("From") snapshot — read-only, loaded on employee pick -->
                    <div id="lc_current" class="mt-3 p-2 rounded d-none" style="background:#e7f0ff;border:1px solid #b6ccfe">
                        <div class="small text-muted mb-1">Current position</div>
                        <div class="small" id="lc_current_body">—</div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="event_date" id="lc_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" id="lc_title" placeholder="e.g. Promoted to Senior Accountant" required maxlength="255">
                        </div>
                    </div>

                    <!-- Promotion / Demotion -->
                    <div class="row g-3 mt-0 lc-group lc-promotion lc-demotion d-none">
                        <div class="col-md-6">
                            <label class="form-label">New Designation <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="new_designation_id" id="lc_new_designation">
                                <option value="">-- Select --</option>
                                <?php foreach ($lc_designations as $d): ?>
                                <option value="<?= (int)$d['designation_id'] ?>"><?= safe_output($d['designation_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Basic Salary <small class="text-muted">(optional)</small></label>
                            <input type="number" class="form-control" name="new_salary" id="lc_new_salary" min="0" step="0.01" placeholder="Leave blank to keep current">
                        </div>
                    </div>

                    <!-- Transfer -->
                    <div class="row g-3 mt-0 lc-group lc-transfer d-none">
                        <div class="col-md-6">
                            <label class="form-label">New Department</label>
                            <select class="form-select select2-static" name="new_department_id" id="lc_new_department">
                                <option value="">-- Keep current --</option>
                                <?php foreach ($lc_departments as $d): ?>
                                <option value="<?= (int)$d['department_id'] ?>"><?= safe_output($d['department_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Project</label>
                            <select class="form-select select2-static" name="new_project_id" id="lc_new_project">
                                <option value="">-- Keep current --</option>
                                <?php foreach ($lc_projects as $p): ?>
                                <option value="<?= (int)$p['project_id'] ?>"><?= safe_output($p['project_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pick a department, a project, or both.</div>
                        </div>
                    </div>

                    <!-- Award -->
                    <div class="row g-3 mt-0 lc-group lc-award d-none">
                        <div class="col-md-4">
                            <label class="form-label">Award Type <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="award_type" id="lc_award_type" placeholder="e.g. Employee of the Month" maxlength="100">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gift <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control" name="award_gift" maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Cash Amount <small class="text-muted">(optional)</small></label>
                            <input type="number" class="form-control" name="award_amount" min="0" step="0.01">
                        </div>
                    </div>

                    <!-- Warning -->
                    <div class="row g-3 mt-0 lc-group lc-warning d-none">
                        <div class="col-md-6">
                            <label class="form-label">Severity <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="severity" id="lc_severity">
                                <option value="">-- Select --</option>
                                <option value="verbal">Verbal</option>
                                <option value="written">Written</option>
                                <option value="final">Final</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Valid Until <small class="text-muted">(optional expiry)</small></label>
                            <input type="date" class="form-control" name="end_date" id="lc_warning_end">
                        </div>
                    </div>

                    <!-- Complaint -->
                    <div class="row g-3 mt-0 lc-group lc-complaint d-none">
                        <div class="col-md-6">
                            <label class="form-label">Complainant <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="complainant" id="lc_complainant" placeholder="Employee or external party" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Resolution <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control" name="resolution" maxlength="500">
                        </div>
                    </div>

                    <!-- Resignation -->
                    <div class="row g-3 mt-0 lc-group lc-resignation d-none">
                        <div class="col-md-6">
                            <label class="form-label">Notice Date <small class="text-muted">(optional)</small></label>
                            <input type="date" class="form-control" name="notice_date" id="lc_notice_date">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Working Day <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="end_date" id="lc_resignation_end">
                            <div class="form-text">The employee stays active until this date passes.</div>
                        </div>
                    </div>

                    <!-- Termination -->
                    <div class="row g-3 mt-0 lc-group lc-termination d-none">
                        <div class="col-md-6">
                            <label class="form-label">Termination Type <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="termination_type" id="lc_termination_type">
                                <option value="">-- Select --</option>
                                <option value="misconduct">Misconduct</option>
                                <option value="poor_performance">Poor Performance</option>
                                <option value="redundancy">Redundancy</option>
                                <option value="contract_end">End of Contract</option>
                                <option value="probation_failed">Failed Probation</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Notice Date <small class="text-muted">(optional)</small></label>
                            <input type="date" class="form-control" name="notice_date" id="lc_term_notice">
                        </div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-12">
                            <label class="form-label">Reason / Details <small class="text-muted">(optional)</small></label>
                            <textarea class="form-control" name="description" rows="2" placeholder="Narrative, citation or reason"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label"><i class="bi bi-paperclip me-1"></i>Attachment <small class="text-muted">(letter / certificate / evidence — optional)</small></label>
                            <input type="file" class="form-control" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save for Approval</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('lifecycleModal');
    const PRESELECTED = <?= $lifecycle_preselect ? 'true' : 'false' ?>;

    // Per-type required fields — mirror of the API's validation matrix
    const REQUIRED = {
        promotion:   ['lc_new_designation'],
        demotion:    ['lc_new_designation'],
        warning:     ['lc_severity'],
        complaint:   ['lc_complainant'],
        award:       ['lc_award_type'],
        resignation: ['lc_resignation_end'],
        termination: ['lc_termination_type'],
        transfer:    []   // "at least one of" — checked on submit
    };

    function toggleGroups() {
        const t = $('#lc_type').val();
        $('.lc-group').addClass('d-none').find('input,select,textarea').prop('disabled', true);
        if (t) $('.lc-' + t).removeClass('d-none').find('input,select,textarea').prop('disabled', false);
        $('#lifecycleForm [required]').not('#lc_employee,#lc_type,#lc_date,#lc_title').prop('required', false);
        (REQUIRED[t] || []).forEach(id => $('#' + id).prop('required', true));
    }

    function loadCurrent(empId) {
        if (!empId) { $('#lc_current').addClass('d-none'); return; }
        $.getJSON('<?= buildUrl('api/get_employee.php') ?>', { id: empId }, function (res) {
            if (!res.success) return;
            const d = res.data;
            const parts = [];
            if (d.designation_name || d.designation_id) parts.push('<strong>Designation:</strong> ' + safeOutput(d.designation_name || ('#' + d.designation_id)));
            if (d.department_name || d.department) parts.push('<strong>Department:</strong> ' + safeOutput(d.department_name || d.department));
            if (d.basic_salary) parts.push('<strong>Basic salary:</strong> ' + Number(d.basic_salary).toLocaleString());
            parts.push('<strong>Status:</strong> ' + safeOutput(d.employment_status));
            $('#lc_current_body').html(parts.join(' &nbsp;•&nbsp; '));
            $('#lc_current').removeClass('d-none');
        });
    }

    $(modalEl).on('shown.bs.modal', function () {
        const modal = $(this);
        // Static selects
        modal.find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: modal, placeholder: 'Select...', allowClear: true, width: '100%' });
            }
        });
        // Employee AJAX picker
        if (!PRESELECTED && !$('#lc_employee').hasClass('select2-hidden-accessible')) {
            $('#lc_employee').select2({
                theme: 'bootstrap-5', dropdownParent: modal, placeholder: 'Type to search...',
                allowClear: true, width: '100%', minimumInputLength: 1,
                ajax: {
                    url: '<?= buildUrl('api/account/search_employees.php') ?>',
                    dataType: 'json', delay: 300,
                    data: params => ({ q: params.term }),
                    processResults: data => ({ results: data.results }),
                    cache: true
                }
            });
        }
        if (PRESELECTED) loadCurrent($('#lc_employee').val());
        toggleGroups();
    });

    $(document).on('change', '#lc_type', toggleGroups);
    $(document).on('change', '#lc_employee', function () { loadCurrent($(this).val()); });

    $(modalEl).on('hidden.bs.modal', function () {
        const form = document.getElementById('lifecycleForm');
        form.reset();
        $('#lifecycle-message').html('');
        $('#lc_current').addClass('d-none');
        if (!PRESELECTED) $('#lc_employee').val(null).trigger('change.select2');
        $('.lc-group').addClass('d-none');
    });

    $('#lifecycleForm').on('submit', function (e) {
        e.preventDefault();
        const t = $('#lc_type').val();
        if (t === 'transfer' && !$('#lc_new_department').val() && !$('#lc_new_project').val()) {
            Swal.fire({ icon: 'error', title: 'Error', text: 'A transfer needs a new department and/or a new project.' });
            return;
        }
        const btn = $(this).find('[type="submit"]');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
        $.ajax({
            url: '<?= buildUrl('api/add_lifecycle_event.php') ?>',
            type: 'POST',
            data: new FormData(this),
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(modalEl).hide();
                    if (typeof window.onLifecycleSaved === 'function') window.onLifecycleSaved();
                    Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Something went wrong.' });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error. Please try again.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });
})();
</script>
