<?php
/**
 * app/bms/restaurant/tables_board.php — live Tables screen (restaurant_plan.md Phase 2b; simpler, calmer screen 2026-11-02).
 *
 * Every table of a restaurant shop at a glance. Tap a table → its order opens on the till (pos?table=…);
 * "Move / Join" shifts an open order to a free table or joins it with another table's order. Refreshes every 20 s.
 *
 * Screen (2026-11-02): one line of counts instead of three boxes; a free table is a calm white card, a table with an
 * order is a blue card with its amount, items, time open (1 h 20 min, not 80 min) and who serves it; an order open
 * 3 h or more, or since yesterday, turns amber and asks "still here?"; today's next booking shows on the table;
 * All / Free / Busy filter only when a shop has more than 12 tables. No red.
 * Smart POS + Restaurant POS, Restaurant/Hybrid shops only. Table setup stays on restaurant/floors (the ⚙ button).
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_order_flow.php';

$page_title = 'Tables';
require_once 'header.php';

if (!canView('restaurant_pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
// Role based (core/restaurant_access.php): the same rule as this page's menu link.
require_once __DIR__ . '/../../../core/restaurant_access.php';
raDeny(raCanTables());
$module_on  = restaurantModuleOn($pdo);
$warehouses = $module_on ? restaurantWarehousesForSelect($pdo) : [];
// Opened from a notification: tables-board?table=N — that table's shop, the table highlighted.
$focusTable = (int)($_GET['table'] ?? 0);
$focusWh = 0;
if ($focusTable > 0 && $warehouses) {
    $ft = $pdo->prepare("SELECT warehouse_id FROM restaurant_tables WHERE table_id = ?");
    $ft->execute([$focusTable]);
    $fw = (int)$ft->fetchColumn();
    if (in_array($fw, array_map(fn($w) => (int)$w['warehouse_id'], $warehouses), true)) $focusWh = $fw; else $focusTable = 0;
}
$can_sell   = canCreate('pos');      // Take away (paid first) — the cashier
$can_take   = rofCanTake($pdo);      // open a table's order, Move / Join — the waiter too (restaurant_order_flow_plan.md)
$cur        = getSetting('currency', 'TZS');
?>

<div class="container-fluid mt-3 mt-md-4 tb-page">
    <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
        <h4 class="mb-0 text-truncate"><i class="ri ri-armchair text-primary me-2"></i><?= t('Tables') ?></h4>
        <div class="d-flex gap-2 flex-shrink-0">
            <?php if (canEdit('restaurant_pos')): ?>
            <a href="<?= getUrl('restaurant/floors') ?>" class="tb-hbtn tb-hbtn-icon" title="<?= htmlspecialchars(t('Manage tables')) ?>" aria-label="<?= htmlspecialchars(t('Manage tables')) ?>"><i class="bi bi-gear"></i></a>
            <?php endif; ?>
            <?php if ($can_sell && $warehouses): ?>
            <a href="<?= getUrl('pos') ?>?ot=take_away" class="tb-hbtn tb-hbtn-main"><i class="bi bi-bag me-1"></i><?= t('Take away') ?></a>
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

    <!-- One line: the counts, the filter (many tables only) and when it last updated -->
    <div class="tb-bar mb-3">
        <?php if (count($warehouses) > 1): ?>
        <div class="tb-shop">
            <select id="whSelect" class="form-select form-select-sm select2-static">
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= (int)$w['warehouse_id'] ?>"<?= (int)$w['warehouse_id'] === $focusWh ? ' selected' : '' ?>><?= caseFormat($w['warehouse_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" id="whSelect" value="<?= (int)$warehouses[0]['warehouse_id'] ?>">
        <?php endif; ?>
        <span class="tb-chip"><span class="tb-dot tb-dot-free"></span><b id="stFree">0</b> <?= t('free') ?></span>
        <span class="tb-chip"><span class="tb-dot tb-dot-busy"></span><b id="stBusy">0</b> <?= t('busy') ?></span>
        <span class="tb-chip"><?= t('Open bills') ?> <b id="stOpen">0</b> <?= htmlspecialchars($cur) ?></span>
        <span class="tb-chip tb-chip-late" id="stLateWrap" style="display:none;"><i class="bi bi-exclamation-triangle"></i><b id="stLate">0</b> <?= t('open long') ?></span>
        <div class="tb-filter" id="tbFilter" style="display:none;" role="group" aria-label="<?= htmlspecialchars(t('Tables')) ?>">
            <button type="button" class="active" data-f="all"><?= t('All') ?></button>
            <button type="button" data-f="free"><?= t('Free') ?></button>
            <button type="button" data-f="busy"><?= t('Busy') ?></button>
        </div>
        <a href="#" class="tb-upd" id="tbRefresh" title="<?= htmlspecialchars(t('Refresh')) ?>"><span class="d-none d-sm-inline"><?= t('Updated') ?> </span><span id="tbUpdAt">—</span> <i class="bi bi-arrow-clockwise"></i></a>
    </div>

    <div id="tbBoard"><div class="text-center py-5"><div class="spinner-border text-primary"></div></div></div>

    <?php endif; ?>
</div>

<!-- Move or join -->
<div class="modal fade" id="moveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><?= t('Move or join') ?> — <span id="moveFrom"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body tb-page">
                <div class="tb-mv-h"><?= t('Choose a free table:') ?></div>
                <div class="row g-2" id="moveTargets"></div>
            </div>
        </div>
    </div>
</div>

<style>
.tb-card.tb-focus{outline:3px solid #0d6efd;outline-offset:2px;}
/* sizes in px: the root font here is 12px, and style.css makes every .btn 16px / 85px wide — this page uses its own buttons */
.tb-page .tb-hbtn { display:inline-flex; align-items:center; justify-content:center; height:36px; padding:0 14px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; white-space:nowrap; }
.tb-page .tb-hbtn-main { background:#0d6efd; color:#fff; border:1px solid #0d6efd; }
.tb-page .tb-hbtn-main:hover { background:#0b5ed7; color:#fff; }
.tb-page .tb-hbtn-icon { width:36px; padding:0; background:#fff; color:#0d6efd; border:1px solid #b6ccfe; font-size:17px; }
.tb-page .tb-hbtn-icon:hover { background:#e7f0ff; }
/* the line under the title */
.tb-page .tb-bar { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.tb-page .tb-shop { width:240px; max-width:100%; }
.tb-page .tb-chip { display:inline-flex; align-items:center; gap:6px; height:30px; padding:0 12px; border-radius:20px; background:#f4f8ff; border:1px solid #dbe6fb; font-size:13px; color:#495057; white-space:nowrap; }
.tb-page .tb-chip b { color:#0b3d91; font-size:14px; }
.tb-page .tb-chip-late { background:#fff7e6; border-color:#f5d38f; color:#7a4b00; }
.tb-page .tb-chip-late b { color:#7a4b00; }
.tb-page .tb-dot { width:9px; height:9px; border-radius:50%; display:inline-block; }
.tb-page .tb-dot-free { background:#fff; border:2px solid #0d6efd; }
.tb-page .tb-dot-busy { background:#0d6efd; }
.tb-page .tb-filter { display:inline-flex; border:1px solid #b6ccfe; border-radius:20px; overflow:hidden; height:30px; }
.tb-page .tb-filter button { border:0; background:#fff; color:#0d6efd; font-size:13px; font-weight:600; padding:0 12px; }
.tb-page .tb-filter button + button { border-left:1px solid #dbe6fb; }
.tb-page .tb-filter button.active { background:#0d6efd; color:#fff; }
.tb-page .tb-upd { margin-left:auto; font-size:12px; color:#6c757d; text-decoration:none; white-space:nowrap; }
.tb-page .tb-upd:hover { color:#0d6efd; }
.tb-page .tb-upd.tb-spin i { display:inline-block; animation:tbspin .8s linear infinite; }
@keyframes tbspin { to { transform:rotate(360deg); } }
.tb-page .tb-area { font-size:12px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:#6c757d; margin:6px 2px 8px; }
/* the table cards */
.tb-page .tb-card { position:relative; display:flex; flex-direction:column; height:100%; min-height:132px; padding:12px 14px; border-radius:14px; cursor:pointer; user-select:none;
                    background:#fff; border:1.5px solid #cfe2ff; transition:transform .12s ease, box-shadow .12s ease, border-color .12s ease; }
.tb-page .tb-card:hover { border-color:#0d6efd; box-shadow:0 6px 16px rgba(13,110,253,.12); }
.tb-page .tb-card:active { transform:scale(.98); }
.tb-page .tb-card:focus-visible { outline:3px solid #9ec5fe; outline-offset:2px; }
.tb-page .tb-top { display:flex; justify-content:space-between; align-items:flex-start; gap:6px; }
.tb-page .tb-num { font-size:22px; font-weight:800; line-height:1.1; color:#0b3d91; overflow-wrap:anywhere; }
.tb-page .tb-pill { font-size:11px; font-weight:700; border-radius:20px; padding:3px 9px; white-space:nowrap; background:#e7f0ff; color:#0d6efd; }
.tb-page .tb-line { font-size:13px; color:#6c757d; margin-top:4px; }
.tb-page .tb-line i { margin-right:4px; }
.tb-page .tb-res { margin-top:auto; padding-top:8px; font-size:12px; font-weight:600; color:#0b3d91; }
.tb-page .tb-res span { display:inline-block; background:#e7f0ff; border-radius:8px; padding:3px 8px; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom; }
/* a table with an order: blue */
.tb-page .tb-busy { background:linear-gradient(140deg, #0d6efd, #0a58ca); border-color:#0a58ca; color:#fff; }
.tb-page .tb-busy:hover { border-color:#084298; box-shadow:0 6px 18px rgba(13,110,253,.35); }
.tb-page .tb-busy .tb-num { color:#fff; }
.tb-page .tb-busy .tb-pill { background:rgba(255,255,255,.22); color:#fff; }
.tb-page .tb-busy .tb-line { color:rgba(255,255,255,.88); }
.tb-page .tb-busy .tb-res span { background:rgba(255,255,255,.2); color:#fff; }
.tb-page .tb-amt { font-size:22px; font-weight:800; margin-top:6px; line-height:1.1; }
.tb-page .tb-amt small { font-size:12px; font-weight:600; opacity:.85; }
.tb-page .tb-grow { flex:1 1 auto; min-height:10px; }
.tb-page .tb-busy .tb-res { margin-top:8px; padding-top:0; }
.tb-page .tb-move { width:100%; height:32px; border-radius:9px; font-size:13px; font-weight:600; background:rgba(255,255,255,.14); color:#fff; border:1px solid rgba(255,255,255,.55); }
.tb-page .tb-move:hover { background:rgba(255,255,255,.26); }
/* open long (3 h+) or since yesterday: amber, asks if they are still here */
.tb-page .tb-late { background:#fff; border:2px solid #f0ad4e; color:#212529; }
.tb-page .tb-late:hover { border-color:#d48806; box-shadow:0 6px 16px rgba(240,173,78,.25); }
.tb-page .tb-late .tb-num { color:#0b3d91; }
.tb-page .tb-late .tb-pill { background:#fff1d6; color:#7a4b00; }
.tb-page .tb-late .tb-line { color:#6c757d; }
.tb-page .tb-late .tb-amt { color:#0b3d91; }
.tb-page .tb-late .tb-res span { background:#e7f0ff; color:#0b3d91; }
.tb-page .tb-late-note { margin-top:6px; font-size:12px; font-weight:600; color:#7a4b00; background:#fff7e6; border-radius:8px; padding:4px 8px; }
.tb-page .tb-late .tb-move { background:#fff; color:#0d6efd; border-color:#b6ccfe; }
.tb-page .tb-late .tb-move:hover { background:#e7f0ff; }
/* reserved / cleaning (set from Reservations or table setup) */
.tb-page .tb-other { background:#f8f9fa; border-color:#dee2e6; }
.tb-page .tb-other .tb-num { color:#495057; }
.tb-page .tb-other .tb-pill { background:#e9ecef; color:#495057; }
.tb-page .tb-empty { text-align:center; color:#6c757d; padding:48px 12px; }
.tb-page .tb-empty i { display:block; font-size:36px; color:#9ec5fe; margin-bottom:8px; }
/* Move / Join window */
.tb-page .tb-mv-h { font-size:13px; color:#6c757d; margin:2px 0 8px; }
.tb-page .tb-mv { width:100%; border-radius:10px; padding:12px 6px; font-size:16px; font-weight:700; background:#fff; color:#0d6efd; border:1.5px solid #b6ccfe; }
.tb-page .tb-mv:hover { background:#e7f0ff; border-color:#0d6efd; }
.tb-page .tb-mv small { display:block; font-size:12px; font-weight:600; color:#6c757d; }
.tb-page .tb-mv-join { color:#0b3d91; background:#f4f8ff; }
/* restaurant_order_flow_plan.md — the table's food state */
.tb-page .tb-kline { display:flex; flex-wrap:wrap; gap:4px; margin-top:6px; }
.tb-page .tb-k { font-size:11px; font-weight:700; border-radius:20px; padding:2px 8px; background:rgba(255,255,255,.22); color:#fff; white-space:nowrap; }
.tb-page .tb-k-ready { background:#fff; color:#0b3d91; }
.tb-page .tb-late .tb-k { background:#e7f0ff; color:#0b3d91; }
.tb-page .tb-late .tb-k-ready { background:#0d6efd; color:#fff; }
@media (max-width: 575.98px) {
    .tb-page .tb-card { min-height:118px; padding:10px 11px; }
    .tb-page .tb-num, .tb-page .tb-amt { font-size:19px; }
    .tb-page .tb-chip { height:28px; padding:0 10px; font-size:12px; }
    .tb-page .tb-shop { width:100%; }
}
</style>

<script>
const TB = {
    canSell: <?= json_encode($can_take) ?>,
    kPending: <?= json_encode(t('Waiting for cashier')) ?>,
    kCooking: <?= json_encode(t('Cooking')) ?>,
    kReady: <?= json_encode(t('Food ready')) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    posUrl: <?= json_encode(getUrl('pos')) ?>,
    cur: <?= json_encode($cur) ?>,
    free: <?= json_encode(t('Free')) ?>,
    busy: <?= json_encode(t('Busy')) ?>,
    reserved: <?= json_encode(t('Reserved')) ?>,
    cleaning: <?= json_encode(t('Cleaning')) ?>,
    seats: <?= json_encode(t('seats')) ?>,
    items: <?= json_encode(t('items')) ?>,
    min: <?= json_encode(t('min')) ?>, hr: <?= json_encode(t('h')) ?>, days: <?= json_encode(t('days')) ?>,
    moveJoin: <?= json_encode(t('Move / Join')) ?>,
    sinceYesterday: <?= json_encode(t('Since yesterday — still here?')) ?>,
    openLong: <?= json_encode(t('Open %s — still here?')) ?>,
    reservedAt: <?= json_encode(t('Reserved %s')) ?>,
    noTables: <?= json_encode(t('No tables yet.')) ?>,
    noneHere: <?= json_encode(t('No table here right now.')) ?>,
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
const TB_LATE_MIN = 180;      // an order open 3 hours or more asks "still here?"
const TB_FILTER_FROM = 13;    // the All / Free / Busy filter only when a shop has more than 12 tables
let TB_DATA = [];
let tbFilter = 'all';
const tbFmt = n => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
/** 35 min · 1 h 20 min · 2 days — not "963 min" */
function tbDur(m) {
    m = Math.max(0, parseInt(m, 10) || 0);
    if (m < 60) return m + ' ' + TB.min;
    if (m < 1440) { const h = Math.floor(m / 60), r = m % 60; return h + ' ' + TB.hr + (r ? ' ' + r + ' ' + TB.min : ''); }
    return Math.floor(m / 1440) + ' ' + TB.days;
}
const tbIsLate = t => !!t.hold_id && (t.opened_today === false || (t.minutes_open || 0) >= TB_LATE_MIN);

$(function () {
    if (!$('#whSelect').length) return;
    if ($('#whSelect').is('select')) $('#whSelect').select2({ theme: 'bootstrap-5', width: '100%' }).on('change', () => loadBoard());
    $('#tbFilter').on('click', 'button', function () { tbFilter = $(this).data('f'); $('#tbFilter button').removeClass('active'); $(this).addClass('active'); renderBoard(); });
    $('#tbRefresh').on('click', function (e) { e.preventDefault(); loadBoard(); });
    $('#tbBoard').on('keydown', '.tb-card', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); } });
    loadBoard();
    setInterval(function () { if (!document.hidden && !$('.modal.show').length) loadBoard(true); }, 20000);
});

const TB_FOCUS = <?= (int)$focusTable ?>; let tbFocused = false;   // ?table=N from a notification
function tbWid() { return parseInt($('#whSelect').val() || 0, 10); }

function loadBoard(quiet) {
    $('#tbRefresh').addClass('tb-spin');
    $.getJSON('<?= buildUrl('api/restaurant/get_tables_board.php') ?>', { warehouse_id: tbWid() }, function (res) {
        if (!res.success) { if (!quiet) Swal.fire({ icon: 'error', title: TB.error, text: res.message }); return; }
        TB_DATA = res.data;
        $('#tbUpdAt').text(new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }));
        renderBoard();
        if (TB_FOCUS && !tbFocused) {   // from a notification: show that table
            tbFocused = true;
            const el = document.querySelector('[data-table="' + TB_FOCUS + '"]');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (typeof bmsCleanQuery === 'function') bmsCleanQuery(['table']);
        }
    }).fail(function () { if (!quiet) Swal.fire({ icon: 'error', title: TB.error, text: TB.serverError }); })
      .always(function () { $('#tbRefresh').removeClass('tb-spin'); });
}

function openTable(t) {
    if (!TB.canSell) return;
    const q = new URLSearchParams({ table: t.table_id, wid: tbWid(), tlabel: t.table_number });
    window.location.href = TB.posUrl + (TB.posUrl.indexOf('?') > -1 ? '&' : '?') + q.toString();
}

function tbResLine(t) {
    const r = t.reservation; if (!r) return '';
    const who = [r.name, r.party ? '(' + r.party + ')' : ''].filter(Boolean).join(' ');
    return `<div class="tb-res"><span><i class="bi bi-calendar-check me-1"></i>${TB.reservedAt.replace('%s', safeOutput(r.time))}${who ? ' · ' + safeOutput(who) : ''}</span></div>`;
}

/** restaurant_order_flow_plan.md — the table's food: Food ready / Cooking / Waiting for cashier (most urgent first). */
function tbKitchen(t) {
    const k = t.kitchen; if (!k) return '';
    const bits = [];
    if (k.ready)   bits.push(`<span class="tb-k tb-k-ready"><i class="bi bi-bell-fill me-1"></i>${TB.kReady} · ${tbFmt(k.ready)}</span>`);
    if (k.cooking) bits.push(`<span class="tb-k"><i class="ri ri-chef-hat me-1"></i>${TB.kCooking} · ${tbFmt(k.cooking)}</span>`);
    if (k.pending) bits.push(`<span class="tb-k"><i class="bi bi-hourglass-split me-1"></i>${TB.kPending} · ${tbFmt(k.pending)}</span>`);
    return bits.length ? `<div class="tb-kline">${bits.join('')}</div>` : '';
}

function tbCard(t) {
    const isBusy = !!t.hold_id, late = tbIsLate(t);
    const open = `onclick='openTable(${JSON.stringify({ table_id: t.table_id, table_number: t.table_number })})'`;
    const attrs = `class="tb-card ${isBusy ? (late ? 'tb-busy tb-late' : 'tb-busy') : (t.status === 'available' ? 'tb-free' : 'tb-other')}${+t.table_id === TB_FOCUS ? ' tb-focus' : ''}" data-table="${+t.table_id}" role="button" tabindex="0" ${open}`;
    if (!isBusy) {
        const pill = t.status === 'available' ? TB.free : (t.status === 'reserved' ? TB.reserved : TB.cleaning);
        return `<div ${attrs}>
            <div class="tb-top"><div class="tb-num">${safeOutput(t.table_number)}</div><span class="tb-pill">${pill}</span></div>
            <div class="tb-line"><i class="bi bi-people"></i>${t.seats} ${TB.seats}</div>
            ${tbResLine(t)}
        </div>`;
    }
    const meta = [tbFmt(t.item_count) + ' ' + TB.items, tbDur(t.minutes_open)].join(' · ');
    const note = late ? `<div class="tb-late-note"><i class="bi bi-exclamation-triangle me-1"></i>${t.opened_today === false ? TB.sinceYesterday : TB.openLong.replace('%s', tbDur(t.minutes_open))}</div>` : '';
    return `<div ${attrs}>
        <div class="tb-top"><div class="tb-num">${safeOutput(t.table_number)}</div><span class="tb-pill">${TB.busy}</span></div>
        <div class="tb-amt">${tbFmt(t.total)} <small>${safeOutput(TB.cur)}</small></div>
        <div class="tb-line">${meta}</div>
        ${t.served_by ? `<div class="tb-line"><i class="bi bi-person"></i>${safeOutput(t.served_by)}</div>` : ''}
        ${tbKitchen(t)}
        ${note}
        ${tbResLine(t)}
        ${TB.canSell ? `<div class="tb-grow"></div><button type="button" class="tb-move" onclick='event.stopPropagation(); openMove(${t.table_id})'><i class="bi bi-arrow-left-right me-1"></i>${TB.moveJoin}</button>` : ''}
    </div>`;
}

function renderBoard() {
    const busy = TB_DATA.filter(t => t.hold_id), late = busy.filter(tbIsLate).length;
    $('#stFree').text(TB_DATA.filter(t => !t.hold_id && t.status === 'available').length);
    $('#stBusy').text(busy.length);
    $('#stOpen').text(tbFmt(busy.reduce((s, t) => s + t.total, 0)));
    $('#stLate').text(late); $('#stLateWrap').toggle(late > 0);
    $('#tbFilter').toggle(TB_DATA.length >= TB_FILTER_FROM);
    if (TB_DATA.length < TB_FILTER_FROM) tbFilter = 'all';
    if (!TB_DATA.length) {
        $('#tbBoard').html(`<div class="tb-empty"><i class="ri ri-armchair"></i>${TB.noTables}<div class="mt-3"><a class="tb-hbtn tb-hbtn-main" href="${TB.floorsUrl}">${TB.addTables}</a></div></div>`);
        return;
    }
    const shown = TB_DATA.filter(t => tbFilter === 'all' || (tbFilter === 'busy' ? !!t.hold_id : !t.hold_id));
    if (!shown.length) { $('#tbBoard').html(`<div class="tb-empty"><i class="ri ri-armchair"></i>${TB.noneHere}</div>`); return; }
    const areas = {};
    shown.forEach(t => { (areas[t.area || ''] = areas[t.area || ''] || []).push(t); });
    const multi = Object.keys(areas).length > 1;
    let html = '';
    Object.keys(areas).forEach(area => {
        if (multi) html += `<div class="tb-area">${safeOutput(area)}</div>`;
        html += '<div class="row g-2 g-md-3 mb-3">';
        areas[area].forEach(t => { html += `<div class="col-6 col-md-4 col-lg-3 col-xxl-2">${tbCard(t)}</div>`; });
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
        `<div class="col-4"><button type="button" class="tb-mv" onclick="doMove(${fromId}, ${t.table_id})">${safeOutput(t.table_number)}<small>${t.seats} ${TB.seats}</small></button></div>`).join('')
        : `<div class="col-12 text-muted text-center py-3">${TB.noFree}</div>`;
    // restaurant_plan.md Phase 4 — join this order into another table that already has one.
    const busy = TB_DATA.filter(t => t.hold_id && t.table_id !== fromId);
    if (busy.length) {
        html += `<div class="col-12 tb-mv-h mt-3 mb-0">${TB.joinWith}</div>` + busy.map(t =>
            `<div class="col-6"><button type="button" class="tb-mv tb-mv-join" onclick="doJoin(${fromId}, ${t.table_id}, ${JSON.stringify(t.table_number).replace(/"/g, '&quot;')})">${safeOutput(t.table_number)}<small>${tbFmt(t.total)} ${safeOutput(TB.cur)}</small></button></div>`).join('');
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
