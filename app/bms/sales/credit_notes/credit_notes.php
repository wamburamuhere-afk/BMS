<?php
// File: app/bms/sales/credit_notes/credit_notes.php
// scope-audit: skip — credit_notes has no direct project_id; scope is enforced via
// the linked sales_order (so) join below, exactly like sales_returns.php.
require_once __DIR__ . '/../../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('credit_notes');

includeHeader();

global $pdo;

$can_create = canCreate('credit_notes');
$can_edit   = canEdit('credit_notes');
$can_delete = canDelete('credit_notes');

// ── Fetch credit notes (scoped via the linked sales order) ──────────────────
$rows = [];
try {
    $query = "
        SELECT
            cn.credit_note_id,
            cn.credit_note_number,
            cn.credit_date,
            cn.grand_total,
            cn.status,
            cn.sales_return_id,
            cn.invoice_id,
            c.customer_name,
            c.company_name,
            sr.return_number
        FROM credit_notes cn
        LEFT JOIN customers c       ON cn.customer_id     = c.customer_id
        LEFT JOIN sales_returns sr  ON cn.sales_return_id = sr.sales_return_id
        LEFT JOIN sales_orders so   ON sr.sales_order_id  = so.sales_order_id
        WHERE cn.status != 'deleted'
    ";
    $query .= scopeFilterSqlNullable('project', 'so');
    $query .= " ORDER BY cn.credit_date DESC, cn.credit_note_id DESC";
    $stmt = $pdo->query($query);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}

// ── Stats ────────────────────────────────────────────────────────────────────
$stat_total    = count($rows);
$stat_pending  = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
$stat_approved = count(array_filter($rows, fn($r) => $r['status'] === 'approved'));
$stat_paid     = count(array_filter($rows, fn($r) => $r['status'] === 'paid'));
$stat_value    = array_sum(array_map(fn($r) => (float)$r['grand_total'], $rows));

// ── Build the JS data array (origin label resolved here) ─────────────────────
$jsRows = array_map(function ($r) {
    if (!empty($r['sales_return_id'])) {
        $origin = 'Return ' . ($r['return_number'] ?: ('#' . $r['sales_return_id']));
    } elseif (!empty($r['invoice_id'])) {
        $origin = 'Invoice #' . $r['invoice_id'];
    } else {
        $origin = 'Standalone';
    }
    return [
        'id'       => (int)$r['credit_note_id'],
        'number'   => $r['credit_note_number'],
        'date'     => $r['credit_date'] ? date('d M Y', strtotime($r['credit_date'])) : '—',
        'customer' => $r['customer_name'] ?: 'Walk-in Customer',
        'company'  => $r['company_name'] ?: '',
        'origin'   => $origin,
        'amount'   => (float)$r['grand_total'],
        'status'   => $r['status'],
    ];
}, $rows);

// Customer list for the searchable filter (distinct, from the visible rows)
$filter_customers = [];
foreach ($jsRows as $jr) { $filter_customers[$jr['customer']] = true; }
$filter_customers = array_keys($filter_customers);
sort($filter_customers);
?>

<style>
    .cn-stat-card { background:#e7f0ff; border:1px solid #b6ccfe !important; border-radius:12px; }
    .cn-stat-card .stat-num { font-size:1.4rem; font-weight:700; }
    .badge-status { min-width:92px; display:inline-block; padding:.4em .6em; border-radius:50rem; font-size:.72rem; font-weight:600; }
    /* Mobile card view */
    #cnCardView .cn-card { border:1px solid #e6ecf5; border-radius:12px; }
    @media (max-width:767px){
        #cnTableWrap { display:none; }
        #cnCardView  { display:flex; }
    }
    @media (min-width:768px){
        #cnCardView  { display:none; }
    }
</style>

<div class="container-fluid mt-4" style="background:#fff;">
    <!-- Header -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Credit Notes</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-receipt text-primary me-2"></i>Credit Notes</h4>
            <p class="text-muted small mb-0">Customer credit notes — issue, approve and refund</p>
        </div>
        <?php if ($can_create): ?>
        <a href="<?= getUrl('credit_note_create') ?>" class="btn btn-primary shadow-sm px-4">
            <i class="bi bi-plus-circle me-1"></i> New Credit Note
        </a>
        <?php endif; ?>
    </div>

    <!-- Statistics cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card cn-stat-card text-center p-3 h-100">
                <div class="stat-num text-primary"><?= number_format($stat_total) ?></div>
                <div class="small text-muted">Total Notes</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cn-stat-card text-center p-3 h-100">
                <div class="stat-num text-secondary"><?= number_format($stat_pending) ?></div>
                <div class="small text-muted">Pending</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cn-stat-card text-center p-3 h-100">
                <div class="stat-num text-primary"><?= number_format($stat_approved) ?></div>
                <div class="small text-muted">Approved</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card cn-stat-card text-center p-3 h-100">
                <div class="stat-num" style="color:#052c65"><?= number_format($stat_value, 0) ?></div>
                <div class="small text-muted">TZS Value (<?= number_format($stat_paid) ?> paid)</div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Customer</label>
                    <select id="filterCustomer" class="form-select">
                        <option value="">All Customers</option>
                        <?php foreach ($filter_customers as $cust): ?>
                            <option value="<?= htmlspecialchars($cust, ENT_QUOTES) ?>"><?= safe_output($cust) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Status</label>
                    <select id="filterStatus" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="reviewed">Reviewed</option>
                        <option value="approved">Approved</option>
                        <option value="paid">Paid</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Search</label>
                    <input type="text" id="filterSearch" class="form-control" placeholder="Number / origin...">
                </div>
                <div class="col-md-2">
                    <button id="btnResetFilters" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-clockwise me-1"></i> Reset</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Desktop table -->
    <div class="card border-0 shadow-sm" id="cnTableWrap">
        <div class="table-responsive">
            <table id="cnTable" class="table table-hover align-middle w-100 mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Credit Note #</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Origin</th>
                        <th class="text-end">Amount (TZS)</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- Mobile card view -->
    <div id="cnCardView" class="row g-2"></div>
</div>

<!-- DataTables JS (canonical BMS set) -->
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<script>
const CN_DATA  = <?= json_encode(array_values($jsRows), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const CN_PERMS = { edit: <?= json_encode($can_edit) ?>, del: <?= json_encode($can_delete) ?> };
const CN_VIEW  = 'credit_note_view';
const CN_EDIT  = 'credit_note_edit';
const CN_PRINT = 'print_credit_note';

// Blue-scale status badge (ui-constants §UI-1)
const CN_BADGE = {
    pending:   ['#e9ecef', '#495057'],
    reviewed:  ['#bfdbfe', '#1e3a8a'],
    approved:  ['#0d6efd', '#fff'],
    paid:      ['#052c65', '#fff'],
    rejected:  ['#dc3545', '#fff'],
    cancelled: ['#6c757d', '#fff'],
};
function cnBadge(status) {
    const c = CN_BADGE[status] || ['#e9ecef', '#495057'];
    const label = status.charAt(0).toUpperCase() + status.slice(1);
    return `<span class="badge-status" style="background:${c[0]};color:${c[1]};">${label}</span>`;
}
function cnMoney(v) { return Number(v || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

function cnActions(row) {
    let items = `<li><a class="dropdown-item py-2 rounded" href="${CN_VIEW}?id=${row.id}"><i class="bi bi-eye text-primary me-2"></i> View</a></li>`;
    items += `<li><a class="dropdown-item py-2 rounded" href="${CN_PRINT}?id=${row.id}" target="_blank"><i class="bi bi-printer text-primary me-2"></i> Print</a></li>`;
    if (CN_PERMS.edit && row.status === 'pending') {
        items += `<li><a class="dropdown-item py-2 rounded" href="${CN_EDIT}?id=${row.id}"><i class="bi bi-pencil text-primary me-2"></i> Edit</a></li>`;
    }
    if (CN_PERMS.del && row.status !== 'paid') {
        items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" onclick="cnDelete(${row.id})"><i class="bi bi-trash text-danger me-2"></i> Delete</button></li>`;
    }
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul>
    </div>`;
}

let cnTable;
$(document).ready(function () {
    logReportAction && logReportAction('Viewed Credit Notes List', 'User viewed the credit notes list');

    cnTable = $('#cnTable').DataTable({
        data: CN_DATA,
        responsive: false,
        scrollX: true,
        pageLength: 25,
        order: [[1, 'desc']],
        dom: 'rtip',
        language: { emptyTable: 'No credit notes found.', zeroRecords: 'No matching credit notes.' },
        columns: [
            { data: null, orderable: false, render: (d, t, r, meta) => meta.row + 1 },
            { data: 'number' },
            { data: 'date' },
            { data: 'customer', render: (d, t, r) => t === 'display'
                ? `<div class="fw-semibold">${$('<div>').text(r.customer).html()}</div>` + (r.company ? `<small class="text-muted">${$('<div>').text(r.company).html()}</small>` : '')
                : r.customer },
            { data: 'origin' },
            { data: 'amount', className: 'text-end', render: (d, t) => t === 'display' ? cnMoney(d) : d },
            { data: 'status', className: 'text-center', render: (d, t) => t === 'display' ? cnBadge(d) : d },
            { data: null, orderable: false, searchable: false, className: 'text-end', render: (d, t, r) => cnActions(r) },
        ],
        drawCallback: function () {
            cnRenderCards(this.api().rows({ page: 'current', search: 'applied' }).data().toArray());
        }
    });

    // Searchable Select2 filters (ui-constants §UI-3)
    $('#filterCustomer').select2({ theme: 'bootstrap-5', placeholder: 'All Customers', allowClear: true, width: '100%' });
    $('#filterStatus').select2({ theme: 'bootstrap-5', placeholder: 'All Statuses', allowClear: true, width: '100%' });

    $('#filterCustomer').on('change', function () {
        const v = this.value ? '^' + $.fn.dataTable.util.escapeRegex(this.value) + '$' : '';
        cnTable.column(3).search(v, true, false).draw();
    });
    $('#filterStatus').on('change', function () {
        const v = this.value ? '^' + this.value + '$' : '';
        cnTable.column(6).search(v, true, false).draw();
    });
    $('#filterSearch').on('keyup', function () { cnTable.search(this.value).draw(); });

    $('#btnResetFilters').on('click', function () {
        $('#filterCustomer').val('').trigger('change.select2');
        $('#filterStatus').val('').trigger('change.select2');
        $('#filterSearch').val('');
        cnTable.column(3).search('').column(6).search('').search('').draw();
    });
});

function cnRenderCards(rows) {
    const $cv = $('#cnCardView');
    if (!rows.length) { $cv.html('<div class="col-12 text-center py-5 text-muted">No credit notes found.</div>'); return; }
    let html = '';
    rows.forEach(r => {
        const esc = s => $('<div>').text(s == null ? '' : s).html();
        html += `
        <div class="col-12">
            <div class="card cn-card shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold text-primary">${esc(r.number)}</div>
                            <small class="text-muted">${esc(r.date)} · ${esc(r.origin)}</small>
                        </div>
                        ${cnBadge(r.status)}
                    </div>
                    <div class="mt-2 fw-semibold">${esc(r.customer)}</div>
                    <div class="fw-bold">TZS ${cnMoney(r.amount)}</div>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">
                        <a class="btn btn-sm btn-outline-primary" style="flex:1" href="${CN_VIEW}?id=${r.id}"><i class="bi bi-eye"></i></a>
                        <a class="btn btn-sm btn-outline-primary" style="flex:1" href="${CN_PRINT}?id=${r.id}" target="_blank"><i class="bi bi-printer"></i></a>
                        ${CN_PERMS.edit && r.status === 'pending' ? `<a class="btn btn-sm btn-outline-primary" style="flex:1" href="${CN_EDIT}?id=${r.id}"><i class="bi bi-pencil"></i></a>` : ''}
                        ${CN_PERMS.del && r.status !== 'paid' ? `<button class="btn btn-sm btn-outline-danger" style="flex:1" onclick="cnDelete(${r.id})"><i class="bi bi-trash"></i></button>` : ''}
                    </div>
                </div>
            </div>
        </div>`;
    });
    $cv.html(html);
}

function cnDelete(id) {
    Swal.fire({
        title: 'Delete Credit Note?',
        text: 'This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        confirmButtonText: 'Yes, Delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        $.ajax({
            url: '<?= buildUrl('api/sales/delete_credit_note.php') ?>',
            type: 'POST',
            data: { credit_note_id: id, _csrf: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '') },
            dataType: 'json',
            success: function (res) {
                if (res.success) {
                    const idx = cnTable.rows().indexes().toArray().find(i => cnTable.row(i).data().id === id);
                    if (idx !== undefined) cnTable.row(idx).remove().draw(false);
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: res.message, timer: 1800, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
        });
    });
}
</script>

<?php includeFooter(); ?>
