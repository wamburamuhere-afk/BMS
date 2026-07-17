<?php
require_once __DIR__ . '/../../../roots.php';

// Phase 2 of security_implementation_plan.md — canonical gate. The
// existing hasPermission() check below stays as a second layer.
autoEnforcePermission('system_settings');

require_once 'header.php';

// Check permissions
if (!hasPermission('system_settings')) {
    header('Location: unauthorized.php');
    exit;
}

// Handle form submissions
if ($_POST) {
    $success_messages = [];
    $error_messages = [];
    
    // General Settings
    if (isset($_POST['save_general'])) {
        try {
            $settings = [
                'company_name' => $_POST['company_name'] ?? '',
                'company_address' => $_POST['company_address'] ?? '',
                'company_phone' => $_POST['company_phone'] ?? '',
                'company_email' => $_POST['company_email'] ?? '',
                'company_website' => $_POST['company_website'] ?? '',
                'company_type' => $_POST['company_type'] ?? '',
                'currency' => $_POST['currency'] ?? '',
                'timezone' => $_POST['timezone'] ?? '',
                'date_format' => $_POST['date_format'] ?? '',
                'items_per_page' => $_POST['items_per_page'] ?? '',
                'enable_projects' => $_POST['enable_projects'] ?? 0
            ];
            
            // Handle Logo Upload
            if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['company_logo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = ROOT_DIR . '/uploads/system/logo/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_extension = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
                    $allowed_extensions = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
                    
                    if (in_array($file_extension, $allowed_extensions)) {
                        $file_name = 'logo_' . time() . '.' . $file_extension;
                        $target_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $target_path)) {
                            // Delete old logo
                            $old_logo = get_setting('company_logo');
                            if ($old_logo) {
                                $old_path = ROOT_DIR . '/' . ltrim($old_logo, '/');
                                if (file_exists($old_path)) {
                                    unlink($old_path);
                                }
                            }
                            $settings['company_logo'] = 'uploads/system/logo/' . $file_name;
                        } else {
                            throw new Exception("Failed to move uploaded file to destination.");
                        }
                    } else {
                        throw new Exception("Invalid file type. Allowed: " . implode(', ', $allowed_extensions));
                    }
                } else {
                    $upload_errors = [
                        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
                    ];
                    $error_code = $_FILES['company_logo']['error'];
                    throw new Exception($upload_errors[$error_code] ?? 'Unknown upload error.');
                }
            }
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "General settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating general settings: " . $e->getMessage();
        }
    }
    
    // Email Settings
    if (isset($_POST['save_email'])) {
        try {
            $settings = [
                'smtp_host' => $_POST['smtp_host'],
                'smtp_port' => $_POST['smtp_port'],
                'smtp_username' => $_POST['smtp_username'],
                'smtp_password' => $_POST['smtp_password'],
                'smtp_encryption' => $_POST['smtp_encryption'],
                'from_email' => $_POST['from_email'],
                'from_name' => $_POST['from_name'],
                'enable_email_notifications' => $_POST['enable_email_notifications'] ?? 0
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "Email settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating email settings: " . $e->getMessage();
        }
    }

    // SMS Settings
    if (isset($_POST['save_sms'])) {
        try {
            $settings = [
                'sms_gateway_type' => $_POST['sms_gateway_type'],
                'sms_api_key' => $_POST['sms_api_key'],
                'sms_api_secret' => $_POST['sms_api_secret'],
                'sms_sender_id' => $_POST['sms_sender_id'],
                'enable_sms_notifications' => $_POST['enable_sms_notifications'] ?? 0
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "SMS settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating SMS settings: " . $e->getMessage();
        }
    }
    
    // Color Settings (print template accent colors — Sales Side + Purchase Side)
    if (isset($_POST['save_colors'])) {
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
            $success_messages[] = "Color settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating color settings: " . $e->getMessage();
        }
    }

    // Collection Settings
    if (isset($_POST['save_collection'])) {
        try {
            $settings = [
                'collection_target_monthly' => $_POST['collection_target_monthly'],
                'overdue_reminder_days' => $_POST['overdue_reminder_days'],
                'max_overdue_days' => $_POST['max_overdue_days'],
                'enable_auto_reminders' => $_POST['enable_auto_reminders'] ?? 0,
                'enable_sms_notifications' => $_POST['enable_sms_notifications'] ?? 0,
                'grace_period_days' => $_POST['grace_period_days']
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "Collection settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating collection settings: " . $e->getMessage();
        }
    }
    
    // Security Settings
    if (isset($_POST['save_security'])) {
        try {
            $settings = [
                'session_timeout' => $_POST['session_timeout'],
                'max_login_attempts' => $_POST['max_login_attempts'],
                'password_expiry_days' => $_POST['password_expiry_days'],
                'require_strong_password' => $_POST['require_strong_password'] ?? 0,
                'enable_2fa' => $_POST['enable_2fa'] ?? 0,
                'enable_audit_log' => $_POST['enable_audit_log'] ?? 0
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "Security settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating security settings: " . $e->getMessage();
        }
    }

    // POS Settings
    if (isset($_POST['save_pos'])) {
        try {
            $settings = [
                'pos_discount_type' => $_POST['pos_discount_type'] ?? 'percentage'
            ];
            
            foreach ($settings as $key => $value) {
                save_setting($key, $value);
            }
            $success_messages[] = "POS settings updated successfully";
        } catch (Exception $e) {
            $error_messages[] = "Error updating POS settings: " . $e->getMessage();
        }
    }
}

// Settings are handled by global helpers in helpers.php
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-gear"></i> System Settings</h2>
            <p class="text-muted">Manage system configurations and preferences</p>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($success_messages)): ?>
        <?php foreach ($success_messages as $message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($error_messages)): ?>
        <?php foreach ($error_messages as $message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Settings UI -->
    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm sticky-top" style="top: 100px; z-index: 100;">
                <div class="card-body p-0">
                    <div class="list-group list-group-flush settings-nav" id="settingsTabs" role="tablist">
                        <a class="list-group-item list-group-item-action active py-3 px-4 border-0 border-start border-4 border-transparent" 
                           id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab" aria-selected="true">
                            <div class="d-flex align-items-center">
                                <div class="icon-box me-3 bg-primary-soft text-primary">
                                    <i class="bi bi-building"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">General</h6>
                                    <small class="text-muted">Business identity & basics</small>
                                </div>
                            </div>
                        </a>
                        <a class="list-group-item list-group-item-action py-3 px-4 border-0 border-start border-4 border-transparent"
                           id="email-tab" data-bs-toggle="tab" data-bs-target="#email" type="button" role="tab" aria-selected="false">
                            <div class="d-flex align-items-center">
                                <div class="icon-box me-3 bg-info-soft text-info">
                                    <i class="bi bi-envelope"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Email Config</h6>
                                    <small class="text-muted">SMTP & notifications</small>
                                </div>
                            </div>
                        </a>
                        <a class="list-group-item list-group-item-action py-3 px-4 border-0 border-start border-4 border-transparent" 
                           id="sms-tab" data-bs-toggle="tab" data-bs-target="#sms" type="button" role="tab" aria-selected="false">
                            <div class="d-flex align-items-center">
                                <div class="icon-box me-3 bg-warning-soft text-warning">
                                    <i class="bi bi-chat-text"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">SMS Gateway</h6>
                                    <small class="text-muted">Providers & settings</small>
                                </div>
                            </div>
                        </a>
                        <a class="list-group-item list-group-item-action py-3 px-4 border-0 border-start border-4 border-transparent"
                           id="colors-tab" data-bs-toggle="tab" data-bs-target="#colors" type="button" role="tab" aria-selected="false">
                            <div class="d-flex align-items-center">
                                <div class="icon-box me-3 bg-success-soft text-success">
                                    <i class="bi bi-palette"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Color Setting</h6>
                                    <small class="text-muted">Print template accents</small>
                                </div>
                            </div>
                        </a>
                        <a class="list-group-item list-group-item-action py-3 px-4 border-0 border-start border-4 border-transparent"
                           id="collection-tab" data-bs-toggle="tab" data-bs-target="#collection" type="button" role="tab" aria-selected="false">
                            <div class="d-flex align-items-center">
                                <div class="icon-box me-3 bg-danger-soft text-danger">
                                    <i class="bi bi-coin"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Collections</h6>
                                    <small class="text-muted">Targets & reminders</small>
                                </div>
                            </div>
                        </a>
                        <a class="list-group-item list-group-item-action py-3 px-4 border-0 border-start border-4 border-transparent" 
                           id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab" aria-selected="false">
                            <div class="d-flex align-items-center">
                                <div class="icon-box me-3 bg-dark-soft text-dark">
                                    <i class="bi bi-shield-lock"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Security</h6>
                                    <small class="text-muted">Sessions & audits</small>
                                </div>
                            </div>
                        </a>
                        <a class="list-group-item list-group-item-action py-3 px-4 border-0 border-start border-4 border-transparent" 
                           id="pos-tab" data-bs-toggle="tab" data-bs-target="#pos" type="button" role="tab" aria-selected="false">
                            <div class="d-flex align-items-center">
                                <div class="icon-box me-3 bg-primary-soft text-primary">
                                    <i class="bi bi-cart"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">POS Settings</h6>
                                    <small class="text-muted">Point of Sale config</small>
                                </div>
                            </div>
                        </a>
                        <a class="list-group-item list-group-item-action py-3 px-4 border-0 border-start border-4 border-transparent" 
                           id="backup-tab" data-bs-toggle="tab" data-bs-target="#backup" type="button" role="tab" aria-selected="false">
                            <div class="d-flex align-items-center">
                                <div class="icon-box me-3 bg-indigo-soft text-indigo">
                                    <i class="bi bi-cloud-arrow-down"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Backup</h6>
                                    <small class="text-muted">Database recovery</small>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="col-lg-9">
            <div class="tab-content settings-content shadow-sm rounded-4 bg-white p-4 p-md-5" id="settingsTabsContent">

                <!-- General Settings Tab -->
                <div class="tab-pane fade show active" id="general" role="tabpanel">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="d-flex align-items-center mb-4">
                            <h4 class="section-title mb-0">General Settings</h4>
                            <span class="badge bg-primary-soft text-primary ms-3">System Identity</span>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-5">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Branding</h6>
                                        
                                        <div class="mb-4 text-center">
                                            <?php $logo = get_setting('company_logo'); ?>
                                            <div class="position-relative d-inline-block mb-3">
                                                <?php if ($logo): ?>
                                                    <?php $display_logo = (strpos($logo, 'http') === 0) ? $logo : '/' . ltrim($logo, '/'); ?>
                                                    <img src="<?= $display_logo ?>" alt="Logo" class="img-thumbnail" style="max-height: 120px; width: auto;">
                                                <?php else: ?>
                                                    <div class="bg-light d-flex align-items-center justify-content-center rounded-4 border" style="width: 120px; height: 120px;">
                                                        <i class="bi bi-image text-muted" style="font-size: 3rem;"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <label for="company_logo" class="btn btn-primary btn-sm position-absolute bottom-0 end-0 rounded-circle shadow-sm" style="width: 35px; height: 35px; padding: 0;">
                                                    <i class="bi bi-camera"></i>
                                                </label>
                                            </div>
                                            <input type="file" id="company_logo" name="company_logo" class="d-none" accept="image/*">
                                            <p class="small text-muted mb-0">Upload company logo (PNG/JPG)</p>
                                        </div>

                                        <div class="mb-3">
                                            <label for="company_name" class="form-label">Company Name *</label>
                                            <input type="text" class="form-control" id="company_name" name="company_name" 
                                                   value="<?= get_setting('company_name', 'Microfinance Institution') ?>" required>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label d-block fw-bold">Module Settings</label>
                                            <div class="form-check form-switch p-2 bg-light rounded border">
                                                <input class="form-check-input ms-0 me-2" type="checkbox" id="enable_projects" name="enable_projects" value="1" <?= get_setting('enable_projects') == '1' ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="enable_projects">Enable Projects Module</label>
                                                <div class="small text-muted mt-1">Manage projects and link finances (expenses, invoices, etc.) to specific projects.</div>
                                            </div>
                                        </div>

                                        <div class="mb-0">
                                            <label for="company_type" class="form-label">Business Type *</label>
                                            <select class="form-select" id="company_type" name="company_type" required>
                                                <option value="microfinance" <?= get_setting('company_type', 'microfinance') == 'microfinance' ? 'selected' : '' ?>>Microfinance / Lending</option>
                                                <option value="retail" <?= get_setting('company_type') == 'retail' ? 'selected' : '' ?>>Retail / Sales</option>
                                                <option value="service" <?= get_setting('company_type') == 'service' ? 'selected' : '' ?>>Service / Inventory</option>
                                            </select>
                                            <div class="form-text small">Adapts modules based on business model.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-7">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Contact & Region</h6>
                                        
                                         <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="company_phone" class="form-label">Phone Number</label>
                                                    <input type="text" class="form-control" id="company_phone" name="company_phone" 
                                                           value="<?= get_setting('company_phone') ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="company_email" class="form-label">Email Address</label>
                                                    <input type="email" class="form-control" id="company_email" name="company_email" 
                                                           value="<?= get_setting('company_email') ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label for="company_website" class="form-label">Company Website</label>
                                            <input type="text" class="form-control" id="company_website" name="company_website" 
                                                   value="<?= get_setting('company_website') ?>" placeholder="https://example.com">
                                        </div>

                                        <div class="mb-3">
                                            <label for="company_address" class="form-label">Address</label>
                                            <textarea class="form-control" id="company_address" name="company_address" 
                                                      rows="2"><?= get_setting('company_address') ?></textarea>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="currency" class="form-label">Currency *</label>
                                                    <select class="form-control" id="currency" name="currency" required>
                                                        <option value="USD" <?= get_setting('currency') == 'USD' ? 'selected' : '' ?>>US Dollar ($)</option>
                                                        <option value="TZS" <?= get_setting('currency') == 'TZS' ? 'selected' : '' ?>>Tanzanian Shilling (TSh)</option>
                                                        <option value="KES" <?= get_setting('currency') == 'KES' ? 'selected' : '' ?>>Kenyan Shilling (KSh)</option>
                                                        <option value="EUR" <?= get_setting('currency') == 'EUR' ? 'selected' : '' ?>>Euro (€)</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="timezone" class="form-label">Timezone *</label>
                                                    <select class="form-control" id="timezone" name="timezone" required>
                                                        <option value="Africa/Nairobi" <?= get_setting('timezone') == 'Africa/Nairobi' ? 'selected' : '' ?>>East Africa (Nairobi)</option>
                                                        <option value="UTC" <?= get_setting('timezone') == 'UTC' ? 'selected' : '' ?>>UTC</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="date_format" class="form-label">Date Format *</label>
                                                    <select class="form-control" id="date_format" name="date_format" required>
                                                        <option value="Y-m-d" <?= get_setting('date_format') == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                                        <option value="d/m/Y" <?= get_setting('date_format') == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="items_per_page" class="form-label">Items Per Page *</label>
                                                    <select class="form-control" id="items_per_page" name="items_per_page" required>
                                                        <option value="10" <?= get_setting('items_per_page') == '10' ? 'selected' : '' ?>>10</option>
                                                        <option value="25" <?= get_setting('items_per_page') == '25' ? 'selected' : '' ?>>25</option>
                                                        <option value="50" <?= get_setting('items_per_page') == '50' ? 'selected' : '' ?>>50</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-5 pt-3 border-top d-flex justify-content-between align-items-center">
                            <span class="text-muted small">System Version: <span class="fw-bold">v2.1.0</span></span>
                            <button type="submit" name="save_general" class="btn btn-primary px-5">
                                <i class="bi bi-save me-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Email Settings Tab -->
                <div class="tab-pane fade" id="email" role="tabpanel">
                    <form method="POST">
                        <div class="d-flex align-items-center mb-4">
                            <h4 class="section-title mb-0">Email Configuration</h4>
                            <span class="badge bg-info-soft text-info ms-3">Communication</span>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-7">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">SMTP Server Details</h6>
                                        <div class="row g-3">
                                            <div class="col-md-8">
                                                <label for="smtp_host" class="form-label">SMTP Host *</label>
                                                <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?= get_setting('smtp_host', 'smtp.gmail.com') ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label for="smtp_port" class="form-label">Port *</label>
                                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="<?= get_setting('smtp_port', '587') ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="smtp_username" class="form-label">Username *</label>
                                                <input type="text" class="form-control" id="smtp_username" name="smtp_username" value="<?= get_setting('smtp_username') ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="smtp_password" class="form-label">Password *</label>
                                                <input type="password" class="form-control" id="smtp_password" name="smtp_password" value="<?= get_setting('smtp_password') ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="smtp_encryption" class="form-label">Encryption *</label>
                                                <select class="form-select" id="smtp_encryption" name="smtp_encryption" required>
                                                    <option value="tls" <?= get_setting('smtp_encryption') == 'tls' ? 'selected' : '' ?>>TLS</option>
                                                    <option value="ssl" <?= get_setting('smtp_encryption') == 'ssl' ? 'selected' : '' ?>>SSL</option>
                                                    <option value="" <?= get_setting('smtp_encryption') == '' ? 'selected' : '' ?>>None</option>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Sender Identification</h6>
                                        <div class="mb-3">
                                            <label for="from_email" class="form-label">From Email Address *</label>
                                            <input type="email" class="form-control" id="from_email" name="from_email" value="<?= get_setting('from_email', get_setting('company_email')) ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label for="from_name" class="form-label">From Name *</label>
                                            <input type="text" class="form-control" id="from_name" name="from_name" value="<?= get_setting('from_name', get_setting('company_name')) ?>" required>
                                        </div>
                                        <div class="mb-0">
                                            <div class="form-check form-switch p-0 ms-0">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <label class="form-check-label fw-bold" for="enable_email_notifications">Enable Alerts</label>
                                                    <input class="form-check-input" type="checkbox" id="enable_email_notifications" name="enable_email_notifications" value="1" <?= get_setting('enable_email_notifications') ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="alert alert-info border-0 rounded-4 d-flex align-items-center p-4 mb-0">
                                    <div class="icon-box bg-white text-info me-4 shadow-sm">
                                        <i class="bi bi-info-circle-fill"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="fw-bold mb-1">Verify your connection</h6>
                                        <p class="small text-muted mb-0">Use the test button to ensure your mail server is configured correctly before saving.</p>
                                    </div>
                                    <button type="button" class="btn btn-white border-0 shadow-sm fw-bold px-4" id="testEmailConfig">
                                        <i class="bi bi-lightning-auto me-2"></i> Test Connection
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_email" class="btn btn-primary px-5">
                                <i class="bi bi-save me-2"></i> Save Configuration
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Collection Settings Tab -->
                <div class="tab-pane fade" id="collection" role="tabpanel">
                    <form method="POST">
                        <div class="d-flex align-items-center mb-4">
                            <h4 class="section-title mb-0">Collections & Recovery</h4>
                            <span class="badge bg-danger-soft text-danger ms-3">Arrears Management</span>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-7">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Targeting & Thresholds</h6>
                                        <div class="mb-3">
                                            <label for="collection_target_monthly" class="form-label">Monthly Target Amount</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><?= get_setting('currency', 'TZS') ?></span>
                                                <input type="number" step="0.01" class="form-control" id="collection_target_monthly" name="collection_target_monthly" value="<?= get_setting('collection_target_monthly', '50000.00') ?>">
                                            </div>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="grace_period_days" class="form-label">Grace Period (Days)</label>
                                                <input type="number" class="form-control" id="grace_period_days" name="grace_period_days" value="<?= get_setting('grace_period_days', '7') ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="max_overdue_days" class="form-label">Default Threshold (Days)</label>
                                                <input type="number" class="form-control" id="max_overdue_days" name="max_overdue_days" value="<?= get_setting('max_overdue_days', '90') ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Automation Switch</h6>
                                        <div class="mb-4">
                                            <div class="form-check form-switch p-0 ms-0">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <label class="form-check-label fw-bold" for="enable_auto_reminders">Auto Reminders</label>
                                                    <input class="form-check-input" type="checkbox" id="enable_auto_reminders" name="enable_auto_reminders" value="1" <?= get_setting('enable_auto_reminders') ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-0">
                                            <label for="overdue_reminder_days" class="form-label">Frequency (Every X Days)</label>
                                            <input type="number" class="form-control" id="overdue_reminder_days" name="overdue_reminder_days" value="<?= get_setting('overdue_reminder_days', '3') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="card info-card bg-light border-0">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-3">Reminder Sequence Protocol</h6>
                                        <div class="row text-center g-2">
                                            <div class="col-md-3">
                                                <div class="p-3 bg-white rounded-3 shadow-sm">
                                                    <div class="text-primary fw-bold mb-1">Friendly</div>
                                                    <div class="small text-muted">3 Days Overdue</div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="p-3 bg-white rounded-3 shadow-sm">
                                                    <div class="text-warning fw-bold mb-1">Final Notice</div>
                                                    <div class="small text-muted">7 Days Overdue</div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="p-3 bg-white rounded-3 shadow-sm">
                                                    <div class="text-danger fw-bold mb-1">Warning</div>
                                                    <div class="small text-muted">14 Days Overdue</div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="p-3 bg-dark text-white rounded-3 shadow-sm">
                                                    <div class="fw-bold mb-1">Default</div>
                                                    <div class="small opacity-75">30+ Days Overdue</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_collection" class="btn btn-primary px-5">
                                <i class="bi bi-save me-2"></i> Save Collection Strategy
                            </button>
                        </div>
                    </form>
                </div>

                <!-- SMS Settings Tab -->
                <div class="tab-pane fade" id="sms" role="tabpanel">
                    <form method="POST">
                        <div class="d-flex align-items-center mb-4">
                            <h4 class="section-title mb-0">SMS Gateway Config</h4>
                            <span class="badge bg-warning-soft text-warning ms-3">Short Message Service</span>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-7">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Provider Authentication</h6>
                                        <div class="mb-3">
                                            <label for="sms_gateway_type" class="form-label">Gateway Provider *</label>
                                            <select class="form-select" id="sms_gateway_type" name="sms_gateway_type">
                                                <option value="placeholder" <?= get_setting('sms_gateway_type') == 'placeholder' ? 'selected' : '' ?>>Simulator (Demo Mode)</option>
                                                <option value="twilio" <?= get_setting('sms_gateway_type') == 'twilio' ? 'selected' : '' ?>>Twilio</option>
                                                <option value="infobip" <?= get_setting('sms_gateway_type') == 'infobip' ? 'selected' : '' ?>>Infobip</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label for="sms_api_key" class="form-label">API Key / Access Token</label>
                                            <input type="text" class="form-control" id="sms_api_key" name="sms_api_key" value="<?= get_setting('sms_api_key') ?>">
                                        </div>
                                        <div class="mb-0">
                                            <label for="sms_api_secret" class="form-label">API Secret / Auth Token</label>
                                            <input type="password" class="form-control" id="sms_api_secret" name="sms_api_secret" value="<?= get_setting('sms_api_secret') ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Broadcast Details</h6>
                                        <div class="mb-4">
                                            <label for="sms_sender_id" class="form-label">Sender ID / Mask</label>
                                            <input type="text" class="form-control" id="sms_sender_id" name="sms_sender_id" value="<?= get_setting('sms_sender_id') ?>" placeholder="e.g. BEJUNDAS">
                                        </div>
                                        <div class="mb-0">
                                            <div class="form-check form-switch p-0 ms-0">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <label class="form-check-label fw-bold" for="sms_enable_notifications">SMS Alerts</label>
                                                    <input class="form-check-input" type="checkbox" id="sms_enable_notifications" name="enable_sms_notifications" value="1" <?= get_setting('enable_sms_notifications') ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="alert alert-warning border-0 rounded-4 d-flex align-items-center p-4 mb-0">
                                    <div class="icon-box bg-white text-warning me-4 shadow-sm">
                                        <i class="bi bi-broadcast-pin"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="fw-bold mb-1">Verify SMS Gateway</h6>
                                        <p class="small text-muted mb-0">Standard charges apply. Ensure your API credentials are correct to avoid broadcast failures.</p>
                                    </div>
                                    <button type="button" class="btn btn-white border-0 shadow-sm fw-bold px-4" id="testSmsConfig">
                                        <i class="bi bi-send-check me-2"></i> Test Send
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_sms" class="btn btn-primary px-5">
                                <i class="bi bi-save me-2"></i> Save SMS Gateway
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Color Setting Tab -->
                <div class="tab-pane fade" id="colors" role="tabpanel">
                    <form method="POST">
                        <div class="d-flex align-items-center mb-4">
                            <h4 class="section-title mb-0">Color Setting</h4>
                            <span class="badge bg-success-soft text-success ms-3">Print Templates</span>
                        </div>

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

                        <div class="mt-5 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_colors" class="btn btn-primary px-5">
                                <i class="bi bi-save me-2"></i> Save Color Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Security Settings Tab -->
                <div class="tab-pane fade" id="security" role="tabpanel">
                    <form method="POST">
                        <div class="d-flex align-items-center mb-4">
                            <h4 class="section-title mb-0">Security & Access</h4>
                            <span class="badge bg-dark-soft text-dark ms-3">Hardening</span>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-7">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Authentication Policies</h6>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="session_timeout" class="form-label">Session Timeout (Min)</label>
                                                <input type="number" class="form-control" id="session_timeout" name="session_timeout" value="<?= get_setting('session_timeout', '30') ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="max_login_attempts" class="form-label">Max Login Retries</label>
                                                <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" value="<?= get_setting('max_login_attempts', '5') ?>" required>
                                            </div>
                                            <div class="col-12">
                                                <label for="password_expiry_days" class="form-label">Password Rotation (Days)</label>
                                                <input type="number" class="form-control" id="password_expiry_days" name="password_expiry_days" value="<?= get_setting('password_expiry_days', '90') ?>">
                                                <div class="form-text small">Set to 0 to disable periodic password changes.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-5">
                                <div class="card info-card h-100">
                                    <div class="card-body p-4">
                                        <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Advanced Controls</h6>
                                        <div class="mb-4">
                                            <div class="form-check form-switch p-0 ms-0">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <label class="form-check-label fw-bold" for="require_strong_password">Strong Passwords</label>
                                                    <input class="form-check-input" type="checkbox" id="require_strong_password" name="require_strong_password" value="1" <?= get_setting('require_strong_password') ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-4">
                                            <div class="form-check form-switch p-0 ms-0">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <label class="form-check-label fw-bold" for="enable_2fa">2FA Verification</label>
                                                    <input class="form-check-input" type="checkbox" id="enable_2fa" name="enable_2fa" value="1" <?= get_setting('enable_2fa') ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-0">
                                            <div class="form-check form-switch p-0 ms-0">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <label class="form-check-label fw-bold" for="enable_audit_log">Audit Logs</label>
                                                    <input class="form-check-input" type="checkbox" id="enable_audit_log" name="enable_audit_log" value="1" <?= get_setting('enable_audit_log', '1') ? 'checked' : '' ?>>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_security" class="btn btn-primary px-5">
                                <i class="bi bi-shield-check me-2"></i> Save Security Policy
                            </button>
                        </div>
                    </form>
                </div>

                <!-- POS Settings Tab -->
                <div class="tab-pane fade" id="pos" role="tabpanel">
                    <form method="POST">
                        <div class="d-flex align-items-center mb-4">
                            <h4 class="section-title mb-0">POS Configuration</h4>
                            <span class="badge bg-primary-soft text-primary ms-3">Retail</span>
                        </div>
                        
                        <div class="card info-card h-100">
                            <div class="card-body p-4">
                                <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Discount Configuration</h6>
                                
                                <div class="mb-3">
                                    <label for="pos_discount_type" class="form-label">Discount Type Preference</label>
                                    <select class="form-select" id="pos_discount_type" name="pos_discount_type">
                                        <option value="percentage" <?= get_setting('pos_discount_type', 'percentage') == 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                                        <option value="fixed" <?= get_setting('pos_discount_type') == 'fixed' ? 'selected' : '' ?>>Fixed Amount (Constant)</option>
                                    </select>
                                    <div class="form-text">Choose how discounts are applied in the POS interface (Percentage vs Constant Amount).</div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 pt-3 border-top d-flex justify-content-end">
                            <button type="submit" name="save_pos" class="btn btn-primary px-5">
                                <i class="bi bi-save me-2"></i> Save POS Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Backup Settings Tab -->
                <div class="tab-pane fade" id="backup" role="tabpanel">
                    <div class="d-flex align-items-center mb-4">
                        <h4 class="section-title mb-0">System Continuity</h4>
                        <span class="badge bg-indigo-soft text-indigo ms-3">Backup & Recovery</span>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-5">
                            <div class="card info-card mb-4">
                                <div class="card-body p-4 text-center">
                                    <div class="icon-box bg-primary-soft text-primary mx-auto mb-3 shadow-sm" style="width: 60px; height: 60px;">
                                        <i class="bi bi-cloud-upload fs-3"></i>
                                    </div>
                                    <h6 class="fw-bold mb-2">Immediate Snapshot</h6>
                                    <p class="small text-muted mb-3">Manually trigger a full system and database backup.</p>
                                    <button type="button" class="btn btn-primary w-100" id="createBackup">
                                        <i class="bi bi-play-circle me-2"></i> Run Backup Now
                                    </button>
                                </div>
                            </div>

                            <div class="card info-card">
                                <div class="card-body p-4">
                                    <h6 class="fw-bold mb-4 text-dark text-uppercase small letter-spacing-1">Scheduler Config</h6>
                                    <div class="mb-3">
                                        <label class="form-label">Backup Frequency</label>
                                        <select class="form-select" id="backup_frequency">
                                            <option value="daily" <?= get_setting('backup_frequency') == 'daily' ? 'selected' : '' ?>>Every 24 Hours</option>
                                            <option value="weekly" <?= get_setting('backup_frequency', 'weekly') == 'weekly' ? 'selected' : '' ?>>Weekly Routine</option>
                                            <option value="monthly" <?= get_setting('backup_frequency') == 'monthly' ? 'selected' : '' ?>>Monthly Archive</option>
                                        </select>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label">Retention Policy</label>
                                        <select class="form-select" id="backup_retention">
                                            <option value="7" <?= get_setting('backup_retention') == '7' ? 'selected' : '' ?>>Keep for 7 Days</option>
                                            <option value="30" <?= get_setting('backup_retention', '30') == '30' ? 'selected' : '' ?>>Keep for 30 Days</option>
                                            <option value="365" <?= get_setting('backup_retention') == '365' ? 'selected' : '' ?>>Keep for 1 Year</option>
                                        </select>
                                    </div>
                                    <button type="button" class="btn btn-outline-primary w-100 fw-bold" id="saveBackupSettings">
                                        <i class="bi bi-save me-2"></i> Update Schedule
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-7">
                            <div class="card info-card h-100">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between align-items-center mb-4">
                                        <h6 class="fw-bold text-dark text-uppercase small letter-spacing-1 mb-0">Repository Logs</h6>
                                        <small class="text-muted">Last Checked: Just Now</small>
                                    </div>
                                    
                                    <div class="table-responsive" style="max-height: 480px; overflow-y: auto;">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="sticky-top bg-white">
                                                <tr class="small text-muted text-uppercase">
                                                    <th class="border-0">Archive Reference</th>
                                                    <th class="border-0">Timestamp</th>
                                                    <th class="border-0">Disk Size</th>
                                                    <th class="border-0 text-end">Commands</th>
                                                </tr>
                                            </thead>
                                            <tbody id="backupList">
                                                <tr>
                                                    <td colspan="4" class="text-center py-5">
                                                        <div class="spinner-border spinner-border-sm text-primary mb-3" role="status"></div>
                                                        <div class="small text-muted">Syncing with backup repository...</div>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-4 p-3 bg-light rounded-3 d-flex align-items-center">
                                        <div class="icon-box bg-white text-info me-3 shadow-sm rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                            <i class="bi bi-info-lg"></i>
                                        </div>
                                        <div class="small text-muted">Archives are stored in the <code>/backups</code> directory. Point-in-time recovery is available for all files listed above.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php include("footer.php"); ?>

<style>
:root {
    --primary-soft: rgba(13, 110, 253, 0.1);
    --success-soft: rgba(25, 135, 84, 0.1);
    --info-soft: rgba(13, 202, 240, 0.1);
    --warning-soft: rgba(255, 193, 7, 0.1);
    --danger-soft: rgba(220, 53, 69, 0.1);
    --dark-soft: rgba(33, 37, 41, 0.1);
    --indigo-soft: rgba(102, 16, 242, 0.1);
    --indigo: #6610f2;
}

body {
    background-color: #f8f9fc;
}

.icon-box {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    font-size: 1.25rem;
}

.settings-nav .list-group-item {
    transition: all 0.3s ease;
    border-radius: 0 !important;
}

.settings-nav .list-group-item:hover {
    background-color: #f8f9fa;
    color: var(--bs-primary);
}

.settings-nav .list-group-item.active {
    background-color: white;
    color: var(--bs-primary);
    border-left-color: var(--bs-primary) !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}

.settings-content {
    min-height: 600px;
    animation: fadeIn 0.4s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.bg-indigo-soft { background-color: var(--indigo-soft); }
.text-indigo { color: var(--indigo); }
.border-indigo { border-color: var(--indigo); }

.bg-primary-soft { background-color: var(--primary-soft); }
.bg-success-soft { background-color: var(--success-soft); }
.bg-info-soft { background-color: var(--info-soft); }
.bg-warning-soft { background-color: var(--warning-soft); }
.bg-danger-soft { background-color: var(--danger-soft); }
.bg-dark-soft { background-color: var(--dark-soft); }

.form-label {
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 0.5rem;
}

.form-control, .form-select {
    padding: 0.75rem 1rem;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    font-size: 0.95rem;
}

.form-control:focus, .form-select:focus {
    box-shadow: 0 0 0 4px var(--primary-soft);
    border-color: var(--bs-primary);
}

.form-switch .form-check-input {
    width: 3.5rem;
    height: 1.75rem;
    cursor: pointer;
}

.btn-primary {
    padding: 0.75rem 1.5rem;
    border-radius: 12px;
    font-weight: 600;
    box-shadow: 0 4px 14px rgba(13, 110, 253, 0.25);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(13, 110, 253, 0.35);
}

.section-title {
    position: relative;
    padding-left: 1rem;
    margin-bottom: 2rem;
}

.section-title::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0.25rem;
    bottom: 0.25rem;
    width: 4px;
    background: var(--bs-primary);
    border-radius: 4px;
}

.card.info-card {
    border-radius: 16px;
    border: 1px solid #edf2f7;
    transition: transform 0.3s ease;
}

.card.info-card:hover {
    transform: translateY(-5px);
}

.img-thumbnail {
    border-radius: 16px;
    padding: 10px;
    background: #fff;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
</style>

<script>
$(document).ready(function() {
    // Logo Upload Interaction
    $('#company_logo').change(function(e) {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('.img-thumbnail').attr('src', e.target.result);
                // If it was a generic icon, replace it with an image tag
                if ($('.bg-light.d-flex.align-items-center').length) {
                    $('.bg-light.d-flex.align-items-center').replaceWith('<img src="' + e.target.result + '" alt="Logo Preview" class="img-thumbnail" style="max-height: 120px; width: auto;">');
                }
            }
            reader.readAsDataURL(this.files[0]);
            
            // Helpful tip: user still needs to click Save to persist
            showToast('info', 'Logo preview updated. Click "Save Changes" to finalize.');
        }
    });

    // Test Email Configuration
    $('#testEmailConfig').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Testing...');
        
        $.ajax({
            url: 'api/test_email_config.php',
            type: 'POST',
            data: {
                smtp_host: $('#smtp_host').val(),
                smtp_port: $('#smtp_port').val(),
                smtp_username: $('#smtp_username').val(),
                smtp_password: $('#smtp_password').val(),
                smtp_encryption: $('#smtp_encryption').val(),
                from_email: $('#from_email').val(),
                from_name: $('#from_name').val()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Email configuration test successful!');
                } else {
                    showToast('error', 'Email test failed: ' + (response.message || 'Unknown error'));
                }
            },
            error: function() {
                showToast('error', 'Error testing email configuration');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Test SMS Configuration
    $('#testSmsConfig').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Testing...');
        
        $.ajax({
            url: 'api/test_sms_config.php',
            type: 'POST',
            data: {
                sms_gateway_type: $('#sms_gateway_type').val(),
                sms_api_key: $('#sms_api_key').val(),
                sms_api_secret: $('#sms_api_secret').val(),
                sms_sender_id: $('#sms_sender_id').val()
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', 'SMS configuration test successful!');
                } else {
                    showToast('error', 'SMS test failed: ' + (response.message || 'Unknown error'));
                }
            },
            error: function() {
                showToast('error', 'Error testing SMS configuration');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Create Backup
    $('#createBackup').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        if (!confirm('Are you sure you want to create a database backup? This may take a few moments.')) {
            return;
        }
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Creating Backup...');
        
        $.ajax({
            url: 'api/create_backup.php',
            type: 'POST',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Backup created successfully!');
                    loadBackupList();
                } else {
                    showToast('error', 'Backup failed: ' + (response.message || 'Unknown error'));
                }
            },
            error: function() {
                showToast('error', 'Error creating backup');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Save Backup Settings
    $('#saveBackupSettings').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        const frequency = $('#backup_frequency').val();
        const retention = $('#backup_retention').val();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Saving...');
        
        $.ajax({
            url: 'api/save_backup_settings.php',
            type: 'POST',
            data: {
                backup_frequency: frequency,
                backup_retention: retention
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Backup settings saved successfully!');
                } else {
                    showToast('error', 'Failed to save settings: ' + response.message);
                }
            },
            error: function() {
                showToast('error', 'Error saving backup settings');
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Load backup list
    function loadBackupList() {
        $.ajax({
            url: 'api/get_backup_list.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    let html = '';
                    if (response.backups.length > 0) {
                        response.backups.forEach(backup => {
                            html += `
                                <tr>
                                    <td>${backup.filename}</td>
                                    <td>${backup.date}</td>
                                    <td>${backup.size}</td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary download-backup" data-file="${backup.filename}">
                                            <i class="bi bi-download"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger delete-backup" data-file="${backup.filename}">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            `;
                        });
                        $('#lastBackupDate').text(response.backups[0].date);
                    } else {
                        html = '<tr><td colspan="4" class="text-center text-muted">No backups found</td></tr>';
                    }
                    $('#backupList').html(html);
                }
            },
            error: function() {
                $('#backupList').html('<tr><td colspan="4" class="text-center text-danger">Error loading backup list</td></tr>');
            }
        });
    }

    // Download backup
    $(document).on('click', '.download-backup', function() {
        const filename = $(this).data('file');
        window.open('api/download_backup.php?file=' + encodeURIComponent(filename), '_blank');
    });

    // Delete backup
    $(document).on('click', '.delete-backup', function() {
        const filename = $(this).data('file');
        
        if (!confirm('Are you sure you want to delete backup: ' + filename + '?')) {
            return;
        }
        
        $.ajax({
            url: 'api/delete_backup.php',
            type: 'POST',
            data: { filename: filename },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Backup deleted successfully!');
                    loadBackupList();
                } else {
                    showToast('error', 'Delete failed: ' + (response.message || 'Unknown error'));
                }
            },
            error: function() {
                showToast('error', 'Error deleting backup');
            }
        });
    });

    // Toast notification function
    function showToast(type, message) {
        var toast = '<div class="toast align-items-center text-white bg-' + type + ' border-0" role="alert" aria-live="assertive" aria-atomic="true">';
        toast += '<div class="d-flex">';
        toast += '<div class="toast-body">' + message + '</div>';
        toast += '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>';
        toast += '</div></div>';
        
        var $toast = $(toast);
        $('.toast-container').append($toast);
        var bsToast = new bootstrap.Toast($toast[0]);
        bsToast.show();
        
        $toast.on('hidden.bs.toast', function() {
            $(this).remove();
        });
    }

    // Load initial backup list
    loadBackupList();
});
</script>