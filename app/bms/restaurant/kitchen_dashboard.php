<?php
/**
 * app/bms/restaurant/kitchen_dashboard.php
 *
 * Phase 30 (pos_upgrade_plan.md §9) — the live Kitchen Display (KDS): polls
 * api/restaurant/get_kitchen_tickets.php (active queue, station+warehouse
 * scoped) and advances tickets queued -> preparing -> ready -> served via
 * api/restaurant/update_ticket_status.php. Auto-refresh selector mirrors the
 * plan's "same UX as SalePro's 5/10/15/30/60-second selector" spec.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_scope.php';

$page_title = 'Kitchen Display';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}

$warehouses = restaurantWarehousesForSelect($pdo);
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-egg-fried text-primary me-2"></i><?= t('Kitchen Display') ?></h4>
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
                    <label class="form-label small mb-1"><?= t('Station') ?></label>
                    <select id="stationFilter" class="form-select form-select-sm select2-static" style="min-width:180px;">
                        <option value="0"><?= t('All Stations') ?></option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1"><?= t('Auto-refresh') ?></label>
                    <select id="refreshInterval" class="form-select form-select-sm">
                        <option value="5">5s</option>
                        <option value="10" selected>10s</option>
                        <option value="15">15s</option>
                        <option value="30">30s</option>
                        <option value="60">60s</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div id="ticketsContainer" class="row g-3"></div>

    <?php endif; ?>
</div>

<script>
const STAGES = ['queued', 'preparing', 'ready', 'served'];
const STAGE_LABELS = {
    queued: <?= json_encode(t('Queued')) ?>, preparing: <?= json_encode(t('Preparing')) ?>,
    ready: <?= json_encode(t('Ready')) ?>, served: <?= json_encode(t('Served')) ?>,
};
const STAGE_COLORS = { queued: '#6c757d', preparing: '#fd7e14', ready: '#198754', served: '#0d6efd' };
const STRINGS = {
    noTickets: <?= json_encode(t('No active kitchen tickets.')) ?>,
    table: <?= json_encode(t('Table')) ?>,
    advance: <?= json_encode(t('Advance')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    qty: <?= json_encode(t('x')) ?>,
};
let pollTimer = null;

$(document).ready(function () {
    if (!$('#whSelect').length) return;
    $('#whSelect, #stationFilter').select2({ theme: 'bootstrap-5', width: '100%' });
    $('#whSelect').on('change', function () { loadStations(); loadTickets(); });
    $('#stationFilter').on('change', loadTickets);
    $('#refreshInterval').on('change', restartPolling);
    loadStations();
    loadTickets();
    restartPolling();
});

function currentWarehouseId() { return parseInt($('#whSelect').val() || 0, 10); }

function loadStations() {
    $.getJSON('<?= buildUrl('api/restaurant/get_kitchen_stations.php') ?>', { warehouse_id: currentWarehouseId() }, function (res) {
        let opts = `<option value="0"><?= t('All Stations') ?></option>`;
        (res.success ? res.data : []).forEach(s => { opts += `<option value="${s.station_id}">${safeOutput(s.name)}</option>`; });
        $('#stationFilter').html(opts).trigger('change.select2');
    });
}

function restartPolling() {
    if (pollTimer) clearInterval(pollTimer);
    const seconds = parseInt($('#refreshInterval').val() || 10, 10);
    pollTimer = setInterval(loadTickets, seconds * 1000);
}

function loadTickets() {
    const wid = currentWarehouseId();
    if (!wid) return;
    const stationId = parseInt($('#stationFilter').val() || 0, 10);
    const params = { warehouse_id: wid, status: 'queued,preparing,ready' };
    if (stationId > 0) params.station_id = stationId;
    $.getJSON('<?= buildUrl('api/restaurant/get_kitchen_tickets.php') ?>', params, function (res) {
        const tickets = res.success ? res.data : [];
        if (!tickets.length) { $('#ticketsContainer').html(`<div class="col-12 text-center text-muted py-5">${STRINGS.noTickets}</div>`); return; }
        let html = '';
        tickets.forEach(t => {
            const color = STAGE_COLORS[t.status] || '#6c757d';
            const nextIdx = STAGES.indexOf(t.status) + 1;
            const nextStage = nextIdx < STAGES.length ? STAGES[nextIdx] : null;
            let items = '';
            (t.items || []).forEach(it => {
                items += `<div class="d-flex justify-content-between"><span>${it.quantity}${STRINGS.qty} ${safeOutput(it.product_name)}</span></div>`;
                if (it.modifiers_summary) items += `<div class="small text-muted ps-2">${safeOutput(it.modifiers_summary)}</div>`;
            });
            html += `
            <div class="col-6 col-md-4 col-lg-3">
                <div class="card border-0 shadow-sm h-100" style="border-top:4px solid ${color} !important;">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small">${safeOutput(t.station_name)}</span>
                            <span class="badge" style="background:${color};color:#fff;">${STAGE_LABELS[t.status]}</span>
                        </div>
                        ${t.table_number ? `<div class="small text-muted mb-1">${STRINGS.table}: ${safeOutput(t.table_number)}</div>` : ''}
                        <div class="small">${items}</div>
                        ${nextStage ? `<button class="btn btn-sm btn-primary w-100 mt-2" onclick="advance(${t.ticket_id}, '${nextStage}')">${STRINGS.advance} → ${STAGE_LABELS[nextStage]}</button>` : ''}
                    </div>
                </div>
            </div>`;
        });
        $('#ticketsContainer').html(html);
    });
}

function advance(ticketId, status) {
    $.post('<?= buildUrl('api/restaurant/update_ticket_status.php') ?>', { ticket_id: ticketId, status: status, _csrf: <?= json_encode(csrf_token()) ?> }, function (res) {
        if (res.success) { loadTickets(); } else { Swal.fire({ icon: 'error', title: STRINGS.error, text: res.message }); }
    }, 'json');
}
</script>

<?php
require_once 'footer.php';
ob_end_flush();
