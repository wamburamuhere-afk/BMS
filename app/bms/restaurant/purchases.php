<?php
/**
 * app/bms/restaurant/purchases.php — Purchases (restaurant focus pass, 2026-11-02).
 *
 * One place to buy what a restaurant counts:
 *   • Stock items — drinks and ingredients you COUNT: quantity, price, supplier, expiry, paid from. Adds stock, and the item's
 *     cost follows what you pay (weighted average), so the cost of every plate and the dashboard's profit stay true.
 *   • Groceries, not counted — market shopping you do not count (the existing "Buy ingredients": straight to cost of sales).
 * The page says which one to use, so the two are not mixed for the same ingredient.
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
?>

<div class="container-fluid mt-3 mt-md-4 rp-page">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
        <h4 class="mb-0 text-truncate"><i class="bi bi-basket text-primary me-2"></i><?= t('Purchases') ?></h4>
        <?php if (count($warehouses) > 1): ?>
        <select id="rpShop" class="form-select form-select-sm select2-static" style="max-width:260px;">
            <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['warehouse_id'] ?>"><?= caseFormat($w['warehouse_name']) ?></option><?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>

    <?php if (!$warehouses): ?>
    <div class="alert alert-warning"><i class="bi bi-info-circle me-1"></i><?= t('No warehouse in your scope is set to Restaurant or Hybrid mode yet. Switch a warehouse\'s POS Mode first.') ?></div>
    <?php elseif (!$can_buy): ?>
    <div class="alert alert-warning"><i class="bi bi-lock me-1"></i><?= t('You do not have permission to buy stock.') ?></div>
    <?php else: ?>

    <div class="rp-explain mb-3">
        <i class="bi bi-lightbulb"></i>
        <div>
            <strong><?= t('Two ways to buy — pick one for each item.') ?></strong>
            <span><?= t('Stock items are things you count (drinks, rice, oil): the stock goes up and the cost of every plate follows the price you pay. Groceries are market shopping you do not count: the amount goes straight to the cost of food sold.') ?></span>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="rp-card h-100">
                <div class="rp-card-h"><h6><i class="bi bi-box-seam me-1"></i><?= t('Stock items') ?> <span class="rp-tag"><?= t('counted') ?></span></h6></div>
                <form id="rpForm" class="p-3" autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="warehouse_id" id="rpWarehouse" value="<?= (int)$warehouses[0]['warehouse_id'] ?>">
                    <div id="rpMessage" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?= t('Item') ?> <span class="text-danger">*</span></label>
                        <select id="rpItem" name="product_id" class="form-select" required style="width:100%">
                            <?php if ($preItem): ?><option value="<?= (int)$preItem['product_id'] ?>" selected data-unit="<?= htmlspecialchars($preItem['unit']) ?>" data-cost="<?= (float)$preItem['cost_price'] ?>"><?= safe_output($preItem['product_name']) ?> (<?= htmlspecialchars($preItem['unit']) ?>)</option><?php endif; ?>
                        </select>
                        <div class="d-flex justify-content-between small mt-1">
                            <span class="text-muted" id="rpItemInfo"></span>
                            <?php if ($can_edit): ?><a href="#" id="rpNewIngredient"><i class="bi bi-plus-circle me-1"></i><?= t('New ingredient') ?></a><?php endif; ?>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('Quantity') ?> <span class="text-danger">*</span> <small class="text-muted" id="rpUnit"></small></label>
                            <input type="number" class="form-control" id="rpQty" name="quantity" min="0.001" step="0.001" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold"><?= t('Price for one') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="rpPrice" name="unit_cost" min="0" step="0.01" required>
                        </div>
                    </div>
                    <div class="rp-preview mt-3" id="rpPreview" style="display:none;"></div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold"><?= t('Paid from') ?></label>
                            <select name="paid_from_account_id" id="rpPaid" class="form-select select2-static" style="width:100%"><?= paidFromSelectOptions($pdo) ?></select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold"><?= t('Date') ?></label>
                            <input type="date" class="form-control" name="date" id="rpDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
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
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary" id="rpSave"><i class="bi bi-check-circle me-1"></i><?= t('Save purchase') ?></button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="rp-card h-100">
                <div class="rp-card-h"><h6><i class="bi bi-bag me-1"></i><?= t('Groceries') ?> <span class="rp-tag rp-tag-grey"><?= t('not counted') ?></span></h6></div>
                <div class="p-3">
                    <p class="small text-muted mb-2"><?= t('Use this for fresh market shopping you do not weigh or count — tomatoes, onions, a bundle of greens. The amount is recorded as the cost of food sold.') ?></p>
                    <p class="small text-muted"><?= t('Do not use it for an ingredient you also track as a stock item — that would count its cost twice.') ?></p>
                    <?php if (canCreate('expenses')): ?>
                    <button type="button" class="btn btn-outline-primary w-100" onclick="rbiOpen()"><i class="bi bi-basket me-1"></i><?= t('Buy Ingredients') ?></button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="rp-card mb-3">
        <div class="rp-card-h"><h6><i class="bi bi-clock-history me-1"></i><?= t('Recent purchases') ?></h6></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="rpTable">
            <thead><tr><th><?= t('Date') ?></th><th><?= t('Item') ?></th><th><?= t('Quantity') ?></th><th><?= t('Price for one') ?></th><th><?= t('Total') ?></th><th><?= t('Reference') ?></th><th><?= t('By') ?></th></tr></thead>
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
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label"><?= t('Unit') ?></label><select class="form-select" name="unit"><?php foreach ($units as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><label class="form-label"><?= t('Price for one') ?></label><input type="number" class="form-control" name="cost_price" min="0" step="0.01" value="0"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button><button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button></div>
            </form>
        </div></div>
    </div>
    <?php require ROOT_DIR . '/includes/restaurant/buy_ingredients_modal.php'; ?>
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
    csrf: <?= json_encode(csrf_token()) ?>,
    cur: <?= json_encode(getSetting('currency', 'TZS')) ?>,
    s: {
        search: <?= json_encode(t('Search the item...')) ?>, saving: <?= json_encode(t('Saving...')) ?>, error: <?= json_encode(t('Error')) ?>,
        serverError: <?= json_encode(t('Server error. Please try again.')) ?>, total: <?= json_encode(t('Total')) ?>,
        onHand: <?= json_encode(t('On hand')) ?>, cost: <?= json_encode(t('Cost')) ?>, newCost: <?= json_encode(t('New cost')) ?>,
        costFollows: <?= json_encode(t('The cost follows the prices you pay (average).')) ?>,
        pickItem: <?= json_encode(t('Select an item.')) ?>, saved: <?= json_encode(t('Purchase saved.')) ?>,
        costNow: <?= json_encode(t('Cost is now')) ?>, none: <?= json_encode(t('No purchases yet.')) ?>
    }
};
const fmt = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
let rpItem = null;   // { stock, cost, unit } of the chosen item

function rpWh() { return parseInt($('#rpShop').length ? $('#rpShop').val() : $('#rpWarehouse').val(), 10); }

function rpPreview() {
    const q = parseFloat($('#rpQty').val()) || 0, p = parseFloat($('#rpPrice').val()) || 0, box = $('#rpPreview');
    if (!rpItem || q <= 0) { box.hide(); return; }
    const old = Math.max(0, rpItem.stock), tot = old + q;
    const nc = tot > 0 ? ((old * rpItem.cost) + (q * p)) / tot : p;
    box.html(`<strong>${RP.s.total}: ${fmt(q * p)} ${RP.cur}</strong><br><span class="small">${RP.s.onHand}: ${fmt(rpItem.stock)} · ${RP.s.cost}: ${fmt(rpItem.cost)} → <strong>${RP.s.newCost}: ${fmt(nc)}</strong> — ${RP.s.costFollows}</span>`).show();
}

let rpDT = null;   // the Recent purchases DataTable (standard init; reloaded with clear().rows.add().draw())
function loadPurchases() {
    $.getJSON(RP.api + 'get_purchases.php', { warehouse_id: rpWh() }, function (res) {
        const rows = (res.success ? res.data : []).map(r => [
            safeOutput(r.date), '<span class="fw-semibold">' + safeOutput(r.item) + '</span>', fmt(r.quantity) + ' ' + safeOutput(r.unit),
            fmt(r.unit_cost), '<strong>' + fmt(r.total) + '</strong>', safeOutput(r.reference || ''), safeOutput(r.by || '')
        ]);
        rpDT.clear().rows.add(rows).draw();
    });
}

$(function () {
    rpDT = $('#rpTable').DataTable({
        responsive: false, scrollX: true, pageLength: 10, order: [[0, 'desc']], dom: 'rtip',
        columnDefs: [{ targets: [2, 3, 4], className: 'text-end' }],
        language: { emptyTable: RP.s.none, zeroRecords: RP.s.none }
    });
    $('#rpPaid, #rpSupplier, #rpShop').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#rpItem').select2({
        theme: 'bootstrap-5', width: '100%', placeholder: RP.s.search, allowClear: true, minimumInputLength: 0,
        ajax: { url: RP.api + 'search_stock_items.php', dataType: 'json', delay: 250, data: p => ({ q: p.term || '', warehouse_id: rpWh() }), processResults: d => ({ results: d.results || [] }), cache: false }
    }).on('select2:select', function (e) {
        const d = e.params.data; rpItem = { stock: parseFloat(d.stock) || 0, cost: parseFloat(d.cost_price) || 0, unit: d.unit };
        $('#rpUnit').text('(' + d.unit + ')'); $('#rpPrice').val(rpItem.cost > 0 ? rpItem.cost : '');
        $('#rpItemInfo').text(RP.s.onHand + ': ' + fmt(rpItem.stock) + ' · ' + RP.s.cost + ': ' + fmt(rpItem.cost)); rpPreview();
    }).on('select2:clear', function () { rpItem = null; $('#rpUnit, #rpItemInfo').text(''); rpPreview(); });
    $('#rpQty, #rpPrice').on('input', rpPreview);
    $('#rpShop').on('change', function () { $('#rpWarehouse').val(this.value); $('#rpItem').val(null).trigger('change'); rpItem = null; loadPurchases(); });
    <?php if ($preItem): ?>
    rpItem = { stock: 0, cost: <?= (float)$preItem['cost_price'] ?>, unit: <?= json_encode($preItem['unit']) ?> }; $('#rpUnit').text('(' + rpItem.unit + ')'); $('#rpPrice').val(rpItem.cost > 0 ? rpItem.cost : '');
    $.getJSON(RP.api + 'search_stock_items.php', { warehouse_id: rpWh(), q: <?= json_encode($preItem['product_name']) ?> }, function (r) {
        const m = (r.results || []).find(x => x.id === <?= (int)$preItem['product_id'] ?>); if (m) { rpItem.stock = m.stock; rpItem.cost = m.cost_price; $('#rpItemInfo').text(RP.s.onHand + ': ' + fmt(m.stock) + ' · ' + RP.s.cost + ': ' + fmt(m.cost_price)); rpPreview(); }
    });
    <?php endif; ?>

    $('#rpForm').on('submit', function (e) {
        e.preventDefault();
        if (!$('#rpItem').val()) { $('#rpMessage').html('<div class="alert alert-danger py-2 mb-0">' + RP.s.pickItem + '</div>'); return; }
        const btn = $('#rpSave'), orig = btn.html(); btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + RP.s.saving);
        $.post(RP.api + 'buy_stock.php', $(this).serialize(), function (res) {
            if (res.success) {
                Swal.fire({ icon: 'success', title: RP.s.saved, html: `${safeOutput(res.product_name)}<br><small>${RP.s.costNow} ${fmt(res.new_cost)} ${RP.cur}</small>`, timer: 2600, showConfirmButton: false });
                $('#rpMessage').empty(); $('#rpQty, #rpPrice').val(''); $('#rpItem').val(null).trigger('change'); rpItem = null; $('#rpUnit, #rpItemInfo').text(''); rpPreview(); loadPurchases();
            } else { $('#rpMessage').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message || RP.s.error) + '</div>'); }
        }, 'json').fail(() => $('#rpMessage').html('<div class="alert alert-danger py-2 mb-0">' + RP.s.serverError + '</div>')).always(() => btn.prop('disabled', false).html(orig));
    });

    $('#rpNewIngredient').on('click', function (e) { e.preventDefault(); $('#rpIngMsg').empty(); $('#rpIngForm')[0].reset(); new bootstrap.Modal(document.getElementById('rpIngModal')).show(); });
    $('#rpIngForm').on('submit', function (e) {
        e.preventDefault();
        $.post(RP.api + 'recipe.php', $(this).serialize() + '&action=new_ingredient&warehouse_id=' + rpWh() + '&_csrf=' + encodeURIComponent(RP.csrf), function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('rpIngModal')).hide();
                rpItem = { stock: 0, cost: parseFloat(res.cost_price) || 0, unit: res.unit };
                $('#rpItem').append(new Option(res.text, res.id, true, true)).trigger('change');
                $('#rpUnit').text('(' + res.unit + ')'); $('#rpPrice').val(rpItem.cost > 0 ? rpItem.cost : ''); $('#rpItemInfo').text(RP.s.onHand + ': 0 · ' + RP.s.cost + ': ' + fmt(rpItem.cost)); rpPreview();
            } else { $('#rpIngMsg').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message || RP.s.error) + '</div>'); }
        }, 'json').fail(() => $('#rpIngMsg').html('<div class="alert alert-danger py-2 mb-0">' + RP.s.serverError + '</div>'));
    });
    window.rbiSaved = function () { loadPurchases(); };
    loadPurchases();
});
</script>
<?php endif; ?>

<?php
require_once 'footer.php';
ob_end_flush();
