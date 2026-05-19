<?php
$page_title = 'Received Invoices';
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('received_invoices');
includeHeader();

global $pdo;
$can_create = canCreate('received_invoices');
$can_edit   = canEdit('received_invoices');
$can_delete = canDelete('received_invoices');
?>
<style>
.stat-card { border-radius: 12px; transition: transform .2s; }
.stat-card:hover { transform: translateY(-3px); }
.badge-supplier { background: #cfe2ff; color: #084298; }
.badge-sc       { background: #d1e7dd; color: #0f5132; }
.badge-draft    { background: #e2e3e5; color: #41464b; }
.badge-submitted{ background: #fff3cd; color: #664d03; }
.badge-approved { background: #d1e7dd; color: #0f5132; }
.badge-paid     { background: #0f5132; color: #fff; }
@media (max-width: 767px) {
    .page-sticky-header { position: sticky; top: 0; z-index: 1020; background: #fff; }
    #tableView { display: none !important; }
    #cardView  { display: flex !important; }
}
@media (min-width: 768px) {
    #cardView { display: none !important; }
}
</style>

<div class="container-fluid mt-3">
    <!-- Sticky header (mobile) -->
    <div class="page-sticky-header py-2 mb-3 d-md-block">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= getUrl('invoices') ?>">Invoices</a></li>
                <li class="breadcrumb-item active">Received Invoices</li>
            </ol>
        </nav>
    </div>

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 fw-bold"><i class="bi bi-inbox-fill text-primary me-2"></i>Received Invoices</h4>
        <?php if ($can_create): ?>
        <button class="btn btn-primary" onclick="openAddModal()">
            <i class="bi bi-plus-circle me-1"></i> Record Invoice
        </button>
        <?php endif; ?>
    </div>

    <!-- Statistics cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm stat-card text-center p-3">
                <div class="fs-4 fw-bold text-primary" id="stat-total">0</div>
                <div class="small text-muted">Total Invoices</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm stat-card text-center p-3">
                <div class="fs-4 fw-bold text-success" id="stat-amount">0</div>
                <div class="small text-muted">Total Amount (TZS)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm stat-card text-center p-3">
                <div class="fs-4 fw-bold text-info" id="stat-suppliers">0</div>
                <div class="small text-muted">From Suppliers</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm stat-card text-center p-3">
                <div class="fs-4 fw-bold text-warning" id="stat-sc">0</div>
                <div class="small text-muted">From Sub-Contractors</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-uppercase text-muted mb-1">Type</label>
                    <select id="f-type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="supplier">Supplier</option>
                        <option value="sub_contractor">Sub-Contractor</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-uppercase text-muted mb-1">Status</label>
                    <select id="f-status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="submitted">Submitted</option>
                        <option value="approved">Approved</option>
                        <option value="paid">Paid</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-uppercase text-muted mb-1">From Date</label>
                    <input type="date" id="f-from" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-uppercase text-muted mb-1">To Date</label>
                    <input type="date" id="f-to" class="form-control form-control-sm">
                </div>
                <div class="col-12 col-md-4 d-flex gap-2">
                    <button class="btn btn-sm btn-primary flex-fill" onclick="loadInvoices()">
                        <i class="bi bi-filter me-1"></i> Filter
                    </button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill" onclick="clearFilters()">
                        <i class="bi bi-arrow-counterclockwise"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="list-message" class="mb-2"></div>

    <!-- Desktop table -->
    <div id="tableView">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="invoiceTable" class="table table-hover align-middle mb-0 w-100">
                        <thead class="table-dark small text-uppercase">
                            <tr>
                                <th class="ps-3">#</th>
                                <th>Invoice Ref</th>
                                <th>Type</th>
                                <th>From</th>
                                <th>Date Raised</th>
                                <th>Date Recorded</th>
                                <th>PO / Project</th>
                                <th class="text-end">Amount (TZS)</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile cards -->
    <div id="cardView" class="row g-2"></div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white" id="modalHeader">
                <h5 class="modal-title" id="modalTitle"><i class="bi bi-inbox me-2"></i>Record Received Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="invoiceForm" autocomplete="off" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="id" id="f-id">
                    <div id="form-msg" class="mb-2"></div>

                    <!-- Type toggle -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">Invoice From <span class="text-danger">*</span></label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="invoice_type" id="type-supplier" value="supplier" checked>
                            <label class="btn btn-outline-primary" for="type-supplier">
                                <i class="bi bi-building me-1"></i> Supplier
                            </label>
                            <input type="radio" class="btn-check" name="invoice_type" id="type-sc" value="sub_contractor">
                            <label class="btn btn-outline-success" for="type-sc">
                                <i class="bi bi-people me-1"></i> Sub-Contractor
                            </label>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- Who -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold" id="who-label">Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" id="f-supplier" class="form-select select2-static" required></select>
                        </div>

                        <!-- Invoice Ref -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Invoice Reference No. <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="invoice_ref" id="f-ref" placeholder="e.g. INV-2026-001" required>
                        </div>

                        <!-- Date Raised -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date Raised <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date_raised" id="f-raised" required>
                        </div>

                        <!-- Date Recorded -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Date Recorded <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="date_recorded" id="f-recorded" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- Amount -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount (TZS) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="amount" id="f-amount" min="1" step="0.01" placeholder="0.00" required>
                        </div>

                        <!-- Attachment -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Attachment <small class="text-muted fw-normal">(PDF / Image, max 5 MB)</small></label>
                            <input type="file" class="form-control" name="attachment" id="f-attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <small id="current-attachment" class="text-muted d-none"></small>
                        </div>

                        <!-- Supplier: PO Reference -->
                        <div class="col-12" id="supplier-fields">
                            <label class="form-label fw-bold">PO Reference</label>
                            <select name="po_id" id="f-po" class="form-select select2-static">
                                <option value="">— Select PO (optional) —</option>
                            </select>
                        </div>

                        <!-- SC fields -->
                        <div class="col-md-6 d-none" id="sc-project-wrap">
                            <label class="form-label fw-bold">Project <span class="text-danger">*</span></label>
                            <select name="project_id" id="f-project" class="form-select select2-static">
                                <option value="">— Select Project —</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-none" id="sc-basis-wrap">
                            <label class="form-label fw-bold">Invoice Basis <span class="text-danger">*</span></label>
                            <select name="sc_invoice_basis" id="f-basis" class="form-select select2-static">
                                <option value="">— Select —</option>
                                <option value="IPC">IPC</option>
                                <option value="Milestone">Milestone</option>
                                <option value="Scope">Scope</option>
                                <option value="Final">Final</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-none" id="sc-ref-wrap">
                            <label class="form-label fw-bold">Basis Ref.</label>
                            <input type="text" class="form-control" name="sc_basis_ref" id="f-basisref" placeholder="e.g. IPC-03">
                        </div>

                        <!-- Notes -->
                        <div class="col-12">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea class="form-control" name="notes" id="f-notes" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="bi bi-check-circle me-1"></i> Save Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const RI_CAN_EDIT   = <?= json_encode($can_edit) ?>;
const RI_CAN_DELETE = <?= json_encode($can_delete) ?>;
const RI_CAN_CREATE = <?= json_encode($can_create) ?>;
const RI_API        = '<?= buildUrl('api/received_invoices.php') ?>';

let riTable = null;
let allRows = [];

$(document).ready(function () {
    initSelect2InModal();
    setupTypeToggle();
    loadInvoices();
    initDataTable();

    $('#f-supplier').on('change', function () {
        const type = $('[name=invoice_type]:checked').val();
        if (type === 'supplier') loadPOs($(this).val());
        else loadProjects($(this).val());
    });

    $('#invoiceForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $('#saveBtn');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

        $.ajax({
            url: RI_API + '?action=' + ($('#f-id').val() ? 'update' : 'create'),
            type: 'POST',
            data: new FormData(this),
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false })
                        .then(() => { bootstrap.Modal.getInstance($('#invoiceModal')[0]).hide(); loadInvoices(); });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('#invoiceModal').on('hidden.bs.modal', function () {
        $('#invoiceForm')[0].reset();
        $('#f-id').val('');
        $('#form-msg').html('');
        $('#current-attachment').addClass('d-none').text('');
        setTypeMode('supplier');
        destroyAndResetSelects();
        initSelect2InModal();
    });
});

function initDataTable() {
    riTable = $('#invoiceTable').DataTable({
        data: [],
        responsive: false,
        dom: '<"row mb-2"<"col-md-6"l><"col-md-6"f>>rtip',
        pageLength: 25,
        order: [[4, 'desc']],
        columns: [
            { data: null, orderable: false, className: 'ps-3 text-muted small',
              render: (d, t, r, m) => m.row + m.settings._iDisplayStart + 1 },
            { data: 'invoice_ref', render: v => `<span class="fw-bold">${safeOutput(v)}</span>` },
            { data: 'invoice_type', render: v => v === 'supplier'
                ? '<span class="badge badge-supplier"><i class="bi bi-building me-1"></i>Supplier</span>'
                : '<span class="badge badge-sc"><i class="bi bi-people me-1"></i>Sub-Contractor</span>' },
            { data: 'party_name', render: v => safeOutput(v) },
            { data: 'date_raised' },
            { data: 'date_recorded' },
            { data: null, render: (d, t, row) => row.invoice_type === 'supplier'
                ? (row.po_number ? `<span class="badge bg-light text-dark border">${safeOutput(row.po_number)}</span>` : '—')
                : (row.project_name ? `<small>${safeOutput(row.project_name)}${row.sc_invoice_basis ? ' / ' + safeOutput(row.sc_invoice_basis) : ''}</small>` : '—') },
            { data: 'amount', className: 'text-end fw-bold',
              render: v => formatCurrency(v) },
            { data: 'status', render: v => statusBadge(v) },
            { data: null, orderable: false, className: 'text-end pe-3',
              render: (d, t, row) => actionButtons(row) }
        ],
        drawCallback: function () {
            renderCards(this.api().rows({ page: 'current' }).data().toArray());
        }
    });
}

function loadInvoices() {
    const params = {
        action: 'list',
        type:   $('#f-type').val(),
        status: $('#f-status').val()
    };
    $.getJSON(RI_API, params, function (res) {
        if (!res.success) { Swal.fire('Error', res.message, 'error'); return; }
        allRows = res.data;
        // Client-side date filter
        const from = $('#f-from').val();
        const to   = $('#f-to').val();
        let rows = allRows;
        if (from) rows = rows.filter(r => r.date_raised >= from);
        if (to)   rows = rows.filter(r => r.date_raised <= to);
        riTable.clear().rows.add(rows).draw();
        updateStats(rows);
    });
}

function updateStats(rows) {
    const total  = rows.length;
    const amount = rows.reduce((s, r) => s + parseFloat(r.amount || 0), 0);
    const sups   = rows.filter(r => r.invoice_type === 'supplier').length;
    const scs    = rows.filter(r => r.invoice_type === 'sub_contractor').length;
    $('#stat-total').text(total);
    $('#stat-amount').text(formatCurrency(amount));
    $('#stat-suppliers').text(sups);
    $('#stat-sc').text(scs);
}

function clearFilters() {
    $('#f-type, #f-status').val('');
    $('#f-from, #f-to').val('');
    loadInvoices();
}

function openAddModal() {
    $('#modalHeader').removeClass('bg-warning').addClass('bg-primary');
    $('#modalTitle').html('<i class="bi bi-inbox me-2"></i>Record Received Invoice');
    $('#saveBtn').html('<i class="bi bi-check-circle me-1"></i> Save Invoice').removeClass('btn-warning').addClass('btn-primary');
    new bootstrap.Modal(document.getElementById('invoiceModal')).show();
}

function editRow(id) {
    $.getJSON(RI_API, { action: 'get', id: id }, function (res) {
        if (!res.success) { Swal.fire('Error', 'Could not load invoice data.', 'error'); return; }
        const d = res.data;
        $('#f-id').val(d.id);
        $('#f-ref').val(d.invoice_ref);
        $('#f-raised').val(d.date_raised);
        $('#f-recorded').val(d.date_recorded);
        $('#f-amount').val(d.amount);
        $('#f-notes').val(d.notes);
        if (d.attachment) {
            $('#current-attachment').removeClass('d-none').text('Current: ' + d.attachment.split('/').pop());
        }
        setTypeMode(d.invoice_type);
        $('[name=invoice_type][value=' + d.invoice_type + ']').prop('checked', true);
        loadPartyList(d.invoice_type, function () {
            $('#f-supplier').val(d.supplier_id).trigger('change.select2');
            if (d.invoice_type === 'supplier') {
                loadPOs(d.supplier_id, function () { $('#f-po').val(d.po_id).trigger('change.select2'); });
            } else {
                loadProjects(d.supplier_id, function () {
                    $('#f-project').val(d.project_id).trigger('change.select2');
                    $('#f-basis').val(d.sc_invoice_basis).trigger('change.select2');
                    $('#f-basisref').val(d.sc_basis_ref);
                });
            }
        });
        $('#modalHeader').removeClass('bg-primary').addClass('bg-warning');
        $('#modalTitle').html('<i class="bi bi-pencil me-2"></i>Edit Received Invoice');
        $('#saveBtn').html('<i class="bi bi-check-circle me-1"></i> Update Invoice').removeClass('btn-primary').addClass('btn-warning');
        new bootstrap.Modal(document.getElementById('invoiceModal')).show();
    });
}

function confirmDelete(id, ref) {
    Swal.fire({
        title: 'Delete Invoice?',
        text: 'Invoice "' + ref + '" will be deleted. This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(RI_API + '?action=delete', { id: id, _csrf: CSRF_TOKEN }, function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1800, showConfirmButton: false })
                    .then(() => loadInvoices());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }, 'json');
    });
}

function viewAttachment(path) {
    if (!path) { Swal.fire('No Attachment', 'This invoice has no attachment.', 'info'); return; }
    window.open('<?= getUrl('') ?>/' + path, '_blank');
}

// ── Type toggle ────────────────────────────────────────────────────────────

function setupTypeToggle() {
    $('[name=invoice_type]').on('change', function () {
        const type = $(this).val();
        setTypeMode(type);
        destroyAndResetSelects();
        loadPartyList(type);
        initSelect2InModal();
    });
}

function setTypeMode(type) {
    if (type === 'supplier') {
        $('#who-label').html('Supplier <span class="text-danger">*</span>');
        $('#supplier-fields').removeClass('d-none');
        $('#sc-project-wrap, #sc-basis-wrap, #sc-ref-wrap').addClass('d-none');
    } else {
        $('#who-label').html('Sub-Contractor <span class="text-danger">*</span>');
        $('#supplier-fields').addClass('d-none');
        $('#sc-project-wrap, #sc-basis-wrap, #sc-ref-wrap').removeClass('d-none');
    }
}

function loadPartyList(type, cb) {
    const action = type === 'supplier' ? 'get_suppliers' : 'get_sub_contractors';
    $.getJSON(RI_API, { action: action }, function (res) {
        const $sel = $('#f-supplier');
        $sel.empty().append('<option value="">— Select ' + (type === 'supplier' ? 'Supplier' : 'Sub-Contractor') + ' —</option>');
        (res.data || []).forEach(function (item) {
            $sel.append($('<option>').val(item.id).text(item.text));
        });
        if (cb) cb();
    });
}

function loadPOs(supplierId, cb) {
    if (!supplierId) return;
    $.getJSON(RI_API, { action: 'get_pos', supplier_id: supplierId }, function (res) {
        const $sel = $('#f-po');
        $sel.empty().append('<option value="">— Select PO (optional) —</option>');
        (res.data || []).forEach(function (item) {
            $sel.append($('<option>').val(item.id).text(item.text));
        });
        if (cb) cb();
    });
}

function loadProjects(supplierId, cb) {
    if (!supplierId) return;
    $.getJSON(RI_API, { action: 'get_projects', supplier_id: supplierId }, function (res) {
        const $sel = $('#f-project');
        $sel.empty().append('<option value="">— Select Project —</option>');
        (res.data || []).forEach(function (item) {
            $sel.append($('<option>').val(item.id).text(item.text));
        });
        if (cb) cb();
    });
}

// ── Select2 helpers ────────────────────────────────────────────────────────

function initSelect2InModal() {
    const $modal = $('#invoiceModal');
    $modal.find('.select2-static').each(function () {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({ theme: 'bootstrap-5', dropdownParent: $modal, placeholder: 'Select...', allowClear: true, width: '100%' });
        }
    });
}

function destroyAndResetSelects() {
    ['#f-supplier', '#f-po', '#f-project', '#f-basis'].forEach(function (id) {
        const $el = $(id);
        if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
        $el.empty();
    });
}

// ── Rendering helpers ──────────────────────────────────────────────────────

function actionButtons(row) {
    let btns = `<div class="d-flex justify-content-end gap-1">`;
    btns += `<button class="btn btn-sm btn-outline-secondary" onclick="viewAttachment('${row.attachment || ''}')" title="View/Download"><i class="bi bi-paperclip"></i></button>`;
    if (RI_CAN_EDIT)   btns += `<button class="btn btn-sm btn-outline-primary"  onclick="editRow(${row.id})" title="Edit"><i class="bi bi-pencil"></i></button>`;
    if (RI_CAN_DELETE) btns += `<button class="btn btn-sm btn-outline-danger"   onclick="confirmDelete(${row.id}, '${safeOutput(row.invoice_ref)}')" title="Delete"><i class="bi bi-trash"></i></button>`;
    btns += `</div>`;
    return btns;
}

function statusBadge(s) {
    const map = { draft: 'badge-draft', submitted: 'badge-submitted', approved: 'badge-approved', paid: 'badge-paid' };
    return `<span class="badge ${map[s] || 'bg-secondary'} text-uppercase">${s}</span>`;
}

function formatCurrency(v) {
    return new Intl.NumberFormat('en-TZ', { minimumFractionDigits: 2 }).format(v);
}

function renderCards(rows) {
    if (!rows.length) {
        $('#cardView').html('<div class="col-12 text-center py-5 text-muted"><i class="bi bi-inbox fs-1 d-block mb-2"></i>No received invoices found</div>');
        return;
    }
    let html = '';
    rows.forEach(function (row) {
        const typeLabel = row.invoice_type === 'supplier'
            ? '<span class="badge badge-supplier">Supplier</span>'
            : '<span class="badge badge-sc">Sub-Contractor</span>';
        const ref = row.po_number || row.project_name || '—';
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm mb-1">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <span class="fw-bold" style="font-size:0.85rem">${safeOutput(row.invoice_ref)}</span>
                        ${statusBadge(row.status)}
                    </div>
                    <div style="font-size:0.8rem" class="text-muted">${typeLabel} &nbsp; ${safeOutput(row.party_name)}</div>
                    <div style="font-size:0.8rem" class="text-muted">Raised: ${row.date_raised} &nbsp;|&nbsp; ${safeOutput(ref)}</div>
                    <div class="fw-bold text-primary" style="font-size:0.85rem">TZS ${formatCurrency(row.amount)}</div>
                </div>
                <div class="card-footer bg-white p-0 border-top">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                        <button onclick="viewAttachment('${row.attachment || ''}')" style="flex:1;padding:3px 4px;font-size:0.72rem" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip"></i></button>
                        ${RI_CAN_EDIT   ? `<button onclick="editRow(${row.id})"                                          style="flex:1;padding:3px 4px;font-size:0.72rem" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></button>` : ''}
                        ${RI_CAN_DELETE ? `<button onclick="confirmDelete(${row.id},'${safeOutput(row.invoice_ref)}')"  style="flex:1;padding:3px 4px;font-size:0.72rem" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>` : ''}
                    </div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
