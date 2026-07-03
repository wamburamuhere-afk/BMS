<?php
// Employee Contracts — Tier 2, Phase 2.3.
// Draft -> active (activate) -> renewed/terminated lifecycle (D12). Activation
// dual-writes employees.contract_end_date/probation_end_date so every existing
// reader of those columns keeps working. List data comes scope-filtered from
// api/get_contracts.php.
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('employee_contracts');

includeHeader();

logActivity($pdo, $_SESSION['user_id'], 'View employee contracts', 'User viewed the Employee Contracts page');

// Permission flags for UI elements
$can_create  = canCreate('employee_contracts');
$can_approve = canApprove('employee_contracts');

// ── Stat cards: scope-filtered (same helper the API uses) ──────────────────
$scope_sql = function_exists('scopeFilterSqlNullable') ? scopeFilterSqlNullable('project', 'e') : '';
$stats_sql = "
    SELECT
        SUM(ec.status = 'active') AS active_count,
        SUM(ec.status = 'active' AND ec.end_date IS NOT NULL
            AND ec.end_date >= CURDATE() AND DATEDIFF(ec.end_date, CURDATE()) <= 60) AS expiring_count,
        SUM(ec.status = 'active' AND ec.end_date IS NOT NULL AND ec.end_date < CURDATE()) AS expired_count
    FROM employee_contracts ec
    JOIN employees e ON e.employee_id = ec.employee_id
    WHERE ec.status != 'deleted' $scope_sql
";
$stats = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC) ?: [];
$probation_sql = "SELECT COUNT(*) FROM employees e WHERE e.employment_status = 'probation'
                   AND (e.status IS NULL OR e.status != 'deleted') $scope_sql";
$probation_count = (int)$pdo->query($probation_sql)->fetchColumn();
?>

<div class="container-fluid mt-4">
    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Employee Contracts</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newContractModal" onclick="prepNewContractModal()">
            <i class="bi bi-plus-circle me-1"></i> New Contract
        </button>
        <?php endif; ?>
    </div>

    <!-- Statistics cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe">
                <div class="fs-4 fw-bold text-primary"><?= (int)($stats['active_count'] ?? 0) ?></div>
                <div class="small text-muted">Active</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-3" style="background:#fff3cd;border:1px solid #ffe69c">
                <div class="fs-4 fw-bold text-warning"><?= (int)($stats['expiring_count'] ?? 0) ?></div>
                <div class="small text-muted">Expiring &le; 60 days</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-3" style="background:#f8d7da;border:1px solid #f1aeb5">
                <div class="fs-4 fw-bold text-danger"><?= (int)($stats['expired_count'] ?? 0) ?></div>
                <div class="small text-muted">Expired</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center p-3" style="background:#e9ecef;border:1px solid #dee2e6">
                <div class="fs-4 fw-bold text-secondary"><?= $probation_count ?></div>
                <div class="small text-muted">On Probation</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Status</label>
                    <select class="form-select form-select-sm" id="f_status">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="renewed">Renewed</option>
                        <option value="terminated">Terminated</option>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">Type</label>
                    <select class="form-select form-select-sm" id="f_type">
                        <option value="">All types</option>
                        <option value="Permanent">Permanent</option>
                        <option value="Fixed-term">Fixed-term</option>
                        <option value="Probation">Probation</option>
                        <option value="Casual">Casual</option>
                        <option value="Consultancy">Consultancy</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label small mb-1">Employee</label>
                    <select class="form-select form-select-sm" id="f_employee">
                        <option value="">All employees</option>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button class="btn btn-sm btn-outline-secondary w-100" id="f_reset" title="Reset filters"><i class="bi bi-arrow-clockwise"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Desktop table -->
    <div id="tableView" class="card border-0 shadow-sm">
        <div class="card-body">
            <table id="contractsTable" class="table table-hover align-middle w-100">
                <thead class="table-dark">
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Start</th>
                        <th>End</th>
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
<div class="modal fade" id="viewContractModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-eye me-1"></i> Contract Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewContractBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php if ($can_create): ?>
<!-- New Contract modal (also used for "Renew" — pre-fills employee/type) -->
<div class="modal fade" id="newContractModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-plus me-1"></i> New Contract</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="newContractForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="new-contract-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" id="nc_employee_id" class="form-select select2-employee" required></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contract Type <span class="text-danger">*</span></label>
                        <select name="contract_type" id="nc_contract_type" class="form-select select2-static" required>
                            <option value="Permanent">Permanent</option>
                            <option value="Fixed-term">Fixed-term</option>
                            <option value="Probation">Probation</option>
                            <option value="Casual">Casual</option>
                            <option value="Consultancy">Consultancy</option>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date">
                            <div class="form-text">Leave blank for open-ended (permanent).</div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Probation (months)</label>
                            <input type="number" min="0" class="form-control" name="probation_months">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Basic Salary</label>
                            <input type="number" min="0" step="0.01" class="form-control" name="basic_salary">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Terms</label>
                        <textarea class="form-control" name="terms" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Signed Copy (optional)</label>
                        <input type="file" class="form-control" name="attachment">
                        <div class="form-text">PDF, Word, Excel or image. Max 10MB.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Save Draft</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const CAN_APPROVE = <?= json_encode($can_approve) ?>;
const CSRF        = <?= json_encode(csrf_token()) ?>;

const STATUS_BADGES = {
    draft:      ['#e9ecef', '#495057'],
    active:     ['#0d6efd', '#fff'],
    expired:    ['#dc3545', '#fff'],
    renewed:    ['#6c757d', '#fff'],
    terminated: ['#dc3545', '#fff'],
};

let table = null;
let ROWS = [];

function statusBadge(s) {
    const [bg, fg] = STATUS_BADGES[s] || ['#e9ecef', '#495057'];
    return `<span class="badge" style="background:${bg};color:${fg}">${s.charAt(0).toUpperCase() + s.slice(1)}</span>`;
}
function expiryChip(r) {
    if (!r.end_date) return '<span class="text-muted">&mdash;</span>';
    const d = Number(r.days_to_expiry);
    if (r.status !== 'active') return safeOutput(r.end_date);
    if (d < 0)  return safeOutput(r.end_date) + ' <span class="badge bg-danger">Expired</span>';
    if (d <= 60) return safeOutput(r.end_date) + ` <span class="badge bg-warning text-dark">${d}d left</span>`;
    return safeOutput(r.end_date);
}

function actionButtons(r) {
    let items = `<li><button class="dropdown-item py-2 rounded" onclick="viewContract(${r.contract_id})"><i class="bi bi-eye text-primary me-2"></i> View</button></li>`;
    if (r.status === 'draft' && CAN_APPROVE) {
        items += `<li><button class="dropdown-item py-2 rounded" onclick="doContractAction(${r.contract_id}, 'activate')"><i class="bi bi-check2-all text-primary me-2"></i> Activate</button></li>`;
    }
    if (r.status === 'active') {
        items += `<li><button class="dropdown-item py-2 rounded" onclick="renewContract(${r.contract_id})"><i class="bi bi-arrow-repeat text-primary me-2"></i> Renew</button></li>`;
        if (CAN_APPROVE) {
            items += `<li><button class="dropdown-item py-2 rounded text-danger" onclick="doContractAction(${r.contract_id}, 'terminate')"><i class="bi bi-x-octagon text-danger me-2"></i> Terminate</button></li>`;
        }
    }
    if (r.attachment_path && r.library_document_id) {
        items += `<li><a class="dropdown-item py-2 rounded" href="<?= getUrl('document_library') ?>?action=download&document_id=${r.library_document_id}" target="_blank"><i class="bi bi-download text-primary me-2"></i> Signed Copy</a></li>`;
    }
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-gear-fill me-1"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
    </div>`;
}

function loadData() {
    const params = { status: $('#f_status').val(), contract_type: $('#f_type').val(), employee_id: $('#f_employee').val() || '' };
    $.getJSON('<?= buildUrl('api/get_contracts.php') ?>', params, function (res) {
        if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not load data.' }); return; }
        ROWS = res.data;
        const data = res.data.map(r => [
            `<a href="<?= getUrl('employee_details') ?>?id=${r.employee_id}" class="text-decoration-none fw-semibold">${safeOutput(r.first_name + ' ' + r.last_name)}</a>`,
            safeOutput(r.contract_type),
            safeOutput(r.start_date),
            expiryChip(r),
            statusBadge(r.status),
            actionButtons(r)
        ]);
        table.clear().rows.add(data).draw();
    });
}

function empSelect2(el, dropdownParent) {
    el.select2({
        theme: 'bootstrap-5', placeholder: 'Select employee...', allowClear: true, width: '100%',
        minimumInputLength: 1, dropdownParent: dropdownParent,
        ajax: {
            url: '<?= buildUrl('api/account/search_employees.php') ?>',
            dataType: 'json', delay: 300,
            data: params => ({ q: params.term }),
            processResults: data => ({ results: data.results }),
            cache: true
        }
    });
}

$(document).ready(function () {
    table = $('#contractsTable').DataTable({
        responsive: false, scrollX: true, pageLength: 25, order: [[2, 'desc']], dom: 'rtipB',
        buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
        language: { emptyTable: 'No contracts recorded yet.', zeroRecords: 'No matching records.' },
        drawCallback: function () {
            const pageRows = this.api().rows({ page: 'current' })[0].map(i => ROWS[i]).filter(Boolean);
            renderCards(pageRows);
        }
    });

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
    $('#f_status, #f_type, #f_employee').on('change', loadData);
    $('#f_reset').on('click', function () {
        $('#f_status, #f_type').val('');
        $('#f_employee').val(null).trigger('change.select2');
        loadData();
    });

    $('#newContractModal').appendTo('body');
    $('#newContractModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#newContractModal'), width: '100%' });
        });
    });
    $('#newContractModal').on('hidden.bs.modal', function () {
        $('#newContractForm')[0].reset();
        $('#new-contract-message').html('');
        $('#nc_employee_id').val(null).trigger('change');
    });

    $('#newContractForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.ajax({
            url: '<?= buildUrl('api/add_contract.php') ?>', type: 'POST',
            data: new FormData(this), contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('newContractModal')).hide();
                    Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 1800, showConfirmButton: false }).then(loadData);
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not save contract.' });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView();
    $(window).on('resize', applyView);

    loadData();
});

function prepNewContractModal() {
    const sel = $('#nc_employee_id');
    if (!sel.hasClass('select2-hidden-accessible')) empSelect2(sel, $('#newContractModal'));
}

function renewContract(id) {
    const r = ROWS.find(x => Number(x.contract_id) === id);
    if (!r) return;
    new bootstrap.Modal(document.getElementById('newContractModal')).show();
    setTimeout(function () {
        prepNewContractModal();
        const opt = new Option(r.first_name + ' ' + r.last_name, r.employee_id, true, true);
        $('#nc_employee_id').append(opt).trigger('change');
        $('#nc_contract_type').val(r.contract_type).trigger('change');
    }, 300);
}

function viewContract(id) {
    const r = ROWS.find(x => Number(x.contract_id) === id);
    if (!r) return;
    let html = `
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <div class="fs-5 fw-bold">${safeOutput(r.first_name + ' ' + r.last_name)}</div>
                <div>${statusBadge(r.status)} <span class="badge bg-light text-dark border">${safeOutput(r.contract_type)}</span></div>
            </div>
            <div class="text-end small text-muted">
                Start: <strong>${safeOutput(r.start_date)}</strong><br>
                ${r.end_date ? 'End: <strong>' + safeOutput(r.end_date) + '</strong>' : 'Open-ended'}
            </div>
        </div>
        <table class="table table-sm">
            ${r.probation_months ? `<tr><th style="width:35%">Probation</th><td>${r.probation_months} month(s)</td></tr>` : ''}
            ${r.basic_salary ? `<tr><th>Basic Salary</th><td>${Number(r.basic_salary).toLocaleString()}</td></tr>` : ''}
            ${r.terms ? `<tr><th>Terms</th><td>${safeOutput(r.terms)}</td></tr>` : ''}
            ${r.renewed_from_contract_id ? `<tr><th>Renews</th><td>Contract #${r.renewed_from_contract_id}</td></tr>` : ''}
            ${r.activated_at ? `<tr><th>Activated</th><td>${safeOutput((r.activated_at || '').substring(0, 10))}</td></tr>` : ''}
        </table>`;
    $('#viewContractBody').html(html);
    new bootstrap.Modal(document.getElementById('viewContractModal')).show();
}

function doContractAction(id, action) {
    const labels = { activate: 'Activate', terminate: 'Terminate' };
    const opts = {
        title: labels[action] + '?',
        icon: action === 'activate' ? 'question' : 'warning',
        showCancelButton: true,
        confirmButtonColor: action === 'terminate' ? '#dc3545' : '#0d6efd',
        confirmButtonText: 'Yes, ' + labels[action]
    };
    if (action === 'activate') opts.text = 'This becomes the employee\'s active contract. Any existing active contract is marked renewed.';
    if (action === 'terminate') opts.text = 'This ends the employee\'s active contract.';
    Swal.fire(opts).then(res => {
        if (!res.isConfirmed) return;
        $.post('<?= buildUrl('api/change_contract_status.php') ?>',
            { contract_id: id, action: action, _csrf: CSRF },
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

// Mobile card renderer
function renderCards(rows) {
    if (!rows.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No contracts recorded yet.</div>'); return; }
    let html = '';
    rows.forEach(r => {
        let btns = `<button class="btn btn-sm btn-outline-primary" onclick="viewContract(${r.contract_id})" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-eye"></i></button>`;
        if (r.status === 'draft' && CAN_APPROVE) {
            btns += `<button class="btn btn-sm btn-outline-primary" onclick="doContractAction(${r.contract_id}, 'activate')" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-check2-all"></i></button>`;
        }
        if (r.status === 'active' && CAN_APPROVE) {
            btns += `<button class="btn btn-sm btn-outline-danger" onclick="doContractAction(${r.contract_id}, 'terminate')" style="flex:1;padding:3px 4px;font-size:0.72rem"><i class="bi bi-x-octagon"></i></button>`;
        }
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-bold">${safeOutput(r.first_name + ' ' + r.last_name)}</div>
                        ${statusBadge(r.status)}
                    </div>
                    <div class="mt-1 small text-muted">${safeOutput(r.contract_type)} &middot; ${safeOutput(r.start_date)}</div>
                    <div class="small mt-1">${expiryChip(r)}</div>
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
