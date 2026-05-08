<?php
require_once __DIR__ . '/../../../roots.php';
require_once 'header.php';

// Get current user data
$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("
    SELECT u.*, r.role_name, d.department_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.role_id 
    LEFT JOIN departments d ON u.department_id = d.department_id 
    WHERE u.user_id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: logout.php');
    exit;
}

// Handle form submissions
if ($_POST) {
    $success_messages = [];
    $error_messages = [];
    
    // Update Profile
    if (isset($_POST['update_profile'])) {
        try {
            $first_name = trim($_POST['first_name']);
            $last_name = trim($_POST['last_name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            
            // Validate required fields
            if (empty($first_name) || empty($last_name) || empty($email)) {
                throw new Exception("First name, last name, and email are required");
            }
            
            // Check if email is already taken by another user
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->execute([$email, $user_id]);
            if ($stmt->fetch()) {
                throw new Exception("Email address is already taken by another user");
            }
            
            $stmt = $pdo->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?, email = ?, phone = ?, updated_at = NOW() 
                WHERE user_id = ?
            ");
            $stmt->execute([$first_name, $last_name, $email, $phone, $user_id]);
            
            // Update session data
            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
            $_SESSION['user_email'] = $email;
            
            $success_messages[] = "Profile updated successfully";
            
            // Refresh user data
            $stmt = $pdo->prepare("
                SELECT u.*, r.role_name, d.department_name 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.role_id 
                LEFT JOIN departments d ON u.department_id = d.department_id 
                WHERE u.user_id = ?
            ");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $error_messages[] = "Error updating profile: " . $e->getMessage();
        }
    }
    
    // Change Password
    if (isset($_POST['change_password'])) {
        try {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            // Validate current password
            if (!password_verify($current_password, $user['password'])) {
                throw new Exception("Current password is incorrect");
            }
            
            // Validate new password
            if (empty($new_password)) {
                throw new Exception("New password is required");
            }
            
            if (strlen($new_password) < 8) {
                throw new Exception("New password must be at least 8 characters long");
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match");
            }
            
            // Check if new password is same as current
            if (password_verify($new_password, $user['password'])) {
                throw new Exception("New password cannot be the same as current password");
            }
            
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE user_id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            
            $success_messages[] = "Password changed successfully";
            
        } catch (Exception $e) {
            $error_messages[] = "Error changing password: " . $e->getMessage();
        }
    }
    
    // Update Preferences
    if (isset($_POST['update_preferences'])) {
        try {
            $theme = $_POST['theme'];
            $language = $_POST['language'];
            $notifications_email = isset($_POST['notifications_email']) ? 1 : 0;
            $notifications_sms = isset($_POST['notifications_sms']) ? 1 : 0;
            $results_per_page = $_POST['results_per_page'];
            
            // Save preferences to database
            $preferences = [
                'theme' => $theme,
                'language' => $language,
                'notifications_email' => $notifications_email,
                'notifications_sms' => $notifications_sms,
                'results_per_page' => $results_per_page
            ];
            
            $stmt = $pdo->prepare("UPDATE users SET preferences = ?, updated_at = NOW() WHERE user_id = ?");
            $stmt->execute([json_encode($preferences), $user_id]);
            
            // Update session
            $_SESSION['user_preferences'] = $preferences;
            
            $success_messages[] = "Preferences updated successfully";
            
        } catch (Exception $e) {
            $error_messages[] = "Error updating preferences: " . $e->getMessage();
        }
    }
    
    // Upload Avatar
    if (isset($_POST['upload_avatar'])) {
        try {
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $avatar = $_FILES['avatar'];
                
                // Validate file type
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                if (!in_array($avatar['type'], $allowed_types)) {
                    throw new Exception("Only JPG, PNG, and GIF images are allowed");
                }
                
                // Validate file size (max 2MB)
                if ($avatar['size'] > 2 * 1024 * 1024) {
                    throw new Exception("Image size must be less than 2MB");
                }
                
                // Generate unique filename
                $extension = pathinfo($avatar['name'], PATHINFO_EXTENSION);
                $filename = 'avatar_' . $user_id . '_' . time() . '.' . $extension;
                $upload_path = 'uploads/avatars/' . $filename;
                
                // Create directory if it doesn't exist
                if (!is_dir('uploads/avatars')) {
                    mkdir('uploads/avatars', 0755, true);
                }
                
                // Move uploaded file
                if (move_uploaded_file($avatar['tmp_name'], $upload_path)) {
                    // Update user record with avatar path
                    $stmt = $pdo->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE user_id = ?");
                    $stmt->execute([$filename, $user_id]);
                    
                    // Update session
                    $_SESSION['user_avatar'] = $filename;
                    $user['avatar'] = $filename;
                    
                    $success_messages[] = "Avatar updated successfully";
                } else {
                    throw new Exception("Failed to upload avatar");
                }
            } else {
                throw new Exception("Please select a valid image file");
            }
        } catch (Exception $e) {
            $error_messages[] = "Error uploading avatar: " . $e->getMessage();
        }
    }
}

// Get user preferences
$preferences = [];
if (!empty($user['preferences'])) {
    $preferences = json_decode($user['preferences'], true);
} else {
    // Default preferences
    $preferences = [
        'theme' => 'light',
        'language' => 'en',
        'notifications_email' => true,
        'notifications_sms' => false,
        'results_per_page' => 25
    ];
}

// Get user activity stats
$activity_stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM loans WHERE created_by = ?) as loans_created,
        (SELECT COUNT(*) FROM loans WHERE loan_officer_id = ?) as loans_assigned,
        (SELECT COUNT(*) FROM access_log WHERE user_id = ? AND DATE(timestamp) = CURDATE()) as today_activities
");
$activity_stmt->execute([$user_id, $user_id, $user_id]);
$activity_stats = $activity_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent activity
$recent_activity_stmt = $pdo->prepare("
    SELECT action, resource, timestamp 
    FROM access_log 
    WHERE user_id = ? 
    ORDER BY timestamp DESC 
    LIMIT 10
");
$recent_activity_stmt->execute([$user_id]);
$recent_activities = $recent_activity_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-person-circle"></i> My Profile</h2>
            <p class="text-muted">Manage your account settings and preferences</p>
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

    <div class="row">
        <!-- Left Sidebar - Profile Summary -->
        <div class="col-lg-4 col-md-5">
            <!-- Profile Card -->
            <div class="card shadow mb-4">
                <div class="card-body text-center">
                    <!-- Avatar -->
                    <div class="mb-3">
                        <?php if (!empty($user['avatar'])): ?>
                            <img src="uploads/avatars/<?= htmlspecialchars($user['avatar']) ?>" 
                                 class="rounded-circle avatar-lg" alt="Avatar"
                                 style="width: 120px; height: 120px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-primary d-inline-flex align-items-center justify-content-center text-white"
                                 style="width: 120px; height: 120px; font-size: 3rem;">
                                <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <h4 class="mb-1"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></h4>
                    <p class="text-muted mb-2"><?= htmlspecialchars($user['role_name']) ?></p>
                    <p class="text-muted mb-3">
                        <i class="bi bi-building me-1"></i>
                        <?= htmlspecialchars($user['department_name'] ?? 'No Department') ?>
                    </p>
                    
                    <div class="d-grid gap-2">
                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#avatarModal">
                            <i class="bi bi-camera"></i> Change Avatar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Statistics Card -->
            <div class="card shadow mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-graph-up"></i> Activity Summary</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="text-xs font-weight-bold text-primary text-uppercase">
                                Loans Created
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= number_format($activity_stats['loans_created']) ?>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="text-xs font-weight-bold text-success text-uppercase">
                                Loans Assigned
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= number_format($activity_stats['loans_assigned']) ?>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="text-xs font-weight-bold text-warning text-uppercase">
                                Today's Activities
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?= number_format($activity_stats['today_activities']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="card shadow">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Account Information</h6>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <small class="text-muted">Username:</small>
                        <div class="fw-bold"><?= htmlspecialchars($user['username']) ?></div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Member Since:</small>
                        <div class="fw-bold"><?= date('M j, Y', strtotime($user['created_at'])) ?></div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Last Login:</small>
                        <div class="fw-bold"><?= date('M j, Y g:i A', strtotime($user['last_login'] ?? $user['created_at'])) ?></div>
                    </div>
                    <div class="mb-2">
                        <small class="text-muted">Account Status:</small>
                        <div>
                            <span class="badge bg-<?= ($user['is_active'] ?? 1) == 1 ? 'success' : 'secondary' ?>">
                                <?= ($user['is_active'] ?? 1) == 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                    </div>
                    <?php if (!empty($user['password_changed_at'])): ?>
                        <div class="mb-2">
                            <small class="text-muted">Password Last Changed:</small>
                            <div class="fw-bold"><?= date('M j, Y', strtotime($user['password_changed_at'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-8 col-md-7">
            <!-- Profile Tabs -->
            <div class="card shadow">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="profileTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" 
                                    data-bs-target="#profile" type="button" role="tab">
                                <i class="bi bi-person"></i> Profile
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="security-tab" data-bs-toggle="tab" 
                                    data-bs-target="#security" type="button" role="tab">
                                <i class="bi bi-shield-lock"></i> Security
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="preferences-tab" data-bs-toggle="tab" 
                                    data-bs-target="#preferences" type="button" role="tab">
                                <i class="bi bi-gear"></i> Preferences
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="activity-tab" data-bs-toggle="tab" 
                                    data-bs-target="#activity" type="button" role="tab">
                                <i class="bi bi-clock-history"></i> Activity
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="card-body">
                    <div class="tab-content" id="profileTabsContent">
                        <!-- Profile Tab -->
                        <div class="tab-pane fade show active" id="profile" role="tabpanel">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="first_name" class="form-label">First Name *</label>
                                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                                   value="<?= htmlspecialchars($user['first_name']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="last_name" class="form-label">Last Name *</label>
                                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                                   value="<?= htmlspecialchars($user['last_name']) ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email Address *</label>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?= htmlspecialchars($user['email']) ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="phone" class="form-label">Phone Number</label>
                                            <input type="tel" class="form-control" id="phone" name="phone" 
                                                   value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Username</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" readonly>
                                            <div class="form-text">Username cannot be changed</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Role</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars($user['role_name']) ?>" readonly>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="update_profile" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Update Profile
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Security Tab -->
                        <div class="tab-pane fade" id="security" role="tabpanel">
                            <form method="POST">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i> 
                                    For security reasons, please ensure your password is strong and unique.
                                </div>

                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password *</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="new_password" class="form-label">New Password *</label>
                                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                                            <div class="form-text">Minimum 8 characters</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="password-strength mb-3">
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar" id="passwordStrengthBar" style="width: 0%"></div>
                                    </div>
                                    <small class="text-muted" id="passwordStrengthText">Password strength</small>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        <i class="bi bi-key"></i> Change Password
                                    </button>
                                </div>
                            </form>

                            <hr class="my-4">

                            <h6 class="mb-3">Two-Factor Authentication</h6>
                            <div class="alert alert-warning">
                                <i class="bi bi-shield-exclamation"></i> 
                                Two-factor authentication is not currently enabled for your account.
                                <a href="#" class="alert-link">Enable 2FA</a> for enhanced security.
                            </div>

                            <h6 class="mb-3">Session Management</h6>
                            <div class="d-grid gap-2">
                                <button class="btn btn-outline-danger" id="logoutOtherSessions">
                                    <i class="bi bi-box-arrow-right"></i> Log Out Other Sessions
                                </button>
                            </div>
                        </div>

                        <!-- Preferences Tab -->
                        <div class="tab-pane fade" id="preferences" role="tabpanel">
                            <form method="POST">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="theme" class="form-label">Theme</label>
                                            <select class="form-control" id="theme" name="theme">
                                                <option value="light" <?= $preferences['theme'] == 'light' ? 'selected' : '' ?>>Light</option>
                                                <option value="dark" <?= $preferences['theme'] == 'dark' ? 'selected' : '' ?>>Dark</option>
                                                <option value="auto" <?= $preferences['theme'] == 'auto' ? 'selected' : '' ?>>Auto (System)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="language" class="form-label">Language</label>
                                            <select class="form-control" id="language" name="language">
                                                <option value="en" <?= $preferences['language'] == 'en' ? 'selected' : '' ?>>English</option>
                                                <option value="es" <?= $preferences['language'] == 'es' ? 'selected' : '' ?>>Spanish</option>
                                                <option value="fr" <?= $preferences['language'] == 'fr' ? 'selected' : '' ?>>French</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="results_per_page" class="form-label">Results Per Page</label>
                                    <select class="form-control" id="results_per_page" name="results_per_page">
                                        <option value="10" <?= $preferences['results_per_page'] == 10 ? 'selected' : '' ?>>10</option>
                                        <option value="25" <?= $preferences['results_per_page'] == 25 ? 'selected' : '' ?>>25</option>
                                        <option value="50" <?= $preferences['results_per_page'] == 50 ? 'selected' : '' ?>>50</option>
                                        <option value="100" <?= $preferences['results_per_page'] == 100 ? 'selected' : '' ?>>100</option>
                                    </select>
                                </div>

                                <h6 class="mt-4 mb-3">Notification Preferences</h6>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notifications_email" 
                                               name="notifications_email" value="1" 
                                               <?= $preferences['notifications_email'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="notifications_email">
                                            Email Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Receive notifications via email</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="notifications_sms" 
                                               name="notifications_sms" value="1" 
                                               <?= $preferences['notifications_sms'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="notifications_sms">
                                            SMS Notifications
                                        </label>
                                    </div>
                                    <div class="form-text">Receive notifications via SMS (if phone number provided)</div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="update_preferences" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Save Preferences
                                    </button>
                                    <button type="button" class="btn btn-secondary" id="resetPreferences">
                                        Reset to Defaults
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Activity Tab -->
                        <div class="tab-pane fade" id="activity" role="tabpanel">
                            <h6 class="mb-3">Recent Activity</h6>
                            
                            <?php if (!empty($recent_activities)): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?= htmlspecialchars($activity['action']) ?></h6>
                                                    <p class="mb-1 text-muted"><?= htmlspecialchars($activity['resource']) ?></p>
                                                </div>
                                                <small class="text-muted">
                                                    <?= date('M j, g:i A', strtotime($activity['timestamp'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-clock-history text-muted fa-3x mb-3"></i>
                                    <p class="text-muted">No recent activity found</p>
                                </div>
                            <?php endif; ?>

                            <div class="mt-4">
                                <button class="btn btn-outline-primary btn-sm" id="loadMoreActivity">
                                    <i class="bi bi-arrow-clockwise"></i> Load More
                                </button>
                                <button class="btn btn-outline-secondary btn-sm" id="exportActivity">
                                    <i class="bi bi-download"></i> Export Activity
                                </button>
                            </div>

                            <hr class="my-4">

                            <h6 class="mb-3">Login History</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>IP Address</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><?= date('M j, Y g:i A', strtotime($user['last_login'] ?? $user['created_at'])) ?></td>
                                            <td><?= $_SERVER['REMOTE_ADDR'] ?></td>
                                            <td><span class="badge bg-success">Success</span></td>
                                        </tr>
                                        <!-- More login history would be loaded here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Avatar Upload Modal -->
<div class="modal fade" id="avatarModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Profile Picture</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="avatar" class="form-label">Select Image</label>
                        <input type="file" class="form-control" id="avatar" name="avatar" accept="image/*">
                        <div class="form-text">
                            Supported formats: JPG, PNG, GIF. Max size: 2MB.
                        </div>
                    </div>
                    
                    <div class="text-center">
                        <div id="avatarPreview" class="mb-3">
                            <?php if (!empty($user['avatar'])): ?>
                                <img src="uploads/avatars/<?= htmlspecialchars($user['avatar']) ?>" 
                                     class="rounded-circle avatar-preview" alt="Current Avatar"
                                     style="width: 150px; height: 150px; object-fit: cover;">
                            <?php else: ?>
                                <div class="rounded-circle bg-primary d-inline-flex align-items-center justify-content-center text-white avatar-preview"
                                     style="width: 150px; height: 150px; font-size: 3rem;">
                                    <?= strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="upload_avatar" class="btn btn-primary">Upload Avatar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php include("footer.php"); ?>

<style>
.card {
    border: none;
    border-radius: 0.5rem;
}

.nav-tabs .nav-link {
    border: none;
    color: #6c757d;
    font-weight: 500;
}

.nav-tabs .nav-link.active {
    color: #0d6efd;
    border-bottom: 3px solid #0d6efd;
    background: transparent;
}

.avatar-lg {
    width: 120px;
    height: 120px;
    object-fit: cover;
}

.avatar-preview {
    width: 150px;
    height: 150px;
    object-fit: cover;
}

.text-xs {
    font-size: 0.7rem;
}

.shadow {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
}

.password-strength .progress {
    height: 5px;
}

.list-group-item {
    border: none;
    border-bottom: 1px solid #e9ecef;
}

.list-group-item:last-child {
    border-bottom: none;
}
</style>

<script>
$(document).ready(function() {
    // Password strength indicator
    $('#new_password').on('input', function() {
        const password = $(this).val();
        const strength = calculatePasswordStrength(password);
        
        $('#passwordStrengthBar')
            .css('width', strength.percentage + '%')
            .removeClass('bg-danger bg-warning bg-success')
            .addClass(strength.class);
        
        $('#passwordStrengthText')
            .text(strength.text)
            .removeClass('text-danger text-warning text-success')
            .addClass(strength.class.replace('bg-', 'text-'));
    });

    function calculatePasswordStrength(password) {
        let score = 0;
        
        if (password.length >= 8) score += 25;
        if (password.length >= 12) score += 25;
        if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score += 25;
        if (/\d/.test(password)) score += 15;
        if (/[^a-zA-Z0-9]/.test(password)) score += 10;
        
        if (score >= 80) {
            return { percentage: 100, class: 'bg-success', text: 'Strong password' };
        } else if (score >= 60) {
            return { percentage: 75, class: 'bg-warning', text: 'Good password' };
        } else if (score >= 30) {
            return { percentage: 50, class: 'bg-warning', text: 'Fair password' };
        } else {
            return { percentage: 25, class: 'bg-danger', text: 'Weak password' };
        }
    }

    // Avatar preview
    $('#avatar').change(function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#avatarPreview').html(`<img src="${e.target.result}" class="rounded-circle avatar-preview" alt="Preview">`);
            }
            reader.readAsDataURL(file);
        }
    });

    // Reset preferences
    $('#resetPreferences').click(function() {
        if (confirm('Are you sure you want to reset all preferences to default values?')) {
            $('#theme').val('light');
            $('#language').val('en');
            $('#results_per_page').val(25);
            $('#notifications_email').prop('checked', true);
            $('#notifications_sms').prop('checked', false);
            showToast('info', 'Preferences reset to defaults. Click "Save Preferences" to apply.');
        }
    });

    // Load more activity
    $('#loadMoreActivity').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Loading...');
        
        // Simulate loading more activity
        setTimeout(() => {
            showToast('info', 'More activity loaded');
            btn.prop('disabled', false).html(originalText);
        }, 1000);
    });

    // Export activity
    $('#exportActivity').click(function() {
        showToast('info', 'Preparing activity export...');
        setTimeout(() => {
            window.open('api/export_activity.php', '_blank');
            showToast('success', 'Activity export completed');
        }, 1500);
    });

    // Logout other sessions
    $('#logoutOtherSessions').click(function() {
        if (confirm('Are you sure you want to log out of all other sessions? This will log you out from all other devices.')) {
            const btn = $(this);
            const originalText = btn.html();
            
            btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Logging out...');
            
            $.ajax({
                url: 'api/logout_other_sessions.php',
                type: 'POST',
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('success', 'All other sessions have been logged out');
                    } else {
                        showToast('error', 'Error logging out other sessions');
                    }
                },
                error: function() {
                    showToast('error', 'Error logging out other sessions');
                },
                complete: function() {
                    btn.prop('disabled', false).html(originalText);
                }
            });
        }
    });

    // Form validation
    $('form').on('submit', function(e) {
        const form = $(this);
        
        // Password confirmation validation
        if (form.find('#new_password').length && form.find('#confirm_password').length) {
            const newPassword = $('#new_password').val();
            const confirmPassword = $('#confirm_password').val();
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                showToast('error', 'New passwords do not match');
                $('#confirm_password').focus();
            }
        }
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
});
</script>