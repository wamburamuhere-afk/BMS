<?php
// Start the buffer
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the header
require_once HEADER_FILE;

// Phase 5a — enforce permission on customer groups list
autoEnforcePermission('customer_groups');

// Fetch customer groups
$stmt = $pdo->query("
    SELECT 
        cg.*,
        COUNT(cgc.customer_id) as customer_count,
        u.username as created_by_name
    FROM customer_groups cg
    LEFT JOIN customer_group_customers cgc ON cg.group_id = cgc.group_id
    LEFT JOIN users u ON cg.created_by = u.user_id
    GROUP BY cg.group_id
    ORDER BY cg.created_at DESC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch total customers count for statistics
$total_customers = $pdo->query("SELECT COUNT(*) as total FROM customers")->fetchColumn();

?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-people-fill"></i> Customer Groups</h2>
                    <p class="text-muted mb-0">Manage customer groups and segment your customer base</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createGroupModal">
                        <i class="bi bi-plus-circle"></i> Create New Group
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= count($groups) ?></h4>
                            <p class="mb-0">Total Groups</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-collection" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0"><?= $total_customers ?></h4>
                            <p class="mb-0">Total Customers</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-people" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-white"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">
                                <?= array_sum(array_column($groups, 'customer_count')) ?>
                            </h4>
                            <p class="mb-0">Group Members</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-person-check" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card text-dark"><div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0">
                                <?= $total_customers - array_sum(array_column($groups, 'customer_count')) ?>
                            </h4>
                            <p class="mb-0">Ungrouped</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-person-dash" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Groups Table -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">All Customer Groups</h5>
                <div class="d-flex">
                    <input type="text" id="searchGroups" class="form-control form-control-sm me-2" placeholder="Search groups..." style="width: 200px;">
                    <button class="btn btn-sm btn-light" onclick="exportGroups()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($groups) > 0): ?>
                <div class="table-responsive">
                    <table id="groupsTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Group Name</th>
                                <th>Description</th>
                                <th>Members</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $group): 
                                $member_percentage = $total_customers > 0 ? 
                                    round(($group['customer_count'] / $total_customers) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="group-color me-2" 
                                             style="width: 16px; height: 16px; background-color: <?= safe_output($group['color'] ?? '#007bff') ?>; border-radius: 3px;"></div>
                                        <strong><?= safe_output($group['group_name']) ?></strong>
                                    </div>
                                </td>
                                <td><?= safe_output($group['description']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-primary rounded-pill me-2">
                                            <?= $group['customer_count'] ?>
                                        </span>
                                        <small class="text-muted">(<?= $member_percentage ?>%)</small>
                                    </div>
                                    <?php if ($group['customer_count'] > 0): ?>
                                    <div class="progress mt-1" style="height: 4px;">
                                        <div class="progress-bar" 
                                             style="width: <?= min($member_percentage, 100) ?>%; background-color: <?= safe_output($group['color'] ?? '#007bff') ?>"></div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $group['group_type'] === 'static' ? 'info' : 'warning' ?>">
                                        <?= ucfirst($group['group_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $group['status'] === 'active' ? 'success' : 'secondary' ?>">
                                        <?= ucfirst($group['status']) ?>
                                    </span>
                                </td>
                                <td><?= safe_output($group['created_by_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($group['created_at'])) ?></td>
                                <td>
                                    <div class="dropdown action-dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-gear"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('customers/group_details') ?>?id=<?= $group['group_id'] ?>">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="<?= getUrl('customers/group_members') ?>?id=<?= $group['group_id'] ?>">
                                                    <i class="bi bi-people"></i> Manage Members
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editGroup(<?= $group['group_id'] ?>)">
                                                    <i class="bi bi-pencil"></i> Edit Group
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= $group['group_id'] ?>)">
                                                    <i class="bi bi-trash"></i> Delete Group
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-people" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Customer Groups Found</h4>
                    <p class="text-muted">Get started by creating your first customer group.</p>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#createGroupModal">
                        <i class="bi bi-plus-circle"></i> Create Your First Group
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Create Group Modal -->
<div class="modal fade" id="createGroupModal" tabindex="-1" aria-labelledby="createGroupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="createGroupModalLabel">
                    <i class="bi bi-plus-circle"></i> Create New Customer Group
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="createGroupForm">
                <div class="modal-body">
                    <div id="create-group-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="group_name" class="form-label">Group Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="group_name" name="group_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="group_type" class="form-label">Group Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="group_type" name="group_type" required>
                                <option value="">Select Type</option>
                                <option value="static">Static Group</option>
                                <option value="dynamic">Dynamic Group</option>
                            </select>
                            <div class="form-text">
                                <small>
                                    <strong>Static:</strong> Manual member management<br>
                                    <strong>Dynamic:</strong> Automatic based on rules
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="Describe the purpose of this group..."></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="color" class="form-label">Group Color</label>
                            <div class="input-group">
                                <input type="color" class="form-control form-control-color" id="color" name="color" value="#007bff" title="Choose group color">
                                <span class="input-group-text">#007bff</span>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>

                    <!-- Dynamic Group Rules (shown when type is dynamic) -->
                    <div id="dynamicRules" class="d-none">
                        <div class="card bg-light">
                            <div class="card-header">
                                <h6 class="mb-0">Dynamic Group Rules</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">Rule Condition</label>
                                    <select class="form-select" name="rule_field">
                                        <option value="">Select Field</option>
                                        <option value="entity_type">Entity Type</option>
                                        <option value="level_of_education">Education Level</option>
                                        <option value="marital_status">Marital Status</option>
                                        <option value="occupation_business">Occupation</option>
                                        <option value="created_at">Registration Date</option>
                                    </select>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <select class="form-select" name="rule_operator">
                                            <option value="equals">Equals</option>
                                            <option value="contains">Contains</option>
                                            <option value="starts_with">Starts With</option>
                                            <option value="ends_with">Ends With</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" class="form-control" name="rule_value" placeholder="Value">
                                    </div>
                                </div>
                                <div class="form-text mt-2">
                                    <small>Dynamic groups automatically include customers matching these rules.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Create Group
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Include jQuery, Bootstrap JS, and Bootstrap Icons -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#groupsTable').DataTable({
        language: {
            search: "Search groups:",
            lengthMenu: "Show _MENU_ groups per page",
            info: "Showing _START_ to _END_ of _TOTAL_ groups",
            paginate: {
                first: "First",
                last: "Last",
                next: "Next",
                previous: "Previous"
            }
        },
        responsive: true,
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'copyHtml5',
                text: '<i class="bi bi-clipboard"></i> Copy',
                titleAttr: 'Copy to clipboard'
            },
            {
                extend: 'excelHtml5',
                text: '<i class="bi bi-file-excel"></i> Excel',
                titleAttr: 'Export to Excel',
                title: 'Customer_Groups_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'csvHtml5',
                text: '<i class="bi bi-file-text"></i> CSV',
                titleAttr: 'Export to CSV',
                title: 'Customer_Groups_' + new Date().toISOString().slice(0,10)
            }
        ]
    });

    // Search functionality
    $('#searchGroups').on('keyup', function() {
        $('#groupsTable').DataTable().search($(this).val()).draw();
    });

    // Show/hide dynamic rules based on group type
    $('#group_type').change(function() {
        if ($(this).val() === 'dynamic') {
            $('#dynamicRules').removeClass('d-none');
        } else {
            $('#dynamicRules').addClass('d-none');
        }
    });

    // Color picker update
    $('#color').change(function() {
        $(this).next('.input-group-text').text($(this).val());
    });

    // Create group form submission
    $('#createGroupForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = $(this).serialize();
        const submitBtn = $(this).find('[type="submit"]');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating...');

        $.ajax({
            url: 'api/create_customer_group.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#create-group-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#create-group-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Create Group');
                }
            },
            error: function() {
                $('#create-group-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Create Group');
            }
        });
    });

    // Reset form when modal is closed
    $('#createGroupModal').on('hidden.bs.modal', function() {
        $('#createGroupForm')[0].reset();
        $('#create-group-message').html('');
        $('#dynamicRules').addClass('d-none');
        $('#createGroupForm [type="submit"]').prop('disabled', false).html('<i class="bi bi-check-circle"></i> Create Group');
    });
});

function editGroup(groupId) {
    // Load group data and open edit modal
    $.ajax({
        url: 'api/get_customer_group.php',
        type: 'GET',
        data: { id: groupId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Populate edit form and show modal
                $('#group_name').val(response.data.group_name);
                $('#description').val(response.data.description);
                $('#group_type').val(response.data.group_type);
                $('#color').val(response.data.color);
                $('#status').val(response.data.status);
                
                // Update color display
                $('#color').next('.input-group-text').text(response.data.color);
                
                // Show/hide dynamic rules
                if (response.data.group_type === 'dynamic') {
                    $('#dynamicRules').removeClass('d-none');
                } else {
                    $('#dynamicRules').addClass('d-none');
                }
                
                // Change modal to edit mode
                $('#createGroupModalLabel').html('<i class="bi bi-pencil"></i> Edit Customer Group');
                $('#createGroupForm').attr('id', 'editGroupForm');
                $('#editGroupForm').append('<input type="hidden" name="group_id" value="' + groupId + '">');
                $('#editGroupForm [type="submit"]').html('<i class="bi bi-check-circle"></i> Update Group');
                
                // Update form submission for edit
                $('#editGroupForm').off('submit').on('submit', function(e) {
                    e.preventDefault();
                    updateGroup(groupId, $(this).serialize());
                });
                
                $('#createGroupModal').modal('show');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Load Error',
                    text: 'Error loading group data: ' + response.message
                });
            }
        },
        error: function() {
            Swal.fire({
                icon: 'error',
                title: 'Server Error',
                text: 'Error loading group data. Please try again.'
            });
        }
    });
}

function updateGroup(groupId, formData) {
    const submitBtn = $('#editGroupForm [type="submit"]');
    
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Updating...');

    $.ajax({
        url: 'api/update_customer_group.php',
        type: 'POST',
        data: formData + '&group_id=' + groupId,
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#create-group-message').html('<div class="alert alert-success">' + response.message + '</div>');
                setTimeout(function() {
                    location.reload();
                }, 1500);
            } else {
                $('#create-group-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Group');
            }
        },
        error: function() {
            $('#create-group-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
            submitBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Update Group');
        }
    });
}

function confirmDelete(groupId) {
    Swal.fire({
        title: 'Delete Group?',
        text: 'Are you sure you want to delete this customer group? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/delete_customer_group.php',
                method: 'POST',
                data: { group_id: groupId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Deleted!',
                            text: 'Customer group has been deleted.',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => {
                            location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Delete Failed',
                            text: response.message
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Server Error',
                        text: 'Error deleting group. Please try again.'
                    });
                }
            });
        }
    });
}

function exportGroups() {
    // Trigger DataTable export
    $('#groupsTable').DataTable().button('.buttons-excel').trigger();
}
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.card-header {
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.action-dropdown .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.action-dropdown .dropdown-menu {
    font-size: 0.875rem;
    min-width: 180px;
}

.action-dropdown .dropdown-item {
    padding: 0.25rem 1rem;
}

.action-dropdown .dropdown-item i {
    width: 18px;
    margin-right: 0.5rem;
}

.group-color {
    border: 1px solid #dee2e6;
}

.progress {
    background-color: #e9ecef;
}

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-info,
.card.bg-warning {
    border: none;
}

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
}

.custom-stat-card h4, 
.custom-stat-card p, 
.custom-stat-card i {
    color: black !important;
    text-shadow: 1px 1px 3px rgba(255, 255, 255, 0.8);
}

.custom-code {
    color: #0f5132 !important;
    background-color: #d1e7dd !important;
    padding: 2px 4px;
    border-radius: 4px;
}

.table thead th {
    background-color: #f8f9fa !important;
}
</style>
</style>

<?php
// Include the footer
require_once FOOTER_FILE;

// Flush the buffer
ob_end_flush();
?>