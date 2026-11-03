<?php
/**
 * app/bms/restaurant/online_orders.php — orders customers sent from the online
 * menu (restaurant_online_menu_plan.md Phase 5). Staff accept or reject each one;
 * accepted table orders join that table's order, pickup orders go to the till's
 * held orders. Polls every 10 s and chimes when a new order arrives.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/restaurant_online.php';

$page_title = 'Online orders';
require_once 'header.php';

if (!canView('restaurant_pos') || !canCreate('pos')) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$ready      = restaurantModuleOn($pdo) && roSchemaReady($pdo);
$warehouses = $ready ? restaurantWarehousesForSelect($pdo) : [];
// Opened from a notification (online_orders?order=N): start on that order's shop (only a shop in the user's list).
$focusOrder = (int)($_GET['order'] ?? 0);
$focusWh = 0;
if ($focusOrder > 0 && $warehouses) {
    $fs = $pdo->prepare("SELECT warehouse_id FROM restaurant_online_orders WHERE order_id = ?");
    $fs->execute([$focusOrder]);
    $fw = (int)$fs->fetchColumn();
    if (in_array($fw, array_map(fn($w) => (int)$w['warehouse_id'], $warehouses), true)) $focusWh = $fw;
}
$canShare   = canEdit('restaurant_pos');
?>
<style>
    .oo-tabs .nav-link { font-weight: 600; }
    .oo-tabs .badge { font-size: .72rem; }
    .oo-card { border: 1px solid #e5e7eb; border-radius: 14px; }
    .oo-card.oo-new { border-color: #b6ccfe; box-shadow: 0 0 0 3px #e7f0ff; }
    .oo-card.oo-focus { border-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
    .oo-code { font-size: 1.25rem; font-weight: 800; letter-spacing: .01em; }
    .oo-where { font-size: .8rem; font-weight: 600; border-radius: 999px; padding: 3px 10px; background: #e7f0ff; color: #084298; }
    .oo-line { display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px dashed #eef0f3; }
    .oo-line:last-child { border-bottom: 0; }
    .oo-line .q { font-weight: 700; min-width: 30px; }
    .oo-line small { display: block; color: #6c757d; }
    .oo-badge { font-size: .72rem; border-radius: 999px; padding: 3px 9px; font-weight: 600; }
    .oo-pending   { background: #e9ecef; color: #495057; }
    .oo-accepted  { background: #0d6efd; color: #fff; }
    .oo-ready     { background: #052c65; color: #fff; }
    .oo-completed { background: #cfe2ff; color: #084298; }
    .oo-rejected  { background: #dc3545; color: #fff; }
    .oo-cancelled { background: #6c757d; color: #fff; }
    .oo-actions { display: flex; gap: 6px; flex-wrap: nowrap; }
    .oo-actions .btn { flex: 1; }
    @media (max-width: 767px) { .oo-head { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 8px 0; } }
</style>

<div class="container-fluid mt-3 mt-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 oo-head">
        <h4 class="mb-0 text-truncate"><i class="bi bi-phone text-primary me-2"></i><?= t('Online orders') ?></h4>
        <div class="d-flex gap-2 flex-shrink-0">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="ooSound" title="<?= htmlspecialchars(t('Sound on new orders')) ?>"><i class="bi bi-volume-up"></i></button>
            <?php if ($canShare): ?><a href="<?= getUrl('restaurant/menu') ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-qr-code"></i><span class="d-none d-md-inline ms-1"><?= t('Share menu') ?></span></a><?php endif; ?>
        </div>
    </div>

    <?php if (!$ready): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i><?= t('The Menu is available for restaurant shops in Smart POS.') ?></div>
    <?php elseif (empty($warehouses)): ?>
    <div class="alert alert-warning"><i class="bi bi-info-circle me-1"></i><?= wLabel("No warehouse in your scope is set to Restaurant or Hybrid mode yet. Switch a warehouse's POS Mode first.", "No shop in your scope is set to Restaurant or Hybrid mode yet. Switch a shop's POS Mode first.") ?></div>
    <?php else: ?>

    <?php if (count($warehouses) > 1): ?>
    <div class="mb-3" style="max-width:320px;">
        <label class="form-label small mb-1"><?= wLabel('Warehouse', 'Shop') ?></label>
        <select id="whSelect" class="form-select form-select-sm select2-static">
            <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['warehouse_id'] ?>"<?= (int)$w['warehouse_id'] === $focusWh ? ' selected' : '' ?>><?= caseFormat($w['warehouse_name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
    <input type="hidden" id="whSelect" value="<?= (int)$warehouses[0]['warehouse_id'] ?>">
    <?php endif; ?>

    <ul class="nav nav-pills oo-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-tab="new" type="button"><?= t('New') ?> <span class="badge bg-primary ms-1" id="cNew">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-tab="progress" type="button"><?= t('In progress') ?> <span class="badge bg-secondary ms-1" id="cProg">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-tab="done" type="button"><?= t('Done today') ?> <span class="badge bg-secondary ms-1" id="cDone">0</span></button></li>
    </ul>
    <div id="ooList" class="row g-3"></div>
    <?php endif; ?>
</div>

<?php if ($ready && $warehouses): ?>
<script>
(function () {
    const OO = <?= json_encode([
        'api'        => buildUrl('api/restaurant/online_orders.php'),
        'kitchenApi' => buildUrl('api/restaurant/send_to_kitchen.php'),
        'csrf'       => csrf_token(),
        'table'      => t('Table %s'),
        'pickup'     => t('Pickup'),
        'minAgo'     => t('%d min ago'),
        'justNow'    => t('just now'),
        'accept'     => t('Accept'),
        'reject'     => t('Reject'),
        'ready'      => t('Ready'),
        'done'       => t('Done'),
        'total'      => t('Total'),
        'noNew'      => t('No new orders. New orders appear here by themselves.'),
        'noProg'     => t('Nothing in progress.'),
        'noDone'     => t('No finished orders today.'),
        'rejectQ'    => t('Reject this order?'),
        'reasonPh'   => t('Reason the customer will see (e.g. sold out)'),
        'rejectYes'  => t('Reject order'),
        'cancel'     => t('Cancel'),
        'error'      => t('Something went wrong. Please try again.'),
        'kitchenOk'  => t('Sent to the kitchen.'),
        'kitchenErr' => t('Accepted, but sending to the kitchen failed: %s'),
        'by'         => t('by %s'),
        'status'     => ['pending' => t('New'), 'accepted' => t('Accepted'), 'ready' => t('Ready'), 'completed' => t('Done'), 'rejected' => t('Rejected'), 'cancelled' => t('Cancelled')],
        'cur'        => bmsCurrencySymbol(),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    let tab = 'new', rows = [], seen = null, timer = null, sound = true;
    const focus = <?= (int)$focusOrder ?>; let focused = false;   // ?order=N from a notification
    try { sound = localStorage.getItem('bms_oo_sound') !== '0'; } catch (e) {}
    const esc = s => safeOutput(String(s ?? ''));
    const money = v => OO.cur + Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const whId = () => parseInt($('#whSelect').val() || 0, 10);

    function paintSound() { $('#ooSound i').attr('class', sound ? 'bi bi-volume-up' : 'bi bi-volume-mute'); }
    function chime() {
        if (!sound) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.18].forEach((d, i) => { const o = ctx.createOscillator(), g = ctx.createGain(); o.frequency.value = i ? 1046 : 784; o.connect(g); g.connect(ctx.destination);
                g.gain.setValueAtTime(0.0001, ctx.currentTime + d); g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + d + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + d + 0.3);
                o.start(ctx.currentTime + d); o.stop(ctx.currentTime + d + 0.32); });
        } catch (e) {}
    }

    function card(o) {
        const where = o.table !== null ? OO.table.replace('%s', esc(o.table)) : OO.pickup;
        const age = o.minutes < 1 ? OO.justNow : OO.minAgo.replace('%d', o.minutes);
        let lines = o.lines.map(l => `<div class="oo-line"><span class="q">${l.qty}×</span><div class="flex-grow-1">${esc(l.name)}
            ${l.options.length ? `<small>${esc(l.options.join(', '))}</small>` : ''}${l.note ? `<small><i class="bi bi-chat-left-text me-1"></i>${esc(l.note)}</small>` : ''}</div>
            <span class="text-nowrap">${money(l.price * l.qty)}</span></div>`).join('');
        let who = '';
        if (o.type === 'pickup') who = `<div class="small mb-2"><i class="bi bi-person me-1 text-primary"></i>${esc(o.name)} · <a href="tel:${esc(o.phone)}">${esc(o.phone)}</a></div>`;
        let btns = '';
        if (o.status === 'pending')  btns = `<button class="btn btn-sm btn-outline-danger" onclick="ooReject(${o.order_id})"><i class="bi bi-x-circle me-1"></i>${OO.reject}</button>
                                            <button class="btn btn-sm btn-primary" onclick="ooAct(${o.order_id},'accept',this)"><i class="bi bi-check-circle me-1"></i>${OO.accept}</button>`;
        if (o.status === 'accepted') btns = `<button class="btn btn-sm btn-outline-primary" onclick="ooAct(${o.order_id},'complete',this)">${OO.done}</button>
                                            <button class="btn btn-sm btn-primary" onclick="ooAct(${o.order_id},'ready',this)"><i class="bi bi-bell me-1"></i>${OO.ready}</button>`;
        if (o.status === 'ready')    btns = `<button class="btn btn-sm btn-primary" onclick="ooAct(${o.order_id},'complete',this)"><i class="bi bi-check2-all me-1"></i>${OO.done}</button>`;
        return `<div class="col-12 col-md-6 col-xl-4"><div class="card oo-card h-100${o.status === 'pending' ? ' oo-new' : ''}${o.order_id === focus ? ' oo-focus' : ''}" data-oid="${o.order_id}"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div><div class="oo-code">${esc(o.code)}</div><small class="text-muted">${age}${o.handled_by ? ' · ' + OO.by.replace('%s', esc(o.handled_by)) : ''}</small></div>
                <div class="text-end"><span class="oo-where">${where}</span><div class="mt-1"><span class="oo-badge oo-${esc(o.status)}">${esc(OO.status[o.status] || o.status)}</span></div></div>
            </div>
            ${who}${lines}
            ${o.note ? `<div class="small bg-light rounded p-2 mt-2"><i class="bi bi-chat-left-text me-1 text-primary"></i>${esc(o.note)}</div>` : ''}
            ${o.reason ? `<div class="small text-danger mt-2">${esc(o.reason)}</div>` : ''}
            <div class="d-flex justify-content-between fw-bold mt-2"><span>${OO.total}</span><span>${money(o.subtotal)}</span></div>
            </div>${btns ? `<div class="card-footer bg-white border-top p-2"><div class="oo-actions">${btns}</div></div>` : ''}</div></div>`;
    }
    function render() {
        const groups = { new: rows.filter(o => o.status === 'pending'), progress: rows.filter(o => o.status === 'accepted' || o.status === 'ready'),
                         done: rows.filter(o => ['completed', 'rejected', 'cancelled'].includes(o.status)) };
        $('#cNew').text(groups.new.length); $('#cProg').text(groups.progress.length); $('#cDone').text(groups.done.length);
        const list = groups[tab];
        $('#ooList').html(list.length ? list.map(card).join('') : `<div class="col-12 text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2"></i>${tab === 'new' ? OO.noNew : tab === 'progress' ? OO.noProg : OO.noDone}</div>`);
    }
    function load() {
        if (!whId()) return;
        $.getJSON(OO.api, { action: 'list', warehouse_id: whId() }).done(r => {
            if (!r.success) { $('#ooList').html(`<div class="col-12"><div class="alert alert-warning">${esc(r.message)}</div></div>`); return; }
            rows = r.data;
            if (focus && !focused) {   // opened from a notification: the tab that holds this order, then scroll to it
                const f = rows.find(o => o.order_id === focus);
                if (f) tab = f.status === 'pending' ? 'new' : (f.status === 'accepted' || f.status === 'ready' ? 'progress' : 'done');
                $('.oo-tabs .nav-link').removeClass('active').filter('[data-tab="' + tab + '"]').addClass('active');
            }
            const pend = new Set(rows.filter(o => o.status === 'pending').map(o => o.order_id));
            if (seen && [...pend].some(id => !seen.has(id))) chime();
            seen = pend;
            render();
            if (focus && !focused) {
                focused = true;
                const el = document.querySelector('[data-oid="' + focus + '"]');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (typeof bmsCleanQuery === 'function') bmsCleanQuery(['order']);
            }
        });
    }
    function post(data) { return $.post(OO.api, Object.assign({ _csrf: OO.csrf }, data), null, 'json'); }

    window.ooAct = function (id, action, btn) {
        const b = $(btn); b.prop('disabled', true);
        post({ action: action, order_id: id }).done(r => {
            if (!r.success) { Swal.fire({ icon: r.refresh ? 'info' : 'error', title: r.message }); load(); return; }
            load();
            if (action === 'accept' && r.kitchen && r.hold_id) {
                $.post(OO.kitchenApi, { hold_id: r.hold_id, warehouse_id: r.warehouse_id, _csrf: OO.csrf }, null, 'json')
                    .done(k => k.success ? Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: r.message + ' ' + OO.kitchenOk, timer: 2500, showConfirmButton: false })
                                         : Swal.fire({ icon: 'warning', title: OO.kitchenErr.replace('%s', k.message || '') }))
                    .fail(() => Swal.fire({ icon: 'warning', title: OO.kitchenErr.replace('%s', OO.error) }));
            } else {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: r.message, timer: 2200, showConfirmButton: false });
            }
        }).fail(() => Swal.fire({ icon: 'error', title: OO.error })).always(() => b.prop('disabled', false));
    };
    window.ooReject = function (id) {
        Swal.fire({ icon: 'warning', title: OO.rejectQ, input: 'text', inputPlaceholder: OO.reasonPh, inputAttributes: { maxlength: 200 },
                    showCancelButton: true, confirmButtonText: OO.rejectYes, cancelButtonText: OO.cancel, confirmButtonColor: '#dc3545' })
            .then(res => { if (!res.isConfirmed) return;
                post({ action: 'reject', order_id: id, reason: res.value || '' }).done(r => { load(); Swal.fire({ icon: r.success ? 'success' : 'error', title: r.message, timer: 2000, showConfirmButton: !r.success }); })
                    .fail(() => Swal.fire({ icon: 'error', title: OO.error })); });
    };

    $('.oo-tabs .nav-link').on('click', function () { $('.oo-tabs .nav-link').removeClass('active'); $(this).addClass('active'); tab = $(this).data('tab'); render(); });
    $('#whSelect').on('change', () => { seen = null; load(); });
    $('#ooSound').on('click', () => { sound = !sound; try { localStorage.setItem('bms_oo_sound', sound ? '1' : '0'); } catch (e) {} paintSound(); if (sound) chime(); });
    paintSound();
    load();
    timer = setInterval(() => { if (!document.hidden) load(); }, 10000);
})();
</script>
<?php endif; ?>
<?php
require_once 'footer.php';
ob_end_flush();
