<?php
/**
 * includes/retail/online_shop_share.php — the "Share shop" window (retail_online_shop_plan.md Phase 3).
 * Smart POS retail shops: switches (online, ordering, pickup, delivery, wholesale, payment numbers),
 * the link (Copy, WhatsApp, Open, QR) and "Make new link". One shop → it opens on that shop;
 * several → a shop picker at the top. Open it with openShareShop([warehouseId]).
 * Included only when the user may edit shops (canEdit('warehouses')) in Smart POS.
 */
require_once __DIR__ . '/../../core/retail_online.php';
require_once __DIR__ . '/../../core/project_scope.php';
require_once __DIR__ . '/../restaurant/form_kit.php';

$rsShareShops = [];
if (rsSchemaReady($pdo)) {
    foreach ($pdo->query("SELECT warehouse_id, warehouse_name FROM warehouses WHERE status = 'active' AND COALESCE(pos_mode, 'retail') IN ('retail','hybrid') ORDER BY warehouse_name")->fetchAll(PDO::FETCH_ASSOC) as $w) {
        if (userCan('warehouse', (int)$w['warehouse_id'])) $rsShareShops[] = ['id' => (int)$w['warehouse_id'], 'name' => (string)$w['warehouse_name']];
    }
}
rfKitAssets();
?>
<div class="modal fade rf-modal" id="shareShopModal" tabindex="-1" aria-labelledby="shareShopTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="shareShopTitle"><i class="bi bi-shop"></i><?= t('Share your shop') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
            </div>
            <div class="modal-body">
                <?php if (!$rsShareShops): ?>
                <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i><?= t('No retail shop in your scope can have an online shop yet.') ?></div>
                <?php else: ?>
                <?php if (count($rsShareShops) > 1): ?>
                <div class="rf-sec">
                    <label class="form-label fw-semibold" for="ssShop"><?= t('Shop') ?></label>
                    <select class="form-select" id="ssShop">
                        <?php foreach ($rsShareShops as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars(caseFormat($s['name'])) ?></option><?php endforeach; ?>
                    </select>
                    <div class="rf-help"><?= t('Each shop has its own link, its own stock and its own orders.') ?></div>
                </div>
                <?php else: ?>
                <input type="hidden" id="ssShop" value="<?= $rsShareShops[0]['id'] ?>">
                <?php endif; ?>

                <div id="ssLoading" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span><?= t('Loading...') ?></div>
                <div id="ssBody" class="d-none">
                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="bi bi-globe2"></i><?= t('Online shop') ?></div>
                        <div class="rf-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="ssEnabled">
                            <label class="form-check-label fw-semibold" for="ssEnabled"><?= t('Show my shop online') ?></label>
                        </div>
                        <div class="rf-help"><?= t('Customers open a link or scan a QR code and see this shop\'s products and prices. They see Available or Sold out — never how many you have. No login needed.') ?></div>
                    </div>

                    <div id="ssOn" class="d-none">
                        <div class="rf-sec">
                            <div class="rf-sec-title"><i class="bi bi-bag-check"></i><?= t('Ordering') ?></div>
                            <div class="rf-grid">
                                <div class="rf-field rf-full">
                                    <label class="rf-choice" for="ssOrdering">
                                        <span class="form-check form-switch m-0 p-0"><input class="form-check-input ss-opt m-0" type="checkbox" role="switch" id="ssOrdering"></span>
                                        <span><span class="rf-ct"><?= t('Let customers order') ?></span><span class="rf-cd"><?= t('Customers send an order with their name and phone. You get an alert and an email. Nothing is sold until staff accept it and take the payment at the till.') ?></span></span>
                                    </label>
                                </div>
                                <div class="rf-field ss-need-order">
                                    <label class="rf-choice" for="ssPickup">
                                        <span class="form-check form-switch m-0 p-0"><input class="form-check-input ss-opt m-0" type="checkbox" role="switch" id="ssPickup"></span>
                                        <span><span class="rf-ct"><?= t('Pickup') ?></span><span class="rf-cd"><?= t('The customer collects from the shop and pays there.') ?></span></span>
                                    </label>
                                </div>
                                <div class="rf-field ss-need-order">
                                    <label class="rf-choice" for="ssDelivery">
                                        <span class="form-check form-switch m-0 p-0"><input class="form-check-input ss-opt m-0" type="checkbox" role="switch" id="ssDelivery"></span>
                                        <span><span class="rf-ct"><?= t('Delivery') ?></span><span class="rf-cd"><?= t('The customer gives an address or area. You agree the delivery fee with them by phone.') ?></span></span>
                                    </label>
                                </div>
                            </div>
                            <div class="alert alert-warning py-2 px-3 small mt-2 mb-0 d-none" id="ssNoWay" role="status"><i class="bi bi-exclamation-triangle me-1"></i><?= t('Choose Pickup, Delivery or both before letting customers order.') ?></div>
                        </div>

                        <div class="rf-sec">
                            <div class="rf-sec-title"><i class="bi bi-tags"></i><?= t('Prices and payment') ?></div>
                            <div class="rf-grid">
                                <div class="rf-field rf-full" id="ssWholesaleWrap">
                                    <label class="rf-choice" for="ssWholesale">
                                        <span class="form-check form-switch m-0 p-0"><input class="form-check-input ss-opt m-0" type="checkbox" role="switch" id="ssWholesale"></span>
                                        <span><span class="rf-ct"><?= t('Also show wholesale prices') ?></span><span class="rf-cd"><?= t('For shops that sell to other traders. Products with a wholesale price show both prices and the customer chooses. Staff check wholesale orders when accepting them.') ?></span></span>
                                    </label>
                                </div>
                                <div class="rf-field rf-full">
                                    <label class="rf-choice" for="ssPay">
                                        <span class="form-check form-switch m-0 p-0"><input class="form-check-input ss-opt m-0" type="checkbox" role="switch" id="ssPay"></span>
                                        <span><span class="rf-ct"><?= t('Show our payment numbers') ?></span><span class="rf-cd"><?= t('After ordering, the customer sees the mobile money and bank numbers from Payment Settings and may pay first, using the order number as the reference. Staff still confirm the payment at the till.') ?></span></span>
                                    </label>
                                    <div class="alert alert-warning py-2 px-3 small mt-2 mb-0 d-none" id="ssNoPay" role="status"><i class="bi bi-exclamation-triangle me-1"></i><?= t('No payment numbers are set to show yet.') ?> <a href="<?= getUrl('payment_settings') ?>"><?= t('Open Payment Settings') ?></a></div>
                                </div>
                            </div>
                        </div>

                        <div class="rf-sec">
                            <div class="rf-sec-title"><i class="bi bi-link-45deg"></i><?= t('Shop link') ?></div>
                            <div class="rf-grid">
                                <div class="rf-field rf-full">
                                    <label class="form-label visually-hidden" for="ssLink"><?= t('Shop link') ?></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control font-monospace" id="ssLink" readonly>
                                        <button class="btn btn-primary" type="button" id="ssCopy"><i class="bi bi-clipboard me-1"></i><?= t('Copy') ?></button>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <a class="btn btn-sm btn-outline-primary" id="ssWhatsapp" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
                                        <a class="btn btn-sm btn-outline-primary" id="ssOpen" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i><?= t('Open') ?></a>
                                        <button class="btn btn-sm btn-outline-primary" type="button" id="ssDownload"><i class="bi bi-download me-1"></i><?= t('Download QR') ?></button>
                                    </div>
                                    <div class="alert alert-info py-2 px-3 small mt-2 mb-0 d-none" id="ssViewOnly" role="status"><i class="bi bi-info-circle me-1"></i><?= t('This link shows your products only. Turn on "Let customers order" so customers can order from it.') ?></div>
                                </div>
                                <div class="rf-field rf-full text-center"><div id="ssQr" class="d-inline-block p-2 bg-white border rounded-3"></div>
                                    <div class="rf-help"><?= t('Print the QR code for your counter, or post it with the link on WhatsApp, Instagram or Facebook.') ?></div></div>
                            </div>
                        </div>

                        <div class="rf-sec">
                            <div class="rf-sec-title"><i class="bi bi-shield-lock"></i><?= t('Safety') ?></div>
                            <button type="button" class="btn btn-link btn-sm text-danger p-0" id="ssRotate" style="min-width:0;"><i class="bi bi-arrow-repeat me-1"></i><?= t('Make new link') ?></button>
                            <div class="rf-help"><?= t('Use this if the link was shared by mistake. The old link and printed QR codes stop working.') ?></div>
                        </div>
                    </div>
                    <div id="ssMsg" class="mt-2"></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer rf-foot">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Close') ?></button>
            </div>
        </div>
    </div>
</div>
<?php if ($rsShareShops): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    const SS = <?= json_encode([
        'api'       => buildUrl('api/stock/online_shop.php'),
        'productApi'=> buildUrl('api/stock/online_shop_product.php'),
        'csrf'      => csrf_token(),
        'copied'    => t('Link copied'),
        'copyFail'  => t('Could not copy. Select the link and copy it.'),
        'error'     => t('Something went wrong. Please try again.'),
        'rotateQ'   => t('Make a new link?'),
        'rotateTxt' => t('The current link and every printed QR code will stop working. You will need to share the new one.'),
        'rotateYes' => t('Yes, make a new link'),
        'cancel'    => t('Cancel'),
        'waView'    => t('See our products here:'),
        'waOrder'   => t('See our products and order here:'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let busy = false;
    const shopId = () => parseInt($('#ssShop').val() || 0, 10);
    function msg(html) { $('#ssMsg').html(html); }
    function paint(d) {
        $('#ssLoading').addClass('d-none'); $('#ssBody').removeClass('d-none');
        $('#ssEnabled').prop('checked', d.enabled);
        $('#ssOrdering').prop('checked', d.ordering);
        $('#ssPickup').prop('checked', d.pickup);
        $('#ssDelivery').prop('checked', d.delivery);
        $('#ssWholesale').prop('checked', d.wholesale);
        $('#ssPay').prop('checked', d.show_payment);
        $('#ssWholesaleWrap').toggleClass('d-none', !d.wholesale_ready);
        $('#ssOn').toggleClass('d-none', !d.enabled);
        $('.ss-need-order').toggleClass('opacity-50', !d.ordering).find('input').prop('disabled', !d.ordering);
        $('#ssNoWay').toggleClass('d-none', !(d.ordering && !d.pickup && !d.delivery));
        $('#ssNoPay').toggleClass('d-none', !(d.show_payment && !d.payment_numbers));
        if (!d.enabled || !d.link) return;
        const canOrder = d.ordering && (d.pickup || d.delivery);
        $('#ssViewOnly').toggleClass('d-none', canOrder);
        $('#ssLink').val(d.link);
        $('#ssOpen').attr('href', d.link);
        $('#ssWhatsapp').attr('href', 'https://wa.me/?text=' + encodeURIComponent((canOrder ? SS.waOrder : SS.waView) + ' ' + d.link));
        const box = document.getElementById('ssQr'); box.textContent = '';
        try { new QRCode(box, { text: d.link, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {}
    }
    function fail(m) { $('#ssLoading').addClass('d-none'); $('#ssBody').removeClass('d-none'); msg('<div class="alert alert-warning py-2 mb-0">' + safeOutput(m) + '</div>'); }
    function load() {
        $('#ssLoading').removeClass('d-none'); $('#ssBody').addClass('d-none'); msg('');
        $.getJSON(SS.api, { warehouse_id: shopId() })
            .done(r => r.success ? paint(r.data) : fail(r.message))
            .fail(() => fail(SS.error));
    }
    function post(data, done) {
        if (busy) return; busy = true;
        $.post(SS.api, Object.assign({ _csrf: SS.csrf, warehouse_id: shopId() }, data), null, 'json')
            .done(r => { if (r.success) { msg(''); paint(r.data); if (done) done(r); } else { msg('<div class="alert alert-danger py-2 mb-0">' + safeOutput(r.message) + '</div>'); if (r.data) paint(r.data); else load(); } })
            .fail(x => { const m = x.responseJSON && x.responseJSON.message; msg('<div class="alert alert-danger py-2 mb-0">' + safeOutput(m || SS.error) + '</div>'); if (x.responseJSON && x.responseJSON.data) paint(x.responseJSON.data); else load(); })
            .always(() => { busy = false; });
    }
    function save() {
        // Switching ordering on with neither way chosen: pick Pickup for the owner (the common case).
        if ($('#ssOrdering').is(':checked') && !$('#ssPickup').is(':checked') && !$('#ssDelivery').is(':checked') && this && this.id === 'ssOrdering') $('#ssPickup').prop('checked', true);
        post({ action: 'save', enabled: on('#ssEnabled'), ordering: on('#ssOrdering'), pickup: on('#ssPickup'), delivery: on('#ssDelivery'),
               wholesale: on('#ssWholesale'), show_payment: on('#ssPay') });
    }
    const on = s => $(s).is(':checked') ? 1 : 0;
    window.openShareShop = function (wid) {
        if (wid && $('#ssShop option[value="' + wid + '"]').length) $('#ssShop').val(String(wid));
        bootstrap.Modal.getOrCreateInstance(document.getElementById('shareShopModal')).show(); load();
    };
    $('#ssShop').on('change', load);
    $('#ssEnabled').on('change', save);
    $('.ss-opt').on('change', save);
    $('#ssCopy').on('click', function () {
        const v = $('#ssLink').val(); if (!v) return;
        const toast = (ok) => Swal.fire({ toast: true, position: 'top-end', icon: ok ? 'success' : 'warning', title: ok ? SS.copied : SS.copyFail, timer: 1800, showConfirmButton: false });
        const fallback = () => { const i = document.getElementById('ssLink'); i.focus(); i.select(); let ok = false; try { ok = document.execCommand('copy'); } catch (e) {} toast(ok); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(v).then(() => toast(true)).catch(fallback);
        else fallback();
    });
    $('#ssDownload').on('click', function () {
        const c = document.querySelector('#ssQr canvas'); if (!c) return;
        // Quiet zone + white background so every phone camera reads it.
        const pad = 24, out = document.createElement('canvas'); out.width = c.width + pad * 2; out.height = c.height + pad * 2;
        const g = out.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, out.width, out.height); g.drawImage(c, pad, pad);
        const a = document.createElement('a'); a.href = out.toDataURL('image/png'); a.download = 'shop-qr.png'; document.body.appendChild(a); a.click(); a.remove();
    });
    // Products page: show / hide one product in the online shop (every shop), then refresh the list.
    window.setOnlineVisibility = function (productId, hide) {
        $.post(SS.productApi, { _csrf: SS.csrf, product_id: productId, hidden: hide ? 1 : 0 }, null, 'json')
            .done(r => Swal.fire({ toast: true, position: 'top-end', icon: r.success ? 'success' : 'error', title: r.message, timer: 1400, showConfirmButton: false })
                          .then(() => { if (r.success) location.reload(); }))
            .fail(x => Swal.fire({ icon: 'error', title: (x.responseJSON && x.responseJSON.message) || SS.error }));
    };
    $('#ssRotate').on('click', function () {
        Swal.fire({ icon: 'warning', title: SS.rotateQ, text: SS.rotateTxt, showCancelButton: true, confirmButtonText: SS.rotateYes, cancelButtonText: SS.cancel, confirmButtonColor: '#dc3545' })
            .then(r => { if (r.isConfirmed) post({ action: 'rotate' }, res => Swal.fire({ icon: 'success', title: res.message, timer: 2200, showConfirmButton: false })); });
    });
})();
</script>
<?php endif; ?>
