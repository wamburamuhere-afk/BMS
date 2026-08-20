function enforceDateConstraints(rowId) {
    const $row = $(`#${rowId}`);
    const parentId = $row.attr('data-parent');
    const $input = $row.find('.task-start');

    if (parentId) {
        const $parent = $(`#${parentId}`);
        const pStart = $parent.find('.task-start').val();
        const pFinish = $parent.find('.task-finish').val();

        if (pStart) $input.attr('min', pStart);
        if (pFinish) $input.attr('max', pFinish);
    } else if (projectData && projectData.data) {
        // Set constraints based on project global dates
        const pStart = projectData.data.start_date;
        const pEnd = projectData.data.deadline;
        if (pStart) $input.attr('min', pStart);
        if (pEnd) $input.attr('max', pEnd);
    }
}

function updatePhaseBadge(phase) {
    const remaining = phase.duration - phase.allocated;
    const $badge = phase.el.find('.phase-rem-days');
    const $chip = phase.el.find('.phase-balance-chip span');
    
    $badge.text(remaining);
    if (remaining < 0) {
        $chip.removeClass('bg-info-soft text-info bg-success-soft text-success').addClass('bg-danger-soft text-danger');
        $chip.html(`<i class="bi bi-exclamation-octagon me-1"></i> Over: ${Math.abs(remaining)}d`);
    } else if (remaining === 0) {
        $chip.removeClass('bg-info-soft text-info bg-danger-soft text-danger').addClass('bg-success-soft text-success');
        $chip.html(`<i class="bi bi-check-circle me-1"></i> Fully Allocated`);
    } else {
        $chip.removeClass('bg-danger-soft text-danger bg-success-soft text-success').addClass('bg-info-soft text-info');
        $chip.html(`Phase Result: ${remaining}d remaining`);
    }
}

function saveProjectPlan() {
    const title = $('#plan_title').val();
    if (!title) {
        Swal.fire('Required', 'Please enter a plan title.', 'warning');
        return;
    }
    
    if (!projectData || !projectData.data) {
        Swal.fire('Error', 'Project data not loaded properly.', 'error');
        return;
    }

    const p = projectData.data;
    const projectTotalDays = Math.ceil((new Date(p.deadline) - new Date(p.start_date)) / (1000 * 60 * 60 * 24)) + 1;

    const tasks = [];
    let valid = true;
    let totalTopLevelDur = 0;

    $('.planning-task-row').each(function() {
        const id = $(this).attr('id');
        const name = $(this).find('.task-name').val();
        const dur = parseInt($(this).find('.task-duration').val()) || 0;
        const start = $(this).find('.task-start').val();
        const finish = $(this).find('.task-finish').val();
        const level = parseInt($(this).attr('data-level')) || 0;
        const parent = $(this).attr('data-parent') || null;

        if (!name || dur === 0 || !start) { valid = false; return false; }

        if (level === 0) totalTopLevelDur += dur;

        tasks.push({
            temp_id: id,
            task_name: name,
            duration_days: dur,
            start_date: start,
            finish_date: finish,
            level: level,
            parent_temp_id: parent,
            is_phase: 0 // Will be determined by having children
        });
    });

    if (!valid) {
        Swal.fire('Required', 'Please fill in name, duration and start date for all rows.', 'warning');
        return;
    }

    // 1. Validate Top Level vs Project
    if (totalTopLevelDur !== projectTotalDays) {
        Swal.fire('Validation Error', `Total duration of all main phases (${totalTopLevelDur} days) must match the total Project duration (${projectTotalDays} days).`, 'error');
        return;
    }

    // 2. Validate Children vs Parents
    for (const t of tasks) {
        const children = tasks.filter(c => c.parent_temp_id === t.temp_id);
        if (children.length > 0) {
            t.is_phase = 1;
            const subTotal = children.reduce((sum, c) => sum + c.duration_days, 0);
            if (subTotal !== t.duration_days) {
                Swal.fire('Validation Error', `The item "${t.task_name}" has sub-tasks totaling ${subTotal} days, but its duration is set to ${t.duration_days} days. They must match.`, 'error');
                return;
            }
        }
    }

    // 3. Project Deadline Final Check
    if (p.deadline) {
        const lastTaskVal = tasks.reduce((prev, current) => (new Date(prev.finish_date) > new Date(current.finish_date)) ? prev : current);
        if (new Date(lastTaskVal.finish_date) > new Date(p.deadline)) {
            Swal.fire('Deadline Alert', 'The plan final date exceeds the overall project deadline defined in registration.', 'error');
            return;
        }
    }
    
    $.post(APP_URL + '/api/operations/save_project_planning.php', {
        project_id: projectId,
        title: title,
        tasks: JSON.stringify(tasks)
    }, function(res) {
        if (res.success) {
            Swal.fire('Plan Submitted!', 'Your plan has been saved and is ready for review.', 'success').then(() => {
                $('#review-tab-trigger').tab('show');
                $('#savePlanBtn').html('<i class="bi bi-save me-1"></i> Save Plan').addClass('btn-primary').removeClass('btn-warning');
            });
            loadExistingPlan();
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
}

function sortTasksHierarchically(tasks) {
    const sorted = [];
    const taskMap = {};
    const childrenMap = {};

    tasks.forEach(t => {
        taskMap[t.id] = t;
        const pid = t.parent_id || 'root';
        if (!childrenMap[pid]) childrenMap[pid] = [];
        childrenMap[pid].push(t);
    });

    // Sort by internal database order (id) to keep user input order
    Object.keys(childrenMap).forEach(pid => {
        childrenMap[pid].sort((a, b) => a.id - b.id);
    });

    function traverse(pid) {
        if (childrenMap[pid]) {
            childrenMap[pid].forEach(child => {
                sorted.push(child);
                traverse(child.id);
            });
        }
    }

    traverse('root');
    return sorted;
}

function loadExistingPlan() {
    $.get(APP_URL + '/api/operations/get_project_planning.php', { project_id: projectId }, function(res) {
        if (res.success) {
            $('#plan_title').val(res.report.title);
            $('#planningTable tbody').empty();
            
            const tasks = res.tasks || [];
            const sortedTasks = sortTasksHierarchically(tasks);
            const isLocked = tasks.length > 0; // If plan exists, lock it by default
            
            if (isLocked) {
                $('#btnAddNewMainPhase').hide();
            } else {
                $('#btnAddNewMainPhase').show();
            }

            sortedTasks.forEach(t => {
                let parentRowId = null;
                if (t.parent_id) {
                    const parentTask = tasks.find(pt => pt.id == t.parent_id);
                    if (parentTask) parentRowId = parentTask.temp_id_mapped;
                }
                
                addPlanningTaskRow(t, parentRowId, null, t.temp_id_mapped, isLocked);
            });
            
            reindexTaskIds();
            calculateDatesFromDependencies(); 
            renderReviewContent(sortedTasks);
            if (res.report.status === 'approved') renderFinalSchedule(res.report, sortedTasks);
        } else if (res.no_plan) {
            $('#planningTable tbody').empty();
            addPlanningTaskRow();
            reindexTaskIds();
            $('#reviewPlanContent').html('<div class="text-center py-5 text-muted">No plan available to review.</div>');
        }
    }, 'json');
}

function unlockPhase(rowId) {
    const $row = $(`#${rowId}`);
    // Unlock this row
    $row.find('input').prop('readonly', false).css({'background': '', 'border-color': ''});
    $row.find('.task-finish').prop('readonly', true).css('background', '#f8f9fa'); // Keep finish readonly
    
    // Unlock all children recursively
    const myLevel = parseInt($row.attr('data-level')) || 0;
    let $next = $row.next();
    while ($next.length && (parseInt($next.attr('data-level')) || 0) > myLevel) {
        $next.find('input').prop('readonly', false).css({'background': '', 'border-color': ''});
        $next.find('.task-finish').prop('readonly', true).css('background', '#f8f9fa');
        $next.find('.subtask-actions').show();
        $next = $next.next();
    }
    
    // Switch cog text or icon to show it's editing
    $row.find('.dropdown-toggle i').removeClass('bi-gear-fill').addClass('bi-pencil-fill');
    $row.find('.dropdown-item:first-child').html('<i class="bi bi-lock-fill text-warning me-2"></i>Editing Mode Active').addClass('disabled');
}



function renderReviewContent(tasks) {
    let html = `
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="text-center" style="width: 60px;">S/NO</th>
                        <th class="text-center">Task Description / Phase</th>
                        <th class="text-center" style="width: 100px;">Duration</th>
                        <th class="text-center" style="width: 150px;">Start Date</th>
                        <th class="text-center" style="width: 150px;">Finish Date</th>
                    </tr>
                </thead>
                <tbody>
                    ${tasks.map((t, idx) => {
                        const level = t.level || 0;
                        const hasChildren = tasks.some(c => c.parent_id == t.id);
                        const f = { day: '2-digit', month: 'short', year: 'numeric' };
                        return `
                            <tr id="review_row_${t.id}" 
                                class="review-task-row" 
                                data-parent="${t.parent_id || ''}" 
                                data-level="${level}"
                                style="${level === 0 ? 'font-weight: 800; background-color: #f8f9ff;' : 'font-weight: 400;'}">
                                <td class="text-center fw-bold review-id-cell text-dark">-</td>
                                <td style="padding-left: ${level * 40 + 20}px !important;">
                                    <div class="d-flex align-items-center">
                                        <button class="btn btn-sm p-0 border-0 me-1 toggle-review-subtasks" 
                                                onclick="toggleReviewSubtasks(${t.id})" 
                                                style="visibility: ${hasChildren ? 'visible' : 'hidden'}; width: 20px; outline: none !important; box-shadow: none !important;">
                                            <i class="bi bi-caret-down-fill text-muted"></i>
                                        </button>
                                        <span style="${level === 0 ? 'text-transform: uppercase;' : 'color: #666;'}">${t.task_name}</span>
                                    </div>
                                </td>
                                <td class="text-center">${t.duration_days} days</td>
                                <td class="text-center">${new Date(t.start_date).toLocaleDateString('en-GB', f)}</td>
                                <td class="text-center">${new Date(t.finish_date).toLocaleDateString('en-GB', f)}</td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;
    $('#reviewPlanContent').html(html);
    reindexReviewTaskIds();
}

function reindexReviewTaskIds() {
    let count = 0;
    $('.review-task-row').each(function() {
        if ($(this).css('display') !== 'none') {
            count++;
            $(this).find('.review-id-cell').text(count);
        }
    });
}

function toggleReviewSubtasks(taskId) {
    const $row = $(`#review_row_${taskId}`);
    const $icon = $row.find('.toggle-review-subtasks i');
    const isCollapsed = $icon.hasClass('bi-caret-right-fill');
    
    if (isCollapsed) {
        $icon.removeClass('bi-caret-right-fill').addClass('bi-caret-down-fill');
    } else {
        $icon.removeClass('bi-caret-down-fill').addClass('bi-caret-right-fill');
    }

    recursiveReviewToggle(taskId, !isCollapsed);
    reindexReviewTaskIds();
}

function recursiveReviewToggle(parentId, hide) {
    $(`.review-task-row[data-parent="${parentId}"]`).each(function() {
        const childId = $(this).attr('id').replace('review_row_', '');
        if (hide) {
            $(this).hide();
            recursiveReviewToggle(childId, true);
        } else {
            $(this).show();
            const $childIcon = $(this).find('.toggle-review-subtasks i');
            if (!$childIcon.hasClass('bi-caret-right-fill')) {
                recursiveReviewToggle(childId, false);
            }
        }
    });
}

function expandCollapseAllReview(expand) {
    $('.review-task-row').each(function() {
        const $row = $(this);
        const rowId = $row.attr('id').replace('review_row_', '');
        const hasChildren = $(`.review-task-row[data-parent="${rowId}"]`).length > 0;
        
        if (hasChildren) {
            const $icon = $row.find('.toggle-review-subtasks i');
            if (expand) {
                $icon.removeClass('bi-caret-right-fill').addClass('bi-caret-down-fill');
            } else {
                $icon.removeClass('bi-caret-down-fill').addClass('bi-caret-right-fill');
            }
        }
    });

    if (expand) {
        $('.review-task-row').show();
    } else {
        $('.review-task-row').each(function() {
            const level = parseInt($(this).attr('data-level')) || 0;
            if (level > 0) $(this).hide();
            else $(this).show();
        });
    }
    reindexReviewTaskIds();
}

function editCurrentPlan() {
    $('#planning-tab').click();
    $('#savePlanBtn').html('<i class="bi bi-save me-1"></i> Resave Plan').addClass('btn-warning').removeClass('btn-primary');
}

function approvePlan() {
    $.post(APP_URL + '/api/operations/approve_project_planning.php', { project_id: projectId }, function(res) {
        if (res.success) {
            Swal.fire('Success', 'Plan approved and activated!', 'success').then(() => {
                $('#schedules-tab-trigger').tab('show');
            });
            loadExistingPlan();
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
}

function deletePlan() {
    Swal.fire({
        title: 'Delete Project Plan?',
        text: "This will permanently remove the current plan and all its tasks. You will need to start fresh in the Planning tab.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#aaa',
        confirmButtonText: 'Yes, delete everything'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/operations/delete_project_planning.php', { project_id: projectId }, function(res) {
                if (res.success) {
                    Swal.fire('Deleted!', 'The plan has been cleared.', 'success').then(() => {
                        $('#planning-tab').click();
                    });
                    loadExistingPlan();
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

function renderFinalSchedule(report, tasks) {
    const html = `
        <div class="card border-0 shadow-sm" id="fullScheduleExportArea" style="border-radius: 12px; overflow: hidden; background: #fff; width: 100%;">
            <div class="card-body p-0">
                <div class="d-print-none p-2 p-md-3 border-bottom bg-light d-flex justify-content-between align-items-center" data-html2canvas-ignore="true">
                    <span class="text-muted small fw-bold ms-2 d-none d-sm-inline"><i class="bi bi-filter-left me-1"></i> SCHEDULE VIEW OPTIONS:</span>
                    
                    <!-- Desktop Buttons -->
                    <div class="d-none d-md-flex gap-2 align-items-center ms-auto">
                        <button class="btn btn-sm btn-outline-secondary px-3" onclick="expandCollapseAllSchedule(false)" title="Collapse to Main Phases">
                            <i class="bi bi-dash-square me-1"></i> MAIN PHASES
                        </button>
                        <button class="btn btn-sm btn-outline-secondary px-3" onclick="expandCollapseAllSchedule(true)" title="Expand Full Details">
                            <i class="bi bi-plus-square me-1"></i> FULL DETAILS
                        </button>
                        <div class="vr mx-2"></div>
                        <button class="btn btn-sm btn-outline-primary px-3" onclick="exportScheduleToPDF()">
                            <i class="bi bi-file-earmark-pdf me-1"></i> EXPORT PDF
                        </button>
                        <button class="btn btn-sm btn-outline-dark px-3" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i> PRINT
                        </button>
                    </div>

                    <!-- Mobile Dropdown -->
                    <div class="dropdown d-md-none ms-auto">
                        <button class="btn btn-primary btn-sm dropdown-toggle shadow-sm px-4" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-sliders me-1"></i> Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="min-width: 200px; border-radius: 12px;">
                            <li class="dropdown-header text-uppercase small fw-bold">View Controls</li>
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="expandCollapseAllSchedule(false)"><i class="bi bi-dash-square me-2 text-secondary"></i>Main Phases</a></li>
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="expandCollapseAllSchedule(true)"><i class="bi bi-plus-square me-2 text-secondary"></i>Full Details</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li class="dropdown-header text-uppercase small fw-bold">Output Options</li>
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="exportScheduleToPDF()"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Export PDF</a></li>
                            <li><a class="dropdown-item py-2" href="javascript:void(0)" onclick="window.print()"><i class="bi bi-printer me-2 text-dark"></i>Print Schedule</a></li>
                        </ul>
                    </div>
                </div>
                
                <!-- Print Header (Visible only on print) -->
                <div class="text-center mb-4 d-none d-print-block" style="padding: 20px 0 10px;">
                    <?php if(!empty($company_logo)): ?>
                    <div style="margin-bottom: 10px;">
                        <img src="<?= getUrl($company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                    </div>
                    <?php endif; ?>
                    <h2 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0;"><?= htmlspecialchars($company_name) ?></h2>
                    <h3 style="color: #000 !important; font-weight: 700; text-transform: uppercase; margin: 4px 0;">${report.title ? report.title.toUpperCase() : 'PROJECT IMPLEMENTATION SCHEDULE'}</h3>
                    <h6 style="color: #666; font-weight: 600; margin: 4px 0; font-size: 0.95rem; word-break: break-all; white-space: normal;">Contract No: <?= htmlspecialchars($contract_no) ?></h6>
                    <h5 style="color: #333; font-weight: 600; margin: 4px 0;"><?= htmlspecialchars($project_name) ?></h5>
                    <div style="width: 60px; height: 3px; background: #0d6efd; border-radius: 2px; margin: 8px auto 0;"></div>
                </div>

                <div class="p-4 text-center border-bottom bg-white d-print-none" style="width: 100%;">
                    <h3 class="fw-bold mb-0 text-dark" style="text-transform: uppercase; letter-spacing: 1px;">${report.title || 'PROJECT IMPLEMENTATION SCHEDULE'}</h3>
                </div>
                
                <div class="schedule-container d-flex flex-nowrap bg-white" style="width: 100%;">
                    <!-- Left Side: Data Table -->
                    <div class="schedule-table-side border-end w-100" style="flex: 0 0 auto; min-width: 100%; max-width: 100%; overflow-x: auto; flex-basis: auto;">
                        <style>
                            @media (min-width: 992px) {
                                .schedule-table-side { min-width: 650px !important; width: 650px !important; }
                            }
                        </style>
                         <style>
        /* Professional Autocomplete for Budget Items */
        .budget-autocomplete-list {
            position: absolute;
            z-index: 9999;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            max-height: 250px;
            overflow-y: auto;
            width: 450px;
            text-align: left;
            top: 100%;
            left: 0;
        }
        /* Ensure table-responsive doesn't clip the autocomplete */
        #addBudgetItemModal .table-responsive {
            overflow: visible !important;
        }
        .budget-autocomplete-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f8f9fa;
            display: grid;
            grid-template-columns: 2fr 0.5fr 0.8fr 1fr;
            gap: 10px;
            font-size: 0.85rem;
        }
        .budget-autocomplete-item:hover {
            background-color: #f0f7ff;
        }
        .budget-autocomplete-header {
            background: #f8f9fa;
            font-weight: bold;
            color: #666;
            padding: 8px 15px;
            display: grid;
            grid-template-columns: 2fr 0.5fr 0.8fr 1fr;
            gap: 10px;
            font-size: 0.75rem;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            border-bottom: 2px solid #eee;
        }
        @media print {
            .d-print-none { display: none !important; }
        }
    </style>
                        <table class="table table-bordered align-middle mb-0" style="font-size: 0.8rem; border-color: #dee2e6; min-width: 600px;">
                            <thead class="bg-light sticky-top" style="z-index: 21;">
                                <tr style="height: 100px;">
                                    <th class="text-center" style="width: 40px; background: #f8f9fa;">S/NO</th>
                                    <th class="text-center" style="min-width: 250px; background: #f8f9fa;">Task Description / Phase</th>
                                    <th class="text-center" style="width: 80px; background: #f8f9fa;">Days</th>
                                    <th class="text-center" style="width: 110px; background: #f8f9fa;">Start Date</th>
                                    <th class="text-center" style="width: 110px; background: #f8f9fa;">End Date</th>
                                </tr>
                            </thead>
                                <tbody>
                                    ${tasks.map((t, idx) => {
                                        const level = t.level || 0;
                                        return `
                                            <tr id="sched_row_${t.id}" 
                                                class="schedule-data-row" 
                                                data-parent="${t.parent_id || ''}" 
                                                data-level="${level}"
                                                style="height: 35px !important; ${level === 0 ? 'font-weight: bold; background: #f8f9fa;' : ''}">
                                                <td class="text-center fw-bold schedule-id-cell">-</td>
                                                <td style="padding-left: ${level * 20 + 10}px !important;">
                                                    <span class="${level === 0 ? 'text-uppercase' : ''}">${t.task_name}</span>
                                                </td>
                                                <td class="text-center">${t.duration_days}</td>
                                                <td class="text-center small">${new Date(t.start_date).toLocaleDateString('en-GB', {day:'2-digit', month:'2-digit', year:'numeric'})}</td>
                                                <td class="text-center small">${new Date(t.finish_date).toLocaleDateString('en-GB', {day:'2-digit', month:'2-digit', year:'numeric'})}</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                        </table>
                    </div>
                    <!-- Right Side: Gantt Chart -->
                    <div class="schedule-gantt-side border-top border-lg-top-0 w-100" style="flex: 1 1 auto; overflow-x: auto;">
                        ${renderEnhancedGantt(tasks)}
                    </div>
                </div>
                
                <div class="schedule-footer p-4 border-top bg-white" style="font-size: 0.75rem; border-top: 2px solid #000 !important; width: 100%;">
                    <div class="row align-items-start">
                        <!-- Roles & Stakeholders -->
                        <div class="col-md-3 border-end">
                            <h6 class="fw-bold mb-3" style="font-size: 0.8rem;">ROLES & STAKEHOLDERS</h6>
                            <div class="mb-2"><strong>EDE</strong> - Electrical Distribution Engineer</div>
                            <div class="mb-2"><strong>PM</strong> - Project Manager: <span class="text-primary fw-bold" id="footerManager">...</span></div>
                           
                        </div>
                        
                        <!-- Symbols Legend -->
                        <div class="col-md-9">
                            <h6 class="fw-bold mb-3 px-3" style="font-size: 0.8rem;">CHART LEGEND (SYMBOLS)</h6>
                            <div class="row g-3 px-3">
                                <div class="col-3 d-flex align-items-center">
                                    <div style="width: 40px; height: 8px; background: #4169E1; margin-right: 10px; border-radius: 2px;"></div>
                                    <span>Manual Task</span>
                                </div>
                                <div class="col-3 d-flex align-items-center">
                                    <div class="position-relative" style="width: 40px; height: 4px; background: #000; margin-right: 10px;">
                                        <div style="position:absolute; top: 0; left:0; width:0; height:0; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 6px solid #000;"></div>
                                        <div style="position:absolute; top: 0; right:0; width:0; height:0; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 6px solid #000;"></div>
                                    </div>
                                    <span>Summary</span>
                                </div>
                                <div class="col-3 d-flex align-items-center">
                                    <div style="width: 10px; height: 10px; background: #333; transform: rotate(45deg); margin-right: 15px;"></div>
                                    <span>Milestone</span>
                                </div>
                                <div class="col-3 d-flex align-items-center">
                                    <div style="width: 40px; height: 2px; background: #666; margin-right: 10px;"></div>
                                    <span>External Tasks</span>
                                </div>
                                <div class="col-3 d-flex align-items-center">
                                    <div style="width: 40px; height: 8px; background: #dc3545; opacity: 0.4; margin-right: 10px;"></div>
                                    <span>Critical Task</span>
                                </div>
                                <div class="col-3 d-flex align-items-center">
                                    <div style="width: 40px; height: 1px; border-bottom: 2px dashed #000; margin-right: 10px;"></div>
                                    <span>Split</span>
                                </div>
                                <div class="col-3 d-flex align-items-center">
                                    <div style="width: 40px; height: 4px; background: #000; border-radius: 10px; margin-right: 10px;"></div>
                                    <span>Project Summary</span>
                                </div>
                                <div class="col-3 d-flex align-items-center">
                                    <div style="width: 10px; height: 10px; border: 2px solid #4169E1; transform: rotate(45deg); margin-right: 15px;"></div>
                                    <span>Inactive Task</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    `;
    $('#activeScheduleContainer').html(html);
    reindexScheduleTaskIds();
    $('#footerManager').text($('#projectManagerDisplay').text() || 'Not Assigned');
}

function expandCollapseAllSchedule(expand) {
    if (expand) {
        $('.schedule-data-row').attr('style', '');
        $('.gantt-row-container').attr('style', 'height: 35px; position: relative;');
    } else {
        $('.schedule-data-row').each(function() {
            const level = parseInt($(this).attr('data-level')) || 0;
            if (level > 0) $(this).attr('style', 'display: none !important');
            else $(this).attr('style', '');
        });
        $('.gantt-row-container').each(function() {
            const level = parseInt($(this).attr('data-level')) || 0;
            if (level > 0) $(this).attr('style', 'display: none !important');
            else $(this).attr('style', 'height: 35px; position: relative;');
        });
    }
    reindexScheduleTaskIds();
}

async function exportScheduleToPDF() {
    const { jsPDF } = window.jspdf;
    const element = document.getElementById('fullScheduleExportArea');
    
    // Temporarily force wide layout for high-quality export
    $('#fullScheduleExportArea').css('min-width', '1600px');
    
    // Inject temporary styles for larger, darker fonts for the export
    const styleId = 'pdf-export-styles';
    if (!$('#' + styleId).length) {
        $('head').append(`
            <style id="${styleId}">
                #fullScheduleExportArea { font-size: 14px !important; color: #000 !important; }
                #fullScheduleExportArea table { font-size: 13px !important; }
                #fullScheduleExportArea th { font-size: 14px !important; color: #000 !important; font-weight: bold !important; border-bottom: 2px solid #000 !important; }
                #fullScheduleExportArea td { font-size: 13px !important; color: #000 !important; }
                #fullScheduleExportArea .schedule-id-cell { font-weight: bold !important; }
                #fullScheduleExportArea span, #fullScheduleExportArea strong { color: #000 !important; }
                /* Darken Gantt bars and legend text */
                #fullScheduleExportArea .gantt-bar-container { border-radius: 4px !important; }
                #fullScheduleExportArea .schedule-footer { font-size: 12px !important; color: #000 !important; }
                #fullScheduleExportArea .schedule-footer strong { color: #000 !important; }
            </style>
        `);
    }

    // Temporarily show print-only elements for canvas capture
    $('.d-print-block').removeClass('d-none').css('display', 'block');
    
    // Temporarily hide screen-only action buttons
    $('.d-print-none').hide();
    
    Swal.fire({
        title: 'Exporting PDF...',
        text: 'Please wait while we generate your schedule',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    try {
        const canvas = await html2canvas(element, {
            scale: 2.5, // Increased scale for even better sharpness
            useCORS: true,
            logging: false,
            backgroundColor: '#ffffff'
        });

        const imgData = canvas.toDataURL('image/png');
        const pdf = new jsPDF('l', 'mm', 'a3'); // CHANGED TO A3 Landscape for better clarity
        
        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        
        const sideMargin = 10; // 10mm margin on each side
        const imgProps = pdf.getImageProperties(imgData);
        const contentWidth = pageWidth - (sideMargin * 2); 
        const contentHeightInPdf = (imgProps.height * contentWidth) / imgProps.width;
        const xOffset = sideMargin; 

        // Configuration for Margins
        const topMargin = 15;    // Space for Header
        const footerMargin = 15; // Space for Footer (Slightly reduced to maximize usable space)
        const usableHeight = pageHeight - topMargin - footerMargin;

        let remainingHeight = contentHeightInPdf;
        let currentY = 0; // Vertical offset of the image in PDF terms
        let pageCount = 1;

        // Function to Draw the Fixed Footer (Repeated on every page)
        const drawPageFooter = (p) => {
            const userName = "<?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?>";
            const userRole = "<?= ucwords($_SESSION['user_role'] ?? 'Staff') ?>";
            const now = new Date();
            const exportDate = now.toLocaleDateString('en-GB', {day: '2-digit', month: '2-digit', year: 'numeric'}).replace(/\//g, ' ');
            const exportTime = now.toLocaleTimeString('en-GB', {hour: '2-digit', minute: '2-digit'});

            p.setFontSize(8);
            p.setTextColor(150, 150, 150);
            p.setDrawColor(220, 220, 220);
            p.line(10, pageHeight - 12, pageWidth - 10, pageHeight - 12); // Separator line
            
            p.setFont("helvetica", "normal");
            p.text(`This Document was Exported by ${userName} - ${userRole} on ${exportDate} at ${exportTime} | Page ${pageCount}`, pageWidth / 2, pageHeight - 8, { align: 'center' });
            
            p.setTextColor(13, 110, 253); 
            p.setFont("helvetica", "bold");
            p.text("Powered By BJP Technologies © 2026, All Rights Reserved", pageWidth / 2, pageHeight - 4, { align: 'center' });
        };

        // Paging Logic
        while (remainingHeight > 0) {
            // Draw the portion of the schedule with side margins (xOffset)
            pdf.addImage(imgData, 'PNG', xOffset, topMargin - currentY, contentWidth, contentHeightInPdf, undefined, 'FAST');

            // Mask the Header area to keep it white/clean at the top of every page
            pdf.setFillColor(255, 255, 255);
            pdf.rect(0, 0, pageWidth, topMargin, 'F');
            
            // Mask the Footer area at the bottom to prevent data overlap
            pdf.rect(0, pageHeight - footerMargin, pageWidth, footerMargin, 'F');

            // Add Footer to current page
            drawPageFooter(pdf);

            remainingHeight -= usableHeight;
            currentY += usableHeight;

            if (remainingHeight > 0) {
                pdf.addPage();
                pageCount++;
            }
        }

        pdf.save('Project_Schedule_' + (typeof projectId !== 'undefined' ? projectId : 'Report') + '.pdf');
        
        Swal.fire('Success', 'PDF exported successfully!', 'success');
    } catch (error) {
        console.error('PDF Export Error:', error);
        Swal.fire('Error', 'Failed to generate PDF. Please try the Print option instead.', 'error');
    } finally {
        // Restore original state and remove temporary styles
        $('#fullScheduleExportArea').css('min-width', '');
        $('#' + styleId).remove();
        $('.d-print-block').addClass('d-none').css('display', '');
        $('.d-print-none').show();
    }
}

function renderEnhancedGantt(tasks) {
    if (tasks.length === 0) return '';
    
    // 1. Calculate Date Range
    const dates = [];
    tasks.forEach(t => {
        dates.push(new Date(t.start_date));
        dates.push(new Date(t.finish_date));
    });
    const startD = new Date(Math.min.apply(null, dates));
    const endD = new Date(Math.max.apply(null, dates));
    
    // Set to start of Jan of the first year and Dec of the last year
    const minD = new Date(startD.getFullYear(), 0, 1);
    const maxD = new Date(endD.getFullYear(), 11, 31);
    
    const years = [];
    for (let y = minD.getFullYear(); y <= maxD.getFullYear(); y++) {
        years.push(y);
    }
    
    const minGanttWidth = 1000; // Minimum width in pixels
    let quarterWidth = 80; // Base width
    const totalQuarters = years.length * 4;
    
    // Dynamically adjust quarter width to fill at least the minGanttWidth
    if (totalQuarters * quarterWidth < minGanttWidth) {
        quarterWidth = Math.floor(minGanttWidth / totalQuarters);
    }
    
    // Header Logic
    let yearHeaders = '';
    let halfHeaders = '';
    let quarterHeaders = '';
    
    years.forEach(y => {
        const yearWidth = quarterWidth * 4;
        yearHeaders += `<div class="text-center border-end border-bottom bg-light fw-bold py-1" style="width: ${yearWidth}px; background-color: #f8f9fa !important;">${y}</div>`;
        
        halfHeaders += `<div class="text-center border-end border-bottom small py-1" style="width: ${yearWidth/2}px;">Half 1, ${y}</div>`;
        halfHeaders += `<div class="text-center border-end border-bottom small py-1" style="width: ${yearWidth/2}px;">Half 2, ${y}</div>`;
        
        for (let q = 1; q <= 4; q++) {
            quarterHeaders += `<div class="text-center border-end small py-1 bg-white" style="width: ${quarterWidth}px; font-size: 0.7rem;">Qtr ${q}</div>`;
        }
    });

    const totalWidth = years.length * quarterWidth * 4;

    return `
        <div class="gantt-scroll-box" style="width: fit-content;">
            <div class="gantt-header sticky-top" style="z-index: 30; background: #fff;">
                <div class="d-flex" style="height: 25px; border-bottom: 2px solid #dee2e6;">&nbsp;</div>
                <div class="d-flex">${yearHeaders}</div>
                <div class="d-flex">${halfHeaders}</div>
                <div class="d-flex">${quarterHeaders}</div>
            </div>
            <div class="gantt-body position-relative" style="width: ${totalWidth}px;">
                <!-- Grid Lines -->
                <div class="position-absolute h-100 w-100" style="z-index: 1; pointer-events: none;">
                    ${Array.from({length: years.length * 4}).map((_, i) => 
                        `<div class="position-absolute h-100 border-end" style="left: ${i * quarterWidth}px; width: 0; opacity: 0.1;"></div>`
                    ).join('')}
                </div>
                
                <!-- Bars -->
                <div class="task-bars-container py-0" style="z-index: 5;">
                    ${tasks.map((t, idx) => {
                        const isP = t.is_phase == 1;
                        const taskStart = new Date(t.start_date);
                        const taskEnd = new Date(t.finish_date);
                        
                        // Calculate offset from minD in days
                        const diffDaysStart = (taskStart - minD) / (1000 * 60 * 60 * 24);
                        const diffDaysEnd = (taskEnd - minD) / (1000 * 60 * 60 * 24) + 1;
                        
                        // Width of a single day (approximated based on quarter width)
                        const dayWidth = (quarterWidth * 4) / 365.25;
                        
                        const left = diffDaysStart * dayWidth;
                        const width = (diffDaysEnd - diffDaysStart) * dayWidth;
                        
                        return `
                             <div class="gantt-row-container gantt-row border-bottom d-flex align-items-center" 
                                 data-level="${t.level || 0}"
                                 data-parent="${t.parent_id || ''}"
                                 style="height: 35px !important; position: relative; width: ${totalWidth}px;">
                                <div class="gantt-bar position-absolute ${isP ? 'phase-bar' : 'subtask-bar'}" 
                                     style="left: ${left}px; width: ${width}px; height: ${isP ? '8px' : '15px'}; 
                                             background-color: ${isP ? '#333' : '#4169E1'} !important; 
                                            border-radius: ${isP ? '0' : '3px'};
                                            opacity: ${isP ? '1' : (0.7 + (1 - (t.level || 0) * 0.2))};
                                            ${isP ? 'margin-top: 0px;' : ''}"
                                     title="${t.task_name} (${t.duration_days} days)">
                                     ${!isP ? `<span class="gantt-label" style="position: absolute; left: 105%; top: 50%; transform: translateY(-50%); white-space: nowrap; font-size: 0.65rem; color: #555;">${t.task_name}</span>` : ''}
                                </div>
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        </div>
    `;
}

$(document).ready(function() {
    loadExistingPlan();
    
    // Refresh S/NO when planning tabs are shown
    $('button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
        if (e.target.id === 'planning-tab') {
            reindexTaskIds();
        } else if (e.target.id === 'review-tab-trigger') {
            reindexReviewTaskIds();
        } else if (e.target.id === 'schedules-tab-trigger') {
            reindexScheduleTaskIds();
        }
    });

    // Pre-load scope headers and initial states
    loadScopes('original');
    loadScopes('revised');
    initVariationScope(); // This will auto-fetch max number and trigger loadScopes('variation')
    loadScopes('additional');
});

// --- SCOPE MANAGEMENT HUB ---
function openScopeTab(type) {
    const triggerId = `trigger-scope-${type}`;
    const triggerEl = document.getElementById(triggerId);
    if (triggerEl) {
        bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        // Close dropdowns
        document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
        
        if (type === 'variation-history') {
            initVariationArchive();
        } else {
            loadScopes(type);
        }
    }
}

function addNewScopeRow(type, data = null, isLocked = false) {
    let tableId = (typeof type === 'string') ? `${type}ScopeTable` : 'originalScopeTable';
    if (type === 'variation-history') tableId = 'variationHistoryTable';
    
    const tbody = $(`#${tableId} tbody`);
    const sn = tbody.find('tr').length + 1;
    
    // Default values if no data provided
    const qty = data ? parseFloat(data.scope) : 0;
    const amount = data ? parseFloat(data.amount) : 0;
    const taxRate = data ? parseFloat(data.tax_rate || 0) : 0;
    const subtotal = qty * amount;
    const taxAmount = subtotal * (taxRate / 100);
    const total = subtotal + taxAmount;

    const row = `
        <tr class="scope-row" data-id="${data ? data.id : ''}">
            <td class="ps-4 text-muted small sn-counter" data-label="S/NO">${sn}</td>
            <td data-label="DESCRIPTION">
                <textarea class="form-control form-control-sm s-desc" 
                          placeholder="Enter scope description..." 
                          ${isLocked ? 'readonly' : ''} 
                          oninput="this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'" 
                          style="min-height: 33px; resize: none; overflow: hidden;">${data ? data.description : ''}</textarea>
            </td>
            <td data-label="UNIT">
                <textarea class="form-control form-control-sm s-unit" 
                          placeholder="e.g. LS" 
                          ${isLocked ? 'readonly' : ''} 
                          oninput="this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'" 
                          style="min-height: 33px; resize: none; overflow: hidden;">${data ? data.unit : ''}</textarea>
            </td>
            <td data-label="QUANTITY"><input type="number" step="0.01" class="form-control form-control-sm s-qty text-center" value="${qty}" oninput="calculateScopeRow(this)" ${isLocked ? 'readonly' : ''}></td>
            <td data-label="PRICE"><input type="number" step="0.01" class="form-control form-control-sm s-amount" value="${amount}" oninput="calculateScopeRow(this)" ${isLocked ? 'readonly' : ''}></td>
            <td data-label="TAX (%)">
                <select class="form-select form-select-sm s-tax-rate text-center" onchange="calculateScopeRow(this)" ${isLocked ? 'disabled' : ''}>
                    <option value="0" ${taxRate == 0 ? 'selected' : ''}>0%</option>
                    <option value="18" ${taxRate == 18 ? 'selected' : ''}>18%</option>
                </select>
            </td>
            <td data-label="TOTAL AMOUNT"><input type="text" class="form-control form-control-sm s-total border-0 bg-light fw-bold" value="${total.toLocaleString(undefined, { minimumFractionDigits: 2 })}" readonly></td>
            <td class="text-end pe-4 action-column d-print-none" ${isLocked ? 'style="display:none"' : ''} data-label="ACTION">
                <button class="btn btn-sm btn-outline-danger border-0" onclick="$(this).closest('tr').remove(); updateScopeGrandTotal('${type}'); updateSNCounters('${tableId}');">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
    `;
    tbody.append(row);
    updateScopeGrandTotal(type);
}

