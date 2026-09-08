<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Moved out of system_settings.php's "POS Settings" tab into its own page,
// grantable independently via Roles & Permissions (page_key 'pos_config_settings').
autoEnforcePermission('pos_config_settings');

require_once __DIR__ . '/../../../header.php';

$success_msg = '';
$error_msg = '';

// Phase 13 (pos_upgrade_plan.md §7) — multi-register management and the
// loyalty program are the 'pos_advanced' upsell tier on top of base POS.
$pos_advanced_entitled = canView('pos_advanced');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        save_setting('pos_discount_type', $_POST['pos_discount_type'] ?? 'percentage');
        // Phase 10 (pos_upgrade_plan.md §7) — receipt printing preferences.
        save_setting('pos_receipt_width', in_array($_POST['pos_receipt_width'] ?? '', ['58', '80'], true) ? $_POST['pos_receipt_width'] : '80');
        save_setting('pos_auto_print_receipt', isset($_POST['pos_auto_print_receipt']) ? '1' : '0');
        // Phase 11/13 — loyalty program settings only take effect for a
        // tenant actually entitled to 'pos_advanced'; silently ignored
        // otherwise so a raw POST can't turn it on without the entitlement.
        if ($pos_advanced_entitled) {
            save_setting('pos_loyalty_enabled', isset($_POST['pos_loyalty_enabled']) ? '1' : '0');
            save_setting('pos_loyalty_spend_per_point', max(1, (float)($_POST['pos_loyalty_spend_per_point'] ?? 1000)));
            save_setting('pos_loyalty_redeem_value', max(0, (float)($_POST['pos_loyalty_redeem_value'] ?? 50)));
        }
        $success_msg = t("POS settings updated successfully");
    } catch (Exception $e) {
        $error_msg = sprintf(t("Error updating POS settings: %s"), $e->getMessage());
    }
}

$pos_discount_type      = get_setting('pos_discount_type', 'percentage');
$pos_receipt_width      = get_setting('pos_receipt_width', '80');
$pos_auto_print_receipt = get_setting('pos_auto_print_receipt', '0');
$pos_loyalty_enabled          = get_setting('pos_loyalty_enabled', '0');
$pos_loyalty_spend_per_point  = get_setting('pos_loyalty_spend_per_point', '1000');
$pos_loyalty_redeem_value     = get_setting('pos_loyalty_redeem_value', '50');
$pos_currency = getSetting('currency', 'TZS');
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0"><i class="bi bi-cart"></i> <?= t('POS Settings') ?></h2>
            <p class="text-muted"><?= t('Point of Sale configuration') ?></p>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= safe_output($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= t('Close') ?>"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= safe_output($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= t('Close') ?>"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-4 text-dark text-uppercase small"><?= t('Discount Configuration') ?></h6>
                    <form method="POST">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <div class="mb-3">
                            <label for="pos_discount_type" class="form-label"><?= t('Discount Type Preference') ?></label>
                            <select class="form-select" id="pos_discount_type" name="pos_discount_type">
                                <option value="percentage" <?= $pos_discount_type == 'percentage' ? 'selected' : '' ?>><?= t('Percentage (%)') ?></option>
                                <option value="fixed" <?= $pos_discount_type == 'fixed' ? 'selected' : '' ?>><?= t('Fixed Amount (Constant)') ?></option>
                            </select>
                            <div class="form-text"><?= t('Choose how discounts are applied in the POS interface (Percentage vs Constant Amount).') ?></div>
                        </div>

                        <h6 class="fw-bold mb-3 mt-4 text-dark text-uppercase small"><?= t('Receipt Printing') ?></h6>
                        <div class="mb-3">
                            <label for="pos_receipt_width" class="form-label"><?= t('Receipt Paper Width') ?></label>
                            <select class="form-select" id="pos_receipt_width" name="pos_receipt_width">
                                <option value="80" <?= $pos_receipt_width == '80' ? 'selected' : '' ?>><?= t('80mm (standard thermal)') ?></option>
                                <option value="58" <?= $pos_receipt_width == '58' ? 'selected' : '' ?>><?= t('58mm (compact thermal)') ?></option>
                            </select>
                            <div class="form-text"><?= t("Match your receipt printer's paper roll width.") ?></div>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="pos_auto_print_receipt" name="pos_auto_print_receipt" value="1" <?= $pos_auto_print_receipt == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pos_auto_print_receipt"><?= t('Automatically open and print the receipt when a sale completes') ?></label>
                            <div class="form-text"><?= t('Sends the receipt straight to your browser\'s print dialog / default printer — no "Print Receipt" click needed. Requires a printer already set as your OS/browser default.') ?></div>
                        </div>

                        <?php if ($pos_advanced_entitled): ?>
                        <h6 class="fw-bold mb-3 mt-4 text-dark text-uppercase small"><?= t('Loyalty Program') ?></h6>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="pos_loyalty_enabled" name="pos_loyalty_enabled" value="1" <?= $pos_loyalty_enabled == '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="pos_loyalty_enabled"><?= t('Enable customer loyalty points') ?></label>
                            <div class="form-text"><?= t('Registered customers (not walk-ins) earn points on every sale and can redeem them for a discount at checkout.') ?></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="pos_loyalty_spend_per_point" class="form-label"><?= t('Points Earned') ?></label>
                                <div class="input-group">
                                    <span class="input-group-text"><?= t('1 pt per') ?></span>
                                    <input type="number" class="form-control" id="pos_loyalty_spend_per_point" name="pos_loyalty_spend_per_point" min="1" step="1" value="<?= safe_output($pos_loyalty_spend_per_point) ?>">
                                    <span class="input-group-text"><?= htmlspecialchars($pos_currency) ?> <?= t('spent') ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="pos_loyalty_redeem_value" class="form-label"><?= t('Redemption Value') ?></label>
                                <div class="input-group">
                                    <span class="input-group-text"><?= t('1 pt =') ?></span>
                                    <input type="number" class="form-control" id="pos_loyalty_redeem_value" name="pos_loyalty_redeem_value" min="0" step="0.01" value="<?= safe_output($pos_loyalty_redeem_value) ?>">
                                    <span class="input-group-text"><?= htmlspecialchars($pos_currency) ?> <?= t('off') ?></span>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <h6 class="fw-bold mb-3 mt-4 text-dark text-uppercase small"><?= t('Loyalty Program') ?></h6>
                        <div class="alert alert-light border small mb-0">
                            <i class="bi bi-lock me-1"></i> <?= sprintf(t('Loyalty points are part of %s — not included in your current plan.'), '<strong>' . t('POS Advanced') . '</strong>') ?>
                        </div>
                        <?php endif; ?>

                        <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_pos" class="btn btn-primary px-5">
                                <i class="bi bi-save me-2"></i> <?= t('Save POS Settings') ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($pos_advanced_entitled): ?>
    <!-- Registers / Tills — Phase 8 (pos_upgrade_plan.md §7) -->
    <div class="row mt-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 class="fw-bold mb-0 text-dark text-uppercase small"><?= t('Registers / Tills') ?></h6>
                        <button class="btn btn-sm btn-primary" onclick="openRegisterModal()">
                            <i class="bi bi-plus-circle me-1"></i> <?= t('Add Register') ?>
                        </button>
                    </div>
                    <p class="text-muted small"><?= t('Each till a cashier signs in at — its own receipt branding and cash-drawer reconciliation. Registers are never deleted (past shifts reference them), only deactivated.') ?></p>
                    <div id="registersTableWrap" class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr><th><?= t('Name') ?></th><th><?= t('Code') ?></th><th><?= t('Location') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('Actions') ?></th></tr>
                            </thead>
                            <tbody id="registersTableBody">
                                <tr><td colspan="5" class="text-center text-muted py-3"><?= t('Loading...') ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="row mt-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3 text-dark text-uppercase small"><?= t('Registers / Tills') ?></h6>
                    <div class="alert alert-light border small mb-0">
                        <i class="bi bi-lock me-1"></i> <?= sprintf(t('Multi-register (multi-till) management is part of %s — not included in your current plan. You still have one default register/till to sign in at.'), '<strong>' . t('POS Advanced') . '</strong>') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($pos_advanced_entitled): ?>
<!-- Add/Edit Register Modal -->
<div class="modal fade" id="registerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="registerModalTitle"><i class="bi bi-shop me-1"></i> <?= t('Add Register') ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="reg_register_id" value="0">
                <div class="mb-3">
                    <label class="form-label"><?= t('Register Name') ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="reg_register_name" placeholder="<?= t('e.g. Main Counter') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('Register Code') ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="reg_register_code" placeholder="<?= t('e.g. REG-002') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('Location') ?></label>
                    <input type="text" class="form-control" id="reg_location" placeholder="<?= t('e.g. Shop Front') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('Default Opening Cash') ?></label>
                    <input type="number" class="form-control" id="reg_opening_cash" min="0" step="0.01" value="0">
                </div>
                <div class="mb-3 d-flex gap-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="reg_barcode_scanner" checked>
                        <label class="form-check-label" for="reg_barcode_scanner"><?= t('Barcode Scanner') ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="reg_cash_drawer" checked>
                        <label class="form-check-label" for="reg_cash_drawer"><?= t('Cash Drawer') ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="reg_card_reader">
                        <label class="form-check-label" for="reg_card_reader"><?= t('Card Reader') ?></label>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('Receipt Header (optional override)') ?></label>
                    <textarea class="form-control" id="reg_receipt_header" rows="2" placeholder="<?= t('Leave blank to use the company header only') ?>"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?= t('Receipt Footer (optional addition)') ?></label>
                    <textarea class="form-control" id="reg_receipt_footer" rows="2" placeholder="<?= t('e.g. branch-specific note') ?>"></textarea>
                </div>
                <!-- Phase 22 (pos_upgrade_plan.md §8) — receipt layout variety -->
                <div class="mb-3">
                    <label class="form-label"><?= t('Receipt Layout') ?></label>
                    <select class="form-select" id="reg_receipt_template">
                        <option value="classic"><?= t('Classic (default)') ?></option>
                        <option value="detailed"><?= t('Detailed (shows per-line discount/tax)') ?></option>
                        <option value="slim"><?= t('Slim (totals only)') ?></option>
                    </select>
                </div>
                <hr>
                <!-- Phase 21 (pos_upgrade_plan.md §8) — network (IP) thermal printer -->
                <div class="mb-3">
                    <label class="form-label"><?= t('Receipt Printer Connection') ?></label>
                    <select class="form-select" id="reg_printer_connection_type" onchange="$('#reg_printer_ip_wrap').toggleClass('d-none', this.value !== 'network')">
                        <option value="browser"><?= t('Browser (print dialog)') ?></option>
                        <option value="network"><?= t('Network (IP) thermal printer') ?></option>
                    </select>
                    <small class="text-muted"><?= t('Network mode sends the receipt directly to a printer with its own IP address on your shop network — no print dialog, real auto-cut and drawer-kick.') ?></small>
                </div>
                <div class="d-none" id="reg_printer_ip_wrap">
                    <div class="row g-2 mb-3">
                        <div class="col-8">
                            <label class="form-label"><?= t('Printer IP Address') ?></label>
                            <input type="text" class="form-control" id="reg_printer_ip_address" placeholder="<?= t('e.g. 192.168.1.50') ?>">
                        </div>
                        <div class="col-4">
                            <label class="form-label"><?= t('Port') ?></label>
                            <input type="number" class="form-control" id="reg_printer_port" value="9100">
                        </div>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testNetworkPrinter()">
                        <i class="bi bi-printer"></i> <?= t('Test Printer') ?>
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-primary" onclick="saveRegister()"><i class="bi bi-check-circle me-1"></i> <?= t('Save') ?></button>
            </div>
        </div>
    </div>
</div>

<script>
let registersCache = [];

// This page doesn't include a global safeOutput() helper — it's a per-page
// local convention elsewhere in the codebase (e.g. app/bms/customer/customers.php),
// not something header.php defines. Defined here so loadRegisters() below
// doesn't throw a ReferenceError the moment a register name/code renders.
function safeOutput(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}

const T_REG_NO_REGISTERS = <?= json_encode(t('No registers yet')) ?>;
const T_REG_DEACTIVATE   = <?= json_encode(t('Deactivate')) ?>;
const T_REG_ACTIVATE     = <?= json_encode(t('Activate')) ?>;
const T_REG_EDIT         = <?= json_encode(t('Edit')) ?>;

function loadRegisters() {
    $.getJSON('<?= buildUrl('api/pos/get_registers.php') ?>', function (res) {
        const tbody = $('#registersTableBody');
        if (!res.success || !res.data.length) {
            tbody.html('<tr><td colspan="5" class="text-center text-muted py-3">' + T_REG_NO_REGISTERS + '</td></tr>');
            return;
        }
        registersCache = res.data;
        let html = '';
        res.data.forEach(r => {
            const badge = r.status === 'active' ? 'success' : 'secondary';
            const toggleLabel = r.status === 'active' ? T_REG_DEACTIVATE : T_REG_ACTIVATE;
            const toggleIcon = r.status === 'active' ? 'bi-x-circle' : 'bi-check-circle';
            html += `<tr>
                <td>${safeOutput(r.register_name)}</td>
                <td>${safeOutput(r.register_code)}</td>
                <td>${safeOutput(r.location)}</td>
                <td><span class="badge bg-${badge}">${safeOutput(r.status)}</span></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary" onclick="editRegister(${r.register_id})" title="${T_REG_EDIT}"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleRegister(${r.register_id}, '${r.status === 'active' ? 'inactive' : 'active'}')" title="${toggleLabel}"><i class="bi ${toggleIcon}"></i></button>
                </td>
            </tr>`;
        });
        tbody.html(html);
    });
}

function openRegisterModal() {
    $('#registerModalTitle').html('<i class="bi bi-shop me-1"></i> ' + <?= json_encode(t('Add Register')) ?>);
    $('#reg_register_id').val(0);
    $('#reg_register_name, #reg_register_code, #reg_location, #reg_receipt_header, #reg_receipt_footer').val('');
    $('#reg_opening_cash').val(0);
    $('#reg_barcode_scanner, #reg_cash_drawer').prop('checked', true);
    $('#reg_card_reader').prop('checked', false);
    $('#reg_printer_connection_type').val('browser');
    $('#reg_printer_ip_address').val('');
    $('#reg_printer_port').val(9100);
    $('#reg_printer_ip_wrap').addClass('d-none');
    $('#reg_receipt_template').val('classic');
    new bootstrap.Modal(document.getElementById('registerModal')).show();
}

function editRegister(id) {
    const r = registersCache.find(x => x.register_id == id);
    if (!r) return;
    $('#registerModalTitle').html('<i class="bi bi-pencil me-1"></i> ' + <?= json_encode(t('Edit Register')) ?>);
    $('#reg_register_id').val(r.register_id);
    $('#reg_register_name').val(r.register_name);
    $('#reg_register_code').val(r.register_code);
    $('#reg_location').val(r.location || '');
    $('#reg_opening_cash').val(r.opening_cash || 0);
    $('#reg_barcode_scanner').prop('checked', !!parseInt(r.barcode_scanner));
    $('#reg_cash_drawer').prop('checked', !!parseInt(r.cash_drawer));
    $('#reg_card_reader').prop('checked', !!parseInt(r.card_reader));
    $('#reg_receipt_header').val(r.receipt_header || '');
    $('#reg_receipt_footer').val(r.receipt_footer || '');
    $('#reg_printer_connection_type').val(r.printer_connection_type || 'browser');
    $('#reg_printer_ip_address').val(r.printer_ip_address || '');
    $('#reg_printer_port').val(r.printer_port || 9100);
    $('#reg_printer_ip_wrap').toggleClass('d-none', (r.printer_connection_type || 'browser') !== 'network');
    $('#reg_receipt_template').val(r.receipt_template || 'classic');
    new bootstrap.Modal(document.getElementById('registerModal')).show();
}

function testNetworkPrinter() {
    const ip = $('#reg_printer_ip_address').val().trim();
    const port = $('#reg_printer_port').val() || 9100;
    if (!ip) {
        Swal.fire(<?= json_encode(t('Missing fields')) ?>, <?= json_encode(t('Enter a printer IP address first.')) ?>, 'warning');
        return;
    }
    Swal.fire({ title: <?= json_encode(t('Testing...')) ?>, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
    $.post('<?= buildUrl('api/pos/test_network_printer.php') ?>', {
        printer_ip_address: ip, printer_port: port, _csrf: <?= json_encode(csrf_token()) ?>
    }, function (res) {
        Swal.fire({ icon: res.success ? 'success' : 'error', title: res.success ? <?= json_encode(t('Success')) ?> : <?= json_encode(t('Error')) ?>, text: res.message });
    }, 'json');
}

function saveRegister() {
    const name = $('#reg_register_name').val().trim();
    const code = $('#reg_register_code').val().trim();
    if (!name || !code) {
        Swal.fire(<?= json_encode(t('Missing fields')) ?>, <?= json_encode(t('Register name and code are required.')) ?>, 'warning');
        return;
    }
    $.post('<?= buildUrl('api/pos/save_register.php') ?>', {
        register_id: $('#reg_register_id').val(),
        register_name: name,
        register_code: code,
        location: $('#reg_location').val(),
        opening_cash: $('#reg_opening_cash').val(),
        barcode_scanner: $('#reg_barcode_scanner').is(':checked') ? 1 : 0,
        cash_drawer: $('#reg_cash_drawer').is(':checked') ? 1 : 0,
        card_reader: $('#reg_card_reader').is(':checked') ? 1 : 0,
        receipt_header: $('#reg_receipt_header').val(),
        receipt_footer: $('#reg_receipt_footer').val(),
        printer_connection_type: $('#reg_printer_connection_type').val(),
        printer_ip_address: $('#reg_printer_ip_address').val(),
        printer_port: $('#reg_printer_port').val(),
        receipt_template: $('#reg_receipt_template').val(),
        _csrf: <?= json_encode(csrf_token()) ?>
    }, function (res) {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('registerModal')).hide();
            Swal.fire({ icon: 'success', title: <?= json_encode(t('Saved')) ?>, text: res.message, timer: 1800, showConfirmButton: false });
            loadRegisters();
        } else {
            Swal.fire(<?= json_encode(t('Error')) ?>, res.message, 'error');
        }
    }, 'json');
}

function toggleRegister(id, newStatus) {
    Swal.fire({
        title: newStatus === 'inactive' ? <?= json_encode(t('Deactivate register?')) ?> : <?= json_encode(t('Activate register?')) ?>,
        icon: 'warning', showCancelButton: true, confirmButtonText: <?= json_encode(t('Yes')) ?>
    }).then(r => {
        if (!r.isConfirmed) return;
        $.post('<?= buildUrl('api/pos/toggle_register_status.php') ?>', { register_id: id, status: newStatus }, function (res) {
            if (res.success) { loadRegisters(); } else { Swal.fire(<?= json_encode(t('Error')) ?>, res.message, 'error'); }
        }, 'json');
    });
}

$(document).ready(function () { loadRegisters(); });
</script>
<?php endif; // $pos_advanced_entitled ?>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
