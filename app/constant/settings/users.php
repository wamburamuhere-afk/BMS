<?php
ob_start();
require_once __DIR__ . '/../../../roots.php';

// Phase 2 of security_implementation_plan.md — explicit gate. The previous
// comment claimed header.php auto-enforces but the routing-layer fallback
// did not cover this filename. Now any user without the 'users' permission
// is redirected to /unauthorized before any output.
autoEnforcePermission('users');

require_once HEADER_FILE;

// User management (account creation + role assignment) is strictly
// admin-only by design — it is NOT delegable via Roles & Permissions no
// matter what is granted (the 'users' permission row is hidden from that
// UI entirely; see migrations/2026_07_29_lock_sensitive_settings.php).
// Granting this to a non-admin would let them create an account and hand
// it any role, including admin — a direct privilege-escalation path.
if (!isAdmin()) {
    header('Location: ' . getUrl('unauthorized'));
    exit;
}




// Fetch available roles from database
$stmt = $pdo->query("SELECT * FROM roles ORDER BY role_name");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log activity for viewing user list
if (isset($_SESSION['user_id'])) {
    logAudit($pdo, $_SESSION['user_id'], 'View Users List', [
        'activity_type' => 'View',
        'description' => 'User viewed the user management list'
    ]);
}
?>

<div class="container-fluid mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-people-fill"></i> <?= t('User Management') ?></h2>
            <p class="text-muted"><?= t('Manage system users and their permissions') ?></p>
        </div>
    </div>

    <div class="card">
        <?php if (isset($_SESSION['success_message'])): ?>
            <script>
                $(document).ready(function() {
                    Swal.fire({
                        icon: 'success',
                        title: <?= json_encode(t('Success!')) ?>,
                        text: <?= json_encode(t($_SESSION['success_message'])) ?>,
                        timer: 3000
                    });
                });
            </script>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        <div class="card-header d-flex justify-content-between align-items-center bg-white py-3">
            <h5 class="mb-0 fw-bold border-start border-primary border-4 ps-3"><?= t('All Users') ?></h5>
            <a href="<?= getUrl('add_user') ?>" class="btn btn-primary btn-sm shadow-sm rounded-pill px-3">
                <i class="bi bi-person-plus"></i> <?= t('Add New User') ?>
            </a>
        </div>

        <div class="card-body">
            <div class="table-responsive">
                <table id="usersTable" class="table table-striped table-hover" style="width:100%">
                    <thead>
                        <tr>
                            <th><?= t('S/NO') ?></th>
                            <th>ID</th>
                            <th><?= t('Username') ?></th>
                            <th><?= t('Full Name') ?></th>
                            <th><?= t('Email') ?></th>
                            <th><?= t('Role') ?></th>
                            <th><?= t('Status') ?></th>
                            <th><?= t('Last Login') ?></th>
                            <th><?= t('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Role Assignment Modal -->
<div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roleModalLabel"><?= t('Assign Role') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" id="assignUserId" name="user_id">
                    <div class="mb-3">
                        <label for="userRole" class="form-label"><?= t('Select Role') ?></label>
                        <select class="form-select" id="userRole" name="role_id" required>
                            <option value=""><?= t('Select a role') ?></option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('Cancel') ?></button>
                <button type="button" class="btn btn-primary" id="saveRole"><?= t('Save Changes') ?></button>
            </div>
        </div>
    </div>
</div>

<?php include(FOOTER_FILE); ?>



<style>
/* Compact dropdown styles */
.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 150px;
}

.action-dropdown .dropdown-item {
    padding: 0.25rem 1rem;
}

.action-dropdown .dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.action-column {
    width: 80px;
    min-width: 80px;
    max-width: 80px;
}

/* Reduce table padding for more compact rows */
.table td, .table th {
    padding: 0.5rem;
}

/* Ensure action buttons stay on one line */
.action-buttons {
    white-space: nowrap;
}
</style>

<script>
const USERS_STRINGS = {
    errorLoadingUsers: <?= json_encode(t('Error loading user data')) ?>,
    noRole: <?= json_encode(t('No role')) ?>,
    active: <?= json_encode(t('Active')) ?>,
    inactive: <?= json_encode(t('Inactive')) ?>,
    neverLoggedIn: <?= json_encode(t('Never logged in')) ?>,
    editUser: <?= json_encode(t('Edit User')) ?>,
    assignRole: <?= json_encode(t('Assign Role')) ?>,
    deactivate: <?= json_encode(t('Deactivate')) ?>,
    activate: <?= json_encode(t('Activate')) ?>,
    delete: <?= json_encode(t('Delete')) ?>,
    emptyTable: <?= json_encode(t('No users found')) ?>,
    zeroRecords: <?= json_encode(t('No matching users found')) ?>,
    info: <?= json_encode(t('Showing _START_ to _END_ of _TOTAL_ users')) ?>,
    infoEmpty: <?= json_encode(t('No users available')) ?>,
    infoFiltered: <?= json_encode(t('(filtered from _MAX_ total users)')) ?>,
    searchPlaceholder: <?= json_encode(t('Search users...')) ?>,
    lengthMenu: <?= json_encode(t('Show _MENU_ users per page')) ?>,
    saving: <?= json_encode(t('Saving...')) ?>,
    saveChanges: <?= json_encode(t('Save Changes')) ?>,
    roleAssignedSuccess: <?= json_encode(t('Role assigned successfully')) ?>,
    errorAssigningRole: <?= json_encode(t('Error assigning role')) ?>,
    errorCommunicating: <?= json_encode(t('Error communicating with server:')) ?>,
    areYouSure: <?= json_encode(t('Are you sure?')) ?>,
    confirmAction: <?= json_encode(t('Are you sure you want to %s this user?')) ?>,
    yesActionIt: <?= json_encode(t('Yes, %s it!')) ?>,
    success: <?= json_encode(t('Success!')) ?>,
    error: <?= json_encode(t('Error!')) ?>,
    errorUpdatingUser: <?= json_encode(t('Error updating user')) ?>,
    confirmDeleteUser: <?= json_encode(t('Are you sure you want to permanently delete this user? This action cannot be undone!')) ?>,
    yesDeleteIt: <?= json_encode(t('Yes, delete it!')) ?>,
    deleted: <?= json_encode(t('Deleted!')) ?>,
    errorDeletingUser: <?= json_encode(t('Error deleting user')) ?>,
    information: <?= json_encode(t('Information')) ?>,
};

$(document).ready(function() {
    // Initialize DataTable with server-side processing
    var table = $('#usersTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: APP_URL + '/ajax/get_users.php',
            type: 'GET',
            dataSrc: function(json) {
                console.log("Server response:", json); // Debugging
                if(json && json.data) {
                    return json.data;
                } else {
                    console.error("Unexpected data format:", json);
                    return [];
                }
            },
            error: function(xhr, error, thrown) {
                console.error("AJAX error:", xhr, error, thrown);
                showAlert(USERS_STRINGS.errorLoadingUsers, 'danger');
            }
        },
        columns: [
            { 
                data: null,
                render: function (data, type, row, meta) {
                    return meta.row + meta.settings._iDisplayStart + 1;
                },
                orderable: false,
                searchable: false
            },
            { data: 'user_id' },
            { data: 'username' },
            { data: 'full_name' },
            { data: 'email' },
            { 
                data: 'role_name',
                render: function(data, type, row) {
                    if(!data) return '<span class="badge bg-secondary">' + USERS_STRINGS.noRole + '</span>';

                    var roleClass = '';
                    switch(data.toLowerCase()) {
                        case 'admin': roleClass = 'danger'; break;
                        case 'manager': roleClass = 'info'; break;
                        case 'loan officer': roleClass = 'warning'; break;
                        default: roleClass = 'primary';
                    }
                    return '<span class="badge bg-' + roleClass + '">' + data + '</span>';
                }
            },
            { 
                data: 'is_active',
                render: function(data) {
                    return data == 1
                        ? '<span class="badge bg-success">' + USERS_STRINGS.active + '</span>'
                        : '<span class="badge bg-secondary">' + USERS_STRINGS.inactive + '</span>';
                }
            },
            {
                data: 'last_login',
                render: function(data) {
                    if(!data) return USERS_STRINGS.neverLoggedIn;
                    try {
                        return new Date(data).toLocaleString();
                    } catch(e) {
                        return data; // Return raw value if date parsing fails
                    }
                }
            },
            {
                data: null,
                className: 'action-column',
                render: function(data, type, row) {
                    var currentUserId = <?= $_SESSION['user_id'] ?? 0 ?>;
                    var isCurrentUser = (row.user_id == currentUserId);
                    
                    var dropdownHtml = '<div class="dropdown action-dropdown">' +
                        '<button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">' +
                        '<i class="bi bi-gear"></i>' +
                        '</button>' +
                        '<ul class="dropdown-menu">' +
                        '<li><button class="dropdown-item" type="button" onclick="window.location.href=\'' + APP_URL + '/edit_user?id=' + row.user_id + '\'"><i class="bi bi-pencil"></i> ' + USERS_STRINGS.editUser + '</button></li>' +
                        '<li><a class="dropdown-item assign-role" href="#" data-id="' + row.user_id + '" data-role="' + (row.role_id || '') + '"><i class="bi bi-person-gear"></i> ' + USERS_STRINGS.assignRole + '</a></li>';

                    if (!isCurrentUser) {
                        if (row.is_active == 1) {
                            dropdownHtml += '<li><a class="dropdown-item toggle-user" href="#" data-id="' + row.user_id + '" data-action="deactivate"><i class="bi bi-person-x"></i> ' + USERS_STRINGS.deactivate + '</a></li>';
                        } else {
                            dropdownHtml += '<li><a class="dropdown-item toggle-user" href="#" data-id="' + row.user_id + '" data-action="activate"><i class="bi bi-person-check"></i> ' + USERS_STRINGS.activate + '</a></li>';
                        }

                        dropdownHtml += '<li><hr class="dropdown-divider"></li>' +
                            '<li><a class="dropdown-item delete-user text-danger" href="#" data-id="' + row.user_id + '"><i class="bi bi-trash"></i> ' + USERS_STRINGS.delete + '</a></li>';
                    }
                    
                    dropdownHtml += '</ul></div>';
                    
                    return dropdownHtml;
                },
                orderable: false
            }
        ],
        language: {
            emptyTable: USERS_STRINGS.emptyTable,
            zeroRecords: USERS_STRINGS.zeroRecords,
            info: USERS_STRINGS.info,
            infoEmpty: USERS_STRINGS.infoEmpty,
            infoFiltered: USERS_STRINGS.infoFiltered,
            search: "_INPUT_",
            searchPlaceholder: USERS_STRINGS.searchPlaceholder,
            lengthMenu: USERS_STRINGS.lengthMenu
        },
        columnDefs: [
            { width: "5%", targets: 0 }, // S/NO column
            { width: "5%", targets: 1 }, // ID column
            { width: "10%", targets: 2 }, // Username column
            { width: "15%", targets: 3 }, // Full Name column
            { width: "20%", targets: 4 }, // Email column
            { width: "10%", targets: 5 }, // Role column
            { width: "8%", targets: 6 },  // Status column
            { width: "15%", targets: 7 }, // Last Login column
            { width: "5%", targets: 8 }   // Actions column
        ]
    });

    // Role Assignment Modal
    var roleModal = new bootstrap.Modal(document.getElementById('roleModal'));
    
    $(document).on('click', '.assign-role', function(e) {
        e.preventDefault();
        var userId = $(this).data('id');
        var currentRole = $(this).data('role');
        
        $('#assignUserId').val(userId);
        $('#userRole').val(currentRole);
        roleModal.show();
    });

    
    $('#saveRole').click(function() {
        var formData = $('#roleForm').serialize();
        
        $.ajax({
            url: APP_URL + '/ajax/assign_role.php',
            method: 'POST',
            data: formData,
            dataType: 'json',
            beforeSend: function() {
                $('#saveRole').prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status"></span> ' + USERS_STRINGS.saving);
            },
            complete: function() {
                $('#saveRole').prop('disabled', false).text(USERS_STRINGS.saveChanges);
            },
            success: function(response) {
                if (response.success) {
                    showAlert(USERS_STRINGS.roleAssignedSuccess, 'success');
                    // Reload the page to clearly show "return" to the list state
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                    roleModal.hide();
                } else {
                    showAlert(response.message || USERS_STRINGS.errorAssigningRole, 'danger');
                }
            },
            error: function(xhr) {
                showAlert(USERS_STRINGS.errorCommunicating + ' ' + xhr.statusText, 'danger');
            }
        });
    });

    // User status toggle
    $(document).on('click', '.toggle-user', function(e) {
        e.preventDefault();
        var userId = $(this).data('id');
        var action = $(this).data('action');
        var btn = $(this);
        
        Swal.fire({
            title: USERS_STRINGS.areYouSure,
            text: USERS_STRINGS.confirmAction.replace('%s', action),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: USERS_STRINGS.yesActionIt.replace('%s', action)
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: APP_URL + '/ajax/toggle_user.php',
                    method: 'POST',
                    data: {
                        user_id: userId,
                        action: action
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire(USERS_STRINGS.success, response.message, 'success');
                            table.ajax.reload(null, false); // Reload without resetting paging
                        } else {
                            Swal.fire(USERS_STRINGS.error, response.message || USERS_STRINGS.errorUpdatingUser, 'error');
                        }
                    },
                    error: function(xhr) {
                        Swal.fire(USERS_STRINGS.error, USERS_STRINGS.errorCommunicating + ' ' + xhr.statusText, 'error');
                    }
                });
            }
        });
    });

    // Delete user
    $(document).on('click', '.delete-user', function(e) {
        e.preventDefault();
        var userId = $(this).data('id');
        var row = $(this).closest('tr');

        Swal.fire({
            title: USERS_STRINGS.areYouSure,
            text: USERS_STRINGS.confirmDeleteUser,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#6c757d',
            confirmButtonText: USERS_STRINGS.yesDeleteIt
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: APP_URL + '/ajax/delete_user.php',
                    method: 'POST',
                    data: { user_id: userId },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire(USERS_STRINGS.deleted, response.message, 'success');
                            table.row(row).remove().draw(false); // Remove without resetting paging
                        } else {
                            Swal.fire(USERS_STRINGS.error, response.message || USERS_STRINGS.errorDeletingUser, 'error');
                        }
                    },
                    error: function(xhr) {
                        Swal.fire(USERS_STRINGS.error, USERS_STRINGS.errorCommunicating + ' ' + xhr.statusText, 'error');
                    }
                });
            }
        });
    });

    // Show alert message
    function showAlert(message, type) {
        Swal.fire({
            icon: type === 'danger' ? 'error' : type,
            title: type === 'success' ? USERS_STRINGS.success : (type === 'danger' ? USERS_STRINGS.error : USERS_STRINGS.information),
            text: message,
            timer: 3000
        });
    }
});
</script>
<?php
// Flush the buffer
ob_end_flush();