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
            'bank_name', 
            'account_name', 
            'account_number', 
            'swift_code', 
            'check_payable_to',
            'mpesa_paybill',
            'mpesa_account_no',
            'default_payment_terms'
        ];

        foreach ($allowed_fields as $field) {
            if (isset($_POST[$field])) {
                $value = trim($_POST[$field]);
                save_setting($field, $value);
            }
        }
        
        $success_msg = "Payment settings updated successfully!";
    } catch (Exception $e) {
        $error_msg = "Error updating settings: " . $e->getMessage();
    }
}

// Fetch current settings
$current_settings = [
    'bank_name' => get_setting('bank_name', ''),
    'account_name' => get_setting('account_name', ''),
    'account_number' => get_setting('account_number', ''),
    'swift_code' => get_setting('swift_code', ''),
    'check_payable_to' => get_setting('check_payable_to', ''),
    'mpesa_paybill' => get_setting('mpesa_paybill', ''),
    'mpesa_account_no' => get_setting('mpesa_account_no', ''),
    'default_payment_terms' => get_setting('default_payment_terms', 'Net 30')
];
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0"><i class="bi bi-wallet2"></i> Payment Settings</h2>
                    <p class="text-muted">Configure payment methods and banking details for invoices</p>
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
            <form method="POST">
                <!-- Bank Transfer Details -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white p-4 border-0">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-bank me-2 text-primary"></i>Bank Transfer Details</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="bank_name" class="form-label">Bank Name</label>
                                <input type="text" class="form-control" id="bank_name" name="bank_name" value="<?= htmlspecialchars($current_settings['bank_name']) ?>" placeholder="e.g. CRDB Bank">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="account_name" class="form-label">Account Name</label>
                                <input type="text" class="form-control" id="account_name" name="account_name" value="<?= htmlspecialchars($current_settings['account_name']) ?>" placeholder="e.g. Bejundas Financial Services">
                            </div>

                            <div class="col-md-6">
                                <label for="account_number" class="form-label">Account Number</label>
                                <input type="text" class="form-control" id="account_number" name="account_number" value="<?= htmlspecialchars($current_settings['account_number']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="swift_code" class="form-label">SWIFT / BIC Code</label>
                                <input type="text" class="form-control" id="swift_code" name="swift_code" value="<?= htmlspecialchars($current_settings['swift_code']) ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mobile Money & Checks -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white p-4 border-0">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-phone me-2 text-success"></i>Mobile Money & Checks</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="mpesa_paybill" class="form-label">M-Pesa / Tigo Pesa Paybill</label>
                                <input type="text" class="form-control" id="mpesa_paybill" name="mpesa_paybill" value="<?= htmlspecialchars($current_settings['mpesa_paybill']) ?>" placeholder="e.g. 123456">
                            </div>
                            
                            <div class="col-md-6">
                                <label for="mpesa_account_no" class="form-label">Account Number (Reference)</label>
                                <input type="text" class="form-control" id="mpesa_account_no" name="mpesa_account_no" value="<?= htmlspecialchars($current_settings['mpesa_account_no']) ?>" placeholder="If applicable">
                            </div>

                            <div class="col-12">
                                <label for="check_payable_to" class="form-label">Checks Payable To</label>
                                <input type="text" class="form-control" id="check_payable_to" name="check_payable_to" value="<?= htmlspecialchars($current_settings['check_payable_to']) ?>" placeholder="Name to appear on checks">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Terms -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white p-4 border-0">
                        <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-warning"></i>Default Terms</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row">
                            <div class="col-md-6">
                                <label for="default_payment_terms" class="form-label">Payment Terms</label>
                                <select class="form-select" id="default_payment_terms" name="default_payment_terms">
                                    <option value="Due on Receipt" <?= $current_settings['default_payment_terms'] == 'Due on Receipt' ? 'selected' : '' ?>>Due upon Receipt</option>
                                    <option value="Net 15" <?= $current_settings['default_payment_terms'] == 'Net 15' ? 'selected' : '' ?>>Net 15 Days</option>
                                    <option value="Net 30" <?= $current_settings['default_payment_terms'] == 'Net 30' ? 'selected' : '' ?>>Net 30 Days</option>
                                    <option value="Net 60" <?= $current_settings['default_payment_terms'] == 'Net 60' ? 'selected' : '' ?>>Net 60 Days</option>
                                </select>
                                <div class="form-text">Default due date calculation for new invoices.</div>
                            </div>
                        </div>

                        <div class="col-12 mt-4 text-end">
                            <button type="submit" class="btn btn-primary px-4 py-2">
                                <i class="bi bi-save me-2"></i>Save Payment Settings
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 bg-light mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Preview</h5>
                    <p class="small text-muted mb-3">How payment details might appear on invoices:</p>
                    
                    <div class="bg-white p-3 rounded-3 border small">
                        <p class="mb-1 text-uppercase text-muted fw-bold" style="font-size: 0.75rem;">Payment Information</p>
                        
                        <?php if (!empty($current_settings['bank_name'])): ?>
                            <div class="mb-2">
                                <strong>Bank Transfer:</strong><br>
                                Bank: <?= htmlspecialchars($current_settings['bank_name']) ?><br>
                                Name: <?= htmlspecialchars($current_settings['account_name']) ?><br>
                                Acc No: <?= htmlspecialchars($current_settings['account_number']) ?><br>
                                Swift: <?= htmlspecialchars($current_settings['swift_code']) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($current_settings['mpesa_paybill'])): ?>
                            <div class="mb-2">
                                <strong>Mobile Money:</strong><br>
                                Paybill: <?= htmlspecialchars($current_settings['mpesa_paybill']) ?><br>
                                Account: <?= htmlspecialchars($current_settings['mpesa_account_no']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($current_settings['check_payable_to'])): ?>
                            <div>
                                <strong>Checks:</strong> Payable to <?= htmlspecialchars($current_settings['check_payable_to']) ?>
                            </div>
                        <?php endif; ?>
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
