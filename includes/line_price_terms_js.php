<?php
/** Loads assets/js/line_price_terms.js with its endpoint + labels. Include once per document form. */
?>
<script>
window.BMS_LINE_TERMS_URL  = <?= json_encode(getUrl('api/account/get_service_price_terms.php')) ?>;
window.BMS_LINE_TERMS_I18N = <?= json_encode(['choose' => t('Choose term…'), 'no_term' => t('No term chosen — the line is saved at the price typed.')], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= getUrl('assets/js/line_price_terms.js') ?>?v=4"></script>
