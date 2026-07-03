<?php
// scope-audit: skip — admin-only user-management page; the employees query only
// previews the optional "Linked Employee" name (Tier 4 D24). Not project-scoped.
ob_start(); // Start output buffering to allow headers after HTML
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../core/permissions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . getUrl('login'));
    exit();
}

// Phase 2 of security_implementation_plan.md — previously any logged-in
// user could open this page and create new accounts. Now only admin or
// roles explicitly granted 'add_user' can.
autoEnforcePermission('add_user');

// Ensure role_id is set in session
if (!isset($_SESSION['role_id'])) {
    $stmt = $pdo->prepare("SELECT role_id FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_data = $stmt->fetch();
    if ($user_data) {
        $_SESSION['role_id'] = $user_data['role_id'];
    }
}

// Check admin permissions
if (!isAdmin()) {
    header("Location: " . getUrl('unauthorized'));
    exit();
}

require_once __DIR__ . '/../../../header.php';

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
$username = $email = $first_name = $last_name = $role_id = '';

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
    // Tier 4 D24 — optional "Linked Employee" (ESS). Absent = unchanged/none.
    $linked_employee_id = (isset($_POST['employee_id']) && $_POST['employee_id'] !== '') ? (int)$_POST['employee_id'] : null;

    // Validate inputs
    if (empty($username)) {
        $errors['username'] = 'Username is required';
    } elseif (strlen($username) < 4) {
        $errors['username'] = 'Username must be at least 4 characters';
    } else {
        // Check if username exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $errors['username'] = 'Username already exists';
        }
    }

    if (empty($email)) {
        $errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Invalid email format';
    } else {
        // Check if email exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
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

    if (empty($password)) {
        $errors['password'] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters';
    }

    if ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match';
    }

    // If no errors, create user
    if (empty($errors)) {
        // Hash password
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Insert user (employee_id is the Tier 4 ESS link — nullable, optional)
        $stmt = $pdo->prepare("INSERT INTO users
            (username, email, first_name, last_name, role_id, employee_id, password, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");

        if ($stmt->execute([$username, $email, $first_name, $last_name, $role_id, $linked_employee_id, $password_hash])) {
            $new_user_id = $pdo->lastInsertId();
            $success = true;

            // Log action
            logActivity($pdo, $_SESSION['user_id'], 'Create user', "User created a new user: $first_name $last_name ($username, ID $new_user_id)");
            logAudit($pdo, $_SESSION['user_id'], 'create_user', [
                'entity_type' => 'user',
                'entity_id' => $new_user_id,
                'description' => "Created new user: $username ($email)",
                'new_values' => [
                    'username' => $username,
                    'email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'role_id' => $role_id
                ]
            ]);

            // Clear form
            $username = $email = $first_name = $last_name = $role_id = '';
            
            // Set success message
            $_SESSION['success_message'] = 'User created successfully!';
            header("Location: " . getUrl('users'));
            exit();
        } else {
            $errors['database'] = 'Error creating user. Please try again.';
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
                    <li class="breadcrumb-item active" aria-current="page">Add New User</li>
                </ol>
            </nav>
            <h2><i class="bi bi-person-plus"></i> Add New User</h2>
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
                            <?php if (!empty($_POST['employee_id'])):
                                $__e = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE employee_id = ?");
                                $__e->execute([(int)$_POST['employee_id']]);
                                $__er = $__e->fetch(PDO::FETCH_ASSOC);
                                if ($__er): ?>
                                <option value="<?= (int)$_POST['employee_id'] ?>" selected><?= htmlspecialchars(trim($__er['first_name'].' '.$__er['last_name'])) ?></option>
                            <?php endif; endif; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="password" class="form-label">Password *</label>
                        <div class="input-group">
                            <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                                   id="password" name="password" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('password')">
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
                        <label for="confirm_password" class="form-label">Confirm Password *</label>
                        <div class="input-group">
                            <input type="password" class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>" 
                                   id="confirm_password" name="confirm_password" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('confirm_password')">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <?php if (isset($errors['confirm_password'])): ?>
                            <div class="invalid-feedback d-block"><?= $errors['confirm_password'] ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-12 mt-4">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-save"></i> Create User
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
    const icon = field.nextElementSibling.querySelector('i');
    
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
</script>

<?php 
include(FOOTER_FILE); 
ob_end_flush(); // Flush output buffer
?>
