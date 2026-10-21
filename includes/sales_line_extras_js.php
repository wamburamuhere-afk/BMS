<?php
/** Loads assets/js/sales_line_extras.js with the System Settings discount choice. Include once per document form. */
require_once ROOT_DIR . '/core/sales_line_discount.php';
?>
<script>
window.BMS_SALES_DISCOUNT = <?= json_encode([
    'type'        => salesDiscountDefaultType(),
    'allowSwitch' => salesDiscountAllowSwitch(),
    'currency'    => get_setting('currency', 'TZS') ?: 'TZS',
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<script src="<?= getUrl('assets/js/sales_line_extras.js') ?>?v=3"></script>
<style>
textarea.item-description { font-size: .85rem; resize: vertical; min-height: 3.2rem; }
</style>
