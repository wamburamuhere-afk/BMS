<?php
/**
 * includes/sales_unlinked_lines_js.php
 * Before saving a quotation / sales order / invoice, warn when a line was typed
 * by hand instead of picked from the catalogue: such a line saves fine but has
 * no product/service link, so it is missing from product and service reports.
 * Only warns — hand-typed lines (e.g. IPC or one-off charges) stay allowed.
 */
?>
<script>
function bmsConfirmUnlinkedLines(proceed) {
    const names = [];
    $('[id^="item-row-"]').each(function () {
        const name = ($(this).find('.item-name').val() || '').trim();
        const pid  = ($(this).find('.item-product-id').val() || '').trim();
        if (name !== '' && (pid === '' || pid === '0')) names.push(name);
    });
    if (!names.length || typeof Swal === 'undefined') { proceed(); return; }
    const list = names.slice(0, 5).map(n => '<li>' + $('<span>').text(n).html() + '</li>').join('')
        + (names.length > 5 ? '<li>…</li>' : '');
    Swal.fire({
        icon: 'warning',
        title: <?= json_encode(t('Lines not linked to a product or service')) ?>,
        html: '<div class="text-start small">'
            + <?= json_encode(t('These lines were typed by hand, so they will not appear in product and service sales reports:')) ?>
            + '<ul class="mt-2 mb-2">' + list + '</ul>'
            + <?= json_encode(t('Pick the item from the search list to link it, or save anyway.')) ?>
            + '</div>',
        showCancelButton: true,
        confirmButtonText: <?= json_encode(t('Save anyway')) ?>,
        cancelButtonText: <?= json_encode(t('Go back')) ?>,
        confirmButtonColor: '#0d6efd'
    }).then(r => { if (r.isConfirmed) proceed(); });
}
</script>
