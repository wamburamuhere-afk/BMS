<?php
/**
 * includes/restaurant/table_form_modal.php — the ONE Add / Edit Table form, used by
 * both Floors (app/bms/restaurant/floors.php) and Tables (tables.php), so the two can
 * never drift apart. Restaurant form layout (includes/restaurant/form_kit.php).
 * Field ids/names are the ones each page's script already uses.
 */
require_once __DIR__ . '/form_kit.php';
rfKitAssets();
?>
<div class="modal fade rf-modal" id="tableModal" tabindex="-1" aria-labelledby="tableModalTitle">
    <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-grid-3x3-gap"></i><span id="tableModalTitle"><?= t('Add Table') ?></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= htmlspecialchars(t('Close')) ?>"></button>
            </div>
            <form id="tableForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" name="table_id" id="f-table-id">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <div id="table-message" class="mb-2"></div>
                    <div class="rf-sec">
                        <div class="rf-sec-title"><i class="bi bi-grid-3x3-gap"></i><?= t('Table') ?></div>
                        <div class="rf-grid">
                            <div class="rf-field">
                                <label class="form-label" for="f-table-number"><?= t('Table Number') ?><span class="rf-req">*</span></label>
                                <input type="text" class="form-control" name="table_number" id="f-table-number" required maxlength="50">
                            </div>
                            <div class="rf-field">
                                <label class="form-label" for="f-table-seats"><?= t('Seats') ?></label>
                                <input type="number" class="form-control" name="seats" id="f-table-seats" value="2" min="1" inputmode="numeric">
                            </div>
                            <div class="rf-field rf-full">
                                <label class="form-label" for="f-table-floor"><?= t('Floor') ?> <span class="rf-opt">(<?= t('optional') ?>)</span></label>
                                <select class="form-select rf-select2" name="floor_id" id="f-table-floor"></select>
                            </div>
                        </div>
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
