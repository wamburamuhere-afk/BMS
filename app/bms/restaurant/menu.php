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
$starterGroups = [t('Breakfast'), t('Main meals'), t('Soups'), t('Snacks'), t('Drinks')];
?>

<div class="container-fluid mt-3 mt-md-4 rm-page">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 rm-head">
        <h4 class="mb-0 text-truncate"><i class="bi bi-journal-richtext text-primary me-2"></i><?= t('Menu') ?></h4>
        <div class="d-flex gap-2 flex-shrink-0">
            <a href="<?= getUrl('restaurant') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i><span class="d-none d-md-inline ms-1"><?= t('Restaurant') ?></span></a>
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
                    <th class="text-end"><?= t('Price') ?></th>
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
<!-- Add / Edit Menu Item -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="itemModalTitle"><?= t('Add Item') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="itemForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="product_id" id="f-id">
                    <input type="hidden" name="item_type" id="f-type" value="dish">
                    <div id="item-message" class="mb-2"></div>

                    <div id="typeChooser" class="mb-3">
                        <div class="fw-semibold mb-2"><?= t('What is this?') ?></div>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn rm-type text-start" data-type="dish">
                                <i class="bi bi-egg-fried fs-4 text-primary me-2"></i>
                                <span><strong><?= t('Dish (cooked here)') ?></strong><br><small class="text-muted"><?= t('Chai, mandazi, lunch, soup… sells without stock.') ?></small></span>
                            </button>
                            <button type="button" class="btn rm-type text-start" data-type="bought">
                                <i class="bi bi-cup-straw fs-4 text-primary me-2"></i>
                                <span><strong><?= t('Bought item (has stock)') ?></strong><br><small class="text-muted"><?= t('Water, soda… you buy it and count the stock.') ?></small></span>
                            </button>
                            <button type="button" class="btn rm-type text-start" data-type="existing">
                                <i class="bi bi-box-seam fs-4 text-primary me-2"></i>
                                <span><strong><?= t('Already in my products') ?></strong><br><small class="text-muted"><?= t('Put a product you already have on the menu.') ?></small></span>
                            </button>
                        </div>
                    </div>

                    <div id="existingFields" class="d-none">
                        <div class="mb-3">
                            <label class="form-label"><?= t('Product') ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="existing_product_id" id="f-existing"></select>
                        </div>
                    </div>

                    <div id="itemFields">
                        <div class="mb-3">
                            <label class="form-label"><?= t('Name') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-lg" name="product_name" id="f-name" maxlength="150">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= t('Group') ?></label>
                            <select class="form-select select2-static" name="category_id" id="f-cat">
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
                        <div class="row g-2">
                            <div class="col-6 bought-only">
                                <label class="form-label"><?= t('Buying price') ?></label>
                                <input type="number" step="any" min="0" class="form-control" name="cost_price" id="f-cost" inputmode="decimal">
                            </div>
                            <div class="col-6" id="priceCol">
                                <label class="form-label"><?= t('Selling price') ?> <span class="text-danger">*</span></label>
                                <input type="number" step="any" min="0" class="form-control" name="selling_price" id="f-price" inputmode="decimal">
                            </div>
                        </div>
                        <div class="mt-3 bought-only new-only">
                            <label class="form-label"><?= t('Opening stock') ?></label>
                            <input type="number" step="any" min="0" class="form-control" name="opening_stock" id="f-stock" inputmode="decimal" placeholder="0">
                        </div>
                        <div class="mt-3">
                            <label class="form-label"><?= t('Prepared at') ?></label>
                            <div class="btn-group w-100" role="group" id="prepGroup">
                                <input type="radio" class="btn-check" name="prep_area" id="prep-kitchen" value="kitchen">
                                <label class="btn btn-outline-primary" for="prep-kitchen"><i class="bi bi-fire me-1"></i><?= t('Kitchen') ?></label>
                                <input type="radio" class="btn-check" name="prep_area" id="prep-bar" value="bar">
                                <label class="btn btn-outline-primary" for="prep-bar"><i class="bi bi-cup me-1"></i><?= t('Bar') ?></label>
                                <input type="radio" class="btn-check" name="prep_area" id="prep-none" value="none">
                                <label class="btn btn-outline-primary" for="prep-none"><?= t('No preparation') ?></label>
                            </div>
                        </div>
                    </div>

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="f-avail" checked>
                        <label class="form-check-label" for="f-avail"><?= t('Available today') ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button>
                </div>
            </form>
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
#prepGroup .btn { font-size:.85rem; padding-left:4px; padding-right:4px; }
</style>

<script>
const CAN_EDIT = <?= json_encode($can_edit) ?>;
const CSRF_FIELD = <?= json_encode(csrf_token()) ?>;
const RM = {
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
    chooseProduct: <?= json_encode(t('Type to search your products...')) ?>,
    saving: <?= json_encode(t('Saving...')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    serverError: <?= json_encode(t('Server error. Please try again.')) ?>,
    stockLeft: <?= json_encode(t('stock')) ?>,
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
        columnDefs: [{ targets: [6, 7], orderable: false }],
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
                minimumInputLength: 1,
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
    let html = `<button class="btn btn-sm rm-chip ${ACTIVE_CAT === 'all' ? 'btn-primary' : 'btn-outline-primary'}" data-cat="all">${RM.all}</button>`;
    Object.keys(cats).forEach(k => {
        html += `<button class="btn btn-sm rm-chip ${ACTIVE_CAT === k ? 'btn-primary' : 'btn-outline-primary'}" data-cat="${k}">${safeOutput(cats[k])}</button>`;
    });
    $('#rmChips').html(html).find('.rm-chip').on('click', function () { ACTIVE_CAT = String($(this).data('cat')); buildChips(); render(); });
    $('#stGroups').text(Object.keys(cats).length);
}

function filtered() {
    const q = ($('#rmSearch').val() || '').toLowerCase().trim();
    return MENU.filter(it => (ACTIVE_CAT === 'all' || catKey(it) === ACTIVE_CAT)
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
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item py-2 rounded text-danger" onclick="removeItem(${it.product_id})"><i class="bi bi-x-circle text-danger me-2"></i>${RM.remove}</button></li>
        </ul></div>`;
}
function typeLabel(it) { return it.is_service ? RM.dish : RM.stockItem; }
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
    $('#itemFields').toggleClass('d-none', type === 'existing');
    // Buying price: bought items only. Opening stock: NEW bought items only (one combined rule,
    // so the two conditions can't undo each other).
    $('.bought-only').each(function () {
        const show = type === 'bought' && (!$(this).hasClass('new-only') || isNew);
        $(this).toggleClass('d-none', !show);
    });
    $('#priceCol').toggleClass('col-6', type === 'bought').toggleClass('col-12', type !== 'bought');
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
require_once 'footer.php';
ob_end_flush();
