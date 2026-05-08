<?php
// app/bms/operations/projects.php
ob_start();
global $pdo;
require_once __DIR__ . '/../../../roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('projects');

includeHeader();

if (get_setting('enable_projects') != '1') {
    echo "<script>window.location.href = '" . getUrl('dashboard') . "';</script>";
    exit;
}

$customers = $pdo->query("SELECT customer_id, customer_name, company_name FROM customers WHERE status = 'active' ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);
$company_name = get_setting('company_name') ?: 'Building Management System';
$company_logo = get_setting('company_logo');
?>

<style>
    @media print {
        @page {
            size: auto;
            margin: 0.5in 0.5in 0.8in 0.5in !important;
        }

        .container-fluid {
            padding-bottom: 3.5cm !important;
            margin: 0 !important;
            width: 100% !important;
        }

        .fixed-print-footer {
            position: fixed !important;
            bottom: 0 !important;
            left: 0;
            right: 0;
            height: 1.5cm; 
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: center;
            background: white !important;
            padding: 0;
            border-top: 1px solid #ddd !important; 
            font-size: 10px;
            z-index: 999999 !important;
            -webkit-print-color-adjust: exact;
            pointer-events: none;
        }

        table#projectsTable { 
            width: 100% !important; 
            max-width: 100% !important;
            table-layout: auto !important;
            border-collapse: collapse !important;
            font-size: 8pt !important; 
        }

        /* Hide Control (1st) and Actions (Last) columns in print */
        table#projectsTable th:nth-child(1), table#projectsTable td:nth-child(1),
        table#projectsTable th:nth-child(11), table#projectsTable td:nth-child(11) {
            display: none !important;
        }

        table#projectsTable th, table#projectsTable td {
            word-wrap: break-word !important;
            word-break: break-all !important;
            white-space: normal !important; 
            padding: 4px 2px !important;
            border: 1px solid #dee2e6 !important;
            text-align: left !important;
        }

        /* Ensure Status column (nth-child 10 now since 1 is hidden?) No, based on original index */
        table#projectsTable th:nth-child(10), table#projectsTable td:nth-child(10) {
            white-space: nowrap !important;
        }

        .d-print-none, .btn, .card-header, .stat-card, .filter-bar, hr, .modal, .modal-backdrop, .row.mb-3.mb-md-4, .card.shadow-sm.border-0.mb-4.bg-white.d-print-none, .d-flex.justify-content-between.align-items-center.mb-4.d-print-none { 
            display: none !important; 
        }
        
        .card, .card-body {
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
            box-shadow: none !important;
        }

        .dataTables_info, .dataTables_paginate, .dataTables_filter, .dataTables_length { 
            display: none !important; 
        }
    }
</style>

<div class="fixed-print-footer d-none d-print-block">
    <p class="mb-1">This document was <strong>Printed</strong> by <span class="text-dark fw-bold"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= $_SESSION['username'] ?? 'Staff' ?></span> on <span class="text-dark fw-bold"><?= date('d M, Y \a\t H:i:s') ?></span></p>
    <p class="mb-0 fw-bold text-primary">Powered By BJP Technologies @ 2026, All Rights Reserved</p>
</div>

<!-- Print Only Header -->
<div class="header-section text-center mb-4 d-none d-print-block">
    <?php if(!empty($company_logo)): ?>
        <div class="mb-2">
            <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
        </div>
    <?php endif; ?>
    <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h1>
    <h3 class="fw-bold mb-1" style="color: #000 !important; text-transform: uppercase;">PROJECT MANAGEMENT LIST REPORT</h3>
    <div class="mx-auto bg-primary mb-3" style="width: 60px; height: 3px; border-radius: 2px;"></div>
    <div class="small fw-bold">Generated on: <?= date('M d, Y') ?></div>
</div>


<div class="container-fluid mt-4">
    <div class="row mb-3 mb-md-4 px-2 px-md-0">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-start flex-nowrap gap-2 d-print-none">
                <div>
                    <h2 class="mb-0 fs-4 fs-md-2 fw-bold text-primary"><i class="bi bi-briefcase"></i> Project Management</h2>
                    <p class="mb-0 text-muted d-none d-md-block small mt-1">Oversee business projects, timelines, and budgets</p>
                </div>
                <div class="ms-auto flex-shrink-0 pt-1 pt-md-2">
                    <button type="button" class="btn btn-primary btn-sm px-1 px-md-3 shadow-sm fw-bold" style="border-radius: 6px;" data-bs-toggle="modal" data-bs-target="#projectModal">
                        <i class="bi bi-plus-circle"></i> <span class="d-none d-sm-inline">New Project</span><span class="d-inline d-sm-none text-uppercase" style="font-size: 0.7rem;">New Project</span>
                    </button>
                </div>
            </div>
            <hr class="d-md-none mt-2 mb-0 opacity-25">
        </div>
    </div>

    <!-- Stats -->
    <div class="row mb-4 d-print-none g-2 g-md-3">
        <div class="col-6 col-md-3 mb-2 mb-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="overflow-hidden">
                            <h4 class="mb-0 fs-5 fs-md-4" id="stat-active">0</h4>
                            <p class="small mb-0 text-muted opacity-75">Active</p>
                        </div>
                        <div class="d-none d-sm-block">
                            <i class="bi bi-activity" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2 mb-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="overflow-hidden">
                            <h4 class="mb-0 fs-5 fs-md-4" id="stat-pending">0</h4>
                            <p class="small mb-0 text-muted opacity-75">Planning</p>
                        </div>
                        <div class="d-none d-sm-block">
                            <i class="bi bi-pause-circle" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2 mb-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="overflow-hidden">
                            <h4 class="mb-0 fs-6 fs-md-5 text-truncate" id="stat-budget" style="max-width: 100px;">0</h4>
                            <p class="small mb-0 text-muted opacity-75">Budget</p>
                        </div>
                        <div class="d-none d-sm-block">
                            <i class="bi bi-cash-stack" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2 mb-md-3">
            <div class="card custom-stat-card h-100 shadow-sm border-0">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="overflow-hidden">
                            <h4 class="mb-0 fs-5 fs-md-4" id="stat-avg-progress">0%</h4>
                            <p class="small mb-0 text-muted opacity-75">Progress</p>
                        </div>
                        <div class="d-none d-sm-block">
                            <i class="bi bi-graph-up-arrow" style="font-size: 1.5rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card shadow-sm border-0 mb-4 bg-white d-print-none" style="border-radius: 12px;">
        <div class="card-body p-2 p-md-3">
            <div class="row g-2 g-md-3 align-items-center">
                <div class="col-6 col-md-3">
                    <label for="statusFilter" class="form-label small fw-bold text-muted mb-1" style="font-size: 0.7rem;">Status Filter</label>
                    <select class="form-select bg-light border-0 form-select-sm" id="statusFilter" style="border-radius: 8px; height: 38px;">
                        <option value="">All Status</option>
                        <option value="planning">Planning</option>
                        <option value="active">Active</option>
                        <option value="on_hold">On Hold</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <label for="searchFilter" class="form-label small fw-bold text-muted mb-1" style="font-size: 0.7rem;">Quick Search</label>
                    <div class="input-group shadow-sm" style="border-radius: 8px; overflow: hidden; border: 1px solid #eee;">
                        <span class="input-group-text bg-white border-0 px-2"><i class="bi bi-search text-muted"></i></span>
                        <input type="text" id="searchFilter" class="form-control border-0 p-2 form-control-sm" style="height: 38px;" placeholder="Search...">
                    </div>
                </div>
                <div class="col-12 col-md-5 d-flex align-items-end gap-2 mt-2 mt-md-0">
                    <button type="button" class="btn btn-primary btn-sm flex-fill shadow-sm" onclick="refreshTable()" style="border-radius: 8px; height: 38px;">
                        <i class="bi bi-funnel"></i> Apply Filter
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" onclick="clearFilters()" style="border-radius: 8px; height: 38px;">
                        <i class="bi bi-arrow-clockwise"></i> Reset
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div class="d-flex align-items-center gap-3">
            <div class="btn-group shadow-sm" style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="printProjects()" style="background: #fff; color: #444;">
                    <i class="bi bi-printer text-primary me-1"></i> Print
                </button>
                <div style="width: 1px; background: #eee; height: 24px; margin-top: 6px;"></div>
                <button type="button" class="btn btn-white fw-medium px-3 border-0" onclick="exportProjects()" style="background: #fff; color: #444;">
                    <i class="bi bi-file-earmark-excel text-success me-1"></i> Excel
                </button>
            </div>
            
            <div class="d-flex align-items-center bg-white shadow-sm px-3 py-1" style="border: 1px solid #dee2e6; border-radius: 8px;">
                <label for="pageLengthSelect" class="small text-muted me-2"><i class="bi bi-list-ol"></i> Show:</label>
                <select id="pageLengthSelect" class="form-select form-select-sm border-0 fw-bold p-0" style="width: 60px; box-shadow: none; background: transparent;" onchange="$('#projectsTable').DataTable().page.len(this.value).draw();">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card shadow-sm border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
        <div class="card-body p-0 p-md-3">
            <div>
                <table id="projectsTable" class="table table-hover align-middle mb-0" style="width:100% !important;">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th style="width:20px;"></th> <!-- Control Column -->
                            <th style="width:50px;">S/NO</th>
                            <th class="ps-4">Project Name</th>
                            <th>Timeline</th>
                            <th>Revenue</th>
                            <th>Expense</th>
                            <th>Budget</th>
                            <th>Profit</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Project Modal -->
<div class="modal fade" id="projectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white p-4">
                <h5 class="modal-title" id="projectModalLabel"><i class="bi bi-briefcase me-2"></i>New Project</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="projectForm">
                <input type="hidden" name="project_id" id="project_id">
                <div class="modal-body p-4">
                    <!-- Section 1: General Info -->
                    <div class="mb-4">
                        <h6 class="text-primary fw-bold mb-3 d-flex align-items-center">
                            <i class="bi bi-info-circle me-2"></i> General Information
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="project_name" class="form-label fw-bold small">Project Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="project_name" id="project_name" required placeholder="Enter project name">
                            </div>
                            <div class="col-md-6">
                                <label for="customerSelect" class="form-label fw-bold small">Client/Employer <span class="text-danger">*</span></label>
                                <select class="form-select" name="customer_id" id="customerSelect" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= $c['customer_id'] ?>" data-name="<?= htmlspecialchars($c['customer_name'] . ($c['company_name'] ? ' (' . $c['company_name'] . ')' : '')) ?>">
                                            <?= htmlspecialchars($c['customer_name'] . ($c['company_name'] ? ' (' . $c['company_name'] . ')' : '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="client_name" id="client_name_hidden">
                            </div>
                            <div class="col-md-6">
                                <label for="disciplineSelect" class="form-label fw-bold small">Discipline <span class="text-danger">*</span></label>
                                <div class="modern-other-container" id="disciplineContainer">
                                    <select class="form-select" name="discipline" id="disciplineSelect" onchange="handleModernOther(this)" required>
                                        <option value="">Select Discipline</option>
                                        <option value="Electrical works">Electrical works</option>
                                        <option value="Civil Works">Civil Works</option>
                                        <option value="Building Work">Building Work</option>
                                        <option value="mechanical works">mechanical works</option>
                                        <option value="Telecommunication">Telecommunication</option>
                                        <option value="Renewable Energy works">Renewable Energy works</option>
                                        <option value="Other">Other (Specify...)</option>
                                    </select>
                                    <div class="modern-input-wrapper mt-2" style="display: none;">
                                        <div class="input-group">
                                            <label for="discipline_other" class="visually-hidden">Specify Other Discipline</label>
                                            <input type="text" class="form-control" name="discipline_other" id="discipline_other" placeholder="Type discipline...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="cancelModernOther(this)"><i class="bi bi-x"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="positionSelect" class="form-label fw-bold small">Position <span class="text-danger">*</span></label>
                                <div class="modern-other-container" id="positionContainer">
                                    <select class="form-select" name="role_position" id="positionSelect" onchange="handleModernOther(this)" required>
                                        <option value="">Select Position</option>
                                        <option value="Main Contractor">Main Contractor</option>
                                        <option value="Sub Contractor">Sub Contractor</option>
                                        <option value="Supplier">Supplier</option>
                                        <option value="Other">Other (Specify...)</option>
                                    </select>
                                    <div class="modern-input-wrapper mt-2" style="display: none;">
                                        <div class="input-group">
                                            <label for="role_position_other" class="visually-hidden">Specify Other Position</label>
                                            <input type="text" class="form-control" name="role_position_other" id="role_position_other" placeholder="Type position...">
                                            <button class="btn btn-outline-secondary" type="button" onclick="cancelModernOther(this)"><i class="bi bi-x"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Contract Details -->
                    <div class="mb-4">
                        <h6 class="text-primary fw-bold mb-3 d-flex align-items-center">
                            <i class="bi bi-file-earmark-text me-2"></i> Contract Details
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="contract_number" class="form-label fw-bold small">Contract Number</label>
                                <input type="text" class="form-control" name="contract_number" id="contract_number" placeholder="Enter contract number">
                            </div>
                            <div class="col-md-6">
                                <label for="contract_sum" class="form-label fw-bold small">Contract Sum</label>
                                <input type="number" step="0.01" class="form-control" name="contract_sum" id="contract_sum" placeholder="0.00">
                            </div>
                            <div class="col-md-6">
                                <label for="prioritySelect" class="form-label fw-bold small">Priority</label>
                                <select class="form-select" name="priority" id="prioritySelect">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Schedule & Management -->
                    <div class="mb-4">
                        <h6 class="text-primary fw-bold mb-3 d-flex align-items-center">
                            <i class="bi bi-calendar-event me-2"></i> Schedule & Management
                        </h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="project_manager" class="form-label fw-bold small">Project Manager</label>
                                <input type="text" class="form-control" name="project_manager" id="project_manager" placeholder="Assign manager">
                            </div>
                            <div class="col-md-6" id="project_status_container">
                                <label for="statusSelect" class="form-label fw-bold small">Status</label>
                                <select class="form-select" name="status" id="statusSelect">
                                    <option value="draft">Draft</option>
                                    <option value="planning">Planning</option>
                                    <option value="active">Active</option>
                                    <option value="on_hold">On Hold</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="project_start_date" class="form-label fw-bold small">Start Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date" id="project_start_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="project_days_total" class="form-label fw-bold small">Duration <span class="text-muted">(Total Days)</span> <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="duration_days" id="project_days_total" placeholder="Enter number of days" required>
                                <input type="hidden" name="deadline" id="project_deadline">
                            </div>
                            <div class="col-12">
                                <label for="description" class="form-label fw-bold small">Description</label>
                                <textarea class="form-control" name="description" id="description" rows="3" placeholder="Project details..."></textarea>
                            </div>
                            <div class="col-12 mt-3 p-3 bg-light border-dashed rounded-3">
                                <label for="contractFile" class="form-label fw-bold small"><i class="bi bi-cloud-arrow-up me-1"></i> Contract Attachment <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" name="contract_file" id="contractFile" accept=".pdf,.doc,.docx,.jpg,.png" required>
                                <small class="text-muted" style="font-size:0.7rem">Max 5MB (PDF, Microsoft Word, or Images)</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Project</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light p-4">
                <h5 class="modal-title fw-bold">Project Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewDetailsContent"></div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    .priority-badge { font-size: 0.7rem; font-weight: 700; padding: 0.35rem 0.6rem; border-radius: 50rem; }
    .status-badge { font-size: 0.75rem; font-weight: 600; padding: 0.3rem 0.6rem; border-radius: 50rem; }
    .border-dashed { border: 2px dashed #dee2e6 !important; }
    .bg-primary-soft { background-color: rgba(13, 110, 253, 0.05) !important; }
    
    .card.custom-stat-card {
        background-color: #d1e7dd !important;
        border-color: #badbcc !important;
        transition: transform 0.2s;
        border-radius: 12px;
        border: 1px solid #badbcc !important;
    }
    .card.custom-stat-card:hover { transform: translateY(-3px); }
    .card.custom-stat-card h4, 
    .card.custom-stat-card p, 
    .card.custom-stat-card i {
        color: #0f5132 !important;
        font-weight: 600;
    }
    .btn-white:hover {
        background-color: #f8f9fa !important;
    }
</style>

<script>
$(document).ready(function() {
    logReportAction('Viewed Projects List', 'User viewed the projects management list');
    
    const table = $('#projectsTable').DataTable({
        responsive: {
            details: {
                type: 'column',
                target: 0
            }
        },
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= buildUrl('api/operations/get_projects.php') ?>',
            data: function(d) {
                d.status = $('#statusFilter').val();
                d.search_term = $('#searchFilter').val();
            },
            dataSrc: function(json) {
                $('#stat-active').text(json.stats.active || 0);
                $('#stat-pending').text((parseInt(json.stats.planning)||0) + (parseInt(json.stats.on_hold)||0));
                $('#stat-budget').text(parseFloat(json.stats.total_budget || 0).toLocaleString() + ' TZS');
                $('#stat-avg-progress').text(parseFloat(json.stats.avg_progress || 0).toFixed(2) + '%');
                return json.data;
            }
        },
        columns: [
            {
                className: 'dtr-control text-center',
                orderable: false,
                data: null,
                defaultContent: '',
                targets: 0
            },
            {
                data: null,
                orderable: false,
                searchable: false,
                width: '50px',
                className: 'text-center fw-bold text-dark',
                responsivePriority: 1,
                render: (data, type, row, meta) => meta.row + meta.settings._iDisplayStart + 1
            },
            { 
                data: 'project_name',
                responsivePriority: 1,
                render: (data, t, row) => `<strong>${data}</strong><br><small class="text-muted text-uppercase" style="font-size:0.65rem">Manager: ${row.project_manager || 'N/A'}</small>`
            },
            { 
                data: 'deadline',
                responsivePriority: 3,
                render: (data, t, row) => `
                    <div class="small">
                        <span class="text-muted">Start:</span> ${row.start_date}<br>
                        <span class="text-muted">End:</span> ${data || 'No Deadline'}
                    </div>
                `
            },
            { 
                data: 'total_revenue', 
                responsivePriority: 4,
                render: data => `<strong class="text-success">${parseFloat(data || 0).toLocaleString()} TZS</strong>` 
            },
            { 
                data: 'total_expense', 
                responsivePriority: 4,
                render: data => `<strong class="text-danger">${parseFloat(data || 0).toLocaleString()} TZS</strong>` 
            },
            { 
                data: 'budget', 
                responsivePriority: 4,
                render: data => `<strong class="text-primary">${parseFloat(data || 0).toLocaleString()} TZS</strong>` 
            },
            { 
                data: 'profit',
                responsivePriority: 4,
                render: function(data, type, row) {
                    const profit = parseFloat(data || 0);
                    const colorClass = profit >= 0 ? 'text-success' : 'text-danger';
                    const icon = profit >= 0 ? 'bi-arrow-up' : 'bi-arrow-down';
                    return `
                        <div>
                            <strong class="${colorClass}">
                                <i class="bi ${icon}"></i> ${profit.toLocaleString()} TZS
                            </strong><br>
                            <small class="text-muted">${row.profit_margin}% margin</small>
                        </div>
                    `;
                }
            },
            {
                data: 'progress_percent',
                responsivePriority: 10,
                render: function(data) {
                    let color = 'bg-danger';
                    if (data > 30) color = 'bg-warning';
                    if (data > 70) color = 'bg-success';
                    return `
                        <div style="width: 100px">
                            <div class="small mb-1 text-end">${parseFloat(data || 0).toFixed(2)}%</div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar ${color}" style="width: ${data}%"></div>
                            </div>
                        </div>
                    `;
                }
            },
            {
                data: 'status',
                responsivePriority: 1,
                render: function(data) {
                    let cls = 'bg-secondary text-white';
                    if (data === 'active') cls = 'bg-success text-white';
                    if (data === 'completed') cls = 'bg-primary text-white';
                    if (data === 'on_hold') cls = 'bg-warning text-dark';
                    return `<span class="status-badge ${cls} text-uppercase">${data.replace('_',' ')}</span>`;
                }
            },
            {
                data: null,
                orderable: false,
                className: 'text-end pe-4',
                responsivePriority: 100, // Hide in expansion on mobile
                render: function(data, type, row) {
                    return `
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                                <li><a class="dropdown-item" href="project_view?id=${row.project_id}" onclick="logReportAction('Viewed Project Details Link', 'User clicked to view details for project: ' + row.project_name)"><i class="bi bi-eye me-2 text-info"></i> View Detail</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="editProject(${row.project_id})"><i class="bi bi-pencil me-2 text-primary"></i> Edit Project</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="javascript:void(0)" onclick="deleteProject(${row.project_id})"><i class="bi bi-trash me-2"></i> Delete</a></li>
                            </ul>
                        </div>
                    `;
                }
            }
        ],
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        dom: '<"d-none" l>rt<"d-flex justify-content-between align-items-center p-4 border-top" ip>',
        initComplete: function() {
            $('#projectsTable_length').appendTo('#tableLengthContainer');
            $('.dataTables_length select').addClass('form-select form-select-sm').css('width', 'auto');
        },
        drawCallback: function() {
            this.api().responsive.recalc();
        }
    });

    $('#projectForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Saving...');
        
        const formData = new FormData(this);
        
        $.ajax({
            url: '<?= buildUrl('api/operations/save_project.php') ?>',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(res) {
                if (res.success) {
                    $('#projectModal').modal('hide');
                    table.ajax.reload();
                    showToast('success', res.message);
                } else {
                    showToast('error', res.message);
                }
            },
            error: function() {
                showToast('error', 'Server error. Please try again.');
            },
            complete: function() {
                $btn.prop('disabled', false).text('Save Project');
            }
        });
    });

    $('#customerSelect').on('change', function() {
        const name = $(this).find('option:selected').data('name') || '';
        $('#client_name_hidden').val(name);
    });

    window.handleModernOther = function(select) {
        if (select.value === 'Other') {
            const container = $(select).closest('.modern-other-container');
            $(select).hide();
            container.find('.modern-input-wrapper').show().find('input').focus().prop('required', true);
        }
    };

    window.cancelModernOther = function(btn) {
        const container = $(btn).closest('.modern-other-container');
        const select = container.find('select');
        const input = container.find('input');
        
        input.val('').prop('required', false);
        container.find('.modern-input-wrapper').hide();
        select.show().val('');
    };

    // Handle Enter key in modern other input to just stay there but signal completion visually (optional)
    $(document).on('keypress', '.modern-input-wrapper input', function(e) {
        if (e.which == 13) {
            e.preventDefault();
            $(this).blur();
        }
    });

    $('#projectModal').on('hidden.bs.modal', function() {
        $('#projectForm')[0].reset();
        $('#project_id').val('');
        $('.modern-input-wrapper').hide().find('input').val('').prop('required', false);
        $('.modern-other-container select').show().val('');
        $('#contractFile').prop('required', true);
        $('#projectModalLabel').html('<i class="bi bi-briefcase me-2"></i>New Project');
        
        // Hide status for new projects, default to draft
        $('#project_status_container').hide();
        $('#project_status_field').val('draft');
    });



    // Check for edit ID in URL
    const urlParams = new URLSearchParams(window.location.search);
    const editId = urlParams.get('edit_id');
    if (editId) {
        editProject(editId);
    }
});

function refreshTable() { $('#projectsTable').DataTable().ajax.reload(); }

function viewProject(id) {
    $.get('<?= buildUrl('api/operations/get_project.php') ?>', { id: id }, function(res) {
        if (res.success) {
            const d = res.data;
            const disc = d.discipline === 'Other' ? d.discipline_other : d.discipline;
            const pos = d.role_position === 'Other' ? d.role_position_other : d.role_position;

            // Priority: use stored duration_days
            let duration = parseInt(d.duration_days) || 0;
            let startDate = new Date(d.start_date);
            let endDate = 'N/A';

            if (duration > 0 && d.start_date) {
                let end = new Date(startDate);
                end.setDate(startDate.getDate() + duration);
                const yyyy = end.getFullYear();
                const mm = String(end.getMonth() + 1).padStart(2, '0');
                const dd = String(end.getDate()).padStart(2, '0');
                endDate = `${yyyy}-${mm}-${dd}`;
            }

            let attachmentLink = d.contract_attachment ? `<a href="<?= buildUrl('') ?>${d.contract_attachment}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> View Contract</a>` : '<span class="text-danger small">No attachment</span>';

            const html = `
                <div class="mb-4 text-center">
                    <h5 class="fw-bold mb-0 text-primary">${d.project_name}</h5>
                    <span class="badge bg-light text-dark border mt-1">${d.contract_number || 'No Contract #'}</span>
                </div>
                <div class="row g-3">
                    <div class="col-6"><small class="text-muted d-block">Client/Employer</small><strong>${d.client_name || 'N/A'}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Start Date</small><strong>${d.start_date}</strong></div>

                    <div class="col-6"><small class="text-muted d-block">Discipline</small><strong>${disc || 'N/A'}</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Position</small><strong>${pos || 'N/A'}</strong></div>

                    <div class="col-6"><small class="text-muted d-block">Duration</small><strong>${duration} Days</strong></div>
                    <div class="col-6"><small class="text-muted d-block">Expected End Date</small><strong>${endDate}</strong></div>

                    <div class="col-6"><small class="text-muted d-block">Contract</small>${attachmentLink}</div>
                    <div class="col-6"><small class="text-muted d-block">Priority</small><strong>${d.priority.toUpperCase()}</strong></div>

                    <div class="col-12"><small class="text-muted d-block">Manager</small><strong>${d.project_manager || 'N/A'}</strong></div>

                    <div class="col-12"><small class="text-muted d-block">Description</small><div class="bg-light p-3 rounded border mt-1">${d.description || 'No description provided.'}</div></div>
                </div>
            `;
            $('#viewDetailsContent').html(html);
            $('#viewModal').modal('show');
        }
    });
}

function editProject(id) {
    logReportAction('Initiated Project Edit', 'User clicked edit for project ID: ' + id);
    $.get('<?= buildUrl('api/operations/get_project.php') ?>', { id: id }, function(res) {
        if (res.success) {
            const d = res.data;
            $('#project_id').val(d.project_id);
            $('input[name="project_name"]').val(d.project_name);
            $('#customerSelect').val(d.customer_id || '').trigger('change');
            $('#client_name_hidden').val(d.client_name);
            
            if (d.discipline === 'Other') {
                const cont = $('#disciplineContainer');
                cont.find('select').hide().val('Other');
                cont.find('.modern-input-wrapper').show().find('input').val(d.discipline_other).prop('required', true);
            } else {
                const cont = $('#disciplineContainer');
                cont.find('select').show().val(d.discipline);
                cont.find('.modern-input-wrapper').hide().find('input').val('').prop('required', false);
            }
            
            if (d.role_position === 'Other') {
                const cont = $('#positionContainer');
                cont.find('select').hide().val('Other');
                cont.find('.modern-input-wrapper').show().find('input').val(d.role_position_other).prop('required', true);
            } else {
                const cont = $('#positionContainer');
                cont.find('select').show().val(d.role_position);
                cont.find('.modern-input-wrapper').hide().find('input').val('').prop('required', false);
            }

            $('input[name="project_manager"]').val(d.project_manager);
            $('input[name="contract_number"]').val(d.contract_number);
            $('input[name="contract_sum"]').val(d.contract_sum);
            $('select[name="priority"]').val(d.priority);
            $('input[name="start_date"]').val(d.start_date);
            // In manual edit, we still calculate the hidden deadline for safety
            $('#project_days_total').val(d.duration_days || '');
            
            // Trigger calculation
            if (d.start_date && d.duration_days) {
                const start = new Date(d.start_date);
                const days = parseInt(d.duration_days);
                const deadline = new Date(start);
                deadline.setDate(start.getDate() + days);
                const yyyy = deadline.getFullYear();
                const mm = String(deadline.getMonth() + 1).padStart(2, '0');
                const dd = String(deadline.getDate()).padStart(2, '0');
                $('#project_deadline').val(`${yyyy}-${mm}-${dd}`);
            }

            $('select[name="status"]').val(d.status);
            $('textarea[name="description"]').val(d.description);
            
            // Attachment not mandatory for edit unless replacing
            $('#contractFile').prop('required', false);
            
            $('#project_status_container').show();
            $('#projectModalLabel').text('Edit Project');
            $('#projectModal').modal('show');
        }
    });
}

function deleteProject(id) {
    Swal.fire({
        title: 'Delete Project?',
        text: 'Are you sure you want to delete this project? This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'No, keep it'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('<?= buildUrl('api/operations/delete_project.php') ?>', { project_id: id }, function(res) {
                if (res.success) { 
                    logReportAction('Deleted Project', 'User deleted project ID: ' + id);
                    refreshTable(); 
                    showToast('success', res.message); 
                } else {
                    showToast('error', res.message);
                }
            });
        }
    });
}

function printProjects() {
    logReportAction('Printed Projects List', 'User generated a printed list of projects via window.print');
    window.print();
}

function exportProjects() {
    logReportAction('Exported Projects List', 'User exported the projects list to Excel/CSV');
    const status = $('#statusFilter').val();
    const search = $('#searchFilter').val();
    window.location.href = `<?= buildUrl('api/operations/export_projects.php') ?>?status=${status}&search=${search}`;
}

function clearFilters() {
    $('#statusFilter').val('');
    $('#searchFilter').val('');
    refreshTable();
}

function showToast(type, msg) {
    if (type === 'success') {
        Swal.fire({
            icon: 'success',
            title: msg,
            confirmButtonText: 'OK',
            confirmButtonColor: '#0d6efd'
        });
    } else {
        Swal.fire({ 
            icon: type, 
            title: msg, 
            toast: true, 
            position: 'top-end', 
            showConfirmButton: false, 
            timer: 3000 
        });
    }
}

// Logic for calculating Deadline based on Days Total
$(document).ready(function() {
    function calculateDeadline() {
        const startDateVal = $('#project_start_date').val();
        const daysTotalVal = $('#project_days_total').val();
        
        if (startDateVal && daysTotalVal) {
            const startDate = new Date(startDateVal);
            const days = parseInt(daysTotalVal);
            if (!isNaN(days)) {
                const deadline = new Date(startDate);
                deadline.setDate(startDate.getDate() + days);
                
                const yyyy = deadline.getFullYear();
                const mm = String(deadline.getMonth() + 1).padStart(2, '0');
                const dd = String(deadline.getDate()).padStart(2, '0');
                
                $('#project_deadline').val(`${yyyy}-${mm}-${dd}`);
            }
        }
    }

    $('#project_days_total').on('input change', calculateDeadline);
    $('#project_start_date').on('change', calculateDeadline);
    
    // Clear Days Total when modal is hidden (reset)
    $('#projectModal').on('hidden.bs.modal', function () {
        $('#project_days_total').val('');
    });
});
</script>

<?php
includeFooter();
ob_end_flush();
?>
