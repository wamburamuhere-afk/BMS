<?php
$page_title = 'Received Invoices';
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/payment_source.php';
autoEnforcePermission('received_invoices');
logActivity($pdo, $_SESSION['user_id'], 'VIEW', '[Received Invoices] Page viewed');
includeHeader();

global $pdo;
$ri_cash_accounts = cashBankAccounts($pdo);   // Paid-From source list
// Active WHT rates for the Record-Payment withholding dropdown (subtractive taxes).
$ri_wht_rates = $pdo->query("SELECT rate_id, rate_name, rate_percentage
                               FROM tax_rates WHERE tax_kind = 'wht' AND status = 'active'
                           ORDER BY rate_percentage")->fetchAll(PDO::FETCH_ASSOC);
$can_create  = canCreate('received_invoices');
$can_edit    = canEdit('received_invoices');
$can_delete  = canDelete('received_invoices');
$can_review  = canReview('received_invoices');
$can_approve = canApprove('received_invoices');
?>
<style>
.stat-card { border-radius: 12px; transition: transform .2s; background-color: #e7f0ff; border: 1px solid #b6ccfe !important; }
.stat-card:hover { transform: translateY(-3px); }
.badge-supplier { background: #cfe2ff; color: #084298; }

/* Invoice modal: the <form> wraps both the body and footer, which breaks
   Bootstrap's .modal-dialog-scrollable flex chain (the body can't scroll and
   the lower fields get clipped). Re-establish the flex column on the form so
   the body scrolls while header/footer stay fixed. */
#invoiceModal .modal-content { max-height: calc(100vh - 3.5rem); overflow: hidden; }
#invoiceModal .modal-content > form { display: flex; flex-direction: column; min-height: 0; flex: 1 1 auto; overflow: hidden; }
#invoiceModal .modal-body { overflow-y: auto; min-height: 0; }
/* Red 3-D row delete button for the items table. */
.ri-del-btn { box-shadow: 0 2px 0 #a52834; border-radius: 8px; }
.ri-del-btn:active { transform: translateY(1px); box-shadow: 0 1px 0 #a52834; }
.badge-sc       { background: #dbeafe; color: #1e40af; }
.badge-draft    { background: #e9ecef; color: #495057; }
.badge-submitted{ background: #cfe2ff; color: #084298; }
.badge-approved { background: #0d6efd; color: #fff; }
.badge-paid     { background: #052c65; color: #fff; }
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
                <div class="fs-4 fw-bold text-primary" id="stat-amount">0</div>
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
                <div class="fs-4 fw-bold text-primary" id="stat-sc">0</div>
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
                        <option value="pending">Pending</option>
                        <option value="reviewed">Reviewed</option>
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
                        <thead class="bg-white small text-uppercase" style="border-bottom:2px solid #dee2e6;">
                            <tr>
                                <th class="ps-3">S/No</th>
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
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
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
                        <!-- 1. Who (Supplier / Sub-contractor) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold" id="who-label">Supplier <span class="text-danger">*</span></label>
                            <select name="supplier_id" id="f-supplier" class="form-select select2-static" required></select>
                        </div>

                        <!-- Invoice Ref -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Invoice Reference No. <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="invoice_ref" id="f-ref" placeholder="Auto-generating..." required>
                                <button type="button" class="btn btn-outline-secondary" id="btnRefresh" onclick="generateInvoiceRef()" title="Regenerate reference"><i class="bi bi-arrow-clockwise"></i></button>
                            </div>
                            <div id="dup-alert" class="d-none mt-1" style="font-size:0.85rem"></div>
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

                        <!-- 2. Project (shown for both supplier and SC) -->
                        <div class="col-md-6" id="sc-project-wrap">
                            <label class="form-label fw-bold" id="project-label">Project <small class="text-muted fw-normal">(optional)</small></label>
                            <select name="project_id" id="f-project" class="form-select select2-static">
                                <option value="">— Select Project —</option>
                            </select>
                        </div>

                        <!-- 3. Warehouse (supplier only) — filtered by project -->
                        <div class="col-md-6 both-types" id="warehouse-wrap">
                            <label class="form-label fw-bold">Warehouse <small class="text-muted fw-normal">(optional)</small></label>
                            <select name="warehouse_id" id="f-warehouse" class="form-select select2-static">
                                <option value="">— All / None —</option>
                            </select>
                        </div>

                        <!-- 4. Supplier: PO Reference (filtered by supplier + project + warehouse) -->
                        <div class="col-12 supplier-only" id="supplier-fields">
                            <label class="form-label fw-bold">PO Reference</label>
                            <select name="po_id" id="f-po" class="form-select select2-static">
                                <option value="">— Select PO (optional) —</option>
                            </select>
                        </div>

                        <!-- PO Summary panel (visible when PO selected) -->
                        <div class="col-12 d-none" id="po-summary-wrap">
                            <div class="border rounded p-3" style="background:#f8fafc;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold text-primary"><i class="bi bi-clipboard-data me-1"></i>PO Summary</span>
                                    <span class="badge" id="po-summary-status">—</span>
                                </div>
                                <div class="row g-2 small">
                                    <div class="col-md-3 col-6">
                                        <div class="text-muted">PO Total</div>
                                        <div class="fw-bold" id="po-sum-total">—</div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-muted">Previously Invoiced</div>
                                        <div class="fw-bold" id="po-sum-invoiced">—</div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-muted">Remaining Capacity</div>
                                        <div class="fw-bold text-success" id="po-sum-remaining">—</div>
                                    </div>
                                    <div class="col-md-3 col-6">
                                        <div class="text-muted">After This Invoice</div>
                                        <div class="fw-bold" id="po-sum-after">—</div>
                                    </div>
                                </div>
                                <div class="mt-2 small d-none" id="po-sum-warning"></div>
                            </div>
                        </div>

                        <!-- 5. Items table (supplier only) — same money math as invoice_create -->
                        <div class="col-12 both-types" id="items-wrap">
                            <label class="form-label fw-bold mb-1">Items</label>
                            <div class="table-responsive border rounded">
                                <table class="table table-sm align-middle mb-0" id="itemsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="min-width:200px" class="ps-2">Product/Item</th>
                                            <th style="width:90px" class="text-end">Quantity</th>
                                            <th style="width:90px">Unit</th>
                                            <th style="width:130px" class="text-end">Unit Price</th>
                                            <th style="width:80px">Tax</th>
                                            <th style="width:130px" class="text-end">Total</th>
                                            <th style="width:40px"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="ri-itemsBody"></tbody>
                                    <tfoot>
                                        <tr>
                                            <td colspan="7" class="p-2">
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="riAddItemRow()"><i class="bi bi-plus-circle me-1"></i> Add Item</button>
                                            </td>
                                        </tr>
                                        <tr class="border-top">
                                            <td colspan="5" class="text-end fw-semibold">Subtotal</td>
                                            <td class="text-end fw-semibold" id="ri-subtotal">0.00</td><td></td>
                                        </tr>
                                        <tr>
                                            <td colspan="5" class="text-end">VAT (18%)</td>
                                            <td class="text-end" id="ri-tax-total">0.00</td><td></td>
                                        </tr>
                                        <tr class="table-primary">
                                            <td colspan="5" class="text-end fw-bold">Grand Total</td>
                                            <td class="text-end fw-bold" id="ri-grand-total">0.00</td><td></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>

                        <!-- Amount is the items' Grand Total (shown in the table); kept
                             hidden so it still posts. Basis / Basis Ref removed per request. -->
                        <input type="hidden" name="amount" id="f-amount">
                        <small id="f-amount-feedback" class="col-12 d-none"></small>

                        <!-- 6. Attachment (below items, per requirement) -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Attachment <small class="text-muted fw-normal">(PDF / Image, max 5 MB)</small></label>
                            <input type="file" class="form-control" name="attachment" id="f-attachment" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            <small id="current-attachment" class="text-muted d-none"></small>
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

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="bi bi-eye me-2"></i>Invoice Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBody">
                <div class="text-center py-4"><span class="spinner-border text-info"></span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <div id="viewStatusActions" class="d-flex gap-2"></div>
                <button type="button" class="btn btn-outline-primary d-none" id="viewEditBtn" onclick="viewToEdit()">
                    <i class="bi bi-pencil me-1"></i> Edit
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i> Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="paymentForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="invoice_id" id="pay-id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Invoice</label>
                        <input type="text" class="form-control" id="pay-ref" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Amount (TZS)</label>
                        <input type="text" class="form-control" id="pay-amount" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Withholding Tax (WHT)</label>
                        <select class="form-select" name="wht_rate_id" id="pay-wht-rate" onchange="recalcPayNet()">
                            <option value="" data-rate="0">No withholding tax</option>
                            <?php foreach ($ri_wht_rates as $w): $pct = rtrim(rtrim(number_format((float)$w['rate_percentage'], 2), '0'), '.'); ?>
                            <option value="<?= (int)$w['rate_id'] ?>" data-rate="<?= htmlspecialchars($w['rate_percentage']) ?>"><?= safe_output($w['rate_name']) ?> (<?= $pct ?>%)</option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Deducted from the supplier and remitted to TRA. Computed on the VAT-exclusive amount.</small>
                    </div>
                    <div class="mb-3 row g-2">
                        <div class="col-6">
                            <label class="form-label fw-bold small">Withheld (−)</label>
                            <input type="text" class="form-control" id="pay-wht-amount" readonly value="0.00">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small">Net to Pay</label>
                            <input type="text" class="form-control fw-bold text-primary" id="pay-net" readonly>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="payment_date" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Method <span class="text-danger">*</span></label>
                        <select class="form-select" name="payment_method" required>
                            <option value="">-- Select --</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                            <option value="Cash">Cash</option>
                            <option value="Cheque">Cheque</option>
                            <option value="Mobile Money">Mobile Money</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Paid From <span class="text-danger">*</span></label>
                        <select class="form-select" name="payment_account_id" required>
                            <option value="">Select account…</option>
                            <?php foreach ($ri_cash_accounts as $acc): ?>
                            <option value="<?= (int)$acc['account_id'] ?>"><?= safe_output($acc['account_name'] . ($acc['account_code'] ? ' (' . $acc['account_code'] . ')' : '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Cash/bank account the money is paid from.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Payment Reference</label>
                        <input type="text" class="form-control" name="payment_ref" placeholder="e.g. transaction no., cheque no.">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="payBtn">
                        <i class="bi bi-check-circle me-1"></i> Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const RI_CAN_EDIT    = <?= json_encode($can_edit) ?>;
const RI_CAN_DELETE  = <?= json_encode($can_delete) ?>;
const RI_CAN_CREATE  = <?= json_encode($can_create) ?>;
const RI_CAN_REVIEW  = <?= json_encode($can_review) ?>;
const RI_CAN_APPROVE = <?= json_encode($can_approve) ?>;
const RI_API        = '<?= buildUrl('api/received_invoices.php') ?>';
const RI_VIEW_URL   = '<?= getUrl('received_invoices_view') ?>';
// CSRF_TOKEN is declared globally by header.php — declaring it here again
// throws "Identifier 'CSRF_TOKEN' has already been declared" which aborts
// the entire <script> block (so openAddModal() never gets defined and the
// "Record Invoice" button stops responding to clicks).
const RI_EDIT_ID    = <?= json_encode(intval($_GET['edit'] ?? 0)) ?>;

function safeOutput(str) {
    if (str === null || str === undefined || str === false) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m];
    });
}

let riTable = null;
let allRows = [];

$(document).ready(function () {
    initDataTable();
    initSelect2InModal();
    setupTypeToggle();
    setTypeMode('supplier');
    loadInvoices();

    if (RI_EDIT_ID && RI_CAN_EDIT) {
        loadInvoices(function () { editRow(RI_EDIT_ID); });
    }

    $('#f-supplier').on('change', function () {
        const type = $('[name=invoice_type]:checked').val();
        const sid  = $(this).val();
        hidePoSummary();
        loadWarehouses($('#f-project').val());   // both types
        if (type === 'supplier') {
            loadPOs(sid);
            loadProjects(sid, 'supplier');
        } else {
            loadProjects(sid, 'sub_contractor');
        }
    });

    // Supplier change also re-runs the duplicate check (different supplier → clear)
    $('#f-supplier').on('change', function () { checkDuplicate(); });

    $('#f-po').on('change', function () {
        loadPoSummary($(this).val());
        riLoadPoItems($(this).val());   // auto-fill items from the PO
    });
    $('#f-amount').on('input', recalcPoAfter);

    // Project drives the warehouse list (both types) + re-filters the PO list
    // (supplier only).
    $('#f-project').on('change', function () {
        const isSupplier = $('[name=invoice_type]:checked').val() === 'supplier';
        loadWarehouses($(this).val(), function () {
            const sid = $('#f-supplier').val();
            if (isSupplier && sid) loadPOs(sid);
        });
    });
    // Warehouse re-filters the PO Reference list.
    $('#f-warehouse').on('change', function () {
        const sid = $('#f-supplier').val();
        if (sid) loadPOs(sid);
    });

    $('#invoiceForm').on('submit', function (e) {
        e.preventDefault();

        // Client-side cap guard (server enforces this too — defense in depth)
        if (_poSummaryCache) {
            const amt   = parseFloat($('#f-amount').val()) || 0;
            const after = parseFloat(_poSummaryCache.invoiced_total) + amt;
            const total = parseFloat(_poSummaryCache.grand_total);
            if (after > total) {
                Swal.fire({
                    icon: 'error',
                    title: 'Exceeds PO Amount',
                    html: 'This invoice (<strong>' + formatTZS(amt) + '</strong>) plus previous invoices ' +
                          '(<strong>' + formatTZS(_poSummaryCache.invoiced_total) + '</strong>) totals ' +
                          '<strong>' + formatTZS(after) + '</strong>, which is over the PO Total of ' +
                          '<strong>' + formatTZS(total) + '</strong>.<br><br>' +
                          'Return the invoice to the supplier so they can issue a corrected amount.',
                    confirmButtonText: 'OK'
                });
                return;
            }
        }

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
                    const modal = bootstrap.Modal.getInstance(document.getElementById('invoiceModal'));
                    if (modal) modal.hide();
                    loadInvoices();
                    Swal.fire({ icon: 'success', title: 'Saved!', text: res.message, timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            },
            error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
            complete: function () { btn.prop('disabled', false).html(orig); }
        });
    });

    $('#invoiceModal').on('shown.bs.modal', function () {
        const isEdit = !!$('#f-id').val();
        $('#btnRefresh').toggleClass('d-none', isEdit);
        if (!isEdit) {
            loadPartyList($('[name=invoice_type]:checked').val());
            generateInvoiceRef();
            loadWarehouses('');
            // Start a new invoice (either type) with one empty item row.
            if (!$('#ri-itemsBody tr').length) riAddItemRow();
        }
    });

    $('#f-ref').on('blur', function () { checkDuplicate(); });
    $('#f-raised').on('change', function () { checkDuplicate(); });

    $('#invoiceModal').on('hidden.bs.modal', function () {
        $('#invoiceForm')[0].reset();
        $('#f-id').val('');
        $('#form-msg').html('');
        $('#current-attachment').addClass('d-none').text('');
        $('#dup-alert').addClass('d-none').html('');
        riClearItems();
        _riEditLoading = false;
        hidePoSummary();
        setTypeMode('supplier');
        destroyAndResetSelects();
        initSelect2InModal();
    });

    $('#paymentForm').on('submit', function (e) {
        e.preventDefault();
        const btn  = $('#payBtn');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');
        $.post(RI_API + '?action=record_payment', {
            invoice_id:         $('#pay-id').val(),
            payment_date:       $('[name=payment_date]', this).val(),
            payment_method:     $('[name=payment_method]', this).val(),
            payment_account_id: $('[name=payment_account_id]', this).val(),
            payment_ref:        $('[name=payment_ref]', this).val(),
            wht_rate_id:        $('#pay-wht-rate').val(),
            _csrf:          CSRF_TOKEN
        }, function (res) {
            const pm = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
            const vm = bootstrap.Modal.getInstance(document.getElementById('viewModal'));
            if (res.success) {
                if (pm) pm.hide();
                if (vm) vm.hide();
                loadInvoices();
                Swal.fire({ icon: 'success', title: 'Payment Recorded!', text: res.message, timer: 2500, showConfirmButton: false });
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message });
            }
        }, 'json').always(function () { btn.prop('disabled', false).html(orig); });
    });

    $('#paymentModal').on('hidden.bs.modal', function () {
        $('#paymentForm')[0].reset();
    });

    $('#viewModal').on('hidden.bs.modal', function () {
        $('#viewStatusActions').html('');
        $('#viewEditBtn').addClass('d-none');
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

function loadInvoices(callback) {
    const params = {
        action: 'list',
        type:   $('#f-type').val(),
        status: $('#f-status').val()
    };
    $('#list-message').html('');
    $.getJSON(RI_API, params, function (res) {
        if (!res.success) {
            $('#list-message').html('<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>' + res.message + '</div>');
            return;
        }
        allRows = res.data;
        const from = $('#f-from').val();
        const to   = $('#f-to').val();
        let rows = allRows;
        if (from) rows = rows.filter(r => r.date_raised >= from);
        if (to)   rows = rows.filter(r => r.date_raised <= to);
        riTable.clear().rows.add(rows).draw();
        updateStats(rows);
        if (typeof callback === 'function') callback();
    }).fail(function (xhr) {
        $('#list-message').html(
            '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>' +
            'Could not load invoices — HTTP ' + xhr.status + ': ' +
            (xhr.responseText ? xhr.responseText.substring(0, 300) : 'no response') +
            '</div>'
        );
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
        // Edit shows the SAVED items — suppress the PO auto-fill while populating.
        _riEditLoading = true;
        loadPartyList(d.invoice_type, function () {
            $('#f-supplier').val(d.supplier_id).trigger('change.select2');
            if (d.invoice_type === 'supplier') {
                loadProjects(d.supplier_id, 'supplier', function () {
                    if (d.project_id) $('#f-project').val(d.project_id).trigger('change.select2');
                    loadWarehouses(d.project_id, function () {
                        if (d.warehouse_id) $('#f-warehouse').val(d.warehouse_id).trigger('change.select2');
                        loadPOs(d.supplier_id, function () {
                            $('#f-po').val(d.po_id).trigger('change.select2');
                            riFillItems(d.items || []);     // saved items, not the PO's
                            _riEditLoading = false;
                        });
                    });
                });
            } else {
                loadProjects(d.supplier_id, 'sub_contractor', function () {
                    $('#f-project').val(d.project_id).trigger('change.select2');
                    // Sub-contractor now also carries warehouse + saved items.
                    loadWarehouses(d.project_id, function () {
                        if (d.warehouse_id) $('#f-warehouse').val(d.warehouse_id).trigger('change.select2');
                        riFillItems(d.items || []);
                        _riEditLoading = false;
                    });
                });
            }
        });
        $('#modalHeader').addClass('bg-primary');
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

let _viewId = null;

function viewRow(id) {
    _viewId = id;
    $('#viewBody').html('<div class="text-center py-4"><span class="spinner-border text-info"></span></div>');
    $('#viewEditBtn').addClass('d-none');
    new bootstrap.Modal(document.getElementById('viewModal')).show();

    $.getJSON(RI_API, { action: 'get', id: id }, function (res) {
        if (!res.success) {
            $('#viewBody').html('<div class="alert alert-danger">Could not load invoice data.</div>');
            return;
        }
        const d = res.data;
        const typeBadge = d.invoice_type === 'supplier'
            ? '<span class="badge badge-supplier"><i class="bi bi-building me-1"></i>Supplier</span>'
            : '<span class="badge badge-sc"><i class="bi bi-people me-1"></i>Sub-Contractor</span>';

        let refRow = '';
        if (d.invoice_type === 'supplier' && d.po_number) {
            refRow = `<div class="col-md-6"><div class="text-muted small">PO Reference</div><div class="fw-bold">${safeOutput(d.po_number)}</div></div>`;
        }
        if (d.project_name) {
            refRow += `<div class="col-md-6"><div class="text-muted small">Project</div><div class="fw-bold">${safeOutput(d.project_name)}</div></div>`;
        }
        // Basis fields are legacy — show only when an older record still has them.
        let scRows = '';
        if (d.invoice_type === 'sub_contractor' && (d.sc_invoice_basis || d.sc_basis_ref)) {
            scRows = `
            <div class="col-md-6"><div class="text-muted small">Invoice Basis</div><div class="fw-bold">${safeOutput(d.sc_invoice_basis) || '—'}</div></div>
            <div class="col-md-6"><div class="text-muted small">Basis Reference</div><div class="fw-bold">${safeOutput(d.sc_basis_ref) || '—'}</div></div>`;
        }
        const attachmentHtml = d.attachment
            ? `<a href="#" onclick="viewAttachment('${d.attachment}'); return false;" class="btn btn-sm btn-outline-secondary"><i class="bi bi-paperclip me-1"></i>View Attachment</a>`
            : `<span class="text-muted small">No attachment</span>`;

        // Line items (supplier invoices that have them).
        let itemsHtml = '';
        if (d.items && d.items.length) {
            let sub = 0, vat = 0, rows = '';
            d.items.forEach(it => {
                const lt = (parseFloat(it.quantity)||0) * (parseFloat(it.unit_price)||0);
                sub += lt; vat += parseFloat(it.tax_amount)||0;
                rows += `<tr>
                    <td>${safeOutput(it.item_name)}</td>
                    <td class="text-end">${formatCurrency(it.quantity)}</td>
                    <td>${safeOutput(it.unit||'')}</td>
                    <td class="text-end">${formatCurrency(it.unit_price)}</td>
                    <td class="text-end">${parseFloat(it.tax_rate)||0}%</td>
                    <td class="text-end">${formatCurrency(lt)}</td></tr>`;
            });
            itemsHtml = `<div class="col-12"><div class="text-muted small mb-1">Items</div>
                <div class="table-responsive border rounded"><table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Product/Item</th><th class="text-end">Qty</th><th>Unit</th><th class="text-end">Unit Price</th><th class="text-end">Tax</th><th class="text-end">Total</th></tr></thead>
                <tbody>${rows}</tbody>
                <tfoot>
                    <tr><td colspan="5" class="text-end fw-semibold">Subtotal</td><td class="text-end fw-semibold">${formatCurrency(sub)}</td></tr>
                    <tr><td colspan="5" class="text-end">VAT</td><td class="text-end">${formatCurrency(vat)}</td></tr>
                    <tr class="table-primary"><td colspan="5" class="text-end fw-bold">Grand Total</td><td class="text-end fw-bold">${formatCurrency(sub+vat)}</td></tr>
                </tfoot></table></div></div>`;
        }

        $('#viewBody').html(`
            <div class="row g-3">
                <div class="col-12 d-flex align-items-center gap-2 pb-2 border-bottom">
                    <span class="fs-5 fw-bold">${safeOutput(d.invoice_ref)}</span>
                    ${statusBadge(d.status)}
                    ${typeBadge}
                </div>
                <div class="col-md-6"><div class="text-muted small">From</div><div class="fw-bold">${safeOutput(d.party_name)}</div></div>
                <div class="col-md-6"><div class="text-muted small">Amount (TZS)</div><div class="fw-bold text-primary fs-5">TZS ${formatCurrency(d.amount)}</div></div>
                <div class="col-md-6"><div class="text-muted small">Date Raised</div><div class="fw-bold">${safeOutput(d.date_raised)}</div></div>
                <div class="col-md-6"><div class="text-muted small">Date Recorded</div><div class="fw-bold">${safeOutput(d.date_recorded)}</div></div>
                ${refRow}
                ${scRows}
                ${itemsHtml}
                <div class="col-md-6"><div class="text-muted small">Recorded By</div><div class="fw-bold">${safeOutput(d.recorded_by_name) || '—'}</div></div>
                <div class="col-md-6"><div class="text-muted small">Created At</div><div class="fw-bold">${safeOutput(d.created_at)}</div></div>
                ${d.notes ? `<div class="col-12"><div class="text-muted small">Notes</div><div class="border rounded p-2 bg-light">${safeOutput(d.notes)}</div></div>` : ''}
                <div class="col-12 pt-1">${attachmentHtml}</div>
            </div>
        `);
        if (RI_CAN_EDIT) $('#viewEditBtn').removeClass('d-none');

        // Status action buttons in footer
        let statusHtml = '';
        if (RI_CAN_REVIEW && d.status === 'pending')
            statusHtml = `<button class="btn btn-outline-info" onclick="changeStatus(${d.id},'reviewed','${safeOutput(d.invoice_ref)}')"><i class="bi bi-check2 me-1"></i> Mark Reviewed</button>`;
        if (RI_CAN_APPROVE && d.status === 'reviewed')
            statusHtml = `<button class="btn btn-primary" onclick="changeStatus(${d.id},'approved','${safeOutput(d.invoice_ref)}')"><i class="bi bi-check-circle me-1"></i> Approve</button>`;
        if (RI_CAN_APPROVE && d.status === 'approved')
            statusHtml = `<button class="btn btn-primary" onclick="openPaymentModal(${d.id},'${safeOutput(d.invoice_ref)}',${d.amount},${d.subtotal||0},${d.default_wht_rate_id||0})"><i class="bi bi-cash-coin me-1"></i> Record Payment</button>`;
        $('#viewStatusActions').html(statusHtml);
    });
}

function viewToEdit() {
    bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
    setTimeout(() => editRow(_viewId), 400);
}

function viewAttachment(path) {
    if (!path) { Swal.fire('No Attachment', 'This invoice has no attachment.', 'info'); return; }
    window.open(APP_URL + '/' + path, '_blank');
}

// ── Type toggle ────────────────────────────────────────────────────────────

function setupTypeToggle() {
    $('[name=invoice_type]').on('change', function () {
        const type = $(this).val();
        setTypeMode(type);
        destroyAndResetSelects();
        loadPartyList(type);
        initSelect2InModal();
        // Both types use items + warehouse now.
        loadWarehouses($('#f-project').val());
        if (!$('#ri-itemsBody tr').length) riAddItemRow();
    });
}

function setTypeMode(type) {
    // Both types capture line items (warehouse + items) with a derived amount;
    // only the PO Reference is supplier-specific.
    $('.both-types').removeClass('d-none');
    $('#sc-project-wrap').removeClass('d-none');

    if (type === 'supplier') {
        $('#who-label').html('Supplier <span class="text-danger">*</span>');
        $('.supplier-only').removeClass('d-none');     // PO Reference
        $('#project-label').html('Project <small class="text-muted fw-normal">(optional)</small>');
        $('#f-project').removeAttr('required');
    } else {
        $('#who-label').html('Sub-Contractor <span class="text-danger">*</span>');
        $('.supplier-only').addClass('d-none');         // no PO for sub-contractors
        $('#project-label').html('Project <span class="text-danger">*</span>');
        $('#f-project').attr('required', true);
        hidePoSummary();
    }
}

function generateInvoiceRef() {
    $.getJSON(RI_API, { action: 'get_next_ref' }, function (res) {
        if (res.success) $('#f-ref').val(res.ref);
    });
}

// ── Duplicate invoice detection ───────────────────────────────────────────
let _dupTimer = null;
function checkDuplicate() {
    const supplierId = $('#f-supplier').val();
    const ref        = $.trim($('#f-ref').val());
    const amount     = parseFloat($('#f-amount').val()) || 0;
    const dateRaised = $('#f-raised').val();
    const excludeId  = $('#f-id').val() || 0;   // 0 on add; invoice id when editing

    $('#dup-alert').addClass('d-none').html('');
    if (!supplierId || !ref) return;

    clearTimeout(_dupTimer);
    _dupTimer = setTimeout(function () {
        $.getJSON(RI_API, {
            action:      'check_duplicate',
            supplier_id: supplierId,
            invoice_ref: ref,
            amount:      amount,
            date_raised: dateRaised,
            exclude_id:  excludeId
        }, function (res) {
            if (!res.success) return;
            let html = '';
            if (res.exact) {
                const e = res.exact;
                html += `<div class="alert alert-danger py-2 px-3 mb-0">
                    <i class="bi bi-exclamation-octagon-fill me-1"></i>
                    <strong>Duplicate reference —</strong>
                    <a href="${RI_VIEW_URL}?id=${e.id}" target="_blank" class="alert-link fw-bold">${safeOutput(e.invoice_ref)}</a>
                    already exists for this supplier
                    (TZS ${formatCurrency(e.amount)}&ensp;&bull;&ensp;${safeOutput(e.date_raised)}&ensp;&bull;&ensp;<em>${safeOutput(e.status)}</em>).
                </div>`;
            }
            if (res.fuzzy && res.fuzzy.length) {
                const rows = res.fuzzy.map(function (f) {
                    return `<a href="${RI_VIEW_URL}?id=${f.id}" target="_blank" class="alert-link">${safeOutput(f.invoice_ref)}</a>
                            (TZS ${formatCurrency(f.amount)}, ${safeOutput(f.date_raised)})`;
                }).join('; &ensp;');
                html += `<div class="alert alert-warning py-2 px-3 mb-0${res.exact ? ' mt-1' : ''}">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <strong>Similar invoice(s) found:</strong> ${rows}.
                    Verify these are not duplicates.
                </div>`;
            }
            if (html) $('#dup-alert').removeClass('d-none').html(html);
        });
    }, 450);
}

function loadPartyList(type, cb) {
    const action = type === 'supplier' ? 'get_suppliers' : 'get_sub_contractors';
    $.getJSON(RI_API, { action: action }, function (res) {
        const $sel = $('#f-supplier');
        $sel.empty().append('<option value="">— Select ' + (type === 'supplier' ? 'Supplier' : 'Sub-Contractor') + ' —</option>');
        (res.data || []).forEach(function (item) {
            $sel.append($('<option>').val(item.id).text(item.text));
        });
        if ($sel.hasClass('select2-hidden-accessible')) $sel.trigger('change.select2');
        if (cb) cb();
    });
}

function loadPOs(supplierId, cb) {
    if (!supplierId) return;
    // PO Reference is narrowed by the chosen project + warehouse (both optional).
    const params = {
        action: 'get_pos',
        supplier_id: supplierId,
        project_id: $('#f-project').val() || '',
        warehouse_id: $('#f-warehouse').val() || ''
    };
    $.getJSON(RI_API, params, function (res) {
        const $sel = $('#f-po');
        $sel.empty().append('<option value="">— Select PO (optional) —</option>');
        (res.data || []).forEach(function (item) {
            $sel.append($('<option>').val(item.id).text(item.text));
        });
        if ($sel.hasClass('select2-hidden-accessible')) $sel.trigger('change.select2');
        if (cb) cb();
    });
}

// Warehouses for the chosen project (or company-wide when no project).
function loadWarehouses(projectId, cb) {
    $.getJSON(RI_API, { action: 'get_warehouses', project_id: projectId || '' }, function (res) {
        const $sel = $('#f-warehouse');
        const cur = $sel.val();
        $sel.empty().append('<option value="">— All / None —</option>');
        (res.data || []).forEach(function (w) { $sel.append($('<option>').val(w.id).text(w.text)); });
        if (cur) $sel.val(cur);
        if ($sel.hasClass('select2-hidden-accessible')) $sel.trigger('change.select2');
        if (cb) cb();
    });
}

// ── Items table — same money math as invoice_create.php ─────────────────────
let _riItemIdx = 0;
function riAddItemRow(item) {
    const idx = _riItemIdx++;
    const tr = `
        <tr>
            <td class="ps-2">
                <input type="hidden" name="items[${idx}][product_id]" value="${item ? (item.product_id || '') : ''}">
                <input type="text" class="form-control form-control-sm ri-item-name" name="items[${idx}][item_name]" value="${item ? safeOutput(item.item_name) : ''}" placeholder="Product / item" required>
            </td>
            <td><input type="number" class="form-control form-control-sm text-end ri-item-qty" name="items[${idx}][quantity]" value="${item ? item.quantity : 1}" min="0" step="0.01" oninput="riCalcTotals()"></td>
            <td><input type="text" class="form-control form-control-sm ri-item-unit" name="items[${idx}][unit]" value="${item ? safeOutput(item.unit || '') : ''}" placeholder="Unit"></td>
            <td><input type="number" class="form-control form-control-sm text-end ri-item-price" name="items[${idx}][unit_price]" value="${item ? item.unit_price : 0}" min="0" step="0.01" oninput="riCalcTotals()"></td>
            <td>
                <select class="form-select form-select-sm ri-item-tax" name="items[${idx}][tax_rate]" onchange="riCalcTotals()">
                    <option value="0" ${item && Number(item.tax_rate) === 0 ? 'selected' : ''}>0%</option>
                    <option value="18" ${item && Number(item.tax_rate) === 18 ? 'selected' : ''}>18%</option>
                </select>
            </td>
            <td class="text-end fw-bold ri-item-total">0.00</td>
            <td class="text-center"><button type="button" class="btn btn-sm btn-danger ri-del-btn" onclick="riRemoveItemRow(this)" title="Remove item"><i class="bi bi-trash3-fill"></i></button></td>
        </tr>`;
    $('#ri-itemsBody').append(tr);
    riCalcTotals();
}
function riRemoveItemRow(btn) { $(btn).closest('tr').remove(); riCalcTotals(); }
function riClearItems() { $('#ri-itemsBody').empty(); riCalcTotals(); }
function riFillItems(items) {
    riClearItems();
    (items || []).forEach(riAddItemRow);
    if (!$('#ri-itemsBody tr').length) riAddItemRow();
}
// Identical math to invoice_create.php calculateTotals():
//   lineTotal (ex-tax) = qty*price ; lineTax = lineTotal*rate/100
//   Subtotal = Σ lineTotal ; VAT = Σ lineTax ; Grand = Subtotal + VAT
function riCalcTotals() {
    let subtotal = 0, taxTotal = 0;
    $('#ri-itemsBody tr').each(function () {
        const qty   = parseFloat($(this).find('.ri-item-qty').val())   || 0;
        const price = parseFloat($(this).find('.ri-item-price').val()) || 0;
        const rate  = parseFloat($(this).find('.ri-item-tax').val())   || 0;
        const lineTotal = qty * price;
        const lineTax   = lineTotal * (rate / 100);
        subtotal += lineTotal;
        taxTotal += lineTax;
        $(this).find('.ri-item-total').text(lineTotal.toFixed(2));
    });
    const grand = subtotal + taxTotal;
    $('#ri-subtotal').text(subtotal.toFixed(2));
    $('#ri-tax-total').text(taxTotal.toFixed(2));
    $('#ri-grand-total').text(grand.toFixed(2));
    // Both types: push the grand total into the (read-only) Amount field.
    $('#f-amount').val(grand ? grand.toFixed(2) : '');
    if ($('[name=invoice_type]:checked').val() === 'supplier') recalcPoAfter();
}

// Pull a PO's items into the table (auto-fill on PO select). Suppressed while
// an existing invoice is being loaded into the form (edit shows saved items).
let _riEditLoading = false;
function riLoadPoItems(poId) {
    if (!poId || _riEditLoading) return;
    $.getJSON(RI_API, { action: 'get_po_items', po_id: poId }, function (res) {
        if (res.success && (res.data || []).length) riFillItems(res.data);
    });
}

// ── PO Summary live panel ─────────────────────────────────────────────────
let _poSummaryCache = null;

function formatTZS(n) {
    n = parseFloat(n) || 0;
    return 'TZS ' + n.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function hidePoSummary() {
    _poSummaryCache = null;
    $('#po-summary-wrap').addClass('d-none');
    $('#f-amount-feedback').addClass('d-none').removeClass('text-danger text-success').text('');
}

function loadPoSummary(poId) {
    if (!poId) { hidePoSummary(); return; }
    const editId = $('#f-id').val() || 0;
    $.getJSON(RI_API, { action: 'po_summary', po_id: poId, exclude_id: editId }, function (res) {
        if (!res.success) { hidePoSummary(); return; }
        _poSummaryCache = res.data;
        $('#po-summary-wrap').removeClass('d-none');
        $('#po-sum-total').text(formatTZS(res.data.grand_total));
        $('#po-sum-invoiced').text(formatTZS(res.data.invoiced_total));
        $('#po-sum-remaining').text(formatTZS(res.data.remaining))
            .toggleClass('text-success', res.data.remaining > 0)
            .toggleClass('text-danger',  res.data.remaining <= 0);
        recalcPoAfter();

        // Auto-fill Project from the PO (per boss: "ukichagua PO, project itokee tuu")
        if (res.data.project_id) {
            const $proj = $('#f-project');
            // If the option isn't in the dropdown yet (e.g. user hasn't loaded projects), inject it
            if ($proj.find('option[value="' + res.data.project_id + '"]').length === 0 && res.data.project_name) {
                $proj.append(new Option(res.data.project_name, res.data.project_id, true, true));
            }
            $proj.val(res.data.project_id).trigger('change.select2');
        }
    });
}

function recalcPoAfter() {
    const d = _poSummaryCache;
    if (!d) return;
    const amt   = parseFloat($('#f-amount').val()) || 0;
    const after = parseFloat(d.invoiced_total) + amt;
    const total = parseFloat(d.grand_total);
    $('#po-sum-after').text(formatTZS(after));

    const $st  = $('#po-summary-status');
    const $fb  = $('#f-amount-feedback');
    const $war = $('#po-sum-warning');

    $war.addClass('d-none').text('');
    $fb.addClass('d-none').removeClass('text-danger text-success').text('');

    if (after > total) {
        const over = after - total;
        $st.removeClass().addClass('badge bg-danger').text('Exceeds PO');
        $war.removeClass('d-none')
            .html('<i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>' +
                  '<strong>This invoice exceeds the PO by ' + formatTZS(over) + '.</strong> ' +
                  'Return it to the supplier to issue a corrected amount.');
        $fb.removeClass('d-none').addClass('text-danger').text('Amount exceeds PO remaining capacity by ' + formatTZS(over));
    } else if (after === total) {
        $st.removeClass().addClass('badge bg-success').text('Fully Billed');
    } else if (after > total * 0.9) {
        $st.removeClass().addClass('badge bg-warning text-dark').text('Near Cap');
    } else {
        $st.removeClass().addClass('badge bg-primary').text('Within Capacity');
    }
}

function loadProjects(supplierId, type, cb) {
    if (!supplierId) return;
    $.getJSON(RI_API, { action: 'get_projects', supplier_id: supplierId, type: type || 'sub_contractor' }, function (res) {
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
    ['#f-supplier', '#f-po', '#f-project'].forEach(function (id) {
        const $el = $(id);
        if ($el.hasClass('select2-hidden-accessible')) $el.select2('destroy');
        $el.empty();
    });
}

// ── Rendering helpers ──────────────────────────────────────────────────────

function actionButtons(row) {
    const ref = safeOutput(row.invoice_ref);
    let btns = `
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-gear"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item py-2" href="${RI_VIEW_URL}?id=${row.id}"><i class="bi bi-eye text-primary me-2"></i> View</a></li>
                <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="viewAttachment('${row.attachment || ''}')"><i class="bi bi-paperclip text-secondary me-2"></i> View/Download Attachment</a></li>`;
    if (RI_CAN_REVIEW && row.status === 'pending')
        btns += `<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeStatus(${row.id},'reviewed','${ref}')"><i class="bi bi-check2 text-info me-2"></i> Mark Reviewed</a></li>`;
    if (RI_CAN_APPROVE && row.status === 'reviewed')
        btns += `<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeStatus(${row.id},'approved','${ref}')"><i class="bi bi-check-circle text-primary me-2"></i> Approve</a></li>`;
    if (RI_CAN_APPROVE && row.status === 'approved')
        btns += `<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="openPaymentModal(${row.id},'${ref}',${row.amount},${row.subtotal||0},${row.default_wht_rate_id||0})"><i class="bi bi-cash-coin text-primary me-2"></i> Record Payment</a></li>`;
    if (RI_CAN_EDIT)
        btns += `<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="editRow(${row.id})"><i class="bi bi-pencil text-info me-2"></i> Edit</a></li>`;
    if (RI_CAN_DELETE)
        btns += `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="confirmDelete(${row.id}, '${ref}')"><i class="bi bi-trash me-2"></i> Delete</a></li>`;
    btns += `</ul></div>`;
    return btns;
}

function statusBadge(s) {
    const labels = { pending: 'Pending', reviewed: 'Reviewed', approved: 'Approved', paid: 'Paid',
                     draft: 'Pending', submitted: 'Reviewed' };
    const map    = { pending: 'badge-draft', reviewed: 'badge-submitted', approved: 'badge-approved', paid: 'badge-paid',
                     draft: 'badge-draft', submitted: 'badge-submitted' };
    return `<span class="badge ${map[s] || 'bg-secondary'}">${labels[s] || s}</span>`;
}

function changeStatus(id, newStatus, ref) {
    const labels = { reviewed: 'Mark Reviewed', approved: 'Approve' };
    Swal.fire({
        title: labels[newStatus] + '?',
        text: 'Invoice: ' + ref,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        confirmButtonText: 'Yes, ' + labels[newStatus]
    }).then(function (r) {
        if (!r.isConfirmed) return;
        $.post(RI_API + '?action=change_status', { id: id, new_status: newStatus, _csrf: CSRF_TOKEN },
            function (res) {
                const vm = bootstrap.Modal.getInstance(document.getElementById('viewModal'));
                if (res.success) {
                    if (vm) vm.hide();
                    loadInvoices();
                    Swal.fire({ icon: 'success', title: 'Done!', text: res.message, timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: res.message });
                }
            }, 'json');
    });
}

let payGross = 0, payBase = 0;   // gross (invoice total) + VAT-exclusive WHT base
function openPaymentModal(id, ref, amount, subtotal, defaultWht) {
    // Reset FIRST — resetting after filling the fields below would wipe the
    // read-only Invoice + Amount display blank (the bug this fixes).
    $('#paymentForm')[0].reset();
    $('#pay-id').val(id);
    $('#pay-ref').val(ref);
    payGross = parseFloat(amount) || 0;
    // WHT is charged on the VAT-exclusive base (subtotal); fall back to the
    // gross only when an invoice has no stored subtotal.
    payBase  = (parseFloat(subtotal) > 0) ? parseFloat(subtotal) : payGross;
    $('#pay-amount').val('TZS ' + formatCurrency(payGross));
    // Auto-fill the supplier's default WHT category (if any) — user can still change it.
    $('#pay-wht-rate').val(defaultWht ? String(defaultWht) : '');
    recalcPayNet();
    new bootstrap.Modal(document.getElementById('paymentModal')).show();
}

// Live preview: selecting a WHT rate reduces the cash that will actually be paid.
function recalcPayNet() {
    const rate = parseFloat($('#pay-wht-rate').find(':selected').data('rate')) || 0;
    const wht  = +(payBase * rate / 100).toFixed(2);
    $('#pay-wht-amount').val(formatCurrency(wht));
    $('#pay-net').val('TZS ' + formatCurrency(payGross - wht));
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
                <div class="card-footer bg-white p-2 border-top d-flex justify-content-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><a class="dropdown-item py-2" href="${RI_VIEW_URL}?id=${row.id}"><i class="bi bi-eye text-primary me-2"></i> View</a></li>
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="viewAttachment('${row.attachment || ''}')"><i class="bi bi-paperclip text-secondary me-2"></i> View/Download Attachment</a></li>
                            ${RI_CAN_REVIEW && row.status === 'pending' ? `<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeStatus(${row.id},'reviewed','${safeOutput(row.invoice_ref)}')"><i class="bi bi-check2 text-info me-2"></i> Mark Reviewed</a></li>` : ''}
                            ${RI_CAN_APPROVE && row.status === 'reviewed' ? `<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="changeStatus(${row.id},'approved','${safeOutput(row.invoice_ref)}')"><i class="bi bi-check-circle text-primary me-2"></i> Approve</a></li>` : ''}
                            ${RI_CAN_APPROVE && row.status === 'approved' ? `<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="openPaymentModal(${row.id},'${safeOutput(row.invoice_ref)}',${row.amount},${row.subtotal||0},${row.default_wht_rate_id||0})"><i class="bi bi-cash-coin text-primary me-2"></i> Record Payment</a></li>` : ''}
                            ${RI_CAN_EDIT   ? `<li><hr class="dropdown-divider opacity-50"></li><li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="editRow(${row.id})"><i class="bi bi-pencil text-info me-2"></i> Edit</a></li>` : ''}
                            ${RI_CAN_DELETE ? `<li><a class="dropdown-item py-2 text-danger" href="javascript:void(0)" onclick="confirmDelete(${row.id},'${safeOutput(row.invoice_ref)}')"><i class="bi bi-trash me-2"></i> Delete</a></li>` : ''}
                        </ul>
                    </div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}
</script>

<?php includeFooter(); ?>
