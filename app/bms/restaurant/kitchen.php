<?php
/**
 * app/bms/restaurant/kitchen.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — Kitchen Station admin (create/rename
 * the stations a product's `kitchen_station_id` can route to). The live
 * Kitchen Display queue itself is a separate page, kitchen_dashboard.php.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_scope.php';

$page_title = 'Kitchen Stations';
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
        <h4 class="mb-0"><i class="bi bi-egg-fried text-primary me-2"></i><?= t('Kitchen Stations') ?></h4>
        <a href="<?= getUrl('restaurant') ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> <?= t('Restaurant Hub') ?>
        </a>
    </div>

    <?php if (empty($warehouses)): ?>
    <div class="alert alert-warning"><i class="bi bi-info-circle me-1"></i>
        <?= t('No warehouse in your scope is set to Restaurant or Hybrid mode yet. Switch a warehouse\'s POS Mode first.') ?>
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
                    <button class="btn btn-primary btn-sm" onclick="openStationModal()">
                        <i class="bi bi-plus-circle me-1"></i> <?= t('Add Station') ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="stationsContainer" class="row g-2"></div>

    <?php endif; ?>
</div>

<div class="modal fade" id="stationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><span id="stationModalTitle"><?= t('Add Station') ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="stationForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="station_id" id="f-station-id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="station-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Name') ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="f-station-name" required maxlength="100" placeholder="<?= t('e.g. Grill, Bar, Bakery') ?>">
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
const STRINGS = {
    noStations: <?= json_encode(t('No kitchen stations yet.')) ?>,
    processing: <?= json_encode(t('Processing...')) ?>,
    serverError: <?= json_encode(t('Server error.')) ?>,
};
$(document).ready(function () {
    if ($('#whSelect').length) {
        $('#whSelect').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#whSelect').on('change', loadStations);
        loadStations();
    }
});
function currentWarehouseId() { return parseInt($('#whSelect').val() || 0, 10); }

function loadStations() {
    const wid = currentWarehouseId();
    if (!wid) return;
    $('#stationsContainer').html('<div class="col-12 text-center py-4"><div class="spinner-border text-primary"></div></div>');
    $.getJSON('<?= buildUrl('api/restaurant/get_kitchen_stations.php') ?>', { warehouse_id: wid }, function (res) {
        if (!res.success || !res.data.length) {
            $('#stationsContainer').html('<div class="col-12 text-center text-muted py-4">' + STRINGS.noStations + '</div>');
            return;
        }
        let html = '';
        res.data.forEach(s => {
            html += `
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm text-center p-3">
                    <div class="fs-4 text-primary mb-1"><i class="bi bi-egg-fried"></i></div>
                    <div class="fw-bold">${safeOutput(s.name)}</div>
                    <button class="btn btn-sm btn-outline-secondary mt-2" onclick='editStation(${s.station_id}, ${JSON.stringify(s.name)})'><i class="bi bi-pencil"></i></button>
                </div>
            </div>`;
        });
        $('#stationsContainer').html(html);
    });
}

function openStationModal() {
    $('#stationModalTitle').text(<?= json_encode(t('Add Station')) ?>);
    $('#f-station-id').val(''); $('#f-station-name').val('');
    new bootstrap.Modal(document.getElementById('stationModal')).show();
}
function editStation(id, name) {
    $('#stationModalTitle').text(<?= json_encode(t('Edit Station')) ?>);
    $('#f-station-id').val(id); $('#f-station-name').val(name);
    new bootstrap.Modal(document.getElementById('stationModal')).show();
}

$('#stationForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'); const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + STRINGS.processing);
    $.post('<?= buildUrl('api/restaurant/save_kitchen_station.php') ?>', $(this).serialize() + '&warehouse_id=' + currentWarehouseId(), function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('stationModal')).hide();
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false }).then(loadStations);
        } else {
            $('#station-message').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message) + '</div>');
        }
    }, 'json').fail(() => $('#station-message').html('<div class="alert alert-danger py-2 mb-0">' + STRINGS.serverError + '</div>'))
      .always(() => btn.prop('disabled', false).html(orig));
});
</script>

<?php
require_once 'footer.php';
ob_end_flush();
