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
// Suppliers: needed to buy on credit, so anyone who may buy here can pick (or add, with just a name and phone) one.
$suppliers  = ($module_on && (canView('suppliers') || $can_buy))
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
        <h4 class="mb-0"><i class="bi bi-basket text-primary me-2"></i><?= t('Purchases') ?></h4>
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
                <div class="rp-card-h"><h6><i class="bi bi-box-arrow-in-down me-1"></i><?= t('Buy stock & ingredients') ?></h6></div>
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
                    <!-- Payment: Paid (default) · Partly paid · Credit — what is not paid now is owed to the supplier -->
                    <div class="rp-pay mt-3">
                        <label class="form-label fw-semibold d-block"><?= t('Payment') ?></label>
                        <div class="btn-group w-100 rp-pay-seg" role="group" aria-label="<?= htmlspecialchars(t('Payment')) ?>">
                            <input type="radio" class="btn-check" name="rpPayment" id="rpPayPaid" value="paid" checked>
                            <label class="btn btn-outline-primary" for="rpPayPaid"><i class="bi bi-check-circle me-1"></i><?= t('Paid') ?></label>
                            <input type="radio" class="btn-check" name="rpPayment" id="rpPayPartial" value="partial">
                            <label class="btn btn-outline-primary" for="rpPayPartial"><i class="bi bi-circle-half me-1"></i><?= t('Partly paid') ?></label>
                            <input type="radio" class="btn-check" name="rpPayment" id="rpPayCredit" value="credit">
                            <label class="btn btn-outline-primary" for="rpPayCredit"><i class="bi bi-hourglass-split me-1"></i><?= t('Credit') ?></label>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-6">
                                <label class="form-label"><?= t('Supplier') ?> <small class="text-muted" id="rpSupHint">(<?= t('optional') ?>)</small><span class="text-danger d-none" id="rpSupStar"> *</span></label>
                                <select name="supplier_id" id="rpSupplier" class="form-select" style="width:100%">
                                    <option value=""><?= t('-- Select --') ?></option>
                                    <?php foreach ($suppliers as $sp): ?><option value="<?= (int)$sp['supplier_id'] ?>"><?= caseFormat($sp['supplier_name']) ?></option><?php endforeach; ?>
                                </select>
                                <a href="#" class="small d-inline-block mt-1" id="rpNewSupplier"><i class="bi bi-person-plus me-1"></i><?= t('New supplier') ?></a>
                            </div>
                            <div class="col-6 col-md-3 rp-owe-only" style="display:none;" id="rpPaidNowWrap">
                                <label class="form-label"><?= t('Paid now') ?> <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="rpPaidNow" min="0" step="0.01" inputmode="decimal">
                            </div>
                            <div class="col-6 col-md-3 rp-owe-only" style="display:none;">
                                <label class="form-label"><?= t('Pay by') ?> <small class="text-muted">(<?= t('optional') ?>)</small></label>
                                <input type="date" class="form-control" id="rpDue">
                            </div>
                        </div>
                        <div class="rp-owe-note mt-2 rp-owe-only" id="rpOweNote" style="display:none;"></div>
                    </div>
                    <div id="rpMoreWrap">
                        <a class="small d-inline-block mt-2" data-bs-toggle="collapse" href="#rpMore" role="button"><i class="bi bi-sliders me-1"></i><?= t('More options') ?></a>
                        <div class="collapse" id="rpMore">
                            <div class="row g-2 mt-1">
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
    <!-- Suppliers you owe — pay part or all of a debt later -->
    <div class="rp-card mb-3" id="rpOwe">
        <div class="rp-card-h d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6><i class="bi bi-hourglass-split me-1"></i><?= t('Suppliers you owe') ?></h6>
            <span class="fw-bold" id="rpOweTotal"></span>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0" id="rpOweTable">
            <thead><tr><th><?= t('Supplier') ?></th><th><?= t('What') ?></th><th><?= t('Date') ?></th><th><?= t('Pay by') ?></th><th class="text-end"><?= t('Total') ?></th><th class="text-end"><?= t('Paid') ?></th><th class="text-end"><?= t('Owed') ?></th><th></th></tr></thead>
            <tbody><tr><td colspan="8" class="text-muted text-center py-3"><?= t('You owe no supplier anything.') ?></td></tr></tbody>
        </table></div>
    </div>

    <!-- New supplier: just a name and a phone number -->
    <div class="modal fade" id="rpSupModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-person-plus me-1"></i><?= t('New supplier') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form id="rpSupForm" autocomplete="off">
                <div class="modal-body">
                    <div id="rpSupMsg" class="mb-2"></div>
                    <div class="mb-3"><label class="form-label" for="rpSupName"><?= t('Supplier name') ?> <span class="text-danger">*</span></label><input type="text" class="form-control" id="rpSupName" maxlength="255" placeholder="<?= htmlspecialchars(t('e.g. Mama Neema Vegetables')) ?>"></div>
                    <div><label class="form-label" for="rpSupPhone"><?= t('Phone') ?></label><input type="text" class="form-control" id="rpSupPhone" maxlength="50" inputmode="tel" placeholder="<?= htmlspecialchars(t('e.g. 0712 345 678')) ?>"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button><button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Add supplier') ?></button></div>
            </form>
        </div></div>
    </div>

    <!-- Pay a supplier -->
    <div class="modal fade" id="rpPayModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i><?= t('Pay supplier') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form id="rpPayForm" autocomplete="off">
                <div class="modal-body">
                    <div id="rpPayMsg" class="mb-2"></div>
                    <div class="rp-preview mb-3" id="rpPayInfo"></div>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label" for="rpPayAmount"><?= t('Amount') ?> <span class="text-danger">*</span></label><input type="number" class="form-control" id="rpPayAmount" min="0.01" step="0.01" inputmode="decimal"></div>
                        <div class="col-6"><label class="form-label" for="rpPayDate"><?= t('Date') ?></label><input type="date" class="form-control" id="rpPayDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
                    </div>
                    <div class="form-text"><?= t('Paid from cash. Pay part now and the rest later, or all of it.') ?></div>
                    <div class="small mt-2" id="rpPayHistory"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button><button type="submit" class="btn btn-primary" id="rpPaySave"><i class="bi bi-check-circle me-1"></i><?= t('Pay') ?></button></div>
            </form>
        </div></div>
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
.rp-page .rp-owe-note { background:#fff7e6; border:1px solid #f5d38f; border-radius:10px; padding:8px 12px; font-size:.85rem; color:#7a4b00; }
.rp-page .rp-tag-owe { background:#fff1d6; color:#7a4b00; }
.rp-page .rp-late { font-size:.65rem; font-weight:700; border-radius:20px; padding:2px 8px; margin-left:4px; background:#b45309; color:#fff; }
.rp-page .rp-pay-seg .btn { font-weight:600; }
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
        groceryDesc: <?= json_encode(t('Ingredients')) ?>,
        optional: <?= json_encode('(' . t('optional') . ')') ?>, chooseSupplier: <?= json_encode(t('Choose the supplier you owe.')) ?>,
        enterPaidNow: <?= json_encode(t('Enter how much you paid now.')) ?>, paidNowLess: <?= json_encode(t('The amount paid now must be less than the total. If you paid it all, choose Paid.')) ?>,
        youWillOwe: <?= json_encode(t('You will owe %s to the supplier.')) ?>, payNow: <?= json_encode(t('Pay now %1$s · owe %2$s')) ?>,
        owedTag: <?= json_encode(t('Owed')) ?>, partlyTag: <?= json_encode(t('Partly paid')) ?>, creditTag: <?= json_encode(t('Credit')) ?>,
        overdue: <?= json_encode(t('Overdue')) ?>, pay: <?= json_encode(t('Pay')) ?>, noDebts: <?= json_encode(t('You owe no supplier anything.')) ?>,
        totalOwed: <?= json_encode(t('Total owed')) ?>, owedNow: <?= json_encode(t('Owed now')) ?>, paidSoFar: <?= json_encode(t('Paid so far')) ?>,
        enterSupName: <?= json_encode(t("Please enter the supplier's name.")) ?>, supAdded: <?= json_encode(t('Supplier added')) ?>,
        paidBefore: <?= json_encode(t('Payments made')) ?>, stillOwe: <?= json_encode(t('Still owed')) ?>
    }
};
RP.creditApi = RP.api + 'supplier_credit.php';
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
    $('#rpQty, #rpPrice, #rpTotal, #rpSell, #rpOtherName, #rpOtherAmount, #rpPaidNow, #rpDue').val(''); $('#rpItem').val(null).trigger('change');
    $('#rpSupplier').val('').trigger('change'); $('#rpPayPaid').prop('checked', true);
    rpItem = null; rpLast = 'price'; $('#rpUnit, #rpItemInfo').text(''); $('#rpSellRow').hide(); rpSetMode('stock'); rpPreview(); rpPayUi();
}

// ── Payment: Paid (default) · Partly paid · Credit ──
function rpPayment() { return $('input[name="rpPayment"]:checked').val() || 'paid'; }
function rpPurchaseTotal() {
    if (rpMode === 'other') return parseFloat($('#rpOtherAmount').val()) || 0;
    return (parseFloat($('#rpQty').val()) || 0) * rpUnitPrice();
}
function rpPayUi() {
    const p = rpPayment(), owe = p !== 'paid';
    $('.rp-owe-only').toggle(owe); $('#rpPaidNowWrap').toggle(p === 'partial');
    $('#rpSupHint').toggle(!owe); $('#rpSupStar').toggleClass('d-none', !owe);
    if (!owe) return;
    const total = Math.round(rpPurchaseTotal() * 100) / 100, paid = p === 'partial' ? (parseFloat($('#rpPaidNow').val()) || 0) : 0;
    const owed = Math.max(0, total - paid);
    $('#rpOweNote').html(total > 0 ? (p === 'partial' ? RP.s.payNow.replace('%1$s', '<strong>' + fmt(paid) + '</strong>').replace('%2$s', '<strong>' + fmt(owed) + ' ' + RP.cur + '</strong>')
                                                       : RP.s.youWillOwe.replace('%s', '<strong>' + fmt(owed) + ' ' + RP.cur + '</strong>')) : RP.s.youWillOwe.replace('%s', '…'));
}
/** Payment fields for a save, or an error message. */
function rpPayData() {
    const p = rpPayment(), d = { payment: p };
    if (p === 'paid') return d;
    if (!$('#rpSupplier').val()) return { error: RP.s.chooseSupplier };
    if (p === 'partial') {
        const paid = parseFloat($('#rpPaidNow').val()) || 0, total = rpPurchaseTotal();
        if (paid <= 0) return { error: RP.s.enterPaidNow };
        if (paid >= total - 0.004) return { error: RP.s.paidNowLess };
        d.amount_paid = paid;
    }
    d.due_date = $('#rpDue').val() || '';
    return d;
}

// ── Suppliers you owe ──
let rpDebts = {};
function loadDebts() {
    $.getJSON(RP.creditApi, { action: 'debts', warehouse_id: rpWh() }, function (res) {
        const rows = res.success ? res.data : [], body = $('#rpOweTable tbody').empty();
        rpDebts = {};
        if (!rows.length) { body.append('<tr><td colspan="8" class="text-muted text-center py-3">' + RP.s.noDebts + '</td></tr>'); $('#rpOweTotal').text(''); return; }
        rows.forEach(r => {
            rpDebts[r.id] = r;
            body.append('<tr><td><span class="fw-semibold">' + safeOutput(r.supplier_name) + '</span>' + (r.phone ? '<br><small class="text-muted">' + safeOutput(r.phone) + '</small>' : '') + '</td>'
                + '<td>' + safeOutput(r.what) + '</td><td>' + safeOutput(r.date_raised) + '</td>'
                + '<td>' + (r.due_date ? safeOutput(r.due_date) : '—') + (+r.overdue ? '<span class="rp-late">' + RP.s.overdue + '</span>' : '') + '</td>'
                + '<td class="text-end">' + fmt(r.amount) + '</td><td class="text-end">' + fmt(r.amount_paid) + '</td><td class="text-end fw-bold">' + fmt(r.owed) + '</td>'
                + '<td class="text-end"><button type="button" class="btn btn-sm btn-primary rp-pay-btn" data-id="' + (+r.id) + '"><i class="bi bi-cash-coin me-1"></i>' + RP.s.pay + '</button></td></tr>');
        });
        $('#rpOweTotal').text(RP.s.totalOwed + ': ' + fmt(res.total) + ' ' + RP.cur);
    });
}

let rpDT = null;   // the Recent purchases DataTable (standard init; reloaded with clear().rows.add().draw())
function loadPurchases() {
    $.getJSON(RP.api + 'get_purchases.php', { warehouse_id: rpWh() }, function (res) {
        const rows = (res.success ? res.data : []).map(r => {
            const g = r.kind === 'grocery';
            const payTag = r.payment === 'credit' ? ' <span class="rp-tag rp-tag-owe">' + RP.s.creditTag + ' · ' + RP.s.owedTag + ' ' + fmt(r.owed) + '</span>'
                         : r.payment === 'partial' ? ' <span class="rp-tag rp-tag-owe">' + RP.s.partlyTag + ' · ' + RP.s.owedTag + ' ' + fmt(r.owed) + '</span>' : '';
            return [
                safeOutput(r.date),
                '<span class="fw-semibold">' + safeOutput(r.item) + '</span>' + (g ? ' <span class="rp-tag rp-tag-grey">' + RP.s.notCounted + '</span>' : '') + payTag,
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
    $('#rpShop').on('change', function () { $('#rpWarehouse').val(this.value); rpReset(); loadPurchases(); loadDebts(); });
    <?php if ($preItem): ?>
    $.getJSON(RP.api + 'search_stock_items.php', { warehouse_id: rpWh(), q: <?= json_encode($preItem['product_name']) ?> }, function (r) {
        const m = (r.results || []).find(x => x.id === <?= (int)$preItem['product_id'] ?>); if (m) rpPick(m);
    });
    <?php endif; ?>

    function done(msgHtml) {
        Swal.fire({ icon: 'success', title: RP.s.saved, html: msgHtml, timer: 2600, showConfirmButton: false });
        $('#rpMessage').empty(); rpReset(); loadPurchases(); loadDebts();
    }

    $('input[name="rpPayment"]').on('change', rpPayUi);
    $('#rpPaidNow, #rpQty, #rpPrice, #rpTotal, #rpOtherAmount').on('input', rpPayUi);

    // New supplier — just a name and a phone number
    $('#rpNewSupplier').on('click', function (e) { e.preventDefault(); $('#rpSupMsg').empty(); $('#rpSupName, #rpSupPhone').val(''); new bootstrap.Modal(document.getElementById('rpSupModal')).show(); setTimeout(() => $('#rpSupName').trigger('focus'), 300); });
    $('#rpSupForm').on('submit', function (e) {
        e.preventDefault();
        const name = ($('#rpSupName').val() || '').trim();
        if (!name) { $('#rpSupMsg').html('<div class="alert alert-danger py-2 mb-0">' + RP.s.enterSupName + '</div>'); return; }
        $.post(RP.creditApi, { _csrf: RP.csrf, action: 'add_supplier', name: name, phone: ($('#rpSupPhone').val() || '').trim() }, function (res) {
            if (!res.success) { $('#rpSupMsg').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message || RP.s.error) + '</div>'); return; }
            if (!$('#rpSupplier option[value="' + (+res.supplier_id) + '"]').length) $('#rpSupplier').append(new Option(res.supplier_name, res.supplier_id));
            $('#rpSupplier').val(String(res.supplier_id)).trigger('change');
            bootstrap.Modal.getInstance(document.getElementById('rpSupModal')).hide();
        }, 'json').fail(() => $('#rpSupMsg').html('<div class="alert alert-danger py-2 mb-0">' + RP.s.serverError + '</div>'));
    });

    // Pay a supplier — part or all of what is owed
    let rpPayBill = null;
    $('#rpOweTable').on('click', '.rp-pay-btn', function () {
        const r = rpDebts[$(this).data('id')]; if (!r) return;
        rpPayBill = r;
        $('#rpPayMsg, #rpPayHistory').empty();
        $('#rpPayInfo').html('<strong>' + safeOutput(r.supplier_name) + '</strong> · ' + safeOutput(r.what) + '<br><span class="small">' + RP.s.total + ': ' + fmt(r.amount)
            + ' · ' + RP.s.paidSoFar + ': ' + fmt(r.amount_paid) + ' · <strong>' + RP.s.owedNow + ': ' + fmt(r.owed) + ' ' + RP.cur + '</strong></span>');
        $('#rpPayAmount').val(r.owed).attr('max', r.owed);
        new bootstrap.Modal(document.getElementById('rpPayModal')).show();
        $.getJSON(RP.creditApi, { action: 'payments', bill_id: r.id }, function (res) {
            if (res.success && res.data.length) $('#rpPayHistory').html('<span class="text-muted">' + RP.s.paidBefore + ':</span> ' + res.data.map(p => safeOutput(p.payment_date) + ' — ' + fmt(p.amount)).join(' · '));
        });
    });
    $('#rpPayForm').on('submit', function (e) {
        e.preventDefault();
        if (!rpPayBill) return;
        const btn = $('#rpPaySave'), orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + RP.s.saving);
        $.post(RP.creditApi, { _csrf: RP.csrf, action: 'pay', bill_id: rpPayBill.id, amount: $('#rpPayAmount').val(), date: $('#rpPayDate').val() }, function (res) {
            if (!res.success) { $('#rpPayMsg').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message || RP.s.error) + '</div>'); return; }
            bootstrap.Modal.getInstance(document.getElementById('rpPayModal')).hide();
            Swal.fire({ icon: 'success', title: res.message, html: res.remaining > 0 ? RP.s.stillOwe + ': <strong>' + fmt(res.remaining) + ' ' + RP.cur + '</strong>' : '', timer: 2600, showConfirmButton: false });
            loadDebts(); loadPurchases();
        }, 'json').fail(() => $('#rpPayMsg').html('<div class="alert alert-danger py-2 mb-0">' + RP.s.serverError + '</div>')).always(() => btn.prop('disabled', false).html(orig));
    });
    function fail(m) { $('#rpMessage').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(m) + '</div>'); }

    $('#rpForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $('#rpSave'), orig = btn.html();
        const finish = () => btn.prop('disabled', false).html(orig);
        const pay = rpPayData();
        if (rpMode === 'other') {
            const name = ($('#rpOtherName').val() || '').trim(), amt = parseFloat($('#rpOtherAmount').val()) || 0;
            if (!name) return fail(RP.s.enterName);
            if (amt <= 0) return fail(RP.s.enterAmount);
            if (pay.error) return fail(pay.error);
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + RP.s.saving);
            if (pay.payment !== 'paid') {      // partly paid / credit: a supplier bill (fully paid keeps the expense path below)
                $.post(RP.creditApi, Object.assign({ _csrf: RP.csrf, action: 'buy_grocery', name: name, amount: amt, warehouse_id: rpWh(), date: $('#rpDate').val(),
                                                     supplier_id: $('#rpSupplier').val() }, pay), function (res) {
                    if (res.success) done(safeOutput(name) + '<br><small>' + RP.s.owedTag + ': ' + fmt(res.owed) + ' ' + RP.cur + '</small>'); else fail(res.message || RP.s.error);
                }, 'json').fail(() => fail(RP.s.serverError)).always(finish);
                return;
            }
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
        if (pay.error) return fail(pay.error);
        const data = Object.assign({ _csrf: RP.csrf, warehouse_id: rpWh(), product_id: $('#rpItem').val(), quantity: q, date: $('#rpDate').val(),
                       supplier_id: $('#rpSupplier').val() || '', expiry_date: $('#rpExpiry').val() || '' }, pay);
        if (rpLast === 'total') data.total_paid = parseFloat($('#rpTotal').val()) || 0; else data.unit_cost = parseFloat($('#rpPrice').val()) || 0;
        if (rpItem.sell !== null && $('#rpSell').val() !== '') data.selling_price = $('#rpSell').val();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>' + RP.s.saving);
        $.post(RP.api + 'buy_stock.php', data, function (res) {
            if (res.success) done(`${safeOutput(res.product_name)}<br><small>${RP.s.costNow} ${fmt(res.new_cost)} ${RP.cur}</small>` + (res.owed > 0 ? `<br><small>${RP.s.owedTag}: ${fmt(res.owed)} ${RP.cur}</small>` : '')); else fail(res.message || RP.s.error);
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
    loadDebts();
    rpPayUi();
    if (location.hash === '#rpOwe') setTimeout(() => document.getElementById('rpOwe').scrollIntoView({ behavior: 'smooth' }), 400);
});
</script>
<?php endif; ?>
<?php
require_once 'footer.php';
ob_end_flush();
