<?php
/**
 * app/bms/restaurant/purchases.php — Purchases (restaurant focus pass, 2026-11-02; simpler, smarter screen 2026-11-02).
 *
 * ONE form, the way the retail Receive Stock works — pick what you bought, say how many and what it cost:
 *   • an item you COUNT (a drink, rice, oil, meat): stock goes up and its cost follows what you pay (weighted average), so the
 *     cost of every plate and the profit stay true. Price for one OR the total paid (a goat, a crate, a sack). An item you sell
 *     also shows its selling price and the profit it leaves.
 *   • "Something else" (tomatoes, a bundle of greens) — not counted: just the amount, straight to the cost of food sold.
 * No account is ever asked — it is paid from the default cash account, like every other Smart POS screen.
 *
 * Screen (2026-11-02): Counted item | Something else switch on top; a live Summary with the one Save button beside the form
 * (below it on a phone — no extra bar over the phone's bottom menu); quiet checks that only speak up when something looks wrong — a price far from the
 * average cost, the same purchase saved twice, a part of a count unit (2.5 crates), a "Something else" that is really a counted
 * item (it would count twice). A new ingredient is added straight from the search. Recent purchases: S/No., full width, a total
 * row, All / Today / This week, and "Buy again".
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
// Opened from a notification: ?shop=W (low stock in that shop) or ?bill=N (pay that supplier bill) — the bill's shop.
// Only a shop in the user's own list is ever chosen; otherwise the page opens as usual.
$focusBill = (int)($_GET['bill'] ?? 0);
$focusShop = (int)($_GET['shop'] ?? 0);
if ($focusBill > 0) {
    $fb = $pdo->prepare("SELECT warehouse_id FROM supplier_invoices WHERE id = ? AND status != 'deleted'");
    $fb->execute([$focusBill]);
    $focusShop = (int)$fb->fetchColumn();
}
if ($warehouses) {
    $okShops = array_map(fn($w) => (int)$w['warehouse_id'], $warehouses);
    if (!in_array($focusShop, $okShops, true)) { $focusShop = 0; $focusBill = 0; }
    if ($focusShop) usort($warehouses, fn($a, $b) => ((int)$b['warehouse_id'] === $focusShop) <=> ((int)$a['warehouse_id'] === $focusShop));   // that shop first = chosen
}
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
$cur = getSetting('currency', 'TZS');
require_once __DIR__ . '/../../../includes/restaurant/form_kit.php';
$rfCur = htmlspecialchars(rfMoneyPrefix());   // money fields show the company currency in front (TSh by default)
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

    <?php rfKitAssets(); ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="rp-card h-100">
                <div class="rp-card-h d-flex justify-content-between align-items-center">
                    <h6><i class="ri ri-shopping-basket me-1"></i><?= t('Buy stock & ingredients') ?></h6>
                    <a class="rp-help" data-bs-toggle="collapse" href="#rpHelp" role="button" aria-expanded="false" title="<?= htmlspecialchars(t('How it works')) ?>"><i class="bi bi-question-lg"></i></a>
                </div>
                <form id="rpForm" class="p-3" autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="warehouse_id" id="rpWarehouse" value="<?= (int)$warehouses[0]['warehouse_id'] ?>">
                    <div class="collapse" id="rpHelp">
                        <div class="rp-explain mb-3">
                            <i class="bi bi-lightbulb"></i>
                            <div>
                                <strong><?= t('How it works') ?></strong>
                                <span><?= t('Drinks, rice, oil and meat are counted: stock goes up, and the cost of every plate follows the price you pay.') ?></span>
                                <span class="mt-2"><?= t('Not on the list — tomatoes, a bundle of greens? Choose "Something else". It is not counted; the amount is the cost of food sold.') ?></span>
                            </div>
                        </div>
                    </div>
                    <div id="rpMessage" class="mb-2"></div>

                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="bi bi-basket"></i><?= t('What did you buy?') ?></div>
                        <?php if ($other_account): ?>
                        <!-- What kind of buy: counted (stock) or something else (not counted) -->
                        <div class="btn-group w-100 rp-seg rp-kind mb-3" role="group" aria-label="<?= htmlspecialchars(t('What did you buy?')) ?>">
                            <input type="radio" class="btn-check" name="rpKind" id="rpKindStock" value="stock" checked>
                            <label class="btn btn-outline-primary" for="rpKindStock"><i class="bi bi-box-seam me-1"></i><?= t('Counted item') ?><small><?= t('stock goes up') ?></small></label>
                            <input type="radio" class="btn-check" name="rpKind" id="rpKindOther" value="other">
                            <label class="btn btn-outline-primary" for="rpKindOther"><i class="bi bi-basket me-1"></i><?= t('Something else') ?><small><?= t('not counted') ?></small></label>
                        </div>
                        <?php endif; ?>

                        <!-- A counted item -->
                        <div id="rpStockFields">
                            <div class="rf-grid">
                                <div class="rf-field rf-full">
                                    <label class="form-label" for="rpItem"><?= t('Item') ?><span class="rf-req">*</span></label>
                                    <select id="rpItem" name="product_id" class="form-select" style="width:100%">
                                        <?php if ($preItem): ?><option value="<?= (int)$preItem['product_id'] ?>" selected><?= safe_output($preItem['product_name']) ?> (<?= htmlspecialchars($preItem['unit']) ?>)</option><?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Something else (not counted) -->
                        <div id="rpOtherFields" style="display:none;">
                            <div class="rf-grid">
                                <div class="rf-field rf-full">
                                    <label class="form-label" for="rpOtherName"><?= t('What is it?') ?><span class="rf-req">*</span></label>
                                    <input type="text" class="form-control" id="rpOtherName" maxlength="200" placeholder="<?= htmlspecialchars(t('e.g. tomatoes, onions, a bundle of greens')) ?>">
                                    <div class="rp-match mt-2" id="rpOtherMatch" style="display:none;"></div>
                                </div>
                                <div class="rf-field">
                                    <label class="form-label" for="rpOtherAmount"><?= t('Amount paid') ?><span class="rf-req">*</span></label>
                                    <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rfCur ?></span><input type="number" class="form-control rp-num" id="rpOtherAmount" min="1" step="any" inputmode="decimal"></div>
                                </div>
                                <?php if ($can_buy): // restaurant costing plan, Phase 3: bought for several days → kept as stock ?>
                                <div class="rf-field rf-full">
                                    <span class="rf-label"><?= t('Will you use it all today?') ?></span>
                                    <div class="btn-group w-100 rp-seg" role="group" aria-label="<?= htmlspecialchars(t('Will you use it all today?')) ?>">
                                        <input type="radio" class="btn-check" name="rpUse" id="rpUseAll" value="all" checked>
                                        <label class="btn btn-outline-primary" for="rpUseAll"><i class="bi bi-check2 me-1"></i><?= t('Yes, all today') ?></label>
                                        <input type="radio" class="btn-check" name="rpUse" id="rpUseKeep" value="keep">
                                        <label class="btn btn-outline-primary" for="rpUseKeep"><i class="bi bi-box-seam me-1"></i><?= t('No, some will remain') ?></label>
                                    </div>
                                    <div class="rf-help" id="rpUseHelp"><?= t('Rice, oil or flour for several days? Choose "No": it is kept as stock and its cost counts on the days you cook it (Cooking plan).') ?></div>
                                </div>
                                <div class="rf-field rp-keep-only" style="display:none;">
                                    <label class="form-label" for="rpKeepQty"><?= t('How much did you buy?') ?><span class="rf-req">*</span></label>
                                    <div class="input-group">
                                        <input type="number" class="form-control rp-num" id="rpKeepQty" min="0.001" step="any" inputmode="decimal">
                                        <select class="form-select" id="rpKeepUnit" style="max-width:110px;">
                                            <?php foreach (['kg', 'litre', 'pcs', 'g', 'ml', 'bag', 'packet', 'dozen'] as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="rf-sec" id="rpQtySec">
                        <div class="rf-sec-title"><i class="bi bi-calculator"></i><?= t('Quantity & price') ?></div>
                        <div class="rp-grid3">
                            <div class="rf-field">
                                <label class="form-label" for="rpQty"><?= t('Quantity') ?><span class="rf-req">*</span></label>
                                <div class="input-group"><input type="number" class="form-control rp-num" id="rpQty" min="0.001" step="0.001" inputmode="decimal"><span class="input-group-text" id="rpUnit">—</span></div>
                            </div>
                            <div class="rf-field">
                                <label class="form-label" for="rpPrice"><?= t('Price for one') ?></label>
                                <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rfCur ?></span><input type="number" class="form-control rp-num" id="rpPrice" min="0" step="0.01" inputmode="decimal"></div>
                                <div class="rp-hint" id="rpPriceHint"></div>
                            </div>
                            <div class="rf-field">
                                <label class="form-label" for="rpTotal"><span class="rp-or"><?= t('or') ?></span> <?= t('Total paid') ?></label>
                                <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rfCur ?></span><input type="number" class="form-control rp-num" id="rpTotal" min="0" step="0.01" inputmode="decimal"></div>
                            </div>
                        </div>
                        <!-- An item sold on the Menu: its selling price, changed only when you tap the pencil -->
                        <div class="rp-sell mt-3" id="rpSellRow" style="display:none;">
                            <i class="bi bi-tag text-primary"></i> <?= t('Selling price') ?> <strong id="rpSellTxt"></strong>
                            <a href="#" id="rpSellEdit" class="ms-1" title="<?= htmlspecialchars(t('Change')) ?>"><i class="bi bi-pencil"></i></a>
                            <div class="mt-2" id="rpSellBox" style="display:none;">
                                <div class="input-group rf-money" style="max-width:260px;"><span class="input-group-text rf-cur"><?= $rfCur ?></span><input type="number" class="form-control rp-num" id="rpSell" min="0" step="0.01" inputmode="decimal"></div>
                            </div>
                        </div>
                        <div id="rpMoreWrap">
                            <a class="small d-inline-block mt-3" data-bs-toggle="collapse" href="#rpMore" role="button"><i class="bi bi-sliders me-1"></i><?= t('More options') ?></a>
                            <div class="collapse" id="rpMore">
                                <div class="rf-grid mt-2">
                                    <div class="rf-field">
                                        <label class="form-label" for="rpExpiry"><?= t('Expiry date') ?> <span class="rf-opt">(<?= t('optional') ?>)</span></label>
                                        <input type="date" class="form-control" name="expiry_date" id="rpExpiry">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment: Paid (default) · Partly paid · Credit — what is not paid now is owed to the supplier -->
                    <div class="rf-sec rp-pay">
                        <div class="rf-sec-title"><i class="bi bi-wallet2"></i><?= t('Payment') ?></div>
                        <div class="btn-group w-100 rp-pay-seg mb-3" role="group" aria-label="<?= htmlspecialchars(t('Payment')) ?>">
                            <input type="radio" class="btn-check" name="rpPayment" id="rpPayPaid" value="paid" checked>
                            <label class="btn btn-outline-primary" for="rpPayPaid"><i class="bi bi-check-circle me-1"></i><?= t('Paid') ?></label>
                            <input type="radio" class="btn-check" name="rpPayment" id="rpPayPartial" value="partial">
                            <label class="btn btn-outline-primary" for="rpPayPartial"><i class="bi bi-circle-half me-1"></i><?= t('Partly paid') ?></label>
                            <input type="radio" class="btn-check" name="rpPayment" id="rpPayCredit" value="credit">
                            <label class="btn btn-outline-primary" for="rpPayCredit"><i class="bi bi-hourglass-split me-1"></i><?= t('Credit') ?></label>
                        </div>
                        <div class="rf-grid">
                            <div class="rf-field rf-full">
                                <label class="form-label" for="rpSupplier"><?= t('Supplier') ?> <span class="rf-opt" id="rpSupHint">(<?= t('optional') ?>)</span><span class="rf-req d-none" id="rpSupStar">*</span></label>
                                <select name="supplier_id" id="rpSupplier" class="form-select" style="width:100%">
                                    <option value=""><?= t('-- Select --') ?></option>
                                    <?php foreach ($suppliers as $sp): ?><option value="<?= (int)$sp['supplier_id'] ?>"><?= caseFormat($sp['supplier_name']) ?></option><?php endforeach; ?>
                                </select>
                                <a href="#" class="small d-inline-block mt-1" id="rpNewSupplier"><i class="bi bi-person-plus me-1"></i><?= t('New supplier') ?></a>
                            </div>
                            <div class="rf-field rp-owe-only" style="display:none;" id="rpNowAmtWrap">
                                <label class="form-label" for="rpNowAmt"><?= t('Paid now') ?><span class="rf-req">*</span></label>
                                <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rfCur ?></span><input type="number" class="form-control" id="rpNowAmt" min="0" step="0.01" inputmode="decimal"></div>
                            </div>
                            <div class="rf-field rp-owe-only" style="display:none;">
                                <label class="form-label" for="rpDue"><?= t('Pay by') ?> <span class="rf-opt">(<?= t('optional') ?>)</span></label>
                                <input type="date" class="form-control" id="rpDue">
                            </div>
                        </div>
                        <div class="rp-owe-note mt-2 rp-owe-only" id="rpOweNote" style="display:none;"></div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary: what you are about to save, live — and the one Save button -->
        <div class="col-lg-4">
            <div class="rp-sum">
                <div class="rp-sum-h"><i class="bi bi-receipt me-1"></i><?= t('Summary') ?></div>
                <div class="rp-sum-b" id="rpSumBody"></div>
                <div class="rp-sum-date">
                    <label for="rpDate"><i class="bi bi-calendar3 me-1"></i><?= t('Date') ?></label>
                    <input type="date" class="form-control form-control-sm" form="rpForm" name="date" id="rpDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="rp-sum-total"><span><?= t('Total') ?></span><strong id="rpSumTotal">0 <?= $rfCur ?></strong></div>
                <div class="p-3 pt-0">
                    <button type="submit" form="rpForm" class="btn btn-primary btn-lg w-100 rp-save" id="rpSave"><i class="bi bi-check-circle me-1"></i><?= t('Save purchase') ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab strip: Recent purchases | Suppliers you owe -->
    <div class="rp-tabs mb-0">
        <button class="rp-tab active" id="rpBtnRecent" data-pane="rpRecent" type="button">
            <i class="bi bi-clock-history me-1"></i><?= t('Recent purchases') ?> <span class="rp-count" id="rpCount">0</span>
        </button>
        <button class="rp-tab" id="rpBtnOwe" data-pane="rpOwe" type="button">
            <i class="bi bi-hourglass-split me-1"></i><?= t('Suppliers you owe') ?> <span class="rp-owe-ct" id="rpOweBadge"></span>
        </button>
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

    <!-- Pay a supplier (restaurant form layout) -->
    <div class="modal fade rf-modal" id="rpPayModal" tabindex="-1" aria-labelledby="rpPayTitle">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="rpPayTitle"><i class="bi bi-cash-coin"></i><?= t('Pay supplier') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button></div>
            <form id="rpPayForm" autocomplete="off">
                <div class="modal-body">
                    <div id="rpPayMsg" class="mb-2"></div>
                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="bi bi-receipt"></i><?= t('Bill') ?></div>
                        <div class="rp-preview" id="rpPayInfo"></div>
                    </div>
                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="bi bi-cash-coin"></i><?= t('Payment') ?></div>
                        <div class="rf-grid">
                            <div class="rf-field">
                                <label class="form-label" for="rpPayAmount"><?= t('Amount') ?><span class="rf-req">*</span></label>
                                <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rfCur ?></span><input type="number" class="form-control" id="rpPayAmount" min="0.01" step="0.01" inputmode="decimal"></div>
                            </div>
                            <div class="rf-field">
                                <label class="form-label" for="rpPayDate"><?= t('Date') ?></label>
                                <input type="date" class="form-control" id="rpPayDate" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="rf-help"><?= t('The money comes from cash. Pay part now and the rest later, or all of it.') ?></div>
                        <div class="small mt-2" id="rpPayHistory"></div>
                    </div>
                </div>
                <div class="modal-footer rf-foot"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button><button type="submit" class="btn btn-primary" id="rpPaySave"><i class="bi bi-check-circle me-1"></i><?= t('Pay') ?></button></div>
            </form>
        </div></div>
    </div>

    <div class="rp-card mb-3 rp-tab-card" id="rpRecent">
        <div class="rp-card-h d-flex justify-content-end align-items-center flex-wrap gap-2">
            <div class="btn-group btn-group-sm rp-seg rp-range" role="group" aria-label="<?= htmlspecialchars(t('Recent purchases')) ?>">
                <input type="radio" class="btn-check" name="rpRange" id="rpRangeAll" value="all" checked>
                <label class="btn btn-outline-primary" for="rpRangeAll"><?= t('All') ?></label>
                <input type="radio" class="btn-check" name="rpRange" id="rpRangeToday" value="today">
                <label class="btn btn-outline-primary" for="rpRangeToday"><?= t('Today') ?></label>
                <input type="radio" class="btn-check" name="rpRange" id="rpRangeWeek" value="week">
                <label class="btn btn-outline-primary" for="rpRangeWeek"><?= t('This week') ?></label>
            </div>
        </div>
        <div class="rp-table-wrap">
            <table class="table table-sm table-hover align-middle mb-0 w-100 rp-table" id="rpTable" style="width:100%">
                <thead><tr>
                    <th><?= t('S/No.') ?></th><th><?= t('Item') ?></th><th><?= t('Date') ?></th><th class="text-end"><?= t('Quantity') ?></th>
                    <th class="text-end"><?= t('Price for one') ?></th><th class="text-end"><?= t('Total') ?></th><th><?= t('By') ?></th><th class="text-end"><?= t('Actions') ?></th>
                </tr></thead>
                <tbody></tbody>
                <tfoot><tr><th></th><th></th><th></th><th></th><th class="text-end"><?= t('Total') ?></th><th class="text-end" id="rpFootTotal">0</th><th></th><th></th></tr></tfoot>
            </table>
        </div>
    </div>
    <div class="rp-card mb-3 rp-tab-card" id="rpOwe" style="display:none;">
        <div class="rp-card-h d-flex justify-content-end align-items-center flex-wrap gap-2">
            <span class="fw-bold" id="rpOweTotal"></span>
        </div>
        <div class="rp-table-wrap"><table class="table table-sm table-hover align-middle mb-0 w-100 rp-table" id="rpOweTable" style="width:100%">
            <thead><tr>
                <th style="width:48px;"><?= t('S/No.') ?></th><th><?= t('Supplier') ?></th><th><?= t('What') ?></th><th><?= t('Date') ?></th><th><?= t('Pay by') ?></th>
                <th class="text-end"><?= t('Total') ?></th><th class="text-end"><?= t('Paid') ?></th><th class="text-end"><?= t('Owed') ?></th><th></th>
            </tr></thead>
            <tbody></tbody>
        </table></div>
    </div>

    <!-- New ingredient (opened from the search: "+ Add … as a new ingredient") -->
    <div class="modal fade" id="rpIngModal" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content">
            <div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-plus-circle me-1"></i><?= t('New ingredient') ?></h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <form id="rpIngForm" autocomplete="off">
                <div class="modal-body">
                    <div id="rpIngMsg" class="mb-2"></div>
                    <div class="mb-3"><label class="form-label" for="rpIngName"><?= t('Name') ?> <span class="text-danger">*</span></label><input type="text" class="form-control" name="name" id="rpIngName" required maxlength="100"></div>
                    <div><label class="form-label" for="rpIngUnit"><?= t('Unit') ?></label><select class="form-select" name="unit" id="rpIngUnit"><?php foreach ($units as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?></select></div>
                    <input type="hidden" name="cost_price" value="0"><!-- the price comes from the purchase itself -->
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button><button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button></div>
            </form>
        </div></div>
    </div>
    <?php endif; ?>
</div>

<style>
.rp-page .rp-grid3 { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px 16px; }
.rp-page .rp-grid3 > .rf-field:first-child { grid-column:1 / -1; }
@media (min-width:768px) { .rp-page .rp-grid3 { grid-template-columns:repeat(3,minmax(0,1fr)); } .rp-page .rp-grid3 > .rf-field:first-child { grid-column:auto; } }
.rp-page .rp-card { background:#fff; border:1px solid #dbe6fb; border-radius:14px; box-shadow:0 1px 3px rgba(13,110,253,.06); overflow:hidden; }
.rp-page .rp-card-h { padding:10px 14px; border-bottom:1px solid #eef3fd; }
.rp-page .rp-card-h h6 { margin:0; font-weight:700; color:#0b3d91; }
.rp-page .rp-help { width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; background:#e7f0ff; color:#0d6efd; text-decoration:none; }
.rp-page .rp-help:hover { background:#cfe2ff; }
.rp-page .rp-tag { font-size:.65rem; font-weight:700; border-radius:20px; padding:2px 8px; margin-left:6px; background:#cfe2ff; color:#084298; vertical-align:middle; white-space:nowrap; }
.rp-page .rp-tag-grey { background:#e9ecef; color:#495057; }
.rp-page .rp-explain { display:flex; gap:10px; align-items:flex-start; background:#f4f8ff; border:1px solid #cfe2ff; border-radius:12px; padding:10px 14px; font-size:.85rem; color:#1e3a8a; }
.rp-page .rp-explain i { color:#0d6efd; font-size:1.2rem; }
.rp-page .rp-explain span { display:block; color:#495057; }
.rp-page .rp-owe-note { background:#fff7e6; border:1px solid #f5d38f; border-radius:10px; padding:8px 12px; font-size:.85rem; color:#7a4b00; }
.rp-page .rp-tag-owe { background:#fff1d6; color:#7a4b00; }
.rp-page .rp-late { font-size:.65rem; font-weight:700; border-radius:20px; padding:2px 8px; margin-left:4px; background:#b45309; color:#fff; }
.rp-page .rp-pay-seg .btn, .rp-page .rp-seg .btn { font-weight:600; }
.rp-page .rp-preview { background:#e7f0ff; border:1px solid #b6ccfe; border-radius:10px; padding:9px 12px; font-size:.85rem; color:#0b3d91; }
/* Counted item | Something else */
.rp-page .rp-kind .btn { padding:8px 6px; line-height:1.2; }
.rp-page .rp-kind .btn small { display:block; font-weight:400; font-size:11px; opacity:.8; }
.rp-page .rp-num { font-size:15px; font-weight:600; }
.rp-page .rp-or { font-weight:400; font-style:italic; color:#6c757d; }
.rp-page .rp-hint { font-size:12px; color:#6c757d; margin-top:3px; min-height:1em; }
.rp-page .rp-hint.rp-warn { color:#b45309; font-weight:600; }
.rp-page .rp-sell { background:#f8fbff; border:1px dashed #b6ccfe; border-radius:10px; padding:8px 12px; font-size:13px; }
.rp-page .rp-match { background:#fff7e6; border:1px solid #f5d38f; border-radius:10px; padding:8px 12px; font-size:13px; color:#7a4b00; }
/* Search results: name + a quiet line of facts */
.rp-opt-name { font-weight:600; }
.rp-opt-meta { font-size:12px; color:#6c757d; }
.rp-opt-new { color:#0d6efd; font-weight:600; }
/* Summary */
.rp-page .rp-sum { background:#fff; border:1px solid #b6ccfe; border-radius:14px; box-shadow:0 2px 8px rgba(13,110,253,.08); position:sticky; top:80px; overflow:hidden; }
.rp-page .rp-sum-h { background:#0d6efd; color:#fff; font-weight:700; padding:10px 14px; }
.rp-page .rp-sum-b { padding:12px 14px 4px; min-height:96px; font-size:13px; }
.rp-page .rp-sum-empty { color:#6c757d; text-align:center; padding:14px 4px; }
.rp-page .rp-sum-empty i { display:block; font-size:24px; color:#b6ccfe; margin-bottom:4px; }
.rp-page .rp-sum-item { font-weight:700; color:#0b3d91; font-size:15px; margin-bottom:6px; }
.rp-page .rp-sum-line { display:flex; justify-content:space-between; gap:8px; padding:3px 0; }
.rp-page .rp-sum-line span:last-child { text-align:right; font-weight:600; }
.rp-page .rp-sum-line.rp-muted { color:#6c757d; }
.rp-page .rp-sum-line.rp-neg { color:#dc3545; }
.rp-page .rp-sum-line.rp-owe { color:#7a4b00; }
.rp-page .rp-sum-date { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 14px; border-top:1px dashed #dbe6fb; font-size:13px; color:#495057; }
.rp-page .rp-sum-date input { max-width:160px; }
.rp-page .rp-sum-total { display:flex; justify-content:space-between; align-items:baseline; padding:10px 14px 14px; border-top:1px solid #eef3fd; }
.rp-page .rp-sum-total span { font-weight:600; color:#495057; font-size:14px; }
.rp-page .rp-sum-total strong { font-size:22px; color:#0b3d91; }
@media (max-width: 991.98px) { .rp-page .rp-sum { position:static; } }
/* Recent purchases */
.rp-page .rp-count { display:inline-block; min-width:22px; padding:1px 8px; margin-left:6px; border-radius:20px; background:#0d6efd; color:#fff; font-size:11px; text-align:center; vertical-align:middle; }
.rp-page .rp-table-wrap { padding:6px 10px 10px; }
.rp-page .rp-table thead th, .rp-page .dataTables_scrollHead thead th { background:#f4f8ff; color:#0b3d91; font-weight:700; font-size:12px; white-space:nowrap; border-bottom:1px solid #cfe2ff; }
/* headers line up with their column (responsive.css centres every th with !important) */
.rp-page .rp-table th, .rp-page .dataTables_scrollHead th, .rp-page .dataTables_scrollFoot th { text-align:left !important; }
.rp-page .rp-table th.text-end, .rp-page .dataTables_scrollHead th.text-end, .rp-page .dataTables_scrollFoot th.text-end { text-align:right !important; }
/* the legacy style.css makes every .btn 16px / 85px wide — keep the row gear and the range switch compact */
.rp-page .rp-table .btn, .rp-page .rp-range .btn { min-width:0; padding:3px 9px; font-size:12px; line-height:1.4; }
.rp-page .rp-table .dropdown-item { font-size:13px; }
.rp-page .rp-table td { padding-top:7px; padding-bottom:7px; }
.rp-page .rp-table td.rp-sno { color:#6c757d; width:48px; }
.rp-page .rp-table .rp-by { padding-left:28px !important; color:#495057; }
.rp-page .rp-table td.text-end { white-space:nowrap; }
.rp-page .rp-table tfoot th, .rp-page .dataTables_scrollFoot tfoot th { background:#e7f0ff; color:#0b3d91; border-top:1px solid #b6ccfe; white-space:nowrap; }
.rp-page .rp-empty { color:#6c757d; padding:18px 0; }
.rp-page .rp-empty i { display:block; font-size:24px; color:#b6ccfe; }
/* Tab strip */
.rp-page .rp-tabs { display:flex; gap:0; }
.rp-page .rp-tab { background:#f4f8ff; border:1px solid #dbe6fb; border-bottom:none; border-radius:10px 10px 0 0; padding:8px 16px; font-size:.88rem; font-weight:600; color:#495057; cursor:pointer; transition:background .15s; margin-right:4px; }
.rp-page .rp-tab.active { background:#fff; border-color:#dbe6fb; color:#0d6efd; }
.rp-page .rp-tab:not(.active):hover { background:#e7f0ff; color:#0b3d91; }
.rp-page .rp-tab-card { border-top-left-radius:0 !important; border-top-right-radius:0 !important; }
.rp-page .rp-owe-ct { font-size:.7rem; padding:1px 7px; border-radius:20px; background:#fff1d6; color:#7a4b00; display:inline-block; min-width:0; }
</style>

<?php if ($warehouses && $can_buy): ?>
<script>
const RP = {
    api: <?= json_encode(buildUrl('api/restaurant/')) ?>,
    expenseApi: <?= json_encode(buildUrl('api/add_expense.php')) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    cur: <?= json_encode(rfMoneyPrefix()) ?>,   // same currency as the money fields (company currency, TSh by default)
    otherAccount: <?= (int)($other_account ?: 0) ?>,
    cashAccount: <?= (int)(posReceiptAccountId($pdo, 'cash') ?: 0) ?>,
    canEdit: <?= $can_edit ? 'true' : 'false' ?>,
    today: <?= json_encode(date('Y-m-d')) ?>, weekStart: <?= json_encode(date('Y-m-d', strtotime('-6 days'))) ?>,
    s: {
        search: <?= json_encode(t('Search the item...')) ?>, saving: <?= json_encode(t('Saving...')) ?>, error: <?= json_encode(t('Error')) ?>,
        serverError: <?= json_encode(t('Server error. Please try again.')) ?>, total: <?= json_encode(t('Total')) ?>,
        onHand: <?= json_encode(t('On hand')) ?>, cost: <?= json_encode(t('Cost')) ?>, newCost: <?= json_encode(t('New cost')) ?>,
        pickItem: <?= json_encode(t('Pick what you bought, or choose "Something else".')) ?>, saved: <?= json_encode(t('Purchase saved.')) ?>,
        costNow: <?= json_encode(t('Cost is now')) ?>, none: <?= json_encode(t('No purchases yet.')) ?>,
        notCounted: <?= json_encode(t('Not counted')) ?>, perOne: <?= json_encode(t('profit on each')) ?>,
        keptStock: <?= json_encode(t('Kept as stock')) ?>, keptNote: <?= json_encode(t('Its cost counts on the days you cook it')) ?>,
        enterKeepQty: <?= json_encode(t('Enter how much you bought.')) ?>, keptSaved: <?= json_encode(t('Kept as stock: %s')) ?>,
        belowCost: <?= json_encode(t('Selling below cost')) ?>, per: <?= json_encode(t('per')) ?>,
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
        paidBefore: <?= json_encode(t('Payments made')) ?>, stillOwe: <?= json_encode(t('Still owed')) ?>,
        // 2026-11-02 simpler, smarter screen
        sumEmpty: <?= json_encode(t('Pick what you bought — the total shows here.')) ?>, foodCost: <?= json_encode(t('Goes to the cost of food sold')) ?>,
        paidNowL: <?= json_encode(t('Paid now')) ?>, owe: <?= json_encode(t('You will owe')) ?>,
        avgCost: <?= json_encode(t('Average cost: %s')) ?>, timesAvg: <?= json_encode(t('%1$s× the average cost (%2$s)')) ?>, belowAvg: <?= json_encode(t('%1$s%% below the average cost (%2$s)')) ?>,
        onMenu: <?= json_encode(t('On the menu')) ?>, addNew: <?= json_encode(t('+ Add "%s" as a new ingredient')) ?>,
        wholeOnly: <?= json_encode(t('%s is counted in whole numbers — enter 1, 2, 3…')) ?>,
        priceCheck: <?= json_encode(t('Check the price')) ?>, priceCheckTxt: <?= json_encode(t('You entered %1$s for one %2$s — the average cost is %3$s. Is this correct?')) ?>,
        dupTitle: <?= json_encode(t('Already saved?')) ?>, dupTxt: <?= json_encode(t('The same purchase (%s) is already saved for this date. Save it again?')) ?>,
        countedTitle: <?= json_encode(t('This item is counted')) ?>,
        countedTxt: <?= json_encode(t('%1$s is a counted item (%2$s). Buy it as a counted item, or it will count twice in your costs.')) ?>,
        switchTo: <?= json_encode(t('Buy as counted item')) ?>, keepOther: <?= json_encode(t('Save as not counted anyway')) ?>,
        yesSave: <?= json_encode(t('Yes, save')) ?>, fixIt: <?= json_encode(t('Let me fix it')) ?>, buyAgain: <?= json_encode(t('Buy again')) ?>,
        gone: <?= json_encode(t('This item is no longer available to buy here.')) ?>
    }
};
RP.creditApi = RP.api + 'supplier_credit.php';
const fmt = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
const rpFmtCur = n => fmt(n) + ' ' + RP.cur;
let rpItem = null;        // { id, name, stock, cost, unit, sell } of the chosen item
let rpMode = 'stock';     // 'stock' = a counted item, 'other' = something else (not counted)
let rpLast = 'price';     // which of price / total the user typed last
let rpRows = [];          // the recent purchases as loaded (also used by the "already saved?" check and "Buy again")
let rpMatch = null;       // a counted item whose name matches what was typed in "Something else"
// units you count in whole pieces — 2.5 crates is a typing mistake; kg / lt keep their decimals
const RP_WHOLE = ['pcs', 'pc', 'piece', 'pieces', 'dozen', 'bunch', 'bag', 'crate', 'carton', 'bottle', 'loaf', 'loaves', 'egg', 'eggs', 'roll', 'box', 'tray', 'packet', 'pack', 'sack', 'tin', 'can'];
const rpIsWhole = u => RP_WHOLE.indexOf(String(u || '').toLowerCase().trim()) !== -1;
const rpNameOf = d => { const t = String(d.text || ''), sfx = ' (' + d.unit + ')'; return t.endsWith(sfx) ? t.slice(0, -sfx.length) : t; };

/** "Something else" bought for several days → kept as stock (only offered to someone who may buy stock). */
function rpKeep() { return rpMode === 'other' && $('#rpUseKeep').is(':checked'); }
function rpWh() { return parseInt($('#rpShop').length ? $('#rpShop').val() : $('#rpWarehouse').val(), 10); }

function rpSetMode(m) {
    rpMode = m;
    $('#rpKindStock').prop('checked', m === 'stock'); $('#rpKindOther').prop('checked', m === 'other');
    $('#rpStockFields, #rpQtySec').toggle(m === 'stock'); $('#rpOtherFields').toggle(m === 'other');
    if (m === 'other') { rpItem = null; $('#rpItem').val(null).trigger('change'); } else { rpMatch = null; $('#rpOtherMatch').hide(); }
    rpSummary(); rpPayUi();
}

// price for one <-> total paid: whichever was typed last wins, the other follows
function rpSync(from) {
    const q = parseFloat($('#rpQty').val()) || 0;
    if (from) rpLast = from;
    if (q > 0) {
        if (rpLast === 'total') { const t = parseFloat($('#rpTotal').val()); $('#rpPrice').val(isNaN(t) ? '' : Math.round(t / q * 100) / 100); }
        else { const p = parseFloat($('#rpPrice').val()); $('#rpTotal').val(isNaN(p) ? '' : Math.round(p * q * 100) / 100); }
    }
    rpSummary();
}
function rpUnitPrice() {
    const q = parseFloat($('#rpQty').val()) || 0;
    if (rpLast === 'total' && q > 0) { const t = parseFloat($('#rpTotal').val()); return isNaN(t) ? 0 : t / q; }
    return parseFloat($('#rpPrice').val()) || 0;
}
/** How far the price is from the item's average cost: null when it is normal (or there is nothing to compare with). */
function rpPriceOff(p) {
    if (!rpItem || rpItem.cost <= 0 || p <= 0) return null;
    const r = p / rpItem.cost;
    if (r >= 1.5) return RP.s.timesAvg.replace('%1$s', (Math.round(r * 10) / 10)).replace('%2$s', fmt(rpItem.cost));
    if (r <= 0.5) return RP.s.belowAvg.replace('%1$s', Math.round((1 - r) * 100)).replace('%%', '%').replace('%2$s', fmt(rpItem.cost));
    return null;
}

const rpLine = (l, r, cls) => `<div class="rp-sum-line ${cls || ''}"><span>${l}</span><span>${r}</span></div>`;
/** The live Summary (and the phone bar): what will be saved, before it is saved. */
function rpSummary() {
    let h = '', total = 0;
    if (rpMode === 'other') {
        const name = ($('#rpOtherName').val() || '').trim(), amt = parseFloat($('#rpOtherAmount').val()) || 0;
        total = amt;
        if ((name || amt) && rpKeep()) {
            const kq = parseFloat($('#rpKeepQty').val()) || 0, ku = safeOutput($('#rpKeepUnit').val());
            h = `<div class="rp-sum-item">${safeOutput(name || '—')} <span class="rp-tag">${RP.s.keptStock}</span></div>`;
            if (kq > 0) h += rpLine(`${fmt(kq)} ${ku}`, amt > 0 ? `${fmt(Math.round(amt / kq * 100) / 100)} / ${ku}` : '');
            h += rpLine(RP.s.keptNote, '', 'rp-muted');
        } else if (name || amt) h = `<div class="rp-sum-item">${safeOutput(name || '—')} <span class="rp-tag rp-tag-grey">${RP.s.notCounted}</span></div>` + rpLine(RP.s.foodCost, '', 'rp-muted');
    } else if (rpItem) {
        const q = parseFloat($('#rpQty').val()) || 0, p = rpUnitPrice(), u = safeOutput(rpItem.unit);
        total = q * p;
        const old = Math.max(0, rpItem.stock), tot = old + q, nc = q > 0 ? (tot > 0 ? ((old * rpItem.cost) + (q * p)) / tot : p) : rpItem.cost;
        h = `<div class="rp-sum-item">${safeOutput(rpItem.name)}</div>`;
        if (q > 0) h += rpLine(`${fmt(q)} ${u} × ${fmt(p)}`, fmt(total));
        h += rpLine(RP.s.onHand, q > 0 ? `${fmt(rpItem.stock)} → ${fmt(rpItem.stock + q)} ${u}` : `${fmt(rpItem.stock)} ${u}`, 'rp-muted');
        h += rpLine(`${RP.s.cost} ${RP.s.per} ${u}`, q > 0 ? `${fmt(rpItem.cost)} → ${fmt(nc)}` : fmt(rpItem.cost), 'rp-muted');
        if (rpItem.sell !== null) {
            const sell = parseFloat($('#rpSell').val()) || 0, pr = sell - nc, pc = sell > 0 ? Math.round(pr / sell * 100) : 0;
            h += rpLine(pr < 0 ? RP.s.belowCost : RP.s.perOne, `${fmt(pr)} (${pc}%)`, pr < 0 ? 'rp-neg' : '');
        }
        // the price hint under "Price for one"
        const off = q > 0 || $('#rpPrice').val() !== '' ? rpPriceOff(p) : null;
        $('#rpPriceHint').toggleClass('rp-warn', !!off).text(off ? '⚠ ' + off : (rpItem.cost > 0 ? RP.s.avgCost.replace('%s', fmt(rpItem.cost)) : ''));
    }
    if (!rpItem || rpMode === 'other') $('#rpPriceHint').removeClass('rp-warn').text('');
    total = Math.round(total * 100) / 100;
    const pay = rpPayment();
    if (h && pay !== 'paid' && total > 0) {
        const paid = pay === 'partial' ? Math.min(total, parseFloat($('#rpNowAmt').val()) || 0) : 0;
        h += rpLine(RP.s.paidNowL, fmt(paid)) + rpLine(RP.s.owe, fmt(total - paid), 'rp-owe');
    }
    $('#rpSumBody').html(h || `<div class="rp-sum-empty"><i class="bi bi-basket"></i>${RP.s.sumEmpty}</div>`);
    $('#rpSumTotal').text(rpFmtCur(total));
}

function rpSellShow() { $('#rpSellTxt').text(rpItem && rpItem.sell !== null ? rpFmtCur(parseFloat($('#rpSell').val()) || 0) : ''); }
function rpPick(d) {
    rpItem = { id: parseInt(d.id, 10) || 0, name: d.name || rpNameOf(d), stock: parseFloat(d.stock) || 0, cost: parseFloat(d.cost_price) || 0, unit: d.unit,
               sell: (d.selling_price === null || d.selling_price === undefined) ? null : parseFloat(d.selling_price) };
    const whole = rpIsWhole(d.unit);
    $('#rpUnit').text(d.unit || '—'); $('#rpQty').attr({ step: whole ? 1 : 0.001, min: whole ? 1 : 0.001, inputmode: whole ? 'numeric' : 'decimal' });
    $('#rpPrice').val(rpItem.cost > 0 ? rpItem.cost : ''); $('#rpTotal').val(''); rpLast = 'price';
    $('#rpSellRow').toggle(rpItem.sell !== null); $('#rpSellBox').hide(); $('#rpSell').val(rpItem.sell !== null ? rpItem.sell : ''); rpSellShow();
    if (rpMode !== 'stock') rpSetMode('stock');
    rpSync();
}
/** Choose an item by id (the Menu's "Buy stock" link, "Buy again", "Buy as counted item"), with its live stock and cost. */
function rpLoadItem(id, name, then) {
    $.getJSON(RP.api + 'search_stock_items.php', { warehouse_id: rpWh(), q: name }, function (r) {
        const m = (r.results || []).find(x => +x.id === +id);
        if (!m) { Swal.fire({ icon: 'info', title: RP.s.gone, timer: 2600, showConfirmButton: false }); return; }
        if (rpMode !== 'stock') rpSetMode('stock');
        if (!$('#rpItem option[value="' + (+m.id) + '"]').length) $('#rpItem').append(new Option(m.text, m.id, false, false));
        $('#rpItem').val(String(m.id)).trigger('change');
        rpPick(m);
        if (then) then(m);
    });
}
function rpReset() {
    $('#rpQty, #rpPrice, #rpTotal, #rpSell, #rpOtherName, #rpOtherAmount, #rpNowAmt, #rpDue, #rpKeepQty').val(''); $('#rpItem').val(null).trigger('change');
    $('#rpUseAll').prop('checked', true); $('.rp-keep-only').hide();
    $('#rpSupplier').val('').trigger('change'); $('#rpPayPaid').prop('checked', true);
    rpItem = null; rpLast = 'price'; rpMatch = null; $('#rpUnit').text('—'); $('#rpOtherMatch').hide(); $('#rpSellRow').hide(); $('#rpQty').attr({ step: 0.001, min: 0.001 });
    rpSetMode('stock');
}

// ── Payment: Paid (default) · Partly paid · Credit ──
function rpPayment() { return $('input[name="rpPayment"]:checked').val() || 'paid'; }
function rpPurchaseTotal() {
    if (rpMode === 'other') return parseFloat($('#rpOtherAmount').val()) || 0;
    return (parseFloat($('#rpQty').val()) || 0) * rpUnitPrice();
}
function rpPayUi() {
    const p = rpPayment(), owe = p !== 'paid';
    $('.rp-owe-only').toggle(owe); $('#rpNowAmtWrap').toggle(p === 'partial');
    $('#rpSupHint').toggle(!owe); $('#rpSupStar').toggleClass('d-none', !owe);
    rpSummary();
    if (!owe) return;
    const total = Math.round(rpPurchaseTotal() * 100) / 100, paid = p === 'partial' ? (parseFloat($('#rpNowAmt').val()) || 0) : 0;
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
        const paid = parseFloat($('#rpNowAmt').val()) || 0, total = rpPurchaseTotal();
        if (paid <= 0) return { error: RP.s.enterPaidNow };
        if (paid >= total - 0.004) return { error: RP.s.paidNowLess };
        d.amount_paid = paid;
    }
    d.due_date = $('#rpDue').val() || '';
    return d;
}

// ── "Something else": is it really a counted item? (it would count twice) ──
let rpMatchTimer = null;
function rpCheckMatch() {
    const q = ($('#rpOtherName').val() || '').trim().toLowerCase();
    rpMatch = null; $('#rpOtherMatch').hide();
    if (q.length < 3) return;
    $.getJSON(RP.api + 'search_stock_items.php', { warehouse_id: rpWh(), q: q }, function (r) {
        if (($('#rpOtherName').val() || '').trim().toLowerCase() !== q) return;          // typed on since
        const m = (r.results || []).find(x => { const n = rpNameOf(x).toLowerCase(); return n === q || n.startsWith(q) || q.startsWith(n); });
        if (!m) return;
        rpMatch = m;
        $('#rpOtherMatch').html('<i class="bi bi-exclamation-triangle me-1"></i>' + RP.s.countedTxt.replace('%1$s', '<strong>' + safeOutput(rpNameOf(m)) + '</strong>').replace('%2$s', safeOutput(m.unit))
            + ' <a href="#" id="rpUseMatch" class="fw-semibold ms-1">' + RP.s.switchTo + ' →</a>').show();
    });
}
function rpUseMatch() {
    const m = rpMatch; if (!m) return;
    const amt = parseFloat($('#rpOtherAmount').val()) || 0;
    $('#rpOtherName, #rpOtherAmount').val('');
    rpLoadItem(m.id, rpNameOf(m), () => { if (amt > 0) { $('#rpTotal').val(amt); rpLast = 'total'; } setTimeout(() => $('#rpQty').trigger('focus'), 100); rpSync(); });
}

/** Ask once, in plain words; resolves true to go on. */
function rpAsk(title, html, yes, no) {
    return Swal.fire({ icon: 'question', title: title, html: html, showCancelButton: true, confirmButtonColor: '#0d6efd',
                       confirmButtonText: yes || RP.s.yesSave, cancelButtonText: no || RP.s.fixIt, reverseButtons: true }).then(r => r.isConfirmed);
}
/** The same purchase already in the list for this date? (a double tap, a retry after a slow network) */
function rpAlreadySaved(kind, name, qty, total, date) {
    return rpRows.some(r => r.date === date && (r.kind === 'grocery') === (kind === 'grocery') && String(r.item).toLowerCase() === String(name).toLowerCase()
        && Math.abs((+r.total || 0) - total) < 0.01 && (kind === 'grocery' || Math.abs((+r.quantity || 0) - qty) < 0.0005));
}

// ── Suppliers you owe ──
let rpDebts = {};
function loadDebts() {
    $.getJSON(RP.creditApi, { action: 'debts', warehouse_id: rpWh() }, function (res) {
        const rows = res.success ? res.data : [];
        rpDebts = {};
        rows.forEach(r => { rpDebts[r.id] = r; });
        rpOweDT.clear().rows.add(rows).draw();
        $('#rpOweTotal').text(rows.length ? RP.s.totalOwed + ': ' + fmt(res.total) + ' ' + RP.cur : '');
        $('#rpOweBadge').text(rows.length ? rows.length : '');
    });
}

// ── Recent purchases ──
let rpDT = null;     // standard DataTable; reloaded with clear().rows.add().draw()
let rpOweDT = null;  // DataTable for the suppliers-you-owe table
let rpRange = 'all'; // All · Today · This week
const rpNum = (v, type, html) => (type === 'sort' || type === 'type') ? (v === null || v === undefined ? -1 : +v) : html;
function loadPurchases() {
    $.getJSON(RP.api + 'get_purchases.php', { warehouse_id: rpWh() }, function (res) {
        rpRows = (res.success ? res.data : []).map((r, i) => Object.assign(r, { _i: i }));
        rpDT.clear().rows.add(rpRows).draw();
    });
}
function rpBuyAgain(i) {
    const r = rpRows[i]; if (!r) return;
    document.getElementById('rpForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (r.kind === 'grocery') {
        rpSetMode('other'); $('#rpOtherName').val(r.item); $('#rpOtherAmount').val(r.total); rpSummary(); rpPayUi(); rpCheckMatch();
        setTimeout(() => $('#rpOtherAmount').trigger('focus').trigger('select'), 300);
        return;
    }
    rpLoadItem(r.product_id, r.item, () => {
        $('#rpQty').val(r.quantity); $('#rpPrice').val(r.unit_cost); rpLast = 'price'; rpSync(); rpPayUi();
        setTimeout(() => $('#rpQty').trigger('focus').trigger('select'), 300);
    });
}

$(function () {
    $.fn.dataTable.ext.search.push(function (settings, data, idx, row) {
        if (settings.nTable.id !== 'rpTable' || rpRange === 'all') return true;
        return rpRange === 'today' ? row.date === RP.today : row.date >= RP.weekStart;
    });
    rpDT = $('#rpTable').DataTable({
        responsive: false, scrollX: true, autoWidth: false, pageLength: 10, order: [], dom: 'rtip',
        columns: [
            { data: null, orderable: false, searchable: false, className: 'rp-sno', render: () => '' },
            { data: 'item', render: (d, t, r) => {      // Item before Date: it is also the title of each phone card
                if (t !== 'display') return d;
                const g = r.kind === 'grocery';
                const payTag = r.payment === 'credit' ? ' <span class="rp-tag rp-tag-owe">' + RP.s.creditTag + ' · ' + RP.s.owedTag + ' ' + fmt(r.owed) + '</span>'
                             : r.payment === 'partial' ? ' <span class="rp-tag rp-tag-owe">' + RP.s.partlyTag + ' · ' + RP.s.owedTag + ' ' + fmt(r.owed) + '</span>' : '';
                return '<span class="fw-semibold">' + safeOutput(d) + '</span>' + (g ? ' <span class="rp-tag rp-tag-grey">' + RP.s.notCounted + '</span>' : '') + payTag;
            } },
            { data: 'date', render: (d, t) => t === 'display' ? safeOutput(d) : d },
            { data: 'quantity', className: 'text-end', render: (d, t, r) => rpNum(d, t, r.kind === 'grocery' ? '—' : fmt(d) + ' ' + safeOutput(r.unit)) },
            { data: 'unit_cost', className: 'text-end', render: (d, t, r) => rpNum(d, t, r.kind === 'grocery' ? '—' : fmt(d)) },
            { data: 'total', className: 'text-end', render: (d, t) => rpNum(d, t, '<strong>' + fmt(d) + '</strong>') },
            { data: 'by', className: 'rp-by', render: (d, t) => t === 'display' ? safeOutput(d || '') : (d || '') },
            { data: null, orderable: false, searchable: false, className: 'text-end', render: (d, t, r) => t !== 'display' ? '' :
                `<div class="dropdown d-flex justify-content-end">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false"><i class="bi bi-gear-fill me-1"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
                        <li><button type="button" class="dropdown-item py-2 rounded rp-again" data-i="${+r._i}"><i class="bi bi-arrow-repeat text-primary me-2"></i> ${RP.s.buyAgain}</button></li>
                    </ul>
                </div>` }
        ],
        language: { emptyTable: '<div class="rp-empty"><i class="bi bi-basket"></i>' + RP.s.none + '</div>', zeroRecords: '<div class="rp-empty"><i class="bi bi-basket"></i>' + RP.s.none + '</div>' },
        drawCallback: function () {
            const api = this.api();
            // S/No. counts down the list as shown (1, 2, 3 … across pages), whatever the sort
            api.column(0, { search: 'applied', order: 'applied' }).nodes().each((c, i) => { c.textContent = i + 1; });
            const shown = api.rows({ search: 'applied' }).data().toArray();
            $('#rpCount').text(shown.length);
            $('#rpFootTotal').html('<strong>' + rpFmtCur(shown.reduce((s, r) => s + (+r.total || 0), 0)) + '</strong>');
        }
    });
    $(window).on('resize', () => rpDT.columns.adjust());
    $('input[name="rpRange"]').on('change', function () { rpRange = this.value; rpDT.draw(); });
    $('#rpTable').on('click', '.rp-again', function () { rpBuyAgain(+$(this).data('i')); });

    // Suppliers you owe — DataTable
    rpOweDT = $('#rpOweTable').DataTable({
        responsive: false, scrollX: true, autoWidth: false, pageLength: 25, order: [], dom: 'rtip',
        data: [],
        columns: [
            { data: null, orderable: false, searchable: false, className: 'rp-sno', render: () => '' },
            { data: 'supplier_name', render: (d, t, r) => t !== 'display' ? d :
                '<span class="fw-semibold">' + safeOutput(d) + '</span>' + (r.phone ? '<br><small class="text-muted">' + safeOutput(r.phone) + '</small>' : '') },
            { data: 'what', render: (d, t) => t !== 'display' ? d : safeOutput(d) },
            { data: 'date_raised', render: (d, t) => t !== 'display' ? d : safeOutput(d) },
            { data: 'due_date', render: (d, t, r) => t !== 'display' ? (d || '') :
                (d ? safeOutput(d) : '—') + (+r.overdue ? '<span class="rp-late ms-1">' + RP.s.overdue + '</span>' : '') },
            { data: 'amount',      className: 'text-end', render: (d, t) => t === 'display' ? fmt(d) : +d },
            { data: 'amount_paid', className: 'text-end', render: (d, t) => t === 'display' ? fmt(d) : +d },
            { data: 'owed',        className: 'text-end', render: (d, t) => t === 'display' ? '<strong>' + fmt(d) + '</strong>' : +d },
            { data: null, orderable: false, searchable: false, className: 'text-end', render: (d, t, r) => t !== 'display' ? '' :
                '<button type="button" class="btn btn-sm btn-primary rp-pay-btn" data-id="' + (+r.id) + '"><i class="bi bi-cash-coin me-1"></i>' + RP.s.pay + '</button>' }
        ],
        drawCallback: function () {
            this.api().column(0, { search: 'applied', order: 'applied' }).nodes().each((c, i) => { c.textContent = i + 1; });
        },
        language: { emptyTable: '<div class="text-muted text-center py-3">' + RP.s.noDebts + '</div>', zeroRecords: '<div class="text-muted text-center py-3">' + RP.s.noDebts + '</div>' }
    });
    $(window).on('resize', () => rpOweDT.columns.adjust());

    // Tab switching
    $('.rp-tab').on('click', function () {
        const pane = $(this).data('pane');
        $('.rp-tab').removeClass('active');
        $(this).addClass('active');
        if (pane === 'rpRecent') { $('#rpOwe').hide(); $('#rpRecent').show(); rpDT.columns.adjust(); }
        else { $('#rpRecent').hide(); $('#rpOwe').show(); rpOweDT.columns.adjust(); }
    });

    $('#rpSupplier, #rpShop').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#rpItem').select2({
        theme: 'bootstrap-5', width: '100%', placeholder: RP.s.search, allowClear: true, minimumInputLength: 0,
        ajax: { url: RP.api + 'search_stock_items.php', dataType: 'json', delay: 250, data: p => ({ q: p.term || '', warehouse_id: rpWh() }),
                processResults: (d, params) => {
                    const r = d.results || [], term = ((params && params.term) || '').trim();
                    // nothing with this exact name? add it as a new ingredient, right from the search
                    if (RP.canEdit && term.length >= 2 && !r.some(x => rpNameOf(x).toLowerCase() === term.toLowerCase())) r.push({ id: '__new', text: RP.s.addNew.replace('%s', term), term: term });
                    return { results: r };
                }, cache: false },
        templateResult: function (d) {
            if (d.loading || !d.id) return d.text;
            if (d.id === '__new') return $('<span class="rp-opt-new"></span>').text(d.text);
            const meta = [d.unit, RP.s.onHand + ' ' + fmt(d.stock), RP.s.cost + ' ' + fmt(d.cost_price)].join(' · ');
            return $('<div></div>').append($('<div class="rp-opt-name"></div>').text(rpNameOf(d)).append(d.selling_price !== null && d.selling_price !== undefined ? ' <span class="rp-tag">' + RP.s.onMenu + '</span>' : ''))
                                   .append($('<div class="rp-opt-meta"></div>').text(meta));
        }
    }).on('select2:select', function (e) {
        const d = e.params.data;
        if (d.id === '__new') {
            $('#rpItem').val(null).trigger('change'); rpItem = null; rpSummary();
            $('#rpIngMsg').empty(); $('#rpIngForm')[0].reset(); $('#rpIngName').val(d.term);
            new bootstrap.Modal(document.getElementById('rpIngModal')).show(); setTimeout(() => $('#rpIngUnit').trigger('focus'), 300);
            return;
        }
        rpPick(d);
    }).on('select2:clear', function () { rpItem = null; $('#rpUnit').text('—'); $('#rpSellRow').hide(); rpSummary(); });
    $('input[name="rpKind"]').on('change', function () { rpSetMode(this.value); if (this.value === 'other') setTimeout(() => $('#rpOtherName').trigger('focus'), 50); });
    $('#rpQty').on('input', () => rpSync());
    $('#rpPrice').on('input', () => rpSync('price'));
    $('#rpTotal').on('input', () => rpSync('total'));
    $('#rpSell').on('input', () => { rpSellShow(); rpSummary(); });
    $('#rpSellEdit').on('click', function (e) { e.preventDefault(); $('#rpSellBox').toggle(); if ($('#rpSellBox').is(':visible')) $('#rpSell').trigger('focus').trigger('select'); });
    $('#rpOtherName').on('input', () => { rpSummary(); clearTimeout(rpMatchTimer); rpMatchTimer = setTimeout(rpCheckMatch, 400); });
    $('#rpOtherAmount').on('input', rpSummary);
    // "Will you use it all today?" — No: quantity and unit, kept as stock (restaurant costing plan, Phase 3)
    $('input[name="rpUse"]').on('change', function () { $('.rp-keep-only').toggle(rpKeep()); rpSummary(); });
    $('#rpKeepQty, #rpKeepUnit').on('input change', rpSummary);
    $('#rpOtherMatch').on('click', '#rpUseMatch', function (e) { e.preventDefault(); rpUseMatch(); });
    $('#rpShop').on('change', function () { $('#rpWarehouse').val(this.value); rpReset(); loadPurchases(); loadDebts(); });
    <?php if ($preItem): ?>
    rpLoadItem(<?= (int)$preItem['product_id'] ?>, <?= json_encode($preItem['product_name']) ?>);
    <?php endif; ?>

    function done(msgHtml) {
        Swal.fire({ icon: 'success', title: RP.s.saved, html: msgHtml, timer: 2600, showConfirmButton: false });
        $('#rpMessage').empty(); rpReset(); loadPurchases(); loadDebts();
    }

    $('input[name="rpPayment"]').on('change', rpPayUi);
    $('#rpNowAmt, #rpQty, #rpPrice, #rpTotal, #rpOtherAmount').on('input', rpPayUi);

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
    function fail(m) {
        $('#rpMessage').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(m) + '</div>');
        const el = document.getElementById('rpMessage'), top = el.getBoundingClientRect().top;
        if (top < 60 || top > window.innerHeight - 120) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    // the Save buttons (Summary and the phone bar) — busy together
    $('.rp-save').each(function () { $(this).data('orig', $(this).html()); });
    const rpBusy = on => $('.rp-save').each(function () { $(this).prop('disabled', on).html(on ? '<span class="spinner-border spinner-border-sm me-1"></span>' + RP.s.saving : $(this).data('orig')); });
    let rpSaving = false;

    $('#rpForm').on('submit', function (e) {
        e.preventDefault();
        if (rpSaving) return;
        const finish = () => { rpSaving = false; rpBusy(false); };
        const start = () => { rpSaving = true; rpBusy(true); };
        const pay = rpPayData(), date = $('#rpDate').val();
        if (rpMode === 'other') {
            const name = ($('#rpOtherName').val() || '').trim(), amt = parseFloat($('#rpOtherAmount').val()) || 0;
            if (!name) return fail(RP.s.enterName);
            if (amt <= 0) return fail(RP.s.enterAmount);
            if (pay.error) return fail(pay.error);
            if (rpKeep()) {
                // Bought for several days: kept as stock (an existing counted item of that name, or a new ingredient).
                const kq = parseFloat($('#rpKeepQty').val()) || 0, ku = $('#rpKeepUnit').val();
                if (kq <= 0) return fail(RP.s.enterKeepQty);
                $('#rpMessage').empty();
                start();
                $.post(RP.api + 'buy_stock.php', Object.assign({ _csrf: RP.csrf, keep: 1, name: name, unit: ku, quantity: kq, total_paid: amt,
                        warehouse_id: rpWh(), date: date, supplier_id: $('#rpSupplier').val() || '' }, pay), function (res) {
                    if (res.success) done(RP.s.keptSaved.replace('%s', safeOutput(res.product_name)) + `<br><small>${fmt(res.on_hand_after)} ${safeOutput(res.unit || ku)} · ${RP.s.keptNote}</small>`
                        + (res.owed > 0 ? `<br><small>${RP.s.owedTag}: ${fmt(res.owed)} ${RP.cur}</small>` : ''));
                    else fail(res.message || RP.s.error);
                }, 'json').fail(() => fail(RP.s.serverError)).always(finish);
                return;
            }
            $('#rpMessage').empty();
            let chain = Promise.resolve(true);
            if (rpMatch) chain = chain.then(ok => ok && rpAsk(RP.s.countedTitle, RP.s.countedTxt.replace('%1$s', '<strong>' + safeOutput(rpNameOf(rpMatch)) + '</strong>').replace('%2$s', safeOutput(rpMatch.unit)), RP.s.keepOther, RP.s.switchTo)
                .then(keep => { if (!keep) rpUseMatch(); return keep; }));
            chain = chain.then(ok => ok && (!rpAlreadySaved('grocery', name, 0, amt, date) || rpAsk(RP.s.dupTitle, RP.s.dupTxt.replace('%s', safeOutput(name) + ' · ' + rpFmtCur(amt)))));
            chain.then(ok => {
                if (!ok) return;
                start();
                if (pay.payment !== 'paid') {      // partly paid / credit: a supplier bill (fully paid keeps the expense path below)
                    $.post(RP.creditApi, Object.assign({ _csrf: RP.csrf, action: 'buy_grocery', name: name, amount: amt, warehouse_id: rpWh(), date: date,
                                                         supplier_id: $('#rpSupplier').val() }, pay), function (res) {
                        if (res.success) done(safeOutput(name) + '<br><small>' + RP.s.owedTag + ': ' + fmt(res.owed) + ' ' + RP.cur + '</small>'); else fail(res.message || RP.s.error);
                    }, 'json').fail(() => fail(RP.s.serverError)).always(finish);
                    return;
                }
                $.post(RP.expenseApi, { _csrf: RP.csrf, expense_account_id: RP.otherAccount, bank_account_id: RP.cashAccount, status: 'paid', amount: amt, expense_date: date,
                                        warehouse_id: rpWh(), description: RP.s.groceryDesc + ': ' + name }, function (res) {
                    if (res.success) done(safeOutput(name) + '<br><small>' + fmt(amt) + ' ' + RP.cur + '</small>'); else fail(res.message || RP.s.error);
                }, 'json').fail(() => fail(RP.s.serverError)).always(finish);
            });
            return;
        }
        if (!$('#rpItem').val() || !rpItem) return fail(RP.s.pickItem);
        const q = parseFloat($('#rpQty').val()) || 0;
        if (q <= 0) return fail(RP.s.enterQty);
        if (rpIsWhole(rpItem.unit) && Math.abs(q - Math.round(q)) > 1e-9) return fail(RP.s.wholeOnly.replace('%s', rpItem.unit));
        if ($('#rpPrice').val() === '' && $('#rpTotal').val() === '') return fail(RP.s.enterPrice);
        if (pay.error) return fail(pay.error);
        $('#rpMessage').empty();
        const data = Object.assign({ _csrf: RP.csrf, warehouse_id: rpWh(), product_id: $('#rpItem').val(), quantity: q, date: date,
                       supplier_id: $('#rpSupplier').val() || '', expiry_date: $('#rpExpiry').val() || '' }, pay);
        if (rpLast === 'total') data.total_paid = parseFloat($('#rpTotal').val()) || 0; else data.unit_cost = parseFloat($('#rpPrice').val()) || 0;
        if (rpItem.sell !== null && $('#rpSell').val() !== '') data.selling_price = $('#rpSell').val();
        const p = rpUnitPrice(), total = Math.round(q * p * 100) / 100, item = rpItem;
        let chain = Promise.resolve(true);
        if (rpPriceOff(p)) chain = chain.then(ok => ok && rpAsk(RP.s.priceCheck, RP.s.priceCheckTxt.replace('%1$s', '<strong>' + rpFmtCur(p) + '</strong>').replace('%2$s', safeOutput(item.unit)).replace('%3$s', rpFmtCur(item.cost))));
        chain = chain.then(ok => ok && (!rpAlreadySaved('stock', item.name, q, total, date) || rpAsk(RP.s.dupTitle, RP.s.dupTxt.replace('%s', safeOutput(item.name) + ' · ' + fmt(q) + ' ' + safeOutput(item.unit) + ' · ' + rpFmtCur(total)))));
        chain.then(ok => {
            if (!ok) return;
            start();
            $.post(RP.api + 'buy_stock.php', data, function (res) {
                if (res.success) done(`${safeOutput(res.product_name)}<br><small>${RP.s.costNow} ${fmt(res.new_cost)} ${RP.cur}</small>` + (res.owed > 0 ? `<br><small>${RP.s.owedTag}: ${fmt(res.owed)} ${RP.cur}</small>` : '')); else fail(res.message || RP.s.error);
            }, 'json').fail(() => fail(RP.s.serverError)).always(finish);
        });
    });

    $('#rpIngForm').on('submit', function (e) {
        e.preventDefault();
        $.post(RP.api + 'recipe.php', $(this).serialize() + '&action=new_ingredient&warehouse_id=' + rpWh() + '&_csrf=' + encodeURIComponent(RP.csrf), function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('rpIngModal')).hide();
                $('#rpItem').append(new Option(res.text, res.id, true, true)).trigger('change');
                rpPick({ id: res.id, text: res.text, stock: 0, cost_price: res.cost_price, unit: res.unit, selling_price: null });
                setTimeout(() => $('#rpQty').trigger('focus'), 300);
            } else { $('#rpIngMsg').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message || RP.s.error) + '</div>'); }
        }, 'json').fail(() => $('#rpIngMsg').html('<div class="alert alert-danger py-2 mb-0">' + RP.s.serverError + '</div>'));
    });
    loadPurchases();
    loadDebts();
    // ?bill=N from a "Supplier payment due" notification: open Pay for that bill once the list is in.
    const RP_FOCUS_BILL = <?= (int)$focusBill ?>;
    if (RP_FOCUS_BILL) {
        let tries = 0;
        const t = setInterval(function () {
            const b = $('#rpOweTable .rp-pay-btn[data-id="' + RP_FOCUS_BILL + '"]');
            if (b.length) { clearInterval(t); b.closest('tr').addClass('table-primary'); b[0].scrollIntoView({ block: 'center' }); b.trigger('click'); }
            else if (++tries > 40) clearInterval(t);
        }, 250);
    }
    if (typeof bmsCleanQuery === 'function') bmsCleanQuery(['bill', 'shop']);
    rpPayUi();
    if (location.hash === '#rpOwe') setTimeout(() => { $('#rpBtnOwe').trigger('click'); document.getElementById('rpOwe').scrollIntoView({ behavior: 'smooth' }); }, 400);
});
</script>
<?php endif; ?>
<?php
require_once 'footer.php';
ob_end_flush();
