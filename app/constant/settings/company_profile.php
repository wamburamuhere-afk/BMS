<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Phase 2 of security_implementation_plan.md — page-level gate. Admin
// can now grant 'company_profile' to other roles via /user_roles.php.
autoEnforcePermission('company_profile');

require_once __DIR__ . '/../../../header.php';

// Check admin permissions
if (!isAdmin()) {
    header("Location: unauthorized.php");
    exit();
}

// Initialize variables
$success_msg = $_SESSION['success_msg'] ?? '';
$error_msg = $_SESSION['error_msg'] ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);

// Default settings if not in DB
$default_settings = [
    'company_name' => 'My Company',
    'company_email' => 'info@example.com',
    'company_phone' => '',
    'company_address' => '',
    'company_website' => '',
    'company_currency' => 'TZS',
    'company_logo' => '',
    'company_tin' => '',
    'company_vrn' => '',
    'company_code_prefix' => '',
    'company_postal_address' => '',
    'company_physical_address' => '',
    // Equity setting used by the Balance Sheet report.
    'share_capital_paid_in' => '0'
];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $allowed_fields = [
            'company_name', 'company_email', 'company_phone',
            'company_website', 'company_currency',
            'company_tin', 'company_vrn', 'company_code_prefix',
            'company_postal_address', 'company_physical_address',
            'share_capital_paid_in',
        ];

        foreach ($allowed_fields as $field) {
            if (isset($_POST[$field])) {
                $value = trim($_POST[$field]);

                // Document-code prefix: letters only, uppercase, 2–5 chars.
                if ($field === 'company_code_prefix') {
                    $value = strtoupper(preg_replace('/[^A-Za-z]/', '', $value));
                    $value = substr($value, 0, 5);
                }

                // Check if setting exists
                $check = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE setting_key = ?");
                $check->execute([$field]);
                $exists = $check->fetchColumn();

                if ($exists) {
                    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?");
                    $stmt->execute([$value, $field]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public) VALUES (?, ?, 'company', 1)");
                    $stmt->execute([$field, $value]);
                }
            }
        }

        // Handle Logo Upload
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../../uploads/system/logo/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            $fileInfo = pathinfo($_FILES['company_logo']['name']);
            $extension = strtolower($fileInfo['extension']);
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($extension, $allowedExtensions)) {
                $newFileName = 'company_logo.' . $extension;
                $targetFile = $uploadDir . $newFileName;

                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $targetFile)) {
                    // Update DB with relative path
                    $logoPath = 'uploads/system/logo/' . $newFileName;
                    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, setting_group, is_public) VALUES ('company_logo', ?, 'company', 1) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$logoPath, $logoPath]);
                } else {
                    $error_msg = "Failed to upload logo.";
                }
            } else {
                $error_msg = "Invalid file type. Only JPG, PNG, and GIF allowed.";
            }
        }

        $pdo->commit();
        $_SESSION['success_msg'] = "Company profile updated successfully!";
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = "Error creating/updating profile: " . $e->getMessage();
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    }
}

// Fetch current settings
$current_settings = $default_settings;
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_group IN ('company', 'general')");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (array_key_exists($row['setting_key'], $default_settings)) {
            $current_settings[$row['setting_key']] = $row['setting_value'];
        }
        // Also map some general settings if they match key names
        if ($row['setting_key'] == 'company_name') $current_settings['company_name'] = $row['setting_value'];
        if ($row['setting_key'] == 'company_email') $current_settings['company_email'] = $row['setting_value'];
        if ($row['setting_key'] == 'company_currency') $current_settings['company_currency'] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Silent error, use defaults
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0"><i class="bi bi-building"></i> Company Profile</h2>
                    <p class="text-muted">Manage your organization's details and branding</p>
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
                <div class="card-header bg-white p-4 border-0">
                    <h5 class="mb-0 fw-bold">General Information</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row g-4">
                            <!-- Logo Upload -->
                            <div class="col-12 mb-3">
                                <label class="form-label fw-bold">Company Logo</label>
                                <div class="d-flex align-items-center gap-4">
                                    <div class="bg-light rounded-3 d-flex align-items-center justify-content-center" style="width: 100px; height: 100px; overflow: hidden; border: 2px dashed #dee2e6;">
                                        <?php if (!empty($current_settings['company_logo'])): ?>
                                            <img src="<?= htmlspecialchars('../../../' . $current_settings['company_logo']) ?>" alt="Logo" class="img-fluid" style="max-height: 100%; width: auto;">
                                        <?php else: ?>
                                            <i class="bi bi-image text-muted fs-2"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <input type="file" class="form-control" name="company_logo" accept="image/*">
                                        <div class="form-text">Recommended size: 200x200px. Max size: 2MB. Formats: PNG, JPG.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="company_name" class="form-label">Company Name *</label>
                                <input type="text" class="form-control" id="company_name" name="company_name" value="<?= htmlspecialchars($current_settings['company_name']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="company_email" class="form-label">Company Email *</label>
                                <input type="email" class="form-control" id="company_email" name="company_email" value="<?= htmlspecialchars($current_settings['company_email']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="company_code_prefix" class="form-label">
                                    Document Code Prefix
                                    <i class="bi bi-info-circle text-muted" title="The 3-letter tag that starts every auto-generated code (invoices, POs, items, customers...). Auto-suggested from the company name; you can override it."></i>
                                </label>
                                <div class="input-group">
                                    <input type="text" class="form-control text-uppercase fw-bold" id="company_code_prefix" name="company_code_prefix"
                                           value="<?= htmlspecialchars($current_settings['company_code_prefix']) ?>"
                                           maxlength="5" style="max-width:120px" placeholder="e.g. BFS">
                                    <button type="button" class="btn btn-outline-secondary" id="suggestPrefixBtn" title="Suggest from company name">
                                        <i class="bi bi-magic"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    New codes will look like <code id="prefixPreview">BFS-INV-0001</code>.
                                    Existing documents keep their old code unless re-saved while still editable.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="company_phone" class="form-label">Phone Number</label>
                                <input type="text" class="form-control" id="company_phone" name="company_phone" value="<?= htmlspecialchars($current_settings['company_phone']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="company_website" class="form-label">Website</label>
                                <input type="url" class="form-control" id="company_website" name="company_website" value="<?= htmlspecialchars($current_settings['company_website']) ?>" placeholder="https://">
                            </div>

                            <div class="col-md-6">
                                <label for="company_postal_address" class="form-label">Postal Address</label>
                                <input type="text" class="form-control" id="company_postal_address" name="company_postal_address" value="<?= htmlspecialchars($current_settings['company_postal_address']) ?>" placeholder="e.g. P.O. Box 123, Machame">
                            </div>

                            <div class="col-md-6">
                                <label for="company_physical_address" class="form-label">Physical Address</label>
                                <input type="text" class="form-control" id="company_physical_address" name="company_physical_address" value="<?= htmlspecialchars($current_settings['company_physical_address']) ?>" placeholder="e.g. Moshi-Kilimanjaro">
                            </div>
                            

                            <div class="col-md-6">
                                <label for="company_currency" class="form-label">Currency Code</label>
                                <input type="text" class="form-control" id="company_currency" name="company_currency" value="<?= htmlspecialchars($current_settings['company_currency']) ?>" placeholder="e.g. TZS, USD">
                            </div>

                            <div class="col-md-6">
                                <label for="company_tin" class="form-label">TIN</label>
                                <input type="text" class="form-control" id="company_tin" name="company_tin" value="<?= htmlspecialchars($current_settings['company_tin']) ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="company_vrn" class="form-label">VRN</label>
                                <input type="text" class="form-control" id="company_vrn" name="company_vrn" value="<?= htmlspecialchars($current_settings['company_vrn']) ?>">
                            </div>

                            <!-- Equity (Balance Sheet) -->
                            <div class="col-12 mt-3">
                                <h6 class="text-muted text-uppercase small fw-bold mt-3"><i class="bi bi-cash-stack me-1"></i> Equity</h6>
                            </div>
                            <div class="col-md-6">
                                <label for="share_capital_paid_in" class="form-label">Share Capital (Paid-up, TZS)</label>
                                <input type="number" step="0.01" min="0" class="form-control" id="share_capital_paid_in" name="share_capital_paid_in" value="<?= htmlspecialchars($current_settings['share_capital_paid_in']) ?>">
                                <small class="text-muted">Owner's paid-in capital. Used by the Balance Sheet Equity section.</small>
                            </div>

                            <div class="col-12 mt-4">
                                <button type="submit" class="btn btn-primary px-4 py-2">
                                    <i class="bi bi-save me-2"></i>Save Changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3"><i class="bi bi-info-circle text-primary me-2"></i>About</h5>
                    <p class="text-muted small">
                        This information will be used across the system, including:
                    </p>
                    <ul class="text-muted small mb-0">
                        <li class="mb-2">Invoices and Receipts headers</li>
                        <li class="mb-2">Email notifications signatures</li>
                        <li class="mb-2">Reports and exported documents</li>
                        <li>System branding</li>
                    </ul>
                </div>
            </div>
            
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white">
                <div class="card-body p-4 position-relative overflow-hidden">
                    <div class="position-relative z-1">
                        <h5 class="fw-bold mb-2">Need Help?</h5>
                        <p class="small opacity-75 mb-0">Contact system administrator if you need dynamic changes to core system configurations.</p>
                    </div>
                    <i class="bi bi-headset position-absolute bottom-0 end-0 opacity-25" style="font-size: 5rem; transform: translate(10%, 20%);"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Mirror of deriveCompanyPrefix() in core/code_generator.php:
// drop parenthetical noise like "(T)", take the first letter of the first three
// remaining words, uppercase.
function derivePrefix(name) {
    const cleaned = (name || '').replace(/\([^)]*\)/g, ' ');
    const words = cleaned.split(/[^A-Za-z0-9]+/).filter(Boolean);
    let letters = '';
    for (const w of words) { letters += w[0].toUpperCase(); if (letters.length >= 3) break; }
    return letters.slice(0, 3);
}

(function () {
    const nameEl    = document.getElementById('company_name');
    const prefixEl  = document.getElementById('company_code_prefix');
    const previewEl = document.getElementById('prefixPreview');
    const suggestBtn= document.getElementById('suggestPrefixBtn');
    if (!prefixEl) return;

    function refreshPreview() {
        const p = (prefixEl.value || derivePrefix(nameEl.value) || 'CMP').toUpperCase();
        previewEl.textContent = p + '-INV-0001';
    }
    // Keep the field uppercase / letters-only as the user types.
    prefixEl.addEventListener('input', function () {
        prefixEl.value = prefixEl.value.replace(/[^A-Za-z]/g, '').toUpperCase();
        refreshPreview();
    });
    // If the prefix is still blank, follow the company name live.
    nameEl.addEventListener('input', function () {
        if (!prefixEl.value) refreshPreview();
    });
    suggestBtn.addEventListener('click', function () {
        prefixEl.value = derivePrefix(nameEl.value);
        refreshPreview();
    });
    refreshPreview();
})();
</script>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
