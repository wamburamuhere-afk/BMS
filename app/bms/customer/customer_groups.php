<?php
// scope-audit: skip — customer groups management; groups have no project_id; global org structure
// Start the buffer
ob_start();

// Phase 5a — enforce permission on customer groups list (before any output)
autoEnforcePermission('customer_groups');
require_once ROOT_DIR . '/core/customer_groups.php';

$can_create = canCreate('customer_groups');
$can_edit   = canEdit('customer_groups');
$can_delete = canDelete('customer_groups');

// Include the header
require_once HEADER_FILE;

// Fetch customer groups
$stmt = $pdo->query("
    SELECT 
        cg.*,
        COUNT(gc.customer_id) as customer_count,
        u.username as created_by_name
    FROM customer_groups cg
    LEFT JOIN customer_group_customers cgc ON cg.group_id = cgc.group_id
    LEFT JOIN customers gc ON gc.customer_id = cgc.customer_id AND gc.status != 'deleted'
    LEFT JOIN users u ON cg.created_by = u.user_id
    WHERE cg.status != 'deleted'
    GROUP BY cg.group_id
    ORDER BY cg.created_at DESC
");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch total customers count for statistics
$total_customers = $pdo->query("SELECT COUNT(*) as total FROM customers WHERE status != 'deleted'")->fetchColumn();

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
                    <?php if ($can_create): ?>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openCreateGroup()">
                        <i class="bi bi-plus-circle"></i> Create New Group
                    </button>
                    <?php endif; ?>
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
                                <th style="width:50px;">S/NO</th>
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
                                <td></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="group-color me-2" 
                                             style="width: 16px; height: 16px; background-color: <?= safe_output($group['color'] ?? '#007bff') ?>; border-radius: 3px;"></div>
                                        <strong><?= caseFormat($group['group_name']) ?></strong>
                                    </div>
                                </td>
                                <td><?= caseFormat($group['description']) ?></td>
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
                                <td><?= caseFormat($group['created_by_name']) ?></td>
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
                                            <?php if ($can_edit): ?>
                                            <li>
                                                <a class="dropdown-item" href="#" onclick="editGroup(<?= (int)$group['group_id'] ?>); return false;">
                                                    <i class="bi bi-pencil"></i> Edit Group
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                            <?php if ($can_delete): ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= (int)$group['group_id'] ?>); return false;">
                                                    <i class="bi bi-trash"></i> Delete Group
                                                </a>
                                            </li>
                                            <?php endif; ?>
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
                    <?php if ($can_create): ?>
                    <button type="button" class="btn btn-primary mt-2" onclick="openCreateGroup()">
                        <i class="bi bi-plus-circle"></i> Create Your First Group
                    </button>
                    <?php endif; ?>
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
                <input type="hidden" name="group_id" id="group_id" value="">
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
                                    <select class="form-select" name="rule_field" id="rule_field">
                                        <option value="">Select Field</option>
                                        <?php foreach (customerGroupRuleFields() as $key => [$label]): ?>
                                        <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <select class="form-select" name="rule_operator" id="rule_operator">
                                            <?php foreach (customerGroupRuleOperators() as $key => $label): ?>
                                            <option value="<?= $key ?>"><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <input type="text" class="form-control" name="rule_value" id="rule_value" placeholder="Value" maxlength="255">
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
                    <button type="submit" class="btn btn-primary" id="groupSubmitBtn">
                        <i class="bi bi-check-circle"></i> Create Group
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const GROUP_URLS = {
    create: <?= json_encode(buildUrl('api/create_customer_group.php')) ?>,
    update: <?= json_encode(buildUrl('api/update_customer_group.php')) ?>,
    get:    <?= json_encode(buildUrl('api/get_customer_group.php')) ?>,
    del:    <?= json_encode(buildUrl('api/delete_customer_group.php')) ?>
};
const SUBMIT_LABEL = { create: '<i class="bi bi-check-circle"></i> Create Group', edit: '<i class="bi bi-check-circle"></i> Update Group' };

$(document).ready(function() {
    $('#groupsTable').DataTable({
        language: {
            search: "Search groups:",
            lengthMenu: "Show _MENU_ groups per page",
            info: "Showing _START_ to _END_ of _TOTAL_ groups",
            paginate: { first: "First", last: "Last", next: "Next", previous: "Previous" }
        },
        responsive: true,
        order: [[0, 'asc']],
        columnDefs: [$.extend({ targets: 0 }, BMSSerial.column()), { targets: -1, orderable: false }],
        dom: 'Bfrtip',
        buttons: [
            { extend: 'copyHtml5', text: '<i class="bi bi-clipboard"></i> Copy', titleAttr: 'Copy to clipboard', exportOptions: { columns: ':not(:last-child)' } },
            { extend: 'excelHtml5', text: '<i class="bi bi-file-excel"></i> Excel', titleAttr: 'Export to Excel', exportOptions: { columns: ':not(:last-child)' },
              title: 'Customer_Groups_' + new Date().toISOString().slice(0,10) },
            { extend: 'csvHtml5', text: '<i class="bi bi-file-text"></i> CSV', titleAttr: 'Export to CSV', exportOptions: { columns: ':not(:last-child)' },
              title: 'Customer_Groups_' + new Date().toISOString().slice(0,10) }
        ]
    });

    $('#searchGroups').on('keyup', function() {
        $('#groupsTable').DataTable().search($(this).val()).draw();
    });

    $('#group_type').on('change', function() {
        $('#dynamicRules').toggleClass('d-none', $(this).val() !== 'dynamic');
    });

    $('#color').on('change input', function() {
        $(this).next('.input-group-text').text($(this).val());
    });

    // One form for create and edit — group_id decides which.
    $('#createGroupForm').on('submit', function(e) {
        e.preventDefault();
        const editing = $('#group_id').val() !== '';
        const btn = $('#groupSubmitBtn');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');
        $.ajax({
            url: editing ? GROUP_URLS.update : GROUP_URLS.create,
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                const box = $('<div class="alert"></div>').text(response.message || (response.success ? 'Saved.' : 'Something went wrong.'));
                $('#create-group-message').empty().append(box.addClass(response.success ? 'alert-success' : 'alert-danger'));
                if (response.success) {
                    setTimeout(function() { location.href = <?= json_encode(getUrl('customers/groups')) ?>; }, 1200);
                } else {
                    btn.prop('disabled', false).html(editing ? SUBMIT_LABEL.edit : SUBMIT_LABEL.create);
                }
            },
            error: function() {
                $('#create-group-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                btn.prop('disabled', false).html(editing ? SUBMIT_LABEL.edit : SUBMIT_LABEL.create);
            }
        });
    });

    // Closing the modal always returns it to "create" mode.
    $('#createGroupModal').on('hidden.bs.modal', resetGroupForm);

    // Opened from Group Details → Edit (?edit=ID).
    const editId = parseInt(new URLSearchParams(location.search).get('edit'), 10);
    if (editId > 0 && <?= json_encode($can_edit) ?>) editGroup(editId);
});

function resetGroupForm() {
    const form = $('#createGroupForm')[0];
    form.reset();
    $('#group_id').val('');
    $('#create-group-message').html('');
    $('#dynamicRules').addClass('d-none');
    $('#color').next('.input-group-text').text($('#color').val());
    $('#createGroupModalLabel').html('<i class="bi bi-plus-circle"></i> Create New Customer Group');
    $('#groupSubmitBtn').prop('disabled', false).html(SUBMIT_LABEL.create);
}

function openCreateGroup() {
    resetGroupForm();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('createGroupModal')).show();
}

function editGroup(groupId) {
    $.ajax({
        url: GROUP_URLS.get,
        type: 'GET',
        data: { id: groupId },
        dataType: 'json',
        success: function(response) {
            if (!response.success) {
                Swal.fire({ icon: 'error', title: 'Load Error', text: 'Error loading group data: ' + response.message });
                return;
            }
            const g = response.data;
            resetGroupForm();
            $('#group_id').val(g.group_id);
            $('#group_name').val(g.group_name);
            $('#description').val(g.description);
            $('#group_type').val(g.group_type);
            $('#color').val(g.color).next('.input-group-text').text(g.color);
            $('#status').val(g.status);
            $('#dynamicRules').toggleClass('d-none', g.group_type !== 'dynamic');
            if (g.rules) {
                $('#rule_field').val(g.rules.field === 'entity_type' ? 'customer_type' : g.rules.field);
                $('#rule_operator').val(g.rules.operator);
                $('#rule_value').val(g.rules.value);
            }
            $('#createGroupModalLabel').html('<i class="bi bi-pencil"></i> Edit Customer Group');
            $('#groupSubmitBtn').html(SUBMIT_LABEL.edit);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('createGroupModal')).show();
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Server Error', text: 'Error loading group data. Please try again.' });
        }
    });
}

function confirmDelete(groupId) {
    Swal.fire({
        title: 'Delete Group?',
        text: 'Are you sure you want to delete this customer group?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (!result.isConfirmed) return;
        $.ajax({
            url: GROUP_URLS.del,
            method: 'POST',
            data: { group_id: groupId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted!', text: 'Customer group has been deleted.', timer: 2000, showConfirmButton: false })
                        .then(() => { location.href = <?= json_encode(getUrl('customers/groups')) ?>; });
                } else {
                    Swal.fire({ icon: 'error', title: 'Delete Failed', text: response.message });
                }
            },
            error: function() {
                Swal.fire({ icon: 'error', title: 'Server Error', text: 'Error deleting group. Please try again.' });
            }
        });
    });
}

function exportGroups() {
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