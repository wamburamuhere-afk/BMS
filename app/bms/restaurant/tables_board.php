<?php
/**
 * app/bms/restaurant/tables_board.php — live Tables screen (restaurant_plan.md Phase 2b).
 *
 * Every table of a restaurant shop at a glance: free / has an order (amount, items,
 * minutes open). Tap a table → its order opens on the till (pos?table=…); Move
 * shifts an open order to a free table. Refreshes every 20 s.
 * Smart POS + Restaurant POS, Restaurant/Hybrid shops only. Table setup stays on
 * restaurant/floors ("Manage tables").
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
$module_on  = restaurantModuleOn($pdo);
$warehouses = $module_on ? restaurantWarehousesForSelect($pdo) : [];
$can_sell   = canCreate('pos');
?>

<div class="container-fluid mt-3 mt-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
        <h4 class="mb-0 text-truncate"><i class="bi bi-grid-3x3-gap text-primary me-2"></i><?= t('Tables') ?></h4>
        <div class="d-flex gap-2 flex-shrink-0">
            <?php if (canEdit('restaurant_pos')): ?>
            <a href="<?= getUrl('restaurant/floors') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil-square"></i><span class="d-none d-md-inline ms-1"><?= t('Manage tables') ?></span></a>
            <?php endif; ?>
            <?php if ($can_sell && $warehouses): ?>
            <a href="<?= getUrl('pos') ?>?ot=take_away" class="btn btn-primary btn-sm"><i class="bi bi-bag me-1"></i><?= t('Take away') ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$module_on): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i><?= t('The Tables screen is available for restaurant shops in Smart POS.') ?></div>
    <?php elseif (empty($warehouses)): ?>
    <div class="alert alert-warning"><i class="bi bi-info-circle me-1"></i>
        <?= wLabel("No warehouse in your scope is set to Restaurant or Hybrid mode yet. Switch a warehouse's POS Mode first.", "No shop in your scope is set to Restaurant or Hybrid mode yet. Switch a shop's POS Mode first.") ?>
    </div>
    <?php else: ?>

    <?php if (count($warehouses) > 1): ?>
    <div class="mb-3" style="max-width:320px;">
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
        <div class="col-4"><div class="card tb-stat text-center p-2"><div class="fs-4 fw-bold text-primary" id="stFree">0</div><div class="small text-muted"><?= t('Free') ?></div></div></div>
        <div class="col-4"><div class="card tb-stat text-center p-2"><div class="fs-4 fw-bold text-danger" id="stBusy">0</div><div class="small text-muted"><?= t('With order') ?></div></div></div>
        <div class="col-4"><div class="card tb-stat text-center p-2"><div class="fs-5 fw-bold text-primary text-truncate" id="stOpen">0</div><div class="small text-muted"><?= t('Open bills') ?></div></div></div>
    </div>

    <div id="tbBoard"><div class="text-center py-5"><div class="spinner-border text-primary"></div></div></div>
    <div class="small text-muted text-center mt-3"><i class="bi bi-arrow-repeat me-1"></i><?= t('Updates every 20 seconds.') ?></div>

    <?php endif; ?>
</div>

<!-- Move order -->
<div class="modal fade" id="moveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><?= t('Move or join') ?> — <span id="moveFrom"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2"><?= t('Choose a free table:') ?></div>
                <div class="row g-2" id="moveTargets"></div>
            </div>
        </div>
    </div>
</div>

<style>
.tb-stat { background:#e7f0ff; border:1px solid #b6ccfe; }
.tb-card { border:0; border-top:4px solid #0d6efd; cursor:pointer; transition:transform .12s ease, box-shadow .12s ease; min-height:118px; }
.tb-card:active { transform:scale(.98); }
.tb-card:hover { box-shadow:0 .5rem 1rem rgba(13,110,253,.15) !important; }
.tb-card.tb-free { border-top-color:#0d6efd; }
.tb-card.tb-busy { border-top-color:#dc3545; background:#fff5f5; }
.tb-card.tb-other { border-top-color:#6c757d; }
.tb-num { font-size:1.25rem; font-weight:700; overflow-wrap:anywhere; }
.tb-amt { font-weight:700; color:#dc3545; }
.tb-pill { font-size:.72rem; border-radius:999px; padding:.15rem .55rem; white-space:nowrap; }
.tb-pill-free { background:#cfe2ff; color:#084298; }
.tb-pill-busy { background:#dc3545; color:#fff; }
.tb-pill-other { background:#6c757d; color:#fff; }
.tb-move { position:relative; z-index:2; }
</style>

<script>
const TB = {
    canSell: <?= json_encode($can_sell) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    posUrl: <?= json_encode(getUrl('pos')) ?>,
    free: <?= json_encode(t('Free')) ?>,
    busy: <?= json_encode(t('Has order')) ?>,
    reserved: <?= json_encode(t('Reserved')) ?>,
    cleaning: <?= json_encode(t('Cleaning')) ?>,
    seats: <?= json_encode(t('seats')) ?>,
    items: <?= json_encode(t('items')) ?>,
    min: <?= json_encode(t('min')) ?>,
    tapToOrder: <?= json_encode(t('Tap to take an order')) ?>,
    move: <?= json_encode(t('Move')) ?>,
    noTables: <?= json_encode(t('No tables yet.')) ?>,
    addTables: <?= json_encode(t('Add tables')) ?>,
    noFree: <?= json_encode(t('No free table right now.')) ?>,
    joinWith: <?= json_encode(t('Or join with a table that has an order:')) ?>,
    joinQ: <?= json_encode(t('Join table %1$s into table %2$s?')) ?>,
    joinNote: <?= json_encode(t('Both orders become one bill on the second table. The first table becomes free.')) ?>,
    join: <?= json_encode(t('Join')) ?>,
    cancel: <?= json_encode(t('Cancel')) ?>,
    error: <?= json_encode(t('Error')) ?>,
    serverError: <?= json_encode(t('Server error. Please try again.')) ?>,
    floorsUrl: <?= json_encode(getUrl('restaurant/floors')) ?>,
};
let TB_DATA = [];
const tbFmt = n => Number(n || 0).toLocaleString();

$(function () {
    if (!$('#whSelect').length) return;
    if ($('#whSelect').is('select')) $('#whSelect').select2({ theme: 'bootstrap-5', width: '100%' }).on('change', loadBoard);
    loadBoard();
    setInterval(function () { if (!document.hidden && !$('.modal.show').length) loadBoard(true); }, 20000);
});

function tbWid() { return parseInt($('#whSelect').val() || 0, 10); }

function loadBoard(quiet) {
    $.getJSON('<?= buildUrl('api/restaurant/get_tables_board.php') ?>', { warehouse_id: tbWid() }, function (res) {
        if (!res.success) { if (!quiet) Swal.fire({ icon: 'error', title: TB.error, text: res.message }); return; }
        TB_DATA = res.data;
        renderBoard();
    }).fail(function () { if (!quiet) Swal.fire({ icon: 'error', title: TB.error, text: TB.serverError }); });
}

function openTable(t) {
    if (!TB.canSell) return;
    const q = new URLSearchParams({ table: t.table_id, wid: tbWid(), tlabel: t.table_number });
    window.location.href = TB.posUrl + (TB.posUrl.indexOf('?') > -1 ? '&' : '?') + q.toString();
}

function renderBoard() {
    const busy = TB_DATA.filter(t => t.hold_id);
    $('#stFree').text(TB_DATA.filter(t => !t.hold_id && t.status === 'available').length);
    $('#stBusy').text(busy.length);
    $('#stOpen').text(tbFmt(busy.reduce((s, t) => s + t.total, 0)));
    if (!TB_DATA.length) {
        $('#tbBoard').html(`<div class="text-center text-muted py-5"><i class="bi bi-grid-3x3-gap fs-1 d-block mb-2 text-primary"></i>${TB.noTables}<div class="mt-3"><a class="btn btn-primary" href="${TB.floorsUrl}">${TB.addTables}</a></div></div>`);
        return;
    }
    const areas = {};
    TB_DATA.forEach(t => { (areas[t.area || ''] = areas[t.area || ''] || []).push(t); });
    const multi = Object.keys(areas).length > 1;
    let html = '';
    Object.keys(areas).forEach(area => {
        if (multi) html += `<h6 class="text-muted small text-uppercase mt-2 mb-2">${safeOutput(area)}</h6>`;
        html += '<div class="row g-2 g-md-3 mb-2">';
        areas[area].forEach(t => {
            const isBusy = !!t.hold_id;
            const cls = isBusy ? 'tb-busy' : (t.status === 'available' ? 'tb-free' : 'tb-other');
            const pill = isBusy ? `<span class="tb-pill tb-pill-busy">${TB.busy}</span>`
                : (t.status === 'available' ? `<span class="tb-pill tb-pill-free">${TB.free}</span>`
                : `<span class="tb-pill tb-pill-other">${t.status === 'reserved' ? TB.reserved : TB.cleaning}</span>`);
            const body = isBusy
                ? `<div class="tb-amt fs-5">${tbFmt(t.total)}</div>
                   <div class="small text-muted">${tbFmt(t.item_count)} ${TB.items} · ${t.minutes_open} ${TB.min}</div>`
                : `<div class="small text-muted">${t.seats} ${TB.seats}</div>
                   ${TB.canSell ? `<div class="small text-primary mt-1">${TB.tapToOrder}</div>` : ''}`;
            html += `<div class="col-6 col-md-4 col-lg-3 col-xl-2">
                <div class="card shadow-sm tb-card ${cls} h-100 p-2 p-md-3" onclick='openTable(${JSON.stringify({ table_id: t.table_id, table_number: t.table_number })})'>
                    <div class="d-flex justify-content-between align-items-start gap-1 flex-wrap">
                        <div class="tb-num">${safeOutput(t.table_number)}</div>${pill}
                    </div>
                    <div class="mt-1">${body}</div>
                    ${isBusy && TB.canSell ? `<button type="button" class="btn btn-sm btn-outline-primary mt-2 tb-move" onclick='event.stopPropagation(); openMove(${t.table_id})'><i class="bi bi-arrow-left-right me-1"></i>${TB.move}</button>` : ''}
                </div>
            </div>`;
        });
        html += '</div>';
    });
    $('#tbBoard').html(html);
}

function openMove(fromId) {
    const from = TB_DATA.find(t => t.table_id === fromId);
    if (!from) return;
    $('#moveFrom').text(from.table_number);
    const free = TB_DATA.filter(t => !t.hold_id && t.table_id !== fromId && t.status !== 'cleaning');
    let html = free.length ? free.map(t =>
        `<div class="col-4"><button type="button" class="btn btn-outline-primary w-100 py-3 fw-bold" onclick="doMove(${fromId}, ${t.table_id})">${safeOutput(t.table_number)}</button></div>`).join('')
        : `<div class="col-12 text-muted text-center py-3">${TB.noFree}</div>`;
    // restaurant_plan.md Phase 4 — join this order into another table that already has one.
    const busy = TB_DATA.filter(t => t.hold_id && t.table_id !== fromId);
    if (busy.length) {
        html += `<div class="col-12 small text-muted mt-3">${TB.joinWith}</div>` + busy.map(t =>
            `<div class="col-6"><button type="button" class="btn btn-outline-danger w-100 py-2" onclick="doJoin(${fromId}, ${t.table_id}, ${JSON.stringify(t.table_number).replace(/"/g, '&quot;')})"><span class="fw-bold">${safeOutput(t.table_number)}</span><br><small>${tbFmt(t.total)}</small></button></div>`).join('');
    }
    $('#moveTargets').html(html);
    new bootstrap.Modal(document.getElementById('moveModal')).show();
}

function doJoin(fromId, toId, toNumber) {
    const from = TB_DATA.find(t => t.table_id === fromId);
    Swal.fire({ icon: 'question', title: TB.joinQ.replace('%1$s', from ? from.table_number : '').replace('%2$s', toNumber),
        text: TB.joinNote, showCancelButton: true, confirmButtonText: TB.join, cancelButtonText: TB.cancel, confirmButtonColor: '#0d6efd' })
    .then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/restaurant/join_table_orders.php') ?>', { _csrf: TB.csrf, warehouse_id: tbWid(), from_table_id: fromId, to_table_id: toId }, function (res) {
            bootstrap.Modal.getInstance(document.getElementById('moveModal'))?.hide();
            if (res.success) { loadBoard(); Swal.fire({ icon: 'success', title: res.message, timer: 1600, showConfirmButton: false }); }
            else Swal.fire({ icon: 'error', title: TB.error, text: res.message });
        }, 'json').fail(() => Swal.fire({ icon: 'error', title: TB.error, text: TB.serverError }));
    });
}

function doMove(fromId, toId) {
    $.post('<?= buildUrl('api/restaurant/move_table_order.php') ?>', { _csrf: TB.csrf, warehouse_id: tbWid(), from_table_id: fromId, to_table_id: toId }, function (res) {
        bootstrap.Modal.getInstance(document.getElementById('moveModal'))?.hide();
        if (res.success) { loadBoard(); Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false }); }
        else Swal.fire({ icon: 'error', title: TB.error, text: res.message });
    }, 'json').fail(() => Swal.fire({ icon: 'error', title: TB.error, text: TB.serverError }));
}
</script>

<?php
require_once 'footer.php';
ob_end_flush();
