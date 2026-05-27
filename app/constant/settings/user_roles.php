<?php
$page_title = "User Roles & Permissions";
require_once __DIR__ . '/../../../roots.php';
require_once 'core/permissions.php';

// Only admins can manage roles
if (!canView('user_roles')) {
    header("Location: unauthorized.php");
    exit();
}

// Handle session messages
$success_messages = $_SESSION['role_success_messages'] ?? [];
$error_messages = $_SESSION['role_error_messages'] ?? [];
unset($_SESSION['role_success_messages'], $_SESSION['role_error_messages']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add/Edit Role
    if (isset($_POST['save_role'])) {
        try {
            $role_id = (isset($_POST['role_id']) && $_POST['role_id'] !== '') ? $_POST['role_id'] : null;
            $role_name = trim($_POST['role_name']);
            $role_description = trim($_POST['role_description']);
            $submitted_permissions = $_POST['perms'] ?? []; // perms[id][action]
            
            // Validate role name
            if (empty($role_name)) {
                throw new Exception("Role name is required");
            }

            // Check if role name already exists (excluding current role if editing)
            if ($role_id) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_name = ? AND role_id != ?");
                $stmt->execute([$role_name, $role_id]);
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE role_name = ?");
                $stmt->execute([$role_name]);
            }
            
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("A role with the name '$role_name' already exists.");
            }
            
            $pdo->beginTransaction();

            if ($role_id) {
                // Update existing role
                $stmt = $pdo->prepare("UPDATE roles SET role_name = ?, description = ?, updated_at = NOW() WHERE role_id = ?");
                $stmt->execute([$role_name, $role_description, $role_id]);
                
                // Delete existing permissions for this role to avoid duplicates
                $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
                $stmt->execute([$role_id]);
                
                $message = "Role updated successfully";
            } else {
                // Create new role
                $stmt = $pdo->prepare("INSERT INTO roles (role_name, description, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$role_name, $role_description]);
                $role_id = $pdo->lastInsertId();
                
                $message = "Role created successfully";
            }
            
            // Add granular permissions
            $stmt = $pdo->prepare("INSERT INTO role_permissions
                (role_id, permission_id, can_view, can_create, can_edit, can_delete, can_review, can_approve)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($submitted_permissions as $perm_id => $actions) {
                $can_view    = isset($actions['view'])    ? 1 : 0;
                $can_create  = isset($actions['create'])  ? 1 : 0;
                $can_edit    = isset($actions['edit'])    ? 1 : 0;
                $can_delete  = isset($actions['delete'])  ? 1 : 0;
                $can_review  = isset($actions['review'])  ? 1 : 0;
                $can_approve = isset($actions['approve']) ? 1 : 0;

                if ($can_view || $can_create || $can_edit || $can_delete || $can_review || $can_approve) {
                    $stmt->execute([$role_id, $perm_id, $can_view, $can_create, $can_edit, $can_delete, $can_review, $can_approve]);
                }
            }
            
            $pdo->commit();
            $success_messages[] = $message;

            // Log action — to both audit_logs (rich detail) AND activity_logs
            // (so the change shows up on app/activity_log.php where security
            // staff watch for permission grants/revokes).
            logAudit($pdo, $_SESSION['user_id'], $role_id ? 'update_role' : 'create_role', [
                'entity_type' => 'role',
                'entity_id' => $role_id,
                'description' => "$message: $role_name",
                'new_values' => [
                    'role_name' => $role_name,
                    'permissions_count' => count($submitted_permissions)
                ]
            ]);
            logActivity(
                $pdo,
                $_SESSION['user_id'],
                $role_id ? 'Updated role permissions' : 'Created role',
                "$message: '$role_name' (" . count($submitted_permissions) . " permission row(s))"
            );
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_messages[] = "Error saving role: " . $e->getMessage();
        }
    }
    
    // Delete Role
    if (isset($_POST['delete_role'])) {
        try {
            $role_id = $_POST['role_id'];
            
            // Check if role is in use
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
            $stmt->execute([$role_id]);
            $user_count = $stmt->fetchColumn();
            
            if ($user_count > 0) {
                throw new Exception("Cannot delete role. There are $user_count users assigned to this role.");
            }
            
            // Delete role permissions
            $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$role_id]);
            
            // Delete role
            $stmt = $pdo->prepare("DELETE FROM roles WHERE role_id = ?");
            $stmt->execute([$role_id]);
            
            $success_messages[] = "Role deleted successfully";

            // Log action — to both audit_logs AND activity_logs.
            logAudit($pdo, $_SESSION['user_id'], 'delete_role', [
                'entity_type' => 'role',
                'entity_id' => $role_id,
                'description' => "Deleted role ID: $role_id",
                'old_values' => ['role_id' => $role_id]
            ]);
            logActivity(
                $pdo,
                $_SESSION['user_id'],
                'Deleted role',
                "Deleted role ID $role_id"
            );
        } catch (Exception $e) {
            $error_messages[] = "Error deleting role: " . $e->getMessage();
        }
    }
    
    // Update User Role
    if (isset($_POST['update_user_role'])) {
        try {
            $user_id = $_POST['user_id'];
            $role_id = $_POST['role_id'];
            
            $stmt = $pdo->prepare("UPDATE users SET role_id = ? WHERE user_id = ?");
            $stmt->execute([$role_id, $user_id]);
            
            $success_messages[] = "User role updated successfully";

            // Log action — to both audit_logs AND activity_logs.
            logAudit($pdo, $_SESSION['user_id'], 'update_user_role', [
                'entity_type' => 'user',
                'entity_id' => $user_id,
                'description' => "Updated role for user ID: $user_id to role ID: $role_id",
                'new_values' => ['role_id' => $role_id]
            ]);
            logActivity(
                $pdo,
                $_SESSION['user_id'],
                'Changed user role assignment',
                "Set user ID $user_id to role ID $role_id"
            );
        } catch (Exception $e) {
            $error_messages[] = "Error updating user role: " . $e->getMessage();
        }
    }

    // Store messages in session and redirect to prevent form resubmission on refresh
    if (!empty($success_messages)) $_SESSION['role_success_messages'] = $success_messages;
    if (!empty($error_messages)) $_SESSION['role_error_messages'] = $error_messages;
    
    // Redirect back to the same page so you continue modifying roles/permissions and NOT dashboard
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Now we can safe include header as no more redirects happen
require_once 'header.php';

// Load all roles
$roles_stmt = $pdo->query("
    SELECT r.*, COUNT(u.user_id) as user_count 
    FROM roles r 
    LEFT JOIN users u ON r.role_id = u.role_id 
    GROUP BY r.role_id 
    ORDER BY r.role_name
");
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load all permissions (exclude hidden/disabled keys such as unused modules)
$permissions_stmt = $pdo->query("
    SELECT permission_id, page_key, page_name, description, module_name
    FROM permissions
    WHERE COALESCE(is_hidden, 0) = 0
    ORDER BY COALESCE(module_name, 'Other'), page_name
");
$permissions = $permissions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group permissions by module
$permissions_by_module = [];
foreach ($permissions as $permission) {
    $module_name = $permission['module_name'] ?? 'Other';
    if (!isset($permissions_by_module[$module_name])) {
        $permissions_by_module[$module_name] = [];
    }
    $permissions_by_module[$module_name][] = $permission;
}

// Load all users with their roles
$users_stmt = $pdo->query("
    SELECT u.user_id, u.username, u.first_name, u.last_name, u.email, u.is_active AS status, 
           r.role_name, r.role_id
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.role_id 
    ORDER BY u.first_name, u.last_name
");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get permission statistics
$stats_stmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM roles) as total_roles,
        (SELECT COUNT(*) FROM permissions) as total_permissions,
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(DISTINCT module_name) FROM permissions) as total_modules
");
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Helper function for role badge colors
function getRoleBadgeColor($role_name) {
    switch ($role_name) {
        case 'Admin': return 'danger';
        case 'Managing Director': return 'warning';
        case 'Director': return 'warning';
        case 'CFO': return 'info';
        case 'Accountant': return 'info';
        default: return 'secondary';
    }
}
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-person-badge"></i> User Roles & Permissions</h2>
            <p class="text-muted">Manage user roles, permissions, and access control across the system</p>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($success_messages)): ?>
        <script>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?= addslashes(implode("\\n", $success_messages)) ?>',
                timer: 3000
            });
        </script>
    <?php endif; ?>

    <?php if (!empty($error_messages)): ?>
        <script>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '<?= addslashes(implode("\\n", $error_messages)) ?>'
            });
        </script>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <style>
        .custom-stat-card {
            background-color: #d1e7dd !important;
            border-color: #badbcc !important;
            transition: transform 0.2s;
            border-radius: 12px;
        }
        .custom-stat-card:hover { transform: translateY(-3px); }
        .custom-stat-card h3, 
        .custom-stat-card h4, 
        .custom-stat-card p, 
        .custom-stat-card i,
        .custom-stat-card .small {
            color: #0f5132 !important;
        }
    </style>
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= number_format($stats['total_roles']) ?></h4>
                            <p class="small mb-0 opacity-75 text-uppercase">Total Roles</p>
                        </div>
                        <i class="bi bi-person-badge opacity-50 fs-2"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= number_format($stats['total_permissions']) ?></h4>
                            <p class="small mb-0 opacity-75 text-uppercase">Total Permissions</p>
                        </div>
                        <i class="bi bi-shield-check opacity-50 fs-2"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= number_format($stats['total_users']) ?></h4>
                            <p class="small mb-0 opacity-75 text-uppercase">System Users</p>
                        </div>
                        <i class="bi bi-people opacity-50 fs-2"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-0 fw-bold"><?= number_format($stats['total_modules']) ?></h4>
                            <p class="small mb-0 opacity-75 text-uppercase">System Modules</p>
                        </div>
                        <i class="bi bi-puzzle opacity-50 fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Tabs -->
    <div class="card shadow">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="rolesTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="roles-tab" data-bs-toggle="tab" 
                            data-bs-target="#roles" type="button" role="tab">
                        <i class="bi bi-person-badge"></i> Roles Management
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="users-tab" data-bs-toggle="tab" 
                            data-bs-target="#users" type="button" role="tab">
                        <i class="bi bi-people"></i> User Assignments
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="audit-tab" data-bs-toggle="tab" 
                            data-bs-target="#audit" type="button" role="tab">
                        <i class="bi bi-clock-history"></i> Access Audit
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body">
            <div class="tab-content" id="rolesTabsContent">
                <!-- Roles Management Tab -->
                <div class="tab-pane fade show active" id="roles" role="tabpanel">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">System Roles</h5>
                                    <button class="btn btn-primary btn-sm" id="addRoleBtn">
                                        <i class="bi bi-plus-circle"></i> Add Role
                                    </button>
                                </div>
                                <div class="card-body">
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($roles as $role): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?= htmlspecialchars($role['role_name']) ?></h6>
                                                    <p class="mb-1 text-muted small"><?= htmlspecialchars($role['description'] ?? '') ?></p>
                                                    <small class="text-muted">
                                                        <?= $role['user_count'] ?> user(s)
                                                    </small>
                                                </div>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-primary edit-role" 
                                                            data-role-id="<?= $role['role_id'] ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if ($role['user_count'] == 0 && $role['role_name'] != 'Administrator'): ?>
                                                        <button class="btn btn-sm btn-outline-danger delete-role" 
                                                                data-role-id="<?= $role['role_id'] ?>" 
                                                                data-role-name="<?= htmlspecialchars($role['role_name']) ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0" id="roleFormTitle">Add New Role</h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="roleForm">
                                        <input type="hidden" id="role_id" name="role_id">
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="role_name" class="form-label">Role Name *</label>
                                                    <input type="text" class="form-control" id="role_name" name="role_name" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="role_description" class="form-label">Description</label>
                                                    <input type="text" class="form-control" id="role_description" name="role_description">
                                                </div>
                                            </div>
                                        </div>

                                        <h6 class="mt-4 mb-3 d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-shield-lock me-2"></i>Configure Permissions</span>
                                            <div class="input-group input-group-sm w-50">
                                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                                <input type="text" class="form-control" id="permSearch" placeholder="Search permissions...">
                                            </div>
                                        </h6>
                                        
                                        <div class="permissions-matrix-container border rounded overflow-hidden">
                                            <!-- Module Tabs -->
                                            <ul class="nav nav-tabs bg-light px-3 pt-2" id="moduleTabs" role="tablist">
                                                <?php 
                                                $first = true;
                                                foreach ($permissions_by_module as $module_name => $module_permissions): 
                                                    $tabId = 'tab-' . md5($module_name);
                                                ?>
                                                    <li class="nav-item" role="presentation">
                                                        <button class="nav-link <?= $first ? 'active' : '' ?> small fw-bold" 
                                                                id="<?= $tabId ?>-tab" 
                                                                data-bs-toggle="tab" 
                                                                data-bs-target="#<?= $tabId ?>" 
                                                                type="button" role="tab">
                                                            <?= htmlspecialchars($module_name) ?>
                                                            <span class="badge bg-secondary-subtle text-secondary rounded-pill ms-1"><?= count($module_permissions) ?></span>
                                                        </button>
                                                    </li>
                                                <?php 
                                                $first = false;
                                                endforeach; 
                                                ?>
                                            </ul>
                                            
                                            <!-- Module Content -->
                                            <div class="tab-content p-0" id="moduleTabsContent">
                                                <?php 
                                                $first = true;
                                                foreach ($permissions_by_module as $module_name => $module_permissions): 
                                                    $tabId = 'tab-' . md5($module_name);
                                                ?>
                                                    <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="<?= $tabId ?>" role="tabpanel">
                                                        <div class="table-responsive">
                                                            <table class="table table-hover table-sm align-middle mb-0">
                                                                <thead class="bg-light-subtle sticky-top">
                                                                    <tr>
                                                                        <th class="ps-3" style="width: 22%;">Feature / Page</th>
                                                                        <th class="text-center" style="width: 12%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small text-muted mb-1">VIEW</span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="view">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 12%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small text-muted mb-1">CREATE</span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="create">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 12%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small text-muted mb-1">EDIT</span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="edit">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 12%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small text-muted mb-1">DELETE</span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="delete">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 13%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small mb-1" style="color:#0d6efd;font-weight:700;">REVIEW</span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="review">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 13%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small mb-1" style="color:#198754;font-weight:700;">APPROVE</span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="approve">
                                                                            </div>
                                                                        </th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($module_permissions as $permission): ?>
                                                                        <tr class="perm-row">
                                                                            <td class="ps-3">
                                                                                <div class="fw-bold small perm-name"><?= htmlspecialchars($permission['page_name'] ?? '') ?></div>
                                                                                <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars($permission['description'] ?? '') ?></div>
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input type="checkbox" class="form-check-input perm-check view"
                                                                                       name="perms[<?= $permission['permission_id'] ?>][view]"
                                                                                       data-perm-id="<?= $permission['permission_id'] ?>"
                                                                                       data-module="<?= $tabId ?>">
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input type="checkbox" class="form-check-input perm-check create"
                                                                                       name="perms[<?= $permission['permission_id'] ?>][create]"
                                                                                       data-perm-id="<?= $permission['permission_id'] ?>"
                                                                                       data-module="<?= $tabId ?>">
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input type="checkbox" class="form-check-input perm-check edit"
                                                                                       name="perms[<?= $permission['permission_id'] ?>][edit]"
                                                                                       data-perm-id="<?= $permission['permission_id'] ?>"
                                                                                       data-module="<?= $tabId ?>">
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input type="checkbox" class="form-check-input perm-check delete"
                                                                                       name="perms[<?= $permission['permission_id'] ?>][delete]"
                                                                                       data-perm-id="<?= $permission['permission_id'] ?>"
                                                                                       data-module="<?= $tabId ?>">
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input type="checkbox" class="form-check-input perm-check review"
                                                                                       name="perms[<?= $permission['permission_id'] ?>][review]"
                                                                                       data-perm-id="<?= $permission['permission_id'] ?>"
                                                                                       data-module="<?= $tabId ?>"
                                                                                       style="accent-color:#0d6efd;">
                                                                            </td>
                                                                            <td class="text-center">
                                                                                <input type="checkbox" class="form-check-input perm-check approve"
                                                                                       name="perms[<?= $permission['permission_id'] ?>][approve]"
                                                                                       data-perm-id="<?= $permission['permission_id'] ?>"
                                                                                       data-module="<?= $tabId ?>"
                                                                                       style="accent-color:#198754;">
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                <?php 
                                                $first = false;
                                                endforeach; 
                                                ?>
                                            </div>
                                        </div>

                                        <div class="mt-4 pt-3 border-top d-flex justify-content-between">
                                            <button type="button" class="btn btn-light border" id="cancelEdit">
                                                <i class="bi bi-x-circle me-1"></i> Cancel
                                            </button>
                                            <button type="submit" name="save_role" class="btn btn-primary px-4 shadow-sm">
                                                <i class="bi bi-check-circle me-1"></i> Save Access Level
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- User Assignments Tab -->
                <div class="tab-pane fade" id="users" role="tabpanel">
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">User Role Assignments</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="usersTable">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Username</th>
                                                    <th>Department</th>
                                                    <th>Current Role</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($users as $user): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></strong>
                                                            <br><small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
                                                        </td>
                                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                                        <td><?= htmlspecialchars($user['department_name'] ?? 'N/A') ?></td>
                                                        <td>
                                                            <span class="badge bg-<?= getRoleBadgeColor($user['role_name']) ?>">
                                                                <?= htmlspecialchars($user['role_name'] ?? 'Unassigned') ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'secondary' ?>">
                                                                <?= ucfirst($user['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary assign-role" 
                                                                    data-user-id="<?= $user['user_id'] ?>"
                                                                    data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>"
                                                                    data-current-role="<?= $user['role_id'] ?>">
                                                                <i class="bi bi-person-gear"></i> Assign Role
                                                            </button>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- Access Audit Tab -->
                <div class="tab-pane fade" id="audit" role="tabpanel">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Access Log</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="accessLogTable">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Action</th>
                                                    <th>Resource</th>
                                                    <th>Timestamp</th>
                                                    <th>IP Address</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">
                                                        Loading access log...
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">Access Statistics</h5>
                                </div>
                                <div class="card-body">
                                    <div id="accessStats">
                                        <div class="text-center text-muted">
                                            <i class="bi bi-hourglass-split"></i><br>
                                            Loading statistics...
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-3">
                                <div class="card-header">
                                    <h5 class="mb-0">Quick Actions</h5>
                                </div>
                                <div class="card-body">
                                    <button class="btn btn-outline-primary btn-sm w-100 mb-2" id="generateAccessReport">
                                        <i class="bi bi-file-earmark-text"></i> Generate Access Report
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm w-100 mb-2" id="clearOldLogs">
                                        <i class="bi bi-trash"></i> Clear Old Logs
                                    </button>
                                    <button class="btn btn-outline-info btn-sm w-100" id="refreshAudit">
                                        <i class="bi bi-arrow-clockwise"></i> Refresh Data
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Assign Role Modal -->
<div class="modal fade" id="assignRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assign Role to User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" id="assign_user_id" name="user_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">User</label>
                        <input type="text" class="form-control" id="assign_user_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="assign_role_id" class="form-label">Select Role *</label>
                        <select class="form-control" id="assign_role_id" name="role_id" required>
                            <option value="">Select a role...</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_user_role" class="btn btn-primary">Assign Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Role Modal -->
<div class="modal fade" id="deleteRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete the role "<strong id="deleteRoleName"></strong>"?</p>
                <p class="text-danger">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <form method="POST">
                    <input type="hidden" id="delete_role_id" name="role_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="delete_role" class="btn btn-danger">Delete Role</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php include("footer.php"); ?>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

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

.border-left-primary { border-left: 0.25rem solid #4e73df !important; }
.border-left-success { border-left: 0.25rem solid #1cc88a !important; }
.border-left-info { border-left: 0.25rem solid #36b9cc !important; }
.border-left-warning { border-left: 0.25rem solid #f6c23e !important; }

.text-xs {
    font-size: 0.7rem;
}

.shadow {
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15) !important;
}

.permissions-container .card-header {
    background-color: #f8f9fa;
    padding: 0.5rem 1rem;
}

.module-checkbox {
    margin-right: 0.5rem;
}

#permissionsMatrix th {
    font-size: 0.8rem;
    white-space: nowrap;
}

#permissionsMatrix td {
    font-size: 0.8rem;
    vertical-align: middle;
}
</style>

<script>
$(document).ready(function() {
    // Initialize DataTables
    $('#usersTable').DataTable({
        pageLength: 25,
        order: [[0, 'asc']]
    });

    $('#permissionsMatrix').DataTable({
        pageLength: 50,
        order: [[1, 'asc'], [0, 'asc']],
        scrollX: true
    });

    // --- Permissions Matrix Logic ---

    // Select All Columns
    $('.select-all-col').change(function() {
        const type = $(this).data('type');
        const moduleHash = $(this).data('module');
        const isChecked = $(this).is(':checked');
        $(`#${moduleHash} .perm-check.${type}`).prop('checked', isChecked).trigger('change');
    });

    $(document).on('change', '.perm-check', function() {
        const permId = $(this).data('perm-id');
        const isChecked = $(this).is(':checked');
        
        // If create/edit/delete/review/approve is checked, VIEW must be checked
        if (isChecked && ($(this).hasClass('create') || $(this).hasClass('edit') || 
                          $(this).hasClass('delete') || $(this).hasClass('review') || 
                          $(this).hasClass('approve'))) {
            $(`.perm-check.view[data-perm-id="${permId}"]`).prop('checked', true);
        }
        
        // If VIEW is unchecked, everything else must be unchecked
        if (!isChecked && $(this).hasClass('view')) {
            $(`.perm-check[data-perm-id="${permId}"]`).not('.view').prop('checked', false);
        }
    });

    // Simple Search Filter for Permissions
    $('#permSearch').on('keyup', function() {
        const value = $(this).val().toLowerCase();
        $('.perm-row').filter(function() {
            $(this).toggle($(this).find('.perm-name').text().toLowerCase().indexOf(value) > -1)
        });
        
        // If results are in other tabs, maybe we should show them? 
        // For now, it stays within current tab but filters rows.
    });

    // --- Role Actions ---

    // Add Role Button
    $('#addRoleBtn').click(function() {
        $('#roleFormTitle').html('<i class="bi bi-plus-circle me-2"></i>Create New System Role');
        $('#roleForm')[0].reset();
        $('#role_id').val('');
        $('.perm-check').prop('checked', false);
        $('.select-all-col').prop('checked', false);
        // Switch to first tab
        $('#moduleTabs button:first').tab('show');
    });

    // Edit Role
    $('.edit-role').click(function() {
        const roleId = $(this).data('role-id');
        
        $.ajax({
            url: 'ajax/get_role.php',
            type: 'GET',
            data: { role_id: roleId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#roleFormTitle').html('<i class="bi bi-pencil-square me-2"></i>Modifying Role: <span class="text-primary">' + response.role.role_name + '</span>');
                    $('#role_id').val(response.role.role_id);
                    $('#role_name').val(response.role.role_name);
                    $('#role_description').val(response.role.description);
                    
                    // Clear all checkboxes
                    $('.perm-check').prop('checked', false);
                    $('.select-all-col').prop('checked', false);
                    
                    // Check assigned permissions
                    // response.permissions is now {id: {view: 1, create: 0, ...}}
                    Object.keys(response.permissions).forEach(permId => {
                        const actions = response.permissions[permId];
                        if (actions.view)    $(`.perm-check.view[data-perm-id="${permId}"]`).prop('checked', true);
                        if (actions.create)  $(`.perm-check.create[data-perm-id="${permId}"]`).prop('checked', true);
                        if (actions.edit)    $(`.perm-check.edit[data-perm-id="${permId}"]`).prop('checked', true);
                        if (actions.delete)  $(`.perm-check.delete[data-perm-id="${permId}"]`).prop('checked', true);
                        if (actions.review)  $(`.perm-check.review[data-perm-id="${permId}"]`).prop('checked', true);
                        if (actions.approve) $(`.perm-check.approve[data-perm-id="${permId}"]`).prop('checked', true);
                    });
                    
                    // Scroll to form
                    $('html, body').animate({
                        scrollTop: $("#roleForm").offset().top - 100
                    }, 500);

                } else {
                    showToast('error', response.message || 'Error loading role details');
                }
            },
            error: function() {
                showToast('error', 'Error loading role details');
            }
        });
    });

    // Delete Role
    $('.delete-role').click(function() {
        const roleId = $(this).data('role-id');
        const roleName = $(this).data('role-name');
        
        $('#deleteRoleName').text(roleName);
        $('#delete_role_id').val(roleId);
        $('#deleteRoleModal').modal('show');
    });

    // Cancel Edit
    $('#cancelEdit').click(function() {
        $('#roleFormTitle').html('<i class="bi bi-plus-circle me-2"></i>Create New System Role');
        $('#roleForm')[0].reset();
        $('#role_id').val('');
        $('.perm-check').prop('checked', false);
        $('.select-all-col').prop('checked', false);
    });

    // Assign Role to User
    $('.assign-role').click(function() {
        const userId = $(this).data('user-id');
        const userName = $(this).data('user-name');
        const currentRole = $(this).data('current-role');
        
        $('#assign_user_id').val(userId);
        $('#assign_user_name').val(userName);
        $('#assign_role_id').val(currentRole);
        $('#assignRoleModal').modal('show');
    });

    // Export Permissions Matrix
    $('#exportMatrix').click(function() {
        window.open('ajax/export_permissions_matrix.php', '_blank');
        showToast('info', 'Exporting permissions matrix...');
    });

    // Load access log
    function loadAccessLog() {
        $.ajax({
            url: 'ajax/get_access_log.php',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    let html = '';
                    if (response.logs.length > 0) {
                        response.logs.forEach(log => {
                            html += `
                                <tr>
                                    <td>${log.user_name}</td>
                                    <td>${log.action}</td>
                                    <td>${log.resource}</td>
                                    <td>${log.timestamp}</td>
                                    <td>${log.ip_address}</td>
                                </tr>
                            `;
                        });
                    } else {
                        html = '<tr><td colspan="5" class="text-center text-muted">No access logs found</td></tr>';
                    }
                    $('#accessLogTable tbody').html(html);
                    
                    // Update statistics
                    $('#accessStats').html(`
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Total Logs:</span>
                                <strong>${response.stats.total_logs}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Today's Activities:</span>
                                <strong>${response.stats.today_activities}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Most Active User:</span>
                                <strong>${response.stats.most_active_user}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>Last Updated:</span>
                                <strong>${response.stats.last_updated}</strong>
                            </div>
                        </div>
                    `);
                }
            },
            error: function() {
                $('#accessLogTable tbody').html('<tr><td colspan="5" class="text-center text-danger">Error loading access log</td></tr>');
            }
        });
    }

    // Generate Access Report
    $('#generateAccessReport').click(function() {
        const btn = $(this);
        const originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Generating...');
        
        setTimeout(() => {
            showToast('success', 'Access report generated successfully!');
            btn.prop('disabled', false).html(originalText);
            window.open('ajax/generate_access_report.php', '_blank');
        }, 2000);
    });

    // Clear Old Logs
    $('#clearOldLogs').click(function() {
        Swal.fire({
            title: 'Are you sure?',
            text: 'Are you sure you want to clear logs older than 90 days? This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, clear them!'
        }).then((result) => {
            if (result.isConfirmed) {
                const btn = $(this);
                const originalText = btn.html();
                
                btn.prop('disabled', true).html('<i class="bi bi-hourglass-split"></i> Clearing...');
                
                $.ajax({
                    url: 'ajax/clear_old_logs.php',
                    type: 'POST',
                    data: { days: 90 },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            showToast('success', 'Old logs cleared successfully!');
                            loadAccessLog();
                        } else {
                            showToast('error', 'Error clearing logs: ' + (response.message || 'Unknown error'));
                        }
                    },
                    error: function() {
                        showToast('error', 'Error clearing logs');
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            }
        });
    });

    // Refresh Audit Data
    $('#refreshAudit').click(function() {
        loadAccessLog();
        showToast('info', 'Audit data refreshed');
    });

    // Toast notification function
    function showToast(type, message) {
        Swal.fire({
            icon: type,
            title: type === 'success' ? 'Success!' : (type === 'error' ? 'Error!' : 'Information'),
            text: message,
            timer: 3000
        });
    }

    // Load initial access log
    loadAccessLog();
});
</script>
