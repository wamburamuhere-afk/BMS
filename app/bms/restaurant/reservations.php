<?php
/**
 * app/bms/restaurant/reservations.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — Table reservation admin: book, list by
 * date, and advance status (booked -> seated -> completed/cancelled/no_show).
 * Backed by api/restaurant/{get,save}_reservation.php +
 * update_reservation_status.php (backend half, commit 9c4d3030).
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_scope.php';

$page_title = 'Reservations';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$can_create = canCreate('restaurant_pos');
$can_edit   = canEdit('restaurant_pos');

$warehouses = restaurantWarehousesForSelect($pdo);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-calendar-check text-primary me-2"></i><?= t('Reservations') ?></h4>
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
                    <label class="form-label small mb-1"><?= t('Date') ?></label>
                    <input type="date" id="dateFilter" class="form-control form-control-sm">
                </div>
                <?php if ($can_create): ?>
                <div class="col-auto">
                    <button class="btn btn-primary btn-sm" onclick="openReservationModal()">
                        <i class="bi bi-plus-circle me-1"></i> <?= t('New Reservation') ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="tableView">
        <table id="resTable" class="table table-hover align-middle w-100">
            <thead class="table-light">
                <tr>
                    <th><?= t('Time') ?></th>
                    <th><?= t('Customer') ?></th>
                    <th><?= t('Table') ?></th>
                    <th><?= t('Party Size') ?></th>
                    <th><?= t('Status') ?></th>
                    <th class="text-end"><?= t('Actions') ?></th>
                </tr>
            </thead>
            <tbody id="resBody"></tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<div class="modal fade" id="reservationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><?= t('New Reservation') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="resForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="res-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Customer') ?></label>
                        <select id="f-res-customer" class="form-select" style="width:100%"></select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label"><?= t('Customer Name') ?> <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="customer_name" id="f-res-name" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= t('Phone') ?></label>
                            <input type="text" class="form-control" name="customer_phone" id="f-res-phone">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label"><?= t('Table') ?> <span class="text-danger">*</span></label>
                            <select class="form-select" name="table_id" id="f-res-table" required></select>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= t('Party Size') ?></label>
                            <input type="number" class="form-control" name="party_size" id="f-res-party" value="2" min="1">
                        </div>
                    </div>
                    <div class="mb-3 mt-2">
                        <label class="form-label"><?= t('Reservation Time') ?> <span class="text-danger">*</span></label>
                        <input type="datetime-local" class="form-control" name="reservation_time" id="f-res-time" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Notes') ?></label>
                        <textarea class="form-control" name="notes" id="f-res-notes" rows="2"></textarea>
                    </div>
                    <input type="hidden" name="customer_id" id="f-res-customer-id">
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
const STATUS_LABELS = {
    booked: <?= json_encode(t('Booked')) ?>, seated: <?= json_encode(t('Seated')) ?>,
    completed: <?= json_encode(t('Completed')) ?>, cancelled: <?= json_encode(t('Cancelled')) ?>, no_show: <?= json_encode(t('No Show')) ?>,
};
const STATUS_COLORS = { booked: '#0d6efd', seated: '#198754', completed: '#6c757d', cancelled: '#dc3545', no_show: '#fd7e14' };
const STRINGS = {
    noRecords: <?= json_encode(t('No records found')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    processing: <?= json_encode(t('Processing...')) ?>,
    serverError: <?= json_encode(t('Server error.')) ?>,
    searchCustomer: <?= json_encode(t('Search or leave blank for a walk-in name')) ?>,
};

$(document).ready(function () {
    $('#dateFilter').val(new Date().toISOString().slice(0, 10));
    if ($('#whSelect').length) {
        $('#whSelect').select2({ theme: 'bootstrap-5', width: '100%' });
        $('#whSelect').on('change', loadReservations);
        $('#dateFilter').on('change', loadReservations);
        loadReservations();
    }
    $('#f-res-customer').select2({
        theme: 'bootstrap-5', width: '100%', placeholder: STRINGS.searchCustomer, allowClear: true,
        ajax: { url: '<?= buildUrl('api/pos/search_customers.php') ?>', dataType: 'json', delay: 250, data: p => ({ q: p.term }) }
    }).on('select2:select', function (e) {
        $('#f-res-customer-id').val(e.params.data.id);
        $('#f-res-name').val(e.params.data.name || e.params.data.text);
    }).on('select2:clear', function () { $('#f-res-customer-id').val(''); });
});

function currentWarehouseId() { return parseInt($('#whSelect').val() || 0, 10); }

function loadReservations() {
    const wid = currentWarehouseId();
    const date = $('#dateFilter').val();
    if (!wid) return;
    $.getJSON('<?= buildUrl('api/restaurant/get_reservations.php') ?>', { warehouse_id: wid, date: date }, function (res) {
        const rows = res.success ? res.data : [];
        if (!rows.length) { $('#resBody').html(`<tr><td colspan="6" class="text-center text-muted py-4">${STRINGS.noRecords}</td></tr>`); return; }
        let html = '';
        rows.forEach(r => {
            const time = new Date(r.reservation_time.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            const color = STATUS_COLORS[r.status] || '#6c757d';
            const label = STATUS_LABELS[r.status] || r.status;
            let actions = '';
            if (CAN_EDIT && r.status === 'booked') {
                actions += `<button class="btn btn-sm btn-outline-success" onclick="setResStatus(${r.id}, 'seated')" title="${STATUS_LABELS.seated}"><i class="bi bi-person-check"></i></button> `;
                actions += `<button class="btn btn-sm btn-outline-danger" onclick="setResStatus(${r.id}, 'cancelled')" title="${STATUS_LABELS.cancelled}"><i class="bi bi-x-circle"></i></button> `;
                actions += `<button class="btn btn-sm btn-outline-warning" onclick="setResStatus(${r.id}, 'no_show')" title="${STATUS_LABELS.no_show}"><i class="bi bi-person-dash"></i></button>`;
            } else if (CAN_EDIT && r.status === 'seated') {
                actions += `<button class="btn btn-sm btn-outline-secondary" onclick="setResStatus(${r.id}, 'completed')" title="${STATUS_LABELS.completed}"><i class="bi bi-check2-circle"></i></button>`;
            }
            html += `<tr>
                <td>${time}</td>
                <td>${safeOutput(r.customer_name)}${r.customer_phone ? '<div class="small text-muted">' + safeOutput(r.customer_phone) + '</div>' : ''}</td>
                <td>${safeOutput(r.table_number)}</td>
                <td>${r.party_size}</td>
                <td><span class="badge" style="background:${color};color:#fff;">${label}</span></td>
                <td class="text-end">${actions}</td>
            </tr>`;
        });
        $('#resBody').html(html);
    });
}

function openReservationModal() {
    $('#resForm')[0].reset();
    $('#f-res-customer').val(null).trigger('change');
    $('#f-res-customer-id').val('');
    $('#f-res-time').val('');
    $('#res-message').html('');
    const wid = currentWarehouseId();
    $.getJSON('<?= buildUrl('api/restaurant/get_tables.php') ?>', { warehouse_id: wid }, function (res) {
        let opts = '';
        (res.success ? res.data : []).forEach(t => { opts += `<option value="${t.table_id}">${safeOutput(t.table_number)} (${t.floor_name})</option>`; });
        $('#f-res-table').html(opts);
    });
    new bootstrap.Modal(document.getElementById('reservationModal')).show();
}

$('#resForm').on('submit', function (e) {
    e.preventDefault();
    const btn = $(this).find('[type="submit"]'); const orig = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> ' + STRINGS.processing);
    $.post('<?= buildUrl('api/restaurant/save_reservation.php') ?>', $(this).serialize() + '&warehouse_id=' + currentWarehouseId(), function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('reservationModal')).hide();
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false }).then(loadReservations);
        } else {
            $('#res-message').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message) + '</div>');
        }
    }, 'json').fail(() => $('#res-message').html('<div class="alert alert-danger py-2 mb-0">' + STRINGS.serverError + '</div>'))
      .always(() => btn.prop('disabled', false).html(orig));
});

function setResStatus(id, status) {
    $.post('<?= buildUrl('api/restaurant/update_reservation_status.php') ?>', { id: id, status: status, _csrf: CSRF_FIELD }, function (res) {
        if (res.success) { loadReservations(); } else { Swal.fire({ icon: 'error', title: STRINGS.error, text: res.message }); }
    }, 'json');
}
</script>

<?php
require_once 'footer.php';
ob_end_flush();
