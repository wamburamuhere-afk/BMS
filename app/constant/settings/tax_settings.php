<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';
require_once __DIR__ . '/../../../header.php';

// Check admin permissions
if (!isAdmin()) {
    header("Location: unauthorized.php");
    exit();
}

$success_msg = '';
$error_msg = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $allowed_fields = [
            'tax_name', 
            'tax_rate', 
            'tax_number', 
            'enable_tax',
            'tax_type' // e.g. Exclusive vs Inclusive
        ];

        foreach ($allowed_fields as $field) {
            if (isset($_POST[$field])) {
                $value = trim($_POST[$field]);
                save_setting($field, $value);
            }
        }
        
        $success_msg = "Tax settings updated successfully!";
    } catch (Exception $e) {
        $error_msg = "Error updating settings: " . $e->getMessage();
    }
}

// Fetch current settings
$current_settings = [
    'tax_name' => get_setting('tax_name', 'VAT'),
    'tax_rate' => get_setting('tax_rate', '18.00'),
    'tax_number' => get_setting('tax_number', ''),
    'enable_tax' => get_setting('enable_tax', '0'),
    'tax_type' => get_setting('tax_type', 'exclusive')
];
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0"><i class="bi bi-percent"></i> Tax Settings</h2>
                    <p class="text-muted">Configure default tax rates and rules for invoices and products</p>
                </div>
            </div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= $success_msg ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error_msg ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-4">
                    <form method="POST">
                        
                        <div class="mb-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="enable_tax" name="enable_tax" value="1" <?= $current_settings['enable_tax'] == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-bold" for="enable_tax">Enable Tax Calculation</label>
                            </div>
                            <small class="text-muted">If disabled, tax will not be applied to new transactions by default.</small>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="tax_name" class="form-label">Tax Name</label>
                                <input type="text" class="form-control" id="tax_name" name="tax_name" value="<?= htmlspecialchars($current_settings['tax_name']) ?>" placeholder="e.g. VAT, GST">
                                <div class="form-text">Will be displayed on invoices (e.g. "VAT 18%")</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="tax_rate" class="form-label">Default Tax Rate (%)</label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" max="100" class="form-control" id="tax_rate" name="tax_rate" value="<?= htmlspecialchars($current_settings['tax_rate']) ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="tax_number" class="form-label">Tax Identification Number</label>
                                <input type="text" class="form-control" id="tax_number" name="tax_number" value="<?= htmlspecialchars($current_settings['tax_number']) ?>" placeholder="e.g. 123-456-789">
                            </div>

                            <div class="col-md-6">
                                <label for="tax_type" class="form-label">Default Pricing Method</label>
                                <select class="form-select" id="tax_type" name="tax_type">
                                    <option value="exclusive" <?= $current_settings['tax_type'] == 'exclusive' ? 'selected' : '' ?>>Tax Exclusive (Price + Tax)</option>
                                    <option value="inclusive" <?= $current_settings['tax_type'] == 'inclusive' ? 'selected' : '' ?>>Tax Inclusive (Price includes Tax)</option>
                                </select>
                                <div class="form-text">Determines how product prices are treated by default.</div>
                            </div>

                            <div class="col-12 mt-4">
                                <button type="submit" class="btn btn-primary px-4 py-2">
                                    <i class="bi bi-save me-2"></i>Save Settings
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 bg-light mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-calculator text-primary me-2"></i>Example Calculation</h5>
                    <p class="small text-muted mb-3">Based on current rate of <strong><?= $current_settings['tax_rate'] ?>%</strong>:</p>
                    
                    <div class="bg-white p-3 rounded-3 mb-3 border">
                        <h6 class="fw-bold small text-uppercase text-muted">Exclusive (Price + Tax)</h6>
                        <div class="d-flex justify-content-between small">
                            <span>Item Price:</span>
                            <span>1,000.00</span>
                        </div>
                        <div class="d-flex justify-content-between small text-danger">
                            <span>+ Tax:</span>
                            <span><?= number_format(1000 * ($current_settings['tax_rate']/100), 2) ?></span>
                        </div>
                        <div class="border-top mt-1 pt-1 d-flex justify-content-between fw-bold">
                            <span>Total:</span>
                            <span><?= number_format(1000 * (1 + $current_settings['tax_rate']/100), 2) ?></span>
                        </div>
                    </div>

                    <div class="bg-white p-3 rounded-3 border">
                        <h6 class="fw-bold small text-uppercase text-muted">Inclusive (Price includes Tax)</h6>
                        <div class="d-flex justify-content-between small">
                            <span>Total Price:</span>
                            <span>1,000.00</span>
                        </div>
                        <div class="d-flex justify-content-between small text-danger">
                            <span>Includes Tax:</span>
                            <span>
                            <?php 
                                $rate = $current_settings['tax_rate'];
                                $tax_amount = 1000 - (1000 / (1 + ($rate/100)));
                                echo number_format($tax_amount, 2);
                            ?>
                            </span>
                        </div>
                        <div class="border-top mt-1 pt-1 d-flex justify-content-between small text-muted">
                            <span>Net Price:</span>
                            <span><?= number_format(1000 - $tax_amount, 2) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
require_once __DIR__ . '/../../../footer.php';
ob_end_flush(); 
?>
