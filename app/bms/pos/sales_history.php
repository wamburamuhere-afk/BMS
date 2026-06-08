<?php
// File: sales_history.php — POS Sales History (list, receipt, return, void)
// scope-audit: skip — reads via api/pos/get_sales.php which applies project scope
ob_start();

require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('pos');

$page_title = 'POS Sales History';
require_once 'header.php';

$can_create = canCreate('pos');   // create a return/refund
$can_delete = canDelete('pos');   // void a sale

// View-page activity log (keeps security-coverage baseline intact).
logActivity($pdo, $_SESSION['user_id'] ?? 0, 'Viewed POS Sales History');

$today_start = date('Y-m-01');
$today_end   = date('Y-m-t');
?>
<div class="container-fluid mt-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0 text-primary"><i class="bi bi-receipt me-2"></i>POS Sales History</h4>
        <a href="<?= getUrl('pos') ?>" class="btn btn-primary"><i class="bi bi-bag-plus me-1"></i> Open POS</a>
    </div>

    <!-- Date filter -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" id="fFrom" class="form-control form-control-sm" value="<?= $today_start ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" id="fTo" class="form-control form-control-sm" value="<?= $today_end ?>">
                </div>
                <div class="col-12 col-md-3">
                    <button id="btnFilter" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel me-1"></i> Apply</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary" id="stat-net">0.00</div>
                <div class="small text-muted">Net Sales (excl. VAT)</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary" id="stat-count">0</div>
                <div class="small text-muted">Completed Sales</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary" id="stat-returns">0</div>
                <div class="small text-muted">Returns</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3" style="background:#e7f0ff;border:1px solid #b6ccfe;">
                <div class="fs-4 fw-bold text-primary" id="stat-voided">0</div>
                <div class="small text-muted">Voided</div>
            </div>
        </div>
    </div>

    <!-- Desktop table -->
    <div id="tableView">
        <table id="posSalesTable" class="table table-hover align-middle w-100">
            <thead class="table-dark">
                <tr>
                    <th>Receipt</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th class="text-end">Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <!-- Mobile card view -->
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Return modal -->
<?php if ($can_create): ?>
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-return-left me-1"></i> Process Return / Refund</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="returnForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="original_sale_id" id="ret_sale_id">
                    <div id="ret-message" class="mb-2"></div>
                    <div class="mb-2 small text-muted">Receipt <span class="fw-bold" id="ret_receipt">—</span> · <span id="ret_customer">—</span></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr><th>Product</th><th class="text-end">Sold</th><th class="text-end">Returnable</th><th style="width:120px">Return Qty</th></tr>
                            </thead>
                            <tbody id="ret_lines"></tbody>
                        </table>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Refund Method <span class="text-danger">*</span></label>
                            <select class="form-select select2-static" name="refund_method" id="ret_method" required>
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="mobile_money">Mobile Money</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Reason <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="reason" id="ret_reason" placeholder="e.g. defective, wrong item" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> Process Return</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const GET_SALES   = '<?= buildUrl('api/pos/get_sales.php') ?>';
const GET_ITEMS   = '<?= buildUrl('api/pos/get_sale_items.php') ?>';
const VOID_URL    = '<?= buildUrl('api/pos/void_sale.php') ?>';
const RETURN_URL  = '<?= buildUrl('api/pos/create_return.php') ?>';
const RECEIPT_URL = '<?= buildUrl('api/pos/print_receipt.php') ?>';
const CAN_CREATE  = <?= json_encode($can_create) ?>;
const CAN_DELETE  = <?= json_encode($can_delete) ?>;

const money = n => (parseFloat(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function statusBadge(s) {
    const map = {
        completed: ['#0d6efd', '#fff'], paid: ['#052c65', '#fff'],
        partially_refunded: ['#bfdbfe', '#1e3a8a'], refunded: ['#6c757d', '#fff'],
        voided: ['#dc3545', '#fff'], cancelled: ['#6c757d', '#fff']
    };
    const [bg, fg] = map[s] || ['#e9ecef', '#495057'];
    return `<span class="badge" style="background:${bg};color:${fg};padding:4px 9px;border-radius:20px;font-size:.72rem;">${safeOutput(s)}</span>`;
}

function actionMenu(row) {
    let items = `<li><a class="dropdown-item py-2 rounded" href="${RECEIPT_URL}?id=${row.sale_id}" target="_blank"><i class="bi bi-receipt text-primary me-2"></i> View Receipt</a></li>`;
    if (CAN_CREATE && row.can_return) {
        items += `<li><button class="dropdown-item py-2 rounded" onclick="openReturn(${row.sale_id})"><i class="bi bi-arrow-return-left text-primary me-2"></i> Return / Refund</button></li>`;
    }
    if (CAN_DELETE && row.can_void) {
        items += `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item py-2 rounded text-danger" onclick="voidSale(${row.sale_id}, '${safeOutput(row.receipt_number)}')"><i class="bi bi-x-octagon text-danger me-2"></i> Void Sale</button></li>`;
    }
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-gear-fill me-1"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">${items}</ul></div>`;
}

let table;
$(document).ready(function () {
    table = $('#posSalesTable').DataTable({
        responsive: false, scrollX: true, pageLength: 25, order: [[1, 'desc']], dom: 'rtipB',
        buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
        columns: [
            { data: 'receipt_number', render: (d, t, r) => (r.is_return_sale ? '<i class="bi bi-arrow-return-left text-muted me-1"></i>' : '') + safeOutput(d) },
            { data: 'sale_date', render: d => d ? new Date(d.replace(' ', 'T')).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' },
            { data: 'party', render: d => safeOutput(d) },
            { data: 'grand_total', className: 'text-end', render: d => money(d) },
            { data: 'payment_method', render: d => safeOutput(d || '—') },
            { data: 'sale_status', render: d => statusBadge(d) },
            { data: null, className: 'text-end', orderable: false, render: (d, t, r) => actionMenu(r) }
        ],
        language: { emptyTable: 'No records found.', zeroRecords: 'No matching records.' },
        drawCallback: function () { renderCards(this.api().rows({ page: 'current' }).data().toArray()); }
    });

    loadData();
    $('#btnFilter').on('click', loadData);

    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView();
    $(window).on('resize', applyView);

    <?php if ($can_create): ?>
    $('#returnModal').on('shown.bs.modal', function () {
        $(this).find('.select2-static').each(function () {
            if (!$(this).hasClass('select2-hidden-accessible')) {
                $(this).select2({ theme: 'bootstrap-5', dropdownParent: $('#returnModal'), placeholder: 'Select...', width: '100%' });
            }
        });
    });
    $('#returnModal').on('hidden.bs.modal', function () { $('#returnForm')[0].reset(); $('#ret_lines').empty(); $('#ret-message').html(''); });

    $('#returnForm').on('submit', function (e) {
        e.preventDefault();
        const lines = [];
        $('#ret_lines tr').each(function () {
            const iid = $(this).data('iid');
            const qty = parseFloat($(this).find('.ret-qty').val()) || 0;
            if (iid && qty > 0) lines.push({ sale_item_id: iid, return_qty: qty });
        });
        if (!lines.length) { Swal.fire({ icon: 'warning', title: 'Nothing selected', text: 'Enter a return quantity for at least one line.' }); return; }

        const btn = $(this).find('[type=submit]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Processing...');
        const fd = new FormData(this); fd.append('items', JSON.stringify(lines));
        $.ajax({
            url: RETURN_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('returnModal')).hide();
                    loadData();
                    Swal.fire({ icon: 'success', title: 'Return processed', text: res.message, timer: 2200, showConfirmButton: false });
                } else { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed.' }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });
    <?php endif; ?>
});

function loadData() {
    $.getJSON(GET_SALES, { start_date: $('#fFrom').val(), end_date: $('#fTo').val() }, function (res) {
        if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Load failed.' }); return; }
        const rows = res.data || [];
        table.clear().rows.add(rows).draw();

        let net = 0, count = 0, returns = 0, voided = 0;
        rows.forEach(r => {
            if (r.sale_status === 'voided') { voided++; return; }
            if (r.is_return_sale) { returns++; net -= (r.grand_total - r.tax_amount); return; }
            count++; net += (r.grand_total - r.tax_amount);
        });
        $('#stat-net').text(money(net));
        $('#stat-count').text(count);
        $('#stat-returns').text(returns);
        $('#stat-voided').text(voided);
    });
}

function voidSale(saleId, receipt) {
    if (!CAN_DELETE) return;
    Swal.fire({
        title: 'Void sale ' + receipt + '?',
        input: 'text', inputPlaceholder: 'Reason for voiding (required)',
        text: 'Stock and cash will be reversed. This cannot be undone.', icon: 'warning',
        showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Void Sale',
        inputValidator: v => (!v || !v.trim()) ? 'A reason is required' : undefined
    }).then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('_csrf', CSRF_TOKEN); fd.append('sale_id', saleId); fd.append('reason', r.value.trim());
        Swal.fire({ title: 'Voiding...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        $.ajax({
            url: VOID_URL, type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) { loadData(); Swal.fire({ icon: 'success', title: 'Voided', text: res.message, timer: 2000, showConfirmButton: false }); }
                else { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed.' }); }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); }
        });
    });
}

function openReturn(saleId) {
    if (!CAN_CREATE) return;
    $.getJSON(GET_ITEMS, { sale_id: saleId }, function (res) {
        if (!res.success) { Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Failed.' }); return; }
        $('#ret_sale_id').val(res.sale.sale_id);
        $('#ret_receipt').text(res.sale.receipt_number);
        $('#ret_customer').text(res.sale.customer_name);
        let html = '';
        res.lines.forEach(l => {
            const disabled = l.returnable <= 0 ? 'disabled' : '';
            html += `<tr data-iid="${l.sale_item_id}">
                <td>${safeOutput(l.product_name)}</td>
                <td class="text-end">${l.quantity}</td>
                <td class="text-end">${l.returnable}</td>
                <td><input type="number" class="form-control form-control-sm ret-qty" min="0" max="${l.returnable}" step="any" value="0" ${disabled}></td>
            </tr>`;
        });
        $('#ret_lines').html(html || '<tr><td colspan="4" class="text-center text-muted py-3">No returnable lines.</td></tr>');
        new bootstrap.Modal(document.getElementById('returnModal')).show();
    });
}

function renderCards(rows) {
    if (!rows.length) { $('#cardView').html('<div class="col-12 text-center py-5 text-muted">No records found</div>'); return; }
    let html = '';
    rows.forEach(row => {
        let actions = `<a class="btn btn-sm btn-outline-primary" href="${RECEIPT_URL}?id=${row.sale_id}" target="_blank" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-receipt"></i></a>`;
        if (CAN_CREATE && row.can_return) actions += `<button class="btn btn-sm btn-outline-primary" onclick="openReturn(${row.sale_id})" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-arrow-return-left"></i></button>`;
        if (CAN_DELETE && row.can_void) actions += `<button class="btn btn-sm btn-outline-danger" onclick="voidSale(${row.sale_id}, '${safeOutput(row.receipt_number)}')" style="flex:1;padding:3px 4px;font-size:.72rem"><i class="bi bi-x-octagon"></i></button>`;
        html += `
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between">
                        <div class="fw-bold">${(row.is_return_sale ? '<i class="bi bi-arrow-return-left text-muted me-1"></i>' : '') + safeOutput(row.receipt_number)}</div>
                        <div>${statusBadge(row.sale_status)}</div>
                    </div>
                    <small class="text-muted">${safeOutput(row.party)}</small>
                    <div class="fw-bold text-primary mt-1">${money(row.grand_total)}</div>
                </div>
                <div class="card-footer bg-white border-top p-0">
                    <div style="display:flex;flex-wrap:nowrap;gap:4px;padding:6px;">${actions}</div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php require_once 'footer.php'; ?>
