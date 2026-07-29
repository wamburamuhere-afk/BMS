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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        save_setting('pos_discount_type', $_POST['pos_discount_type'] ?? 'percentage');
        $success_msg = "POS settings updated successfully";
    } catch (Exception $e) {
        $error_msg = "Error updating POS settings: " . $e->getMessage();
    }
}

$pos_discount_type = get_setting('pos_discount_type', 'percentage');
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0"><i class="bi bi-cart"></i> POS Settings</h2>
            <p class="text-muted">Point of Sale configuration</p>
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

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-4 text-dark text-uppercase small">Discount Configuration</h6>
                    <form method="POST">
                        <div class="mb-3">
                            <label for="pos_discount_type" class="form-label">Discount Type Preference</label>
                            <select class="form-select" id="pos_discount_type" name="pos_discount_type">
                                <option value="percentage" <?= $pos_discount_type == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                <option value="fixed" <?= $pos_discount_type == 'fixed' ? 'selected' : '' ?>>Fixed Amount (Constant)</option>
                            </select>
                            <div class="form-text">Choose how discounts are applied in the POS interface (Percentage vs Constant Amount).</div>
                        </div>

                        <div class="mt-4 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_pos" class="btn btn-primary px-5">
                                <i class="bi bi-save me-2"></i> Save POS Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
