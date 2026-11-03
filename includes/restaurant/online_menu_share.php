<?php
/**
 * includes/restaurant/online_menu_share.php — "Share menu" modal on the
 * restaurant Menu page (restaurant_online_menu_plan.md Phase 4).
 * Included only when the user can edit the restaurant and the schema is ready.
 * Needs the page's whId() (current shop).
 */
?>
<div class="modal fade" id="shareMenuModal" tabindex="-1" aria-labelledby="shareMenuTitle">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="shareMenuTitle"><i class="bi bi-qr-code me-2"></i><?= t('Share your menu') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
            </div>
            <div class="modal-body">
                <div id="smLoading" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span><?= t('Loading...') ?></div>
                <div id="smBody" class="d-none">
                    <div class="form-check form-switch fs-6 mb-1">
                        <input class="form-check-input" type="checkbox" role="switch" id="smEnabled">
                        <label class="form-check-label fw-semibold" for="smEnabled"><?= t('Show my menu online') ?></label>
                    </div>
                    <p class="small text-muted mb-3"><?= t('Customers open a link or scan a QR code and see the dishes and drinks available today. No login needed.') ?></p>

                    <div id="smOn" class="d-none">
                        <div class="border rounded-3 p-3 mb-3">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input sm-opt" type="checkbox" role="switch" id="smTable">
                                <label class="form-check-label" for="smTable"><strong><?= t('Order from the table') ?></strong><br><small class="text-muted"><?= t('A customer scans the QR on their table and sends an order. Staff accept it first.') ?></small></label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input sm-opt" type="checkbox" role="switch" id="smPickup">
                                <label class="form-check-label" for="smPickup"><strong><?= t('Order for pickup') ?></strong><br><small class="text-muted"><?= t('Anyone with the menu link can order and collect. They give a name and phone number.') ?></small></label>
                            </div>
                        </div>

                        <label class="form-label fw-semibold mb-1"><?= t('Menu link') ?></label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control font-monospace" id="smLink" readonly>
                            <button class="btn btn-outline-secondary" type="button" id="smCopy" title="<?= htmlspecialchars(t('Copy')) ?>"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <a class="btn btn-sm btn-outline-primary" id="smWhatsapp" target="_blank" rel="noopener noreferrer"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
                            <a class="btn btn-sm btn-outline-primary" id="smOpen" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right me-1"></i><?= t('Open') ?></a>
                            <button class="btn btn-sm btn-outline-primary" type="button" id="smDownload"><i class="bi bi-download me-1"></i><?= t('Download QR') ?></button>
                        </div>
                        <div class="text-center mb-3"><div id="smQr" class="d-inline-block p-2 bg-white border rounded-3"></div></div>

                        <div class="d-flex justify-content-between align-items-center border-top pt-3">
                            <div>
                                <div class="fw-semibold"><?= t('Table QR codes') ?></div>
                                <small class="text-muted" id="smTablesCount"></small>
                            </div>
                            <a class="btn btn-sm btn-primary" id="smPrint" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i><?= t('Print') ?></a>
                        </div>
                        <div class="border-top mt-3 pt-3">
                            <button type="button" class="btn btn-link btn-sm text-danger p-0" id="smRotate"><i class="bi bi-arrow-repeat me-1"></i><?= t('Make new links') ?></button>
                            <div class="small text-muted"><?= t('Use this if a link was shared by mistake. Old links and printed QR codes stop working.') ?></div>
                        </div>
                    </div>
                    <div id="smMsg" class="mt-2"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
(function () {
    const SM = <?= json_encode([
        'api'       => buildUrl('api/restaurant/online_menu.php'),
        'print'     => getUrl('menu_qr'),
        'csrf'      => csrf_token(),
        'tables'    => t('%d tables'),
        'noTables'  => t('No tables yet — add tables to print table QR codes.'),
        'copied'    => t('Link copied'),
        'error'     => t('Something went wrong. Please try again.'),
        'rotateQ'   => t('Make new links?'),
        'rotateTxt' => t('The current link and every printed table QR code will stop working. You will need to share and print the new ones.'),
        'rotateYes' => t('Yes, make new links'),
        'cancel'    => t('Cancel'),
        'waText'    => t('See our menu and order here:'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let cur = null;

    function msg(html) { $('#smMsg').html(html); }
    function paint(d) {
        cur = d;
        $('#smLoading').addClass('d-none'); $('#smBody').removeClass('d-none');
        $('#smEnabled').prop('checked', d.enabled);
        $('#smTable').prop('checked', d.order_table);
        $('#smPickup').prop('checked', d.order_pickup);
        $('#smOn').toggleClass('d-none', !d.enabled);
        if (!d.enabled || !d.link) return;
        $('#smLink').val(d.link);
        $('#smOpen').attr('href', d.link);
        $('#smWhatsapp').attr('href', 'https://wa.me/?text=' + encodeURIComponent(SM.waText + ' ' + d.link));
        $('#smPrint').attr('href', SM.print + '?warehouse_id=' + encodeURIComponent(whId())).toggleClass('disabled', !d.tables.length);
        $('#smTablesCount').text(d.tables.length ? SM.tables.replace('%d', d.tables.length) : SM.noTables);
        const box = document.getElementById('smQr'); box.textContent = '';
        try { new QRCode(box, { text: d.link, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {}
    }
    function load() {
        $('#smLoading').removeClass('d-none'); $('#smBody').addClass('d-none'); msg('');
        $.getJSON(SM.api, { warehouse_id: whId() })
            .done(r => r.success ? paint(r.data) : ($('#smLoading').addClass('d-none'), $('#smBody').removeClass('d-none'), msg('<div class="alert alert-warning py-2 mb-0">' + safeOutput(r.message) + '</div>')))
            .fail(() => { $('#smLoading').addClass('d-none'); $('#smBody').removeClass('d-none'); msg('<div class="alert alert-danger py-2 mb-0">' + SM.error + '</div>'); });
    }
    function post(data, done) {
        $.post(SM.api, Object.assign({ _csrf: SM.csrf, warehouse_id: whId() }, data), null, 'json')
            .done(r => { if (r.success) { paint(r.data); if (done) done(r); } else { msg('<div class="alert alert-danger py-2 mb-0">' + safeOutput(r.message) + '</div>'); load(); } })
            .fail(() => { msg('<div class="alert alert-danger py-2 mb-0">' + SM.error + '</div>'); load(); });
    }
    function save() {
        post({ action: 'save', enabled: $('#smEnabled').is(':checked') ? 1 : 0, order_table: $('#smTable').is(':checked') ? 1 : 0, order_pickup: $('#smPickup').is(':checked') ? 1 : 0 });
    }

    window.openShareMenu = function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('shareMenuModal')).show(); load(); };
    $('#smEnabled').on('change', save);
    $('.sm-opt').on('change', save);
    $('#smCopy').on('click', function () {
        const v = $('#smLink').val(); if (!v) return;
        (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).catch(() => { $('#smLink').trigger('select'); document.execCommand('copy'); })
            .finally(() => Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: SM.copied, timer: 1400, showConfirmButton: false }));
    });
    $('#smDownload').on('click', function () {
        const c = document.querySelector('#smQr canvas'); if (!c) return;
        // Quiet zone + white background so every phone camera reads it.
        const pad = 24, out = document.createElement('canvas'); out.width = c.width + pad * 2; out.height = c.height + pad * 2;
        const g = out.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, out.width, out.height); g.drawImage(c, pad, pad);
        const a = document.createElement('a'); a.href = out.toDataURL('image/png'); a.download = 'menu-qr.png'; document.body.appendChild(a); a.click(); a.remove();
    });
    $('#smRotate').on('click', function () {
        Swal.fire({ icon: 'warning', title: SM.rotateQ, text: SM.rotateTxt, showCancelButton: true, confirmButtonText: SM.rotateYes, cancelButtonText: SM.cancel, confirmButtonColor: '#dc3545' })
            .then(r => { if (r.isConfirmed) post({ action: 'rotate' }, res => Swal.fire({ icon: 'success', title: res.message, timer: 2200, showConfirmButton: false })); });
    });
})();
</script>
