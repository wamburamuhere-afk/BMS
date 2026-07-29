<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Moved out of system_settings.php's "Color Setting" tab into its own page,
// grantable independently via Roles & Permissions (page_key 'color_settings').
autoEnforcePermission('color_settings');

require_once __DIR__ . '/../../../header.php';

$success_msg = '';
$error_msg = '';

// Color Settings (print template accent colors — Sales Side + Purchase Side)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $color_defaults = [
            // Purchase Side — Purchase Order's own Navy/Corporate/Banded family
            'print_template_color_po_navy'      => '#0f1f3d',
            'print_template_color_po_corporate' => '#000000',
            'print_template_color_po_banded'    => '#1f7ae0',
            // Purchase Side — Purchase Return's own Navy/Corporate/Banded family
            'print_template_color_pret_navy'      => '#0f1f3d',
            'print_template_color_pret_corporate' => '#000000',
            'print_template_color_pret_banded'    => '#1f7ae0',
            // Purchase Side — Debit Note's own Navy/Corporate/Banded family
            'print_template_color_dbn_navy'      => '#0f1f3d',
            'print_template_color_dbn_corporate' => '#000000',
            'print_template_color_dbn_banded'    => '#1f7ae0',
            // Purchase Side — RFQ's own letter-format family
            'print_template_color_rfq_striped' => '#d9601a',
            'print_template_color_rfq_minimal' => '#1a7ea8',
            'print_template_color_rfq_radiant' => '#e07b1e',
            // Purchase Side — Delivery Order's own family
            'print_template_color_do_manifest' => '#b45309',
            'print_template_color_do_convoy'   => '#374151',
            'print_template_color_do_waybill'  => '#0f766e',
            // Sales Side — Sales Order's own family
            'print_template_color_so_confirmation' => '#c8981f',
            'print_template_color_so_ledger'       => '#14213d',
            'print_template_color_so_studio'       => '#2b2b2b',
            // Sales Side — Quotation's own family
            'print_template_color_qt_noir'   => '#111111',
            'print_template_color_qt_meadow' => '#2f7d4f',
            'print_template_color_qt_terra'  => '#9c6b3e',
            // Sales Side — Invoice's own family
            'print_template_color_inv_summit' => '#12b5c9',
            'print_template_color_inv_wave'   => '#164a91',
            'print_template_color_inv_onyx'   => '#1c1c1c',
            // Sales Side — Delivery Note (Outbound)'s own family
            'print_template_color_dn_depot'   => '#e05a1c',
            'print_template_color_dn_transit' => '#1b5fa8',
            'print_template_color_dn_custody' => '#6b7c5e',
            // Sales Side — Credit Note's own family
            'print_template_color_cn_ledger'  => '#2F5D50',
            'print_template_color_cn_horizon' => '#1F5AA8',
            'print_template_color_cn_ember'   => '#B3402C',
            // Sales Side — Sales Return's own family (structures borrowed from
            // DN Outbound's Custody, Sales Order's Ledger, and Quotation's Meadow)
            'print_template_color_sr_intake'   => '#5f7052',
            'print_template_color_sr_register' => '#2c3e5c',
            'print_template_color_sr_meridian' => '#3f8f5f',
        ];

        foreach ($color_defaults as $field => $default) {
            if (!isset($_POST[$field])) continue;
            $value = trim($_POST[$field]);

            // Accent colors: must be a valid #rrggbb hex, otherwise keep the default
            // rather than let the print template's :root rule inherit something
            // unparseable from an unsanitised value.
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                $value = $default;
            }

            save_setting($field, $value);
        }
        $success_msg = "Color settings updated successfully";
    } catch (Exception $e) {
        $error_msg = "Error updating color settings: " . $e->getMessage();
    }
}
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0"><i class="bi bi-palette"></i> Color Setting</h2>
            <p class="text-muted">Print template accents</p>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= safe_output($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= safe_output($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form method="POST">
                <h6 class="fw-bold text-uppercase small text-muted mb-3"><i class="bi bi-cart-check me-1"></i> Sales Side</h6>

                <!-- Sales Order Print Template Colors (own family, unrelated to Quotation) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Sales Order Print Template Colors</h6>
                    <p class="text-muted small mb-2">Sales Order uses its own template family, visually distinct from Quotation even though both share the same data fields.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_so_confirmation" class="form-label">Confirmation Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_so_confirmation" name="print_template_color_so_confirmation" value="<?= get_setting('print_template_color_so_confirmation', '#c8981f') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_so_ledger" class="form-label">Ledger Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_so_ledger" name="print_template_color_so_ledger" value="<?= get_setting('print_template_color_so_ledger', '#14213d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_so_studio" class="form-label">Studio Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_so_studio" name="print_template_color_so_studio" value="<?= get_setting('print_template_color_so_studio', '#2b2b2b') ?>">
                    </div>
                </div>

                <!-- Quotation Print Template Colors (own family, unrelated to Sales Order) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Quotation Print Template Colors</h6>
                    <p class="text-muted small mb-2">Quotation uses its own template family, visually distinct from Sales Order even though both share the same data fields.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_qt_noir" class="form-label">Noir Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_qt_noir" name="print_template_color_qt_noir" value="<?= get_setting('print_template_color_qt_noir', '#111111') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_qt_meadow" class="form-label">Meadow Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_qt_meadow" name="print_template_color_qt_meadow" value="<?= get_setting('print_template_color_qt_meadow', '#2f7d4f') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_qt_terra" class="form-label">Terra Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_qt_terra" name="print_template_color_qt_terra" value="<?= get_setting('print_template_color_qt_terra', '#9c6b3e') ?>">
                    </div>
                </div>

                <!-- Invoice Print Template Colors (own family) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Invoice Print Template Colors</h6>
                    <p class="text-muted small mb-2">Invoice uses its own template family, separate from every other document.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_inv_summit" class="form-label">Summit Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_inv_summit" name="print_template_color_inv_summit" value="<?= get_setting('print_template_color_inv_summit', '#12b5c9') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_inv_wave" class="form-label">Wave Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_inv_wave" name="print_template_color_inv_wave" value="<?= get_setting('print_template_color_inv_wave', '#164a91') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_inv_onyx" class="form-label">Onyx Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_inv_onyx" name="print_template_color_inv_onyx" value="<?= get_setting('print_template_color_inv_onyx', '#1c1c1c') ?>">
                    </div>
                </div>

                <!-- Delivery Note (Outbound) Print Template Colors (own family) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Delivery Note (Outbound) Print Template Colors</h6>
                    <p class="text-muted small mb-2">Outbound Delivery Note uses its own template family, separate from every other document.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_dn_depot" class="form-label">Depot Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_dn_depot" name="print_template_color_dn_depot" value="<?= get_setting('print_template_color_dn_depot', '#e05a1c') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_dn_transit" class="form-label">Transit Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_dn_transit" name="print_template_color_dn_transit" value="<?= get_setting('print_template_color_dn_transit', '#1b5fa8') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_dn_custody" class="form-label">Custody Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_dn_custody" name="print_template_color_dn_custody" value="<?= get_setting('print_template_color_dn_custody', '#6b7c5e') ?>">
                    </div>
                </div>

                <!-- Credit Note Print Template Colors (own family) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Credit Note Print Template Colors</h6>
                    <p class="text-muted small mb-2">Credit Note uses its own template family, separate from every other document.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_cn_ledger" class="form-label">Ledger Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_cn_ledger" name="print_template_color_cn_ledger" value="<?= get_setting('print_template_color_cn_ledger', '#2F5D50') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_cn_horizon" class="form-label">Horizon Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_cn_horizon" name="print_template_color_cn_horizon" value="<?= get_setting('print_template_color_cn_horizon', '#1F5AA8') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_cn_ember" class="form-label">Ember Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_cn_ember" name="print_template_color_cn_ember" value="<?= get_setting('print_template_color_cn_ember', '#B3402C') ?>">
                    </div>
                </div>

                <!-- Sales Return Print Template Colors (own family) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Sales Return Print Template Colors</h6>
                    <p class="text-muted small mb-2">Sales Return uses its own template family, separate from every other document.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_sr_intake" class="form-label">Intake Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_sr_intake" name="print_template_color_sr_intake" value="<?= get_setting('print_template_color_sr_intake', '#5f7052') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_sr_register" class="form-label">Register Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_sr_register" name="print_template_color_sr_register" value="<?= get_setting('print_template_color_sr_register', '#2c3e5c') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_sr_meridian" class="form-label">Meridian Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_sr_meridian" name="print_template_color_sr_meridian" value="<?= get_setting('print_template_color_sr_meridian', '#3f8f5f') ?>">
                    </div>
                </div>

                <hr class="my-4">

                <h6 class="fw-bold text-uppercase small text-muted mb-3"><i class="bi bi-truck me-1"></i> Purchase Side</h6>

                <!-- Purchase Order Print Template Colors (own family, unrelated to Purchase Return / Debit Note) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Purchase Order Print Template Colors</h6>
                    <p class="text-muted small mb-2">Purchase Order uses its own Navy/Corporate/Banded colors, separate from Purchase Return and Debit Note even though all three share the same layout designs.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_po_navy" class="form-label">Navy Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_po_navy" name="print_template_color_po_navy" value="<?= get_setting('print_template_color_po_navy', '#0f1f3d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_po_corporate" class="form-label">Corporate Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_po_corporate" name="print_template_color_po_corporate" value="<?= get_setting('print_template_color_po_corporate', '#000000') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_po_banded" class="form-label">Banded Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_po_banded" name="print_template_color_po_banded" value="<?= get_setting('print_template_color_po_banded', '#1f7ae0') ?>">
                        <div class="form-text">Only the blue is configurable here; the orange section bands stay fixed.</div>
                    </div>
                </div>

                <!-- Purchase Return Print Template Colors (own family, unrelated to Purchase Order / Debit Note) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Purchase Return Print Template Colors</h6>
                    <p class="text-muted small mb-2">Purchase Return uses its own Navy/Corporate/Banded colors, separate from Purchase Order and Debit Note even though all three share the same layout designs.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_pret_navy" class="form-label">Navy Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_pret_navy" name="print_template_color_pret_navy" value="<?= get_setting('print_template_color_pret_navy', '#0f1f3d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_pret_corporate" class="form-label">Corporate Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_pret_corporate" name="print_template_color_pret_corporate" value="<?= get_setting('print_template_color_pret_corporate', '#000000') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_pret_banded" class="form-label">Banded Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_pret_banded" name="print_template_color_pret_banded" value="<?= get_setting('print_template_color_pret_banded', '#1f7ae0') ?>">
                        <div class="form-text">Only the blue is configurable here; the orange section bands stay fixed.</div>
                    </div>
                </div>

                <!-- Debit Note Print Template Colors (own family, unrelated to Purchase Order / Purchase Return) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Debit Note Print Template Colors</h6>
                    <p class="text-muted small mb-2">Debit Note uses its own Navy/Corporate/Banded colors, separate from Purchase Order and Purchase Return even though all three share the same layout designs.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_dbn_navy" class="form-label">Navy Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_dbn_navy" name="print_template_color_dbn_navy" value="<?= get_setting('print_template_color_dbn_navy', '#0f1f3d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_dbn_corporate" class="form-label">Corporate Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_dbn_corporate" name="print_template_color_dbn_corporate" value="<?= get_setting('print_template_color_dbn_corporate', '#000000') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_dbn_banded" class="form-label">Banded Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_dbn_banded" name="print_template_color_dbn_banded" value="<?= get_setting('print_template_color_dbn_banded', '#1f7ae0') ?>">
                        <div class="form-text">Only the blue is configurable here; the orange section bands stay fixed.</div>
                    </div>
                </div>

                <!-- RFQ Print Template Colors (own family, unrelated design) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> RFQ Print Template Colors</h6>
                    <p class="text-muted small mb-2">RFQ uses its own letter-format template family, separate from the layouts above.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_rfq_striped" class="form-label">Striped Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_rfq_striped" name="print_template_color_rfq_striped" value="<?= get_setting('print_template_color_rfq_striped', '#d9601a') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_rfq_minimal" class="form-label">Minimal Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_rfq_minimal" name="print_template_color_rfq_minimal" value="<?= get_setting('print_template_color_rfq_minimal', '#1a7ea8') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_rfq_radiant" class="form-label">Radiant Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_rfq_radiant" name="print_template_color_rfq_radiant" value="<?= get_setting('print_template_color_rfq_radiant', '#e07b1e') ?>">
                    </div>
                </div>

                <!-- Delivery Order Print Template Colors (own family) -->
                <div class="mb-3">
                    <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-palette2 me-1"></i> Delivery Order Print Template Colors</h6>
                    <p class="text-muted small mb-2">Delivery Order uses its own Manifest/Convoy/Waybill colors, separate from every other document.</p>
                </div>
                <div class="row g-4 mb-3">
                    <div class="col-md-4">
                        <label for="print_template_color_do_manifest" class="form-label">Manifest Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_do_manifest" name="print_template_color_do_manifest" value="<?= get_setting('print_template_color_do_manifest', '#b45309') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_do_convoy" class="form-label">Convoy Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_do_convoy" name="print_template_color_do_convoy" value="<?= get_setting('print_template_color_do_convoy', '#374151') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="print_template_color_do_waybill" class="form-label">Waybill Template</label>
                        <input type="color" class="form-control form-control-color w-100" id="print_template_color_do_waybill" name="print_template_color_do_waybill" value="<?= get_setting('print_template_color_do_waybill', '#0f766e') ?>">
                    </div>
                </div>

                <div class="mt-5 pt-3 border-top d-flex justify-content-end">
                    <button type="submit" name="save_colors" class="btn btn-primary px-5">
                        <i class="bi bi-save me-2"></i> Save Color Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
