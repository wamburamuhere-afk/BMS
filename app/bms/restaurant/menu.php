<?php
/**
 * app/bms/restaurant/menu.php — restaurant Menu (restaurant_plan.md Phase 1b).
 *
 * One place for everything a restaurant shop sells:
 *   - Dish (cooked here)      → no-stock item, always sells, no recipe needed
 *   - Bought item (has stock) → water, soda… with opening stock
 *   - Already in my products  → put an existing product on the menu (menu flags only)
 * Each row has an "Available today" switch (sold-out items hide from selling).
 *
 * Only for Smart POS + Restaurant POS tenants, on Restaurant/Hybrid shops
 * (core/restaurant_scope.php::restaurantModuleOn / restaurantShopActive).
 * Retail shops never see this page or any of its data changes.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_scope.php';
require_once __DIR__ . '/../../../core/restaurant_online.php';

$page_title = 'Menu';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$can_edit    = canEdit('restaurant_pos');
$module_on   = restaurantModuleOn($pdo);
$warehouses  = $module_on ? restaurantWarehousesForSelect($pdo) : [];
$categories  = $pdo->query("SELECT category_id, category_name FROM categories WHERE type = 'product' AND status = 'active' ORDER BY sort_order, category_name")->fetchAll(PDO::FETCH_ASSOC);
$can_share   = $can_edit && $warehouses && roSchemaReady($pdo);   // online menu & QR (restaurant_online_menu_plan.md)
$starterGroups = [t('Breakfast'), t('Main meals'), t('Soups'), t('Snacks'), t('Drinks')];
?>

<div class="container-fluid mt-3 mt-md-4 rm-page">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 rm-head">
        <h4 class="mb-0 text-truncate"><i class="ri ri-book-open-text text-primary me-2"></i><?= t('Menu') ?></h4>
        <div class="d-flex gap-2 flex-shrink-0">
            <a href="<?= getUrl('restaurant') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i><span class="d-none d-md-inline ms-1"><?= t('Restaurant') ?></span></a>
            <?php if ($can_share): ?>
            <button class="btn btn-outline-primary btn-sm" onclick="openShareMenu()"><i class="bi bi-qr-code"></i><span class="d-none d-md-inline ms-1"><?= t('Share menu') ?></span></button>
            <?php endif; ?>
            <?php if ($can_edit && $warehouses): ?>
            <button class="btn btn-primary btn-sm" onclick="openAddItem()"><i class="bi bi-plus-circle me-1"></i><?= t('Add Item') ?></button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$module_on): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
        <?= t('The Menu is available for restaurant shops in Smart POS.') ?>
    </div>
    <?php elseif (empty($warehouses)): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><i class="bi bi-info-circle me-1"></i>
            <?= wLabel("No warehouse in your scope is set to Restaurant or Hybrid mode yet. Switch a warehouse's POS Mode first.", "No shop in your scope is set to Restaurant or Hybrid mode yet. Switch a shop's POS Mode first.") ?>
        </div>
        <?php if (canEdit('warehouses')): ?>
        <a href="<?= getUrl('warehouses') ?>" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-gear me-1"></i><?= wLabel('Go to Warehouses', 'Go to Shops') ?></a>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <?php if (count($warehouses) > 1): ?>
    <div class="mb-3" style="max-width:320px;">
        <label class="form-label small mb-1"><?= wLabel('Warehouse', 'Shop') ?></label>
        <select id="whSelect" class="form-select form-select-sm select2-static">
            <?php foreach ($warehouses as $w): ?>
            <option value="<?= (int)$w['warehouse_id'] ?>"><?= caseFormat($w['warehouse_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
    <input type="hidden" id="whSelect" value="<?= (int)$warehouses[0]['warehouse_id'] ?>">
    <?php endif; ?>

    <div class="row g-2 g-md-3 mb-3">
        <div class="col-6 col-md-3"><div class="card rm-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary" id="stTotal">0</div><div class="small text-muted"><?= t('Items') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card rm-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary" id="stAvail">0</div><div class="small text-muted"><?= t('Available today') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card rm-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-danger" id="stOut">0</div><div class="small text-muted"><?= t('Sold out') ?></div></div></div>
        <div class="col-6 col-md-3"><div class="card rm-stat text-center p-2 p-md-3"><div class="fs-4 fw-bold text-primary" id="stGroups">0</div><div class="small text-muted"><?= t('Groups') ?></div></div></div>
    </div>

    <div class="d-flex flex-column flex-md-row gap-2 mb-2 align-items-md-center">
        <div class="input-group input-group-sm rm-search">
            <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
            <input type="search" id="rmSearch" class="form-control" placeholder="<?= htmlspecialchars(t('Search the menu...')) ?>">
        </div>
        <div id="rmChips" class="rm-chips"></div>
    </div>

    <div id="tableView" class="d-none d-md-block">
        <table id="menuTable" class="table table-hover align-middle w-100" data-bms-cards="off">
            <thead class="table-dark">
                <tr>
                    <th><?= t('Item') ?></th>
                    <th><?= t('Group') ?></th>
                    <th><?= t('Type') ?></th>
                    <th><?= t('Prepared at') ?></th>
                    <th class="text-end"><?= t('Selling price') ?></th>
                    <th class="text-end"><?= t('Cost') ?></th>
                    <th class="text-end"><?= t('Profit') ?></th>
                    <th class="text-end"><?= t('Stock') ?></th>
                    <th class="text-center"><?= t('Available today') ?></th>
                    <th class="text-end"><?= t('Actions') ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
    <div id="cardView" class="row g-2 d-md-none"></div>

    <?php endif; ?>
</div>

<?php if ($can_edit && $warehouses): ?>
<!-- Add / Edit Menu Item (one form for both; restaurant form layout — includes/restaurant/form_kit.php) -->
<?php require_once __DIR__ . '/../../../includes/restaurant/form_kit.php'; rfKitAssets(); $rfCur = htmlspecialchars(rfMoneyPrefix()); ?>
<div class="modal fade rf-modal" id="itemModal" tabindex="-1" aria-labelledby="itemModalTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-journal-richtext"></i><span id="itemModalTitle"><?= t('Add Item') ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
            </div>
            <form id="itemForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="product_id" id="f-id">
                    <input type="hidden" name="item_type" id="f-type" value="dish">
                    <div id="item-message" class="mb-2"></div>

                    <div class="rf-sec" id="typeChooser">
                        <div class="rf-sec-title"><i class="bi bi-ui-radios-grid"></i><?= t('What is this?') ?></div>
                        <?php $rfThree = !restaurantOnlyCompany($pdo); ?>
                        <div class="rf-choices <?= $rfThree ? 'rf-c3' : 'rf-c2' ?>">
                            <button type="button" class="rf-choice rm-type" data-type="dish">
                                <i class="ri ri-cooking-pot fs-4 text-primary"></i>
                                <span><span class="rf-ct"><?= t('Dish (cooked here)') ?></span><span class="rf-cd"><?= t('Chai, mandazi, lunch, soup… sells without stock.') ?></span></span>
                            </button>
                            <button type="button" class="rf-choice rm-type" data-type="bought">
                                <i class="ri ri-cup-soda fs-4 text-primary"></i>
                                <span><span class="rf-ct"><?= t('Bought item (has stock)') ?></span><span class="rf-cd"><?= t('Water, soda… you buy it and count the stock.') ?></span></span>
                            </button>
                            <?php if ($rfThree): // a restaurant-only company has no retail products to bring in ?>
                            <button type="button" class="rf-choice rm-type" data-type="existing">
                                <i class="bi bi-box-seam fs-4 text-primary"></i>
                                <span><span class="rf-ct"><?= t('Already in my products') ?></span><span class="rf-cd"><?= t('Put a product you already have on the menu.') ?></span></span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="bi bi-tag"></i><?= t('Item') ?></div>
                        <div class="rf-grid">
                            <div class="rf-field rf-full d-none" id="existingFields">
                                <label class="form-label" for="f-existing"><?= t('Product') ?><span class="rf-req">*</span></label>
                                <select class="form-select" name="existing_product_id" id="f-existing"></select>
                            </div>
                            <div class="rf-field rf-full item-field">
                                <label class="form-label" for="f-name"><?= t('Name') ?><span class="rf-req">*</span></label>
                                <input type="text" class="form-control" name="product_name" id="f-name" maxlength="150">
                            </div>
                            <div class="rf-field rf-full item-field">
                                <label class="form-label" for="f-cat"><?= t('Group') ?></label>
                                <select class="form-select rf-select2" name="category_id" id="f-cat">
                                    <option value=""><?= t('-- No group --') ?></option>
                                    <?php foreach ($categories as $c): ?>
                                    <option value="<?= (int)$c['category_id'] ?>"><?= safe_output($c['category_name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="__new"><?= t('+ New group') ?></option>
                                </select>
                                <input type="text" class="form-control mt-2 d-none" name="new_category" id="f-newcat" maxlength="100" placeholder="<?= htmlspecialchars(t('Group name')) ?>">
                                <div class="d-flex flex-wrap gap-1 mt-2" id="starterGroups">
                                    <?php foreach ($starterGroups as $g): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill py-0 rm-starter"><?= htmlspecialchars($g) ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rf-sec item-field">
                        <div class="rf-sec-title"><i class="bi bi-cash-coin"></i><?= t('Price') ?></div>
                        <div class="rf-grid">
                            <div class="rf-field bought-only">
                                <label class="form-label" for="f-cost"><?= t('Buying price') ?></label>
                                <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rfCur ?></span>
                                    <input type="number" step="any" min="0" class="form-control" name="cost_price" id="f-cost" inputmode="decimal"></div>
                            </div>
                            <div class="rf-field" id="priceCol">
                                <label class="form-label" for="f-price"><?= t('Selling price') ?><span class="rf-req">*</span></label>
                                <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rfCur ?></span>
                                    <input type="number" step="any" min="0" class="form-control" name="selling_price" id="f-price" inputmode="decimal"></div>
                            </div>
                            <div class="rf-field dish-only">
                                <label class="form-label" for="f-plate"><?= t('What does one plate cost you?') ?> <span class="rf-opt">(<?= t('optional') ?>)</span></label>
                                <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= $rfCur ?></span>
                                    <input type="number" step="any" min="0" class="form-control" name="plate_cost" id="f-plate" inputmode="decimal"></div>
                                <div class="rf-help"><?= t('A rough figure, to show the profit on each plate. For an exact cost add a recipe.') ?></div>
                            </div>
                            <div class="rf-field bought-only new-only">
                                <label class="form-label" for="f-stock"><?= t('Opening stock') ?></label>
                                <input type="number" step="any" min="0" class="form-control" name="opening_stock" id="f-stock" inputmode="decimal" placeholder="0">
                            </div>
                        </div>
                    </div>

                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="ri ri-chef-hat"></i><?= t('Kitchen & availability') ?></div>
                        <div class="rf-grid">
                            <div class="rf-field rf-full item-field">
                                <span class="rf-label"><?= t('Prepared at') ?></span>
                                <div class="btn-group w-100" role="group" id="prepGroup">
                                    <input type="radio" class="btn-check" name="prep_area" id="prep-kitchen" value="kitchen">
                                    <label class="btn btn-outline-primary" for="prep-kitchen"><i class="ri ri-chef-hat me-1"></i><?= t('Kitchen') ?></label>
                                    <input type="radio" class="btn-check" name="prep_area" id="prep-bar" value="bar">
                                    <label class="btn btn-outline-primary" for="prep-bar"><i class="bi bi-cup me-1"></i><?= t('Bar') ?></label>
                                    <input type="radio" class="btn-check" name="prep_area" id="prep-none" value="none">
                                    <label class="btn btn-outline-primary" for="prep-none"><?= t('No preparation') ?></label>
                                </div>
                            </div>
                            <div class="rf-field rf-full">
                                <div class="rf-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="f-avail" checked>
                                    <label class="form-check-label" for="f-avail"><?= t('Available today') ?></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer rf-foot">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_edit && $warehouses): ?>
<!-- Recipe — restaurant_plan.md Phase 7 (restaurant form layout) -->
<?php require_once __DIR__ . '/../../../includes/restaurant/form_kit.php'; rfKitAssets(); ?>
<div class="modal fade rf-modal" id="recipeModal" tabindex="-1" aria-labelledby="rcpTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="rcpTitle"><i class="ri ri-scroll-text"></i><span class="text-truncate"><?= t('Recipe') ?> — <span id="rcpDish"></span></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
            </div>
            <div class="modal-body">
                <div class="rf-sec">
                    <div class="rf-sec-title"><i class="bi bi-basket"></i><?= t('Ingredients in one plate') ?></div>
                    <div class="rf-help mt-0 mb-2"><?= t('What goes into ONE plate. Selling it uses up these ingredients from stock. A dish without a recipe still sells normally.') ?></div>
                    <div id="rcpLines"></div>
                    <div class="rf-field mt-2">
                        <label class="form-label" for="rcpPick"><?= t('Add an ingredient') ?></label>
                        <div class="d-flex gap-2">
                            <div class="flex-grow-1" style="min-width:0;"><select id="rcpPick" class="form-select"></select></div>
                            <button type="button" class="btn btn-outline-primary flex-shrink-0" style="min-width:0;" onclick="rcpNewIngredient()" title="<?= htmlspecialchars(t('New ingredient')) ?>" aria-label="<?= htmlspecialchars(t('New ingredient')) ?>"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </div>
                </div>
                <div class="rf-sec">
                    <div class="rf-sec-title"><i class="bi bi-calculator"></i><?= t('Cost of one plate') ?></div>
                    <div class="card rm-stat p-3">
                        <div class="d-flex justify-content-between py-1"><span><?= t('Cost of one plate') ?></span><strong id="rcpCost">0</strong></div>
                        <div class="d-flex justify-content-between py-1"><span><?= t('Selling price') ?></span><strong id="rcpPrice">0</strong></div>
                        <div class="d-flex justify-content-between border-top mt-1 pt-2"><span><?= t('Profit per plate') ?></span><strong id="rcpProfit">0</strong></div>
                    </div>
                    <div class="rf-help mt-2"><i class="bi bi-info-circle me-1"></i><?= t('With a recipe, buy its ingredients as counted items in Purchases (not "Something else"), so they are not counted twice.') ?></div>
                </div>
            </div>
            <div class="modal-footer rf-foot">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-primary" onclick="rcpSave()"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.rm-stat { background:#e7f0ff; border:1px solid #b6ccfe; }
.rm-chips { display:flex; gap:6px; overflow-x:auto; padding-bottom:4px; -webkit-overflow-scrolling:touch; scrollbar-width:none; }
.rm-chips::-webkit-scrollbar { display:none; }
.rm-chip { white-space:nowrap; border-radius:999px; flex:0 0 auto; width:auto !important; min-width:0 !important; padding:.3rem .9rem !important; font-size:.85rem; line-height:1.3; }
.rm-search { max-width:100%; }
@media (min-width:768px) { .rm-search { max-width:320px; } }
.rm-type { border:1px solid #b6ccfe; background:#fff; display:flex; align-items:center; padding:10px 12px; }
.rm-type:hover, .rm-type.active { background:#e7f0ff; border-color:#0d6efd; }
.rm-card .rm-name { font-weight:600; font-size:1rem; overflow-wrap:anywhere; }
.rm-card .rm-price { font-weight:700; color:#0d6efd; white-space:nowrap; }
.rm-card.rm-off { opacity:.6; }
.rm-badge { background:#cfe2ff; color:#084298; font-weight:500; }
.rm-badge-out { background:#dc3545; color:#fff; }
#prepGroup .btn { font-size:.85rem; padding-left:4px; padding-right:4px; flex:1 1 0; min-width:0; }
</style>

<script>
const CAN_EDIT = <?= json_encode($can_edit) ?>;
const CSRF_FIELD = <?= json_encode(csrf_token()) ?>;
let NOCOST = <?= !empty($_GET['nocost']) ? 'true' : 'false' ?>;   // menu?nocost=1 from a notification
if (NOCOST && typeof bmsCleanQuery === 'function') bmsCleanQuery(['nocost']);
const RM = {
    noCostOnly: <?= json_encode(t('Dishes with no cost')) ?>,
    dish: <?= json_encode(t('Dish')) ?>,
    stockItem: <?= json_encode(t('Stock item')) ?>,
    noPrep: <?= json_encode(t('No preparation')) ?>,
    madeToOrder: <?= json_encode(t('made to order')) ?>,
    soldOut: <?= json_encode(t('Sold out')) ?>,
    all: <?= json_encode(t('All')) ?>,
    noGroup: <?= json_encode(t('Other')) ?>,
    empty: <?= json_encode(t('Your menu is empty. Tap "Add Item" to add chai, mandazi, lunch, soda…')) ?>,
    noMatch: <?= json_encode(t('No matching items.')) ?>,
    addItem: <?= json_encode(t('Add Item')) ?>,
    editItem: <?= json_encode(t('Edit Item')) ?>,
    edit: <?= json_encode(t('Edit')) ?>,
    remove: <?= json_encode(t('Remove from menu')) ?>,
    removeQ: <?= json_encode(t('Remove this item from the menu?')) ?>,
    removeNote: <?= json_encode(t('The item is kept in your products; it just stops showing on the menu.')) ?>,
    yesRemove: <?= json_encode(t('Yes, remove')) ?>,
    cancel: <?= json_encode(t('Cancel')) ?>,
    save: <?= json_encode(t('Save')) ?>,
    chooseProduct: <?= json_encode(t('Type to search your products...')) ?>,
    saving: <?= json_encode(t('Saving...')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    serverError: <?= json_encode(t('Server error. Please try again.')) ?>,
    stockLeft: <?= json_encode(t('stock')) ?>,
    recipe: <?= json_encode(t('Recipe')) ?>,
    buyStock: <?= json_encode(t('Buy stock & ingredients')) ?>,
    buyUrl: <?= json_encode(getUrl('restaurant/purchases')) ?>,
    cost: <?= json_encode(t('cost')) ?>,
    noCostYet: <?= json_encode(t('No cost yet — add it')) ?>,
    profit: <?= json_encode(t('profit')) ?>,
    perPlate: <?= json_encode(t('per plate')) ?>,
    inStock: <?= json_encode(t('in stock')) ?>,
    pickIngredient: <?= json_encode(t('Add an ingredient (type to search)...')) ?>,
    newIngredient: <?= json_encode(t('New ingredient')) ?>,
    ingName: <?= json_encode(t('Name (e.g. Rice)')) ?>,
    ingUnit: <?= json_encode(t('Unit (kg, litre, pcs)')) ?>,
    ingCost: <?= json_encode(t('Buying price per unit')) ?>,
    noIngredients: <?= json_encode(t('No ingredients yet — this dish sells without stock.')) ?>,
};
let MENU = [];
let ACTIVE_CAT = 'all';
let menuTable = null;
const fmt = n => Number(n || 0).toLocaleString();

$(function () {
    if (!$('#whSelect').length) return;
    if ($('#whSelect').is('select')) {
        $('#whSelect').select2({ theme: 'bootstrap-5', width: '100%' }).on('change', loadMenu);
    }
    menuTable = $('#menuTable').DataTable({
        responsive: false, scrollX: true, pageLength: 25, order: [], dom: 'rtipB',
        buttons: [{ extend: 'excelHtml5', className: 'd-none', exportOptions: { columns: ':not(:last-child)' } }],
        columnDefs: [{ targets: [8, 9], orderable: false }],
        language: { emptyTable: RM.empty, zeroRecords: RM.noMatch }
    });
    $('#rmSearch').on('input', render);
    loadMenu();

    $('.rm-type').on('click', function () { setType($(this).data('type')); });
    $('#f-cat').on('change', function () { $('#f-newcat').toggleClass('d-none', $(this).val() !== '__new'); });
    $('.rm-starter').on('click', function () {
        const name = $(this).text().trim();
        const opt = $('#f-cat option').filter(function () { return $(this).text().trim().toLowerCase() === name.toLowerCase(); });
        if (opt.length) { $('#f-cat').val(opt.val()).trigger('change'); }
        else { $('#f-cat').val('__new').trigger('change'); $('#f-newcat').val(name); }
    });
    $('#itemModal').on('shown.bs.modal', function () {
        const modal = $(this);
        if (!$('#f-cat').hasClass('select2-hidden-accessible')) {
            $('#f-cat').select2({ theme: 'bootstrap-5', dropdownParent: modal, width: '100%' });
        }
        if (!$('#f-existing').hasClass('select2-hidden-accessible')) {
            $('#f-existing').select2({
                theme: 'bootstrap-5', dropdownParent: modal, width: '100%', placeholder: RM.chooseProduct, allowClear: true,
                minimumInputLength: 0,   // click shows the first products; typing narrows them
                ajax: {
                    url: '<?= buildUrl('api/restaurant/search_products_for_menu.php') ?>', dataType: 'json', delay: 300,
                    data: p => ({ q: p.term, warehouse_id: whId() }), processResults: d => ({ results: d.results }), cache: true
                }
            });
        }
    });
    $('#itemForm').on('submit', saveItem);
});

function whId() { return parseInt($('#whSelect').val() || 0, 10); }

function loadMenu() {
    $.getJSON('<?= buildUrl('api/restaurant/get_menu_items.php') ?>', { warehouse_id: whId() }, function (res) {
        if (!res.success) { Swal.fire({ icon: 'error', title: RM.error, text: res.message }); return; }
        MENU = res.data;
        buildChips();
        render();
    }).fail(() => Swal.fire({ icon: 'error', title: RM.error, text: RM.serverError }));
}

function catKey(it) { return it.category_id ? String(it.category_id) : 'none'; }

function buildChips() {
    const cats = {};
    MENU.forEach(it => { cats[catKey(it)] = it.category_name || RM.noGroup; });
    if (ACTIVE_CAT !== 'all' && !cats[ACTIVE_CAT]) ACTIVE_CAT = 'all';
    // From the "Dishes with no cost" notification (menu?nocost=1): a chip that shows only those; ✕ shows everything again.
    let html = NOCOST ? `<button class="btn btn-sm rm-chip btn-primary" id="rmNoCost">${RM.noCostOnly} <i class="bi bi-x-lg ms-1"></i></button>` : '';
    html += `<button class="btn btn-sm rm-chip ${ACTIVE_CAT === 'all' && !NOCOST ? 'btn-primary' : 'btn-outline-primary'}" data-cat="all">${RM.all}</button>`;
    Object.keys(cats).forEach(k => {
        html += `<button class="btn btn-sm rm-chip ${ACTIVE_CAT === k ? 'btn-primary' : 'btn-outline-primary'}" data-cat="${k}">${safeOutput(cats[k])}</button>`;
    });
    $('#rmChips').html(html).find('.rm-chip[data-cat]').on('click', function () { ACTIVE_CAT = String($(this).data('cat')); NOCOST = false; buildChips(); render(); });
    $('#rmNoCost').on('click', function () { NOCOST = false; buildChips(); render(); });
    $('#stGroups').text(Object.keys(cats).length);
}

function filtered() {
    const q = ($('#rmSearch').val() || '').toLowerCase().trim();
    return MENU.filter(it => (ACTIVE_CAT === 'all' || catKey(it) === ACTIVE_CAT)
        && (!NOCOST || (it.is_service && itemCost(it) === null))
        && (!q || it.product_name.toLowerCase().includes(q) || (it.category_name || '').toLowerCase().includes(q)));
}

function availSwitch(it) {
    return `<div class="form-check form-switch d-inline-block m-0"><input class="form-check-input" type="checkbox" role="switch" ${it.is_available ? 'checked' : ''} ${CAN_EDIT ? '' : 'disabled'} onchange="setAvailable(${it.product_id}, this.checked)" style="transform:scale(1.25);"></div>`;
}
function actionMenu(it) {
    if (!CAN_EDIT) return '';
    return `<div class="dropdown d-flex justify-content-end">
        <button class="btn btn-sm btn-outline-primary dropdown-toggle shadow-sm px-2" type="button" data-bs-toggle="dropdown"><i class="bi bi-gear-fill me-1"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2">
            <li><button class="dropdown-item py-2 rounded" onclick="editItem(${it.product_id})"><i class="bi bi-pencil text-primary me-2"></i>${RM.edit}</button></li>
            ${it.is_service ? `<li><button class="dropdown-item py-2 rounded" onclick="openRecipe(${it.product_id})"><i class="ri ri-scroll-text text-primary me-2"></i>${RM.recipe}</button></li>` : `<li><a class="dropdown-item py-2 rounded" href="${RM.buyUrl}?item=${it.product_id}"><i class="ri ri-shopping-basket text-primary me-2"></i>${RM.buyStock}</a></li>`}
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item py-2 rounded text-danger" onclick="removeItem(${it.product_id})"><i class="bi bi-x-circle text-danger me-2"></i>${RM.remove}</button></li>
        </ul></div>`;
}
function typeLabel(it) { return it.is_service ? RM.dish : RM.stockItem; }
// What one item costs: a recipe (exact), a bought item's cost, or the owner's plate estimate. null = nothing known yet.
function itemCost(it) {
    if (it.has_recipe) return { v: it.recipe_cost, est: false };
    if (!it.is_service && it.cost_price > 0) return { v: it.cost_price, est: false };
    if (it.is_service && it.plate_cost > 0) return { v: it.plate_cost, est: true };
    return null;
}
function costCell(it) {
    const c = itemCost(it);
    if (!c) return `<span data-order="-1"><a href="#" class="text-danger fw-semibold small" onclick="${it.is_service ? `openRecipe(${it.product_id})` : `editItem(${it.product_id})`};return false;">${RM.noCostYet}</a></span>`;
    return `<span data-order="${c.v}">${c.est ? '~' : ''}${fmt(c.v)}</span>`;
}
function profitCell(it) {
    const c = itemCost(it);
    if (!c) return '<span data-order="-999999999" class="text-muted">—</span>';
    const p = it.selling_price - c.v, pc = it.selling_price > 0 ? Math.round(p / it.selling_price * 100) : 0;
    return `<span data-order="${p}" class="${p < 0 ? 'text-danger' : 'text-primary'} fw-semibold">${c.est ? '~' : ''}${fmt(p)} <small class="fw-normal">(${pc}%)</small></span>`;
}
// Phone card: the same figures in one line.
function costLine(it) {
    const c = itemCost(it);
    if (!c) return `<div class="small">${costCell(it)}</div>`;
    return `<div class="small text-muted">${RM.cost} ${c.est ? '~' : ''}${fmt(c.v)} · ${RM.profit} ${profitCell(it)}</div>`;
}
function prepLabel(it) { return it.station_name || RM.noPrep; }
function stockLabel(it) { return it.is_service ? `<span class="text-muted">${RM.madeToOrder}</span>` : fmt(it.stock); }

function render() {
    const rows = filtered();
    $('#stTotal').text(MENU.length);
    $('#stAvail').text(MENU.filter(i => i.is_available).length);
    $('#stOut').text(MENU.filter(i => !i.is_available).length);

    menuTable.clear();
    rows.forEach(it => menuTable.row.add([
        `<span class="fw-semibold">${safeOutput(it.product_name)}</span>${it.is_available ? '' : ` <span class="badge rm-badge-out ms-1">${RM.soldOut}</span>`}`,
        safeOutput(it.category_name || RM.noGroup),
        `<span class="badge rm-badge">${typeLabel(it)}</span>`,
        safeOutput(prepLabel(it)),
        `<span data-order="${it.selling_price}">${fmt(it.selling_price)}</span>`,
        costCell(it),
        profitCell(it),
        stockLabel(it),
        `<div class="text-center">${availSwitch(it)}</div>`,
        actionMenu(it)
    ]));
    menuTable.draw();

    if (!rows.length) {
        $('#cardView').html(`<div class="col-12 text-center py-5 text-muted"><i class="bi bi-journal-plus fs-1 d-block mb-2 text-primary"></i>${MENU.length ? RM.noMatch : RM.empty}</div>`);
        return;
    }
    let html = '';
    rows.forEach(it => {
        html += `<div class="col-12">
            <div class="card border-0 shadow-sm rm-card ${it.is_available ? '' : 'rm-off'}">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <div class="rm-name">${safeOutput(it.product_name)}</div>
                            <div class="small text-muted">${safeOutput(it.category_name || RM.noGroup)} · ${safeOutput(prepLabel(it))}</div>
                            ${costLine(it)}
                        </div>
                        <div class="rm-price">${fmt(it.selling_price)}</div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2 gap-2">
                        <div class="small">
                            <span class="badge rm-badge">${typeLabel(it)}</span>
                            ${it.is_service ? '' : `<span class="text-muted ms-1">${RM.stockLeft}: ${fmt(it.stock)}</span>`}
                            ${it.is_available ? '' : `<span class="badge rm-badge-out ms-1">${RM.soldOut}</span>`}
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-shrink-0">${availSwitch(it)}${actionMenu(it)}</div>
                    </div>
                </div>
            </div>
        </div>`;
    });
    $('#cardView').html(html);
}

// ───────── restaurant_plan.md Phase 7 — recipes ─────────
let RCP = { id: 0, price: 0, lines: [] };
function openRecipe(id) {
    $.getJSON('<?= buildUrl('api/restaurant/recipe.php') ?>', { action: 'get', product_id: id, warehouse_id: whId() }, function (res) {
        if (!res.success) { Swal.fire({ icon: 'error', title: RM.error, text: res.message }); return; }
        RCP = { id: id, price: parseFloat(res.dish.selling_price) || 0, lines: res.components.map(c => ({ product_id: c.product_id, name: c.product_name, unit: c.unit, qty: c.qty, cost: c.cost_price, stock: c.stock })) };
        $('#rcpDish').text(res.dish.product_name);
        rcpRender();
        const m = $('#recipeModal');
        if (!$('#rcpPick').hasClass('select2-hidden-accessible')) {
            $('#rcpPick').select2({ theme: 'bootstrap-5', dropdownParent: m, width: '100%', placeholder: RM.pickIngredient, allowClear: true, minimumInputLength: 0,
                ajax: { url: '<?= buildUrl('api/restaurant/recipe.php') ?>', dataType: 'json', delay: 300,
                        data: p => ({ action: 'search', q: p.term, warehouse_id: whId() }), processResults: d => ({ results: d.results }), cache: true } })
            .on('select2:select', function (e) { rcpAdd(e.params.data); $(this).val(null).trigger('change'); });
        }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('recipeModal')).show();
    }).fail(() => Swal.fire({ icon: 'error', title: RM.error, text: RM.serverError }));
}
function rcpAdd(d) {
    if (!d || !d.id || RCP.lines.some(l => l.product_id === d.id)) return;
    RCP.lines.push({ product_id: d.id, name: String(d.text || '').replace(/\s*\([^)]*\)$/, ''), unit: d.unit || 'pcs', qty: 1, cost: parseFloat(d.cost_price) || null, stock: null });
    rcpRender();
}
function rcpRender() {
    if (!RCP.lines.length) { $('#rcpLines').html(`<div class="text-muted small py-2">${RM.noIngredients}</div>`); }
    else {
        $('#rcpLines').html(RCP.lines.map((l, i) => `
            <div class="d-flex align-items-center gap-2 py-2 border-bottom">
                <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate">${safeOutput(l.name)}</div>
                    <div class="small text-muted">${l.cost !== null ? fmt(l.cost) + ' / ' + safeOutput(l.unit) : ''}${l.stock !== null ? ' · ' + fmt(l.stock) + ' ' + RM.inStock : ''}</div></div>
                <div class="input-group input-group-sm flex-shrink-0" style="width:132px;">
                    <input type="number" class="form-control text-end" min="0.01" step="0.01" value="${l.qty}" inputmode="decimal" onchange="RCP.lines[${i}].qty = parseFloat(this.value) || 0; rcpTotals();">
                    <span class="input-group-text">${safeOutput(l.unit)}</span>
                </div>
                <button type="button" class="btn btn-sm btn-link text-danger p-0 flex-shrink-0" onclick="RCP.lines.splice(${i}, 1); rcpRender();"><i class="bi bi-x-circle fs-5"></i></button>
            </div>`).join(''));
    }
    rcpTotals();
}
function rcpTotals() {
    const cost = RCP.lines.reduce((s, l) => s + (l.qty || 0) * (l.cost || 0), 0);
    $('#rcpCost').text(fmt(cost)); $('#rcpPrice').text(fmt(RCP.price));
    $('#rcpProfit').text(fmt(RCP.price - cost)).toggleClass('text-danger', RCP.price - cost < 0);
}
function rcpNewIngredient() {
    Swal.fire({ title: RM.newIngredient, confirmButtonText: RM.save || 'OK', showCancelButton: true, cancelButtonText: RM.cancel, confirmButtonColor: '#0d6efd',
        html: `<input id="ingN" class="swal2-input" placeholder="${RM.ingName}"><input id="ingU" class="swal2-input" placeholder="${RM.ingUnit}" value="kg"><input id="ingC" type="number" min="0" step="any" class="swal2-input" placeholder="${RM.ingCost}">`,
        preConfirm: () => ({ name: $('#ingN').val().trim(), unit: $('#ingU').val().trim(), cost_price: $('#ingC').val() })
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/restaurant/recipe.php') ?>', Object.assign({ action: 'new_ingredient', warehouse_id: whId(), _csrf: CSRF_FIELD }, r.value), function (res) {
            if (res.success) { rcpAdd({ id: res.id, text: res.text, unit: res.unit, cost_price: res.cost_price }); bootstrap.Modal.getOrCreateInstance(document.getElementById('recipeModal')).show(); }
            else Swal.fire({ icon: 'error', title: RM.error, text: res.message }).then(() => bootstrap.Modal.getOrCreateInstance(document.getElementById('recipeModal')).show());
        }, 'json');
    });
}
function rcpSave() {
    const comps = RCP.lines.filter(l => l.qty > 0).map(l => ({ product_id: l.product_id, qty: l.qty }));
    $.post('<?= buildUrl('api/restaurant/recipe.php') ?>', { action: 'save', product_id: RCP.id, warehouse_id: whId(), components: JSON.stringify(comps), _csrf: CSRF_FIELD }, function (res) {
        if (res.success) { bootstrap.Modal.getInstance(document.getElementById('recipeModal'))?.hide(); loadMenu(); Swal.fire({ icon: 'success', title: res.message, timer: 1600, showConfirmButton: false }); }
        else Swal.fire({ icon: 'error', title: RM.error, text: res.message });
    }, 'json').fail(() => Swal.fire({ icon: 'error', title: RM.error, text: RM.serverError }));
}

function setAvailable(id, on) {
    $.post('<?= buildUrl('api/restaurant/update_menu_item.php') ?>', { _csrf: CSRF_FIELD, warehouse_id: whId(), product_id: id, action: on ? 'available' : 'unavailable' }, function (res) {
        if (res.success) { const it = MENU.find(i => i.product_id === id); if (it) it.is_available = on ? 1 : 0; render(); }
        else { Swal.fire({ icon: 'error', title: RM.error, text: res.message }); loadMenu(); }
    }, 'json').fail(() => { Swal.fire({ icon: 'error', title: RM.error, text: RM.serverError }); loadMenu(); });
}

function removeItem(id) {
    Swal.fire({ title: RM.removeQ, text: RM.removeNote, icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: RM.yesRemove, cancelButtonText: RM.cancel })
    .then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/restaurant/update_menu_item.php') ?>', { _csrf: CSRF_FIELD, warehouse_id: whId(), product_id: id, action: 'remove' }, function (res) {
            if (res.success) { loadMenu(); Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false }); }
            else Swal.fire({ icon: 'error', title: RM.error, text: res.message });
        }, 'json');
    });
}

function setType(type) {
    $('#f-type').val(type);
    $('.rm-type').removeClass('active').filter(`[data-type="${type}"]`).addClass('active');
    const isNew = !$('#f-id').val();
    $('#existingFields').toggleClass('d-none', type !== 'existing');
    $('.item-field').toggleClass('d-none', type === 'existing');
    // Buying price: bought items only. Opening stock: NEW bought items only (one combined rule,
    // so the two conditions can't undo each other).
    $('.bought-only').each(function () {
        const show = type === 'bought' && (!$(this).hasClass('new-only') || isNew);
        $(this).toggleClass('d-none', !show);
    });
    $('.dish-only').toggleClass('d-none', type !== 'dish');
    $('#priceCol').toggleClass('rf-full', type !== 'bought');   // full width when there is no buying price beside it
    if (isNew) $(`#prep-${type === 'dish' ? 'kitchen' : 'none'}`).prop('checked', true);
}

function resetForm() {
    $('#itemForm')[0].reset();
    $('#f-id').val(''); $('#item-message').html('');
    $('#f-cat').val('').trigger('change'); $('#f-newcat').addClass('d-none');
    if ($('#f-existing').hasClass('select2-hidden-accessible')) $('#f-existing').val(null).trigger('change');
    $('#f-avail').prop('checked', true);
}

function openAddItem() {
    resetForm();
    $('#itemModalTitle').text(RM.addItem);
    $('#typeChooser').removeClass('d-none');
    setType('dish');
    new bootstrap.Modal(document.getElementById('itemModal')).show();
}

function editItem(id) {
    const it = MENU.find(i => i.product_id === id);
    if (!it) return;
    resetForm();
    $('#itemModalTitle').text(RM.editItem);
    $('#f-id').val(it.product_id);
    $('#typeChooser').addClass('d-none');
    setType(it.is_service ? 'dish' : 'bought');
    $('#f-name').val(it.product_name);
    $('#f-price').val(it.selling_price);
    $('#f-cost').val(it.cost_price);
    $('#f-plate').val(it.plate_cost > 0 ? it.plate_cost : '');
    $('.dish-only').toggleClass('d-none', !it.is_service || !!it.has_recipe);   // a recipe gives the exact cost
    $('#f-cat').val(it.category_id ? String(it.category_id) : '').trigger('change');
    $('#prepGroup .rm-extra').remove();
    $('input[name="prep_area"]').prop('checked', false);
    if (!it.kitchen_station_id) $('#prep-none').prop('checked', true);
    else if (it.station_key) $(`#prep-${it.station_key}`).prop('checked', true);
    else ensureStationRadio(it.kitchen_station_id, it.station_name); // the shop's own named area keeps its id
    $('#f-avail').prop('checked', !!it.is_available);
    new bootstrap.Modal(document.getElementById('itemModal')).show();
}

// A shop's own named area (not Kitchen/Bar) shows as an extra choice when editing.
function ensureStationRadio(stationId, name) {
    $('#prepGroup .rm-extra').remove();
    $('#prepGroup').prepend(`<input type="radio" class="btn-check rm-extra" name="prep_area" id="prep-st${stationId}" value="${stationId}" data-station="${stationId}" checked>
        <label class="btn btn-outline-primary rm-extra" for="prep-st${stationId}">${safeOutput(name)}</label>`);
}

function saveItem(e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'); const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + RM.saving);
    const fd = new FormData(this);
    fd.append('warehouse_id', whId());
    fd.set('is_available', $('#f-avail').is(':checked') ? '1' : '0');
    if ($('#f-cat').val() === '__new') fd.set('category_id', '');
    $.ajax({ url: '<?= buildUrl('api/restaurant/save_menu_item.php') ?>', type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
        success: function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('itemModal')).hide();
                $('#prepGroup .rm-extra').remove();
                loadMenu();
                Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false });
            } else {
                $('#item-message').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message) + '</div>');
            }
        },
        error: () => $('#item-message').html('<div class="alert alert-danger py-2 mb-0">' + RM.serverError + '</div>'),
        complete: () => btn.prop('disabled', false).html(orig)
    });
}
</script>

<?php
if ($can_share) require __DIR__ . '/../../../includes/restaurant/online_menu_share.php';
require_once 'footer.php';
ob_end_flush();
