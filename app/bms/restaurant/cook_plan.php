<?php
/**
 * app/bms/restaurant/cook_plan.php — "Pika Leo": the cooking plan (restaurant costing plan, Phase 3).
 *
 * Before selling, the cook (or owner) says what goes on the fire today — "2 pots of pilau" — and sees the plates it
 * gives, what it costs and, if every plate sells, the sales and the gross profit to expect. An expectation only: the
 * real profit stays on the dashboard (from the books). Rice or oil kept in stock can be taken for the cook (it leaves
 * stock and becomes cost the day it is cooked); spoiled stock is recorded here too. At the end of the day: plates left.
 *
 * Logic: core/restaurant_cook_plan.php · API: api/restaurant/cook_plan.php.
 * Rights: view = Restaurant POS; start/close = Restaurant POS edit; stock = the Purchases right; money = restaurant_costing.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_cook_plan.php';

$page_title = 'Cooking plan';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
// Role based (core/restaurant_access.php): the same rule as this page's menu link.
require_once __DIR__ . '/../../../core/restaurant_access.php';
raDeny(raCanCookPlan());
$can_edit   = rcpCanCook();   // the restaurant editor, or the kitchen (ready-made Cook role)
$can_move   = rcpCanMoveStock();
$can_cost   = restaurantCanSeeCost();
$module_on  = restaurantModuleOn($pdo) && rcpReady($pdo);
// Phase 4: a cook without the stock right may ASK for stock; the storekeeper gives it out (Stock requests).
$can_ask    = $module_on && $can_edit && !$can_move && rcpRequestsReady($pdo);
$can_report = canView('restaurant_pos') && ($can_edit || $can_cost || isAdmin() || (function_exists('hasReportsAccess') && hasReportsAccess()));
$warehouses = $module_on ? restaurantWarehousesForSelect($pdo) : [];
require_once __DIR__ . '/../../../includes/restaurant/form_kit.php';
rfKitAssets();
$rfCur = htmlspecialchars(rfMoneyPrefix());
?>

<div class="container-fluid mt-3 mt-md-4 cp-page">
    <div class="d-flex justify-content-between align-items-center mb-1 gap-2 flex-wrap">
        <h4 class="mb-0 text-truncate"><i class="ri ri-cooking-pot text-primary me-2"></i><?= t('Cooking plan') ?></h4>
        <div class="d-flex gap-2 align-items-center">
            <input type="date" id="cpDate" class="form-control form-control-sm" style="width:auto;" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" aria-label="<?= htmlspecialchars(t('Date')) ?>">
            <?php if ($can_report): ?><a href="<?= getUrl('restaurant/cooking-report') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard-data"></i><span class="d-none d-md-inline ms-1"><?= t('Planned vs actual') ?></span></a><?php endif; ?>
            <a href="<?= getUrl('restaurant/menu') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-journal-richtext"></i><span class="d-none d-md-inline ms-1"><?= t('Menu') ?></span></a>
        </div>
    </div>
    <p class="text-muted small mb-3"><?= t('Plan today\'s pots: the plates they give, what they cost and the profit to expect — before you sell.') ?></p>

    <?php if (!$module_on || !$warehouses): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i><?= t('The cooking plan is available for restaurant shops in Smart POS.') ?></div>
    <?php else: ?>

    <?php if (count($warehouses) > 1): ?>
    <div class="mb-3" style="max-width:320px;">
        <label class="form-label small mb-1" for="cpShop"><?= wLabel('Warehouse', 'Shop') ?></label>
        <select id="cpShop" class="form-select form-select-sm">
            <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['warehouse_id'] ?>"><?= caseFormat($w['warehouse_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
    <input type="hidden" id="cpShop" value="<?= (int)$warehouses[0]['warehouse_id'] ?>">
    <?php endif; ?>

    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-md"><div class="card cp-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary" id="stPlanned">0</div><div class="small text-muted"><?= t('Plates planned') ?></div></div></div>
        <div class="col-6 col-md"><div class="card cp-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-success" id="stSold">0</div><div class="small text-muted"><?= t('Plates sold (till)') ?></div></div></div>
        <div class="col-6 col-md"><div class="card cp-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-warning" id="stLeft">—</div><div class="small text-muted"><?= t('Plates left') ?></div></div></div>
        <?php if ($can_cost): ?>
        <div class="col-6 col-md"><div class="card cp-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary" id="stGp">0</div><div class="small text-muted"><?= t('Expected gross profit') ?></div></div></div>
        <?php endif; ?>
    </div>

    <div class="row g-3">
        <?php if ($can_edit): ?>
        <div class="col-lg-5" id="cpFormCol">
            <div class="cp-card">
                <div class="cp-card-h"><h6><i class="bi bi-fire me-1"></i><?= t('Cook') ?></h6></div>
                <div class="p-3">
                    <div id="cpMsg" class="mb-2"></div>
                    <div class="rf-field mb-3">
                        <label class="form-label" for="cpDish"><?= t('Dish') ?><span class="rf-req">*</span></label>
                        <select id="cpDish" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="rf-field mb-1">
                        <label class="form-label" for="cpPots" id="cpPotsLbl"><?= t('How many pots?') ?><span class="rf-req">*</span></label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <input type="number" id="cpPots" class="form-control text-center fw-bold" style="width:100px;" min="0.5" max="100" step="0.5" value="1" inputmode="decimal">
                            <span class="fw-semibold text-primary" id="cpPlatesNote"></span>
                        </div>
                    </div>
                    <div class="rf-help mb-3" id="cpYieldHelp"></div>

                    <div id="cpComps" class="cp-note mb-3 d-none"></div>

                    <div id="cpCheck" class="small mb-3 d-none"></div>

                    <?php if ($can_move || $can_ask): ?>
                    <div class="rf-sec mb-0">
                        <div class="rf-sec-title"><i class="bi bi-box-seam"></i><?= $can_move ? t('Taken from stock') : t('Ask for stock') ?> <span class="rf-opt">(<?= t('optional') ?>)</span></div>
                        <div class="rf-help mt-0 mb-2"><?= $can_move
                            ? t('Rice, oil or flour kept from an earlier purchase. It leaves stock now and its cost counts today.')
                            : t('Ask for rice, oil or flour from stock. The storekeeper gives it out — only then does it leave stock.') ?></div>
                        <div id="cpLines"></div>
                        <select id="cpPick" class="form-select mt-2" style="width:100%"></select>
                    </div>
                    <?php else: ?>
                    <div class="rf-help"><i class="bi bi-info-circle me-1"></i><?= t('Taking rice or oil from stock needs the Purchases right — ask the person who buys.') ?></div>
                    <?php endif; ?>

                    <?php if ($can_cost): ?>
                    <div class="cp-sum mt-3" id="cpSum"></div>
                    <?php endif; ?>

                    <button type="button" class="btn btn-primary btn-lg w-100 mt-3" id="cpSave"><i class="bi bi-fire me-1"></i><?= t('Start cooking') ?></button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="<?= $can_edit ? 'col-lg-7' : 'col-12' ?>">
            <div class="cp-card mb-3">
                <div class="cp-card-h d-flex justify-content-between align-items-center"><h6><i class="bi bi-list-check me-1"></i><span id="cpDayTitle"><?= t('Today\'s cooking') ?></span></h6></div>
                <div id="cpEarlier" class="cp-earlier m-2 d-none"></div>
                <div id="cpPlans" class="p-2"></div>
                <?php if ($can_cost): ?><div class="small text-muted px-3 pb-3"><i class="bi bi-info-circle me-1"></i><?= t('Expected only, before you sell: staff, rent and plates that do not sell are not included — gas or charcoal only if you put it in Other costs. Your real profit is in the Profit Report.') ?></div><?php endif; ?>
            </div>
            <?php if ($can_move && rcpRequestsReady($pdo)): ?>
            <div class="cp-card mb-3 d-none" id="cpReqCard">
                <div class="cp-card-h d-flex justify-content-between align-items-center"><h6><i class="bi bi-inbox me-1"></i><?= t('Stock requests') ?> <span class="badge bg-warning text-dark ms-1" id="cpReqCount"></span></h6></div>
                <div id="cpReqs" class="p-2"></div>
            </div>
            <?php endif; ?>
            <?php if ($can_move): ?>
            <div class="cp-card">
                <div class="cp-card-h"><h6><i class="bi bi-box-seam me-1"></i><?= t('Kitchen stock') ?></h6></div>
                <div id="cpStock" class="p-2"></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.cp-page .cp-stat { background:#e7f0ff; border:1px solid #b6ccfe; }
.cp-page .cp-card { background:#fff; border:1px solid #dbe6fb; border-radius:14px; box-shadow:0 1px 3px rgba(13,110,253,.06); overflow:hidden; }
.cp-page .cp-card-h { padding:10px 14px; border-bottom:1px solid #eef3fd; }
.cp-page .cp-card-h h6 { margin:0; font-weight:700; color:#0b3d91; }
.cp-page .cp-note { background:#f5f9ff; border:1px dashed #9ec5fe; border-radius:10px; padding:10px 12px; font-size:.9rem; }
.cp-page .cp-sum { background:#f5f9ff; border:1px dashed #9ec5fe; border-radius:12px; padding:12px 14px; }
.cp-page .cp-sum:empty { display:none; }   /* nothing chosen yet: no empty box */
.cp-page .cp-sum .cp-row, .cp-page .cp-plan .cp-row { display:flex; justify-content:space-between; gap:8px; padding:2px 0; }
.cp-page .cp-sum .cp-total { border-top:1px solid #cfe2ff; margin-top:4px; padding-top:6px; font-weight:700; }
.cp-page .cp-loss { color:#b45309; }
.cp-page .cp-line { display:flex; align-items:center; gap:8px; padding:8px 0; border-bottom:1px solid #eef3fd; }
.cp-page .cp-line .cp-qty { width:150px; flex:0 0 150px; }
.cp-page .cp-plan { border:1px solid #e3ecfb; border-radius:12px; padding:10px 12px; margin:6px; }
.cp-page .cp-earlier { border:1px solid #f5d18b; background:#fff8e6; border-radius:12px; padding:10px 12px; }
.cp-page .cp-earlier .cp-plan { background:#fff; margin:8px 0 0; }
.cp-page .cp-plan.cp-closed { background:#fafcff; }
.cp-page .cp-badge { font-size:.75rem; border-radius:999px; padding:2px 8px; background:#cfe2ff; color:#084298; }
.cp-page .cp-badge.cp-done { background:#e9ecef; color:#495057; }
.cp-page .cp-stock-row { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 10px; border-bottom:1px solid #eef3fd; }
.cp-page .cp-empty { text-align:center; color:#6c757d; padding:24px 8px; }
/* Pop-up boxes the full width of the pop-up, so their hints are never cut off (on a phone too) */
.swal2-popup .cp-swal-in { width:100%; max-width:100%; margin:8px 0 0; box-sizing:border-box; }
</style>

<script>
const CP = {
    api: <?= json_encode(buildUrl('api/restaurant/cook_plan.php')) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    canEdit: <?= json_encode($can_edit) ?>, canMove: <?= json_encode($can_move) ?>, canCost: <?= json_encode($can_cost) ?>,
    canAsk: <?= json_encode($can_ask) ?>,   // Phase 4: lines become a request for the storekeeper
    cur: <?= json_encode(getSetting('currency', 'TZS')) ?>,
    s: {
        pickDish: <?= json_encode(t('Choose a dish...')) ?>, pickStock: <?= json_encode(t('+ Take an item from stock (type to search)...')) ?>,
        pots: <?= json_encode(t('How many pots?')) ?>, plates: <?= json_encode(t('How many plates?')) ?>,
        equals: <?= json_encode(t('= %s plates')) ?>, yieldHelp: <?= json_encode(t('One pot of this dish gives %d plates (set in its recipe on the Menu).')) ?>,
        noYield: <?= json_encode(t('This dish has no recipe yet, so each "pot" is one plate. Add a recipe on the Menu to cook by the pot.')) ?>,
        comps: <?= json_encode(t('These come from the recipe and leave stock as each plate is sold — do not take them here:')) ?>,
        perPlate: <?= json_encode(t('%s per plate')) ?>, left: <?= json_encode(t('%s left')) ?>,
        notEnough: <?= json_encode(t('more than in stock')) ?>,
        plannedPlates: <?= json_encode(t('Plates')) ?>, recipeCost: <?= json_encode(t('Cost from the recipe')) ?>, stockCost: <?= json_encode(t('Taken from stock')) ?>,
        totalCost: <?= json_encode(t('Total cost')) ?>, sales: <?= json_encode(t('Expected sales')) ?>, gp: <?= json_encode(t('Expected gross profit')) ?>,
        foodPct: <?= json_encode(t('Food cost (share of the selling price)')) ?>, ifAll: <?= json_encode(t('If all %s plates sell')) ?>,
        noCost: <?= json_encode(t('This dish has no cost yet: add a recipe on the Menu (or take items from stock), or the profit will look too high.')) ?>,
        partCost: <?= json_encode(t('This dish has no recipe: only what you take from stock is counted. Meat, spices or anything bought at the market are missing, so the profit looks too high. Add a recipe (other costs) on the Menu.')) ?>,
        lowCost: <?= json_encode(t('Food cost is only %s — very low for a cooked dish. Is something missing from the recipe?')) ?>,
        loading: <?= json_encode(t('Loading…')) ?>, noDishes: <?= json_encode(t('No dishes on the Menu yet — add them on the Menu first.')) ?>,
        ingredients: <?= json_encode(t('Ingredients')) ?>, forSale: <?= json_encode(t('Drinks & items for sale')) ?>,
        platesOnly: <?= json_encode(t('%s plates')) ?>, platesLeftOf: <?= json_encode(t('How many plates of %s are left?')) ?>,
        keepLeft: <?= json_encode(t('Keep the plates left to sell later (tomorrow)')) ?>,
        keepHelp: <?= json_encode(t('Not ticked: the plates left are thrown away — their ingredients leave stock as waste today.')) ?>,
        wasteDone: <?= json_encode(t('%1$s plate(s) not sold: their ingredients left stock as waste (%2$s).')) ?>,
        wasteDoneNoCost: <?= json_encode(t('%s plate(s) not sold: their ingredients left stock as waste.')) ?>,
        unsoldLine: <?= json_encode(t('Cooked, not sold')) ?>, keptLine: <?= json_encode(t('kept to sell later')) ?>,
        showDrinks: <?= json_encode(t('Show drinks & items for sale (%d)')) ?>,
        noPlans: <?= json_encode(t('Nothing cooked yet. Choose a dish and how many pots, then Start cooking.')) ?>,
        noPlansDay: <?= json_encode(t('Nothing was cooked on this day.')) ?>,
        today: <?= json_encode(t('Today\'s cooking')) ?>, dayOf: <?= json_encode(t('Cooking on %s')) ?>,
        open: <?= json_encode(t('Cooking')) ?>, closed: <?= json_encode(t('Done')) ?>,
        potsShort: <?= json_encode(t('pot(s)')) ?>, platesPot: <?= json_encode(t('%d plates a pot')) ?>,
        allCooks: <?= json_encode(t('all %d cooks of this dish today')) ?>, sold: <?= json_encode(t('Sold')) ?>, leftL: <?= json_encode(t('Left')) ?>,
        unacc: <?= json_encode(t('Not accounted for')) ?>, unaccHelp: <?= json_encode(t('eaten by staff, given away or spilled?')) ?>,
        cost: <?= json_encode(t('Cost')) ?>, platesLeftBtn: <?= json_encode(t('Plates left')) ?>, cancel: <?= json_encode(t('Cancel')) ?>,
        platesLeftQ: <?= json_encode(t('How many plates are left?')) ?>, platesLeftHelp: <?= json_encode(t('Count what is left in the pot at the end of the day. Sold plates come from the till.')) ?>,
        cancelQ: <?= json_encode(t('Cancel this cooking?')) ?>, cancelTxt: <?= json_encode(t('Use this only for a mistake. Anything taken from stock goes back to stock.')) ?>,
        yesCancel: <?= json_encode(t('Yes, cancel it')) ?>, no: <?= json_encode(t('No')) ?>, save: <?= json_encode(t('Save')) ?>,
        spoiled: <?= json_encode(t('Spoiled')) ?>, spoiledQ: <?= json_encode(t('Spoiled: %s')) ?>, spoiledQty: <?= json_encode(t('How much is spoiled? (%s)')) ?>,
        reason: <?= json_encode(t('Why? (optional, e.g. weevils, went bad)')) ?>, inStock: <?= json_encode(t('In stock')) ?>,
        noStock: <?= json_encode(t('No counted items in stock. Rice, oil or flour you buy for several days appear here.')) ?>,
        spoilNote: <?= json_encode(t('It leaves stock and counts as waste today.')) ?>,
        error: <?= json_encode(t('Error')) ?>, serverError: <?= json_encode(t('Server error. Please try again.')) ?>,
        saving: <?= json_encode(t('Saving...')) ?>, chooseDish: <?= json_encode(t('Choose a dish from the Menu.')) ?>,
        enterPots: <?= json_encode(t('Enter how many pots you cook (more than 0, up to 100).')) ?>,
        remove: <?= json_encode(t('Remove')) ?>,
        enoughFor: <?= json_encode(t('Your stock is enough for %1$s plates — %2$s runs out first.')) ?>,
        onlyFor: <?= json_encode(t('Not enough stock: only %1$s plates — %2$s runs out first. Buy more, or cook less.')) ?>,
        requested: <?= json_encode(t('asked for')) ?>, rejected: <?= json_encode(t('rejected')) ?>,
        reqBy: <?= json_encode(t('Asked by %s')) ?>, give: <?= json_encode(t('Give out')) ?>, reject: <?= json_encode(t('Reject')) ?>,
        giveQ: <?= json_encode(t('Give out this stock?')) ?>, giveTxt: <?= json_encode(t('It leaves stock now and its cost counts today.')) ?>,
        rejectQ: <?= json_encode(t('Reject this request?')) ?>, yes: <?= json_encode(t('Yes')) ?>,
        earlierT: <?= json_encode(t('Still open from earlier days')) ?>,
        earlierH: <?= json_encode(t('Close each one: count the plates left. Until you do, its plates not sold are not counted.')) ?>,
        cookedOn: <?= json_encode(t('Cooked on %s')) ?>, realGp: <?= json_encode(t('Real gross profit')) ?>,
        realGpH: <?= json_encode(t('sold × price − cost')) ?>,
    }
};
CP.canTake = CP.canMove || CP.canAsk;   // the "from stock" lines: taken straight away, or asked for
const cpFmt = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
const cpQty = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 3 });
const nm = s => safeOutput(caseFormatJs(String(s || '')));
let CPD = { dishes: [], stock: [], plans: [], today: '' };
let CPX = { dish: null, lines: [], fromLast: false };   // the dish being planned, lines taken from stock

function cpWh() { return parseInt($('#cpShop').val() || 0, 10); }
function cpIsToday() { return $('#cpDate').val() === CPD.today; }

$(function () {
    if (!$('#cpShop').length) return;
    if ($('#cpShop').is('select')) $('#cpShop').on('change', cpInit);
    $('#cpDate').on('change', cpLoadDay);
    if (CP.canEdit) {
        // "Loading…" and disabled until the dishes arrive — never an empty "No results found" on a slow phone
        $('#cpDish').prop('disabled', true).select2({ theme: 'bootstrap-5', width: '100%', placeholder: CP.s.loading }).on('change', cpPickDish);
        $('#cpPots').on('input change', function () { cpScaleLast(); cpRender(); });
        $('#cpSave').on('click', cpSave);
        if (CP.canTake) {
            $('#cpPick').select2({ theme: 'bootstrap-5', width: '100%', placeholder: CP.s.pickStock, allowClear: true })
                .on('select2:select', function (e) { cpAddLine(parseInt(e.params.data.id, 10)); $(this).val(null).trigger('change'); });
        }
    }
    cpInit();
});

function cpInit() {
    $.getJSON(CP.api, { action: 'init', warehouse_id: cpWh() }, function (r) {
        if (!r.success) { Swal.fire({ icon: 'error', title: CP.s.error, text: r.message }); return; }
        CPD = { dishes: r.dishes, stock: r.stock, plans: r.plans, today: r.today, earlier: r.earlier || [] };
        if (CP.canEdit) {
            const sel = $('#cpDish').empty().append(new Option('', '', true, true));
            r.dishes.forEach(d => sel.append(new Option(caseFormatJs(d.name) + (d.yield > 1 ? ' · ' + CP.s.platesPot.replace('%d', d.yield) : ''), d.product_id, false, false)));
            if (sel.hasClass('select2-hidden-accessible')) sel.select2('destroy');
            sel.prop('disabled', false).select2({ theme: 'bootstrap-5', width: '100%', placeholder: r.dishes.length ? CP.s.pickDish : CP.s.noDishes });
            CPX = { dish: null, lines: [], fromLast: false };
            cpFillPick(r.stock); cpRender();
        }
        $('#cpDate').val(r.today).attr('max', r.today);
        cpRenderPlans(r.plans); cpRenderStock(r.stock); cpRenderRequests(r.requests || []); cpRenderEarlier();
    }).fail(() => Swal.fire({ icon: 'error', title: CP.s.error, text: CP.s.serverError }));
}

function cpFillPick(stock) {
    if (!CP.canTake) return;
    // Ingredients first; drinks sold on the Menu (soda, bottled water) in their own group so they do not crowd cooking
    const sel = $('#cpPick').empty().append(new Option('', '', true, true));
    const opt = s => new Option(`${caseFormatJs(s.name)} — ${cpQty(s.stock)} ${s.unit}`, s.product_id, false, false);
    const ing = stock.filter(s => !s.for_sale), sale = stock.filter(s => s.for_sale);
    if (ing.length) { const g = $('<optgroup>').attr('label', CP.s.ingredients); ing.forEach(s => g.append(opt(s))); sel.append(g); }
    if (sale.length) { const g = $('<optgroup>').attr('label', CP.s.forSale); sale.forEach(s => g.append(opt(s))); sel.append(g); }
    sel.trigger('change.select2');
}

function cpPickDish() {
    const id = parseInt($('#cpDish').val() || 0, 10);
    if (!id) { CPX = { dish: null, lines: [], fromLast: false }; cpRender(); return; }
    $.getJSON(CP.api, { action: 'dish', warehouse_id: cpWh(), product_id: id }, function (r) {
        if (!r.success) { Swal.fire({ icon: 'error', title: CP.s.error, text: r.message }); return; }
        CPX.dish = r.dish; CPX.stock = r.stock;
        cpFillPick(r.stock);
        // What was taken per pot the last time this dish was cooked, scaled to the pots now.
        const pots = parseFloat($('#cpPots').val()) || 1;
        CPX.lines = CP.canTake ? r.last.map(l => { const s = r.stock.find(x => x.product_id === l.product_id); return s ? { ...s, qty: Math.round(l.qty_per_pot * pots * 1000) / 1000, perPot: l.qty_per_pot } : null; }).filter(Boolean) : [];
        CPX.fromLast = CPX.lines.length > 0;
        cpRender();
    });
}
function cpScaleLast() {
    if (!CPX.fromLast) return;
    const pots = parseFloat($('#cpPots').val()) || 0;
    CPX.lines.forEach(l => { if (l.perPot) l.qty = Math.round(l.perPot * pots * 1000) / 1000; });
}
function cpAddLine(id) {
    const s = (CPX.stock || CPD.stock).find(x => x.product_id === id);
    if (!s || CPX.lines.some(l => l.product_id === id)) return;
    CPX.lines.push({ ...s, qty: 0 }); CPX.fromLast = false;
    cpRender(); setTimeout(() => $('#cpLines .cp-qty-in').last().focus(), 50);
}
function cpSetQty(i, v) { const l = CPX.lines[i]; if (!l) return; l.qty = Math.max(0, parseFloat(String(v).replace(',', '.')) || 0); l.perPot = null; CPX.fromLast = false; cpRender(true); }
function cpDelLine(i) { CPX.lines.splice(i, 1); cpRender(); }

/** The form: plates, ingredients note, stock lines and the money summary. keepInputs: do not redraw the lines (typing). */
function cpRender(keepInputs) {
    const d = CPX.dish, pots = parseFloat($('#cpPots').val()) || 0;
    const y = d ? d.yield : 1, plates = Math.round(pots * y * 100) / 100;
    $('#cpPotsLbl').contents().first().replaceWith(y > 1 || !d ? CP.s.pots : CP.s.plates);
    $('#cpPlatesNote').text(d && y > 1 ? CP.s.equals.replace('%s', cpFmt(plates)) : '');
    $('#cpYieldHelp').text(!d ? '' : (y > 1 ? CP.s.yieldHelp.replace('%d', y) : CP.s.noYield));
    // The recipe's ingredients: they leave stock with each plate sold.
    if (d && d.components.length) {
        $('#cpComps').removeClass('d-none').html(`<div class="small fw-semibold mb-1"><i class="bi bi-info-circle me-1"></i>${CP.s.comps}</div>`
            + d.components.map(c => `<div class="small">• ${nm(c.name)} — ${CP.s.perPlate.replace('%s', cpQty(c.qty_per_plate) + ' ' + safeOutput(c.unit))} · ${CP.s.left.replace('%s', cpQty(c.stock) + ' ' + safeOutput(c.unit))}</div>`).join(''));
    } else $('#cpComps').addClass('d-none').empty();
    // Phase 4 — stock check: the recipe's ingredient that runs out first decides how many plates the stock allows.
    if (d && d.max_plates !== null && d.max_plates !== undefined && plates > 0) {
        const short = plates > d.max_plates;
        $('#cpCheck').removeClass('d-none').toggleClass('cp-loss fw-semibold', short).toggleClass('text-muted', !short)
            .html(`<i class="bi ${short ? 'bi-exclamation-triangle' : 'bi-check2-circle'} me-1"></i>`
                + (short ? CP.s.onlyFor : CP.s.enoughFor).replace('%1$s', cpFmt(d.max_plates)).replace('%2$s', nm(d.limiting)));
    } else $('#cpCheck').addClass('d-none').empty();
    // Lines taken from stock (or asked for).
    if (CP.canTake && !keepInputs) {
        $('#cpLines').html(CPX.lines.map((l, i) => `
            <div class="cp-line">
                <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate">${nm(l.name)}</div>
                    <div class="small text-muted" id="cpLeft${i}"></div></div>
                <div class="input-group input-group-sm cp-qty"><input type="number" class="form-control text-end cp-qty-in" min="0" step="any" value="${l.qty > 0 ? l.qty : ''}" placeholder="0" inputmode="decimal" oninput="cpSetQty(${i}, this.value)"><span class="input-group-text">${safeOutput(l.unit)}</span></div>
                <button type="button" class="btn btn-sm btn-link text-danger p-0" onclick="cpDelLine(${i})" aria-label="${CP.s.remove}"><i class="bi bi-x-circle fs-5"></i></button>
            </div>`).join(''));
    }
    CPX.lines.forEach((l, i) => {
        const over = l.qty > l.stock + 0.0005;
        $(`#cpLeft${i}`).toggleClass('text-danger', over).text(over ? `${CP.s.left.replace('%s', cpQty(l.stock) + ' ' + l.unit)} — ${CP.s.notEnough}` : CP.s.left.replace('%s', cpQty(Math.max(0, l.stock - l.qty)) + ' ' + l.unit));
    });
    if (!CP.canCost) return;
    if (!d || plates <= 0) { $('#cpSum').html(''); return; }
    const recipe = Math.round(d.plate_cost * plates), stock = Math.round(CPX.lines.reduce((s, l) => s + (l.qty || 0) * (l.cost_price || 0), 0));
    const total = recipe + stock, sales = Math.round(plates * d.selling_price), gp = sales - total;
    const row = (l, v, cls) => `<div class="cp-row ${cls || ''}"><span>${l}</span><span>${v}</span></div>`;
    let h = `<div class="fw-semibold text-primary mb-1">${CP.s.ifAll.replace('%s', cpFmt(plates))}</div>`;
    h += row(CP.s.sales + ` <small class="text-muted">(${cpFmt(plates)} × ${cpFmt(d.selling_price)})</small>`, cpFmt(sales));
    if (recipe > 0) h += row(CP.s.recipeCost, '− ' + cpFmt(recipe));
    if (stock > 0) h += row(CP.s.stockCost, '− ' + cpFmt(stock));
    h += row(`<strong>${CP.s.gp}</strong>`, `<strong class="${gp < 0 ? 'cp-loss' : 'text-primary'}">${cpFmt(gp)} ${safeOutput(CP.cur)}</strong>`, 'cp-total');
    if (total > 0 && sales > 0) h += row(`<small class="text-muted">${CP.s.foodPct}</small>`, `<small class="text-muted">${Math.round(total / sales * 100)}%</small>`);
    // Honest about what is missing: with no recipe only the stock taken is counted (meat, spices bought at the market
    // are not), and a food cost far below normal usually means something was left out.
    const warn = t => `<div class="small cp-loss mt-2"><i class="bi bi-exclamation-triangle me-1"></i>${t}</div>`;
    if (!d.has_recipe) h += warn(stock > 0 ? CP.s.partCost : CP.s.noCost);
    else if (total > 0 && sales > 0 && total / sales < 0.15) h += warn(CP.s.lowCost.replace('%s', Math.round(total / sales * 100) + '%'));
    $('#cpSum').html(h);
}

function cpSave() {
    const d = CPX.dish, pots = parseFloat($('#cpPots').val()) || 0;
    const fail = m => $('#cpMsg').html(`<div class="alert alert-danger py-2 mb-0">${safeOutput(m)}</div>`);
    if (!d) return fail(CP.s.chooseDish);
    if (pots <= 0 || pots > 100) return fail(CP.s.enterPots);
    $('#cpMsg').empty();
    const btn = $('#cpSave'), orig = btn.html();
    btn.prop('disabled', true).html(`<span class="spinner-border spinner-border-sm me-1"></span>${CP.s.saving}`);
    const lines = CPX.lines.filter(l => l.qty > 0).map(l => ({ product_id: l.product_id, qty: l.qty }));
    $.ajax({ url: CP.api, type: 'POST', dataType: 'json',
        data: { _csrf: CP.csrf, action: 'create', warehouse_id: cpWh(), product_id: d.product_id, pots: pots, lines: JSON.stringify(lines) },
        success: function (r) {
            if (!r.success) return fail(r.message || CP.s.error);
            Swal.fire({ icon: 'success', title: r.message, timer: r.requested ? 3200 : 1600, showConfirmButton: false });
            $('#cpDish').val('').trigger('change'); $('#cpPots').val(1);
            cpInit();
        },
        error: () => fail(CP.s.serverError),
        complete: () => btn.prop('disabled', false).html(orig)
    });
}

function cpLoadDay() {
    $.getJSON(CP.api, { action: 'day', warehouse_id: cpWh(), date: $('#cpDate').val() }, function (r) {
        if (!r.success) { Swal.fire({ icon: 'error', title: CP.s.error, text: r.message }); return; }
        cpRenderPlans(r.plans);
    });
}

/** The day's cooks as cards (same on phone and desktop). Sold is per dish and day, counted once in the totals. */
function cpRenderPlans(plans) {
    const today = cpIsToday();
    $('#cpDayTitle').text(today ? CP.s.today : CP.s.dayOf.replace('%s', $('#cpDate').val()));
    $('#cpFormCol').toggle(today);
    $('#cpEarlier').toggleClass('d-none', !today || !(CPD.earlier || []).length);
    let planned = 0, left = 0, anyLeft = false, gp = 0; const soldBy = {};
    plans.forEach(p => {
        planned += p.plates_planned; soldBy[p.product_id] = p.sold;
        if (p.plates_left !== null) { left += p.plates_left; anyLeft = true; }
        if (CP.canCost) gp += p.plates_planned * p.selling_price - p.recipe_cost - p.stock_cost;
    });
    const sold = Object.values(soldBy).reduce((a, b) => a + b, 0);
    $('#stPlanned').text(cpFmt(planned)); $('#stSold').text(cpFmt(sold)); $('#stLeft').text(anyLeft ? cpFmt(left) : '—');
    if (CP.canCost) $('#stGp').text(cpFmt(Math.round(gp))).toggleClass('cp-loss', gp < 0);
    if (!plans.length) { $('#cpPlans').html(`<div class="cp-empty"><i class="ri ri-cooking-pot fs-1 d-block mb-2 text-primary"></i>${today ? CP.s.noPlans : CP.s.noPlansDay}</div>`); return; }
    // The till's sales belong to the DISH and day, not to one cook: "not accounted for" is worked out per dish —
    // all its cooks' plates − sold − all its plates left — once every cook of it is closed, and shown on its last card.
    const byDish = {};
    plans.forEach(p => { const g = byDish[p.product_id] = byDish[p.product_id] || { n: 0, planned: 0, left: 0, open: 0, last: null, cost: 0 };
        g.n++; g.planned += p.plates_planned; g.left += p.plates_left || 0; if (p.status !== 'closed') g.open++; g.last = p.id;
        if (CP.canCost) g.cost += p.recipe_cost + p.stock_cost; });
    CPD.dayPlans = plans;   // for the "plates left" pop-up
    $('#cpPlans').html(plans.map(p => {
        const closed = p.status === 'closed', g = byDish[p.product_id];
        const unacc = (g.last === p.id && g.open === 0) ? Math.round((g.planned - p.sold - g.left) * 100) / 100 : null;
        const row = (l, v, cls) => `<div class="cp-row ${cls || ''}"><span>${l}</span><span>${v}</span></div>`;
        let h = `<div class="cp-plan ${closed ? 'cp-closed' : ''}">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="min-w-0"><div class="fw-bold text-truncate">${nm(p.name)}</div>
                    <div class="small text-muted">${Math.abs(p.pots - p.plates_planned) < 0.005
                        ? `<strong>${CP.s.platesOnly.replace('%s', cpFmt(p.plates_planned))}</strong>`   /* no recipe: plates were entered, not pots */
                        : `${cpFmt(p.pots)} ${CP.s.potsShort} → <strong>${cpFmt(p.plates_planned)}</strong> ${CP.s.plannedPlates.toLowerCase()}`}</div></div>
                <span class="cp-badge ${closed ? 'cp-done' : ''}">${closed ? CP.s.closed : CP.s.open}</span>
            </div>`;
        h += row(g.n > 1 ? `${CP.s.sold} <small class="text-muted">(${CP.s.allCooks.replace('%d', g.n)})</small>` : CP.s.sold, cpFmt(p.sold));
        if (p.plates_left !== null) h += row(CP.s.leftL, cpFmt(p.plates_left));
        if (unacc !== null && Math.abs(unacc) > 0.004) h += row(`${CP.s.unacc}${g.n > 1 ? ` <small class="text-muted">(${CP.s.allCooks.replace('%d', g.n)})</small>` : ''} <small class="text-muted">(${CP.s.unaccHelp})</small>`, `<span class="cp-loss fw-semibold">${cpFmt(unacc)}</span>`);
        // Phase 5: on the dish's last card, what the day's close sent to waste (plates cooked, not sold)
        if (g.last === p.id && p.unsold && (p.unsold.plates > 0 || p.unsold.kept_left > 0)) {
            h += row(`${CP.s.unsoldLine}${p.unsold.kept_left > 0 ? ` <small class="text-muted">(${cpFmt(p.unsold.kept_left)} ${CP.s.keptLine})</small>` : ''}`,
                     `<span class="cp-loss">${cpFmt(p.unsold.plates)}${CP.canCost && p.unsold.amount !== undefined ? ' · ' + cpFmt(p.unsold.amount) : ''}</span>`);
        }
        if (p.items.length) h += `<div class="small text-muted mt-1"><i class="bi bi-box-seam me-1"></i>${p.items.map(i => `${nm(i.name)} ${cpQty(i.qty)} ${safeOutput(i.unit)}`
            + (i.status === 'requested' ? ` <span class="cp-badge">${CP.s.requested}</span>` : i.status === 'rejected' ? ` <span class="cp-badge cp-done"><s>${CP.s.rejected}</s></span>` : '')).join(' · ')}</div>`;
        if (CP.canCost) {
            const cost = p.recipe_cost + p.stock_cost, gpv = Math.round(p.plates_planned * p.selling_price - cost);
            h += row(CP.s.cost, cpFmt(Math.round(cost))) + row(CP.s.gp, `<span class="${gpv < 0 ? 'cp-loss' : 'text-primary'} fw-semibold">${cpFmt(gpv)}</span>`);
            // Once every cook of the dish is closed, the real figure (the same as Planned vs actual): sold × price − all its cooks' cost
            if (g.last === p.id && g.open === 0) {
                const real = Math.round(p.sold * p.selling_price - g.cost);
                h += row(`${CP.s.realGp} <small class="text-muted">(${CP.s.realGpH})</small>`, `<span class="${real < 0 ? 'cp-loss' : 'text-success'} fw-bold">${cpFmt(real)}</span>`);
            }
        }
        if (CP.canEdit && today) {
            h += `<div class="d-flex gap-2 mt-2">
                <button class="btn btn-sm btn-outline-primary flex-fill" onclick="cpClose(${p.id})"><i class="bi bi-check2-square me-1"></i>${CP.s.platesLeftBtn}</button>
                ${!closed ? `<button class="btn btn-sm btn-outline-secondary" onclick="cpCancel(${p.id})"><i class="bi bi-x-circle me-1"></i>${CP.s.cancel}</button>` : ''}
            </div>`;
        }
        return h + '</div>';
    }).join(''));
}

/** Cooks of earlier days never closed: shown above today's cooks with their own "Plates left" button. */
function cpRenderEarlier() {
    const list = CPD.earlier || [];
    $('#cpEarlier').toggleClass('d-none', !list.length || !cpIsToday());
    if (!list.length) { $('#cpEarlier').empty(); return; }
    $('#cpEarlier').html(`<div class="fw-semibold"><i class="bi bi-exclamation-triangle me-1 text-warning"></i>${CP.s.earlierT} (${list.length})</div>
        <div class="small text-muted">${CP.s.earlierH}</div>`
        + list.map(p => `<div class="cp-plan">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="min-w-0"><div class="fw-bold text-truncate">${nm(p.name)}</div>
                    <div class="small text-muted">${CP.s.cookedOn.replace('%s', safeOutput(p.plan_date))} · ${cpFmt(p.plates_planned)} ${CP.s.plannedPlates.toLowerCase()} · ${CP.s.sold} ${cpFmt(p.sold)}</div></div>
                <span class="cp-badge">${CP.s.open}</span>
            </div>
            ${CP.canEdit ? `<button class="btn btn-sm btn-outline-primary w-100 mt-2" onclick="cpClose(${p.id})"><i class="bi bi-check2-square me-1"></i>${CP.s.platesLeftBtn}</button>` : ''}
        </div>`).join(''));
}

function cpClose(id) {
    const p = (CPD.dayPlans || []).find(x => x.id === id) || (CPD.earlier || []).find(x => x.id === id); if (!p) return;
    const planned = p.plates_planned, current = p.plates_left;
    // The "keep to sell later" choice only matters when the dish's recipe takes ingredients from stock (Phase 5);
    // a small restaurant without stock never sees it.
    const keepBox = p.stock_recipe
        ? `<label class="d-flex gap-2 align-items-start text-start mt-3 small" style="cursor:pointer"><input type="checkbox" id="cpKeep" class="form-check-input mt-1" ${p.keep_left ? 'checked' : ''}>
             <span><strong>${CP.s.keepLeft}</strong><br><span class="text-muted">${CP.s.keepHelp}</span></span></label>` : '';
    Swal.fire({ title: CP.s.platesLeftOf.replace('%s', caseFormatJs(p.name)) + (p.plan_date ? ` <small class="d-block text-muted fs-6 fw-normal">${CP.s.cookedOn.replace('%s', safeOutput(p.plan_date))}</small>` : ''),
                html: `<div class="small text-muted mb-2">${CP.s.platesLeftHelp}</div>
                       <input id="cpLeftIn" type="number" min="0" max="${planned}" step="any" inputmode="decimal" class="swal2-input" style="width:100%;max-width:100%;margin:0;box-sizing:border-box" value="${current === null ? '' : current}">${keepBox}`,
                showCancelButton: true, confirmButtonText: CP.s.save, cancelButtonText: CP.s.cancel, confirmButtonColor: '#0d6efd',
                didOpen: () => setTimeout(() => $('#cpLeftIn').trigger('focus'), 50),
                preConfirm: () => {
                    const v = $('#cpLeftIn').val();
                    if (v === '' || isNaN(parseFloat(v)) || parseFloat(v) < 0 || parseFloat(v) > planned) { Swal.showValidationMessage(`0 – ${cpFmt(planned)}`); return false; }
                    return { left: v, keep: $('#cpKeep').is(':checked') ? 1 : 0 };
                } })
    .then(r => {
        if (!r.isConfirmed) return;
        $.post(CP.api, { _csrf: CP.csrf, action: 'close', plan_id: id, plates_left: r.value.left, keep_left: r.value.keep }, function (res) {
            if (!res.success) { Swal.fire({ icon: 'error', title: CP.s.error, text: res.message }); return; }
            const waste = res.waste_plates > 0
                ? (res.waste_cost !== undefined ? CP.s.wasteDone.replace('%1$s', cpFmt(res.waste_plates)).replace('%2$s', cpFmt(res.waste_cost) + ' ' + CP.cur)
                                                : CP.s.wasteDoneNoCost.replace('%s', cpFmt(res.waste_plates))) : '';
            Swal.fire({ icon: 'success', title: res.message, text: waste, timer: waste ? 3500 : 1500, showConfirmButton: false });
            cpInit();
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: CP.s.error, text: CP.s.serverError }));
    });
}
function cpCancel(id) {
    Swal.fire({ icon: 'warning', title: CP.s.cancelQ, text: CP.s.cancelTxt, showCancelButton: true, confirmButtonColor: '#dc3545',
                confirmButtonText: CP.s.yesCancel, cancelButtonText: CP.s.no })
    .then(r => {
        if (!r.isConfirmed) return;
        $.post(CP.api, { _csrf: CP.csrf, action: 'cancel', plan_id: id }, function (res) {
            if (!res.success) { Swal.fire({ icon: 'error', title: CP.s.error, text: res.message }); return; }
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false });
            cpInit();
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: CP.s.error, text: CP.s.serverError }));
    });
}

/** Phase 4 — the cooks' stock requests, for whoever may give stock out: Give out / Reject. */
function cpRenderRequests(reqs) {
    if (!$('#cpReqCard').length) return;
    $('#cpReqCard').toggleClass('d-none', !reqs.length); $('#cpReqCount').text(reqs.length || '');
    $('#cpReqs').html(reqs.map(q => `<div class="cp-plan">
            <div class="d-flex justify-content-between gap-2"><div class="min-w-0"><div class="fw-bold text-truncate">${nm(q.dish)} · ${cpFmt(q.pots)} ${CP.s.potsShort}</div>
                <div class="small text-muted">${CP.s.reqBy.replace('%s', safeOutput(q.by || '—'))}</div></div></div>
            ${q.lines.map(l => { const short = l.qty > l.stock + 0.0005;
                return `<div class="cp-row small"><span>${nm(l.name)}: <strong>${cpQty(l.qty)} ${safeOutput(l.unit)}</strong></span><span class="${short ? 'cp-loss fw-semibold' : 'text-muted'}">${CP.s.inStock}: ${cpQty(l.stock)} ${safeOutput(l.unit)}</span></div>`; }).join('')}
            <div class="d-flex gap-2 mt-2">
                <button class="btn btn-sm btn-primary flex-fill" onclick="cpDecide(${q.plan_id}, 'approve')"><i class="bi bi-box-arrow-right me-1"></i>${CP.s.give}</button>
                <button class="btn btn-sm btn-outline-secondary" onclick="cpDecide(${q.plan_id}, 'reject')"><i class="bi bi-x-circle me-1"></i>${CP.s.reject}</button>
            </div></div>`).join(''));
}
function cpDecide(planId, decision) {
    const give = decision === 'approve';
    Swal.fire({ icon: 'question', title: give ? CP.s.giveQ : CP.s.rejectQ, text: give ? CP.s.giveTxt : '', showCancelButton: true,
                confirmButtonText: CP.s.yes, cancelButtonText: CP.s.no, confirmButtonColor: give ? '#0d6efd' : '#6c757d' })
    .then(r => {
        if (!r.isConfirmed) return;
        $.post(CP.api, { _csrf: CP.csrf, action: 'decide', plan_id: planId, decision: decision }, function (res) {
            if (!res.success) { Swal.fire({ icon: 'error', title: CP.s.error, text: res.message }); return; }
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false });
            cpInit();
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: CP.s.error, text: CP.s.serverError }));
    });
}

/** Kitchen stock: what is on hand, and "Spoiled". */
function cpRenderStock(stock) {
    if (!CP.canMove) return;
    if (!stock.length) { $('#cpStock').html(`<div class="cp-empty">${CP.s.noStock}</div>`); return; }
    const rowOf = s => `<div class="cp-stock-row">
            <div class="min-w-0"><div class="fw-semibold text-truncate">${nm(s.name)}</div><div class="small text-muted">${CP.s.inStock}: ${cpQty(s.stock)} ${safeOutput(s.unit)}${CP.canCost && s.cost_price ? ' · ' + cpFmt(s.cost_price) + ' / ' + safeOutput(s.unit) : ''}</div></div>
            <button class="btn btn-sm btn-outline-danger flex-shrink-0" onclick="cpSpoil(${s.product_id})"><i class="bi bi-trash3 me-1"></i>${CP.s.spoiled}</button>
        </div>`;
    // Ingredients in view; drinks sold on the Menu folded away (they are stocked for sale, rarely cooked or spoiled)
    const ing = stock.filter(s => !s.for_sale), sale = stock.filter(s => s.for_sale);
    let h = ing.map(rowOf).join('') || `<div class="cp-empty py-2">${CP.s.noStock}</div>`;
    if (sale.length) h += `<a href="#" class="d-block small fw-semibold px-2 py-2" onclick="$(this).next().toggleClass('d-none');return false;"><i class="bi bi-cup-straw me-1"></i>${CP.s.showDrinks.replace('%d', sale.length)}</a>
                           <div class="d-none">${sale.map(rowOf).join('')}</div>`;
    $('#cpStock').html(h);
}
function cpSpoil(id) {
    const s = CPD.stock.find(x => x.product_id === id); if (!s) return;
    Swal.fire({ title: CP.s.spoiledQ.replace('%s', caseFormatJs(s.name)), confirmButtonText: CP.s.save, showCancelButton: true, cancelButtonText: CP.s.cancel, confirmButtonColor: '#dc3545',
        html: `<div class="small text-muted mb-2">${CP.s.inStock}: ${cpQty(s.stock)} ${safeOutput(s.unit)} · ${CP.s.spoilNote}</div>
               <input id="spQ" type="number" min="0" step="any" class="swal2-input cp-swal-in" placeholder="${safeOutput(CP.s.spoiledQty.replace('%s', s.unit))}" inputmode="decimal">
               <input id="spR" class="swal2-input cp-swal-in" maxlength="255" placeholder="${safeOutput(CP.s.reason)}">`,
        preConfirm: () => {
            const q = parseFloat($('#spQ').val());
            if (!(q > 0) || q > s.stock + 0.0005) { Swal.showValidationMessage(`0 – ${cpQty(s.stock)} ${s.unit}`); return false; }
            return { qty: q, reason: $('#spR').val().trim() };
        }
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post(CP.api, { _csrf: CP.csrf, action: 'spoil', warehouse_id: cpWh(), product_id: id, qty: r.value.qty, reason: r.value.reason }, function (res) {
            if (!res.success) { Swal.fire({ icon: 'error', title: CP.s.error, text: res.message }); return; }
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false });
            cpInit();
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: CP.s.error, text: CP.s.serverError }));
    });
}
</script>

<?php
require_once 'footer.php';
ob_end_flush();
