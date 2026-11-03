<?php
/**
 * includes/restaurant/buy_ingredients_modal.php — "Buy Ingredients" window
 * (restaurant_plan.md Phase 5). Included on the Expenses page, the restaurant hub
 * and the restaurant dashboard, only when the restaurant module is on.
 *
 * Saves through the existing Expenses engine (api/add_expense.php) as a paid expense
 * debited to Cost of Sales (restaurantIngredientsAccountId — 5-2000 Purchases), for a
 * restaurant shop, paid from cash (Smart POS default). So it appears in Expenses, posts
 * through the one ledger, and the profit report counts it as the cost of the food sold.
 * Open it with rbiOpen(). Expects $pdo.
 */
if (!restaurantModuleOn($pdo) || !canCreate('expenses')) return;
require_once ROOT_DIR . '/core/sales_posting.php';   // posReceiptAccountId() — the same cash account the sales go to
$rbiShops   = restaurantWarehousesForSelect($pdo);
$rbiAccount = restaurantIngredientsAccountId($pdo);
if (!$rbiShops || !$rbiAccount) return;
?>
<?php require_once __DIR__ . '/form_kit.php'; rfKitAssets(); ?>
<div class="modal fade rf-modal" id="rbiModal" tabindex="-1" aria-labelledby="rbiTitle">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="rbiTitle"><i class="bi bi-basket"></i><?= t('Buy Ingredients') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
            </div>
            <form id="rbiForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="expense_account_id" value="<?= (int)$rbiAccount ?>">
                    <input type="hidden" name="status" value="paid">
                    <input type="hidden" name="bank_account_id" value="<?= (int)(posReceiptAccountId($pdo, 'cash') ?: 0) ?>">
                    <div id="rbi-message" class="mb-2"></div>
                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="bi bi-basket"></i><?= t('What did you buy?') ?></div>
                        <div class="rf-grid">
                            <div class="rf-field rf-full">
                                <label class="form-label" for="rbi-item"><?= t('Item') ?><span class="rf-req">*</span></label>
                                <input type="text" class="form-control" name="rbi_item" id="rbi-item" maxlength="200" required placeholder="<?= htmlspecialchars(t('e.g. Meat 5 kg, fish, wheat flour')) ?>">
                            </div>
                        </div>
                    </div>
                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="bi bi-cash-coin"></i><?= t('Payment') ?></div>
                        <div class="rf-grid">
                            <div class="rf-field">
                                <label class="form-label" for="rbi-amount"><?= t('Amount paid') ?><span class="rf-req">*</span></label>
                                <div class="input-group rf-money"><span class="input-group-text rf-cur"><?= htmlspecialchars(rfMoneyPrefix()) ?></span>
                                    <input type="number" class="form-control" name="amount" id="rbi-amount" min="1" step="any" inputmode="decimal" required></div>
                            </div>
                            <div class="rf-field">
                                <label class="form-label" for="rbi-date"><?= t('Date') ?></label>
                                <input type="date" class="form-control" name="expense_date" id="rbi-date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                            </div>
                            <?php if (count($rbiShops) > 1): ?>
                            <div class="rf-field rf-full">
                                <label class="form-label" for="rbi-shop"><?= wLabel('Warehouse', 'Shop') ?></label>
                                <select class="form-select rf-select2" name="warehouse_id" id="rbi-shop">
                                    <?php foreach ($rbiShops as $w): ?>
                                    <option value="<?= (int)$w['warehouse_id'] ?>"><?= caseFormat($w['warehouse_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php else: ?>
                            <input type="hidden" name="warehouse_id" value="<?= (int)$rbiShops[0]['warehouse_id'] ?>">
                            <?php endif; ?>
                        </div>
                        <div class="rf-help mt-2"><i class="bi bi-info-circle me-1"></i><?= t('Paid from cash. Counted as the cost of the food you sell, so your profit is correct.') ?></div>
                    </div>
                </div>
                <div class="modal-footer rf-foot">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle me-1"></i><?= t('Save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function rbiOpen() {
    const f = document.getElementById('rbiForm');
    if (!f) return;
    f.reset();
    $('#rbi-date').val(<?= json_encode(date('Y-m-d')) ?>);
    $('#rbi-message').html('');
    $('#rbi-shop').trigger('change.select2');   // the form reset changed the value under Select2
    bootstrap.Modal.getOrCreateInstance(document.getElementById('rbiModal')).show();
    setTimeout(() => $('#rbi-item').trigger('focus'), 300);
}
$(function () {
    $('#rbiForm').on('submit', function (e) {
        e.preventDefault();
        const btn = $(this).find('[type="submit"]'); const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> ' + <?= json_encode(t('Saving...')) ?>);
        const fd = new FormData(this);
        fd.set('description', <?= json_encode(t('Ingredients')) ?> + ': ' + ($('#rbi-item').val() || '').trim());
        fd.delete('rbi_item');
        $.ajax({ url: '<?= buildUrl('api/add_expense.php') ?>', type: 'POST', data: fd, contentType: false, processData: false, dataType: 'json',
            success: function (res) {
                if (res.success) {
                    bootstrap.Modal.getInstance(document.getElementById('rbiModal')).hide();
                    if (typeof window.rbiSaved === 'function') window.rbiSaved(res);
                    Swal.fire({ icon: 'success', title: <?= json_encode(t('Ingredients purchase saved.')) ?>, timer: 1800, showConfirmButton: false });
                } else {
                    $('#rbi-message').html('<div class="alert alert-danger py-2 mb-0">' + safeOutput(res.message || <?= json_encode(t('Something went wrong.')) ?>) + '</div>');
                }
            },
            error: () => $('#rbi-message').html('<div class="alert alert-danger py-2 mb-0">' + <?= json_encode(t('Server error. Please try again.')) ?> + '</div>'),
            complete: () => btn.prop('disabled', false).html(orig)
        });
    });
});
</script>
