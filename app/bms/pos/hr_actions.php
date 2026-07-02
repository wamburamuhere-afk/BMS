<?php
// HR Actions — employee lifecycle events (Tier 1).
// One page for promotions, transfers, awards, warnings, complaints,
// resignations and terminations: stat cards, filters, DataTable + mobile
// cards, per-state workflow actions, shared New-Action modal.
// List data comes scope-filtered from api/get_lifecycle_events.php.
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/lifecycle_effects.php';

// Enforce permission BEFORE any output
autoEnforcePermission('employee_lifecycle');

// D5 — apply any approved resignation whose last working day has passed
applyDueLifecycleEffects($pdo);

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View HR actions', 'User viewed the HR actions (employee lifecycle) page');

// Permission flags for UI elements
$can_create  = canCreate('employee_lifecycle');
$can_edit    = canEdit('employee_lifecycle');
$can_delete  = canDelete('employee_lifecycle');
$can_approve = canApprove('employee_lifecycle');

// ── Stat cards: current year, scope-filtered (same helper the API uses) ────
$scope_sql = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'e') : '';
$stats_sql = "
    SELECT
        SUM(ele.status = 'pending') AS pending_count,
        SUM(ele.status = 'approved' AND ele.event_type IN ('promotion','demotion')) AS promotions,
        SUM(ele.status = 'approved' AND ele.event_type = 'transfer') AS transfers,
        SUM(ele.status = 'approved' AND ele.event_type = 'award') AS awards,
        SUM(ele.status = 'approved' AND ele.event_type = 'warning') AS warnings,
        SUM(ele.status = 'approved' AND ele.event_type IN ('resignation','termination')) AS exits
    FROM employee_lifecycle_events ele
    JOIN employees e ON e.employee_id = ele.employee_id
    WHERE ele.status != 'deleted' AND YEAR(ele.event_date) = YEAR(CURDATE()) $scope_sql
";
$stats = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC) ?: [];
?>

<div class="container-fluid mt-4">
    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-person-lines-fill me-2 text-primary"></i>HR Actions</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#lifecycleModal">
            <i class="bi bi-plus-circle me-1"></i> New Action
        </button>
        <?php endif; ?>
    </div>

    <!-- Statistics cards (approved this year; pending = needs attention) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= (int)($stats['pending_count'] ?? 0) ?></div>
                <div class="small text-muted">Pending Approval</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= (int)($stats['promotions'] ?? 0) ?></div>
                <div class="small text-muted">Promotions</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= (int)($stats['transfers'] ?? 0) ?></div>
                <div class="small text-muted">Transfers</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= (int)($stats['awards'] ?? 0) ?></div>
                <div class="small text-muted">Awards</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= (int)($stats['warnings'] ?? 0) ?></div>
                <div class="small text-muted">Warnings</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= (int)($stats['exits'] ?? 0) ?></div>
                <div class="small text-muted">Exits</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Type</label>
                    <select class="form-select form-select-sm" id="f_type">
                        <option value="">All types</option>
                        <option value="promotion">Promotion</option>
                        <option value="demotion">Demotion</option>
                        <option value="transfer">Transfer</option>
                        <option value="award">Award</option>
                        <option value="warning">Warning</option>
                        <option value="complaint">Complaint</option>
                        <option value="resignation">Resignation</option>
                        <option value="termination">Termination</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select class="form-select form-select-sm" id="f_status">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="approved">Approved</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label small mb-1">Employee</label>
                    <select class="form-select form-select-sm" id="f_employee">
                        <option value="">All employees</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" class="form-control form-control-sm" id="f_from">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" class="form-control form-control-sm" id="f_to">
                </div>
                <div class="col-12 col-md-1">
                    <button class="btn btn-sm btn-outline-secondary w-100" id="f_reset" title="Reset filters"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Desktop table -->
    <div id="tableView" class="card border-0 shadow-sm">
        <div class="card-body">
            <table id="hrActionsTable" class="table table-hover align-middle w-100">
                <thead class="table-dark">
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Change</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Mobile card view -->
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- View modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-eye me-1"></i> HR Action Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBody"></div>
            <div class="modal-footer" id="viewFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php if ($can_create) { $lifecycle_preselect = null; require __DIR__ . '/includes/lifecycle_modal.php'; } ?>

<script>
const CAN_EDIT    = <?= json_encode($can_edit) ?>;
const CAN_DELETE  = <?= json_encode($can_delete) ?>;
const CAN_APPROVE = <?= json_encode($can_approve) ?>;
const MY_USER_ID  = <?= (int)$_SESSION['user_id'] ?>;
const CSRF        = <?= json_encode(csrf_token()) ?>;

const TYPE_BADGES = {
    promotion:   ['#0d6efd', '#fff',    'bi-arrow-up-circle'],
    demotion:    ['#6c757d', '#fff',    'bi-arrow-down-circle'],
    transfer:    ['#cfe2ff', '#084298', 'bi-arrow-left-right'],
    award:       ['#bfdbfe', '#1e3a8a', 'bi-trophy'],
    warning:     ['#e9ecef', '#495057', 'bi-exclamation-triangle'],
    complaint:   ['#e9ecef', '#495057', 'bi-chat-left-text'],
    resignation: ['#6c757d', '#fff',    'bi-box-arrow-right'],
    termination: ['#dc3545', '#fff',    'bi-x-octagon'],
};
const STATUS_BADGES = {
    pending:   ['#e9ecef', '#495057'],
    approved:  ['#0d6efd', '#fff'],
    rejected:  ['#dc3545', '#fff'],
    cancelled: ['#6c757d', '#fff'],
};

let table = null;
let ROWS = [];

function typeBadge(t) {
    const [bg, fg, icon] = TYPE_BADGES[t] || ['#e9ecef', '#495057', 'bi-tag'];
    return `<span class="badge" style="background:${bg};color:${fg}"><i class="bi ${icon} me-1"></i>${t.charAt(0).toUpperCase() + t.slice(1)}</span>`;
}
function statusBadge(s) {
    const [bg, fg] = STATUS_BADGES[s] || ['#e9ecef', '#495057'];
    return `<span class="badge" style="background:${bg};color:${fg}">${s.charAt(0).toUpperCase() + s.slice(1)}</span>`;
}
function changeSummary(r) {
    switch (r.event_type) {
        case 'promotion':
        case 'demotion': {
            let s = `${safeOutput(r.old_designation_name || '—')} → ${safeOutput(r.new_designation_name || '—')}`;
            if (r.new_salary) s += `<br><small class="text-muted">${Number(r.old_salary || 0).toLocaleString()} → ${Number(r.new_salary).toLocaleString()}</small>`;
            return s;
        }
        case 'transfer': {
            const bits = [];
            if (r.new_department_id) bits.push(`${safeOutput(r.old_department_name || '—')} → ${safeOutput(r.new_department_name || '—')}`);
            if (r.new_project_id) bits.push(`<small class="text-muted">${safeOutput(r.old_project_name || 'No project')} → ${safeOutput(r.new_project_name || '—')}</small>`);
            return bits.join('<br>') || '—';
        }
        case 'award':       return safeOutput(r.award_type || '—') + (r.award_amount ? ` <small class="text-muted">(${Number(r.award_amount).toLocaleString()})</small>` : '');
        case 'warning':     return r.severity ? `${r.severity.charAt(0).toUpperCase() + r.severity.slice(1)} warning` : '—';
        case 'complaint':   return `By: ${safeOutput(r.complainant || '—')}`;
        case 'resignation': return `Last day: ${safeOutput(r.end_date || '—')}`;
        case 'termination': return safeOutput(r.termination_type || '—');
        default: return '—';
    }
}

function actionButtons(r) {
    let items = `<li><button class="dropdown-item py-2 rounded" onclick="viewEvent(${r.event_id})"><i class="bi bi-eye text-primary me-2"></i> View</button></li>`;
    if (r.status === 'pending') {
        if (CAN_APPROVE) {
            items += `<li><button class="dropdown-item py-2 rounded" onclick="doAction(${r.event_id}, 'approve')"><i class="bi bi-check2-all text-primary me-2"></i> Approve</button></li>`;
            items += `<li><button class="dropdown-item py-2 rounded" onclick="doAction(${r.event_id}, 'reject')"><i class="bi bi-slash-circle text-danger me-2"></i> Reject</button></li>`;
        }
        if (Number(r.created_by) === MY_USER_ID || CAN_EDIT) {
            items += `<li><button class="dropdown-item py-2 rounded" onclick="doAction(${r.event_id}, 'cancel')"><i class="bi bi-x-circle me-2"></i> Cancel</button></li>`;
        }
    }
    if (r.attachment_path) {
        items += `<li><a class="dropdown-item py-2 rounded" href="<?= buildUrl('api/download_lifecycle_attachment.php') ?>?event_id=${r.event_id}"><i class="bi bi-download text-primary me-2"></i> Attachment</a></li>`;
    }
    if (CAN_DELETE && r.status !== 'approved') {
        items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" onclick="confirmDelete(${r.event_id})"><i class="bi bi-trash text-danger me-2"></i> Delete</button></li>`;
    }
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-gear-fill me-1"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
    </div>`;
}

function loadData() {
    const params = {
        event_type: $('#f_type').val(), status: $('#f_status').val(),
        employee_id: $('#f_employee').val() || '', date_from: $('#f_from').val(), date_to: $('#f_to').val()
    };
    $.getJSON('<?= buildUrl('api/get_lifecycle_events.php') ?>', params, function (res) {
        if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not load data.' }); return; }
        ROWS = res.data;
        const data = res.data.map(r => [
            safeOutput(r.event_date),
            `<a href="<?= getUrl('employee_details') ?>?id=${r.employee_id}" class="text-decoration-none fw-semibold">${safeOutput(r.first_name + ' ' + r.last_name)}</a>`,
            typeBadge(r.event_type),
            safeOutput(r.title),
            changeSummary(r),
            statusBadge(r.status),
            actionButtons(r)
        ]);
        table.clear().rows.add(data).draw();
    });
}

$(document).ready(function () {
    table = $('#hrActionsTable').DataTable({
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [[0, 'desc']],
        dom: 'rtipB',
        buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
        language: { emptyTable: 'No HR actions recorded yet.', zeroRecords: 'No matching records.' },
        drawCallback: function () {
            const pageRows = this.api().rows({ page: 'current' })[0].map(i => ROWS[i]).filter(Boolean);
            renderCards(pageRows);
        }
    });

    // Employee filter — AJAX Select2 (page-level, no modal parent)
    $('#f_employee').select2({
        theme: 'bootstrap-5', placeholder: 'All employees', allowClear: true, width: '100%',
        minimumInputLength: 1,
        ajax: {
            url: '<?= buildUrl('api/account/search_employees.php') ?>',
            dataType: 'json', delay: 300,
            data: params => ({ q: params.term }),
            processResults: data => ({ results: data.results }),
            cache: true
        }
    });

    $('#f_type, #f_status, #f_employee').on('change', loadData);
    $('#f_from, #f_to').on('change', loadData);
    $('#f_reset').on('click', function () {
        $('#f_type, #f_status').val('');
        $('#f_employee').val(null).trigger('change.select2');
        $('#f_from, #f_to').val('');
        loadData();
    });

    function applyView() {
        if (window.innerWidth < 768) {
            $('#tableView').addClass('d-none');
            $('#cardView').removeClass('d-none');
        } else {
            $('#tableView').removeClass('d-none');
            $('#cardView').addClass('d-none');
        }
    }
    applyView();
    $(window).on('resize', applyView);

    loadData();
});

// Refresh hook called by the shared modal after a successful save
window.onLifecycleSaved = loadData;

function viewEvent(id) {
    const r = ROWS.find(x => Number(x.event_id) === id);
    if (!r) return;
    let html = `
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <div class="fs-5 fw-bold">${safeOutput(r.title)}</div>
                <div>${typeBadge(r.event_type)} ${statusBadge(r.status)}</div>
            </div>
            <div class="text-end small text-muted">
                Effective: <strong>${safeOutput(r.event_date)}</strong><br>
                ${r.end_date ? 'End date: <strong>' + safeOutput(r.end_date) + '</strong><br>' : ''}
                ${r.notice_date ? 'Notice: <strong>' + safeOutput(r.notice_date) + '</strong>' : ''}
            </div>
        </div>
        <table class="table table-sm">
            <tr><th style="width:35%">Employee</th><td><a href="<?= getUrl('employee_details') ?>?id=${r.employee_id}">${safeOutput(r.first_name + ' ' + r.last_name)}</a></td></tr>
            <tr><th>Change</th><td>${changeSummary(r)}</td></tr>
            ${r.description ? `<tr><th>Details</th><td>${safeOutput(r.description)}</td></tr>` : ''}
            ${r.resolution ? `<tr><th>Resolution</th><td>${safeOutput(r.resolution)}</td></tr>` : ''}
            ${r.reject_reason ? `<tr><th class="text-danger">Reject reason</th><td class="text-danger">${safeOutput(r.reject_reason)}</td></tr>` : ''}
            <tr><th>Recorded by</th><td>${safeOutput(r.created_by_name || '—')} <small class="text-muted">on ${safeOutput((r.created_at || '').substring(0, 10))}</small></td></tr>
            ${r.approved_by_name ? `<tr><th>${r.status === 'rejected' ? 'Rejected' : 'Approved'} by</th><td>${safeOutput(r.approved_by_name)} <small class="text-muted">on ${safeOutput((r.approved_at || '').substring(0, 10))}</small></td></tr>` : ''}
            ${r.effect_applied_at ? `<tr><th>Effect applied</th><td>${safeOutput(r.effect_applied_at)}</td></tr>` : ''}
            ${r.attachment_path ? `<tr><th>Attachment</th><td><a href="<?= buildUrl('api/download_lifecycle_attachment.php') ?>?event_id=${r.event_id}"><i class="bi bi-download me-1"></i>${safeOutput(r.attachment_name || 'Download')}</a></td></tr>` : ''}
        </table>`;
    $('#viewBody').html(html);
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}

function doAction(id, action) {
    const labels = { approve: 'Approve', reject: 'Reject', cancel: 'Cancel' };
    const opts = {
        title: labels[action] + '?',
        icon: action === 'approve' ? 'question' : 'warning',
        showCancelButton: true,
        confirmButtonColor: action === 'reject' ? '#dc3545' : '#0d6efd',
        confirmButtonText: 'Yes, ' + labels[action]
    };
    if (action === 'approve') opts.text = 'The change will be applied to the employee record.';
    if (action === 'cancel')  opts.text = 'The pending action will be withdrawn.';
    if (action === 'reject') {
        opts.input = 'text';
        opts.inputPlaceholder = 'Reason for rejection (required)';
        opts.inputValidator = v => !v ? 'A reason is required to reject' : undefined;
    }
    Swal.fire(opts).then(res => {
        if (!res.isConfirmed) return;
        $.post('<?= buildUrl('api/change_lifecycle_status.php') ?>',
            { event_id: id, action: action, reject_reason: res.value || '', _csrf: CSRF },
            function (r) {
                if (r.success) {
                    loadData();
                    Swal.fire({ icon: 'success', title: 'Done!', text: r.message, timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: r.message });
                }
            }, 'json');
    });
}

function confirmDelete(id) {
    Swal.fire({
        title: 'Delete?', text: 'This cannot be undone.', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, Delete'
    }).then(res => {
        if (!res.isConfirmed) return;
        $.post('<?= buildUrl('api/delete_lifecycle_event.php') ?>', { event_id: id, _csrf: CSRF }, function (r) {
            if (r.success) {
                loadData();
                Swal.fire({ icon: 'success', title: 'Deleted!', text: r.message, timer: 1800, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: r.message });
            }
        }, 'json');
    });
}

// Mobile card renderer
function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No HR actions recorded yet.</div>');
        return;
    }
    let html = '';
    rows.forEach(r => {
        let btns = `<button class="btn btn-sm btn-outline-primary" onclick="viewEvent(${r.event_id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-eye"></i></button>`;
        if (r.status === 'pending' && CAN_APPROVE) {
            btns += `<button class="btn btn-sm btn-outline-primary" onclick="doAction(${r.event_id}, 'approve')" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-check2-all"></i></button>`;
            btns += `<button class="btn btn-sm btn-outline-danger" onclick="doAction(${r.event_id}, 'reject')" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-slash-circle"></i></button>`;
        }
        if (CAN_DELETE && r.status !== 'approved') {
            btns += `<button class="btn btn-sm btn-outline-danger" onclick="confirmDelete(${r.event_id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-trash"></i></button>`;
        }
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-bold">${safeOutput(r.first_name + ' ' + r.last_name)}</div>
                        ${statusBadge(r.status)}
                    </div>
                    <div class="mt-1">${typeBadge(r.event_type)} <small class="text-muted">${safeOutput(r.event_date)}</small></div>
                    <div class="small mt-1">${safeOutput(r.title)}</div>
                    <div class="small text-muted mt-1">${changeSummary(r)}</div>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${btns}</div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
