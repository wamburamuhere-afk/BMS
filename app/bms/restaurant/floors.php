<?php
/**
 * app/bms/restaurant/floors.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — Floors & Tables admin. One page
 * manages both: a floor is just a grouping for its tables, so splitting
 * them into two separate pages would force two round-trips for a task
 * that's really one screen (pick a warehouse, see its floors, see each
 * floor's tables). Backed by api/restaurant/{get,save}_floor.php and
 * {get,save}_table.php + update_table_status.php (all shipped in the
 * backend half, commit 9c4d3030).
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_scope.php';

$page_title = 'Floors & Tables';
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
        <h4 class="mb-0"><i class="bi bi-diagram-3 text-primary me-2"></i><?= t('Floors & Tables') ?></h4>
        <div class="d-flex gap-2">
            <a href="<?= getUrl('restaurant/tables') ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-list-ul me-1"></i> <?= t('Manage All Tables') ?>
            </a>
            <a href="<?= getUrl('restaurant') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> <?= t('Restaurant Hub') ?>
            </a>
        </div>
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
                <?php if ($can_edit): ?>
                <div class="col-auto">
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#floorModal" onclick="openFloorModal()">
                        <i class="bi bi-plus-circle me-1"></i> <?= t('Add Floor') ?>
                    </button>
                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tableModal" onclick="openTableModal()">
                        <i class="bi bi-plus-circle me-1"></i> <?= t('Add Table') ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="floorsContainer"><div class="text-center py-4"><div class="spinner-border text-primary"></div></div></div>

    <?php endif; ?>
</div>

<!-- Add/Edit Floor Modal -->
<div class="modal fade" id="floorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><span id="floorModalTitle"><?= t('Add Floor') ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="floorForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="floor_id" id="f-floor-id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="floor-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Name') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="f-floor-name" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Sort Order') ?></label>
                        <input type="number" class="form-control" name="sort_order" id="f-floor-sort" value="0">
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
    noFloors: <?= json_encode(t('No floors yet. Add one to start placing tables.')) ?>,
    tables: <?= json_encode(t('table(s)')) ?>,
    statusAvailable: <?= json_encode(t('Available')) ?>,
    statusOccupied: <?= json_encode(t('Occupied')) ?>,
    statusReserved: <?= json_encode(t('Reserved')) ?>,
    statusCleaning: <?= json_encode(t('Cleaning')) ?>,
    seats: <?= json_encode(t('seats')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    serverError: <?= json_encode(t('Server error.')) ?>,
    processing: <?= json_encode(t('Processing...')) ?>,
};
const STATUS_COLORS = { available: '#198754', occupied: '#dc3545', reserved: '#fd7e14', cleaning: '#6c757d' };
const STATUS_LABELS = { available: STRINGS.statusAvailable, occupied: STRINGS.statusOccupied, reserved: STRINGS.statusReserved, cleaning: STRINGS.statusCleaning };
let FLOORS_CACHE = [];

$(document).ready(function () {
    if ($('#whSelect').length) {
        $('#whSelect').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#whSelect').on('change', loadFloors);
        loadFloors();
    }
});

function currentWarehouseId() { return parseInt($('#whSelect').val() || 0, 10); }

function loadFloors() {
    const wid = currentWarehouseId();
    if (!wid) return;
    $('#floorsContainer').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    $.getJSON('<?= buildUrl('api/restaurant/get_floors.php') ?>', { warehouse_id: wid }, function (res) {
        if (!res.success) { $('#floorsContainer').html('<div class="alert alert-danger">' + safeOutput(res.message) + '</div>'); return; }
        FLOORS_CACHE = res.data;
        if (!res.data.length) {
            $('#floorsContainer').html('<div class="text-center text-muted py-5">' + STRINGS.noFloors + '</div>');
            return;
        }
        renderFloorSelect(res.data);
        renderFloors(res.data, wid);
    });
}

function renderFloorSelect(floors) {
    let opts = '';
    floors.forEach(f => { opts += `<option value="${f.floor_id}">${safeOutput(f.name)}</option>`; });
    $('#f-table-floor').html(opts);
}

function renderFloors(floors, wid) {
    let html = '';
    let pending = floors.length;
    const sections = {};
    floors.forEach(f => {
        $.getJSON('<?= buildUrl('api/restaurant/get_tables.php') ?>', { warehouse_id: wid, floor_id: f.floor_id }, function (res) {
            sections[f.floor_id] = renderFloorSection(f, res.success ? res.data : []);
            pending--;
            if (pending === 0) {
                let out = '';
                floors.forEach(fl => { out += sections[fl.floor_id]; });
                $('#floorsContainer').html(out);
            }
        });
    });
}

function renderFloorSection(floor, tables) {
    let cards = '';
    tables.forEach(t => {
        const color = STATUS_COLORS[t.status] || '#6c757d';
        const label = STATUS_LABELS[t.status] || t.status;
        cards += `
        <div class="col-6 col-md-3 col-lg-2">
            <div class="card border-0 shadow-sm text-center p-2" style="border-top:4px solid ${color} !important;">
                <div class="fw-bold">${safeOutput(t.table_number)}</div>
                <div class="small text-muted">${t.seats} ${STRINGS.seats}</div>
                <span class="badge mt-1" style="background:${color};color:#fff;">${safeOutput(label)}</span>
                ${CAN_EDIT ? `
                <div class="d-flex gap-1 mt-2 justify-content-center">
                    <button class="btn btn-sm btn-outline-secondary" onclick="editTable(${t.table_id}, ${t.floor_id}, ${JSON.stringify(t.table_number).replace(/"/g, '&quot;')}, ${t.seats})" style="padding:2px 6px;"><i class="bi bi-pencil"></i></button>
                    <select class="form-select form-select-sm" style="width:auto;font-size:.7rem;" onchange="setTableStatus(${t.table_id}, this.value)">
                        <option value="">${STRINGS.statusAvailable}/...</option>
                        <option value="available">${STRINGS.statusAvailable}</option>
                        <option value="occupied">${STRINGS.statusOccupied}</option>
                        <option value="reserved">${STRINGS.statusReserved}</option>
                        <option value="cleaning">${STRINGS.statusCleaning}</option>
                    </select>
                </div>` : ''}
            </div>
        </div>`;
    });
    return `
    <div class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0"><i class="bi bi-layers me-1"></i> ${safeOutput(floor.name)} <span class="text-muted small">(${tables.length} ${STRINGS.tables})</span></h6>
            ${CAN_EDIT ? `<button class="btn btn-sm btn-outline-primary" onclick="editFloor(${floor.floor_id}, ${JSON.stringify(floor.name).replace(/"/g, '&quot;')}, ${floor.sort_order})"><i class="bi bi-pencil"></i></button>` : ''}
        </div>
        <div class="row g-2">${cards || '<div class="col-12 text-muted small">' + STRINGS.noFloors + '</div>'}</div>
    </div>`;
}

function openFloorModal() {
    $('#floorModalTitle').text(<?= json_encode(t('Add Floor')) ?>);
    $('#f-floor-id').val(''); $('#f-floor-name').val(''); $('#f-floor-sort').val(0);
}
function editFloor(id, name, sort) {
    $('#floorModalTitle').text(<?= json_encode(t('Edit Floor')) ?>);
    $('#f-floor-id').val(id); $('#f-floor-name').val(name); $('#f-floor-sort').val(sort);
    new bootstrap.Modal(document.getElementById('floorModal')).show();
}
function openTableModal() {
    $('#tableModalTitle').text(<?= json_encode(t('Add Table')) ?>);
    $('#f-table-id').val(''); $('#f-table-number').val(''); $('#f-table-seats').val(2);
}
function editTable(id, floorId, number, seats) {
    $('#tableModalTitle').text(<?= json_encode(t('Edit Table')) ?>);
    $('#f-table-id').val(id); $('#f-table-floor').val(floorId); $('#f-table-number').val(number); $('#f-table-seats').val(seats);
    new bootstrap.Modal(document.getElementById('tableModal')).show();
}

$('#floorForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'); const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + STRINGS.processing);
    $.post('<?= buildUrl('api/restaurant/save_floor.php') ?>', $(this).serialize() + '&warehouse_id=' + currentWarehouseId(), function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('floorModal')).hide();
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false }).then(loadFloors);
        } else {
            $('#floor-message').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message) + '</div>');
        }
    }, 'json').fail(() => $('#floor-message').html('<div class="alert alert-danger py-2 mb-0">' + STRINGS.serverError + '</div>'))
      .always(() => btn.prop('disabled', false).html(orig));
});

$('#tableForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'); const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + STRINGS.processing);
    $.post('<?= buildUrl('api/restaurant/save_table.php') ?>', $(this).serialize() + '&warehouse_id=' + currentWarehouseId(), function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('tableModal')).hide();
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false }).then(loadFloors);
        } else {
            $('#table-message').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message) + '</div>');
        }
    }, 'json').fail(() => $('#table-message').html('<div class="alert alert-danger py-2 mb-0">' + STRINGS.serverError + '</div>'))
      .always(() => btn.prop('disabled', false).html(orig));
});

function setTableStatus(tableId, status) {
    if (!status) return;
    $.post('<?= buildUrl('api/restaurant/update_table_status.php') ?>', { table_id: tableId, status: status, _csrf: CSRF_FIELD }, function (res) {
        if (res.success) { loadFloors(); } else { Swal.fire({ icon: 'error', title: STRINGS.error, text: res.message }); }
    }, 'json');
}
</script>

<?php
require_once 'footer.php';
ob_end_flush();
