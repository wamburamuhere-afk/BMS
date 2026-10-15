<?php
/**
 * Optional "Price by term" editor inside the Add/Edit Service modals (full form only).
 * Expects: $sptPrefix ('svc' | 'edit_svc'), $isVendor (bool). Behaviour lives in
 * services.php (sptAddRow / sptSync / sptCollect / sptLoad).
 */
$p = $sptPrefix;
?>
<div class="mb-4 p-3 bg-white rounded-3 shadow-sm border border-primary border-opacity-10" id="<?= $p ?>_spt_section">
    <input type="hidden" name="price_terms" id="<?= $p ?>_price_terms" value="">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
        <div>
            <h6 class="fw-bold mb-1"><i class="bi bi-calendar-range text-primary me-2"></i><?= t('Price by term') ?>
                <span class="text-muted fw-normal small">(<?= t('optional') ?>)</span></h6>
            <div class="small text-muted"><?= t('Leave empty to sell at one fixed price. Add terms — for example 1 Month, 3 Months, 1 Year — and quotations and invoices will ask which term and use its price.') ?></div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" onclick="sptAddRow('<?= $p ?>')">
            <i class="bi bi-plus-circle me-1"></i><?= t('Add term') ?>
        </button>
    </div>

    <div class="table-responsive d-none" id="<?= $p ?>_spt_wrap">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light small text-uppercase">
                <tr>
                    <th style="width:50px;"><?= t('S/NO') ?></th>
                    <th style="min-width:150px;"><?= t('Term name') ?></th>
                    <th style="min-width:170px;"><?= t('Length') ?></th>
                    <th style="min-width:130px;"><?= t('Price (TZS)') ?></th>
                    <th style="min-width:180px;"><?= t('Description') ?></th>
                    <th class="text-center" style="width:70px;"><?= t('Default') ?></th>
                    <th style="width:50px;"></th>
                </tr>
            </thead>
            <tbody id="<?= $p ?>_spt_body"></tbody>
        </table>
    </div>

    <?php if (!empty($isVendor)): ?>
    <div class="form-check mt-3 pt-2 border-top">
        <input type="hidden" name="subscription_flag_sent" value="1">
        <input class="form-check-input" type="checkbox" name="is_subscription" value="1" id="<?= $p ?>_is_subscription">
        <label class="form-check-label small" for="<?= $p ?>_is_subscription">
            <strong><?= t('This service is a BMS system subscription') ?></strong> —
            <?= t('when an invoice for it is fully paid, the customer\'s company gets the term\'s months added automatically. Terms must be in months or years.') ?>
        </label>
    </div>
    <?php endif; ?>
</div>
