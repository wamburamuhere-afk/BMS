<?php
/**
 * Optional "Price by term" editor inside the Add/Edit Service modals (full form only).
 * Expects: $sptPrefix ('svc' | 'edit_svc'), $isVendor (bool), $canMapPlatform (bool),
 * $platformProducts ([key => name]). Behaviour lives in services.php
 * (sptAddRow / sptSync / sptCollect / sptLoad, and the sp* plans drawer).
 */
$p = $sptPrefix;
?>
<!-- Hidden until a switch in the strip above (service_type_switches.php) turns it on. -->
<div class="mb-4 p-3 bg-white rounded-3 shadow-sm border border-primary border-opacity-10 d-none" id="<?= $p ?>_spt_section">
    <input type="hidden" name="price_terms" id="<?= $p ?>_price_terms" value="">
    <input type="hidden" name="service_plans" id="<?= $p ?>_service_plans" value="">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2" id="<?= $p ?>_spt_head">
        <div>
            <h6 class="fw-bold mb-1"><i class="bi bi-calendar-range text-primary me-2"></i><?= t('Price by term') ?>
                <span class="text-muted fw-normal small">(<?= t('optional') ?>)</span></h6>
            <div class="small text-muted"><?= t('Leave empty to sell at one fixed price. Add terms — for example 1 Month, 3 Months, 1 Year — and quotations and invoices will ask which term and use its price.') ?></div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" id="<?= $p ?>_spt_add" onclick="sptAddRow('<?= $p ?>')">
            <i class="bi bi-plus-circle me-1"></i><?= t('Add term') ?>
        </button>
    </div>

    <!-- Plans (core/service_plans.php): filled by spRenderBar() -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-2 mb-2 rounded-3" style="background:#f4f8ff;border:1px dashed #b6ccfe;" id="<?= $p ?>_sp_bar"></div>

    <div class="table-responsive d-none" id="<?= $p ?>_spt_wrap">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light small text-uppercase">
                <tr>
                    <th style="width:50px;"><?= t('S/NO') ?></th>
                    <th style="min-width:150px;"><?= t('Term name') ?></th>
                    <th style="min-width:170px;"><?= t('Length') ?></th>
                    <th style="min-width:130px;"><?= str_replace('TZS', bmsCompanyCurrency(), t('Price (TZS)')) ?></th>
                    <th style="min-width:180px;"><?= t('Description') ?></th>
                    <th class="text-center" style="width:70px;"><?= t('Default') ?></th>
                    <th style="width:50px;"></th>
                </tr>
            </thead>
            <tbody id="<?= $p ?>_spt_body"></tbody>
        </table>
    </div>

    <?php if (!empty($isVendor)): ?>
    <?php if (!empty($canMapPlatform)): ?>
    <div class="mt-3 pt-2 border-top" id="<?= $p ?>_platform_wrap">
        <input type="hidden" name="platform_product_sent" value="1">
        <label class="form-label small fw-bold" for="<?= $p ?>_platform_product"><?= t('This service sells') ?> <span class="text-muted fw-normal">(<?= t('optional') ?>)</span></label>
        <select class="form-select form-select-sm select2-static" name="platform_product_key" id="<?= $p ?>_platform_product" onchange="spPlatformChanged('<?= $p ?>')">
            <option value=""><?= t('None — prices set here') ?></option>
            <?php foreach (($platformProducts ?? []) as $ppKey => $ppName): ?>
            <option value="<?= safe_output($ppKey, '') ?>"><?= safe_output($ppName, '') ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text"><?= t('Choose a platform product to sell its Superadmin plans. Its plans and prices then come from Superadmin → Plans and cannot be edited here.') ?></div>
    </div>
    <?php endif; ?>
    <div class="form-check mt-3 pt-2 border-top" id="<?= $p ?>_sub_wrap">
        <input type="hidden" name="subscription_flag_sent" value="1">
        <input class="form-check-input" type="checkbox" name="is_subscription" value="1" id="<?= $p ?>_is_subscription">
        <label class="form-check-label small" for="<?= $p ?>_is_subscription">
            <strong><?= t('This service is a BMS system subscription') ?></strong> —
            <?= t('when an invoice for it is fully paid, the customer\'s company gets the term\'s months added automatically. Terms must be in months or years.') ?>
        </label>
    </div>
    <div class="mt-2" id="<?= $p ?>_contract_wrap">
        <label class="form-label small fw-bold" for="<?= $p ?>_contract_template"><?= t('Contract template') ?> <span class="text-muted fw-normal">(<?= t('optional') ?>)</span></label>
        <select class="form-select form-select-sm select2-static" name="contract_template_id" id="<?= $p ?>_contract_template">
            <option value=""><?= t('No contract') ?></option>
            <?php foreach (($contractTemplates ?? []) as $ct): ?>
            <option value="<?= (int)$ct['id'] ?>"><?= safe_output($ct['template_name'], '') ?></option>
            <?php endforeach; ?>
        </select>
        <div class="form-text"><?= t('When a subscription invoice is paid, a contract is made from this Document Template for BJP and then the customer to sign. Tokens such as {{customer_name}}, {{period}}, {{period_start}}, {{period_end}}, {{amount}} are filled in.') ?></div>
    </div>
    <?php endif; ?>

    <!-- Plans drawer. Inside the modal so the modal's focus trap allows typing in it. -->
    <div class="offcanvas offcanvas-end shadow-lg" tabindex="-1" id="<?= $p ?>_sp_drawer" data-bs-backdrop="false" data-bs-scroll="true"
         style="width:min(560px,100vw);z-index:1075;" aria-labelledby="<?= $p ?>_sp_title">
        <div class="offcanvas-header bg-primary text-white">
            <h5 class="offcanvas-title fw-bold" id="<?= $p ?>_sp_title"><i class="bi bi-layers me-2"></i><?= t('Plans') ?></h5>
            <button type="button" class="btn-close btn-close-white" onclick="spCancel('<?= $p ?>')" aria-label="<?= t('Close') ?>"></button>
        </div>
        <div class="offcanvas-body" id="<?= $p ?>_sp_body"></div>
        <div class="p-3 border-top d-flex justify-content-end gap-2" id="<?= $p ?>_sp_footer"></div>
    </div>
</div>
