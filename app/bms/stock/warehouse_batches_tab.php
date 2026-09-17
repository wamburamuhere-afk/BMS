<?php
/**
 * app/bms/stock/warehouse_batches_tab.php
 *
 * The "Batches" tab pane on warehouse_view.php — included only from there,
 * only when posSimpleModeEnabled() (see the including file). Expects
 * $warehouse_id, $can_edit_warehouse already defined by the parent.
 *
 * Everything here reads product_batches through api/stock/get_warehouse_batches.php
 * (a plain SELECT, no parallel calculation) and edits through
 * api/stock/update_warehouse_batch.php — see that file's docblock for
 * exactly which fields are editable and why quantity never is.
 */
?>
<div class="d-flex justify-content-end px-3 pt-2">
    <span class="badge bg-light text-dark border"><?= t('Every batch received into this shop') ?></span>
</div>
<div class="card-body p-0">
    <div id="whBatchesLoading" class="text-center py-5">
        <div class="spinner-border text-primary"></div>
    </div>
    <div class="table-responsive d-none" id="whBatchesTableWrap">
        <table class="table table-hover align-middle mb-0 w-100" id="whBatchesTable">
            <thead class="bg-light text-muted small text-uppercase">
                <tr>
                    <th style="width:40px;"><?= t('S/NO') ?></th>
                    <th><?= t('Product') ?></th>
                    <th><?= t('Batch Number') ?></th>
                    <th class="text-end"><?= t('Buying Price') ?></th>
                    <th class="text-end"><?= t('Selling Price') ?></th>
                    <th class="text-end"><?= t('Wholesale Price') ?></th>
                    <th><?= t('Manufacturing Date') ?></th>
                    <th><?= t('Expiry Date') ?></th>
                    <th class="text-end"><?= t('Received') ?></th>
                    <th class="text-end"><?= t('Remaining') ?></th>
                    <th><?= t('Status') ?></th>
                    <?php if ($can_edit_warehouse): ?><th class="text-center"><?= t('Actions') ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="whBatchesBody"></tbody>
        </table>
    </div>
    <!-- Mobile card view (populated by warehouse-batches.js's drawCallback) -->
    <div id="whBatchesCards" class="px-2 pb-2 d-none"></div>
    <div class="text-center py-5 d-none" id="whBatchesEmpty">
        <i class="bi bi-inbox" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3 text-muted"><?= t('No batches recorded for this shop yet') ?></h5>
        <p class="text-muted small px-3"><?= t('A batch is created automatically the first time a product is added with opening stock, or whenever you restock at the POS.') ?></p>
    </div>
</div>

<!-- Edit Batch modal -->
<?php if ($can_edit_warehouse): ?>
<div class="modal fade" id="warehouseBatchEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="warehouseBatchEditForm">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-pencil me-1"></i> <?= t('Edit Batch') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="batch_id" id="wb_batch_id">
                    <input type="hidden" name="warehouse_id" id="wb_warehouse_id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <p class="mb-3"><?= t('Product:') ?> <strong id="wb_product_name"></strong></p>

                    <div class="alert alert-warning py-2 px-3 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        <?= t('This corrects this batch\'s own recorded details only. It does not change the quantity in stock, and does not change what the product sells for today — do that on the Product page.') ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold"><?= t('Batch Number') ?></label>
                        <input type="text" class="form-control" name="batch_number" id="wb_batch_number">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold"><?= t('Manufacturing Date') ?></label>
                            <input type="date" class="form-control" name="manufacturing_date" id="wb_manufacturing_date">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold"><?= t('Expiry Date') ?></label>
                            <input type="date" class="form-control" name="expiry_date" id="wb_expiry_date">
                        </div>
                    </div>
                    <div class="row g-2 mb-1">
                        <div class="col-4">
                            <label class="form-label small fw-bold"><?= t('Buying Price') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="unit_cost" id="wb_unit_cost" step="0.01" min="0" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-bold"><?= t('Selling Price') ?></label>
                            <input type="number" class="form-control" name="selling_price" id="wb_selling_price" step="0.01" min="0">
                        </div>
                        <div class="col-4">
                            <label class="form-label small fw-bold"><?= t('Wholesale Price') ?></label>
                            <input type="number" class="form-control" name="wholesale_price" id="wb_wholesale_price" step="0.01" min="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-circle"></i> <?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* ── Mobile custom card view — same avatar/labelled-row convention as
   assets/js/pos-credit-aging.js's redesigned card (2026-09-17). ── */
@media (max-width: 768px) {
    .wb-mobile-card {
        background: #fff;
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 10px 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
    .wb-mobile-card .wb-head { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; }
    .wb-mobile-card .wb-icon {
        width: 40px; height: 40px; border-radius: 50%;
        background: #0d6efd; color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem; flex-shrink: 0;
    }
    .wb-mobile-card .wb-name { font-weight: 700; font-size: 0.85rem; line-height: 1.25; }
    .wb-mobile-card .wb-row {
        display: flex; justify-content: space-between; align-items: center; gap: 8px;
        padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 0.78rem;
    }
    .wb-mobile-card .wb-row:last-of-type { border-bottom: none; }
    .wb-mobile-card .wb-label { color: #6c757d; text-transform: uppercase; font-size: 0.65rem; letter-spacing: 0.03em; flex-shrink: 0; }
    .wb-mobile-card .wb-value { text-align: right; }
    .wb-mobile-card .wb-actions { margin-top: 10px; }
}
</style>

<script src="<?= getUrl('assets/js/tables/bms-table-utils.js') ?>?v=<?= @filemtime(ROOT_DIR . '/assets/js/tables/bms-table-utils.js') ?>"></script>
<script src="<?= getUrl('assets/js/warehouse-batches.js') ?>?v=<?= @filemtime(ROOT_DIR . '/assets/js/warehouse-batches.js') ?>"></script>
<script>
$(function () {
    WarehouseBatches.init({
        id: 'wh-batches',
        tableSel: '#whBatchesTable',
        listContainer: '#whBatchesTableWrap',
        cardContainer: '#whBatchesCards',
        loadingEl: '#whBatchesLoading',
        emptyEl: '#whBatchesEmpty',
        warehouseId: <?= (int)$warehouse_id ?>,
        canEdit: <?= json_encode($can_edit_warehouse) ?>,
        hide: <?= json_encode($can_edit_warehouse ? [] : ['actions']) ?>,
        // Not the default-active tab-pane on this page (Recent Activity is)
        // — hold DataTable() init until it's actually shown, same reasoning
        // as the Madeni tab in customer_details.php.
        deferPane: '#pane-warehouse-batches',
        urls: {
            list:   <?= json_encode(buildUrl('api/stock/get_warehouse_batches.php')) ?>,
            update: <?= json_encode(buildUrl('api/stock/update_warehouse_batch.php')) ?>,
        },
        i18n: {
            edit: <?= json_encode(t('Edit')) ?>,
            active: <?= json_encode(t('Active')) ?>,
            expired: <?= json_encode(t('Expired')) ?>,
            exhausted: <?= json_encode(t('Exhausted')) ?>,
            buyingPrice: <?= json_encode(t('Buying Price')) ?>,
            sellingPrice: <?= json_encode(t('Selling Price')) ?>,
            wholesalePrice: <?= json_encode(t('Wholesale Price')) ?>,
            manufacturingDate: <?= json_encode(t('Manufacturing Date')) ?>,
            expiryDate: <?= json_encode(t('Expiry Date')) ?>,
            received: <?= json_encode(t('Received')) ?>,
            remaining: <?= json_encode(t('Remaining')) ?>,
            success: <?= json_encode(t('Success!')) ?>,
            error: <?= json_encode(t('Error')) ?>,
        }
    });
});
</script>
