<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Phase 5d — gate my_settings page (key seeded in 2026_05_24_phase5d_loans_seed.php)
autoEnforcePermission('my_settings');

require_once __DIR__ . '/../../../header.php';

$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Fetch current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_user) {
    header("Location: " . getUrl('login'));
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Update Profile Info
    if (isset($_POST['update_profile'])) {
        try {
            $full_name = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (empty($full_name) || empty($email)) {
                throw new Exception("Full name and email are required.");
            }

            // Check if email is already in use by another user
            $check = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $check->execute([$email, $user_id]);
            if ($check->fetch()) {
                throw new Exception("This email is already in use by another account.");
            }

            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
            $stmt->execute([$full_name, $email, $phone, $user_id]);

            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $current_user = $stmt->fetch(PDO::FETCH_ASSOC);

            $success_msg = "Profile updated successfully!";
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
        }
    }

    // Change Password
    if (isset($_POST['change_password'])) {
        try {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                throw new Exception("All password fields are required.");
            }

            if (!password_verify($current_password, $current_user['password'])) {
                throw new Exception("Current password is incorrect.");
            }

            if (strlen($new_password) < 6) {
                throw new Exception("New password must be at least 6 characters.");
            }

            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match.");
            }

            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->execute([$hashed, $user_id]);

            $success_msg = "Password changed successfully!";
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
        }
    }

    // Save Preferences
    if (isset($_POST['save_preferences'])) {
        try {
            $prefs = [
                'user_theme' => $_POST['user_theme'] ?? 'light',
                'user_language' => $_POST['user_language'] ?? 'en',
                'user_timezone' => $_POST['user_timezone'] ?? 'Africa/Dar_es_Salaam',
                'user_date_format' => $_POST['user_date_format'] ?? 'd/m/Y',
                'user_email_notifications' => isset($_POST['user_email_notifications']) ? '1' : '0',
                'user_sms_notifications' => isset($_POST['user_sms_notifications']) ? '1' : '0'
            ];

            foreach ($prefs as $key => $value) {
                save_setting($key . '_' . $user_id, $value);
            }

            $success_msg = "Preferences saved successfully!";
        } catch (Exception $e) {
            $error_msg = $e->getMessage();
        }
    }
}

// Load user preferences
$prefs = [
    'user_theme' => get_setting('user_theme_' . $user_id, 'light'),
    'user_language' => get_setting('user_language_' . $user_id, 'en'),
    'user_timezone' => get_setting('user_timezone_' . $user_id, 'Africa/Dar_es_Salaam'),
    'user_date_format' => get_setting('user_date_format_' . $user_id, 'd/m/Y'),
    'user_email_notifications' => get_setting('user_email_notifications_' . $user_id, '1'),
    'user_sms_notifications' => get_setting('user_sms_notifications_' . $user_id, '1')
];
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mt-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-0"><i class="bi bi-gear"></i> My Settings</h2>
                    <p class="text-muted">Manage your account, password, and preferences</p>
                </div>
            </div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_msg): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error_msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profileTab" type="button" role="tab">
                <i class="bi bi-person me-1"></i> Profile
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#securityTab" type="button" role="tab">
                <i class="bi bi-shield-lock me-1"></i> Security
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="preferences-tab" data-bs-toggle="tab" data-bs-target="#preferencesTab" type="button" role="tab">
                <i class="bi bi-sliders me-1"></i> Preferences
            </button>
        </li>
    </ul>

    <div class="tab-content" id="settingsTabsContent">

        <!-- Profile Tab -->
        <div class="tab-pane fade show active" id="profileTab" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 p-4">
                            <h5 class="fw-bold mb-0"><i class="bi bi-person-vcard me-2 text-primary"></i>Personal Information</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label for="username" class="form-label">Username</label>
                                        <input type="text" class="form-control bg-light" id="username" value="<?= htmlspecialchars($current_user['username'] ?? '') ?>" disabled>
                                        <div class="form-text">Username cannot be changed.</div>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" value="<?= htmlspecialchars($current_user['full_name'] ?? '') ?>" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($current_user['email'] ?? '') ?>" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="phone" class="form-label">Phone Number</label>
                                        <input type="text" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($current_user['phone'] ?? '') ?>">
                                    </div>

                                    <div class="col-12 mt-4">
                                        <button type="submit" name="update_profile" class="btn btn-primary px-4 py-2">
                                            <i class="bi bi-save me-2"></i>Save Changes
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Account Summary Sidebar -->
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 bg-light">
                        <div class="card-body p-4 text-center">
                            <div class="mb-3">
                                <div class="bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 80px; height: 80px;">
                                    <i class="bi bi-person-fill text-primary" style="font-size: 2.5rem;"></i>
                                </div>
                            </div>
                            <h5 class="fw-bold mb-1"><?= htmlspecialchars($current_user['full_name'] ?? $current_user['username']) ?></h5>
                            <p class="text-muted small mb-3"><?= htmlspecialchars($current_user['email'] ?? '') ?></p>
                            <span class="badge bg-primary rounded-pill px-3 py-2"><?= htmlspecialchars($user_role) ?></span>

                            <hr class="my-3">

                            <div class="text-start small">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">User ID:</span>
                                    <span class="fw-bold">#<?= $current_user['user_id'] ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Status:</span>
                                    <span class="badge <?= ($current_user['is_active'] ?? 1) ? 'bg-success' : 'bg-danger' ?>">
                                        <?= ($current_user['is_active'] ?? 1) ? 'Active' : 'Inactive' ?>
                                    </span>
                                </div>
                                <?php if (!empty($current_user['last_login'])): ?>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Last Login:</span>
                                    <span><?= date('d M Y, h:i A', strtotime($current_user['last_login'])) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Tab -->
        <div class="tab-pane fade" id="securityTab" role="tabpanel">
            <div class="row">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 p-4">
                            <h5 class="fw-bold mb-0"><i class="bi bi-lock me-2 text-warning"></i>Change Password</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="current_password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="new_password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Minimum 6 characters.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                                        <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div id="password-strength" class="mb-3" style="display: none;">
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar" id="strength-bar" role="progressbar" style="width: 0%"></div>
                                    </div>
                                    <small id="strength-text" class="text-muted"></small>
                                </div>

                                <button type="submit" name="change_password" class="btn btn-warning px-4 py-2">
                                    <i class="bi bi-shield-check me-2"></i>Update Password
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm rounded-4 bg-light">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-3"><i class="bi bi-shield-exclamation text-danger me-2"></i>Security Tips</h5>
                            <ul class="list-unstyled small text-muted">
                                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Use a strong, unique password</li>
                                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Don't share your credentials</li>
                                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Change password regularly</li>
                                <li class="mb-2"><i class="bi bi-check-circle text-success me-2"></i>Log out after each session</li>
                                <li><i class="bi bi-check-circle text-success me-2"></i>Use a mix of letters, numbers, and symbols</li>
                            </ul>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm rounded-4 mt-4">
                        <div class="card-body p-4">
                            <h5 class="fw-bold mb-3"><i class="bi bi-clock-history text-primary me-2"></i>Recent Activity</h5>
                            <p class="text-muted small">Your last login was:
                                <strong>
                                <?php if (!empty($current_user['last_login'])): ?>
                                    <?= date('d M Y \a\t h:i A', strtotime($current_user['last_login'])) ?>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                                </strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preferences Tab -->
        <div class="tab-pane fade" id="preferencesTab" role="tabpanel">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white border-0 p-4">
                            <h5 class="fw-bold mb-0"><i class="bi bi-sliders me-2 text-info"></i>Display & Notifications</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label for="user_theme" class="form-label">Theme</label>
                                        <select class="form-select" id="user_theme" name="user_theme">
                                            <option value="light" <?= $prefs['user_theme'] == 'light' ? 'selected' : '' ?>>Light</option>
                                            <option value="dark" <?= $prefs['user_theme'] == 'dark' ? 'selected' : '' ?>>Dark</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="user_language" class="form-label">Language</label>
                                        <select class="form-select" id="user_language" name="user_language">
                                            <option value="en" <?= $prefs['user_language'] == 'en' ? 'selected' : '' ?>>English</option>
                                            <option value="sw" <?= $prefs['user_language'] == 'sw' ? 'selected' : '' ?>>Kiswahili</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="user_timezone" class="form-label">Timezone</label>
                                        <select class="form-select" id="user_timezone" name="user_timezone">
                                            <option value="Africa/Dar_es_Salaam" <?= $prefs['user_timezone'] == 'Africa/Dar_es_Salaam' ? 'selected' : '' ?>>East Africa Time (EAT)</option>
                                            <option value="Africa/Nairobi" <?= $prefs['user_timezone'] == 'Africa/Nairobi' ? 'selected' : '' ?>>Nairobi (EAT)</option>
                                            <option value="UTC" <?= $prefs['user_timezone'] == 'UTC' ? 'selected' : '' ?>>UTC</option>
                                            <option value="America/New_York" <?= $prefs['user_timezone'] == 'America/New_York' ? 'selected' : '' ?>>Eastern Time (US)</option>
                                            <option value="Europe/London" <?= $prefs['user_timezone'] == 'Europe/London' ? 'selected' : '' ?>>London (GMT)</option>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="user_date_format" class="form-label">Date Format</label>
                                        <select class="form-select" id="user_date_format" name="user_date_format">
                                            <option value="d/m/Y" <?= $prefs['user_date_format'] == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                            <option value="m/d/Y" <?= $prefs['user_date_format'] == 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                                            <option value="Y-m-d" <?= $prefs['user_date_format'] == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                            <option value="d M Y" <?= $prefs['user_date_format'] == 'd M Y' ? 'selected' : '' ?>>DD Mon YYYY</option>
                                        </select>
                                    </div>
                                </div>

                                <hr class="my-4">

                                <h6 class="fw-bold mb-3">Notification Preferences</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="user_email_notifications" name="user_email_notifications" value="1" <?= $prefs['user_email_notifications'] == '1' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="user_email_notifications">Email Notifications</label>
                                        </div>
                                        <div class="form-text">Receive email about system events and updates</div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" id="user_sms_notifications" name="user_sms_notifications" value="1" <?= $prefs['user_sms_notifications'] == '1' ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="user_sms_notifications">SMS Notifications</label>
                                        </div>
                                        <div class="form-text">Receive SMS alerts for critical events</div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="save_preferences" class="btn btn-primary px-4 py-2">
                                        <i class="bi bi-save me-2"></i>Save Preferences
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Toggle password visibility
    $('.toggle-password').click(function() {
        const targetId = $(this).data('target');
        const input = $('#' + targetId);
        const icon = $(this).find('i');

        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('bi-eye').addClass('bi-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('bi-eye-slash').addClass('bi-eye');
        }
    });

    // Password strength meter
    $('#new_password').on('input', function() {
        const password = $(this).val();
        const strengthBar = $('#strength-bar');
        const strengthText = $('#strength-text');
        const container = $('#password-strength');

        if (password.length === 0) {
            container.hide();
            return;
        }

        container.show();
        let strength = 0;

        if (password.length >= 6) strength += 20;
        if (password.length >= 8) strength += 10;
        if (password.length >= 12) strength += 10;
        if (/[a-z]/.test(password)) strength += 15;
        if (/[A-Z]/.test(password)) strength += 15;
        if (/[0-9]/.test(password)) strength += 15;
        if (/[^a-zA-Z0-9]/.test(password)) strength += 15;

        strengthBar.css('width', strength + '%');

        if (strength < 30) {
            strengthBar.removeClass().addClass('progress-bar bg-danger');
            strengthText.text('Weak').removeClass().addClass('text-danger small');
        } else if (strength < 60) {
            strengthBar.removeClass().addClass('progress-bar bg-warning');
            strengthText.text('Fair').removeClass().addClass('text-warning small');
        } else if (strength < 80) {
            strengthBar.removeClass().addClass('progress-bar bg-info');
            strengthText.text('Good').removeClass().addClass('text-info small');
        } else {
            strengthBar.removeClass().addClass('progress-bar bg-success');
            strengthText.text('Strong').removeClass().addClass('text-success small');
        }
    });

    // Validate password match
    $('#confirm_password').on('input', function() {
        const newPass = $('#new_password').val();
        const confirmPass = $(this).val();

        if (confirmPass.length > 0 && newPass !== confirmPass) {
            $(this).addClass('is-invalid');
        } else {
            $(this).removeClass('is-invalid');
        }
    });
});
</script>

<?php
require_once __DIR__ . '/../../../footer.php';
ob_end_flush();
?>
