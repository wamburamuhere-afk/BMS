<?php
$page_title = "User Roles & Permissions";
require_once __DIR__ . '/../../../roots.php';
require_once 'core/permissions.php';
require_once 'core/role_permission_ui.php';

// Role/permission management is strictly admin-only by design — it is NOT
// delegable via itself no matter what is granted (the 'user_roles'
// permission row is hidden from this very UI entirely; see
// migrations/2026_07_29_lock_sensitive_settings.php). This is the page that
// defines what every role can do — a non-admin with edit access here could
// grant their own role admin-equivalent power, a direct escalation path.
if (!canView('user_roles') || !isAdmin()) {
    header("Location: unauthorized.php");
    exit();
}

// Handle session messages
$success_messages = $_SESSION['role_success_messages'] ?? [];
$error_messages = $_SESSION['role_error_messages'] ?? [];
unset($_SESSION['role_success_messages'], $_SESSION['role_error_messages']);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Every form on this page carries the session's CSRF token: this page decides what every role may do,
    // so a forged request (another site making the admin's browser post here) must never change a right.
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['_csrf'] ?? ''))) {
        $_SESSION['role_error_messages'] = [t('Your session expired. Please try again.')];
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
    $__actor = (int)($_SESSION['user_id'] ?? 0);

    // Add/Edit Role
    if (isset($_POST['save_role'])) {
        try {
            $role_id = (isset($_POST['role_id']) && $_POST['role_id'] !== '') ? (int)$_POST['role_id'] : null;
            $is_new = $role_id === null;
            $role_name = trim((string)($_POST['role_name'] ?? ''));
            $role_description = trim((string)($_POST['role_description'] ?? ''));
            $submitted_permissions = $_POST['perms'] ?? []; // perms[id][action]

            // Validate role name
            if (empty($role_name)) {
                throw new Exception("Role name is required");
            }

            // Editing: the role must exist (a stale or crafted id must not write rights for a role that is gone).
            $old_role = null;
            if (!$is_new) {
                $st = $pdo->prepare("SELECT role_id, role_name, description FROM roles WHERE role_id = ?");
                $st->execute([$role_id]);
                $old_role = $st->fetch(PDO::FETCH_ASSOC);
                if (!$old_role) {
                    throw new Exception("Role not found (ID $role_id).");
                }
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

            $rights_before = $is_new ? [] : rolePermissionSnapshot($pdo, (int)$role_id);

            if (!$is_new) {
                // Update existing role
                $stmt = $pdo->prepare("UPDATE roles SET role_name = ?, description = ?, updated_at = NOW() WHERE role_id = ?");
                $stmt->execute([$role_name, $role_description, $role_id]);

                $message = t('Role updated successfully');
            } else {
                // Create new role
                $stmt = $pdo->prepare("INSERT INTO roles (role_name, description, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$role_name, $role_description]);
                $role_id = $pdo->lastInsertId();
                
                $message = t('Role created successfully');
            }
            
            // Grants on rows hidden because their module is off are kept, so
            // switching the module back on restores them.
            saveRolePermissionGrants($pdo, (int)$role_id, is_array($submitted_permissions) ? $submitted_permissions : [], loadRolePermissionMatrix($pdo));

            // Exactly what changed, so the logs answer "who did what": the rights added and removed, row by row.
            $rights_after = rolePermissionSnapshot($pdo, (int)$role_id);
            $changes = rolePermissionChanges($rights_before, $rights_after, rolePermissionNamesById($pdo));
            $what = [];
            if (!$is_new && $old_role['role_name'] !== $role_name) $what[] = "renamed \"{$old_role['role_name']}\" → \"$role_name\"";
            if (!$is_new && (string)$old_role['description'] !== $role_description) $what[] = 'description changed';
            $what[] = 'rights: ' . rolePermissionChangesText($changes);
            $log_detail = ($is_new ? "Created role \"$role_name\" (ID $role_id)" : "Edited role \"$role_name\" (ID $role_id)")
                        . ' — ' . implode('; ', $what);

            // Both logs inside the transaction: a role change is never saved without its record.
            logAudit($pdo, $__actor, $is_new ? 'create_role' : 'update_role', [
                'entity_type' => 'role',
                'entity_id'   => $role_id,
                'description' => $log_detail,
                'old_values'  => $is_new ? null : [
                    'role_name'   => $old_role['role_name'],
                    'description' => $old_role['description'],
                ],
                'new_values'  => [
                    'role_name'   => $role_name,
                    'description' => $role_description,
                    'changes'     => $changes,
                    'rights_held' => count($rights_after),
                ],
            ]);
            logActivity($pdo, $__actor, $is_new ? 'Create role' : 'Edit role', $log_detail);

            $pdo->commit();
            $success_messages[] = $message;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_messages[] = t('Error saving role:') . ' ' . $e->getMessage();
        }
    }
    
    // Delete Role
    if (isset($_POST['delete_role'])) {
        try {
            $role_id = (int) $_POST['role_id'];

            // Capture the role's details BEFORE deleting, so the activity log can
            // state clearly WHAT was removed (name + id), not just an opaque id.
            $roleStmt = $pdo->prepare("SELECT role_id, role_name, description, is_admin FROM roles WHERE role_id = ?");
            $roleStmt->execute([$role_id]);
            $role_row  = $roleStmt->fetch(PDO::FETCH_ASSOC);
            if (!$role_row) {
                throw new Exception("Role not found (ID $role_id).");
            }
            $role_name = $role_row['role_name'];
            if (rolePermissionIsProtectedRole($role_row)) {
                throw new Exception("The \"$role_name\" role cannot be deleted.");
            }

            // The rights it held, by name (for a clear log).
            $held = rolePermissionChanges(rolePermissionSnapshot($pdo, $role_id), [], rolePermissionNamesById($pdo));
            $perm_count = count($held);

            // Check if role is in use
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ?");
            $stmt->execute([$role_id]);
            $user_count = $stmt->fetchColumn();

            if ($user_count > 0) {
                throw new Exception("Cannot delete role. There are $user_count users assigned to this role.");
            }

            // Clear, human-readable record of WHAT was deleted. WHO + WHEN are
            // captured automatically by logActivity/logAudit (user id + timestamp).
            $log_detail = "Deleted role \"$role_name\" (ID $role_id)"
                        . ($perm_count > 0 ? " and its $perm_count right(s): " . implode('; ', array_map(
                              fn($c) => $c['page'] . ': ' . implode(', ', $c['removed']), array_slice($held, 0, 25)))
                              . ($perm_count > 25 ? '; … and ' . ($perm_count - 25) . ' more' : '') : " (it held no rights)");

            // Rights, role and its record together — never half done.
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$role_id]);
            $pdo->prepare("DELETE FROM roles WHERE role_id = ?")->execute([$role_id]);
            logAudit($pdo, $__actor, 'delete_role', [
                'entity_type' => 'role',
                'entity_id'   => $role_id,
                'description' => $log_detail,
                'old_values'  => [
                    'role_id'          => $role_id,
                    'role_name'        => $role_name,
                    'description'      => $role_row['description'],
                    'permission_count' => $perm_count,
                    'rights'           => array_map(fn($c) => ['page' => $c['page'], 'verbs' => $c['removed']], $held),
                ],
            ]);
            logActivity($pdo, $__actor, 'Deleted role', $log_detail);
            $pdo->commit();

            $success_messages[] = sprintf(t('Role "%s" deleted successfully'), htmlspecialchars($role_name));
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_messages[] = t('Error deleting role:') . ' ' . $e->getMessage();
        }
    }

    // Update User Role
    if (isset($_POST['update_user_role'])) {
        try {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $role_id = (int)($_POST['role_id'] ?? 0);

            // Both must exist: a crafted id must never leave a user on a role that is not there.
            $st = $pdo->prepare("SELECT u.user_id, u.username, TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) AS full_name,
                                        u.role_id AS old_role_id, r.role_name AS old_role_name
                                   FROM users u LEFT JOIN roles r ON r.role_id = u.role_id
                                  WHERE u.user_id = ?");
            $st->execute([$user_id]);
            $target = $st->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                throw new Exception("User not found (ID $user_id).");
            }
            $st = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = ?");
            $st->execute([$role_id]);
            $new_role_name = $st->fetchColumn();
            if ($new_role_name === false) {
                throw new Exception("Role not found (ID $role_id).");
            }

            $who = ($target['full_name'] !== '' ? $target['full_name'] : $target['username']) . " ({$target['username']}, ID $user_id)";
            $from = $target['old_role_name'] ?? 'no role';

            if ((int)$target['old_role_id'] === $role_id) {
                $success_messages[] = t('User role updated successfully');
            } else {
                $log_detail = "Changed the role of $who from \"$from\" to \"$new_role_name\"";
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE users SET role_id = ? WHERE user_id = ?")->execute([$role_id, $user_id]);
                logAudit($pdo, $__actor, 'update_user_role', [
                    'entity_type' => 'user',
                    'entity_id'   => $user_id,
                    'description' => $log_detail,
                    'old_values'  => ['role_id' => $target['old_role_id'] !== null ? (int)$target['old_role_id'] : null, 'role_name' => $target['old_role_name']],
                    'new_values'  => ['role_id' => $role_id, 'role_name' => $new_role_name],
                ]);
                logActivity($pdo, $__actor, 'Edit user role', $log_detail);
                $pdo->commit();
                $success_messages[] = t('User role updated successfully');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_messages[] = t('Error updating user role:') . ' ' . $e->getMessage();
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
// Ready-made restaurant roles: there by default for a Smart POS restaurant, left out everywhere else.
require_once __DIR__ . '/../../../core/restaurant_ready_roles.php';
restaurantReadyRolesSync($pdo);
$__hideR = restaurantReadyRolesHideSql($pdo, 'r');
$roles_stmt = $pdo->query("
    SELECT r.*, COUNT(u.user_id) as user_count
    FROM roles r
    LEFT JOIN users u ON r.role_id = u.role_id
    WHERE 1=1 $__hideR
    GROUP BY r.role_id
    ORDER BY r.role_name
");
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Only rows for modules this company has active (core/role_permission_ui.php).
// Shop mode (retail / mixed / restaurant) decides whether restaurant rows show and how shared rows are worded.
$shop_mode = rolePermissionShopMode($pdo);
$permissions = loadRolePermissionMatrix($pdo)['visible'];

$permissions_by_module = [];
foreach ($permissions as $permission) {
    $permission['has_workflow'] = rolePermissionHasWorkflow((string)$permission['page_key']);
    $permissions_by_module[rolePermissionTabName((string)$permission['page_key'], $permission['module_name'])][] = $permission;
}
ksort($permissions_by_module, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($permissions_by_module as &$_tab_rows) {
    usort($_tab_rows, static fn($a, $b) => strnatcasecmp((string)$a['page_name'], (string)$b['page_name']));
}
unset($_tab_rows);

// Load all users with their roles
$users_stmt = $pdo->query("
    SELECT u.user_id, u.username, u.first_name, u.last_name, u.email, u.is_active AS status,
           r.role_name, r.role_id, d.department_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN departments d ON d.department_id = u.department_id
    ORDER BY u.first_name, u.last_name
");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get permission statistics
$stats_stmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM roles r WHERE 1=1 $__hideR) as total_roles,
        (SELECT COUNT(*) FROM permissions) as total_permissions,
        (SELECT COUNT(*) FROM users) as total_users,
        (SELECT COUNT(DISTINCT module_name) FROM permissions) as total_modules
");
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// The two counters above are raw catalogue totals; report what this company can
// actually assign instead, so the number on screen matches the checkboxes below
// it once entitlement filtering has removed a module.
$stats['total_permissions'] = count($permissions);
$stats['total_modules']     = count($permissions_by_module);

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

<?php require_once ROOT_DIR . '/includes/settings_admin_nav.php'; settingsAdminShellOpen('user_roles'); ?>
<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-person-badge"></i> <?= t('User Roles & Permissions') ?></h2>
            <p class="text-muted"><?= t('Manage user roles, permissions, and access control across the system') ?></p>
        </div>
    </div>

    <!-- Messages -->
    <?php
    // $success_messages/$error_messages are set during POST handling, before
    // header.php resolves the user's language (the POST branch above redirects
    // and exit()s well before header.php ever runs on that request) —
    // translated here, at display time, on the follow-up GET, once the
    // correct language is loaded. t() no-ops gracefully on a message with
    // dynamic data interpolated into it (e.g. a role name or a raw exception).
    ?>
    <?php if (!empty($success_messages)): ?>
        <script>
            Swal.fire({
                icon: 'success',
                title: <?= json_encode(t('Success!')) ?>,
                text: <?= json_encode(implode("\n", array_map('t', $success_messages))) ?>,
                timer: 3000
            });
        </script>
    <?php endif; ?>

    <?php if (!empty($error_messages)): ?>
        <script>
            Swal.fire({
                icon: 'error',
                title: <?= json_encode(t('Error!')) ?>,
                text: <?= json_encode(implode("\n", array_map('t', $error_messages))) ?>
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
                            <p class="small mb-0 opacity-75 text-uppercase"><?= t('Total Roles') ?></p>
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
                            <p class="small mb-0 opacity-75 text-uppercase"><?= t('Total Permissions') ?></p>
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
                            <p class="small mb-0 opacity-75 text-uppercase"><?= t('System Users') ?></p>
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
                            <p class="small mb-0 opacity-75 text-uppercase"><?= t('System Modules') ?></p>
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
                        <i class="bi bi-person-badge"></i> <?= t('Roles Management') ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="users-tab" data-bs-toggle="tab"
                            data-bs-target="#users" type="button" role="tab">
                        <i class="bi bi-people"></i> <?= t('User Assignments') ?>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="audit-tab" data-bs-toggle="tab"
                            data-bs-target="#audit" type="button" role="tab">
                        <i class="bi bi-clock-history"></i> <?= t('Access Audit') ?>
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
                                    <h5 class="mb-0"><?= t('System Roles') ?></h5>
                                    <button class="btn btn-primary btn-sm" id="addRoleBtn">
                                        <i class="bi bi-plus-circle"></i> <?= t('Add Role') ?>
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
                                                        <?= $role['user_count'] ?> <?= t('user(s)') ?>
                                                    </small>
                                                </div>
                                                <div class="btn-group">
                                                    <button class="btn btn-sm btn-outline-primary edit-role" 
                                                            data-role-id="<?= $role['role_id'] ?>">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <?php if ($role['user_count'] == 0 && !rolePermissionIsProtectedRole($role)): ?>
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
                                    <h5 class="mb-0" id="roleFormTitle"><?= t('Add New Role') ?></h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" id="roleForm">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" id="role_id" name="role_id">
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="role_name" class="form-label"><?= t('Role Name') ?> *</label>
                                                    <input type="text" class="form-control" id="role_name" name="role_name" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label for="role_description" class="form-label"><?= t('Description') ?></label>
                                                    <input type="text" class="form-control" id="role_description" name="role_description">
                                                </div>
                                            </div>
                                        </div>

                                        <h6 class="mt-4 mb-3 d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-shield-lock me-2"></i><?= t('Configure Permissions') ?></span>
                                            <div class="input-group input-group-sm w-50">
                                                <span class="input-group-text"><i class="bi bi-search"></i></span>
                                                <input type="text" class="form-control" id="permSearch" placeholder="<?= t('Search permissions...') ?>">
                                            </div>
                                        </h6>

                                        <div class="small text-muted mb-2 d-flex flex-wrap gap-3">
                                            <span><strong><?= t('View') ?></strong> — <?= t('open the page and see its records') ?></span>
                                            <span><strong><?= t('Create') ?></strong> — <?= t('add new records') ?></span>
                                            <span><strong><?= t('Edit') ?></strong> — <?= t('change existing records') ?></span>
                                            <span><strong><?= t('Delete') ?></strong> — <?= t('remove records') ?></span>
                                            <span><strong style="color:#0d6efd;"><?= t('Review') ?></strong> / <strong style="color:#198754;"><?= t('Approve') ?></strong> — <?= t('move a document through its approval steps; shown only where an approval workflow exists') ?></span>
                                        </div>

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
                                                            <?= htmlspecialchars($module_name === 'Point of Sale' ? wLabel('Point of Sale', 'Selling') : ($module_name === 'Restaurant' ? t('Restaurant') : $module_name)) ?>
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
                                                    $tabHasWorkflow = in_array(true, array_column($module_permissions, 'has_workflow'), true);
                                                ?>
                                                    <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="<?= $tabId ?>" role="tabpanel">
                                                        <div class="table-responsive">
                                                            <table class="table table-hover table-sm align-middle mb-0">
                                                                <thead class="bg-light-subtle sticky-top">
                                                                    <tr>
                                                                        <th class="ps-3" style="width: 22%;"><?= t('Feature / Page') ?></th>
                                                                        <th class="text-center" style="width: 12%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small text-muted mb-1"><?= t('VIEW') ?></span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="view">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 12%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small text-muted mb-1"><?= t('CREATE') ?></span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="create">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 12%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small text-muted mb-1"><?= t('EDIT') ?></span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="edit">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 12%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small text-muted mb-1"><?= t('DELETE') ?></span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="delete">
                                                                            </div>
                                                                        </th>
                                                                        <?php if ($tabHasWorkflow): ?>
                                                                        <th class="text-center" style="width: 13%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small mb-1" style="color:#0d6efd;font-weight:700;"><?= t('REVIEW') ?></span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="review">
                                                                            </div>
                                                                        </th>
                                                                        <th class="text-center" style="width: 13%;">
                                                                            <div class="d-flex flex-column align-items-center">
                                                                                <span class="small mb-1" style="color:#198754;font-weight:700;"><?= t('APPROVE') ?></span>
                                                                                <input type="checkbox" class="form-check-input select-all-col" data-module="<?= $tabId ?>" data-type="approve">
                                                                            </div>
                                                                        </th>
                                                                        <?php endif; ?>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($module_permissions as $permission): ?>
                                                                        <tr class="perm-row">
                                                                            <td class="ps-3">
                                                                                <?php $_shop = rolePermissionShopText((string)$permission['page_key'], $shop_mode); ?>
                                                                                <div class="fw-bold small perm-name"><?= htmlspecialchars(!empty($_shop['name']) ? t($_shop['name']) : ($permission['page_name'] ?? '')) ?></div>
                                                                                <div class="text-muted" style="font-size: 0.7rem;"><?= htmlspecialchars(($_shop && $_shop['replace']) ? t($_shop['text']) : ($permission['description'] ?? '')) ?></div>
                                                                                <?php if ($_shop && !$_shop['replace']): ?>
                                                                                <div class="text-success" style="font-size: 0.7rem;"><i class="bi bi-cup-hot me-1"></i><?= htmlspecialchars(t('In a restaurant shop:') . ' ' . t($_shop['text'])) ?></div>
                                                                                <?php endif; ?>
                                                                                <?php if ($_note = rolePermissionNote((string)$permission['page_key'])): ?>
                                                                                <div class="text-primary" style="font-size: 0.7rem;"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars(t($_note)) ?></div>
                                                                                <?php endif; ?>
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
                                                                            <?php if ($tabHasWorkflow && $permission['has_workflow']): ?>
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
                                                                            <?php elseif ($tabHasWorkflow): ?>
                                                                            <td class="text-center text-muted" title="<?= htmlspecialchars(t('No approval workflow on this page')) ?>">—</td>
                                                                            <td class="text-center text-muted" title="<?= htmlspecialchars(t('No approval workflow on this page')) ?>">—</td>
                                                                            <?php endif; ?>
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
                                                <i class="bi bi-x-circle me-1"></i> <?= t('Cancel') ?>
                                            </button>
                                            <button type="submit" name="save_role" class="btn btn-primary px-4 shadow-sm">
                                                <i class="bi bi-check-circle me-1"></i> <?= t('Save Access Level') ?>
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
                                    <h5 class="mb-0"><?= t('User Role Assignments') ?></h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="usersTable">
                                            <thead>
                                                <tr>
                                                    <th><?= t('User') ?></th>
                                                    <th><?= t('Username') ?></th>
                                                    <th><?= t('Department') ?></th>
                                                    <th><?= t('Current Role') ?></th>
                                                    <th><?= t('Status') ?></th>
                                                    <th><?= t('Actions') ?></th>
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
                                                        <td><?= htmlspecialchars($user['department_name'] ?? t('N/A')) ?></td>
                                                        <td>
                                                            <span class="badge bg-<?= getRoleBadgeColor($user['role_name']) ?>">
                                                                <?= $user['role_name'] ? htmlspecialchars($user['role_name']) : t('Unassigned') ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?= $user['status'] == 'active' ? 'success' : 'secondary' ?>">
                                                                <?= $user['status'] == 'active' ? t('Active') : t('Inactive') ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <button class="btn btn-sm btn-outline-primary assign-role"
                                                                    data-user-id="<?= $user['user_id'] ?>"
                                                                    data-user-name="<?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>"
                                                                    data-current-role="<?= $user['role_id'] ?>">
                                                                <i class="bi bi-person-gear"></i> <?= t('Assign Role') ?>
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
                                    <h5 class="mb-0"><?= t('Access Log') ?></h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover" id="accessLogTable">
                                            <thead>
                                                <tr>
                                                    <th><?= t('User') ?></th>
                                                    <th><?= t('Action') ?></th>
                                                    <th><?= t('Resource') ?></th>
                                                    <th><?= t('Timestamp') ?></th>
                                                    <th><?= t('IP Address') ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">
                                                        <?= t('Loading access log...') ?>
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
                                    <h5 class="mb-0"><?= t('Access Statistics') ?></h5>
                                </div>
                                <div class="card-body">
                                    <div id="accessStats">
                                        <div class="text-center text-muted">
                                            <i class="bi bi-hourglass-split"></i><br>
                                            <?= t('Loading statistics...') ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="card mt-3">
                                <div class="card-header">
                                    <h5 class="mb-0"><?= t('Quick Actions') ?></h5>
                                </div>
                                <div class="card-body">
                                    <?php // Full history (every module, date filter, export) lives on Audit Logs. History is never cleared from here. ?>
                                    <a class="btn btn-outline-primary btn-sm w-100 mb-2" href="<?= getUrl('audit_logs') ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-file-earmark-text"></i> <?= t('Open full Audit Logs') ?>
                                    </a>
                                    <button class="btn btn-outline-info btn-sm w-100" id="refreshAudit">
                                        <i class="bi bi-arrow-clockwise"></i> <?= t('Refresh Data') ?>
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
                <h5 class="modal-title"><?= t('Assign Role to User') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" id="assign_user_id" name="user_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?= t('User') ?></label>
                        <input type="text" class="form-control" id="assign_user_name" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="assign_role_id" class="form-label"><?= t('Select Role') ?> *</label>
                        <select class="form-control" id="assign_role_id" name="role_id" required>
                            <option value=""><?= t('Select a role...') ?></option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" name="update_user_role" class="btn btn-primary"><?= t('Assign Role') ?></button>
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
                <h5 class="modal-title"><?= t('Confirm Delete') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><?= t('Are you sure you want to delete the role') ?> "<strong id="deleteRoleName"></strong>"?</p>
                <p class="text-danger"><?= t('This action cannot be undone.') ?></p>
            </div>
            <div class="modal-footer">
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" id="delete_role_id" name="role_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                    <button type="submit" name="delete_role" class="btn btn-danger"><?= t('Delete Role') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999">
    <!-- Toast notifications will be inserted here -->
</div>

<?php settingsAdminShellClose(); ?>
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
const UR_STRINGS = {
    createNewSystemRole: <?= json_encode(t('Create New System Role')) ?>,
    modifyingRole: <?= json_encode(t('Modifying Role:')) ?>,
    errorLoadingRoleDetails: <?= json_encode(t('Error loading role details')) ?>,
    exportingMatrix: <?= json_encode(t('Exporting permissions matrix...')) ?>,
    noAccessLogsFound: <?= json_encode(t('No access logs found')) ?>,
    totalLogs: <?= json_encode(t('Total Logs:')) ?>,
    todaysActivities: <?= json_encode(t("Today's Activities:")) ?>,
    mostActiveUser: <?= json_encode(t('Most Active User:')) ?>,
    lastUpdated: <?= json_encode(t('Last Updated:')) ?>,
    errorLoadingAccessLog: <?= json_encode(t('Error loading access log')) ?>,
    auditDataRefreshed: <?= json_encode(t('Audit data refreshed')) ?>,
    success: <?= json_encode(t('Success!')) ?>,
    error: <?= json_encode(t('Error!')) ?>,
    information: <?= json_encode(t('Information')) ?>,
};

// Escape anything typed by people (role names, descriptions) before it goes into HTML.
function urEsc(v) {
    return String(v === null || v === undefined ? '' : v)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

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
        $('#roleFormTitle').html('<i class="bi bi-plus-circle me-2"></i>' + UR_STRINGS.createNewSystemRole);
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
                    $('#roleFormTitle').html('<i class="bi bi-pencil-square me-2"></i>' + UR_STRINGS.modifyingRole + ' <span class="text-primary">' + urEsc(response.role.role_name) + '</span>');
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
                    showToast('error', response.message || UR_STRINGS.errorLoadingRoleDetails);
                }
            },
            error: function() {
                showToast('error', UR_STRINGS.errorLoadingRoleDetails);
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
        $('#roleFormTitle').html('<i class="bi bi-plus-circle me-2"></i>' + UR_STRINGS.createNewSystemRole);
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
        showToast('info', UR_STRINGS.exportingMatrix);
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
                        // Role names and descriptions are typed by people: always escaped.
                        response.logs.forEach(log => {
                            html += `
                                <tr>
                                    <td>${urEsc(log.user_name)}</td>
                                    <td>${urEsc(log.action)}</td>
                                    <td style="white-space:normal;">${urEsc(log.resource)}</td>
                                    <td class="text-nowrap">${urEsc(log.timestamp)}</td>
                                    <td>${urEsc(log.ip_address)}</td>
                                </tr>
                            `;
                        });
                    } else {
                        html = `<tr><td colspan="5" class="text-center text-muted">${UR_STRINGS.noAccessLogsFound}</td></tr>`;
                    }
                    $('#accessLogTable tbody').html(html);

                    // Update statistics
                    $('#accessStats').html(`
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>${UR_STRINGS.totalLogs}</span>
                                <strong>${urEsc(response.stats.total_logs)}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>${UR_STRINGS.todaysActivities}</span>
                                <strong>${urEsc(response.stats.today_activities)}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>${UR_STRINGS.mostActiveUser}</span>
                                <strong>${urEsc(response.stats.most_active_user)}</strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>${UR_STRINGS.lastUpdated}</span>
                                <strong>${urEsc(response.stats.last_updated)}</strong>
                            </div>
                        </div>
                    `);
                }
            },
            error: function() {
                $('#accessLogTable tbody').html(`<tr><td colspan="5" class="text-center text-danger">${UR_STRINGS.errorLoadingAccessLog}</td></tr>`);
            }
        });
    }

    // Refresh Audit Data
    $('#refreshAudit').click(function() {
        loadAccessLog();
        showToast('info', UR_STRINGS.auditDataRefreshed);
    });

    // Toast notification function
    function showToast(type, message) {
        Swal.fire({
            icon: type,
            title: type === 'success' ? UR_STRINGS.success : (type === 'error' ? UR_STRINGS.error : UR_STRINGS.information),
            text: message,
            timer: 3000
        });
    }

    // Load initial access log
    loadAccessLog();
});
</script>
