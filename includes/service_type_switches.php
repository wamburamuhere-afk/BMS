<?php
/**
 * The thin strip at the top of "Pricing & Planning" (full form only): two switches, both off by
 * default, so a plain service keeps the form exactly as before. At most one is on.
 *   - "BMS plan from Superadmin" (BJP's install, admins): sells a platform product's Superadmin plans.
 *   - "Other system, own plans": plans and terms typed here, invoiced by hand (no auto-extension).
 * Expects: $sptPrefix ('svc' | 'edit_svc'), $isVendor (bool), $canMapPlatform (bool).
 * Behaviour lives in services.php (svcTypeSwitch / svcTypeApply).
 */
$p = $sptPrefix;
?>
<div class="d-flex flex-wrap align-items-center gap-3 px-3 py-2 mb-3 rounded-3" id="<?= $p ?>_type_strip"
     style="background:#f8fbff;border:1px dashed #b6ccfe;">
    <span class="small text-muted fw-semibold"><i class="bi bi-layers text-primary me-1"></i><?= t('Sold in plans?') ?></span>
    <?php if (!empty($canMapPlatform)): ?>
    <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="<?= $p ?>_type_sa" onchange="svcTypeSwitch('<?= $p ?>', 'sa', this)">
        <label class="form-check-label small fw-semibold" for="<?= $p ?>_type_sa"><?= t('BMS plan from Superadmin') ?></label>
    </div>
    <?php endif; ?>
    <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="<?= $p ?>_type_own" onchange="svcTypeSwitch('<?= $p ?>', 'own', this)">
        <label class="form-check-label small fw-semibold" for="<?= $p ?>_type_own"><?= !empty($isVendor) ? t('Other system, own plans') : t('Own plans and terms') ?></label>
    </div>
    <span class="small text-primary ms-auto d-none" id="<?= $p ?>_type_lock"><i class="bi bi-lock-fill me-1"></i><?= t('Linked to Superadmin') ?></span>
</div>
