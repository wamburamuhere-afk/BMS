<?php
/**
 * app/bms/restaurant/tables.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — a focused Tables admin view: pick a
 * warehouse, pick a floor, manage that floor's tables in one list (create,
 * edit, and force a status override). floors.php already gives a combined
 * floor+table overview; this page exists for a straight per-floor CRUD list
 * with more room per row than the card grid there — same underlying
 * api/restaurant/{get,save}_table.php + update_table_status.php endpoints
 * from the backend half (commit 9c4d3030), no new backend needed.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_scope.php';

$page_title = 'Tables';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$can_edit = canEdit('restaurant_pos');

$warehouses = restaurantWarehousesForSelect($pdo);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-table text-primary me-2"></i><?= t('Tables') ?></h4>
        <a href="<?= getUrl('restaurant') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> <?= t('Restaurant Hub') ?>
        </a>
    </div>

    <?php if (empty($warehouses)): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><i class="bi bi-info-circle me-1"></i>
            <?= t('No warehouse in your scope is set to Restaurant or Hybrid mode yet. Switch a warehouse\'s POS Mode first.') ?>
        </div>
        <?php if (canEdit('warehouses')): ?>
        <a href="<?= getUrl('warehouses') ?>" class="btn btn-sm btn-warning fw-bold text-nowrap">
            <i class="bi bi-gear me-1"></i> <?= t('Go to Warehouses') ?>
        </a>
        <?php endif; ?>
    </div>
    <?php else: ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-1"><?= t('Warehouse') ?></label>
                    <select id="whSelect" class="form-select form-select-sm select2-static" style="min-width:220px;">
                        <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['warehouse_id'] ?>"><?= safe_output($w['warehouse_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1"><?= t('Floor') ?></label>
                    <select id="floorFilter" class="form-select form-select-sm select2-static" style="min-width:180px;">
                        <option value=""><?= t('All Floors') ?></option>
                    </select>
                </div>
                <?php if ($can_edit): ?>
                <div class="col-auto">
                    <button class="btn btn-primary btn-sm" onclick="openTableModal()">
                        <i class="bi bi-plus-circle me-1"></i> <?= t('Add Table') ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-primary" id="stat-total">0</div>
                <div class="small text-muted"><?= t('Total') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-success" id="stat-available">0</div>
                <div class="small text-muted"><?= t('Available') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-danger" id="stat-occupied">0</div>
                <div class="small text-muted"><?= t('Occupied') ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-4 fw-bold text-warning" id="stat-reserved">0</div>
                <div class="small text-muted"><?= t('Reserved') ?></div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive d-none d-md-block">
            <table class="table table-hover align-middle mb-0" id="tablesTable">
                <thead class="table-dark">
                    <tr>
                        <th><?= t('Table #') ?></th>
                        <th><?= t('Floor') ?></th>
                        <th><?= t('Seats') ?></th>
                        <th><?= t('Status') ?></th>
                        <th class="text-end"><?= t('Actions') ?></th>
                    </tr>
                </thead>
                <tbody id="tablesTbody">
                    <tr><td colspan="5" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>
                </tbody>
            </table>
        </div>
        <div class="d-md-none p-2" id="tablesCards"></div>
    </div>

    <?php endif; ?>
</div>

<!-- Add/Edit Table Modal -->
<div class="modal fade" id="tableModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><span id="tableModalTitle"><?= t('Add Table') ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="tableForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="table_id" id="f-table-id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="table-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Floor') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" name="floor_id" id="f-table-floor" required></select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Table Number') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="table_number" id="f-table-number" required maxlength="50">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Seats') ?></label>
                        <input type="number" class="form-control" name="seats" id="f-table-seats" value="2" min="1">
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

<script>
const CAN_EDIT = <?= json_encode($can_edit) ?>;
const CSRF_FIELD = <?= json_encode(csrf_token()) ?>;
const STRINGS = {
    noTables: <?= json_encode(t('No tables found.')) ?>,
    statusAvailable: <?= json_encode(t('Available')) ?>,
    statusOccupied: <?= json_encode(t('Occupied')) ?>,
    statusReserved: <?= json_encode(t('Reserved')) ?>,
    statusCleaning: <?= json_encode(t('Cleaning')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    serverError: <?= json_encode(t('Server error.')) ?>,
    processing: <?= json_encode(t('Processing...')) ?>,
};
const STATUS_COLORS = { available: '#198754', occupied: '#dc3545', reserved: '#fd7e14', cleaning: '#6c757d' };
const STATUS_LABELS = { available: STRINGS.statusAvailable, occupied: STRINGS.statusOccupied, reserved: STRINGS.statusReserved, cleaning: STRINGS.statusCleaning };
let TABLES_CACHE = [];
let FLOORS_CACHE = [];

$(document).ready(function () {
    if ($('#whSelect').length) {
        $('#whSelect, #floorFilter').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#whSelect').on('change', loadFloorsThenTables);
        $('#floorFilter').on('change', renderTables);
        loadFloorsThenTables();
    }
});

function currentWarehouseId() { return parseInt($('#whSelect').val() || 0, 10); }

function loadFloorsThenTables() {
    const wid = currentWarehouseId();
    if (!wid) return;
    $.getJSON('<?= buildUrl('api/restaurant/get_floors.php') ?>', { warehouse_id: wid }, function (res) {
        FLOORS_CACHE = res.success ? res.data : [];
        let opts = '<option value="">' + <?= json_encode(t('All Floors')) ?> + '</option>';
        let formOpts = '';
        FLOORS_CACHE.forEach(f => {
            opts += `<option value="${f.floor_id}">${safeOutput(f.name)}</option>`;
            formOpts += `<option value="${f.floor_id}">${safeOutput(f.name)}</option>`;
        });
        $('#floorFilter').html(opts).trigger('change.select2');
        $('#f-table-floor').html(formOpts);
        loadTables();
    });
}

function loadTables() {
    const wid = currentWarehouseId();
    if (!wid) return;
    $('#tablesTbody').html('<tr><td colspan="5" class="text-center py-4"><div class="spinner-border text-primary"></div></td></tr>');
    $.getJSON('<?= buildUrl('api/restaurant/get_tables.php') ?>', { warehouse_id: wid }, function (res) {
        TABLES_CACHE = res.success ? res.data : [];
        renderTables();
    });
}

function renderTables() {
    const floorId = parseInt($('#floorFilter').val() || 0, 10);
    const rows = floorId ? TABLES_CACHE.filter(t => t.floor_id == floorId) : TABLES_CACHE;

    $('#stat-total').text(TABLES_CACHE.length);
    $('#stat-available').text(TABLES_CACHE.filter(t => t.status === 'available').length);
    $('#stat-occupied').text(TABLES_CACHE.filter(t => t.status === 'occupied').length);
    $('#stat-reserved').text(TABLES_CACHE.filter(t => t.status === 'reserved').length);

    if (!rows.length) {
        $('#tablesTbody').html(`<tr><td colspan="5" class="text-center text-muted py-4">${STRINGS.noTables}</td></tr>`);
        $('#tablesCards').html(`<div class="text-center text-muted py-4">${STRINGS.noTables}</div>`);
        return;
    }

    let tbody = '', cards = '';
    rows.forEach(t => {
        const color = STATUS_COLORS[t.status] || '#6c757d';
        const label = STATUS_LABELS[t.status] || t.status;
        const statusSelect = CAN_EDIT ? `
            <select class="form-select form-select-sm d-inline-block" style="width:auto;" onchange="setTableStatus(${t.table_id}, this.value)">
                <option value="available" ${t.status === 'available' ? 'selected' : ''}>${STRINGS.statusAvailable}</option>
                <option value="occupied" ${t.status === 'occupied' ? 'selected' : ''}>${STRINGS.statusOccupied}</option>
                <option value="reserved" ${t.status === 'reserved' ? 'selected' : ''}>${STRINGS.statusReserved}</option>
                <option value="cleaning" ${t.status === 'cleaning' ? 'selected' : ''}>${STRINGS.statusCleaning}</option>
            </select>` : `<span class="badge" style="background:${color};color:#fff;">${safeOutput(label)}</span>`;

        tbody += `<tr>
            <td class="fw-bold">${safeOutput(t.table_number)}</td>
            <td>${safeOutput(t.floor_name)}</td>
            <td>${t.seats}</td>
            <td>${statusSelect}</td>
            <td class="text-end">
                ${CAN_EDIT ? `<button class="btn btn-sm btn-outline-primary" onclick="editTable(${t.table_id}, ${t.floor_id}, ${JSON.stringify(t.table_number).replace(/"/g, '&quot;')}, ${t.seats})"><i class="bi bi-pencil"></i></button>` : ''}
            </td>
        </tr>`;

        cards += `<div class="card border-0 shadow-sm mb-2"><div class="card-body p-3" style="border-left:4px solid ${color};">
            <div class="d-flex justify-content-between"><div class="fw-bold">${safeOutput(t.table_number)}</div><span class="badge" style="background:${color};color:#fff;">${safeOutput(label)}</span></div>
            <div class="small text-muted">${safeOutput(t.floor_name)} &middot; ${t.seats} <?= t('seats') ?></div>
            ${CAN_EDIT ? `<button class="btn btn-sm btn-outline-primary mt-2" onclick="editTable(${t.table_id}, ${t.floor_id}, ${JSON.stringify(t.table_number).replace(/"/g, '&quot;')}, ${t.seats})"><i class="bi bi-pencil"></i> ${<?= json_encode(t('Edit')) ?>}</button>` : ''}
        </div></div>`;
    });
    $('#tablesTbody').html(tbody);
    $('#tablesCards').html(cards);
}

function openTableModal() {
    $('#tableModalTitle').text(<?= json_encode(t('Add Table')) ?>);
    $('#f-table-id').val(''); $('#f-table-number').val(''); $('#f-table-seats').val(2);
    new bootstrap.Modal(document.getElementById('tableModal')).show();
}
function editTable(id, floorId, number, seats) {
    $('#tableModalTitle').text(<?= json_encode(t('Edit Table')) ?>);
    $('#f-table-id').val(id); $('#f-table-floor').val(floorId); $('#f-table-number').val(number); $('#f-table-seats').val(seats);
    new bootstrap.Modal(document.getElementById('tableModal')).show();
}

$('#tableForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'); const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + STRINGS.processing);
    $.post('<?= buildUrl('api/restaurant/save_table.php') ?>', $(this).serialize() + '&warehouse_id=' + currentWarehouseId(), function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('tableModal')).hide();
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false }).then(loadTables);
        } else {
            $('#table-message').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message) + '</div>');
        }
    }, 'json').fail(() => $('#table-message').html('<div class="alert alert-danger py-2 mb-0">' + STRINGS.serverError + '</div>'))
      .always(() => btn.prop('disabled', false).html(orig));
});

function setTableStatus(tableId, status) {
    $.post('<?= buildUrl('api/restaurant/update_table_status.php') ?>', { table_id: tableId, status: status, _csrf: CSRF_FIELD }, function (res) {
        if (res.success) { loadTables(); } else { Swal.fire({ icon: 'error', title: STRINGS.error, text: res.message }); }
    }, 'json');
}

$('.modal').on('hidden.bs.modal', function () {
    $(this).find('form')[0]?.reset();
    $(this).find('#table-message').html('');
});
</script>

<?php
require_once 'footer.php';
ob_end_flush();
