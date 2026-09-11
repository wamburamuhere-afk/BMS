<?php
/**
 * app/bms/restaurant/modifier_group.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — Modifier Group admin: list + per-group
 * option manager + product linking. Company-wide catalog concept (like
 * price groups/tax rates), not warehouse-scoped — matches
 * api/restaurant/get_modifier_groups.php's own comment. Column shape
 * (Name/Type/Min-Max/Required/Options/Linked Products/Status) mirrors the
 * SalePro reference screen named in the plan.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';

$page_title = 'Modifier Group';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$can_edit = canEdit('restaurant_pos');
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-list-check text-primary me-2"></i><?= t('Modifier Group') ?></h4>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('restaurant') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> <?= t('Restaurant Hub') ?>
            </a>
            <?php if ($can_edit): ?>
            <button class="btn btn-primary btn-sm" onclick="openGroupModal()">
                <i class="bi bi-plus-circle me-1"></i> <?= t('New Group') ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div id="tableView">
        <table id="groupsTable" class="table table-hover align-middle w-100">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th><?= t('Name') ?></th>
                    <th><?= t('Type') ?></th>
                    <th><?= t('Min-Max') ?></th>
                    <th><?= t('Required') ?></th>
                    <th><?= t('Options') ?></th>
                    <th><?= t('Linked Products') ?></th>
                    <th><?= t('Status') ?></th>
                    <th class="text-end"><?= t('Actions') ?></th>
                </tr>
            </thead>
            <tbody id="groupsBody"></tbody>
        </table>
    </div>
    <div id="cardView" class="row g-2 d-none"></div>
</div>

<!-- Add/Edit Group Modal -->
<div class="modal fade" id="groupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><span id="groupModalTitle"><?= t('New Group') ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="groupForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="group_id" id="f-group-id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="group-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Name') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="f-group-name" required maxlength="150" placeholder="<?= t('e.g. Size, Toppings, Spice Level') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Selection Type') ?></label>
                        <select class="form-select" name="selection_type" id="f-group-type" onchange="toggleMaxField()">
                            <option value="single"><?= t('Single choice') ?></option>
                            <option value="multiple"><?= t('Multiple choice') ?></option>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label"><?= t('Min Select') ?></label>
                            <input type="number" class="form-control" name="min_select" id="f-group-min" value="0" min="0">
                        </div>
                        <div class="col-6" id="f-group-max-wrap">
                            <label class="form-label"><?= t('Max Select') ?></label>
                            <input type="number" class="form-control" name="max_select" id="f-group-max" value="1" min="1">
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="is_required" id="f-group-required" value="1">
                        <label class="form-check-label" for="f-group-required"><?= t('Required — customer must choose before adding to cart') ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> <?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Options Modal -->
<div class="modal fade" id="optionsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-list-ul me-1"></i> <span id="optionsModalTitle"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($can_edit): ?>
                <form id="addOptionForm" class="row g-2 align-items-end mb-3" autocomplete="off">
                    <input type="hidden" id="opt-group-id">
                    <div class="col">
                        <label class="form-label small mb-1"><?= t('Option Name') ?></label>
                        <input type="text" class="form-control form-control-sm" id="opt-name" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1"><?= t('Price Adj.') ?></label>
                        <input type="number" class="form-control form-control-sm" id="opt-price" step="0.01" value="0" style="width:120px;">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i></button>
                    </div>
                </form>
                <?php endif; ?>
                <table class="table table-sm table-hover">
                    <thead><tr><th><?= t('Option') ?></th><th class="text-end"><?= t('Price Adj.') ?></th><th><?= t('Status') ?></th><th></th></tr></thead>
                    <tbody id="optionsBody"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Close') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Manage Products Modal -->
<div class="modal fade" id="productsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-box-seam me-1"></i> <span id="productsModalTitle"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($can_edit): ?>
                <select id="addProductSelect" class="form-select mb-3" style="width:100%"></select>
                <?php endif; ?>
                <table class="table table-sm table-hover">
                    <thead><tr><th><?= t('Product') ?></th><th></th></tr></thead>
                    <tbody id="productsBody"></tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Close') ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const CAN_EDIT = <?= json_encode($can_edit) ?>;
const CSRF_FIELD = <?= json_encode(csrf_token()) ?>;
const STRINGS = {
    single: <?= json_encode(t('Single choice')) ?>,
    multiple: <?= json_encode(t('Multiple choice')) ?>,
    yes: <?= json_encode(t('Yes')) ?>,
    no: <?= json_encode(t('No')) ?>,
    active: <?= json_encode(t('Active')) ?>,
    inactive: <?= json_encode(t('Inactive')) ?>,
    noRecords: <?= json_encode(t('No records found')) ?>,
    options: <?= json_encode(t('Options')) ?>,
    products: <?= json_encode(t('Products')) ?>,
    edit: <?= json_encode(t('Edit')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    processing: <?= json_encode(t('Processing...')) ?>,
    serverError: <?= json_encode(t('Server error.')) ?>,
    remove: <?= json_encode(t('Remove')) ?>,
    activate: <?= json_encode(t('Activate')) ?>,
    deactivate: <?= json_encode(t('Deactivate')) ?>,
    searchProduct: <?= json_encode(t('Search a product to add...')) ?>,
};
let GROUPS_DATA = [];
let currentGroupId = 0;

$(document).ready(function () {
    loadGroups();
    function applyView() {
        if (window.innerWidth < 768) { $('#tableView').addClass('d-none'); $('#cardView').removeClass('d-none'); }
        else { $('#tableView').removeClass('d-none'); $('#cardView').addClass('d-none'); }
    }
    applyView();
    $(window).on('resize', applyView);

    if (CAN_EDIT) {
        $('#addProductSelect').select2({
            theme: 'bootstrap-5', width: '100%', placeholder: STRINGS.searchProduct,
            ajax: { url: '<?= buildUrl('api/search_products.php') ?>', dataType: 'json', delay: 250, data: p => ({ q: p.term }) }
        }).on('select2:select', function (e) {
            linkProduct(e.params.data.id, e.params.data.text);
            $(this).val(null).trigger('change');
        });
    }
});

function toggleMaxField() {
    const isSingle = $('#f-group-type').val() === 'single';
    $('#f-group-max-wrap').toggleClass('d-none', isSingle);
}

function loadGroups() {
    $.getJSON('<?= buildUrl('api/restaurant/get_modifier_groups.php') ?>', function (res) {
        GROUPS_DATA = res.success ? res.data : [];
        renderTable();
        renderCards();
    });
}

function renderTable() {
    if (!GROUPS_DATA.length) {
        $('#groupsBody').html(`<tr><td colspan="9" class="text-center text-muted py-4">${STRINGS.noRecords}</td></tr>`);
        return;
    }
    let html = '';
    GROUPS_DATA.forEach((g, i) => {
        const statusColor = g.status === 'active' ? '#198754' : '#6c757d';
        const statusLabel = g.status === 'active' ? STRINGS.active : STRINGS.inactive;
        const typeLabel = g.selection_type === 'multiple' ? STRINGS.multiple : STRINGS.single;
        html += `<tr>
            <td>${i + 1}</td>
            <td>${safeOutput(g.name)}</td>
            <td>${typeLabel}</td>
            <td>${g.min_select}-${g.max_select}</td>
            <td>${g.is_required == 1 ? STRINGS.yes : STRINGS.no}</td>
            <td><button class="btn btn-sm btn-outline-primary" onclick="openOptions(${g.group_id}, '${escAttr(g.name)}')">${(g.options || []).length} ${STRINGS.options}</button></td>
            <td>${CAN_EDIT ? `<button class="btn btn-sm btn-outline-secondary" onclick="openProducts(${g.group_id}, '${escAttr(g.name)}')">${g.linked_product_count} ${STRINGS.products}</button>` : `${g.linked_product_count} ${STRINGS.products}`}</td>
            <td><span class="badge" style="background:${statusColor};color:#fff;">${statusLabel}</span></td>
            <td class="text-end">${CAN_EDIT ? `<button class="btn btn-sm btn-outline-primary" onclick='editGroup(${JSON.stringify(g)})'><i class="bi bi-pencil"></i></button>` : ''}</td>
        </tr>`;
    });
    $('#groupsBody').html(html);
}

function renderCards() {
    if (!GROUPS_DATA.length) { $('#cardView').html(`<div class="col-12 text-center text-muted py-4">${STRINGS.noRecords}</div>`); return; }
    let html = '';
    GROUPS_DATA.forEach(g => {
        const statusColor = g.status === 'active' ? '#198754' : '#6c757d';
        html += `<div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body p-3">
            <div class="fw-bold">${safeOutput(g.name)}</div>
            <small class="text-muted">${(g.options || []).length} ${STRINGS.options} · ${g.linked_product_count} ${STRINGS.products}</small>
        </div></div></div>`;
    });
    $('#cardView').html(html);
}

function escAttr(s) { return String(s).replace(/'/g, "\\'").replace(/"/g, '&quot;'); }

function openGroupModal() {
    $('#groupModalTitle').text(<?= json_encode(t('New Group')) ?>);
    $('#f-group-id').val(''); $('#f-group-name').val(''); $('#f-group-type').val('single');
    $('#f-group-min').val(0); $('#f-group-max').val(1); $('#f-group-required').prop('checked', false);
    toggleMaxField();
    new bootstrap.Modal(document.getElementById('groupModal')).show();
}
function editGroup(g) {
    $('#groupModalTitle').text(<?= json_encode(t('Edit Group')) ?>);
    $('#f-group-id').val(g.group_id); $('#f-group-name').val(g.name); $('#f-group-type').val(g.selection_type);
    $('#f-group-min').val(g.min_select); $('#f-group-max').val(g.max_select); $('#f-group-required').prop('checked', g.is_required == 1);
    toggleMaxField();
    new bootstrap.Modal(document.getElementById('groupModal')).show();
}

$('#groupForm').on('submit', function (e) {
    e.preventDefault();
    const data = $(this).serialize() + (($('#f-group-required').is(':checked')) ? '' : '&is_required=0');
    const btn = $(this).find('[type="submit"]'); const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + STRINGS.processing);
    $.post('<?= buildUrl('api/restaurant/save_modifier_group.php') ?>', data, function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('groupModal')).hide();
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false }).then(loadGroups);
        } else {
            $('#group-message').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message) + '</div>');
        }
    }, 'json').fail(() => $('#group-message').html('<div class="alert alert-danger py-2 mb-0">' + STRINGS.serverError + '</div>'))
      .always(() => btn.prop('disabled', false).html(orig));
});

function openOptions(groupId, name) {
    currentGroupId = groupId;
    $('#optionsModalTitle').text(name);
    $('#opt-group-id').val(groupId);
    loadOptions();
    new bootstrap.Modal(document.getElementById('optionsModal')).show();
}
function loadOptions() {
    const group = GROUPS_DATA.find(g => g.group_id == currentGroupId);
    const opts = group ? (group.options || []) : [];
    let html = '';
    opts.forEach(o => {
        const statusColor = o.status === 'active' ? '#198754' : '#6c757d';
        const statusLabel = o.status === 'active' ? STRINGS.active : STRINGS.inactive;
        html += `<tr>
            <td>${safeOutput(o.name)}</td>
            <td class="text-end">${Number(o.price_adjustment).toLocaleString()}</td>
            <td><span class="badge" style="background:${statusColor};color:#fff;">${statusLabel}</span></td>
            <td>${CAN_EDIT ? `<button class="btn btn-sm btn-outline-secondary" onclick="toggleOption(${o.option_id}, '${o.status === 'active' ? 'inactive' : 'active'}')"><i class="bi bi-${o.status === 'active' ? 'pause-circle' : 'play-circle'}"></i></button>` : ''}</td>
        </tr>`;
    });
    $('#optionsBody').html(html || `<tr><td colspan="4" class="text-center text-muted py-3">${STRINGS.noRecords}</td></tr>`);
}

$('#addOptionForm').on('submit', function (e) {
    e.preventDefault();
    const name = $('#opt-name').val(); const price = $('#opt-price').val();
    $.post('<?= buildUrl('api/restaurant/save_modifier_option.php') ?>', { group_id: currentGroupId, name: name, price_adjustment: price, _csrf: CSRF_FIELD }, function (res) {
        if (res.success) { $('#opt-name').val(''); $('#opt-price').val(0); loadGroupsThenOptions(); }
        else { Swal.fire({ icon: 'error', title: STRINGS.error, text: res.message }); }
    }, 'json');
});
function toggleOption(optionId, status) {
    const group = GROUPS_DATA.find(g => g.group_id == currentGroupId);
    const opt = (group.options || []).find(o => o.option_id == optionId);
    $.post('<?= buildUrl('api/restaurant/save_modifier_option.php') ?>', {
        option_id: optionId, group_id: currentGroupId, name: opt.name, price_adjustment: opt.price_adjustment, status: status, _csrf: CSRF_FIELD
    }, function (res) {
        if (res.success) { loadGroupsThenOptions(); } else { Swal.fire({ icon: 'error', title: STRINGS.error, text: res.message }); }
    }, 'json');
}
function loadGroupsThenOptions() {
    $.getJSON('<?= buildUrl('api/restaurant/get_modifier_groups.php') ?>', function (res) {
        GROUPS_DATA = res.success ? res.data : [];
        renderTable(); renderCards(); loadOptions();
    });
}

function openProducts(groupId, name) {
    currentGroupId = groupId;
    $('#productsModalTitle').text(name);
    loadGroupProducts();
    new bootstrap.Modal(document.getElementById('productsModal')).show();
}
function loadGroupProducts() {
    $.getJSON('<?= buildUrl('api/restaurant/get_group_products.php') ?>', { group_id: currentGroupId }, function (res) {
        const rows = res.success ? res.data : [];
        let html = '';
        rows.forEach(p => {
            html += `<tr><td>${safeOutput(p.product_name)} ${p.sku ? '<span class="text-muted small">(' + safeOutput(p.sku) + ')</span>' : ''}</td>
                <td class="text-end">${CAN_EDIT ? `<button class="btn btn-sm btn-outline-danger" onclick="unlinkProduct(${p.product_id})"><i class="bi bi-x-circle"></i> ${STRINGS.remove}</button>` : ''}</td></tr>`;
        });
        $('#productsBody').html(html || `<tr><td colspan="2" class="text-center text-muted py-3">${STRINGS.noRecords}</td></tr>`);
    });
}
function linkProduct(productId) {
    $.post('<?= buildUrl('api/restaurant/toggle_group_product_link.php') ?>', { group_id: currentGroupId, product_id: productId, linked: 1, _csrf: CSRF_FIELD }, function (res) {
        if (res.success) { loadGroupProducts(); loadGroups(); } else { Swal.fire({ icon: 'error', title: STRINGS.error, text: res.message }); }
    }, 'json');
}
function unlinkProduct(productId) {
    $.post('<?= buildUrl('api/restaurant/toggle_group_product_link.php') ?>', { group_id: currentGroupId, product_id: productId, linked: 0, _csrf: CSRF_FIELD }, function (res) {
        if (res.success) { loadGroupProducts(); loadGroups(); } else { Swal.fire({ icon: 'error', title: STRINGS.error, text: res.message }); }
    }, 'json');
}
</script>

<?php
require_once 'footer.php';
ob_end_flush();
