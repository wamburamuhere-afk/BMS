<?php
/**
 * app/bms/stock/shop_online_orders.php — orders buyers sent from a shop's online shop
 * (retail_online_shop_plan.md Phase 5). Staff accept (stock re-checked, customer linked, a held
 * order made for the till) or reject each one; "Ready" tells the buyer; an order closes itself
 * when its sale is completed at the till; "Not collected" cancels an unpaid one. Polls every
 * 10 s and chimes when a new order arrives.
 */
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/retail_online.php';

$page_title = 'Online orders';
require_once 'header.php';

if (!canCreate('pos') || !posSimpleModeEnabled()) {
    header('Location: ' . getUrl('unauthorized'));
    exit();
}
$ready = rsSchemaReady($pdo);
$shops = [];
if ($ready) {
    foreach ($pdo->query("SELECT warehouse_id, warehouse_name, online_shop_enabled FROM warehouses
                           WHERE status = 'active' AND COALESCE(pos_mode, 'retail') IN ('retail','hybrid')
                           ORDER BY online_shop_enabled DESC, warehouse_name")->fetchAll(PDO::FETCH_ASSOC) as $w) {
        if (userCan('warehouse', (int)$w['warehouse_id'])) $shops[] = $w;
    }
}
$onlineShops = array_values(array_filter($shops, fn($w) => (int)$w['online_shop_enabled'] === 1));
$listShops   = $onlineShops ?: [];
// Opened from a notification (shop_online_orders?order=N): start on that order's shop (only a shop in the user's list).
$focusOrder = (int)($_GET['order'] ?? 0);
$focusWh = 0;
if ($focusOrder > 0 && $shops) {
    $fs = $pdo->prepare("SELECT warehouse_id FROM restaurant_online_orders WHERE order_id = ? AND order_type IN ('shop_pickup','shop_delivery')");
    $fs->execute([$focusOrder]);
    $fw = (int)$fs->fetchColumn();
    foreach ($shops as $w) {
        if ((int)$w['warehouse_id'] === $fw) {
            $focusWh = $fw;
            if (!in_array($fw, array_map(fn($x) => (int)$x['warehouse_id'], $listShops), true)) $listShops[] = $w;   // switched off since — still show its orders
        }
    }
}
$canShare = canEdit('warehouses') && $ready && $shops;
?>
<style>
    .oo-tabs .nav-link { font-weight: 600; }
    .oo-tabs .badge { font-size: .72rem; }
    .oo-card { border: 1px solid #e5e7eb; border-radius: 14px; }
    .oo-card.oo-new { border-color: #b6ccfe; box-shadow: 0 0 0 3px #e7f0ff; }
    .oo-card.oo-focus { border-color: #0d6efd; box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
    .oo-code { font-size: 1.25rem; font-weight: 800; letter-spacing: .01em; }
    .oo-where { font-size: .8rem; font-weight: 600; border-radius: 999px; padding: 3px 10px; background: #e7f0ff; color: #084298; white-space: nowrap; }
    .oo-where.del { background: #fff3cd; color: #664d03; }
    .oo-line { display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px dashed #eef0f3; }
    .oo-line:last-child { border-bottom: 0; }
    .oo-line .q { font-weight: 700; min-width: 42px; }
    .oo-badge { font-size: .72rem; border-radius: 999px; padding: 3px 9px; font-weight: 600; }
    .oo-pending   { background: #e9ecef; color: #495057; }
    .oo-accepted  { background: #0d6efd; color: #fff; }
    .oo-ready     { background: #052c65; color: #fff; }
    .oo-completed { background: #cfe2ff; color: #084298; }
    .oo-rejected  { background: #dc3545; color: #fff; }
    .oo-cancelled { background: #6c757d; color: #fff; }
    .oo-ws { font-size: .7rem; font-weight: 700; border-radius: 999px; padding: 2px 8px; background: #fff3cd; color: #664d03; }
    .oo-actions { display: flex; gap: 6px; flex-wrap: nowrap; }
    .oo-actions .btn { flex: 1; }
    @media (max-width: 767px) { .oo-head { position: sticky; top: 0; z-index: 1020; background: #fff; padding: 8px 0; } }
</style>

<div class="container-fluid mt-3 mt-md-4">
    <div class="d-flex justify-content-between align-items-center mb-3 gap-2 oo-head">
        <h4 class="mb-0 text-truncate"><i class="bi bi-bag-check text-primary me-2"></i><?= t('Online orders') ?></h4>
        <div class="d-flex gap-2 flex-shrink-0">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="ooSound" title="<?= htmlspecialchars(t('Sound on new orders')) ?>" aria-label="<?= htmlspecialchars(t('Sound on new orders')) ?>"><i class="bi bi-volume-up"></i></button>
            <?php if ($canShare): ?><button type="button" class="btn btn-outline-primary btn-sm" onclick="openShareShop(parseInt(document.getElementById('whSelect') ? document.getElementById('whSelect').value : 0, 10))"><i class="bi bi-share"></i><span class="d-none d-md-inline ms-1"><?= t('Share shop') ?></span></button><?php endif; ?>
        </div>
    </div>

    <?php if (!$ready): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i><?= t('The online shop is being set up for your account — please check back shortly.') ?></div>
    <?php elseif (!$listShops): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
        <span><i class="bi bi-info-circle me-1"></i><?= t('No shop is online yet. Share your shop so customers can see your products and order.') ?></span>
        <?php if ($canShare): ?><button type="button" class="btn btn-primary btn-sm ms-md-auto" onclick="openShareShop()"><i class="bi bi-share me-1"></i><?= t('Share shop') ?></button><?php endif; ?>
    </div>
    <?php else: ?>

    <?php if (count($listShops) > 1): ?>
    <div class="mb-3" style="max-width:320px;">
        <label class="form-label small mb-1" for="whSelect"><?= wLabel('Warehouse', 'Shop') ?></label>
        <select id="whSelect" class="form-select form-select-sm">
            <?php foreach ($listShops as $w): ?><option value="<?= (int)$w['warehouse_id'] ?>"<?= (int)$w['warehouse_id'] === $focusWh ? ' selected' : '' ?>><?= htmlspecialchars(caseFormat($w['warehouse_name'])) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php else: ?>
    <input type="hidden" id="whSelect" value="<?= (int)$listShops[0]['warehouse_id'] ?>">
    <?php endif; ?>

    <ul class="nav nav-pills oo-tabs mb-3" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-tab="new" type="button"><?= t('New') ?> <span class="badge bg-primary ms-1" id="cNew">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-tab="progress" type="button"><?= t('In progress') ?> <span class="badge bg-secondary ms-1" id="cProg">0</span></button></li>
        <li class="nav-item"><button class="nav-link" data-tab="done" type="button"><?= t('Done today') ?> <span class="badge bg-secondary ms-1" id="cDone">0</span></button></li>
    </ul>
    <div id="ooList" class="row g-3"></div>
    <?php endif; ?>
</div>

<?php if ($ready && $listShops): ?>
<script>
(function () {
    const OO = <?= json_encode([
        'api'          => buildUrl('api/stock/shop_online_orders.php'),
        'csrf'         => csrf_token(),
        'pickup'       => t('Pickup'),
        'delivery'     => t('Delivery'),
        'wholesale'    => t('Wholesale prices'),
        'minAgo'       => t('%d min ago'),
        'justNow'      => t('just now'),
        'accept'       => t('Accept'),
        'reject'       => t('Reject'),
        'readyPick'    => t('Ready'),
        'readyDel'     => t('On the way'),
        'total'        => t('Total'),
        'noNew'        => t('No new orders. New orders appear here by themselves.'),
        'noProg'       => t('Nothing in progress.'),
        'noDone'       => t('No finished orders today.'),
        'rejectQ'      => t('Reject this order?'),
        'reasonPh'     => t('Reason the customer will see (e.g. sold out)'),
        'rejectYes'    => t('Reject order'),
        'cancel'       => t('Cancel'),
        'error'        => t('Something went wrong. Please try again.'),
        'by'           => t('by %s'),
        'status'       => ['pending' => t('New'), 'accepted' => t('Accepted'), 'ready' => t('Ready'), 'completed' => t('Paid'), 'rejected' => t('Rejected'), 'cancelled' => t('Cancelled')],
        'notCollected' => t('Not collected'),
        'ncQ'          => t('Cancel this order as not collected?'),
        'ncText'       => t('It is removed from Held orders on the till. The customer sees the reason.'),
        'ncPh'         => t('Reason (e.g. customer did not come)'),
        'ncYes'        => t('Yes, cancel order'),
        'paidOn'       => t('Paid · receipt %s'),
        'closesAuto'   => t('Open it in Held orders on the till and take the payment. It closes by itself when paid.'),
        'customer'     => t('Customer: %s'),
        'byCustomer'   => t('Cancelled by the customer'),
        'bySystem'     => t('Expired — not confirmed in time'),
        'hintTitle'    => t('This phone number is already used'),
        'hintText'     => t('The order is from %1$s (%2$s). Link it to an existing customer, or create a new one?'),
        'hintNew'      => t('New customer: %s'),
        'hintGo'       => t('Accept order'),
        'cur'          => bmsCurrencySymbol(),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    let tab = 'new', rows = [], seen = null, sound = true;
    const focus = <?= (int)$focusOrder ?>; let focused = false;
    try { sound = localStorage.getItem('bms_soo_sound') !== '0'; } catch (e) {}
    const esc = s => safeOutput(String(s ?? ''));
    const money = v => OO.cur + Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
    const qty = q => String(Math.round(Number(q) * 1000) / 1000);
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
        const del = o.type === 'delivery';
        const age = o.minutes < 1 ? OO.justNow : OO.minAgo.replace('%d', o.minutes);
        const lines = o.lines.map(l => `<div class="oo-line"><span class="q">${esc(qty(l.qty))}${l.unit && Number(l.qty) % 1 ? ' ' + esc(l.unit) : '×'}</span>
            <div class="flex-grow-1">${esc(l.name)}</div><span class="text-nowrap">${money(l.price * l.qty)}</span></div>`).join('');
        let who = `<div class="small mb-1"><i class="bi bi-person me-1 text-primary"></i>${esc(o.name)} · <a href="tel:${esc(o.phone)}">${esc(o.phone)}</a></div>`;
        if (del && o.address) who += `<div class="small mb-2"><i class="bi bi-geo-alt me-1 text-warning"></i>${esc(o.address)}</div>`;
        let rec = '';
        if (o.customer) rec += `<div class="small text-muted"><i class="bi bi-person-check me-1"></i>${OO.customer.replace('%s', esc(o.customer))}</div>`;
        if (o.receipt)  rec += `<div class="small text-success fw-semibold"><i class="bi bi-receipt me-1"></i>${OO.paidOn.replace('%s', esc(o.receipt))}</div>`;
        if (o.status === 'cancelled' && o.cancelled_by === 'customer') rec += `<div class="small text-muted">${OO.byCustomer}</div>`;
        if (o.status === 'cancelled' && o.cancelled_by === 'system')   rec += `<div class="small text-muted">${OO.bySystem}</div>`;
        if (o.status === 'accepted' || o.status === 'ready') rec += `<div class="small text-muted mt-1"><i class="bi bi-info-circle me-1"></i>${OO.closesAuto}</div>`;
        let btns = '';
        if (o.status === 'pending')  btns = `<button class="btn btn-sm btn-outline-danger" onclick="sooReject(${o.order_id})"><i class="bi bi-x-circle me-1"></i>${OO.reject}</button>
                                            <button class="btn btn-sm btn-primary" onclick="sooAccept(${o.order_id},this)"><i class="bi bi-check-circle me-1"></i>${OO.accept}</button>`;
        if (o.status === 'accepted') btns = `<button class="btn btn-sm btn-outline-danger" onclick="sooNotCollected(${o.order_id})"><i class="bi bi-x-octagon me-1"></i>${OO.notCollected}</button>
                                            <button class="btn btn-sm btn-primary" onclick="sooAct(${o.order_id},'ready',this)"><i class="bi ${del ? 'bi-truck' : 'bi-bell'} me-1"></i>${del ? OO.readyDel : OO.readyPick}</button>`;
        if (o.status === 'ready')    btns = `<button class="btn btn-sm btn-outline-danger" onclick="sooNotCollected(${o.order_id})"><i class="bi bi-x-octagon me-1"></i>${OO.notCollected}</button>`;
        const statusLabel = o.status === 'ready' && del ? OO.readyDel : (OO.status[o.status] || o.status);
        return `<div class="col-12 col-md-6 col-xl-4"><div class="card oo-card h-100${o.status === 'pending' ? ' oo-new' : ''}${o.order_id === focus ? ' oo-focus' : ''}" data-oid="${o.order_id}"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div><div class="oo-code">${esc(o.code)}</div><small class="text-muted">${age}${o.handled_by ? ' · ' + OO.by.replace('%s', esc(o.handled_by)) : ''}</small></div>
                <div class="text-end"><span class="oo-where${del ? ' del' : ''}"><i class="bi ${del ? 'bi-truck' : 'bi-bag'} me-1"></i>${del ? OO.delivery : OO.pickup}</span>
                    <div class="mt-1"><span class="oo-badge oo-${esc(o.status)}">${esc(statusLabel)}</span></div></div>
            </div>
            ${o.price_type === 'wholesale' ? `<div class="mb-2"><span class="oo-ws"><i class="bi bi-tags me-1"></i>${OO.wholesale}</span></div>` : ''}
            ${who}${lines}
            ${o.note ? `<div class="small bg-light rounded p-2 mt-2"><i class="bi bi-chat-left-text me-1 text-primary"></i>${esc(o.note)}</div>` : ''}
            ${o.reason && o.cancelled_by !== 'customer' ? `<div class="small text-danger mt-2">${esc(o.reason)}</div>` : ''}
            <div class="d-flex justify-content-between fw-bold mt-2"><span>${OO.total}</span><span>${money(o.subtotal)}</span></div>
            ${rec ? `<div class="mt-2">${rec}</div>` : ''}
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
            if (focus && !focused) {
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
    const toast = (r) => Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: r.message, timer: 2500, showConfirmButton: false });

    window.sooAct = function (id, action, btn, linkCustomer) {
        const b = $(btn); b.prop('disabled', true);
        post({ action: action, order_id: id, link_customer_id: linkCustomer || 0 })
            .done(r => { load(); if (r.success) toast(r); else Swal.fire({ icon: r.refresh ? 'info' : 'error', title: r.message }); })
            .fail(x => { load(); Swal.fire({ icon: 'error', title: (x.responseJSON && x.responseJSON.message) || OO.error }); })
            .always(() => b.prop('disabled', false));
    };
    // Accept: when the buyer's phone already belongs to a customer under another name, staff choose
    // to link to them or create a new customer (default — the owner's rule).
    window.sooAccept = function (id, btn) {
        const o = rows.find(r => r.order_id === id) || {};
        $.getJSON(OO.api, { action: 'customer_hint', order_id: id }).done(h => {
            const list = (h && h.success && h.data) || [];
            if (!list.length) { sooAct(id, 'accept', btn); return; }
            // SweetAlert puts option labels in as HTML — names come from the public, so escape them.
            const opts = { 0: esc(OO.hintNew.replace('%s', o.name || '')) };
            list.forEach(c => { opts[c.customer_id] = esc(c.customer_name + (c.customer_code ? ' (' + c.customer_code + ')' : '')); });
            Swal.fire({ icon: 'question', title: OO.hintTitle, text: OO.hintText.replace('%1$s', o.name || '').replace('%2$s', o.phone || ''),
                        input: 'radio', inputOptions: opts, inputValue: '0', showCancelButton: true, confirmButtonText: OO.hintGo, cancelButtonText: OO.cancel })
                .then(res => { if (res.isConfirmed) sooAct(id, 'accept', btn, parseInt(res.value || '0', 10)); });
        }).fail(() => sooAct(id, 'accept', btn));
    };
    window.sooNotCollected = function (id) {
        Swal.fire({ icon: 'warning', title: OO.ncQ, text: OO.ncText, input: 'text', inputPlaceholder: OO.ncPh, inputAttributes: { maxlength: 200 },
                    showCancelButton: true, confirmButtonText: OO.ncYes, cancelButtonText: OO.cancel, confirmButtonColor: '#dc3545' })
            .then(res => { if (!res.isConfirmed) return;
                post({ action: 'cancel', order_id: id, reason: res.value || '' }).done(r => { load(); Swal.fire({ icon: r.success ? 'success' : 'error', title: r.message, timer: 2200, showConfirmButton: !r.success }); })
                    .fail(() => Swal.fire({ icon: 'error', title: OO.error })); });
    };
    window.sooReject = function (id) {
        Swal.fire({ icon: 'warning', title: OO.rejectQ, input: 'text', inputPlaceholder: OO.reasonPh, inputAttributes: { maxlength: 200 },
                    showCancelButton: true, confirmButtonText: OO.rejectYes, cancelButtonText: OO.cancel, confirmButtonColor: '#dc3545' })
            .then(res => { if (!res.isConfirmed) return;
                post({ action: 'reject', order_id: id, reason: res.value || '' }).done(r => { load(); Swal.fire({ icon: r.success ? 'success' : 'error', title: r.message, timer: 2000, showConfirmButton: !r.success }); })
                    .fail(() => Swal.fire({ icon: 'error', title: OO.error })); });
    };

    $('.oo-tabs .nav-link').on('click', function () { $('.oo-tabs .nav-link').removeClass('active'); $(this).addClass('active'); tab = $(this).data('tab'); render(); });
    $('#whSelect').on('change', () => { seen = null; load(); });
    $('#ooSound').on('click', () => { sound = !sound; try { localStorage.setItem('bms_soo_sound', sound ? '1' : '0'); } catch (e) {} paintSound(); if (sound) chime(); });
    paintSound();
    load();
    setInterval(() => { if (!document.hidden) load(); }, 10000);
})();
</script>
<?php endif; ?>
<?php
if ($canShare) require __DIR__ . '/../../../includes/retail/online_shop_share.php';
require_once 'footer.php';
ob_end_flush();
