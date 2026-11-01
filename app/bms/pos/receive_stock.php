<?php
// app/bms/pos/receive_stock.php — Receive Stock, opened straight away.
//
// The Products button window, the dashboard tile, the menus and the Products
// list come here. Before, they opened the whole POS (?restock=1), which loaded
// the till, product grid and cart before the form appeared. This page shows
// only the Receive Stock window (restock_modal.php, the same one the POS uses):
// saving clears it for the next product; X / Cancel goes to the Products list
// (stock belongs to a product, and that is where the new quantity shows).
require_once __DIR__ . '/../../../roots.php';
require_once ROOT_DIR . '/core/warehouse_scope.php';
require_once ROOT_DIR . '/core/payment_source.php';
global $pdo;

// Same gates as every Receive Stock link — checked before any output.
autoEnforcePermission('pos');
if (!canView('pos_restock')) {
    header('Location: ' . getUrl('unauthorized'));
    exit;
}

$can_restock_product = true;
// The user's shops — the same narrowing the POS shop dropdown and
// api/pos/quick_restock.php apply.
$_pos_warehouse_scoped = array_values(array_filter(
    warehousesForSelect($pdo),
    fn($w) => userCan('warehouse', (int)$w['warehouse_id'])
));
$__one_shop = count($_pos_warehouse_scoped) === 1 ? (int)$_pos_warehouse_scoped[0]['warehouse_id'] : 0;

includeHeader();
?>

<div class="container-fluid mt-3 receive-stock-page">
    <h5 class="fw-bold mb-1"><i class="bi bi-box-arrow-in-down me-1 text-primary"></i><?= t('Receive Stock') ?></h5>
    <p class="text-muted small mb-3"><?= t('Add stock to a product you already have') ?></p>
    <?php if (empty($_pos_warehouse_scoped)): ?>
    <div class="alert alert-warning"><?= t('No shop is assigned to your account — contact an administrator.') ?></div>
    <?php endif; ?>
    <?php if ($__one_shop): ?>
    <input type="hidden" id="restockDefaultWarehouseId" value="<?= $__one_shop ?>">
    <?php endif; ?>
</div>

<?php include __DIR__ . '/restock_modal.php'; ?>

<script>
<?php include __DIR__ . '/restock_modal_js.php'; ?>

$(function () {
    if (!document.getElementById('restockProductModal')) return;

    // Saved → ready for the next product; the window stays open (opted out of
    // the footer's "close the open window after a successful save" rule).
    document.getElementById('restockProductModal').setAttribute('data-no-autoclose', 'true');
    initRestockModal(restockResetForm);

    // Closing the window (X / Cancel) leaves the page for the Products list — always the
    // same place, no guessing from the browser's "referrer" (which could be missing and left
    // a blank page). Users without access to Products go to the dashboard instead.
    const LEAVE_URL = <?= json_encode(getUrl(canView('products') ? 'products' : 'dashboard')) ?>;
    $('#restockProductModal').on('hidden.bs.modal', function () { location.replace(LEAVE_URL); });

    // This page is only a shell around the window. If the browser restores it from its
    // back/forward cache (window already closed), never leave the user on an empty page.
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && !document.querySelector('#restockProductModal.show')) location.replace(LEAVE_URL);
    });

    <?php if (!empty($_pos_warehouse_scoped)): ?>
    // Opens straight away; a tap outside the window doesn't close it by accident.
    const el = document.getElementById('restockProductModal');
    bootstrap.Modal.getOrCreateInstance(el, { backdrop: 'static' });
    openRestockProductModal();
    <?php endif; ?>
});
</script>

<?php includeFooter(); ?>
