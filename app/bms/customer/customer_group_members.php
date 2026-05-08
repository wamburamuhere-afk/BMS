<?php
// Start the buffer
ob_start();

// Include the header
require_once HEADER_FILE;

// Check if group ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: ' . getUrl('customers/groups'));
    exit;
}

$group_id = $_GET['id'];

// Fetch group data
$stmt = $pdo->prepare("
    SELECT 
        cg.*,
        COUNT(cgc.customer_id) as customer_count,
        u.username as created_by_name
    FROM customer_groups cg
    LEFT JOIN customer_group_customers cgc ON cg.group_id = cgc.group_id
    LEFT JOIN users u ON cg.created_by = u.user_id
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
        c.office_business_location,
        c.level_of_education,
        c.marital_status,
        c.created_at as customer_since,
        cgc.added_at,
        u.username as added_by_name
    FROM customer_group_customers cgc
    INNER JOIN customers c ON cgc.customer_id = c.customer_id
    LEFT JOIN users u ON cgc.added_by = u.user_id
    WHERE cgc.group_id = ?
    ORDER BY cgc.added_at DESC
");
$stmt->execute([$group_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available customers not in this group (for static groups)
$available_customers = [];
if ($group['group_type'] === 'static') {
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
            c.occupation_business
        FROM customers c
        WHERE c.customer_id NOT IN (
            SELECT customer_id 
            FROM customer_group_customers 
            WHERE group_id = ?
        )
        ORDER BY c.first_name, c.last_name, c.company_name
    ");
    $stmt->execute([$group_id]);
    $available_customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}


// Format customer name
function formatCustomerName($customer) {
    if ($customer['entity_type'] === 'company') {
        return safe_output($customer['company_name']);
    } else {
        return safe_output(trim($customer['first_name'] . ' ' . $customer['last_name']));
    }
}
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= getUrl('customers/groups') ?>">Customer Groups</a></li>
                    <li class="breadcrumb-item"><a href="<?= getUrl('customers/group_details') ?>?id=<?= $group_id ?>"><?= safe_output($group['group_name']) ?></a></li>
                    <li class="breadcrumb-item active" aria-current="page">Manage Members</li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-people"></i> Group Members</h2>
                    <p class="text-muted mb-0">Manage members of <strong><?= safe_output($group['group_name']) ?></strong> group</p>
                </div>
                <div>
                    <a href="<?= getUrl('customers/group_details') ?>?id=<?= $group_id ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left"></i> Back to Group
                    </a>
                    <?php if ($group['group_type'] === 'static'): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMembersModal">
                        <i class="bi bi-person-plus"></i> Add Members
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-success btn-sm" onclick="exportMembers()">
                        <i class="bi bi-download"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Group Summary -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card custom-stat-card text-white"><div class="card-body text-center">
                    <h4 class="mb-0"><?= count($members) ?></h4>
                    <p class="mb-0">Total Members</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card text-white"><div class="card-body text-center">
                    <h4 class="mb-0">
                        <?= $group['group_type'] === 'static' ? 'Manual' : 'Auto' ?>
                    </h4>
                    <p class="mb-0">Group Type</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-<?= $group['status'] === 'active' ? 'success' : 'secondary' ?> text-white">
                <div class="card-body text-center">
                    <h4 class="mb-0"><?= ucfirst($group['status']) ?></h4>
                    <p class="mb-0">Status</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card custom-stat-card text-dark"><div class="card-body text-center">
                    <h4 class="mb-0"><?= count($available_customers) ?></h4>
                    <p class="mb-0">Available Customers</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Group Information Alert -->
    <div class="alert alert-info d-flex align-items-center">
        <i class="bi bi-info-circle me-2" style="font-size: 1.2rem;"></i>
        <div>
            <strong><?= safe_output($group['group_name']) ?></strong> - 
            <?= safe_output($group['description']) ?>
            <?php if ($group['group_type'] === 'dynamic'): ?>
                <br><small class="text-muted">This is a dynamic group. Members are automatically managed based on group rules.</small>
            <?php else: ?>
                <br><small class="text-muted">This is a static group. Members must be manually added or removed.</small>
            <?php endif; ?>
        </div>
    </div>

    <!-- Members Table -->
    <div class="card">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-people-fill"></i> 
                    Group Members (<?= count($members) ?>)
                </h5>
                <div class="d-flex">
                    <input type="text" id="searchMembers" class="form-control form-control-sm me-2" placeholder="Search members..." style="width: 200px;">
                    <?php if ($group['group_type'] === 'dynamic'): ?>
                    <button type="button" class="btn btn-sm btn-light me-2" onclick="refreshDynamicGroup()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <?php if (count($members) > 0): ?>
                <div class="table-responsive">
                    <table id="membersTable" class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Customer</th>
                                <th>Contact Information</th>
                                <th>Occupation/Business</th>
                                <th>Customer Since</th>
                                <th>Added to Group</th>
                                <th>Added By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member): 
                                $customer_name = formatCustomerName($member);
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <i class="bi bi-<?= $member['entity_type'] === 'company' ? 'building' : 'person' ?> 
                                                text-<?= $member['entity_type'] === 'company' ? 'info' : 'primary' ?>" 
                                                style="font-size: 1.5rem;">
                                            </i>
                                        </div>
                                        <div>
                                            <strong>
                                                <a href="<?= getUrl('customers/details') ?>?id=<?= $member['customer_id'] ?>" class="text-decoration-none">
                                                    <?= $customer_name ?>
                                                </a>
                                            </strong>
                                            <br>
                                            <span class="badge bg-<?= $member['entity_type'] === 'company' ? 'info' : 'primary' ?>">
                                                <?= $member['entity_type'] === 'company' ? 'Company' : 'Individual' ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <i class="bi bi-telephone text-muted me-1"></i>
                                        <?= safe_output($member['phone_number']) ?>
                                        <br>
                                        <i class="bi bi-envelope text-muted me-1"></i>
                                        <?= safe_output($member['email_address']) ?>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= safe_output($member['occupation_business']) ?></strong>
                                        <?php if (!empty($member['office_business_location'])): ?>
                                        <br>
                                        <small class="text-muted">
                                            <i class="bi bi-geo-alt"></i>
                                            <?= safe_output($member['office_business_location']) ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <small><?= date('M d, Y', strtotime($member['customer_since'])) ?></small>
                                </td>
                                <td>
                                    <small><?= date('M d, Y', strtotime($member['added_at'])) ?></small>
                                    <br>
                                    <small class="text-muted"><?= date('h:i A', strtotime($member['added_at'])) ?></small>
                                </td>
                                <td>
                                    <?= safe_output($member['added_by_name']) ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= getUrl('customers/details') ?>?id=<?= $member['customer_id'] ?>" 
                                           class="btn btn-outline-primary" 
                                           title="View Customer Details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($group['group_type'] === 'static'): ?>
                                        <button type="button" 
                                                class="btn btn-outline-danger" 
                                                title="Remove from Group"
                                                onclick="removeMember(<?= $member['customer_id'] ?>, '<?= addslashes($customer_name) ?>')">
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
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-people" style="font-size: 4rem; color: #6c757d;"></i>
                    <h4 class="mt-3 text-muted">No Members in Group</h4>
                    <p class="text-muted">
                        <?php if ($group['group_type'] === 'dynamic'): ?>
                            No customers currently match the group rules.
                        <?php else: ?>
                            This group doesn't have any members yet.
                        <?php endif; ?>
                    </p>
                    <?php if ($group['group_type'] === 'static' && count($available_customers) > 0): ?>
                    <button type="button" class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addMembersModal">
                        <i class="bi bi-person-plus"></i> Add Members
                    </button>
                    <?php elseif ($group['group_type'] === 'static'): ?>
                    <p class="text-muted">All customers are already in this group or no customers available.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Members Modal (for static groups) -->
<?php if ($group['group_type'] === 'static'): ?>
<div class="modal fade" id="addMembersModal" tabindex="-1" aria-labelledby="addMembersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addMembersModalLabel">
                    <i class="bi bi-person-plus"></i> Add Members to Group
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addMembersForm">
                <input type="hidden" name="group_id" value="<?= $group_id ?>">
                <div class="modal-body">
                    <div id="add-members-message" class="mb-3"></div>
                    
                    <?php if (count($available_customers) > 0): ?>
                        <div class="mb-3">
                            <label class="form-label">Select Customers to Add</label>
                            <div class="input-group mb-3">
                                <input type="text" id="searchAvailableCustomers" class="form-control" placeholder="Search available customers...">
                                <button type="button" class="btn btn-outline-secondary" onclick="clearSearch()">
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive" style="max-height: 400px;">
                            <table class="table table-sm table-hover">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="selectAllCustomers" onchange="toggleSelectAll(this)">
                                        </th>
                                        <th>Customer</th>
                                        <th>Contact</th>
                                        <th>Occupation</th>
                                    </tr>
                                </thead>
                                <tbody id="availableCustomersList">
                                    <?php foreach ($available_customers as $customer): 
                                        $customer_name = formatCustomerName($customer);
                                    ?>
                                    <tr class="customer-row">
                                        <td>
                                            <input type="checkbox" name="customer_ids[]" value="<?= $customer['customer_id'] ?>" class="customer-checkbox">
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <i class="bi bi-<?= $customer['entity_type'] === 'company' ? 'building' : 'person' ?> 
                                                    text-<?= $customer['entity_type'] === 'company' ? 'info' : 'primary' ?> me-2">
                                                </i>
                                                <strong><?= $customer_name ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= safe_output($customer['phone_number']) ?></small>
                                            <br>
                                            <small class="text-muted"><?= safe_output($customer['email_address']) ?></small>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= safe_output($customer['occupation_business']) ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <span id="selectedCount">0</span> customer(s) selected
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            <i class="bi bi-info-circle"></i>
                            <p class="mb-0">All customers are already members of this group.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <?php if (count($available_customers) > 0): ?>
                    <button type="submit" class="btn btn-primary" id="addMembersBtn">
                        <i class="bi bi-person-plus"></i> Add Selected Members
                    </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Include jQuery, Bootstrap JS, and Bootstrap Icons -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">

<script>
$(document).ready(function() {
    // Initialize DataTable for members
    $('#membersTable').DataTable({
        language: {
            search: "Search members:",
            lengthMenu: "Show _MENU_ members per page",
            info: "Showing _START_ to _END_ of _TOTAL_ members",
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
                title: 'Group_Members_<?= safe_output($group['group_name']) ?>_' + new Date().toISOString().slice(0,10)
            },
            {
                extend: 'csvHtml5',
                text: '<i class="bi bi-file-text"></i> CSV',
                titleAttr: 'Export to CSV',
                title: 'Group_Members_<?= safe_output($group['group_name']) ?>_' + new Date().toISOString().slice(0,10)
            }
        ]
    });

    // Search functionality for members table
    $('#searchMembers').on('keyup', function() {
        $('#membersTable').DataTable().search($(this).val()).draw();
    });

    // Search functionality for available customers
    $('#searchAvailableCustomers').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('.customer-row').each(function() {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(searchTerm));
        });
    });

    // Update selected count
    $('.customer-checkbox').change(function() {
        updateSelectedCount();
    });

    // Add members form submission
    $('#addMembersForm').on('submit', function(e) {
        e.preventDefault();
        
        const selectedCustomers = $('input[name="customer_ids[]"]:checked').length;
        if (selectedCustomers === 0) {
            $('#add-members-message').html('<div class="alert alert-warning">Please select at least one customer to add.</div>');
            return;
        }

        const formData = $(this).serialize();
        const submitBtn = $('#addMembersBtn');
        
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Adding...');

        $.ajax({
            url: 'api/add_group_members.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#add-members-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-members-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    submitBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Add Selected Members');
                }
            },
            error: function() {
                $('#add-members-message').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
                submitBtn.prop('disabled', false).html('<i class="bi bi-person-plus"></i> Add Selected Members');
            }
        });
    });

    // Reset modal when closed
    $('#addMembersModal').on('hidden.bs.modal', function() {
        $('#addMembersForm')[0].reset();
        $('#add-members-message').html('');
        $('.customer-checkbox').prop('checked', false);
        updateSelectedCount();
        $('#searchAvailableCustomers').val('');
        $('.customer-row').show();
        $('#addMembersBtn').prop('disabled', false).html('<i class="bi bi-person-plus"></i> Add Selected Members');
    });
});

function toggleSelectAll(checkbox) {
    $('.customer-checkbox').prop('checked', checkbox.checked);
    updateSelectedCount();
}

function updateSelectedCount() {
    const selectedCount = $('input[name="customer_ids[]"]:checked').length;
    $('#selectedCount').text(selectedCount);
}

function clearSearch() {
    $('#searchAvailableCustomers').val('');
    $('.customer-row').show();
}

function removeMember(customerId, customerName) {
    if (!confirm('Remove ' + customerName + ' from this group?')) {
        return;
    }

    $.ajax({
        url: 'api/remove_group_member.php',
        type: 'POST',
        data: { 
            group_id: <?= $group_id ?>,
            customer_id: customerId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error removing member: ' + response.message);
            }
        },
        error: function() {
            alert('Error removing member. Please try again.');
        }
    });
}

function refreshDynamicGroup() {
    if (!confirm('This will refresh the group members based on current rules. Continue?')) {
        return;
    }

    $.ajax({
        url: 'api/refresh_dynamic_group.php',
        type: 'POST',
        data: { group_id: <?= $group_id ?> },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message);
                location.reload();
            } else {
                alert('Error refreshing group: ' + response.message);
            }
        },
        error: function() {
            alert('Error refreshing group. Please try again.');
        }
    });
}

function exportMembers() {
    // Trigger DataTable export
    $('#membersTable').DataTable().button('.buttons-excel').trigger();
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

.table td, .table th {
    padding: 0.75rem;
    vertical-align: middle;
}

.badge {
    font-size: 0.75em;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
}

/* Statistics cards */
.card.bg-primary,
.card.bg-success,
.card.bg-info,
.card.bg-warning,
.card.bg-secondary {
    border: none;
}

/* Modal styles */
.modal-body {
    max-height: 70vh;
    overflow-y: auto;
}

.sticky-top {
    position: sticky;
    top: 0;
    background: white;
    z-index: 1;
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
    
    .btn-group-sm .btn {
        padding: 0.125rem 0.25rem;
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