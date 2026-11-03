<?php
/**
 * app/bms/restaurant/purchases.php — Purchases (restaurant focus pass, 2026-11-02).
 *
 * ONE form, the way the retail Receive Stock works — pick what you bought, say how many and what it cost:
 *   • an item you COUNT (a drink, rice, oil, meat): stock goes up and its cost follows what you pay (weighted average), so the
 *     cost of every plate and the profit stay true. Price for one OR the total paid (a goat, a crate, a sack). An item you sell
 *     also shows its selling price and the profit it leaves.
 *   • "Something else" (tomatoes, a bundle of greens) — not counted: just the amount, straight to the cost of food sold.
 * No account is ever asked — it is paid from the default cash account, like every other Smart POS screen.
 *
 * Only for Smart POS + Restaurant POS, on Restaurant/Hybrid shops. Logic: core/restaurant_purchases.php.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_purchases.php';

$page_title = 'Purchases';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$module_on  = restaurantModuleOn($pdo);
$warehouses = $module_on ? restaurantWarehousesForSelect($pdo) : [];
$can_buy    = hasPermission('adjust_stock') || hasPermission('pos_restock') || isAdmin();
$can_edit   = canEdit('restaurant_pos');
$suppliers  = ($module_on && canView('suppliers'))
    ? $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC) : [];
$preItem = null;                                   // ?item=ID (from the Menu's "Buy stock") — shown as the chosen item
if ($module_on && $warehouses && !empty($_GET['item'])) {
    $pi = $pdo->prepare("SELECT p.product_id, p.product_name, COALESCE(NULLIF(p.unit,''), NULLIF(p.unit_of_measure,''), 'pcs') AS unit, COALESCE(p.cost_price,0) AS cost_price
                           FROM products p WHERE p.product_id = ? AND " . restaurantStockItemSql('p'));
    $pi->execute([(int)$_GET['item']]);
    $preItem = $pi->fetch(PDO::FETCH_ASSOC) ?: null;
}
$units = ['kg', 'g', 'lt', 'ml', 'pcs', 'dozen', 'bunch', 'bag', 'crate', 'carton'];
// "Something else" (not counted) is saved as an ordinary expense on the cost-of-food account — needs permission and that account.
$other_account = ($module_on && canCreate('expenses')) ? restaurantIngredientsAccountId($pdo) : null;
?>

<div class="container-fluid mt-3 mt-md-4 rp-page">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
        <h4 class="mb-0"><i class="ri ri-shopping-basket text-primary me-2"></i><?= t('Purchases') ?></h4>
        <?php if (count($warehouses) > 1): ?>
        <div style="width:260px;max-width:55%;"><select id="rpShop" class="form-select form-select-sm select2-static">
            <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['warehouse_id'] ?>"><?= caseFormat($w['warehouse_name']) ?></option><?php endforeach; ?>
        </select></div>
        <?php endif; ?>
    </div>

    <?php if (!$warehouses): ?>
    <div class="alert alert-warning"><i class="bi bi-info-circle me-1"></i><?= t('No warehouse in your scope is set to Restaurant or Hybrid mode yet. Switch a warehouse\'s POS Mode first.') ?></div>
    <?php elseif (!$can_buy): ?>
    <div class="alert alert-warning"><i class="bi bi-lock me-1"></i><?= t('You do not have permission to buy stock.') ?></div>
    <?php else: ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="rp-card h-100">
                <div class="rp-card-h"><h6><i class="ri ri-shopping-basket me-1"></i><?= t('Buy stock & ingredients') ?></h6></div>
                <form id="rpForm" class="p-3" autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="warehouse_id" id="rpWarehouse" value="<?= (int)$warehouses[0]['warehouse_id'] ?>">
                    <div id="rpMessage" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= t('What did you buy?') ?> <span class="text-danger">*</span></label>
                        <select id="rpItem" name="product_id" class="form-select" style="width:100%">
                            <?php if ($preItem): ?><option value="<?= (int)$preItem['product_id'] ?>" selected><?= safe_output($preItem['product_name']) ?> (<?= htmlspecialchars($preItem['unit']) ?>)</option><?php endif; ?>
                        </select>
                        <div class="d-flex justify-content-between small mt-1">
                            <span class="text-muted" id="rpItemInfo"></span>
                            <?php if ($can_edit): ?><a href="#" id="rpNewIngredient"><i class="bi bi-plus-circle me-1"></i><?= t('New ingredient') ?></a><?php endif; ?>
                        </div>
                    </div>

                    <!-- A counted item -->
                    <div id="rpStockFields">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold"><?= t('Quantity') ?> <span class="text-danger">*</span> <small class="text-muted" id="rpUnit"></small></label>
                                <input type="number" class="form-control" id="rpQty" min="0.001" step="0.001" inputmode="decimal">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label fw-semibold"><?= t('Price for one') ?></label>
                                <input type="number" class="form-control" id="rpPrice" min="0" step="0.01" inputmode="decimal">
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label fw-semibold"><?= t('Total paid') ?></label>
                                <input type="number" class="form-control" id="rpTotal" min="0" step="0.01" inputmode="decimal">
                            </div>
                        </div>
                        <div class="form-text"><?= t('Fill the price for one, or the total you paid — the other is worked out. Buying a goat or a crate? Enter what you can use and the total.') ?></div>
                        <div class="mt-3" id="rpSellRow" style="display:none;">
                            <label class="form-label fw-semibold"><?= t('Selling price') ?></label>
                            <input type="number" class="form-control" id="rpSell" min="0" step="0.01" inputmode="decimal" style="max-width:220px;">
                        </div>
                        <div class="rp-preview mt-3" id="rpPreview" style="display:none;"></div>
                    </div>

                    <!-- Something else (not counted) -->
                    <div id="rpOtherFields" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label fw-semibold"><?= t('What is it?') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="rpOtherName" maxlength="200" placeholder="<?= htmlspecialchars(t('e.g. tomatoes, onions, a bundle of greens')) ?>">
                        </div>
                        <div class="mb-1">
                            <label class="form-label fw-semibold"><?= t('Amount paid') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="rpOtherAmount" min="1" step="any" inputmode="decimal" style="max-width:220px;">
                        </div>
                        <div class="form-text"><?= t('Not counted in stock — the amount is recorded as the cost of the food you sell.') ?></div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold"><?= t('Date') ?></label>
                            <input type="date" class="form-control" name="date" id="rpDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div id="rpMoreWrap">
                        <a class="small d-inline-block mt-2" data-bs-toggle="collapse" href="#rpMore" role="button"><i class="bi bi-sliders me-1"></i><?= t('More options') ?></a>
                        <div class="collapse" id="rpMore">
                            <div class="row g-2 mt-1">
                                <?php if ($suppliers): ?>
                                <div class="col-md-6">
                                    <label class="form-label"><?= t('Supplier') ?> <small class="text-muted">(<?= t('optional') ?>)</small></label>
                                    <select name="supplier_id" id="rpSupplier" class="form-select select2-static" style="width:100%">
                                        <option value=""><?= t('-- Select --') ?></option>
                                        <?php foreach ($suppliers as $sp): ?><option value="<?= (int)$sp['supplier_id'] ?>"><?= caseFormat($sp['supplier_name']) ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-6">
                                    <label class="form-label"><?= t('Expiry date') ?> <small class="text-muted">(<?= t('optional') ?>)</small></label>
                                    <input type="date" class="form-control" name="expiry_date" id="rpExpiry">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary" id="rpSave"><i class="bi bi-check-circle me-1"></i><?= t('Save purchase') ?></button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="rp-explain h-100">
                <i class="bi bi-lightbulb"></i>
                <div>
                    <strong><?= t('How it works') ?></strong>
                    <span><?= t('Drinks, rice, oil and meat are counted: stock goes up, and the cost of every plate follows the price you pay.') ?></span>
                    <span class="mt-2"><?= t('Not on the list — tomatoes, a bundle of greens? Choose "Something else". It is not counted; the amount is the cost of food sold.') ?></span>
                </div>
            </div>
        </div>
    </div>
    <div class="rp-card mb-3">
        <div class="rp-card-h"><h6><i class="bi bi-clock-history me-1"></i><?= t('Recent purchases') ?></h6></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="rpTable">
            <thead><tr><th><?= t('Date') ?></th><th><?= t('Item') ?></th><th><?= t('Quantity') ?></th><th><?= t('Price for one') ?></th><th><?= t('Total') ?></th><th><?= t('By') ?></th></tr></thead>
            <tbody></tbody>
        </table></div>
    </div>

    <!-- New ingredient -->
    <div class="modal fade" id="rpIngModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i><?= t('New ingredient') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form id="rpIngForm" autocomplete="off">
                <div class="modal-body">
                    <div id="rpIngMsg" class="mb-2"></div>
                    <div class="mb-3"><label class="form-label"><?= t('Name') ?> <span class="text-danger">*</span></label><input type="text" class="form-control" name="name" required maxlength="100"></div>
                    <div><label class="form-label"><?= t('Unit') ?></label><select class="form-select" name="unit"><?php foreach ($units as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?></select></div>
                    <input type="hidden" name="cost_price" value="0"><!-- the price comes from the purchase itself -->
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button><button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button></div>
            </form>
        </div></div>
    </div>
    <?php endif; ?>
</div>

<style>
.rp-page .rp-card { background:#fff; border:1px solid #dbe6fb; border-radius:14px; box-shadow:0 1px 3px rgba(13,110,253,.06); overflow:hidden; }
.rp-page .rp-card-h { padding:10px 14px; border-bottom:1px solid #eef3fd; }
.rp-page .rp-card-h h6 { margin:0; font-weight:700; color:#0b3d91; }
.rp-page .rp-tag { font-size:.65rem; font-weight:700; border-radius:20px; padding:2px 8px; margin-left:6px; background:#cfe2ff; color:#084298; vertical-align:middle; }
.rp-page .rp-tag-grey { background:#e9ecef; color:#495057; }
.rp-page .rp-explain { display:flex; gap:10px; align-items:flex-start; background:#f4f8ff; border:1px solid #cfe2ff; border-radius:12px; padding:10px 14px; font-size:.85rem; color:#1e3a8a; }
.rp-page .rp-explain i { color:#0d6efd; font-size:1.2rem; }
.rp-page .rp-explain span { display:block; color:#495057; }
.rp-page .rp-preview { background:#e7f0ff; border:1px solid #b6ccfe; border-radius:10px; padding:9px 12px; font-size:.85rem; color:#0b3d91; }
</style>

<?php if ($warehouses && $can_buy): ?>
<script>
const RP = {
    api: <?= json_encode(buildUrl('api/restaurant/')) ?>,
    expenseApi: <?= json_encode(buildUrl('api/add_expense.php')) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    cur: <?= json_encode(getSetting('currency', 'TZS')) ?>,
    otherAccount: <?= (int)($other_account ?: 0) ?>,
    cashAccount: <?= (int)(posReceiptAccountId($pdo, 'cash') ?: 0) ?>,
    s: {
        search: <?= json_encode(t('Search the item...')) ?>, saving: <?= json_encode(t('Saving...')) ?>, error: <?= json_encode(t('Error')) ?>,
        serverError: <?= json_encode(t('Server error. Please try again.')) ?>, total: <?= json_encode(t('Total')) ?>,
        onHand: <?= json_encode(t('On hand')) ?>, cost: <?= json_encode(t('Cost')) ?>, newCost: <?= json_encode(t('New cost')) ?>,
        costFollows: <?= json_encode(t('The cost follows the prices you pay (average).')) ?>,
        pickItem: <?= json_encode(t('Pick what you bought, or choose "Something else".')) ?>, saved: <?= json_encode(t('Purchase saved.')) ?>,
        costNow: <?= json_encode(t('Cost is now')) ?>, none: <?= json_encode(t('No purchases yet.')) ?>,
        other: <?= json_encode(t('Something else (not counted)')) ?>, notCounted: <?= json_encode(t('Not counted')) ?>,
        sells: <?= json_encode(t('Sells for')) ?>, costs: <?= json_encode(t('costs')) ?>, perOne: <?= json_encode(t('profit on each')) ?>,
        belowCost: <?= json_encode(t('Selling below cost')) ?>, costsPer: <?= json_encode(t('Costs')) ?>, per: <?= json_encode(t('per')) ?>,
        enterQty: <?= json_encode(t('Enter the quantity.')) ?>, enterPrice: <?= json_encode(t('Enter the price for one, or the total you paid.')) ?>,
        enterName: <?= json_encode(t('Enter what you bought.')) ?>, enterAmount: <?= json_encode(t('Enter the amount paid.')) ?>,
        groceryDesc: <?= json_encode(t('Ingredients')) ?>
    }
};
const fmt = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
let rpItem = null;        // { stock, cost, unit, sell } of the chosen item
let rpMode = 'stock';     // 'stock' = a counted item, 'other' = something else (not counted)
let rpLast = 'price';     // which of price / total the user typed last

function rpWh() { return parseInt($('#rpShop').length ? $('#rpShop').val() : $('#rpWarehouse').val(), 10); }

function rpSetMode(m) {
    rpMode = m;
    $('#rpStockFields').toggle(m === 'stock'); $('#rpOtherFields').toggle(m === 'other'); $('#rpMoreWrap').toggle(m === 'stock');
    if (m === 'other') { rpItem = null; $('#rpItemInfo').text(''); }
}

// price for one <-> total paid: whichever was typed last wins, the other follows
function rpSync(from) {
    const q = parseFloat($('#rpQty').val()) || 0;
    if (from) rpLast = from;
    if (q > 0) {
        if (rpLast === 'total') { const t = parseFloat($('#rpTotal').val()); $('#rpPrice').val(isNaN(t) ? '' : Math.round(t / q * 100) / 100); }
        else { const p = parseFloat($('#rpPrice').val()); $('#rpTotal').val(isNaN(p) ? '' : Math.round(p * q * 100) / 100); }
    }
    rpPreview();
}
function rpUnitPrice() {
    const q = parseFloat($('#rpQty').val()) || 0;
    if (rpLast === 'total' && q > 0) { const t = parseFloat($('#rpTotal').val()); return isNaN(t) ? 0 : t / q; }
    return parseFloat($('#rpPrice').val()) || 0;
}

function rpPreview() {
    const q = parseFloat($('#rpQty').val()) || 0, p = rpUnitPrice(), box = $('#rpPreview');
    if (!rpItem || q <= 0) { box.hide(); return; }
    const old = Math.max(0, rpItem.stock), tot = old + q;
    const nc = tot > 0 ? ((old * rpItem.cost) + (q * p)) / tot : p;
    let h = `<strong>${RP.s.total}: ${fmt(q * p)} ${RP.cur}</strong><br><span class="small">${RP.s.onHand}: ${fmt(rpItem.stock)} · ${RP.s.cost}: ${fmt(rpItem.cost)} → <strong>${RP.s.newCost}: ${fmt(nc)}</strong></span>`;
    const sell = rpItem.sell !== null ? (parseFloat($('#rpSell').val()) || 0) : null;
    if (sell !== null) {
        const pr = sell - nc, pc = sell > 0 ? Math.round(pr / sell * 100) : 0;
        h += `<br><span class="small ${pr < 0 ? 'text-danger fw-bold' : ''}">${RP.s.sells} ${fmt(sell)} · ${RP.s.costs} ${fmt(nc)} → <strong>${RP.s.perOne} ${fmt(pr)} (${pc}%)</strong>${pr < 0 ? ' — ' + RP.s.belowCost : ''}</span>`;
    } else {
        h += `<br><span class="small">${RP.s.costsPer} ${fmt(nc)} ${RP.s.per} ${safeOutput(rpItem.unit)} — ${RP.s.costFollows}</span>`;
    }
    box.html(h).show();
}

function rpPick(d) {
    rpItem = { stock: parseFloat(d.stock) || 0, cost: parseFloat(d.cost_price) || 0, unit: d.unit, sell: (d.selling_price === null || d.selling_price === undefined) ? null : parseFloat(d.selling_price) };
    $('#rpUnit').text('(' + d.unit + ')'); $('#rpPrice').val(rpItem.cost > 0 ? rpItem.cost : ''); $('#rpTotal').val(''); rpLast = 'price';
    $('#rpSellRow').toggle(rpItem.sell !== null); $('#rpSell').val(rpItem.sell !== null ? rpItem.sell : '');
    $('#rpItemInfo').text(RP.s.onHand + ': ' + fmt(rpItem.stock) + ' · ' + RP.s.cost + ': ' + fmt(rpItem.cost));
    rpSetMode('stock'); rpSync();
}
function rpReset() {
    $('#rpQty, #rpPrice, #rpTotal, #rpSell, #rpOtherName, #rpOtherAmount').val(''); $('#rpItem').val(null).trigger('change');
    rpItem = null; rpLast = 'price'; $('#rpUnit, #rpItemInfo').text(''); $('#rpSellRow').hide(); rpSetMode('stock'); rpPreview();
}

let rpDT = null;   // the Recent purchases DataTable (standard init; reloaded with clear().rows.add().draw())
function loadPurchases() {
    $.getJSON(RP.api + 'get_purchases.php', { warehouse_id: rpWh() }, function (res) {
        const rows = (res.success ? res.data : []).map(r => {
            const g = r.kind === 'grocery';
            return [
                safeOutput(r.date),
                '<span class="fw-semibold">' + safeOutput(r.item) + '</span>' + (g ? ' <span class="rp-tag rp-tag-grey">' + RP.s.notCounted + '</span>' : ''),
                g ? '—' : fmt(r.quantity) + ' ' + safeOutput(r.unit),
                g ? '—' : fmt(r.unit_cost), '<strong>' + fmt(r.total) + '</strong>', safeOutput(r.by || '')
            ];
        });
        rpDT.clear().rows.add(rows).draw();
    });
}

$(function () {
    rpDT = $('#rpTable').DataTable({
        responsive: false, scrollX: true, pageLength: 10, order: [[0, 'desc']], dom: 'rtip',
        columnDefs: [{ targets: [2, 3, 4], className: 'text-end' }],
        language: { emptyTable: RP.s.none, zeroRecords: RP.s.none }
    });
    $('#rpSupplier, #rpShop').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#rpItem').select2({
        theme: 'bootstrap-5', width: '100%', placeholder: RP.s.search, allowClear: true, minimumInputLength: 0,
        ajax: { url: RP.api + 'search_stock_items.php', dataType: 'json', delay: 250, data: p => ({ q: p.term || '', warehouse_id: rpWh() }),
                processResults: d => { const r = d.results || []; if (RP.otherAccount) r.push({ id: '__other', text: RP.s.other }); return { results: r }; }, cache: false }
    }).on('select2:select', function (e) {
        const d = e.params.data;
        if (d.id === '__other') { rpSetMode('other'); setTimeout(() => $('#rpOtherName').trigger('focus'), 100); } else { rpPick(d); }
    }).on('select2:clear', function () { rpItem = null; rpSetMode('stock'); $('#rpUnit, #rpItemInfo').text(''); $('#rpSellRow').hide(); rpPreview(); });
    $('#rpQty').on('input', () => rpSync());
    $('#rpPrice').on('input', () => rpSync('price'));
    $('#rpTotal').on('input', () => rpSync('total'));
    $('#rpSell').on('input', rpPreview);
    $('#rpShop').on('change', function () { $('#rpWarehouse').val(this.value); rpReset(); loadPurchases(); });
    <?php if ($preItem): ?>
    $.getJSON(RP.api + 'search_stock_items.php', { warehouse_id: rpWh(), q: <?= json_encode($preItem['product_name']) ?> }, function (r) {
        const m = (r.results || []).find(x => x.id === <?= (int)$preItem['product_id'] ?>); if (m) rpPick(m);
    });
    <?php endif; ?>

    function done(msgHtml) {
        Swal.fire({ icon: 'success', title: RP.s.saved, html: msgHtml, timer: 2600, showConfirmButton: false });
        $('#rpMessage').empty(); rpReset(); loadPurchases();
    }
    function fail(m) { $('#rpMessage').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(m) + '</div>'); }

    $('#rpForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $('#rpSave'), orig = btn.html();
        const finish = () => btn.prop('disabled', false).html(orig);
        if (rpMode === 'other') {
            const name = ($('#rpOtherName').val() || '').trim(), amt = parseFloat($('#rpOtherAmount').val()) || 0;
            if (!name) return fail(RP.s.enterName);
            if (amt <= 0) return fail(RP.s.enterAmount);
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + RP.s.saving);
            $.post(RP.expenseApi, { _csrf: RP.csrf, expense_account_id: RP.otherAccount, bank_account_id: RP.cashAccount, status: 'paid', amount: amt, expense_date: $('#rpDate').val(),
                                    warehouse_id: rpWh(), description: RP.s.groceryDesc + ': ' + name }, function (res) {
                if (res.success) done(safeOutput(name) + '<br><small>' + fmt(amt) + ' ' + RP.cur + '</small>'); else fail(res.message || RP.s.error);
            }, 'json').fail(() => fail(RP.s.serverError)).always(finish);
            return;
        }
        if (!$('#rpItem').val() || !rpItem) return fail(RP.s.pickItem);
        const q = parseFloat($('#rpQty').val()) || 0;
        if (q <= 0) return fail(RP.s.enterQty);
        if ($('#rpPrice').val() === '' && $('#rpTotal').val() === '') return fail(RP.s.enterPrice);
        const data = { _csrf: RP.csrf, warehouse_id: rpWh(), product_id: $('#rpItem').val(), quantity: q, date: $('#rpDate').val(),
                       supplier_id: $('#rpSupplier').val() || '', expiry_date: $('#rpExpiry').val() || '' };
        if (rpLast === 'total') data.total_paid = parseFloat($('#rpTotal').val()) || 0; else data.unit_cost = parseFloat($('#rpPrice').val()) || 0;
        if (rpItem.sell !== null && $('#rpSell').val() !== '') data.selling_price = $('#rpSell').val();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + RP.s.saving);
        $.post(RP.api + 'buy_stock.php', data, function (res) {
            if (res.success) done(`${safeOutput(res.product_name)}<br><small>${RP.s.costNow} ${fmt(res.new_cost)} ${RP.cur}</small>`); else fail(res.message || RP.s.error);
        }, 'json').fail(() => fail(RP.s.serverError)).always(finish);
    });

    $('#rpNewIngredient').on('click', function (e) { e.preventDefault(); $('#rpIngMsg').empty(); $('#rpIngForm')[0].reset(); new bootstrap.Modal(document.getElementById('rpIngModal')).show(); });
    $('#rpIngForm').on('submit', function (e) {
        e.preventDefault();
        $.post(RP.api + 'recipe.php', $(this).serialize() + '&action=new_ingredient&warehouse_id=' + rpWh() + '&_csrf=' + encodeURIComponent(RP.csrf), function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('rpIngModal')).hide();
                $('#rpItem').append(new Option(res.text, res.id, true, true)).trigger('change');
                rpPick({ stock: 0, cost_price: res.cost_price, unit: res.unit, selling_price: null });
            } else { $('#rpIngMsg').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message || RP.s.error) + '</div>'); }
        }, 'json').fail(() => $('#rpIngMsg').html('<div class="alert alert-danger py-2 mb-0">' + RP.s.serverError + '</div>'));
    });
    loadPurchases();
});
</script>
<?php endif; ?>
<?php
require_once 'footer.php';
ob_end_flush();
