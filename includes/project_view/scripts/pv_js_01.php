const projectId    = <?= $project_id ?>;
const scId         = <?= $sc_id ?>;
const scMode       = <?= $sc_mode ? 'true' : 'false' ?>;
const supplierMode = <?= $supplier_mode ? 'true' : 'false' ?>;
const viewSupplierId = <?= $view_supplier_id ?>;
const currentUserName = "<?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?>";
const currentUserRole = "<?= ucwords($_SESSION['user_role'] ?? 'Staff') ?>";
const companyName = "<?= htmlspecialchars($company_name) ?>";
const companyLogo = "<?= htmlspecialchars($company_logo) ?>";
let projectData = null;

$(document).ready(function() {
    // Desktop always opens these tabs in table view; clear any stale card-view pref from localStorage
    if (window.innerWidth >= 768) {
        ['milestonesTable', 'reportingTable', 'performanceTable', 'projectDocsTable'].forEach(function(id) {
            try { localStorage.removeItem('bms_view_' + id); } catch(e) {}
        });
    }

    logReportAction('Viewed Project Details', 'User viewed full details for project ID: ' + projectId);
    if (projectId > 0) {
        if (window.BMSSkeleton) BMSSkeleton.render('#loading', { cards: 4, rows: 6 });
        loadProjectDetails();
        loadExpenseSchema();
        $('button[data-bs-target="#inventory"]').off('shown.bs.tab.load').on('shown.bs.tab.load', function () {
            loadProjectInventory();
        });
    } else {
        Swal.fire({
            icon: 'error',
            title: 'Project not found',
            text: 'Redirecting...',
            timer: 2000,
            showConfirmButton: false
        }).then(() => { window.location.href = '<?= getUrl("projects") ?>'; });
    }

    // Initialize Select2 for modals
    $('#addBudgetItemModal').on('shown.bs.modal', function () {
        $(this).find('.select2').select2({
            dropdownParent: $('#addBudgetItemModal'),
            theme: 'bootstrap-5'
        });
    });

    $('#addExpenseModal').on('shown.bs.modal', function () {
        $(this).find('.select2').select2({
            dropdownParent: $('#addExpenseModal'),
            theme: 'bootstrap-5'
        });
    });

    $('#expenseActionModal').on('shown.bs.modal', function () {
        $(this).find('.select2').select2({
            dropdownParent: $('#expenseActionModal'),
            theme: 'bootstrap-5'
        });
        if (!$('#edit_ex_paid_to_type').hasClass('select2-hidden-accessible')) {
            $('#edit_ex_paid_to_type').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#expenseActionModal'),
                minimumResultsForSearch: Infinity,
                width: '100%'
            });
        }
    });

    $('#payVoucherModal').on('shown.bs.modal', function () {
        if (!$('#pay_paid_from').hasClass('select2-hidden-accessible')) {
            $('#pay_paid_from').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#payVoucherModal'),
                placeholder: 'Select cash/bank account…',
                allowClear: true,
                width: '100%'
            });
        }
    });

    $('#editProjectModal').on('shown.bs.modal', function () {
        if (!$('#edit_customerSelect').hasClass('select2-hidden-accessible')) {
            $('#edit_customerSelect').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#editProjectModal'),
                placeholder: 'Select Customer',
                allowClear: true,
                width: '100%'
            });
        }
    });

    $('#applyLeaveModal').on('shown.bs.modal', function () {
        if (!$('#lv_type').hasClass('select2-hidden-accessible')) {
            $('#lv_type').select2({
                theme: 'bootstrap-5',
                dropdownParent: $('#applyLeaveModal'),
                placeholder: 'Select Type',
                allowClear: true,
                width: '100%'
            });
        }
    });
    
    // Edit Button Click
    $('#editProjectBtn').on('click', function() {
        if (projectData && projectData.data) {
            populateEditForm(projectData.data);
            $('#editProjectModal').modal('show');
        }
    });
    
    // Edit Form Submit
    $('#editProjectForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i> Saving...');
        
        const formData = new FormData(this);
        
        $.ajax({
            url: '/api/operations/save_project.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    $('#editProjectModal').modal('hide');
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: res.message || 'Project updated successfully',
                        confirmButtonColor: '#28a745',
                        confirmButtonText: 'OK'
                    }).then(() => {
                        loadProjectDetails();
                    });
                } else {
                    Swal.fire('Error', res.message || 'Failed to update project', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Server error occurred', 'error');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save Changes');
            }
        });
    });

    // Customer select sync
    $('#edit_customerSelect').on('change', function() {
        const name = $(this).find('option:selected').data('name') || '';
        $('#edit_client_name_hidden').val(name);
    });

    // Global shared functions for modern Other field
    window.handleModernOther = window.handleModernOther || function(select) {
        const selectedOption = $(select).find('option:selected');
        const name = selectedOption.data('name') || '';
        if (select.value === 'Other' || name === 'Other') {
            const container = $(select).closest('.modern-other-container');
            $(select).hide();
            container.find('.modern-input-wrapper').show().find('input').focus().prop('required', true);
        }
    };

    window.cancelModernOther = window.cancelModernOther || function(btn) {
        const container = $(btn).closest('.modern-other-container');
        const select = container.find('select');
        const input = container.find('input');
        input.val('').prop('required', false);
        container.find('.modern-input-wrapper').hide();
        select.show().val('');
    };

    $(document).on('keypress', '.modern-input-wrapper input', function(e) {
        if (e.which == 13) { e.preventDefault(); $(this).blur(); }
    });

    // Custom Dropdown Tab Handling
    $('#projectWorkspaceTabs .dropdown-item').on('click', function() {
        const parentDropdown = $(this).closest('.dropdown');
        const dropdownToggle = parentDropdown.find('.dropdown-toggle');

        // Remove active from any other main nav-links
        $('#projectWorkspaceTabs .nav-link').not(dropdownToggle).removeClass('active');
        
        // Add active to the parent dropdown toggle
        dropdownToggle.addClass('active');
    });

    // Auto-fill Document Title from filename
    $('#doc_upload_file').on('change', function() {
        const fileName = $(this).val().split('\\').pop();
        const $nameInput = $('#doc_upload_name');
        if (fileName && !$nameInput.val()) {
            // Remove extension for the title
            const nameWithoutExt = fileName.substring(0, fileName.lastIndexOf('.')) || fileName;
            $nameInput.val(nameWithoutExt);
        }
    });

    // Toggle Source Manual Input
    $('#source_select').on('change', function() {
        if ($(this).val() === 'Other') {
            $('#source_manual').slideDown().prop('required', true);
        } else {
            $('#source_manual').slideUp().prop('required', false);
        }
    });

    // Project Document Upload Handler
    $('#projectDocUploadForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]');
        const originalText = $btn.html();
        
        // Prepare final source value
        const selVal = $('#source_select').val();
        const finalSource = (selVal === 'Other') ? $('#source_manual').val() : selVal;
        $('#final_source').val(finalSource);

        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Uploading...');

        const formData = new FormData(this);
        $.ajax({
            url: APP_URL + '/api/document/upload_document.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Document uploaded successfully',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        $('#projectDocUploadForm')[0].reset();
                        $('#source_manual').hide();
                        // Activate the "View Docs" tab
                        $('#docs-view-tab').click();
                        loadProjectDetails(); // Refresh everything
                    });
                } else {
                    Swal.fire('Error', res.message || 'Upload failed', 'error');
                }
            },
            error: () => Swal.fire('Error', 'System error during upload', 'error'),
            complete: () => $btn.prop('disabled', false).html(originalText)
        });
    });

    // Edit Document Meta Form Submit
    $('#editDocMetaForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $(this).find('button[type="submit"]');
        
        const selVal = $('#edit_source_select').val();
        const finalSource = (selVal === 'Other') ? $('#edit_source_manual').val() : selVal;
        
        const formData = {
            document_id: $('#edit_doc_id').val(),
            document_name: $('#edit_doc_name').val(),
            source: finalSource
        };

        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

        $.ajax({
            url: APP_URL + '/api/document/update_document_metadata.php',
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    $('#editDocMetaModal').modal('hide');
                    Swal.fire('Success', 'Document updated', 'success');
                    loadProjectDetails();
                } else {
                    Swal.fire('Error', res.message || 'Update failed', 'error');
                }
            },
            error: () => Swal.fire('Error', 'System error', 'error'),
            complete: () => $btn.prop('disabled', false).html('Save Changes')
        });
    });

    // Toggle Edit Source Manual Input
    $('#edit_source_select').on('change', function() {
        if ($(this).val() === 'Other') {
            $('#edit_source_manual').slideDown();
        } else {
            $('#edit_source_manual').slideUp();
        }
    });
});

function editProjectDocument(id, origin, name, source) {
    $('#edit_doc_id').val(id);
    $('#edit_doc_name').val(name);
    
    const standardSources = ['Project Asset', 'Payment Voucher', 'Budget Allocation', 'Invoice / Sales', 'Purchase Order'];
    if (standardSources.includes(source)) {
        $('#edit_source_select').val(source);
        $('#edit_source_manual').hide();
    } else {
        $('#edit_source_select').val('Other');
        $('#edit_source_manual').val(source).show();
    }
    $('#editDocMetaModal').modal('show');
}

function deleteProjectDocument(id, origin) {
    Swal.fire({
        title: 'Are you sure?',
        text: "This document will be permanently deleted!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: '<?= getUrl("api/operations/delete_project_doc") ?>',
                type: 'POST',
                dataType: 'json',
                data: { id: id, origin: origin },
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Deleted!', 'Document has been removed.', 'success');
                        loadProjectDetails();
                    } else {
                        Swal.fire('Error', res.message || 'Delete failed', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Network error. Please try again.', 'error');
                }
            });
        }
    });
}

function loadProjectDetails() {
    BMSSkeleton.load({
        loading: '#loading',
        content: '#content',
        skeleton: { cards: 4, rows: 6 },
        ajax: {
            url: '/api/operations/get_project.php',
            data: { id: projectId },
            dataType: 'json'
        },
        onData: function (res) {
            if (!res.success) throw new Error(res.message || 'Failed to load project data.');
            projectData = res;
            renderProject(res.data, res.financial_summary, res.progress_analysis);
            renderTables(res);
        }
    });
}

// Inventory tab: the expensive stock_movements aggregation lives in its own
// endpoint (get_project_inventory.php) and loads only when the tab is opened,
// not as part of the initial project view.
function loadProjectInventory(force, onDone) {
    if (_cachedInventory && !force) {
        if (onDone) onDone();
        return;
    }
    BMSSkeleton.load({
        loading: '#projectWarehousesSummaryTable',
        skeleton: { rows: 5 },
        ajax: {
            url: '<?= buildUrl('api/operations/get_project_inventory.php') ?>',
            data: { id: projectId },
            dataType: 'json'
        },
        onData: function (res) {
            if (!res.success) {
                if (onDone) onDone();
                throw new Error(res.message || 'Failed to load inventory data.');
            }
            if (projectData) projectData.inventory = res.inventory;
            renderInventory(res.inventory);
            if (onDone) onDone();
        }
    });
}

function renderProject(d, fin, progress) {
    // Header
    $('#projectNameDisplay').text(d.project_name);
    $('#projectNameReport').text(d.project_name);
    $('#projectTitlePrint').text(d.project_name);
    $('#projectManagerDisplay').text(d.project_manager || 'Not Assigned');
    $('#projectIdMeta').text(d.project_id);

    // Update Planning Summary Cards
    $('#summaryProjectStart').text(new Date(d.start_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}));
    $('#summaryProjectDeadline').text(d.deadline ? new Date(d.deadline).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : 'No Deadline');
    if (d.start_date && d.deadline) {
        const diff = Math.ceil((new Date(d.deadline) - new Date(d.start_date)) / (1000*60*60*24)) + 1; // Inclusive
        $('#summaryProjectDuration').text(diff + ' Days');
    }
    
    // Status & Priority
    const statusCls = getStatusColorClass(d.status);
    $('#projectStatusBadge').text(d.status.toUpperCase().replace('_', ' ')).addClass(statusCls);
    $('#statusTextDisplay').text(d.status.replace('_', ' '));
    
    const priBadge = $('#priorityBadge');
    priBadge.text(d.priority.toUpperCase());
    if (d.priority === 'urgent') priBadge.addClass('bg-danger text-white');
    else if (d.priority === 'high') priBadge.addClass('bg-warning text-dark');
    else if (d.priority === 'medium') priBadge.addClass('bg-info text-white');
    else priBadge.addClass('bg-secondary text-white');

    // Financial Summary
    $('#revenueDisplay').text(formatMoney(fin.total_revenue) + ' TZS');
    $('#expectedDisplay').text(formatMoney(d.contract_sum) + ' TZS');
    $('#paidDisplay').text(formatMoney(fin.total_paid || 0) + ' TZS');
    $('#expenseDisplay').text(formatMoney(fin.total_expense) + ' TZS');
    $('#budgetDisplay').text(formatMoney(fin.budget) + ' TZS');
    
    const profit = fin.profit;
    const profitClass = profit >= 0 ? 'text-success' : 'text-danger';
    const profitIconClass = profit >= 0 ? 'text-success' : 'text-danger';
    $('#profitDisplay').text(formatMoney(profit) + ' TZS').removeClass('text-success text-danger').addClass(profitClass);
    $('#profitIcon').removeClass('text-success text-danger').addClass(profitIconClass);
    $('#profitMarginDisplay').text(fin.profit_margin + '% margin');

    const executedProgress = parseFloat(d.progress_percent) || 0;
    const executedValue = (executedProgress / 100) * parseFloat(d.form_contract_sum || 0);
    $('#executedDisplay').text(formatMoney(executedValue) + ' TZS');
    const revenueUnbilled = executedValue - (parseFloat(fin.total_revenue) || 0);
    $('#revenueUnbilledDisplay').text(formatMoney(revenueUnbilled) + ' TZS');
    
    // Budget Performance
    const budget = parseFloat(fin.budget) || 0;
    const spent = parseFloat(fin.total_expense) || 0;
    const remaining = budget - spent;
    const utilization = budget > 0 ? Math.round((spent / budget) * 100) : 0;
    
    $('#budgetAllocated').text(formatMoney(budget) + ' TZS');
    $('#budgetSpent').text(formatMoney(spent) + ' TZS');
    
    // Remaining budget color
    const remainingClass = remaining >= 0 ? 'text-success' : 'text-danger';
    $('#budgetRemaining').text(formatMoney(Math.abs(remaining)) + ' TZS').removeClass('text-success text-danger').addClass(remainingClass);
    
    // Progress bar
    const progressWidth = Math.min(utilization, 100);
    let progressColor = 'bg-success';
    let badgeColor = 'bg-success';
    
    if (utilization >= 90) {
        progressColor = 'bg-danger';
        badgeColor = 'bg-danger';
    } else if (utilization >= 75) {
        progressColor = 'bg-warning';
        badgeColor = 'bg-warning';
    }
    
    $('#budgetProgressBar').css('width', progressWidth + '%').removeClass('bg-success bg-warning bg-danger').addClass(progressColor);
    $('#budgetProgressText').text(utilization + '%');
    $('#utilizationBadge').text(utilization + '%').removeClass('bg-success bg-warning bg-danger').addClass(badgeColor);
    
    // Status message
    let statusMessage = '';
    let statusClass = 'alert-info';
    let statusIcon = 'bi-info-circle';
    
    if (budget === 0) {
        statusMessage = 'No budget has been allocated for this project.';
        statusClass = 'alert-secondary';
    } else if (utilization > 100) {
        const overBudget = spent - budget;
        statusMessage = `⚠️ Over budget by ${formatMoney(overBudget)} TZS (${utilization - 100}% over)`;
        statusClass = 'alert-danger';
        statusIcon = 'bi-exclamation-triangle-fill';
    } else if (utilization >= 90) {
        statusMessage = `⚠️ Warning: ${100 - utilization}% of budget remaining. Approaching limit!`;
        statusClass = 'alert-warning';
        statusIcon = 'bi-exclamation-triangle';
    } else if (utilization >= 75) {
        statusMessage = `Budget is ${utilization}% utilized. Monitor spending closely.`;
        statusClass = 'alert-warning';
        statusIcon = 'bi-exclamation-circle';
    } else if (utilization >= 50) {
        statusMessage = `Budget is ${utilization}% utilized. On track.`;
        statusClass = 'alert-info';
    } else {
        statusMessage = `✓ Budget is ${utilization}% utilized. Well within limits.`;
        statusClass = 'alert-success';
        statusIcon = 'bi-check-circle-fill';
    }
    
    $('#budgetStatusMessage').removeClass('alert-info alert-success alert-warning alert-danger alert-secondary').addClass(statusClass);
    $('#budgetStatusMessage i').removeClass('bi-info-circle bi-check-circle-fill bi-exclamation-circle bi-exclamation-triangle bi-exclamation-triangle-fill').addClass(statusIcon);
    $('#budgetStatusText').text(statusMessage);
    
    // STRICT PRIORITY: 1) Real Milestone Performance Total, 2) Otherwise 0.00% (Avoids confusion with auto-calculated data)
    const progressData = progress;
    let displayProgress, progressSource;
    if (progressData.has_performance_data && progressData.performance_total !== null) {
        displayProgress = progressData.performance_total;
        progressSource = 'milestone';
    } else {
        displayProgress = 0;
        progressSource = 'none';
    }
    
    $('#progressTextDisplay').text(parseFloat(displayProgress).toFixed(2) + '%');
    $('#progressBarDisplay')
        .css('width', displayProgress + '%')
        .removeClass('bg-success bg-warning bg-danger bg-info')
        .addClass(getProgressColor(displayProgress));

    // Source label under the big number
    const sourceLabels = {
        milestone: '<span class="badge bg-primary-subtle text-primary border border-primary-subtle small"><i class="bi bi-flag-fill me-1"></i>Milestone Performance</span>',
        none: '<span class="badge bg-light text-muted small"><i class="bi bi-info-circle me-1"></i>No Reports Yet</span>'
    };
    if ($('#progressSourceLabel').length === 0) {
        $('#progressTextDisplay').after('<div id="progressSourceLabel" class="mt-1"></div>');
    }
    $('#progressSourceLabel').html(sourceLabels[progressSource]);

    // Progress breakdown
    const perfRow = progressData.has_performance_data 
        ? `<div class="d-flex justify-content-between mb-1 fw-bold text-primary border-bottom pb-1">
                <span><i class="bi bi-flag-fill me-1"></i> Performance Progress:</span>
                
           </div>`
        : `<div class="d-flex justify-content-between mb-1 text-muted border-bottom pb-1">
                <span><i class="bi bi-flag me-1"></i> Performance Progress:</span>
                <em class="small" id="performanceProgressBreakdownVal">0.00% (No reports)</em>
           </div>`;

    let progressBreakdown = `
        <div class="small mt-2">
            ${perfRow}
            <div class="d-flex justify-content-between mb-1 text-muted">
                <span><i class="bi bi-cash-coin"></i> Financial Completion:</span>
                <strong>${parseFloat(progressData.financial_completion || 0).toFixed(2)}%</strong>
            </div>
            <div class="d-flex justify-content-between mb-1 text-muted">
                <span><i class="bi bi-calendar-event"></i> Timeline Progress:</span>
                <strong id="timelineProgressBreakdownVal">${parseFloat(displayProgress || 0).toFixed(2)}%</strong>
            </div>
            <div class="d-flex justify-content-between mb-1 text-muted">
                <span><i class="bi bi-piggy-bank"></i> Budget Utilization:</span>
                <strong>${parseFloat(progressData.budget_utilization || 0).toFixed(2)}%</strong>
            </div>
        </div>
    `;
    
    // Progress status message
    let progressStatusMsg = ''; 
    let progressStatusCls = ''; 
    
    if (progressData.is_overdue) {
        progressStatusMsg = `<i class="bi bi-exclamation-triangle-fill me-1"></i> Overdue by ${Math.abs(progressData.days_remaining)} days`;
        progressStatusCls = 'text-danger';
    } else if (progressData.status === 'behind') {
        progressStatusMsg = `<i class="bi bi-exclamation-circle me-1"></i> Behind schedule`;
        progressStatusCls = 'text-warning';
    } else if (progressData.status === 'ahead') {
        progressStatusMsg = `<i class="bi bi-check-circle-fill me-1"></i> Ahead of schedule`;
        progressStatusCls = 'text-success';
    } else {
        progressStatusMsg = progressData.days_remaining !== null ? 
            `<i class="bi bi-check-circle me-1"></i> On track (${progressData.days_remaining} days left)` : 
            `<i class="bi bi-check-circle me-1"></i> Project is on track`;
        progressStatusCls = 'text-success';
    }
    
    $('#progressStatusMessage').html(progressStatusMsg).removeClass('text-success text-warning text-danger').addClass(progressStatusCls);
    $('#activeProjectBadge').text(d.status.toUpperCase().replace('_', ' ')).removeClass('bg-success-soft bg-warning-soft bg-danger-soft bg-primary-soft bg-info-soft text-success text-warning text-danger text-primary text-info border-success border-warning border-danger border-primary border-info')
        .addClass(statusCls.replace('bg-', 'bg-').replace('text-', 'text-')) // Simplified, statusCls from earlier
        .addClass(statusCls.includes('success') ? 'bg-success-soft text-success border-success' : 
                 statusCls.includes('warning') ? 'bg-warning-soft text-warning border-warning' :
                 statusCls.includes('danger') ? 'bg-danger-soft text-danger border-danger' :
                 statusCls.includes('primary') ? 'bg-primary-soft text-primary border-primary' :
                 'bg-info-soft text-info border-info');

    progressBreakdown += `
        <div class="alert alert-sm ${progressStatusCls === 'text-danger' ? 'alert-danger' : progressStatusCls === 'text-warning' ? 'alert-warning' : 'alert-success'} p-2 mt-2 mb-0">
            <i class="bi ${progressStatusCls === 'text-danger' ? 'bi-exclamation-triangle-fill' : progressStatusCls === 'text-warning' ? 'bi-exclamation-circle' : 'bi-check-circle-fill'} me-1"></i>
            <small>${progressStatusMsg.replace(/<i.*<\/i> /, '')}</small>
        </div>
    `;
    
    if (progressSource === 'calculated') {
        progressBreakdown += `
            <div class="mt-2">
                <small class="text-info" style="font-size: 0.7rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    Auto-calculated from financial, timeline, and budget metrics
                </small>
            </div>
        `;
    }
    
    $('#progressBreakdown').html(progressBreakdown);
    
    // Core Project info Grid
    const disc = d.discipline === 'Other' ? d.discipline_other : d.discipline;
    const pos = d.role_position === 'Other' ? d.role_position_other : d.role_position;
    
    let detailsHtml = `
        <div class="col-12 col-sm-6 mb-3">
            <div class="d-flex align-items-start">
                <div class="bg-primary-soft text-primary p-2 rounded-circle me-2 flex-shrink-0" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center;">
                    <i class="bi bi-person-badge" style="font-size:0.85rem;"></i>
                </div>
                <div class="min-w-0" style="min-width:0; flex:1;">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.6rem;">Client/Employer</small>
                    <strong class="text-dark d-block" style="word-break:break-word; white-space:normal; line-height:1.3; font-size:clamp(0.75rem,2vw,0.9rem);">${d.client_name || 'N/A'}</strong>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 mb-3">
            <div class="d-flex align-items-start">
                <div class="text-indigo p-2 rounded-circle me-2 flex-shrink-0" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center; background-color:rgba(102,16,242,0.1); color:#6610f2;">
                    <i class="bi bi-hash" style="font-size:0.85rem;"></i>
                </div>
                <div class="min-w-0" style="min-width:0; flex:1;">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.6rem;">Contract Number</small>
                    <strong class="text-dark d-block" style="word-break:break-all; white-space:normal; line-height:1.3; font-size:clamp(0.75rem,2vw,0.9rem);">${d.contract_number || 'N/A'}</strong>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 mb-3">
            <div class="d-flex align-items-start">
                <div class="bg-success-soft text-success p-2 rounded-circle me-2 flex-shrink-0" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center;">
                    <i class="bi bi-currency-dollar" style="font-size:0.85rem;"></i>
                </div>
                <div class="min-w-0" style="min-width:0; flex:1;">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.6rem;">Contract Sum </small>
                    <strong class="text-dark d-block" style="word-break:break-word; white-space:normal; line-height:1.3; font-size:clamp(0.75rem,2vw,0.9rem);"><span id="contractSumDisplay">${formatMoney(d.form_contract_sum || 0)}</span> TZS</strong>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 mb-3">
            <div class="d-flex align-items-start">
                <div class="bg-success-soft text-success p-2 rounded-circle me-2 flex-shrink-0" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center;">
                    <i class="bi bi-gear-wide-connected" style="font-size:0.85rem;"></i>
                </div>
                <div class="min-w-0" style="min-width:0; flex:1;">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.6rem;">Discipline</small>
                    <strong class="text-dark d-block" style="word-break:break-word; white-space:normal; line-height:1.3; font-size:clamp(0.75rem,2vw,0.9rem);">${disc || 'N/A'}</strong>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 mb-3">
            <div class="d-flex align-items-start">
                <div class="bg-warning-soft text-warning p-2 rounded-circle me-2 flex-shrink-0" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center;">
                    <i class="bi bi-briefcase" style="font-size:0.85rem;"></i>
                </div>
                <div class="min-w-0" style="min-width:0; flex:1;">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.6rem;">Project Role</small>
                    <strong class="text-dark d-block" style="word-break:break-word; white-space:normal; line-height:1.3; font-size:clamp(0.75rem,2vw,0.9rem);">${pos || 'N/A'}</strong>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 mb-3">
            <div class="d-flex align-items-start">
                <div class="bg-info-soft text-info p-2 rounded-circle me-2 flex-shrink-0" style="width:34px; height:34px; display:flex; align-items:center; justify-content:center;">
                    <i class="bi bi-person-gear" style="font-size:0.85rem;"></i>
                </div>
                <div class="min-w-0" style="min-width:0; flex:1;">
                    <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.6rem;">Project Manager</small>
                    <strong class="text-dark d-block" style="word-break:break-word; white-space:normal; line-height:1.3; font-size:clamp(0.75rem,2vw,0.9rem);">${d.project_manager || 'N/A'}</strong>
                </div>
            </div>
        </div>
    `;
    $('#projectDetailsGrid').html(detailsHtml);
    
    // Timelines
    // Timelines - Use normalized values from API for absolute consistency
    const daysRemaining = progress.days_remaining;
    const timeProgress = progress.timeline_progress;
    
    $('#startDateDisplay').text(formatDate(d.start_date));
    $('#deadlineDisplay').text(d.deadline ? formatDate(d.deadline) : 'No Deadline');
    
    $('#timeProgressBar').css('width', timeProgress + '%');
    $('#percentDisplay').text(Math.round(timeProgress) + '% Elapsed');
    $('#totalDurationBadge').text((d.duration_days || 0) + ' Days Total');
    
    const countdownContainer = $('#daysRemainingFocus').closest('.rounded-4');
        
    if (d.duration_days > 0) {
        if (d.status === 'completed') {
            $('#daysRemainingFocus').text('Completed');
            $('#timeDescriptor').html('<i class="bi bi-check-circle-fill me-1"></i>Project Finalized');
            countdownContainer.css('background', 'linear-gradient(135deg, #198754 0%, #20c997 100%)');
        } else if (daysRemaining < 0) {
            $('#daysRemainingFocus').text(Math.abs(daysRemaining) + ' Days Overdue');
            $('#timeDescriptor').html('<i class="bi bi-exclamation-triangle-fill me-1"></i>Schedule Delayed');
            countdownContainer.css('background', 'linear-gradient(135deg, #dc3545 0%, #fd7e14 100%)');
        } else {
            // Mirror Overall Progress exactly — reuse its already-computed status
            // (progressStatusMsg/progressStatusCls) instead of re-deriving ahead/behind
            // here, so the two panels can never disagree.
            $('#daysRemainingFocus').text(daysRemaining + ' Days Left');
            $('#timeDescriptor').html(progressStatusMsg);
            countdownContainer.css('background',
                progressStatusCls === 'text-danger'  ? 'linear-gradient(135deg, #dc3545 0%, #fd7e14 100%)' :
                progressStatusCls === 'text-warning' ? 'linear-gradient(135deg, #ffc107 0%, #fd7e14 100%)' :
                                                        'linear-gradient(135deg, #198754 0%, #20c997 100%)');
        }
    } else {
        $('#daysRemainingFocus').text('No Duration Set');
        $('#timeDescriptor').html('<i class="bi bi-info-circle me-1"></i>Set duration to track progress');
        $('#timeProgressBar').css('width', '0%');
    }

    // Description
    $('#descriptionDisplay').text(d.description || 'No description provided.');
    
    $('#createdAtMeta').text(formatDateTime(d.created_at));
    $('#updatedAtMeta').text(d.updated_at ? formatDateTime(d.updated_at) : 'N/A');

    renderNotes(d.description);
}

function renderDocs(data) {
    const $container = $('#projectDocsList');
    let docs = [];

    // 1. Project Contract
    if (data.data.contract_attachment) {
        docs.push({
            id: data.data.project_id,
            name: 'Project Contract / Agreement',
            path: data.data.contract_attachment,
            source: 'Project Assets',
            date: data.data.created_at,
            type: data.data.contract_attachment.split('.').pop(),
            isManual: false,
            origin: 'contract'
        });
    }

    // 2. Budget Attachments
    const budgets = data.budgets || [];
    budgets.forEach(b => {
        if (b.attachment) {
            docs.push({
                id: b.budget_id,
                name: `Budget Proof - ${b.category_name || 'Item'}`,
                path: b.attachment,
                source: 'Budget Allocation',
                date: b.updated_at || b.created_at,
                type: b.attachment.split('.').pop(),
                isManual: false,
                origin: 'budget'
            });
        }
    });

    // 3. Payment Voucher Attachments
    const vouchers = data.payment_vouchers || [];
    vouchers.forEach(v => {
        if (v.attachment) {
            docs.push({
                id: v.id,
                name: `Payment Proof - PV#${v.reference_number || v.voucher_number || 'N/A'}`,
                path: v.attachment,
                source: 'Payment Vouchers',
                date: v.vouch_date || v.created_at,
                type: v.attachment.split('.').pop(),
                isManual: false,
                origin: 'voucher'
            });
        }
    });

    // 4. Manual Project Uploads (via Documents table)
    const manualDocs = data.project_documents || [];
    manualDocs.forEach(d => {
        if (d.file_path) {
            docs.push({
                id: d.id,
                name: d.document_name,
                path: d.file_path,
                source: d.source || d.category_name || 'Project Upload',
                date: d.uploaded_at,
                type: d.file_type || d.file_path.split('.').pop(),
                isManual: true,
                origin: 'manual'
            });
        }
    });

    if (docs.length === 0) {
        $container.html(`
            <div class="col-12 text-center py-5 border rounded bg-light-soft" style="border-radius: 12px;">
                <div class="stats-icon bg-light text-muted mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2.5rem;">
                    <i class="bi bi-folder-x"></i>
                </div>
                <h6 class="text-muted fw-bold">No documents found for this project</h6>
                <p class="small text-muted mb-0">Upload contracts in Project Edit, or add proofs in Budget/Vouchers.</p>
            </div>
        `);
        return 0;
    }

    let html = `
        <div class="col-12">
            <div class="table-responsive">
                <table id="projectDocsTable" class="table table-hover table-sm align-middle border" style="border-radius: 12px; min-width: 500px;">
                    <thead class="table-light">
                        <tr>
                            <th width="45" class="text-center">S/NO</th>
                            <th>Document Title</th>
                            <th class="d-none d-md-table-cell">Source / Category</th>
                            <th class="d-none d-lg-table-cell text-center">Type</th>
                            <th class="d-none d-md-table-cell">Date Added</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>`;

    docs.forEach((doc, idx) => {
        const icon = getFileIcon(doc.type);
        const color = getFileColor(doc.type);
        html += `
            <tr>
                <td class="text-center text-muted small">${idx + 1}</td>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="bg-${color}-soft text-${color} rounded-circle p-2 me-2 flex-shrink-0" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">
                            <i class="bi ${icon}"></i>
                        </div>
                        <span class="fw-bold text-dark small">${doc.name}</span>
                    </div>
                </td>
                <td class="d-none d-md-table-cell">
                    <span class="badge bg-light text-primary border small text-uppercase" style="font-size: 0.65rem;">
                        <i class="bi bi-tag-fill me-1"></i>${doc.source}
                    </span>
                </td>
                <td class="d-none d-lg-table-cell text-center">
                    <span class="badge bg-secondary-soft text-dark small text-uppercase" style="font-size: 0.6rem;">${doc.type}</span>
                </td>
                <td class="d-none d-md-table-cell">
                    <small class="text-muted"><i class="bi bi-calendar-event me-1"></i>${formatDate(doc.date)}</small>
                </td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-outline-primary dropdown-toggle border shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear me-1"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 151px; border-radius: 10px;">
                            <li>
                                <a href="${APP_URL}/${doc.path}" target="_blank" class="dropdown-item py-2">
                                    <i class="bi bi-eye text-primary me-2"></i> View
                                </a>
                            </li>
                            <li>
                                <a href="${APP_URL}/${doc.path}" download class="dropdown-item py-2">
                                    <i class="bi bi-download text-success me-2"></i> Download
                                </a>
                            </li>
                            ${doc.isManual ? `
                            <li>
                                <a href="javascript:void(0)" class="dropdown-item py-2" onclick="editProjectDocument(${doc.id}, '${doc.origin}', '${doc.name.replace(/'/g, "\\'")}', '${doc.source.replace(/'/g, "\\'")}')">
                                    <i class="bi bi-pencil text-info me-2"></i> Edit
                                </a>
                            </li>
                            <li>
                                <a href="javascript:void(0)" class="dropdown-item py-2" onclick="openDocActivity(${doc.id}, '${doc.name.replace(/'/g, "\\'")}')">
                                    <i class="bi bi-chat-square-text text-secondary me-2"></i> Comments &amp; Access
                                </a>
                            </li>` : ''}
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a href="javascript:void(0)" class="dropdown-item py-2 text-danger" onclick="deleteProjectDocument(${doc.id}, '${doc.origin}')">
                                    <i class="bi bi-trash me-2"></i> Delete
                                </a>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>`;
    });

    html += `
                    </tbody>
                </table>
            </div>
        </div>`;

    $container.html(html);

    // Mobile card view — runs only when on mobile; desktop stays in table view
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('projectDocsTable');

    return docs.length;
}

