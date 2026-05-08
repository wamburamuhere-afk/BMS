<?php
// Start the buffer
ob_start();

// Include roots which sets up paths and authentication
require_once __DIR__ . '/../../../roots.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('document_workflow');
}

// Fetch stats for the header
$workflow_stats = $pdo->query("
    SELECT 
        COUNT(*) as total_workflows,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_workflows,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_workflows,
        (SELECT COUNT(*) FROM workflow_steps WHERE status = 'pending') as pending_tasks
    FROM document_workflows
")->fetch(PDO::FETCH_ASSOC);

?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-diagram-3"></i> Document Workflows</h2>
                    
                </div>
                <div>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createWorkflowModal">
                        <i class="bi bi-plus-circle"></i> Create Workflow
                    </button>
                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#assignDocModal">
                        <i class="bi bi-link-45deg"></i> Assign Document
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Section -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card bg-light-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between text-primary">
                        <div>
                            <h4 class="mb-0"><?= $workflow_stats['total_workflows'] ?></h4>
                            <p class="mb-0 small">Total Workflows</p>
                        </div>
                        <i class="bi bi-workflow fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card bg-light-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between text-warning">
                        <div>
                            <h4 class="mb-0"><?= $workflow_stats['active_workflows'] ?></h4>
                            <p class="mb-0 small">Active Now</p>
                        </div>
                        <i class="bi bi-activity fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card bg-light-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between text-info">
                        <div>
                            <h4 class="mb-0"><?= $workflow_stats['pending_tasks'] ?></h4>
                            <p class="mb-0 small">Pending Tasks</p>
                        </div>
                        <i class="bi bi-clock-history fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card bg-light-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between text-success">
                        <div>
                            <h4 class="mb-0"><?= $workflow_stats['completed_workflows'] ?></h4>
                            <p class="mb-0 small">Completed</p>
                        </div>
                        <i class="bi bi-check2-all fs-2"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Grid -->
    <div class="row">
        <!-- My Tasks Sidebar -->
        <div class="col-lg-4 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <div class="d-flex justify-content-between align-items-center mb-0">
                        <h5 class="mb-0 small text-uppercase fw-bold"><i class="bi bi-list-check"></i> Work Items</h5>
                        <?php if (isAdmin() || $user_role == 'CFO'): ?>
                        <div class="form-check form-switch mb-0 mx-2">
                            <input class="form-check-input" type="checkbox" id="toggleAllTasks" onchange="loadMyTasks()">
                            <label class="form-check-label small text-muted" for="toggleAllTasks">All</label>
                        </div>
                        <?php endif; ?>
                        <ul class="nav nav-pills nav-pills-sm" id="taskTabs" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active py-1 px-3 small" data-status="pending" onclick="loadMyTasks('pending')">Active</button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link py-1 px-3 small" data-status="completed" onclick="loadMyTasks('completed')">Done</button>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="card-body p-0" style="max-height: 600px; overflow-y: auto;">
                    <div id="myTasksContainer">
                        <div class="p-4 text-center text-muted">
                            <div class="spinner-border spinner-border-sm mb-2"></div>
                            <p class="small mb-0">Loading items...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow Tables -->
        <div class="col-lg-8 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between">
                    <h5 class="mb-0" id="workflowsCardHeading">Active Workflows</h5>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary active" onclick="loadWorkflows('active')">Active</button>
                        <button class="btn btn-outline-secondary" onclick="loadWorkflows('completed')">Archive</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="workflowsTable" class="table table-hover align-middle" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th>Workflow Name</th>
                                    <th>Priority</th>
                                    <th>Progress</th>
                                    <th>Status</th>
                                    <th>Users</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <!-- Loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Workflow Modal -->
<div class="modal fade" id="createWorkflowModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Create New Workflow</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="createWorkflowForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Workflow Name</label>
                        <input type="text" class="form-control" name="name" required placeholder="e.g. Q3 Audit Review">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Describe the purpose of this workflow..."></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Category</label>
                            <select class="form-select" name="category">
                                <option value="General">General</option>
                                <option value="Finance">Finance</option>
                                <option value="Legal">Legal</option>
                                <option value="HR">HR</option>
                                <option value="Technical">Technical</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Priority</label>
                            <select class="form-select" name="priority">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    <hr>
                    <h6 class="fw-bold mb-3"><i class="bi bi-list-ol"></i> Initial Workflow Step</h6>
                    <div class="bg-light p-3 rounded">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Step Name</label>
                            <input type="text" class="form-control form-control-sm" name="step_name" required placeholder="e.g. Initial Document Review">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Assign To</label>
                                <select class="form-select form-select-sm" name="assigned_to" id="userListSelect" required>
                                    <!-- Loaded via AJAX -->
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label small fw-bold">Due Date</label>
                                <input type="date" class="form-control form-control-sm" name="due_date" required>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSaveWorkflow">Create Workflow</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Document Modal -->
<div class="modal fade" id="assignDocModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-link-45deg"></i> Link Document to Workflow</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="assignDocForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Workflow</label>
                        <select class="form-select" name="workflow_id" id="activeWorkflowsSelect" required>
                            <!-- Loaded via AJAX -->
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Document(s)</label>
                        <select class="form-select" name="document_ids[]" id="documentListSelect" multiple required style="height: 150px;">
                            <!-- Loaded via AJAX -->
                        </select>
                        <div class="form-text mt-1 small">Hold Ctrl to select multiple documents.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="btnAssignDoc">Link Documents</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Workflow Details Modal -->
<div class="modal fade" id="workflowDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="bi bi-eye"></i> Workflow Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="workflowDetailsBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Workflow Modal -->
<div class="modal fade" id="editWorkflowModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square"></i> Edit Workflow</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="editWorkflowForm">
                <input type="hidden" name="id" id="editWorkflowId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Workflow Name</label>
                        <input type="text" class="form-control" name="name" id="editWorkflowName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Description</label>
                        <textarea class="form-control" name="description" id="editWorkflowDesc" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Category</label>
                            <select class="form-select" name="category" id="editWorkflowCat">
                                <option value="General">General</option>
                                <option value="Finance">Finance</option>
                                <option value="Legal">Legal</option>
                                <option value="HR">HR</option>
                                <option value="Technical">Technical</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">Priority</label>
                            <select class="form-select" name="priority" id="editWorkflowPri">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Status</label>
                        <select class="form-select" name="status" id="editWorkflowStatus">
                            <option value="draft">Draft (Inactive)</option>
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-bold" id="btnUpdateWorkflow">Update Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Task Details Modal -->
<div class="modal fade" id="taskDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-info-circle"></i> Task Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="taskDetailsBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" id="btnSaveTaskNote" style="display:none">
                    <i class="bi bi-save"></i> Save Note
                </button>
                <button type="button" class="btn btn-success" id="btnCompleteTask" style="display:none">
                    <i class="bi bi-check-lg"></i> Mark as Completed
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 is kept as it is not in footer -->
<script src="<?= getUrl('assets/js/sweetalert2.all.min.js') ?>"></script>
<!-- DataTables JS is handled by footer.php -->

<script>
$(document).ready(function() {
    window.userPermissions = {
        canEdit: <?= canEdit('documents') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('documents') ? 'true' : 'false' ?>
    };
    window.currentWorkflowStatus = 'active';
    loadMyTasks();
    initWorkflowTable();
    loadSelectOptions();
});

function loadSelectOptions() {
    // Load users
    $.get('/ajax/get_all_users.php', function(users) {
        let html = '<option value="">Select User</option>';
        users.forEach(u => html += `<option value="${u.user_id}">${escapeHtml(u.full_name)} (${u.role_name})</option>`);
        $('#userListSelect').html(html);
    });

    // Load documents
    $.get(`${APP_URL}/ajax/get_all_documents.php`, function(docs) {
        let html = '';
        docs.forEach(d => html += `<option value="${d.id}">${escapeHtml(d.document_name)} (${d.file_type.toUpperCase()})</option>`);
        $('#documentListSelect').html(html);
    });

    // Load active workflows for assignment
    $.get(`${APP_URL}/ajax/get_active_workflows_list.php`, function(workflows) {
        let html = '<option value="">Select Workflow</option>';
        workflows.forEach(w => html += `<option value="${w.id}">${escapeHtml(w.name)}</option>`);
        $('#activeWorkflowsSelect').html(html);
    });
}

function initWorkflowTable() {
    $('#workflowsTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: `${APP_URL}/api/get_workflows.php`,
            data: d => { d.status = window.currentWorkflowStatus; }
        },
        columns: [
            { 
                data: 'name',
                render: (data, t, row) => `<strong>${escapeHtml(data)}</strong><br><small class="text-muted">${row.document_count} Documents</small>`
            },
            { 
                data: 'priority',
                render: data => {
                    let p = data.toLowerCase();
                    let color = p == 'high' ? 'danger' : (p == 'medium' ? 'warning' : 'info');
                    let icon = p == 'high' ? 'exclamation-octagon' : (p == 'medium' ? 'exclamation-triangle' : 'info-circle');
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-3 text-uppercase"><i class="bi bi-${icon}"></i> ${data}</span>`;
                }
            },
            {
                data: 'progress',
                render: data => `
                    <div class="progress" style="height: 6px; width: 100px;">
                        <div class="progress-bar" style="width: ${data}%"></div>
                    </div>
                    <small class="text-muted">${data}% Complete</small>`
            },
            {
                data: 'status',
                render: data => {
                    let colors = { 'active': 'primary', 'completed': 'success', 'draft': 'secondary', 'cancelled': 'danger' };
                    let color = colors[data] || 'info';
                    return `<span class="badge bg-${color}-subtle text-${color} text-uppercase px-2">${data}</span>`;
                }
            },
            { data: 'user_count', render: data => `<i class="bi bi-people"></i> ${data} assigned` },
            {
                data: null,
                className: 'text-end',
                render: (data, t, row) => {
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" onclick="viewWorkflow(${row.id})"><i class="bi bi-eye text-info"></i> View Details</a></li>
                            <li><a class="dropdown-item" href="#" onclick="editWorkflow(${row.id})"><i class="bi bi-pencil text-warning"></i> Edit Settings</a></li>`;
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteWorkflow(${row.id})"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ]
    });
}

function loadMyTasks(status) {
    if (!status) status = $('#taskTabs .nav-link.active').data('status') || 'pending';
    
    $('#taskTabs .nav-link').removeClass('active');
    $(`#taskTabs .nav-link[data-status="${status}"]`).addClass('active');
    
    const showAll = $('#toggleAllTasks').is(':checked') ? 1 : 0;
    
    $.get('/ajax/get_my_tasks.php', { status: status, all: showAll }, function(data) {
        let html = '';
        if (!data || data.length === 0) {
            html = `<div class="p-5 text-center text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-3 opacity-25"></i>
                        <h6 class="fw-bold">No ${status} tasks found</h6>
                        <p class="small">${showAll ? 'The queue is completely empty.' : 'Try switching the "All" toggle to see team tasks or verify assignments.'}</p>
                    </div>`;
        } else {
            data.forEach(task => {
                const isOverdue = task.due_date && new Date(task.due_date) < new Date() && task.status !== 'completed';
                const statusBadge = task.status === 'completed' ? 'success' : (isOverdue ? 'danger' : 'primary');
                
                html += `
                <div class="p-3 border-bottom task-item position-relative" onclick="viewTask(${task.id})">
                    ${isOverdue ? '<span class="position-absolute top-0 end-0 mt-2 me-2 badge bg-danger-subtle text-danger small">Overdue</span>' : ''}
                    <div class="d-flex justify-content-between mb-1">
                        <span class="badge bg-${statusBadge}-subtle text-${statusBadge} small fw-semibold">${task.step_name}</span>
                        <small class="text-muted"><i class="bi bi-calendar3"></i> ${task.due_date || 'No date'}</small>
                    </div>
                    <h6 class="mb-1 text-dark fw-bold">${escapeHtml(task.workflow_name)}</h6>
                    <p class="small text-muted mb-2 line-clamp-2">${escapeHtml(task.description || 'No description provided')}</p>
                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top border-light">
                        <div class="small">
                            <span class="text-muted"><i class="bi bi-person"></i> ${showAll ? 'To: ' + escapeHtml(task.assignee_name) : 'From: ' + escapeHtml(task.assigner_name || 'System')}</span>
                        </div>
                        <button class="btn btn-link btn-sm p-0 text-decoration-none">Details <i class="bi bi-chevron-right small"></i></button>
                    </div>
                </div>`;
            });
        }
        $('#myTasksContainer').html(html);
    });
}

function viewTask(taskId) {
    const modal = new bootstrap.Modal(document.getElementById('taskDetailsModal'));
    modal.show();
    
    $('#taskDetailsBody').html('<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>');
    $('#btnCompleteTask').hide();
    
    $.get('/ajax/get_task_details.php', { id: taskId }, function(task) {
        if (task.error) {
            $('#taskDetailsBody').html(`<div class="alert alert-danger">${task.error}</div>`);
            return;
        }
        
        const isOverdue = task.due_date && new Date(task.due_date) < new Date() && task.status !== 'completed';
        
        let html = `
            <div class="mb-4">
                <label class="small text-muted text-uppercase fw-bold">Workflow</label>
                <div class="h5 fw-bold text-dark">${escapeHtml(task.workflow_name)}</div>
            </div>
            <div class="row mb-4">
                <div class="col-6">
                    <label class="small text-muted text-uppercase fw-bold">Step Name</label>
                    <div class="fw-bold">${escapeHtml(task.step_name)}</div>
                </div>
                <div class="col-6 text-end">
                    <label class="small text-muted text-uppercase fw-bold">Priority</label>
                    <div><span class="badge bg-${task.priority === 'high' ? 'danger' : 'primary'}-subtle text-${task.priority === 'high' ? 'danger' : 'primary'} text-uppercase">${task.priority}</span></div>
                </div>
            </div>
            <div class="mb-4 p-3 bg-light rounded">
                <label class="small text-muted text-uppercase fw-bold">Instruction / Description</label>
                <p class="mb-0 text-dark">${escapeHtml(task.description || 'No detailed instructions.')}</p>
            </div>
            ${task.status === 'completed' ? `
            <div class="mb-4 p-3 border border-success rounded bg-success-subtle">
                <label class="small text-success text-uppercase fw-bold">Completion Comments</label>
                <p class="mb-0 text-dark italic">${escapeHtml(task.comments || 'No comments left.')}</p>
            </div>` : `
            <div class="mb-0">
                <label class="form-label small text-muted text-uppercase fw-bold">Add Completion Comments</label>
                <textarea class="form-control" id="taskCompletionComments" rows="3" placeholder="Enter any notes or findings..."></textarea>
            </div>`}
            <div class="row g-3 mt-2">
                <div class="col-6">
                    <label class="small text-muted text-uppercase fw-bold">Assigned By</label>
                    <div class="small"><i class="bi bi-person"></i> ${escapeHtml(task.assigner_name)}</div>
                </div>
                <div class="col-6 text-end">
                    <label class="small text-muted text-uppercase fw-bold">Due Date</label>
                    <div class="small ${isOverdue ? 'text-danger fw-bold' : ''}"><i class="bi bi-calendar-event"></i> ${task.due_date || 'None'}</div>
                </div>
            </div>`;
            
        $('#taskDetailsBody').html(html);
        
        if (task.status !== 'completed') {
            $('#btnSaveTaskNote').show().off('click').on('click', function() {
                const comments = $('#taskCompletionComments').val();
                updateTask(taskId, 'in_progress', comments);
            });
            $('#btnCompleteTask').show().off('click').on('click', function() {
                const comments = $('#taskCompletionComments').val();
                updateTask(taskId, 'completed', comments);
            });
        } else {
            $('#btnSaveTaskNote').hide();
            $('#btnCompleteTask').hide();
        }
    });
}

function updateTask(taskId, status, comments = '') {
    const isComp = status === 'completed';
    
    if (isComp) {
        Swal.fire({
            title: 'Complete Task?',
            text: "Are you sure you want to mark this task as completed?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, complete it!'
        }).then((result) => {
            if (result.isConfirmed) {
                performUpdateTask(taskId, status, comments);
            }
        });
    } else {
        performUpdateTask(taskId, status, comments);
    }
}

function performUpdateTask(taskId, status, comments) {
    const isComp = status === 'completed';
    const btn = isComp ? $('#btnCompleteTask') : $('#btnSaveTaskNote');
    const oldHtml = btn.html();
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Processing...');
    
    $.post(`${APP_URL}/ajax/update_task_status.php`, { id: taskId, status: status, comments: comments }, function(res) {
        if (res.success) {
            if (isComp) {
                bootstrap.Modal.getInstance(document.getElementById('taskDetailsModal')).hide();
                loadMyTasks($('.nav-link.active[data-status]').data('status'));
                location.reload(); 
            } else {
                btn.prop('disabled', false).html(oldHtml);
                Swal.fire({ title: 'Saved', text: 'Task notes updated.', icon: 'success', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
            }
        } else {
            Swal.fire('Error!', res.message, 'error');
            btn.prop('disabled', false).html(oldHtml);
        }
    }, 'json');
}

function escapeHtml(text) { return text ? $('<div>').text(text).html() : ''; }

// Handle Forms
$('#createWorkflowForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btnSaveWorkflow');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
    
    $.post(`${APP_URL}/ajax/save_workflow.php`, $(this).serialize(), function(res) {
        if (res.success) {
            $('#createWorkflowModal').modal('hide');
            $('#createWorkflowForm')[0].reset();
            location.reload(); 
        } else {
            Swal.fire('Error!', res.message, 'error');
            btn.prop('disabled', false).html('Create Workflow');
        }
    }, 'json');
});

$('#assignDocForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btnAssignDoc');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Linking...');
    
    $.post(`${APP_URL}/assign_workflow_document.php`, $(this).serialize(), function(res) {
        if (res.success) {
            $('#assignDocModal').modal('hide');
            $('#assignDocForm')[0].reset();
            $('#workflowsTable').DataTable().ajax.reload();
            Swal.fire('Success', 'Documents linked successfully!', 'success');
        } else {
            Swal.fire('Error!', res.message, 'error');
        }
        btn.prop('disabled', false).html('Link Documents');
    }, 'json');
});

$('#editWorkflowForm').on('submit', function(e) {
    e.preventDefault();
    const btn = $('#btnUpdateWorkflow');
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Updating...');
    
    $.post(`${APP_URL}/ajax/update_workflow.php`, $(this).serialize(), function(res) {
        if (res.success) {
            $('#editWorkflowModal').modal('hide');
            $('#workflowsTable').DataTable().ajax.reload();
            Swal.fire('Updated', 'Workflow settings updated!', 'success');
        } else {
            Swal.fire('Error!', res.message, 'error');
        }
        btn.prop('disabled', false).html('Update Changes');
    }, 'json');
});

function editWorkflow(id) {
    $.get(`${APP_URL}/ajax/get_workflow_details.php`, { id: id }, function(res) {
        if (res.success) {
            const w = res.workflow;
            $('#editWorkflowId').val(w.id);
            $('#editWorkflowName').val(w.name);
            $('#editWorkflowDesc').val(w.description);
            $('#editWorkflowCat').val(w.category);
            $('#editWorkflowPri').val(w.priority);
            $('#editWorkflowStatus').val(w.status);
            $('#editWorkflowModal').modal('show');
        } else {
            Swal.fire('Error!', 'Error loading details', 'error');
        }
    }, 'json');
}

function viewWorkflow(id) {
    $('#workflowDetailsModal').modal('show');
    $('#workflowDetailsBody').html('<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>');
    
    $.get('/ajax/get_workflow_details.php', { id: id }, function(res) {
        if (!res.success) {
            $('#workflowDetailsBody').html(`<div class="alert alert-danger">${res.message}</div>`);
            return;
        }
        
        const w = res.workflow;
        let html = `
            <div class="row mb-4">
                <div class="col-md-8">
                    <h3 class="fw-bold mb-1">${escapeHtml(w.name)}</h3>
                    <p class="text-muted mb-0">${escapeHtml(w.description || 'No description')}</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="badge bg-primary px-3 py-2 text-uppercase mb-2">${w.category}</div>
                    <div class="d-block small text-muted">Created by Admin on ${new Date(w.created_at).toLocaleDateString()}</div>
                </div>
            </div>
            
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card bg-light border-0">
                        <div class="card-body">
                            <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="bi bi-files"></i> Linked Documents</h6>
                            <div class="list-group list-group-flush bg-transparent">
                                ${res.documents.length ? res.documents.map(d => `
                                    <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center px-0">
                                        <div><i class="bi bi-file-earmark-text text-primary"></i> ${escapeHtml(d.document_name)}</div>
                                        <a href="document_library.php?action=download&document_id=${d.document_id}" class="btn btn-link btn-sm p-0"><i class="bi bi-download"></i></a>
                                    </div>
                                `).join('') : '<p class="small text-muted mb-0">No documents linked yet.</p>'}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card bg-light border-0">
                        <div class="card-body">
                            <h6 class="fw-bold border-bottom pb-2 mb-3"><i class="bi bi-collection-play"></i> Workflow Steps</h6>
                            <div class="workflow-steps-vertical shadow-none">
                                ${res.steps.map((s, idx) => `
                                    <div class="step-item mb-3 last-child-mb-0">
                                        <div class="d-flex align-items-center mb-1">
                                            <span class="badge ${s.status == 'completed' ? 'bg-success' : 'bg-warning'} rounded-circle p-1 me-2"><i class="bi bi-check"></i></span>
                                            <span class="fw-bold small">${escapeHtml(s.step_name)}</span>
                                        </div>
                                        <div class="ps-4 small text-muted">
                                            Assigned to: <strong>${escapeHtml(s.assigned_user || 'Unknown')}</strong><br>
                                            Due: ${s.due_date || 'N/A'} | Status: ${s.status.toUpperCase()}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        $('#workflowDetailsBody').html(html);
    }, 'json');
}

function deleteWorkflow(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This will delete the workflow and all its steps. Document files will not be deleted.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(`${APP_URL}/ajax/delete_workflow.php`, { id: id }, function(res) {
                if (res.success) {
                    Swal.fire('Deleted!', 'Workflow has been deleted.', 'success');
                    $('#workflowsTable').DataTable().ajax.reload();
                    location.reload(); 
                } else {
                    Swal.fire('Error!', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function loadWorkflows(status) {
    window.currentWorkflowStatus = status;
    
    // Update button UI
    $('.btn-group .btn').removeClass('active');
    if (status === 'active') {
        $('.btn-group .btn:contains("Active")').addClass('active');
        $('#workflowsCardHeading').text('Active Workflows');
    } else {
        $('.btn-group .btn:contains("Archive")').addClass('active');
        $('#workflowsCardHeading').text('Workflow Archive (Completed)');
    }
    
    $('#workflowsTable').DataTable().ajax.reload();
}
</script>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;  
    overflow: hidden;
}
.bg-light-primary { background-color: #e7f1ff !important; border-left: 4px solid #0d6efd !important; }
.bg-light-warning { background-color: #fff3cd !important; border-left: 4px solid #ffc107 !important; }
.bg-light-info { background-color: #cff4fc !important; border-left: 4px solid #0dcaf0 !important; }
.bg-light-success { background-color: #d1e7dd !important; border-left: 4px solid #198754 !important; }

.task-item { cursor: pointer; transition: background 0.2s; }
.task-item:hover { background-color: #f8f9fa; }
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    border-radius: 12px !important;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transition: all 0.2s ease-in-out;
}
.custom-stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 15px rgba(0,0,0,0.1); }

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
</style>

<?php
includeFooter();
ob_end_flush();
?>