<?php
// scope-audit: skip — admin-only user-management page; the employees query only
// previews the optional "Linked Employee" name (Tier 4 D24). Not project-scoped.
// Start output buffering at the very beginning
ob_start();

require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Phase 2 of security_implementation_plan.md — explicit page-level gate
// keyed on 'edit_user'. The existing canEdit('users') check below stays
// as a second layer (belt-and-suspenders).
autoEnforcePermission('edit_user');

require_once HEADER_FILE;

// Check admin permissions
if (!canEdit('users')) {
    // Clear buffer before redirect
    ob_end_clean();
    header("Location: " . getUrl('unauthorized'));
    exit();
}

// Fetch roles from database
$roles = [];
try {
    $stmt = $pdo->query("SELECT role_id, role_name FROM roles ORDER BY role_name");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors['database'] = 'Error fetching roles: ' . $e->getMessage();
}

// Initialize variables
$errors = [];
$success = false;
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Check if user exists
$user = null;
if ($user_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT u.*, r.role_name 
                              FROM users u 
                              LEFT JOIN roles r ON u.role_id = r.role_id 
                              WHERE u.user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $errors['database'] = 'Error fetching user: ' . $e->getMessage();
    }
}

if (!$user) {
    // Clear buffer before redirect
    ob_end_clean();
    header("Location: " . getUrl('users'));
    exit();
}

// Set initial form values
$username = $user['username'];
$email = $user['email'];
$first_name = $user['first_name'];
$last_name = $user['last_name'];
$role_id = $user['role_id'];
$current_role_name = $user['role_name'];
// Tier 4 D24 — current ESS link + its display name (nullable column)
$linked_employee_id = $user['employee_id'] ?? null;
$linked_employee_name = '';
if (!empty($linked_employee_id)) {
    try {
        $__le = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
        $__le->execute([(int)$linked_employee_id]);
        if ($__ler = $__le->fetch(PDO::FETCH_ASSOC)) $linked_employee_name = trim($__ler['first_name'] . ' ' . $__ler['last_name']);
    } catch (Throwable $e) {}
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $role_id = $_POST['role_id'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    // Tier 4 D24 — optional "Linked Employee" (ESS). Blank clears the link.
    $linked_employee_id = (isset($_POST['employee_id']) && $_POST['employee_id'] !== '') ? (int)$_POST['employee_id'] : null;

    // Validate inputs
    if (empty($username)) {
        $errors['username'] = 'Username is required';
    } elseif (strlen($username) < 4) {
        $errors['username'] = 'Username must be at least 4 characters';
    } else {
        // Check if username exists (excluding current user)
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $stmt->execute([$username, $user_id]);
        if ($stmt->fetch()) {
            $errors['username'] = 'Username already exists';
        }
    }

    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } else {
        // Check if email exists (excluding current user)
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Email already exists';
        }
    }

    if (empty($first_name)) {
        $errors['first_name'] = 'First name is required';
    }

    if (empty($last_name)) {
        $errors['last_name'] = 'Last name is required';
    }

    // Validate role against database values
    $valid_role_ids = array_column($roles, 'role_id');
    if (empty($role_id) || !in_array($role_id, $valid_role_ids)) {
        $errors['role_id'] = 'Invalid role selected';
    }

    // Only validate password if provided
    if (!empty($password)) {
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if ($password !== $confirm_password) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
    }

    // If no errors, update user
    if (empty($errors)) {
        try {
            // Update user with or without password change
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users
                    SET username = ?, email = ?, first_name = ?, last_name = ?, role_id = ?, employee_id = ?, password = ?
                    WHERE user_id = ?");
                $result = $stmt->execute([$username, $email, $first_name, $last_name, $role_id, $linked_employee_id, $password_hash, $user_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users
                    SET username = ?, email = ?, first_name = ?, last_name = ?, role_id = ?, employee_id = ?
                    WHERE user_id = ?");
                $result = $stmt->execute([$username, $email, $first_name, $last_name, $role_id, $linked_employee_id, $user_id]);
            }
            
            if ($result) {
                // Log action
                $description = "Updated user: $username";
                if (!empty($password)) $description .= " (Password changed)";

                logActivity($pdo, $_SESSION['user_id'], 'Edit user', "User edited user: $first_name $last_name ($username, ID $user_id)");
                logAudit($pdo, $_SESSION['user_id'], 'update_user', [
                    'entity_type' => 'user',
                    'entity_id' => $user_id,
                    'description' => $description,
                    'old_values' => [
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'role_id' => $user['role_id']
                    ],
                    'new_values' => [
                        'username' => $username,
                        'email' => $email,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'role_id' => $role_id,
                        'password_changed' => !empty($password)
                    ]
                ]);

                $success = true;
                $_SESSION['success_message'] = 'User updated successfully!';
                // Clear buffer before redirect
                ob_end_clean();
                header("Location: users.php");
                exit();
            } else {
                $errors['database'] = 'Error updating user. Please try again.';
            }
        } catch (PDOException $e) {
            $errors['database'] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= getUrl('users') ?>">Users</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit User</li>
                </ol>
            </nav>
            <h2><i class="bi bi-person-gear"></i> Edit User: <?php echo htmlspecialchars($user['username']); ?></h2>
            <p class="text-muted">Edit user information and permissions</p>
        </div>
    </div>

        <?php if (!empty($errors['database'])): ?>
            <div class="alert alert-danger"><?= $errors['database'] ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" novalidate>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="username" class="form-label">Username *</label>
                            <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>" 
                                   id="username" name="username" value="<?= htmlspecialchars($username) ?>" required>
                            <?php if (isset($errors['username'])): ?>
                                <div class="invalid-feedback"><?= $errors['username'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                                   id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                            <?php if (isset($errors['email'])): ?>
                                <div class="invalid-feedback"><?= $errors['email'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="first_name" class="form-label">First Name *</label>
                            <input type="text" class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" 
                                   id="first_name" name="first_name" value="<?= htmlspecialchars($first_name) ?>" required>
                            <?php if (isset($errors['first_name'])): ?>
                                <div class="invalid-feedback"><?= $errors['first_name'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="last_name" class="form-label">Last Name *</label>
                            <input type="text" class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" 
                                   id="last_name" name="last_name" value="<?= htmlspecialchars($last_name) ?>" required>
                            <?php if (isset($errors['last_name'])): ?>
                                <div class="invalid-feedback"><?= $errors['last_name'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="role_id" class="form-label">Role *</label>
                            <select class="form-select <?= isset($errors['role_id']) ? 'is-invalid' : '' ?>" 
                                    id="role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role['role_id'] ?>" <?= $role_id == $role['role_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($role['role_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['role_id'])): ?>
                                <div class="invalid-feedback"><?= $errors['role_id'] ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label for="employee_id" class="form-label">Linked Employee <small class="text-muted">(optional — enables self-service)</small></label>
                            <select class="form-select" id="employee_id" name="employee_id" style="width:100%">
                                <?php if (!empty($linked_employee_id) && $linked_employee_name !== ''): ?>
                                <option value="<?= (int)$linked_employee_id ?>" selected><?= htmlspecialchars($linked_employee_name) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="password" class="form-label">New Password (leave blank to keep current)</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                       id="password" name="password">
                                <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePasswordVisibility('password')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['password'])): ?>
                                <div class="invalid-feedback d-block"><?= $errors['password'] ?></div>
                            <?php else: ?>
                                <small class="text-muted">Minimum 8 characters</small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                       id="confirm_password" name="confirm_password">
                                <button type="button" class="btn btn-outline-secondary password-toggle" onclick="togglePasswordVisibility('confirm_password')">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['confirm_password'])): ?>
                                <div class="invalid-feedback d-block"><?= $errors['confirm_password'] ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-12 mt-4">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-check-circle"></i> Update User
                            </button>
                            <a href="<?= getUrl('users') ?>" class="btn btn-outline-secondary px-4 shadow-sm">
                                <i class="bi bi-x-circle"></i> Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Password visibility toggle
    function togglePasswordVisibility(fieldId) {
        const field = document.getElementById(fieldId);
        const icon = field.parentNode.querySelector('i');
        
        if (field.type === 'password') {
            field.type = 'text';
            icon.classList.remove('bi-eye');
            icon.classList.add('bi-eye-slash');
        } else {
            field.type = 'password';
            icon.classList.remove('bi-eye-slash');
            icon.classList.add('bi-eye');
        }
    }

    // Tier 4 D24 — "Linked Employee" Select2 AJAX picker
    $(function () {
        if (window.jQuery && $.fn.select2) {
            $('#employee_id').select2({
                theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: 'Not linked',
                minimumInputLength: 1,
                ajax: {
                    url: '<?= buildUrl('api/account/search_employees.php') ?>',
                    dataType: 'json', delay: 300,
                    data: params => ({ q: params.term }),
                    processResults: data => ({ results: data.results }),
                    cache: true
                }
            });
        }
    });

    // Form validation
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('form');
        
        form.addEventListener('submit', function(event) {
            let valid = true;
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            // Only validate passwords if they are provided
            if (password !== '' || confirmPassword !== '') {
                if (password.length < 8) {
                    valid = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Password must be at least 8 characters long',
                        confirmButtonColor: '#6c757d'
                    });
                } else if (password !== confirmPassword) {
                    valid = false;
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Passwords do not match',
                        confirmButtonColor: '#6c757d'
                    });
                }
            }
            
            if (!valid) {
                event.preventDefault();
            }
        });
    });
    </script>
<?php include(FOOTER_FILE); ?>

<?php 
// Close database connection
$pdo = null;

// Flush the output buffer
if (ob_get_level() > 0) {
    ob_end_flush();
}
?>