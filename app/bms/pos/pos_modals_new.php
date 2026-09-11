<?php
// File: pos_modals_new.php - Modal dialogs for new POS
?>

<!-- Product Quick View Modal -->
<div class="modal fade" id="productQuickView" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= t('Add to Cart') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="quickViewContent">
                    <!-- Product details will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Table Picker Modal — Phase 30 (pos_upgrade_plan.md §9). Only ever opened
     from the Table button, which itself is hidden unless the selected
     warehouse's pos_mode !== 'retail' and the tenant holds restaurant_pos. -->
<div class="modal fade" id="tablePickerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-grid-3x3-gap me-1"></i> <?= t('Select Table') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="tablePickerBody" style="max-height:65vh;overflow-y:auto;">
                <!-- Populated by openTablePicker() in pos_scripts_new.php -->
            </div>
        </div>
    </div>
</div>

<!-- Quick Add Customer Modal — Phase 10 (pos_upgrade_plan.md §7) -->
<div class="modal fade" id="quickAddCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i> <?= t('New Customer') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label"><?= t('Customer Name') ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="qac_name" placeholder="<?= t('e.g. John Mushi') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('Phone') ?></label>
                    <input type="text" class="form-control" id="qac_phone" placeholder="<?= t('e.g. 0712 345 678') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-primary" id="btnSaveQuickCustomer"><i class="bi bi-check-circle me-1"></i> <?= t('Add Customer') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Held Sales Modal -->
<div class="modal fade" id="heldSalesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="bi bi-pause"></i> <?= t('Held Sales') ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th><?= t('Hold #') ?></th>
                            <th><?= t('Customer') ?></th>
                            <th><?= t('Items') ?></th>
                            <th><?= t('Total') ?></th>
                            <th><?= t('Held At') ?></th>
                            <th><?= t('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody id="heldSalesBody">
                        <!-- Held sales will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Start Shift Modal -->
<div class="modal fade" id="startShiftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-play-circle"></i> <?= t('Start Shift') ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label"><?= t('Register / Till') ?></label>
                    <select class="form-select" id="startShiftRegister" style="width:100%"></select>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('Opening Cash Amount') ?></label>
                    <input type="number" class="form-control" id="openingCash"
                           min="0" step="0.01" value="0">
                </div>
                <!-- Phase 20 (pos_upgrade_plan.md §8) — optional denomination count -->
                <a href="#" class="small" onclick="event.preventDefault(); $('#openDenomGrid').toggleClass('d-none'); renderDenomGrid('openDenomGrid');">
                    <i class="bi bi-calculator"></i> <?= t('Count by denomination (optional)') ?>
                </a>
                <div class="d-none mt-2" id="openDenomGrid" data-target-input="openingCash"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-success" onclick="confirmStartShift()"><?= t('Start Shift') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- End Shift Modal -->
<div class="modal fade" id="endShiftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="bi bi-power"></i> <?= t('End Shift') ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label"><?= t('Ending Cash Count') ?></label>
                    <input type="number" class="form-control" id="endingCash"
                           min="0" step="0.01" value="<?= $cash_balance ?>">
                </div>
                <div class="alert alert-info">
                    <div class="d-flex justify-content-between">
                        <span><?= t('Starting Cash:') ?></span>
                        <strong><?= format_currency($starting_cash, $currency) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span><?= t('Calculated Balance:') ?></span>
                        <strong><?= format_currency($cash_balance, $currency) ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span><?= t('Difference:') ?></span>
                        <strong id="cashDifference"><?= htmlspecialchars($currency) ?> 0.00</strong>
                    </div>
                </div>
                <!-- Phase 20 (pos_upgrade_plan.md §8) — optional denomination count -->
                <a href="#" class="small" onclick="event.preventDefault(); $('#closeDenomGrid').toggleClass('d-none'); renderDenomGrid('closeDenomGrid');">
                    <i class="bi bi-calculator"></i> <?= t('Count by denomination (optional)') ?>
                </a>
                <div class="d-none mt-2" id="closeDenomGrid" data-target-input="endingCash"></div>
                <div class="mb-3 mt-3">
                    <label class="form-label"><?= t('Notes (optional)') ?></label>
                    <textarea class="form-control" id="shiftNotes" rows="2"
                              placeholder="<?= t('Any notes about the shift...') ?>"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-warning" onclick="confirmEndShift()"><?= t('End Shift') ?></button>
            </div>
        </div>
    </div>
</div>
<!-- Discount Modal -->
<div class="modal fade" id="discountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-percent me-2" id="discountIcon"></i><?= t('Apply Discount') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 border-bottom bg-light">
                    <label class="form-label small fw-bold text-muted text-uppercase" id="discountLabel"><?= t('Discount Percentage (%)') ?></label>
                    <div class="input-group mb-2">
                        <input type="number" class="form-control rounded-start" id="discountValue" min="0" value="0">
                        <span class="input-group-text rounded-end" id="discountSuffix">%</span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap" id="discountPresets">
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="$('#discountValue').val(5)">5%</button>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="$('#discountValue').val(10)">10%</button>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="$('#discountValue').val(15)">15%</button>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="$('#discountValue').val(20)">20%</button>
                    </div>
                </div>
                
                <div class="p-0">
                    <div class="bg-light p-2 border-bottom">
                        <small class="fw-bold text-uppercase text-muted ms-2"><?= t('Select Products to Discount') ?></small>
                    </div>
                    <div id="discountProductList" class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;">
                        <!-- Products will be loaded here via JS -->
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-warning rounded-pill px-4 fw-bold" onclick="applyProductDiscount()"><?= t('Apply Discount') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Split Payment Modal -->
<div class="modal fade" id="splitPaymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-columns-gap me-2"></i><?= t('Split Payout Methods') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-primary border-0 rounded-4 d-flex justify-content-between align-items-center mb-4">
                    <span class="fw-bold"><?= t('TOTAL PAYABLE:') ?></span>
                    <h4 class="fw-bold mb-0" id="splitTotalDisplay"><?= htmlspecialchars($currency) ?> 0.00</h4>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted"><?= t('CASH AMOUNT') ?></label>
                        <div class="input-group">
                            <span class="input-group-text text-muted"><?= htmlspecialchars($currency) ?></span>
                            <input type="number" class="form-control split-amount" id="splitCash" value="0" oninput="calculateSplitRemaining()">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted"><?= t('MOBILE MONEY') ?></label>
                        <div class="input-group">
                            <span class="input-group-text text-muted"><?= htmlspecialchars($currency) ?></span>
                            <input type="number" class="form-control split-amount" id="splitMobile" value="0" oninput="calculateSplitRemaining()">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted"><?= t('BANK TRANSFER') ?></label>
                        <div class="input-group">
                            <span class="input-group-text text-muted"><?= htmlspecialchars($currency) ?></span>
                            <input type="number" class="form-control split-amount" id="splitBank" value="0" oninput="calculateSplitRemaining()">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted"><?= t('CARD / OTHER') ?></label>
                        <div class="input-group">
                            <span class="input-group-text text-muted"><?= htmlspecialchars($currency) ?></span>
                            <input type="number" class="form-control split-amount" id="splitCard" value="0" oninput="calculateSplitRemaining()">
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-3 rounded-4 bg-light d-flex justify-content-between align-items-center">
                    <span class="text-muted fw-bold small"><?= t('REMAINING BALANCE:') ?></span>
                    <h5 class="fw-bold mb-0" id="splitRemaining"><?= htmlspecialchars($currency) ?> 0.00</h5>
                </div>
            </div>
            <div class="modal-footer border-0 p-4 bg-light">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal"><?= t('Close') ?></button>
                <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" onclick="processSplitPayment()"><?= t('Confirm & Process') ?></button>
            </div>
        </div>
    </div>
</div>
