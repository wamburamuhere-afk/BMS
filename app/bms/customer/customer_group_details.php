<?php
// scope-audit: skip — customer group details; groups have no project_id
// Start the buffer
ob_start();

// Include the header
require_once HEADER_FILE;

// Phase 5a — enforce permission on customer group detail view
autoEnforcePermission('customer_groups');

// Check if group ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: ' . getUrl('customers/groups'));
    exit;
}

$group_id = $_GET['id'];

// Fetch group data with member count
$stmt = $pdo->prepare("
    SELECT 
        cg.*,
        COUNT(cgc.customer_id) as customer_count,
        u.username as created_by_name,
        u2.username as updated_by_name
    FROM customer_groups cg
    LEFT JOIN customer_group_customers cgc ON cg.group_id = cgc.group_id
    LEFT JOIN users u ON cg.created_by = u.user_id
    LEFT JOIN users u2 ON cg.updated_by = u2.user_id
    WHERE cg.group_id = ?
    GROUP BY cg.group_id
");
$stmt->execute([$group_id]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if group exists
if (!$group) {
    header('Location: ' . getUrl('customers/groups'));
    exit;
}

// Fetch group members with customer details
$stmt = $pdo->prepare("
    SELECT 
        c.customer_id,
        c.entity_type,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.company_name,
        c.phone_number,
        c.email_address,
        c.occupation_business,
        c.created_at,
        cgc.added_at,
        u.username as added_by_name
    FROM customer_group_customers cgc
    INNER JOIN customers c ON cgc.customer_id = c.customer_id
    LEFT JOIN users u ON cgc.added_by = u.user_id
    WHERE cgc.group_id = ?
    ORDER BY cgc.added_at DESC
    LIMIT 100
");
$stmt->execute([$group_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch group statistics
$total_customers = $pdo->query("SELECT COUNT(*) as total FROM customers")->fetchColumn();
$member_percentage = $total_customers > 0 ? round(($group['customer_count'] / $total_customers) * 100, 1) : 0;

// Decode rules if they exist
$rules = null;
if (!empty($group['rules'])) {
    $rules = json_decode($group['rules'], true);
}


// Format group type display
$group_type_display = [
    'static' => 'Static Group (Manual)',
    'dynamic' => 'Dynamic Group (Rule-based)'
];

// Format operator display
$operator_display = [
    'equals' => 'Equals',
    'contains' => 'Contains',
    'starts_with' => 'Starts With',
    'ends_with' => 'Ends With'
];
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-people-fill"></i> Group Details</h2>
                    <p class="text-muted mb-0">Detailed information about <?= safe_output($group['group_name']) ?> group</p>
                </div>
                <div>
                    <a href="<?= getUrl('customers/groups') ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back to Groups
                    </a>
                    <a href="<?= getUrl('customers/group_members') ?>?id=<?= $group_id ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-people"></i> Manage Members
                    </a>
                    <button type="button" class="btn btn-warning btn-sm" onclick="editGroup(<?= $group_id ?>)">
                        <i class="bi bi-pencil"></i> Edit Group
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column - Group Information -->
        <div class="col-md-4 mb-4">
            <!-- Group Summary Card -->
            <div class="card mb-4">
                <div class="card-header text-white" style="background-color: <?= safe_output($group['color']) ?>">
                    <h6 class="mb-0"><i class="bi bi-info-circle"></i> Group Summary</h6>
                </div>
                <div class="card-body text-center">
                    <div class="group-color mb-3 mx-auto" 
                         style="width: 80px; height: 80px; background-color: <?= safe_output($group['color']) ?>; border-radius: 50%; border: 3px solid #dee2e6;"></div>
                    <h4><?= safe_output($group['group_name']) ?></h4>
                    <p class="text-muted"><?= safe_output($group['description']) ?></p>
                    
                    <div class="row text-center mt-4">
                        <div class="col-6">
                            <h5 class="mb-0"><?= $group['customer_count'] ?></h5>
                            <small class="text-muted">Members</small>
                        </div>
                        <div class="col-6">
                            <h5 class="mb-0"><?= $member_percentage ?>%</h5>
                            <small class="text-muted">Coverage</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Card -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-lightning"></i> Quick Actions</h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="<?= getUrl('customers/group_members') ?>?id=<?= $group_id ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-people"></i> Manage Members
                        </a>
                        <button type="button" class="btn btn-outline-warning btn-sm" onclick="editGroup(<?= $group_id ?>)">
                            <i class="bi bi-pencil"></i> Edit Group
                        </button>
                        <?php if ($group['group_type'] === 'dynamic' && $rules): ?>
                        <button type="button" class="btn btn-outline-info btn-sm" onclick="refreshDynamicGroup(<?= $group_id ?>)">
                            <i class="bi bi-arrow-clockwise"></i> Refresh Members
                        </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="exportGroupMembers(<?= $group_id ?>)">
                            <i class="bi bi-download"></i> Export Members
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDelete(<?= $group_id ?>)">
                            <i class="bi bi-trash"></i> Delete Group
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column - Detailed Information -->
        <div class="col-md-8">
            <!-- Group Information -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-card-checklist"></i> Group Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Group Name</label>
                            <p class="mb-0 fw-semibold"><?= safe_output($group['group_name']) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Group Type</label>
                            <p class="mb-0">
                                <span class="badge bg-<?= $group['group_type'] === 'static' ? 'info' : 'warning' ?>">
                                    <?= safe_output($group_type_display[$group['group_type']] ?? $group['group_type']) ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Status</label>
                            <p class="mb-0">
                                <span class="badge bg-<?= $group['status'] === 'active' ? 'success' : 'secondary' ?>">
                                    <?= ucfirst($group['status']) ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Group Color</label>
                            <p class="mb-0">
                                <span class="badge" style="background-color: <?= safe_output($group['color']) ?>; color: white;">
                                    <?= safe_output($group['color']) ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label text-muted small mb-1">Description</label>
                            <p class="mb-0 fw-semibold"><?= safe_output($group['description']) ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dynamic Group Rules -->
            <?php if ($group['group_type'] === 'dynamic' && $rules): ?>
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="bi bi-diagram-3"></i> Dynamic Group Rules</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-muted small mb-1">Field</label>
                            <p class="mb-0 fw-semibold text-capitalize"><?= str_replace('_', ' ', $rules['field']) ?></p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-muted small mb-1">Operator</label>
                            <p class="mb-0 fw-semibold"><?= safe_output($operator_display[$rules['operator']] ?? $rules['operator']) ?></p>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label text-muted small mb-1">Value</label>
                            <p class="mb-0 fw-semibold"><?= safe_output($rules['value']) ?></p>
                        </div>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle"></i> This group automatically includes customers where 
                        <strong><?= $rules['field'] ?></strong> 
                        <?= $rules['operator'] ?> 
                        "<strong><?= $rules['value'] ?></strong>"
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Member Statistics -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="bi bi-bar-chart"></i> Member Statistics</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3 text-center">
                            <div class="border rounded p-3">
                                <h3 class="text-primary mb-0"><?= $group['customer_count'] ?></h3>
                                <small class="text-muted">Total Members</small>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3 text-center">
                            <div class="border rounded p-3">
                                <h3 class="text-success mb-0"><?= $member_percentage ?>%</h3>
                                <small class="text-muted">Customer Coverage</small>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Group Coverage</label>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar" 
                                     role="progressbar" 
                                     style="width: <?= $member_percentage ?>%; background-color: <?= safe_output($group['color']) ?>"
                                     aria-valuenow="<?= $member_percentage ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?= $member_percentage ?>%
                                </div>
                            </div>
                            <div class="text-muted small mt-1">
                                <?= $group['customer_count'] ?> out of <?= $total_customers ?> total customers
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Members -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-people"></i> Recent Members</h6>
                        <a href="customer_group_members.php?id=<?= $group_id ?>" class="btn btn-sm btn-light">
                            View All Members
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (count($members) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Customer</th>
                                        <th>Contact</th>
                                        <th>Occupation</th>
                                        <th>Added On</th>
                                        <th>Added By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $member): 
                                        $customer_name = ($member['entity_type'] === 'company') ? 
                                            safe_output($member['company_name']) : 
                                            safe_output(trim($member['first_name'] . ' ' . $member['last_name']));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="me-2">
                                                    <i class="bi bi-<?= $member['entity_type'] === 'company' ? 'building' : 'person' ?> text-muted"></i>
                                                </div>
                                                <div>
                                                    <strong><?= $customer_name ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?= $member['entity_type'] === 'company' ? 'Company' : 'Individual' ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                <small class="text-muted"><?= safe_output($member['phone_number']) ?></small>
                                                <br>
                                                <small class="text-muted"><?= safe_output($member['email_address']) ?></small>
                                            </div>
                                        </td>
                                        <td><?= safe_output($member['occupation_business']) ?></td>
                                        <td>
                                            <small><?= date('M d, Y', strtotime($member['added_at'])) ?></small>
                                            <br>
                                            <small class="text-muted"><?= date('h:i A', strtotime($member['added_at'])) ?></small>
                                        </td>
                                        <td><?= safe_output($member['added_by_name']) ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="customer_details.php?id=<?= $member['customer_id'] ?>" 
                                                   class="btn btn-outline-primary" 
                                                   title="View Customer">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if ($group['group_type'] === 'static'): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-danger" 
                                                        title="Remove from Group"
                                                        onclick="removeMember(<?= $group_id ?>, <?= $member['customer_id'] ?>, '<?= addslashes($customer_name) ?>')">
                                                    <i class="bi bi-person-dash"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($group['customer_count'] > 10): ?>
                        <div class="text-center mt-3">
                            <a href="customer_group_members.php?id=<?= $group_id ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-list-ul"></i> View All <?= $group['customer_count'] ?> Members
                            </a>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-people" style="font-size: 3rem; color: #6c757d;"></i>
                            <h5 class="mt-3 text-muted">No Members in Group</h5>
                            <p class="text-muted">
                                <?php if ($group['group_type'] === 'dynamic'): ?>
                                    No customers match the current group rules.
                                <?php else: ?>
                                    This group doesn't have any members yet.
                                <?php endif; ?>
                            </p>
                            <a href="customer_group_members.php?id=<?= $group_id ?>" class="btn btn-primary">
                                <i class="bi bi-person-plus"></i> Add Members
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- System Information -->
            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h6 class="mb-0"><i class="bi bi-clock-history"></i> System Information</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Created By</label>
                            <p class="mb-0 fw-semibold"><?= safe_output($group['created_by_name']) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Created Date</label>
                            <p class="mb-0 fw-semibold"><?= date('M d, Y \a\t h:i A', strtotime($group['created_at'])) ?></p>
                        </div>
                        <?php if (!empty($group['updated_at'])): ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Last Updated By</label>
                            <p class="mb-0 fw-semibold"><?= safe_output($group['updated_by_name']) ?></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted small mb-1">Last Updated</label>
                            <p class="mb-0 fw-semibold"><?= date('M d, Y \a\t h:i A', strtotime($group['updated_at'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include jQuery, Bootstrap JS, and Bootstrap Icons -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">

<script>
function editGroup(groupId) {
    // Load group data and open edit modal
    $.ajax({
        url: 'api/get_customer_group.php',
        type: 'GET',
        data: { id: groupId },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Redirect to edit page or open modal
                window.location.href = '<?= getUrl('customers/groups') ?>?edit=' + groupId;
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Error loading group data: ' + response.message });
            }
        },
        error: function() {
            Swal.fire({ icon: 'error', title: 'Error', text: 'Error loading group data. Please try again.' });
        }
    });
}

function refreshDynamicGroup(groupId) {
    Swal.fire({
        title: 'Refresh Members?',
        text: 'This will refresh the group members based on current rules. Continue?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#0d6efd',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, refresh!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/refresh_dynamic_group.php',
                type: 'POST',
                data: { group_id: groupId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: response.message,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => { location.reload(); });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Error refreshing group: ' + response.message });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error refreshing group. Please try again.' });
                }
            });
        }
    });
}

function exportGroupMembers(groupId) {
    // Trigger export
    window.location.href = 'api/export_group_members.php?group_id=' + groupId;
}

function removeMember(groupId, customerId, customerName) {
    Swal.fire({
        title: 'Remove Member?',
        text: 'Remove ' + customerName + ' from this group?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, remove!',
        cancelButtonText: 'Cancel'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'api/remove_group_member.php',
                type: 'POST',
                data: { 
                    group_id: groupId,
                    customer_id: customerId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: response.message,
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => { location.reload(); });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Error removing member: ' + response.message });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error removing member. Please try again.' });
                }
            });
        }
    });
}

function confirmDelete(groupId) {
    Swal.fire({
        title: 'Delete Group?',
        text: 'Are you sure you want to delete this customer group? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, keep it'
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
                            title: 'Group Deleted!',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#0d6efd'
                        }).then(() => { window.location.href = '<?= getUrl('customers/groups') ?>'; });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Error deleting group: ' + response.message });
                    }
                },
                error: function() {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Error deleting group. Please try again.' });
                }
            });
        }
    });
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

.table-sm td, .table-sm th {
    padding: 0.75rem;
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

.group-color {
    border: 2px solid #dee2e6;
}

.progress {
    background-color: #e9ecef;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .d-flex.justify-content-between.align-items-center {
        flex-direction: column;
        gap: 1rem;
    }
    
    .d-flex.justify-content-between.align-items-center > div:last-child {
        align-self: stretch;
    }
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