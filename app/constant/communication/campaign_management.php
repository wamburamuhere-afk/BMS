<?php
require_once __DIR__ . '/../../../roots.php';
autoEnforcePermission('campaign_management');
includeHeader();

$types = ['Email', 'SMS', 'Social Media', 'Direct Call', 'Other'];
$statuses = ['Planned', 'Active', 'Completed', 'Cancelled', 'Paused'];
?>

<div class="container-fluid mt-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-megaphone"></i> Campaign Management</h2>
                    <p class="text-muted mb-0">Track and optimize your marketing outreach activities</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createCampaignModal">
                        <i class="bi bi-plus-lg"></i> Create Campaign
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Total Campaigns</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-layers" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-active">0</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Active Now</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-activity" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-budget">0.00</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Total Budget</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-cash-coin" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card custom-stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-spent">0.00</h4>
                            <p class="mb-0 small uppercase font-weight-bold">Total Spent</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-pie-chart" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main List -->
        <div class="col-lg-9">
            <div class="card shadow-sm mb-4">
                <div class="card-header custom-table-header bg-light d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0"><i class="bi bi-list-task"></i> Campaign List</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="campaignTable" class="table table-hover align-middle" style="width:100%">
                            <thead class="bg-light text-muted small uppercase">
                                <tr>
                                    <th>S/NO</th>
                                    <th>Campaign Name</th>
                                    <th>Type</th>
                                    <th>Budget</th>
                                    <th>Spent</th>
                                    <th>Timeline</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                <!-- AJAX Loaded -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Filters -->
        <div class="col-lg-3">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light py-3">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-funnel"></i> Filters</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small text-muted">Channel Type</label>
                        <select class="form-select form-select-sm" id="filter_type">
                            <option value="">All Types</option>
                            <?php foreach ($types as $type): ?>
                                <option value="<?= $type ?>"><?= $type ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small text-muted">Status</label>
                        <select class="form-select form-select-sm" id="filter_status">
                            <option value="">All Status</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= $status ?>"><?= $status ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-grid">
                        <button type="button" class="btn btn-primary btn-sm" onclick="reloadTable()">Apply</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Campaign Modal -->
<div class="modal fade" id="createCampaignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Campaign</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="campaignForm">
                <input type="hidden" name="campaign_id" id="campaign_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Campaign Name</label>
                            <input type="text" name="campaign_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <?php foreach ($types as $type): ?>
                                    <option value="<?= $type ?>"><?= $type ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Target Audience</label>
                            <textarea name="target_audience" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Budget</label>
                            <input type="number" name="budget" class="form-control" step="0.01" value="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= $status ?>"><?= $status ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Campaign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
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
.custom-table-header { border-bottom: 2px solid #e9ecef; }
</style>

<script>
$(document).ready(function() {
    logReportAction('Viewed Campaign Management', 'User viewed the marketing campaigns list');
    const table = $('#campaignTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '/api/get_campaigns.php',
            data: d => {
                d.type = $('#filter_type').val();
                d.status = $('#filter_status').val();
            },
            dataSrc: json => {
                if (json.stats) {
                    $('#stat-total').text(json.stats.total);
                    $('#stat-active').text(json.stats.active);
                    $('#stat-budget').text(parseFloat(json.stats.budget).toLocaleString(undefined, {minimumFractionDigits: 2}));
                    $('#stat-spent').text(parseFloat(json.stats.spent).toLocaleString(undefined, {minimumFractionDigits: 2}));
                }
                return json.data;
            }
        },
        columns: [
            { 
                data: null, 
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1 
            },
            { 
                data: 'campaign_name',
                render: (data, t, row) => `<strong>${data}</strong><br><small class="text-muted">${row.target_audience || 'Broad Target'}</small>`
            },
            {
                data: 'type',
                render: data => {
                    const icons = { Email: 'envelope', SMS: 'chat', 'Social Media': 'share', 'Direct Call': 'telephone' };
                    return `<i class="bi bi-${icons[data] || 'megaphone'}"></i> ${data}`;
                }
            },
            { data: 'budget', render: data => parseFloat(data).toLocaleString(undefined, {minimumFractionDigits: 2}) },
            { 
                data: 'spent', 
                render: (data, t, row) => {
                    const percent = row.budget > 0 ? (data / row.budget * 100) : 0;
                    let color = 'primary';
                    if (percent > 90) color = 'danger';
                    else if (percent > 70) color = 'warning';
                    return `<div>${parseFloat(data).toLocaleString(undefined, {minimumFractionDigits: 2})}</div>
                            <div class="progress" style="height: 4px;">
                                <div class="progress-bar bg-${color}" style="width: ${percent}%"></div>
                            </div>`;
                }
            },
            {
                data: 'start_date',
                render: (data, t, row) => `<small>${data} to<br>${row.end_date || 'Ongoing'}</small>`
            },
            {
                data: 'status',
                render: data => {
                    let color = 'secondary';
                    if (data === 'Active') color = 'success';
                    if (data === 'Completed') color = 'info';
                    if (data === 'Cancelled') color = 'danger';
                    return `<span class="badge bg-${color}-subtle text-${color} border border-${color}-subtle px-3">${data}</span>`;
                }
            },
            {
                data: null,
                className: 'text-end',
                render: (data, type, row) => `
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light border dropdown-toggle d-flex align-items-center" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="editCampaign(${row.campaign_id})"><i class="bi bi-pencil me-2"></i> Edit Campaign</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="viewCampaign(${row.campaign_id})"><i class="bi bi-search me-2"></i> View Details</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteCampaign(${row.campaign_id})"><i class="bi bi-trash me-2"></i> Delete</a></li>
                        </ul>
                    </div>`
            }
        ]
    });
});
function reloadTable() { 
    logReportAction('Filtered Campaigns', 'User applied search filters: Type=' + ($('#filter_type').val() || 'All') + ', Status=' + ($('#filter_status').val() || 'All'));
    $('#campaignTable').DataTable().ajax.reload(); 
}

function editCampaign(id) {
    const table = $('#campaignTable').DataTable();
    const rowData = table.rows().data().toArray().find(r => r.campaign_id == id);
    
    if (!rowData) return;

    // Reset form first
    $('#campaignForm')[0].reset();
    
    // Fill in data
    $('#campaign_id').val(rowData.campaign_id);
    $('[name="campaign_name"]').val(rowData.campaign_name);
    $('[name="type"]').val(rowData.type);
    $('[name="target_audience"]').val(rowData.target_audience);
    $('[name="start_date"]').val(rowData.start_date);
    $('[name="end_date"]').val(rowData.end_date);
    $('[name="budget"]').val(rowData.budget);
    $('[name="status"]').val(rowData.status);
    
    // Update labels
    $('#createCampaignModal .modal-title').text('Edit Campaign');
    $('#createCampaignModal button[type="submit"]').text('Update Campaign');
    
    // Show modal
    const modalEl = document.getElementById('createCampaignModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    
    logReportAction('Initiated Campaign Edit', 'User clicked edit for campaign ID: ' + id + ' (' + rowData.campaign_name + ')');
    modal.show();
}

function viewCampaign(id) {
    const table = $('#campaignTable').DataTable();
    const row = table.rows().data().toArray().find(r => r.campaign_id == id);
    if (row) {
        logReportAction('Viewed Campaign Detail', 'User viewed details for campaign: ' + row.campaign_name + ' (ID: ' + id + ')');
        Swal.fire({
            title: row.campaign_name,
            html: `
                <div class="text-start">
                    <p><strong>Target:</strong> ${row.target_audience}</p>
                    <p><strong>Budget:</strong> ${row.budget}</p>
                    <p><strong>Spent:</strong> ${row.spent}</p>
                    <p><strong>Status:</strong> ${row.status}</p>
                </div>
            `,
            icon: 'info',
            confirmButtonText: 'OK',
            confirmButtonColor: '#3085d6'
        });
    }
}

function deleteCampaign(id) {
    Swal.fire({
        title: 'Are you sure?',
        text: "You want to delete this campaign?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/delete_campaign.php', { campaign_id: id }, function(res) {
                if (res.success) {
                    logReportAction('Deleted Campaign', 'User successfully deleted campaign ID: ' + id);
                    reloadTable();
                    Swal.fire({
                        title: 'Imefutwa!',
                        text: res.message,
                        icon: 'success',
                        timer: 3000,
                        showConfirmButton: true,
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#3085d6'
                    });
                } else {
                    Swal.fire({
                        title: 'Hitilafu!',
                        text: res.message,
                        icon: 'error',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#3085d6'
                    });
                }
            });
        }
    });
}

// Reset modal when closed
$('#createCampaignModal').on('hidden.bs.modal', function () {
    $('#campaignForm')[0].reset();
    $('#campaign_id').val('');
    $('#createCampaignModal .modal-title').text('Create New Campaign');
    $('#createCampaignModal button[type="submit"]').text('Create Campaign');
});

$('#campaignForm').on('submit', function(e) {
    e.preventDefault();
    const submitBtn = $(this).find('button[type="submit"]');
    submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> Processing...');

    $.post('/api/save_campaign.php', $(this).serialize(), function(res) {
        submitBtn.prop('disabled', false).text($('#campaign_id').val() ? 'Update Campaign' : 'Create Campaign');
        
        if (res.success) {
            const isEdit = $('#campaign_id').val();
            logReportAction(isEdit ? 'Updated Campaign' : 'Created Campaign', 'User successfully ' + (isEdit ? 'updated' : 'created') + ' campaign: ' + $('[name="campaign_name"]').val());
            
            const modalEl = document.getElementById('createCampaignModal');
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            
            $('#campaignForm')[0].reset();
            reloadTable();
            Swal.fire({
                title: 'Mafanikio!',
                text: res.message,
                icon: 'success',
                timer: 3000,
                showConfirmButton: true,
                confirmButtonText: 'OK',
                confirmButtonColor: '#3085d6'
            });
        } else {
            Swal.fire({
                title: 'Hitilafu!',
                text: res.message,
                icon: 'error',
                confirmButtonText: 'OK',
                confirmButtonColor: '#3085d6'
            });
        }
    }).fail(function() {
        submitBtn.prop('disabled', false).text($('#campaign_id').val() ? 'Update Campaign' : 'Create Campaign');
        Swal.fire('Hitilafu!', 'Server connection failed. Please try again.', 'error');
    });
});
</script>

<?php include 'footer.php'; ?>
