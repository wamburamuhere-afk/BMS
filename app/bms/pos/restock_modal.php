<?php
// app/bms/pos/restock_modal.php — the Receive Stock (restock) window.
// One copy, used by the POS (pos_modals_new.php) and the Receive Stock page
// (receive_stock.php). Needs $can_restock_product and $_pos_warehouse_scoped
// (the user's shops); its script is restock_modal_js.php.
?>
<?php if ($can_restock_product ?? false): ?>
<!-- Restock Product Modal -->
<div class="modal fade" id="restockProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-arrow-repeat me-1"></i> <?= t('Restock Product') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="restockProductForm" autocomplete="off">
                <div class="modal-body">
                    <div id="restock-message" class="mb-2"></div>
                    <div class="mb-3">
                        <label class="form-label"><?= t('Product') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="restock_product_id" name="product_id" required style="width:100%"></select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label"><?= t('Date') ?></label>
                            <input type="date" class="form-control" id="restock_date" name="date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= t('Quantity') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="restock_quantity" name="quantity" min="0.001" step="0.001" required>
                        </div>
                    </div>
                    <?php if (count($_pos_warehouse_scoped ?? []) > 1): ?>
                    <div class="mb-3 mt-2">
                        <label class="form-label"><?= t('Shop') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="restock_warehouse_id" name="warehouse_id" required>
                            <?= renderWarehouseOptions($_pos_warehouse_scoped) ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="row g-2 mt-1">
                        <div class="col-4">
                            <label class="form-label"><?= t('Buying Price') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="restock_buying_price" name="buying_price" min="0" step="0.01" required>
                        </div>
                        <div class="col-4">
                            <label class="form-label"><?= t('Wholesale Price') ?></label>
                            <input type="number" class="form-control" id="restock_wholesale_price" name="wholesale_price" min="0" step="0.01">
                        </div>
                        <div class="col-4">
                            <label class="form-label"><?= t('Retail Price') ?> <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="restock_selling_price" name="selling_price" min="0" step="0.01" required>
                        </div>
                    </div>
                    <!-- Both optional (products_simple_pos_plan.md §8) — forcing an
                         expiry date on every restock would break for non-perishable
                         goods. Feeds straight into receiveProductBatch(); the
                         already-built expiry-notification cron picks it up with no
                         further changes. -->
                    <div class="row g-2 mt-1">
                        <div class="col-6">
                            <label class="form-label"><?= t('Manufacturing Date') ?></label>
                            <input type="date" class="form-control" id="restock_manufacturing_date" name="manufacturing_date">
                        </div>
                        <div class="col-6">
                            <label class="form-label"><?= t('Expiry Date') ?></label>
                            <input type="date" class="form-control" id="restock_expiry_date" name="expiry_date">
                        </div>
                    </div>
                    <?php
                    // Optional "which supplier was this batch ordered from" (2026-09-17
                    // Supplier Access request) — genuinely absent from the DOM (not just
                    // hidden) unless Suppliers is actually reachable for this tenant
                    // (full Procurement, or the lightweight Supplier Access toggle —
                    // canView('suppliers') already folds both in via
                    // tenantModuleAllowsPage()). Never required either way — "not
                    // recommended but not required" per the product owner; a batch
                    // restocked without it simply has no supplier on file, same as today.
                    $_pos_can_pick_supplier = function_exists('canView') && canView('suppliers');
                    $_pos_restock_suppliers = $_pos_can_pick_supplier
                        ? $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC)
                        : [];
                    ?>
                    <?php if ($_pos_can_pick_supplier): ?>
                    <div class="mb-3 mt-2">
                        <label class="form-label"><?= t('Supplier') ?></label>
                        <select class="form-select" id="restock_supplier_id" name="supplier_id">
                            <option value=""><?= t('— Not specified —') ?></option>
                            <?php foreach ($_pos_restock_suppliers as $_sup): ?>
                            <option value="<?= $_sup['supplier_id'] ?>"><?= caseFormat($_sup['supplier_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?= t('Optional — which supplier this batch was ordered from.') ?></small>
                    </div>
                    <?php endif; ?>
                    <?php
                    // Simple Mode ("normal business man" —
                    // core/pos_nav.php::posSimpleModeEnabled()) never shows a
                    // GL account picker — api/pos/quick_restock.php resolves
                    // one default cash account itself (posReceiptAccountId(),
                    // the same "Cash Drawer" account cash POS sales already
                    // post to) and posts the whole thing silently in the
                    // background. A pro business (Simple Mode off) keeps
                    // choosing exactly as before.
                    $posSimpleRestock = function_exists('posSimpleModeEnabled') && posSimpleModeEnabled();
                    ?>
                    <?php if (!$posSimpleRestock): ?>
                    <div class="mb-3 mt-2">
                        <label class="form-label"><?= t('Paid From') ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="restock_paid_from_account_id" name="paid_from_account_id" required>
                            <option value=""><?= t('— Select Account —') ?></option>
                            <?= paidFromSelectOptions($pdo) ?>
                        </select>
                        <small class="text-muted"><?= t('The buying price is assumed already paid from this account.') ?></small>
                    </div>
                    <?php endif; ?>
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i> <?= t('Save Restock') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
