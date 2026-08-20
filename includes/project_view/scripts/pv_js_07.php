function openPayVoucher(v) {
    const balance = parseFloat(v.balance_due ?? v.amount) || 0;
    const alreadyPaid = parseFloat(v.amount_paid ?? 0) || 0;
    $('#pay_voucher_id').val(v.id);
    $('#pay_voucher_no').text(v.voucher_number || ('#' + v.id));
    $('#pay_payee').text(v.payee_name || '—');
    $('#pay_amount').text(formatMoney(v.amount) + ' TZS');
    $('#pay_already_paid').text(formatMoney(alreadyPaid) + ' TZS');
    $('#pay_balance_due').text(formatMoney(balance) + ' TZS');
    $('#pay_payment_amount').val(balance.toFixed(2)).attr('max', balance.toFixed(2));
    $('#pay_reference').val(v.reference_number || '');
    $('#pay_date').val(new Date().toISOString().split('T')[0]);
    if (v.payment_method) $('#pay_method').val(v.payment_method);
    $('#pay_paid_from').val('').trigger('change');
    new bootstrap.Modal(document.getElementById('payVoucherModal')).show();
}

$(document).on('submit', '#payVoucherForm', function (e) {
    e.preventDefault();
    if (!$('#pay_paid_from').val()) { Swal.fire({ icon: 'warning', title: 'Required', text: 'Choose the Paid From account.' }); return; }
    const $btn = $(this).find('[type="submit"]');
    const orig = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Recording…');
    $.ajax({
        url: '/api/account/record_voucher_payment.php',
        type: 'POST', data: new FormData(this), contentType: false, processData: false, dataType: 'json',
        success: function (res) {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('payVoucherModal')).hide();
                Swal.fire({ icon: 'success', title: 'Payment Recorded', text: res.message, showConfirmButton: true })
                    .then(() => loadProjectDetails());
            } else {
                Swal.fire({ icon: 'error', title: 'Error', text: res.message || 'Could not record payment.' });
            }
        },
        error: function () { Swal.fire({ icon: 'error', title: 'Error', text: 'Server error.' }); },
        complete: function () { $btn.prop('disabled', false).html(orig); }
    });
});

function deleteVoucher(id) {
    Swal.fire({
        title: 'Delete Voucher?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('/api/account/delete_voucher.php', { id: id }, res => {
                if (res.success) showActionSuccess(res.message);
                else Swal.fire('Error', res.message, 'error');
            }, 'json');
        }
    });
}

function printPurchaseOrder(id) {
    const url = '<?= getUrl("print-purchase-order") ?>?id=' + id;
    window.open(url, '_blank');
}

function generateProgressReport() {
    logReportAction('Generated Project Progress Report', 'User generated a professional progress report for project ID: ' + projectId);
    Swal.fire({
        title: 'Generating Report...',
        text: 'Please wait while we prepare your professional progress analysis report',
        icon: 'info',
        showConfirmButton: false,
        timer: 1500
    }).then(() => {
        const reportUrl = '<?= getUrl("app/bms/operations/project_progress_report.php") ?>?id=' + projectId;
        window.open(reportUrl, '_blank');
    });
}

function generateBudgetReport() {
    logReportAction('Generated Budget Analysis Report', 'User generated a professional budget analysis report for project ID: ' + projectId);
    Swal.fire({
        title: 'Analyzing Budget...',
        text: 'Fetching category breakdowns and variance data',
        icon: 'info',
        showConfirmButton: false,
        timer: 1500
    }).then(() => {
        const reportUrl = '<?= getUrl("project-budget-report") ?>?id=' + projectId;
        window.open(reportUrl, '_blank');
    });
}

// ===== Payment Voucher (Project Modal) =====

// Amount → Words converter (simple TZS version)
function vcUpdateAmountWords(val) {
    const num = parseFloat(val) || 0;
    if (num <= 0) { $('#vc_amount_words').val(''); return; }
    const ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                  'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                  'Seventeen','Eighteen','Nineteen'];
    const tens = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    function words(n) {
        if (n === 0) return '';
        if (n < 20) return ones[n] + ' ';
        if (n < 100) return tens[Math.floor(n/10)] + (n%10 ? ' ' + ones[n%10] : '') + ' ';
        if (n < 1000) return ones[Math.floor(n/100)] + ' Hundred ' + words(n%100);
        if (n < 1000000) return words(Math.floor(n/1000)) + 'Thousand ' + words(n%1000);
        if (n < 1000000000) return words(Math.floor(n/1000000)) + 'Million ' + words(n%1000000);
        return words(Math.floor(n/1000000000)) + 'Billion ' + words(n%1000000000);
    }
    const intPart = Math.floor(num);
    const cents = Math.round((num - intPart) * 100);
    let result = words(intPart).trim();
    if (cents > 0) result += ' and ' + words(cents).trim() + ' Cents';
    $('#vc_amount_words').val(result + ' Shillings Only');
}

// ===== PROGRESS & MILESTONES LOGIC =====

let currentPerformanceFilter = 'daily';

function openMilestonesTab() {
    // Use Bootstrap 5 Tab API on the hidden trigger inside the real nav list
    const triggerEl = document.getElementById('trigger-milestones');
    bootstrap.Tab.getOrCreateInstance(triggerEl).show();
    // Collapse any open dropdown
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    document.querySelectorAll('.dropdown-toggle[aria-expanded="true"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
    loadMilestones();
    setTimeout(updatePrintBtnVisibility, 100);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openReportingTab() {
    const triggerEl = document.getElementById('trigger-reporting');
    bootstrap.Tab.getOrCreateInstance(triggerEl).show();
    // Collapse any open dropdown
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    document.querySelectorAll('.dropdown-toggle[aria-expanded="true"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
    loadMilestones(() => {
        loadReportingData();
    });
    setTimeout(updatePrintBtnVisibility, 100);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function openPerformanceTab() {
    const triggerEl = document.getElementById('trigger-performance');
    bootstrap.Tab.getOrCreateInstance(triggerEl).show();
    // Collapse any open dropdown
    document.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    document.querySelectorAll('.dropdown-toggle[aria-expanded="true"]').forEach(t => t.setAttribute('aria-expanded', 'false'));
    updatePerformanceFilterUI(); // Initialize filters before milestones load
    loadMilestones(); 
    setTimeout(updatePrintBtnVisibility, 100);
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

let milestoneMaxId = 0;

function addNewMilestoneRow(data = null, parentId = '', level = 0) {
    const tbody = $('#milestonesTable tbody');
    milestoneMaxId++;
    const rowId = `milestone_row_${milestoneMaxId}`;
    
    const row = `
        <tr class="milestone-row" id="${rowId}" data-parent="${parentId}" data-level="${level}" data-db-id="">
            <td class="ps-4 text-center fw-bold text-muted milestone-id-cell">-</td>
            <td style="padding-left: ${level * 30 + 15}px !important;">
                <div class="d-flex align-items-center">
                    <button class="btn btn-sm p-0 border-0 me-1 toggle-milestone-subtasks d-print-none"
                            onclick="toggleMilestoneSubtasks('${rowId}')"
                            style="visibility: hidden; width: 20px; outline: none !important; box-shadow: none !important;">
                        <i class="bi bi-caret-down-fill text-muted"></i>
                    </button>
                    <textarea class="form-control form-control-sm m-desc ${level === 0 ? 'fw-bold text-dark' : ''}"
                              style="${level === 0 ? 'font-weight: 800 !important;' : ''} rows: 1; min-height: 31px; resize: none; overflow: hidden;"
                              placeholder="e.g. Concrete Casting"
                              oninput="this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'">${data ? data.description : ''}</textarea>
                </div>
            </td>
            <td class="text-center"><input type="text" class="form-control form-control-sm m-unit text-center ${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}" value="${data ? data.unit : ''}" placeholder="e.g. m3"></td>
            <td class="text-center"><input type="number" step="0.01" class="form-control form-control-sm m-scope text-center ${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}" value="${data ? data.scope : ''}" placeholder="Qty" oninput="calculateMilestoneScopes()"></td>
            <td class="text-center">
                <div class="position-relative">
                    <input type="number" step="0.01" class="form-control form-control-sm m-weight text-center ${level === 0 ? 'fw-bold text-dark' : ''} ${data && data.has_children ? 'bg-light' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}" value="${data ? parseFloat(data.weight_percent).toFixed(2) : ''}" placeholder="%" oninput="calculateMilestoneScopes()" onchange="updateMilestoneTotalWeight()" ${data && data.has_children ? 'readonly' : ''}>
                </div>
            </td>
            <td class="text-end pe-4 d-print-none">
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle border shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-strategy="fixed">
                        <i class="bi bi-gear me-1"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 170px; border-radius: 10px;">
                        <li>
                            <a href="javascript:void(0)" class="dropdown-item py-2" onclick="editMilestoneRow('${rowId}')">
                                <i class="bi bi-pencil text-info me-2"></i> Edit
                            </a>
                        </li>
                        <li>
                            <a href="javascript:void(0)" class="dropdown-item py-2" onclick="addNewMilestoneRow(null, '${rowId}', ${level + 1})">
                                <i class="bi bi-plus-circle text-primary me-2"></i> Add Sub-Milestone
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a href="javascript:void(0)" class="dropdown-item py-2 text-danger" onclick="removeMilestoneRow('${rowId}')">
                                <i class="bi bi-trash me-2"></i> Delete
                            </a>
                        </li>
                    </ul>
                </div>
            </td>
        </tr>
    `;

    if (parentId === '') {
        tbody.append(row);
    } else {
        const $parent = $(`#${parentId}`);
        // Insert after last descendant of parent
        let $lastDescendant = $parent;
        function findLastDescendant(pid) {
            const $children = $(`.milestone-row[data-parent="${pid}"]`);
            if ($children.length > 0) {
                $lastDescendant = $children.last();
                findLastDescendant($lastDescendant.attr('id'));
            }
        }
        findLastDescendant(parentId);
        $lastDescendant.after(row);
        
        // Update parent toggle visibility
        $parent.find('.toggle-milestone-subtasks').css('visibility', 'visible');
    }

    reindexMilestones();
    calculateMilestoneScopes();
    updateMilestoneTotalWeight();
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('milestonesTable');
}

function removeMilestoneRow(rowId) {
    const $row = $(`#${rowId}`);
    const parentId = $row.data('parent');
    
    // Remove all descendants
    function removeDescendants(pid) {
        $(`.milestone-row[data-parent="${pid}"]`).each(function() {
            removeDescendants($(this).attr('id'));
            $(this).remove();
        });
    }
    removeDescendants(rowId);
    $row.remove();

    // Update parent toggle if no children left
    if (parentId) {
        const hasChildren = $(`.milestone-row[data-parent="${parentId}"]`).length > 0;
        if (!hasChildren) {
            $(`#${parentId}`).find('.toggle-milestone-subtasks').css('visibility', 'hidden');
        }
    }

    reindexMilestones();
    calculateMilestoneScopes();
    updateMilestoneTotalWeight();
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('milestonesTable');
}

function editMilestoneRow(rowId) {
    const $row = $(`#${rowId}`);

    // On mobile use the edit modal (table is hidden; inline fields are inaccessible)
    if (window.innerWidth < 768) {
        const isParent = $row.find('.m-weight').data('has-children') === true ||
                         $row.find('.m-weight').attr('data-has-children') === 'true';

        document.getElementById('msEditRowId').value  = rowId;
        document.getElementById('msEditDesc').value   = $row.find('.m-desc').val() || '';
        document.getElementById('msEditUnit').value   = $row.find('.m-unit').val() || '';
        document.getElementById('msEditScope').value  = $row.find('.m-scope').val() || '';
        document.getElementById('msEditWeight').value = $row.find('.m-weight').val() || '';

        // Hide weight field for parent rows (auto-calculated from children)
        document.getElementById('msEditWeightGroup').style.display = isParent ? 'none' : '';

        new bootstrap.Modal(document.getElementById('milestoneEditModal')).show();
        return;
    }

    // Desktop: inline edit inside the table row
    $row.find('.m-desc').removeAttr('readonly')
        .attr('oninput', "this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'");

    $row.find('.m-unit').removeAttr('readonly');
    $row.find('.m-scope').removeAttr('readonly')
        .attr('oninput', 'calculateMilestoneScopes()');

    const $weight = $row.find('.m-weight');
    if ($weight.data('has-children') !== true && $weight.attr('data-has-children') !== 'true') {
        $weight.removeAttr('readonly')
               .attr('oninput', 'calculateMilestoneScopes()')
               .attr('onchange', 'updateMilestoneTotalWeight()');
    }

    $row[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    $row.addClass('table-warning');
    setTimeout(() => $row.removeClass('table-warning'), 1500);
    $row.find('.m-desc').first().focus().select();
}

function saveMilestoneEditModal() {
    const rowId = document.getElementById('msEditRowId').value;
    if (!rowId) return;

    const $row = $(`#${rowId}`);

    // Write modal values back to the hidden table row fields
    $row.find('.m-desc').val(document.getElementById('msEditDesc').value);
    $row.find('.m-unit').val(document.getElementById('msEditUnit').value);
    $row.find('.m-scope').val(document.getElementById('msEditScope').value).attr('oninput', 'calculateMilestoneScopes()');

    const $weight = $row.find('.m-weight');
    const isParent = $weight.data('has-children') === true || $weight.attr('data-has-children') === 'true';
    if (!isParent) {
        $weight.val(document.getElementById('msEditWeight').value)
               .attr('oninput', 'calculateMilestoneScopes()')
               .attr('onchange', 'updateMilestoneTotalWeight()');
    }

    // Recalculate totals
    calculateMilestoneScopes();
    updateMilestoneTotalWeight();

    // Refresh card view
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('milestonesTable');

    bootstrap.Modal.getInstance(document.getElementById('milestoneEditModal')).hide();
}

function toggleMilestoneSubtasks(rowId) {
    const $icon = $(`#${rowId}`).find('.toggle-milestone-subtasks i');
    const isCollapsed = $icon.hasClass('bi-caret-right-fill');

    if (isCollapsed) {
        $icon.removeClass('bi-caret-right-fill').addClass('bi-caret-down-fill');
        recursiveToggleMilestones(rowId, false);
    } else {
        $icon.removeClass('bi-caret-down-fill').addClass('bi-caret-right-fill');
        recursiveToggleMilestones(rowId, true);
    }
    reindexMilestones();
}

function recursiveToggleMilestones(parentId, hide) {
    $(`.milestone-row[data-parent="${parentId}"]`).each(function() {
        const childId = $(this).attr('id');
        if (hide) {
            $(this).hide();
            recursiveToggleMilestones(childId, true);
        } else {
            $(this).show();
            const $childIcon = $(this).find('.toggle-milestone-subtasks i');
            if (!$childIcon.hasClass('bi-caret-right-fill')) {
                recursiveToggleMilestones(childId, false);
            }
        }
    });
}

function reindexMilestones() {
    let count = 0;
    $('#milestonesTable tbody tr').each(function() {
        if ($(this).css('display') !== 'none') {
            count++;
            $(this).find('.milestone-id-cell').text(count);
        }
    });
}

function calculateMilestoneScopes() {
    // We need to work bottom-up
    const levels = [];
    $('.milestone-row').each(function() {
        const l = parseInt($(this).data('level')) || 0;
        if (!levels.includes(l)) levels.push(l);
    });
    levels.sort((a, b) => b - a); // Highest level first

    levels.forEach(l => {
        $(`.milestone-row[data-level="${l}"]`).each(function() {
            const rowId = $(this).attr('id');
            const $children = $(`.milestone-row[data-parent="${rowId}"]`);
            
            if ($children.length > 0) {
                let totalScope = 0;
                let totalWeight = 0;
                $children.each(function() {
                    totalScope += parseFloat($(this).find('.m-scope').val()) || 0;
                    totalWeight += parseFloat($(this).find('.m-weight').val()) || 0;
                });
                
                const avgWeight = totalWeight / $children.length;
                
                $(this).find('.m-scope').val(totalScope.toFixed(2)).prop('readonly', true).addClass('bg-light');
                $(this).find('.m-weight').val(avgWeight.toFixed(2)).prop('readonly', true).addClass('bg-light');
                
                // Also update the toggle visibility just in case
                $(this).find('.toggle-milestone-subtasks').css('visibility', 'visible');
            } else {
                $(this).find('.m-scope').prop('readonly', false).removeClass('bg-light');
                $(this).find('.m-weight').prop('readonly', false).removeClass('bg-light');
            }
        });
    });
}

function updateMilestoneTotalWeight() {
    // 1. Calculate main project total (Level 0)
    let mainTotal = 0;
    $('.milestone-row[data-level="0"]').each(function() {
        mainTotal += parseFloat($(this).find('.m-weight').val()) || 0;
    });
    
    $('#totalMilestoneWeight').text(mainTotal.toFixed(2) + '%').addClass('text-primary').removeClass('text-danger text-success');

    // Total nested totals logic removed as requested (indicators removed)
}

function saveMilestones() {
    const milestones = [];
    $('.milestone-row').each(function() {
        milestones.push({
            temp_id:        $(this).attr('id'),
            parent_temp_id: $(this).attr('data-parent'),
            db_id:          $(this).attr('data-db-id') || '',
            description:    $(this).find('.m-desc').val(),
            unit:           $(this).find('.m-unit').val(),
            scope:          $(this).find('.m-scope').val(),
            weight_percent: $(this).find('.m-weight').val()
        });
    });

    if (milestones.length === 0) {
        Swal.fire('Error', 'Please add at least one milestone', 'error');
        return;
    }

    $('#btnSaveMilestones').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Saving...');

    $.post(APP_URL + '/api/operations/save_milestones.php', {
        project_id: projectId,
        milestones: JSON.stringify(milestones)
    }, function(res) {
        if (res.success) {
            Swal.fire('Success', 'Milestones saved successfully!', 'success');
            loadMilestones();
            loadProjectDetails(); // Refresh overall progress
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json').always(() => {
        $('#btnSaveMilestones').prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i> Save All Milestones');
    });
}

function loadMilestones(callback = null) {
    $.getJSON(APP_URL + '/api/operations/get_milestones.php', { project_id: projectId }, function(res) {
        if (res.success) {
            const tbody = $('#milestonesTable tbody');
            tbody.empty();
            milestoneMaxId = 0;
            
            if (res.data.length > 0) {
                const milestoneMap = {};
                res.data.forEach(m => {
                    milestoneMap[m.id] = { ...m, children: [] };
                });
                
                const roots = [];
                res.data.forEach(m => {
                    if (m.parent_id && milestoneMap[m.parent_id]) {
                        milestoneMap[m.parent_id].children.push(milestoneMap[m.id]);
                    } else {
                        roots.push(milestoneMap[m.id]);
                    }
                });
                
                function renderMilestoneRecursive(m, parentId = '', level = 0) {
                    milestoneMaxId++;
                    const rowId = `milestone_row_${milestoneMaxId}`;
                    m.frontend_id = rowId;
                    
                    const row = `
                        <tr class="milestone-row" id="${rowId}" data-parent="${parentId}" data-level="${level}" data-db-id="${m.id}">
                            <td class="ps-4 text-center fw-bold text-muted milestone-id-cell">-</td>
                            <td style="padding-left: ${level * 30 + 15}px !important;">
                                <div class="d-flex align-items-center">
                                    <button class="btn btn-sm p-0 border-0 me-1 toggle-milestone-subtasks d-print-none"
                                            onclick="toggleMilestoneSubtasks('${rowId}')"
                                            style="visibility: ${m.children.length > 0 ? 'visible' : 'hidden'}; width: 20px; outline: none !important; box-shadow: none !important;">
                                        <i class="bi bi-caret-down-fill text-muted"></i>
                                    </button>
                                    <textarea class="form-control form-control-sm m-desc ${level === 0 ? 'fw-bold text-dark' : ''}"
                                              style="${level === 0 ? 'font-weight: 800 !important;' : ''} rows: 1; min-height: 33px; resize: none; overflow: hidden;"
                                              placeholder="e.g. Concrete Casting"
                                              readonly
                                              oninput="this.style.height = 'auto'; this.style.height = (this.scrollHeight) + 'px'">${m.description}</textarea>
                                </div>
                            </td>
                            <td class="text-center"><input type="text" class="form-control form-control-sm m-unit text-center ${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}" value="${m.unit}" placeholder="e.g. m3" readonly></td>
                            <td class="text-center"><input type="number" step="0.01" class="form-control form-control-sm m-scope text-center ${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}" value="${m.scope}" placeholder="Qty" readonly></td>
                            <td class="text-center">
                                <div class="position-relative">
                                    <input type="number" step="0.01" class="form-control form-control-sm m-weight text-center ${level === 0 ? 'fw-bold text-dark' : ''} ${m.children.length > 0 ? 'bg-light' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}" value="${parseFloat(m.weight_percent).toFixed(2)}" placeholder="%" readonly data-has-children="${m.children.length > 0}">
                                </div>
                            </td>
                            <td class="text-end pe-4 d-print-none">
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-primary dropdown-toggle border shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-strategy="fixed">
                                        <i class="bi bi-gear me-1"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="min-width: 170px; border-radius: 10px;">
                                        <li>
                                            <a href="javascript:void(0)" class="dropdown-item py-2" onclick="editMilestoneRow('${rowId}')">
                                                <i class="bi bi-pencil text-info me-2"></i> Edit
                                            </a>
                                        </li>
                                        <li>
                                            <a href="javascript:void(0)" class="dropdown-item py-2" onclick="addNewMilestoneRow(null, '${rowId}', ${level + 1})">
                                                <i class="bi bi-plus-circle text-primary me-2"></i> Add Sub-Milestone
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a href="javascript:void(0)" class="dropdown-item py-2 text-danger" onclick="removeMilestoneRow('${rowId}')">
                                                <i class="bi bi-trash me-2"></i> Delete
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    `;
                    tbody.append(row);
                    
                    if (m.children.length > 0) {
                        m.children.forEach(c => renderMilestoneRecursive(c, rowId, level + 1));
                    }
                }
                
                roots.forEach(r => renderMilestoneRecursive(r));
            } else {
                addNewMilestoneRow();
            }
            
            reindexMilestones();
            calculateMilestoneScopes();
            updateMilestoneTotalWeight();
            
            // Auto-resize all textareas to fit content on load
            document.querySelectorAll('.m-desc').forEach(textarea => {
                textarea.style.height = 'auto';
                textarea.style.height = (textarea.scrollHeight) + 'px';
            });

            if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('milestonesTable');

            if (callback) callback(res.data);
        }
    });
}

// --- Reporting Logic ---
function loadReportingData() {
    const date = $('#reportingReportDate').val();
    const $tbody = $('#reportingTable tbody');
    $tbody.html('<tr><td colspan="7" class="text-center py-4"><span class="spinner-border spinner-border-sm me-2 text-info"></span> Loading reporting data...</td></tr>');

    $.getJSON(APP_URL + '/api/operations/get_milestones.php', { project_id: projectId }, function(mRes) {
        if (mRes.success) {
            const rptParams = { project_id: projectId, type: 'daily', date: date };
            if (scMode) rptParams.sc_id = scId;
            $.getJSON(APP_URL + '/api/operations/get_progress_reports.php', rptParams, function(pRes) {
                renderReportingTable(mRes.data, pRes.data && pRes.data.length > 0 ? pRes.data[0] : null, pRes.cumulative_map);
                // Load existing comments and attachments for this day
                $('#newAttachmentsList').empty();
                if (pRes.data && pRes.data.length > 0) {
                    $('#reportingComment').val(pRes.data[0].comments || '');
                    renderSavedAttachments(pRes.data[0].attachments || []);
                } else {
                    $('#reportingComment').val('');
                    renderSavedAttachments([]);
                }
            });
        }
    });
}

function renderSavedAttachments(attachments) {
    const $list = $('#savedAttachmentsList');
    $list.empty();
    if (!attachments || attachments.length === 0) return;
    attachments.forEach(function(att) {
        const fileName = att.attachment_name || att.file_path.split('/').pop();
        $list.append(`
            <div class="saved-att-item border rounded p-2 mb-1 bg-light" data-att-id="${att.id}" data-removed="0">
                <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-file-earmark text-info fs-5 flex-shrink-0 mt-1"></i>
                    <span class="flex-grow-1 fw-semibold small" style="word-break:break-word; white-space:normal;">${fileName}</span>
                    <a href="${APP_URL}/${att.file_path}" target="_blank" class="btn btn-sm btn-outline-info py-0 px-2 flex-shrink-0">
                        <i class="bi bi-eye me-1"></i>View
                    </a>
                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 flex-shrink-0" onclick="removeSavedAttachment(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `);
    });
}

function removeSavedAttachment(btn) {
    const $item = $(btn).closest('.saved-att-item');
    $item.attr('data-removed', '1').addClass('opacity-50');
    $(btn).html('<i class="bi bi-arrow-counterclockwise"></i>').attr('onclick', 'restoreSavedAttachment(this)').removeClass('btn-outline-danger').addClass('btn-outline-secondary');
}

function restoreSavedAttachment(btn) {
    const $item = $(btn).closest('.saved-att-item');
    $item.attr('data-removed', '0').removeClass('opacity-50');
    $(btn).html('<i class="bi bi-trash"></i>').attr('onclick', 'removeSavedAttachment(this)').removeClass('btn-outline-secondary').addClass('btn-outline-danger');
}

function addReportingAttachmentRow() {
    const idx = Date.now();
    $('#newAttachmentsList').append(`
        <div class="new-att-row border rounded p-2 mb-2" id="newatt_${idx}">
            <div class="d-flex align-items-start gap-2 mb-1">
                <i class="bi bi-paperclip text-primary fs-5 flex-shrink-0 mt-1"></i>
                <input type="text" class="form-control form-control att-name-input"
                       placeholder="Attachment name (e.g. Site Progress Photo, Delivery Note)"
                       style="word-break:break-word; white-space:normal;">
                <button type="button" class="btn btn-sm btn-outline-danger flex-shrink-0" onclick="$('#newatt_${idx}').remove()" title="Remove">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div class="ps-4">
                <input type="file" class="form-control form-control-sm att-file-input" accept=".pdf,.jpg,.jpeg,.png">
            </div>
        </div>
    `);
}

function renderReportingTable(milestones, report = null, cumulativeMap = null) {
    const $tbody = $('#reportingTable tbody');
    $tbody.empty();

    if (milestones.length === 0) {
        $tbody.html('<tr><td colspan="7" class="text-center py-4 text-muted">No milestones defined. Please set milestones first.</td></tr>');
        return;
    }

    // Build hierarchy
    const milestoneMap = {};
    milestones.forEach(m => {
        const detailEntry = (report && report.details) ? report.details.find(d => d.milestone_id == m.id) : undefined;
        const savedToday = detailEntry !== undefined ? parseFloat(detailEntry.actual_value) : null;
        const totalSoFar = (cumulativeMap && cumulativeMap[m.id] != null) ? parseFloat(cumulativeMap[m.id]) : 0;
        const prevActual = totalSoFar - (savedToday ?? 0);

        milestoneMap[m.id] = { ...m, children: [], actual: savedToday ?? '', prevActual: prevActual };
    });

    const roots = [];
    milestones.forEach(m => {
        if (m.parent_id && milestoneMap[m.parent_id]) {
            milestoneMap[m.parent_id].children.push(milestoneMap[m.id]);
        } else {
            roots.push(milestoneMap[m.id]);
        }
    });

    // INTEL: Calculate recursive scopes for parents so progress calculation works upwards
    function calculateReportingScope(m) {
        if (m.children.length > 0) {
            let sumScope = 0;
            m.children.forEach(c => {
                sumScope += calculateReportingScope(c);
            });
            m.scope = sumScope;
            return sumScope;
        } else {
            return parseFloat(m.scope) || 0;
        }
    }
    roots.forEach(r => calculateReportingScope(r));

    function renderRow(m, level = 0, parentId = '', phaseWeight = 0) {
        const hasChildren = m.children.length > 0;
        const scope = parseFloat(m.scope) || 0;
        const currentWeight = (level === 0) ? (parseFloat(m.weight_percent) || 0) : phaseWeight;
        const rowId = `reporting_row_${m.id}`;

        const row = `
            <tr class="reporting-row ${level === 0 ? 'bg-light-subtle' : ''}" id="${rowId}" 
                data-id="${m.id}" data-parent="${parentId}" data-level="${level}" 
                data-scope="${scope}" data-weight="${currentWeight}" data-prev-actual="${m.prevActual}">
                <td class="ps-4 text-center text-muted small r-id-cell">-</td>
                <td style="padding-left: ${level * 30 + 15}px !important;">
                    <div class="d-flex align-items-center">
                        <button class="btn btn-sm p-0 border-0 me-1 toggle-reporting-subtasks d-print-none" 
                                onclick="toggleReportingSubtasks('${rowId}')" 
                                style="visibility: ${hasChildren ? 'visible' : 'hidden'}; width: 20px; outline: none !important; box-shadow: none !important;">
                            <i class="bi bi-caret-down-fill text-muted"></i>
                        </button>
                        <span class="${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800 !important; font-size: 1.05rem;' : ''}">${m.description}</span>
                    </div>
                </td>
                <td class="text-center"><span class="badge bg-light text-dark ${level === 0 ? 'fw-bold' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}">${m.unit}</span></td>
                <td class="r-scope-display text-center ${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}">${scope.toFixed(2)}</td>
                <td class="text-center">
                    <input type="number" step="0.01" class="form-control form-control-sm r-actual text-center border-info-subtle ${hasChildren ? 'bg-light' : ''} ${level === 0 ? 'fw-bold text-dark' : ''}" 
                           style="${level === 0 ? 'font-weight: 800 !important;' : ''}"
                           value="${m.actual}" 
                           ${hasChildren ? 'readonly' : ''}
                           placeholder="${hasChildren ? 'Sum of subs' : 'Qty done'}" 
                           oninput="updateReportingCalculations()">
                </td>
                <td class="text-center ${level === 0 ? 'fw-bold text-dark' : ''}" style="${level === 0 ? 'font-weight: 800 !important;' : ''}">${currentWeight.toFixed(2)}%</td>
                <td class="fw-bold r-progress text-center ${level === 0 ? 'text-info' : 'text-dark'}" style="${level === 0 ? 'font-size: 1.1rem; font-weight: 800 !important;' : ''}">0.00%</td>
            </tr>
        `;
        $tbody.append(row);
        m.children.forEach(c => renderRow(c, level + 1, rowId, currentWeight));
    }

    roots.forEach(r => renderRow(r));
    updateReportingCalculations();
    if (window.bmsMobileCards) window.bmsMobileCards.renderForTable('reportingTable');
}

function toggleReportingSubtasks(rowId) {
    const $icon = $(`#${rowId}`).find('.toggle-reporting-subtasks i');
    const isCollapsed = $icon.hasClass('bi-caret-right-fill');

    if (isCollapsed) {
        $icon.removeClass('bi-caret-right-fill').addClass('bi-caret-down-fill');
        recursiveToggleReporting(rowId, false);
    } else {
        $icon.removeClass('bi-caret-down-fill').addClass('bi-caret-right-fill');
        recursiveToggleReporting(rowId, true);
    }
    reindexReporting();
}

function recursiveToggleReporting(parentId, hide) {
    $(`.reporting-row[data-parent="${parentId}"]`).each(function() {
        const childId = $(this).attr('id');
        if (hide) {
            $(this).hide();
            recursiveToggleReporting(childId, true);
        } else {
            $(this).show();
            const $childIcon = $(this).find('.toggle-reporting-subtasks i');
            if (!$childIcon.hasClass('bi-caret-right-fill')) {
                recursiveToggleReporting(childId, false);
            }
        }
    });
}

function reindexReporting() {
    let count = 0;
    $('#reportingTable tbody tr').each(function() {
        if ($(this).css('display') !== 'none') {
            count++;
            $(this).find('.r-id-cell').text(count);
        }
    });
}

function updateReportingCalculations() {
    const levels = [];
    $('.reporting-row').each(function() {
        const l = parseInt($(this).data('level')) || 0;
        if (!levels.includes(l)) levels.push(l);
    });
    levels.sort((a, b) => b - a); // Bottom-up (from deep to shallow)

    let hasError = false;

    // Pass 1: Calculate Actuals and Progress % recursively
    levels.forEach(l => {
        $(`.reporting-row[data-level="${l}"]`).each(function() {
            const level = l;
            const $row = $(this);
            const weight = parseFloat($row.data('weight')) || 0;
            const scope = parseFloat($row.data('scope')) || 0;
            const rowId = $row.attr('id');
            const $children = $(`.reporting-row[data-parent="${rowId}"]`);
            const $progressCell = $row.find('.r-progress');
            
            let internalProg = 0; // 0-100%
            let sumWeightedProg = 0;
            let sumActual = 0;

            if ($children.length > 0) {
                // If it's a parent, sum up actuals from children
                $children.each(function() {
                    const childActual = parseFloat($(this).find('.r-actual').val()) || 0;
                    sumActual += childActual;
                    sumWeightedProg += parseFloat($(this).find('.r-progress').text()) || 0;
                });
                // Calculate parent progress based on its children (Weighted Cumulative)
                internalProg = $children.length > 0 ? (sumWeightedProg / $children.length) : 0;
                $row.find('.r-actual').val(sumActual > 0 ? sumActual.toFixed(2) : '');
            } else {
                const prevActual = parseFloat($row.data('prev-actual')) || 0;
                const actual = parseFloat($row.find('.r-actual').val()) || 0;
                // INTEL: Cumulative Progress = (History + Current Entry) / Scope * Phase Weight
                internalProg = (scope > 0) ? ((prevActual + actual) / scope) * weight : 0;
                sumActual = actual;
            }

            // UI: Display 0-100% for all levels to prevent "Not counting" confusion
            let displayVal = internalProg;
            let baseColor = (level === 0) ? 'text-info' : 'text-dark';

            $progressCell.text(displayVal.toFixed(2) + '%');

            if (internalProg > 100.01) {
                $progressCell.addClass('text-danger').removeClass(baseColor).find('small').remove();
                $progressCell.append('<br><small class="fw-bold">EXCEEDED!</small>');
                if ($children.length === 0) $row.find('.r-actual').addClass('is-invalid');
                hasError = true;
            } else {
                $progressCell.addClass(baseColor).removeClass('text-danger').find('small').remove();
                if ($children.length === 0) $row.find('.r-actual').removeClass('is-invalid');
            }
        });
    });

    // Pass 2: Grand Totals (based on Level 0 milestones weighted contribution)
    let grandTotalWeight = 0;
    let grandTotalProgress = 0;
    $('.reporting-row[data-level="0"]').each(function() {
        let weight = parseFloat($(this).data('weight')) || 0;
        let progText = $(this).find('.r-progress').text().split('%')[0];
        let prog = parseFloat(progText) || 0;
        grandTotalWeight += weight;
        grandTotalProgress += (prog * (weight / 100)); // Apply weight for the total project aggregate
    });

    const normalizedReportingProgress = grandTotalProgress; 
    $('#totalReportingWeight').text(grandTotalWeight.toFixed(2) + '%');
    $('#totalReportingProgress').text(normalizedReportingProgress.toFixed(2) + '%');

    // Intelligence Validation: Disable Save button if error exists
    if (hasError) {
        $('#btnSaveReporting').prop('disabled', true)
            .attr('title', 'Please fix exceeded scopes before submitting.')
            .removeClass('btn-info').addClass('btn-secondary');
    } else {
        $('#btnSaveReporting').prop('disabled', false)
            .removeAttr('title')
            .removeClass('btn-secondary').addClass('btn-info');
    }

    reindexReporting();
}


function saveDailyReporting() {
    const details = [];
    $('.reporting-row').each(function() {
        const val = $(this).find('.r-actual').val();
        if (val !== '') {
            const prog = $(this).find('.r-progress').text().split('%')[0];
            details.push({
                milestone_id: $(this).data('id'),
                actual_value: val,
                progress_percent: prog
            });
        }
    });

    const hasComment = $('#reportingComment').val().trim() !== '';
    const hasNewAttachments = $('#newAttachmentsList .new-att-row').length > 0;
    if (details.length === 0 && !hasComment && !hasNewAttachments) {
        Swal.fire('Notice', 'No data entered to save.', 'info');
        return;
    }

    $('#btnSaveReporting').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> Submitting...');

    const formData = new FormData();
    formData.append('project_id', projectId);
    if (scMode) formData.append('sc_id', scId);
    formData.append('report_date', $('#reportingReportDate').val());
    formData.append('report_type', 'daily');
    formData.append('details', JSON.stringify(details));
    formData.append('comments', $('#reportingComment').val());
    // Collect IDs of saved attachments the user marked for removal
    const removedIds = [];
    $('#savedAttachmentsList .saved-att-item[data-removed="1"]').each(function() {
        removedIds.push($(this).data('att-id'));
    });
    formData.append('removed_attachment_ids', JSON.stringify(removedIds));

    // Collect new attachment rows
    $('#newAttachmentsList .new-att-row').each(function() {
        const name = $(this).find('.att-name-input').val().trim();
        const file = $(this).find('.att-file-input')[0].files[0];
        if (file) {
            formData.append('attachment_names[]', name || file.name);
            formData.append('attachment_files[]', file, file.name);
        }
    });

    $.ajax({
        url: APP_URL + '/api/operations/save_progress_report.php',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        success: function(res) {
            if (res.success) {
                Swal.fire('Report Saved', 'Your daily progress report has been submitted successfully.', 'success');
                loadReportingData();
                if (typeof loadProjectDetails === 'function') loadProjectDetails();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'An unexpected error occurred. Please try again.', 'error');
        }
    }).always(() => {
        $('#btnSaveReporting').prop('disabled', false).html('<i class="bi bi-cloud-upload me-1"></i> Submit Daily Report');
    });
}

// --- Analysis/Reports Logic (Read Only) ---
// --- Analysis/Reports Logic (Read Only) ---
