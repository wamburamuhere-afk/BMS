<?php
// File: app/bms/purchase/debit_notes/debit_notes.php
// scope-audit: skip — debit_notes has no direct project_id; scope is enforced via
// the linked purchase_order (po) join below, like the purchase return list.
require_once __DIR__ . '/../../../../roots.php';

autoEnforcePermission('debit_notes');

includeHeader();

global $pdo;

$can_create = canCreate('debit_notes');
$can_edit   = canEdit('debit_notes');
$can_delete = canDelete('debit_notes');

// The list itself now comes from api/purchase/get_debit_notes.php (the query
// that used to live here), so the supplier-details Debit Notes tab can show the
// same list. Stat cards and the supplier filter are filled from that response.
?>

<style>
    .dn-stat-card { background:#e7f0ff; border:1px solid #b6ccfe !important; border-radius:12px; }
    .dn-stat-card .stat-num { font-size:1.4rem; font-weight:700; }
    .badge-status { min-width:92px; display:inline-block; padding:.4em .6em; border-radius:50rem; font-size:.72rem; font-weight:600; }
    #dnCardView .dn-card { border:1px solid #e6ecf5; border-radius:12px; }
    @media (max-width:767px){ #dnTableWrap { display:none; } #dnCardView { display:flex; } }
    @media (min-width:768px){ #dnCardView { display:none; } }
</style>

<div class="container-fluid mt-4" style="background:#fff;">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="<?= getUrl('dashboard') ?>" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Debit Notes</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Debit Notes</h4>
            <p class="text-muted small mb-0">Supplier debit notes — issue, approve and record the refund received</p>
        </div>
        <?php if ($can_create): ?>
        <a href="<?= getUrl('debit_note_create') ?>" class="btn btn-primary shadow-sm px-4">
            <i class="bi bi-plus-circle me-1"></i> New Debit Note
        </a>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3"><div class="card dn-stat-card text-center p-3 h-100"><div class="stat-num text-primary" id="dnStatTotal">0</div><div class="small text-muted">Total Notes</div></div></div>
        <div class="col-6 col-md-3"><div class="card dn-stat-card text-center p-3 h-100"><div class="stat-num text-secondary" id="dnStatPending">0</div><div class="small text-muted">Pending</div></div></div>
        <div class="col-6 col-md-3"><div class="card dn-stat-card text-center p-3 h-100"><div class="stat-num text-primary" id="dnStatApproved">0</div><div class="small text-muted">Approved</div></div></div>
        <div class="col-6 col-md-3"><div class="card dn-stat-card text-center p-3 h-100"><div class="stat-num" style="color:#052c65" id="dnStatValue">0</div><div class="small text-muted">TZS Value (<span id="dnStatPaid">0</span> settled)</div></div></div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted">Supplier</label>
                    <!-- Options filled from the API response (see onSuppliers below) -->
                    <select id="filterSupplier" class="form-select"><option value="">All Suppliers</option></select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Status</label>
                    <select id="filterStatus" class="form-select"><option value="">All Statuses</option>
                        <option value="pending">Pending</option><option value="reviewed">Reviewed</option>
                        <option value="approved">Approved</option><option value="paid">Paid</option>
                        <option value="rejected">Rejected</option><option value="cancelled">Cancelled</option>
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

    <div class="card border-0 shadow-sm" id="dnTableWrap">
        <?php
        // Shared Debit Notes table — same code path as the Debit Notes tab in
        // supplier_details.
        $tbl = [
            'id'           => 'dnTable',
            'card'         => '#dnCardView',
            'page_length'  => 25,
            'on_stats'     => 'function (s) {
                $("#dnStatTotal").text(Number(s.total || 0).toLocaleString());
                $("#dnStatPending").text(Number(s.pending || 0).toLocaleString());
                $("#dnStatApproved").text(Number(s.approved || 0).toLocaleString());
                $("#dnStatPaid").text(Number(s.paid || 0).toLocaleString());
                $("#dnStatValue").text(Number(s.value || 0).toLocaleString(undefined, {maximumFractionDigits: 0}));
            }',
            'on_suppliers' => 'function (list) {
                var $f = $("#filterSupplier");
                if ($f.data("filled")) return;
                list.forEach(function (s) { $f.append(new Option(s.name, s.name)); });
                $f.data("filled", true);
            }',
        ];
        include ROOT_DIR . '/includes/tables/debit_notes_table.php';
        ?>
    </div>

    <div id="dnCardView" class="row g-2"></div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
// Row rendering, the action dropdown, all four print templates and Delete live in
// assets/js/tables/bms-debit-notes-table.js, wired up by
// includes/tables/debit_notes_table.php — the same code the Debit Notes tab in
// supplier_details uses. Only this page's own filter chrome is here.
$(document).ready(function () {
    if (typeof logReportAction === 'function') logReportAction('Viewed Debit Notes List', 'User viewed the debit notes list');

    $('#filterSupplier').select2({ theme: 'bootstrap-5', placeholder: 'All Suppliers', allowClear: true, width: '100%' });
    $('#filterStatus').select2({ theme: 'bootstrap-5', placeholder: 'All Statuses', allowClear: true, width: '100%' });

    // Column indexes come from the table instance, so these filters keep working
    // whatever set of columns the host chose to show.
    function col(key) { return BMSDebitNotesTable.colIndex('dnTable', key); }
    function dt()      { return BMSDebitNotesTable.dt('dnTable'); }

    $('#filterSupplier').on('change', function () {
        const v = this.value ? '^' + $.fn.dataTable.util.escapeRegex(this.value) + '$' : '';
        dt().column(col('supplier')).search(v, true, false).draw();
    });
    $('#filterStatus').on('change', function () {
        const v = this.value ? '^' + this.value + '$' : '';
        dt().column(col('status')).search(v, true, false).draw();
    });
    $('#filterSearch').on('keyup', function () { dt().search(this.value).draw(); });
    $('#btnResetFilters').on('click', function () {
        $('#filterSupplier').val('').trigger('change.select2');
        $('#filterStatus').val('').trigger('change.select2');
        $('#filterSearch').val('');
        dt().column(col('supplier')).search('').column(col('status')).search('').search('').draw();
    });
});
</script>

<?php includeFooter(); ?>
