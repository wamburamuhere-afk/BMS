<?php
// File: app/bms/tenders/tender_boq.php
// scope-audit: skip — tender BOQ view; tenders reference customers (no direct project_id); deferred to Phase G-2
require_once __DIR__ . '/../../../roots.php';

autoEnforcePermission('tenders');
$can_edit = canEdit('tenders') || canCreate('tenders');

includeHeader();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$stmt = $pdo->prepare("SELECT tender_id, tender_no, tender_description, boq_contingency_percent, boq_vat_percent, boq_grand_total FROM tenders WHERE tender_id = ?");
$stmt->execute([$id]);
$tender = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tender) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Tender not found.</div></div>";
    includeFooter();
    exit;
}

$billsStmt = $pdo->prepare("SELECT * FROM tender_boq_bills WHERE tender_id = ? ORDER BY sort_order, bill_id");
$billsStmt->execute([$id]);
$bills = $billsStmt->fetchAll(PDO::FETCH_ASSOC);

$itemsByBill = [];
if ($bills) {
    $billIds = array_column($bills, 'bill_id');
    $placeholders = implode(',', array_fill(0, count($billIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT * FROM tender_boq_items WHERE bill_id IN ($placeholders) ORDER BY sort_order, item_id");
    $itemsStmt->execute($billIds);
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemsByBill[$item['bill_id']][] = $item;
    }
}

$tenderNavActive = 'boq';
?>

<div class="container-fluid px-4 mt-4 mb-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
        <div class="mb-3 mb-md-0 text-center text-md-start">
            <h2 class="fw-bold text-primary"><i class="bi bi-receipt-cutoff me-2"></i>Bills of Quantities</h2>
            <p class="text-muted small mb-0">Tender No: <span class="fw-bold text-dark"><?= safe_output($tender['tender_no']) ?></span></p>
        </div>
        <a href="<?= getUrl('tenders') ?>" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-arrow-left"></i> Back to List</a>
    </div>

    <?php require __DIR__ . '/_tender_nav.php'; ?>

    <div id="boq-message" class="mb-3"></div>

    <p class="text-muted small">Structure the BOQ into bills (sections). Rates are TZS exclusive of VAT; contingency and VAT are added in the Collection / Summary below.</p>

    <form id="boqForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="tender_id" value="<?= (int)$id ?>">
        <input type="hidden" name="action" value="SAVE_BOQ">

        <?php foreach ($bills as $bill): $billId = $bill['bill_id']; $items = $itemsByBill[$billId] ?? []; ?>
        <div class="card border-0 shadow-sm mb-3" data-bill-id="<?= $billId ?>">
            <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <input type="text" class="form-control form-control-sm fw-bold border-0 bg-transparent" style="max-width:320px;"
                       name="bill_titles[<?= $billId ?>]" value="<?= safe_output($bill['bill_title']) ?>" <?= $can_edit ? '' : 'readonly' ?>>
                <?php if ($can_edit): ?>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteBill(<?= $billId ?>)"><i class="bi bi-trash"></i> Remove Bill</button>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px;">#</th>
                            <th>Description</th>
                            <th style="width:100px;">Unit</th>
                            <th style="width:110px;">Qty</th>
                            <th style="width:130px;">Rate (TZS)</th>
                            <th style="width:140px;" class="text-end">Amount</th>
                            <?php if ($can_edit): ?><th style="width:40px;"></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="boq-items-body">
                        <?php if (!$items): ?>
                        <tr class="text-muted"><td colspan="7">No items.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($items as $i => $item): $itemId = $item['item_id']; ?>
                        <tr data-item-id="<?= $itemId ?>">
                            <td><?= $i + 1 ?></td>
                            <td>
                                <input type="hidden" name="items[<?= $itemId ?>][bill_id]" value="<?= $billId ?>">
                                <input type="text" class="form-control form-control-sm" name="items[<?= $itemId ?>][description]" value="<?= safe_output($item['description']) ?>" <?= $can_edit ? '' : 'readonly' ?>>
                            </td>
                            <td><input type="text" class="form-control form-control-sm" name="items[<?= $itemId ?>][unit]" value="<?= safe_output($item['unit']) ?>" <?= $can_edit ? '' : 'readonly' ?>></td>
                            <td><input type="number" step="0.001" class="form-control form-control-sm boq-qty" name="items[<?= $itemId ?>][qty]" value="<?= safe_output($item['qty'], '0') ?>" <?= $can_edit ? '' : 'readonly' ?>></td>
                            <td><input type="number" step="0.01" class="form-control form-control-sm boq-rate" name="items[<?= $itemId ?>][rate]" value="<?= safe_output($item['rate'], '0') ?>" <?= $can_edit ? '' : 'readonly' ?>></td>
                            <td class="text-end boq-amount fw-bold"><?= number_format((float)$item['amount'], 2) ?></td>
                            <?php if ($can_edit): ?>
                            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteItem(<?= $itemId ?>)"><i class="bi bi-x"></i></button></td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="5" class="text-end">Total — <?= safe_output($bill['bill_title']) ?></td>
                            <td class="text-end bill-total" data-bill-id="<?= $billId ?>"><?= number_format(array_sum(array_column($items, 'amount')), 2) ?></td>
                            <?php if ($can_edit): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php if ($can_edit): ?>
            <div class="card-body py-2">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItem(<?= $billId ?>)"><i class="bi bi-plus-circle"></i> Add Item</button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!$bills): ?>
        <div class="alert alert-light border text-center text-muted">No bills yet. <?= $can_edit ? 'Add one below to start pricing this tender.' : '' ?></div>
        <?php endif; ?>

        <?php if ($can_edit): ?>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <button type="button" class="btn btn-outline-primary" onclick="addBill()"><i class="bi bi-plus-circle"></i> Add Bill (Section)</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save BOQ</button>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold text-uppercase small">Collection / Summary</div>
            <div class="card-body">
                <?php foreach ($bills as $bill): $items = $itemsByBill[$bill['bill_id']] ?? []; ?>
                <div class="d-flex justify-content-between py-1 border-bottom">
                    <span><?= safe_output($bill['bill_title']) ?></span>
                    <span class="summary-bill-amount" data-bill-id="<?= $bill['bill_id'] ?>"><?= number_format(array_sum(array_column($items, 'amount')), 2) ?></span>
                </div>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between py-1 border-bottom fw-bold">
                    <span>Sub-total</span>
                    <span id="summarySubtotal">0.00</span>
                </div>
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                    <span>Contingency</span>
                    <span>
                        <input type="number" step="0.01" class="form-control form-control-sm d-inline-block" style="width:90px;" id="contingencyPercent" name="contingency_percent" value="<?= safe_output($tender['boq_contingency_percent'], '0') ?>" <?= $can_edit ? '' : 'readonly' ?>> %
                        &nbsp; <span id="summaryContingencyAmount">0.00</span>
                    </span>
                </div>
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                    <span><input type="number" step="0.01" class="form-control form-control-sm d-inline-block" style="width:70px;" id="vatPercent" name="vat_percent" value="<?= safe_output($tender['boq_vat_percent'], '18') ?>" <?= $can_edit ? '' : 'readonly' ?>> % VAT</span>
                    <span id="summaryVatAmount">0.00</span>
                </div>
                <div class="d-flex justify-content-between py-2 fw-bold fs-5 text-primary">
                    <span>GRAND TOTAL CARRIED TO FORM OF TENDER</span>
                    <span id="summaryGrandTotal"><?= number_format((float)$tender['boq_grand_total'], 2) ?></span>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
const BOQ_API = '<?= buildUrl('api/tender_boq.php') ?>';
const TENDER_ID = <?= (int)$id ?>;

function boqMessage(type, text) {
    $('#boq-message').html(`<div class="alert alert-${type} py-2">${text}</div>`);
}

function recalcTotals() {
    let grand = 0;
    document.querySelectorAll('[data-bill-id].card').forEach(card => {
        const billId = card.getAttribute('data-bill-id');
        let billTotal = 0;
        card.querySelectorAll('.boq-items-body tr[data-item-id]').forEach(tr => {
            const qty = parseFloat(tr.querySelector('.boq-qty')?.value || 0);
            const rate = parseFloat(tr.querySelector('.boq-rate')?.value || 0);
            const amount = qty * rate;
            tr.querySelector('.boq-amount').textContent = amount.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
            billTotal += amount;
        });
        const totalCell = card.querySelector(`.bill-total[data-bill-id="${billId}"]`);
        if (totalCell) totalCell.textContent = billTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        const summaryCell = document.querySelector(`.summary-bill-amount[data-bill-id="${billId}"]`);
        if (summaryCell) summaryCell.textContent = billTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
        grand += billTotal;
    });

    const contingencyPercent = parseFloat(document.getElementById('contingencyPercent')?.value || 0);
    const vatPercent = parseFloat(document.getElementById('vatPercent')?.value || 0);
    const contingencyAmount = grand * (contingencyPercent / 100);
    const vatAmount = (grand + contingencyAmount) * (vatPercent / 100);
    const grandTotal = grand + contingencyAmount + vatAmount;

    document.getElementById('summarySubtotal').textContent = grand.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('summaryContingencyAmount').textContent = contingencyAmount.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('summaryVatAmount').textContent = vatAmount.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('summaryGrandTotal').textContent = grandTotal.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
}

document.addEventListener('input', function (e) {
    if (e.target.matches('.boq-qty, .boq-rate, #contingencyPercent, #vatPercent')) {
        recalcTotals();
    }
});

function addBill() {
    $.post(BOQ_API, { action: 'ADD_BILL', tender_id: TENDER_ID, bill_title: 'New Bill', _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { location.reload(); } else { boqMessage('danger', res.message); }
    }, 'json');
}

function deleteBill(billId) {
    Swal.fire({ title: 'Remove this bill?', text: 'All its items will be removed too.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove' })
        .then(r => {
            if (!r.isConfirmed) return;
            $.post(BOQ_API, { action: 'DELETE_BILL', tender_id: TENDER_ID, bill_id: billId, _csrf: '<?= csrf_token() ?>' }, function (res) {
                if (res.success) { location.reload(); } else { boqMessage('danger', res.message); }
            }, 'json');
        });
}

function addItem(billId) {
    $.post(BOQ_API, { action: 'ADD_ITEM', tender_id: TENDER_ID, bill_id: billId, _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { location.reload(); } else { boqMessage('danger', res.message); }
    }, 'json');
}

function deleteItem(itemId) {
    $.post(BOQ_API, { action: 'DELETE_ITEM', tender_id: TENDER_ID, item_id: itemId, _csrf: '<?= csrf_token() ?>' }, function (res) {
        if (res.success) { location.reload(); } else { boqMessage('danger', res.message); }
    }, 'json');
}

$('#boqForm').on('submit', function (e) {
    e.preventDefault();
    const $btn = $(this).find('[type="submit"]');
    const orig = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');

    $.ajax({
        url: BOQ_API,
        method: 'POST',
        data: $(this).serialize(),
        dataType: 'json',
        success: function (res) {
            if (res.success) {
                boqMessage('success', res.message);
                recalcTotals();
            } else {
                boqMessage('danger', res.message);
            }
        },
        error: function () { boqMessage('danger', 'Server error. Please try again.'); },
        complete: function () { $btn.prop('disabled', false).html(orig); }
    });
});

recalcTotals();
</script>

<?php includeFooter(); ?>
