function calculateScopeRow(input) {
    const row = $(input).closest('tr');
    const qty = parseFloat(row.find('.s-qty').val()) || 0;
    const amount = parseFloat(row.find('.s-amount').val()) || 0;
    const taxRate = parseFloat(row.find('.s-tax-rate').val()) || 0;
    
    const subtotal = qty * amount;
    const taxAmount = subtotal * (taxRate / 100);
    const total = subtotal + taxAmount;
    
    row.find('.s-total').val(total.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    
    // Determine which table this belongs to
    const targetTableId = row.closest('table').attr('id');
    let scopeType = targetTableId.replace('ScopeTable', '').replace('Table', '');
    updateScopeGrandTotal(scopeType);
}

function updateScopeGrandTotal(type) {
    let tableId = `${type}ScopeTable`;
    let totalDisplayId = `${type}ScopeTotal`;

    if (type === 'variation-history') {
        tableId = 'variationHistoryTable';
        totalDisplayId = 'variationHistoryTotal';
    }

    let grandTotal = 0;
    $(`#${tableId} tbody .scope-row`).each(function() {
        const rawQty = $(this).find('.s-qty').val();
        const rawAmount = $(this).find('.s-amount').val();
        const rawTaxRate = $(this).find('.s-tax-rate').val();
        const qty = parseFloat(rawQty) || 0;
        const amount = parseFloat(rawAmount) || 0;
        const taxRate = parseFloat(rawTaxRate) || 0;
        
        const subtotal = qty * amount;
        const taxAmount = subtotal * (taxRate / 100);
        grandTotal += (subtotal + taxAmount);
    });
    
    $(`#${totalDisplayId}`).text(grandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

    // Update global project summary if we're not in history view
    if (type !== 'variation-history') {
        refreshProjectGrandTotal();
    }
}

function refreshProjectGrandTotal() {
    $.getJSON(APP_URL + '/api/operations/get_scopes.php', { project_id: projectId, summary_only: true }, function(res) {
        if (res.success && res.summary) {
            const s = res.summary;
            const baseline = (s.revised > 0) ? s.revised : (s.original || 0);
            const variations = s.variation || 0;
            const additional = s.additional || 0;
            const absoluteGrandTotal = baseline + variations + additional;

            // Update Additional Scope tab totals
            $('#grandTotalBaseline').text(baseline.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            $('#grandTotalVariations').text(variations.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            $('#additionalScopeTotal').text(additional.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            $('#additionalScopeGrandTotal').text(absoluteGrandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

            // Comparison logic
            if (baseline > 0) {
                const increasePercent = ((absoluteGrandTotal - baseline) / baseline) * 100;
                $('#additionalScopeIncreasePercent').html(`
                    
                `);
            }

            // Contract Sum display is fixed to the value entered during project creation (form_contract_sum)
            const grandTotalStr = absoluteGrandTotal.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

            // Recalculate Budget Performance based on APPROVED budgets, NOT scope total
            if (projectData && projectData.financial_summary) {
                // IMPORTANT: Use the budget allocated from the Finance -> Budgets table
                const currentBudget = parseFloat(projectData.financial_summary.budget) || 0;
                const spent = parseFloat(projectData.financial_summary.total_expense) || 0;
                const remaining = currentBudget - spent;
                const utilization = currentBudget > 0 ? Math.round((spent / currentBudget) * 100) : 0;
                
                // Update Budget Cards
                $('#budgetDisplay').text(formatMoney(currentBudget) + ' TZS');
                $('#budgetAllocated').text(formatMoney(currentBudget) + ' TZS');
                
                const remainingClass = remaining >= 0 ? 'text-success' : 'text-danger';
                $('#budgetRemaining').text(formatMoney(remaining) + ' TZS')
                    .removeClass('text-success text-danger').addClass(remainingClass);
                
                const progressWidth = Math.min(utilization, 100);
                let progressColor = 'bg-success';
                let badgeColor = 'bg-success';
                if (utilization >= 90) { progressColor = 'bg-danger'; badgeColor = 'bg-danger'; }
                else if (utilization >= 75) { progressColor = 'bg-warning'; badgeColor = 'bg-warning'; }
                
                $('#budgetProgressBar').css('width', progressWidth + '%').removeClass('bg-success bg-warning bg-danger').addClass(progressColor);
                $('#budgetProgressText').text(utilization + '%');
                $('#utilizationBadge').text(utilization + '%').removeClass('bg-success bg-warning bg-danger').addClass(badgeColor);

                // Update Status Message
                let statusMessage = '';
                let statusIcon = 'bi-info-circle';
                let statusClass = 'alert-info';

                if (currentBudget === 0) {
                    statusMessage = 'ATTENTION: No approved budget was set for this project in Finance';
                    statusClass = 'alert-secondary';
                } else if (utilization > 100) {
                    statusMessage = `⚠️ Over budget by ${formatMoney(Math.abs(remaining))} TZS (${utilization - 100}% over)`;
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
            }
        }
    });
}


function updateSNCounters(tableId) {
    $(`#${tableId} tbody tr`).each(function(index) {
        $(this).find('.sn-counter').text(index + 1);
    });
}

function saveScope(type) {
    let tableId = `${type}ScopeTable`;
    let addendum_no = null;
    
    if (type === 'variation') {
        addendum_no = $('#variationAddendumSel').val();
    } else if (type === 'variation-history') {
        tableId = 'variationHistoryTable';
        addendum_no = $('#variationHistoryAddendumSel').val();
    }

    const items = [];
    $(`#${tableId} tbody .scope-row`).each(function() {
        const desc = $(this).find('.s-desc').val();
        if (desc.trim() !== '') {
            items.push({
                description: desc,
                unit: $(this).find('.s-unit').val(),
                scope: $(this).find('.s-qty').val(),
                amount: $(this).find('.s-amount').val(),
                tax_rate: $(this).find('.s-tax-rate').val(),
                tax_amount: (parseFloat($(this).find('.s-qty').val()) || 0) * (parseFloat($(this).find('.s-amount').val()) || 0) * ((parseFloat($(this).find('.s-tax-rate').val()) || 0) / 100)
            });
        }
    });

    if (items.length === 0) {
        Swal.fire('Notice', 'No items found to save in this table.', 'info');
        return;
    }


    Swal.fire({
        title: (type === 'original') ? 'Finalize Original Scope?' : 'Save changes?',
        text: (type === 'original') ? 'Warning: Original scope cannot be edited after finalizing.' : 'This will update the project scope records.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Save'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: APP_URL + '/api/operations/save_scopes.php',
                type: 'POST',
                data: {
                    project_id: projectId,
                    scope_type: (type === 'variation' || type === 'variation-history') ? 'variation' : type,
                    addendum_no: addendum_no,
                    items: JSON.stringify(items)
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        Swal.fire('Success', res.message, 'success');
                        if (type === 'variation') {
                            // After saving new Variation, stay at NEW Variation tab but increment NO and clear table
                            initVariationScope(); 
                        } else if (type === 'variation-history') {
                            loadScopes('variation-history');
                        } else {
                            loadScopes(type);
                        }
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

function loadScopes(type) {
    let addendum_no = null;
    if (type === 'variation') addendum_no = $('#variationAddendumSel').val();
    if (type === 'variation-history') addendum_no = $('#variationHistoryAddendumSel').val();

    $.ajax({
        url: APP_URL + '/api/operations/get_scopes.php',
        type: 'GET',
        data: { project_id: projectId, scope_type: (type === 'variation' || type === 'variation-history') ? 'variation' : type, addendum_no: addendum_no },
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                const tableId = (type === 'variation-history') ? 'variationHistoryTable' : `${type}ScopeTable`;
                const tbody = $(`#${tableId} tbody`);
                tbody.empty();
                
                const isLocked = (type === 'original' && res.data && res.data.length > 0);
                
                if (res.data && res.data.length > 0) {
                    res.data.forEach(item => addNewScopeRow(type, item, isLocked));
                } else if (type === 'revised') {
                    // Autoload original data into revised scope if revised is empty
                    $.getJSON(APP_URL + '/api/operations/get_scopes.php', { project_id: projectId, scope_type: 'original' }, function(origRes) {
                        if (origRes.success && origRes.data.length > 0) {
                            origRes.data.forEach(item => addNewScopeRow('revised', item, false));
                        }
                    });
                }

                // Auto-resize scope textareas
                setTimeout(() => {
                    $(`#${tableId} .s-desc, #${tableId} .s-unit`).each(function() {
                        this.style.height = 'auto';
                        this.style.height = (this.scrollHeight) + 'px';
                    });
                }, 100);
                
                if (isLocked) {
                    $('#btnAddOriginalScopeItem').hide();
                    $('#btnSaveOriginalScope').hide(); 
                    $(`#${tableId} thead th:last-child`).hide();
                    $(`#${tableId} tfoot td:last-child`).hide();
                } else if (type === 'original') {
                    $('#btnAddOriginalScopeItem').show();
                    $('#btnSaveOriginalScope').show().html('<i class="bi bi-save me-1"></i> Save Original Scope').prop('disabled', false).addClass('btn-primary').removeClass('btn-secondary');
                    $(`#${tableId} thead th:last-child`).show();
                    $(`#${tableId} tfoot td:last-child`).show();
                }

                updateScopeGrandTotal(type);
                
                // Update print header addendum number
                if (type === 'variation') {
                    $('#print-variation-no').text(addendum_no);
                } else if (type === 'variation-history') {
                    $('#print-variation-history-no').text(addendum_no);
                }
                
                // Load the signed document status
                const container = $(`#signedDocContainer-${type}`);
                const link = $(`#signedDocLink-${type}`);
                const attachBtn = $(`#attachDocBtn-${type}`);

                if (res.document) {
                    container.removeClass('d-none');
                    link.attr('href', APP_URL + '/' + res.document.file_path).text(res.document.file_name);
                    
                    // Update button to "View Document" as per user request
                    if (attachBtn.length) {
                        attachBtn.html('<i class="bi bi-file-earmark-text me-1"></i> View Document');
                        attachBtn.removeClass('btn-outline-info btn-outline-secondary btn-outline-primary').addClass('btn-info text-white shadow-sm');
                        attachBtn.attr('onclick', `window.open('${APP_URL}/${res.document.file_path}', '_blank')`);
                    }
                } else {
                    container.addClass('d-none');
                    
                    // Revert button to original "Attach" state
                    if (attachBtn.length) {
                        let originalText = 'Attach Signed';
                        let originalClass = 'btn-outline-info';
                        
                        if (type === 'variation') { originalText = 'Attach Signed Addendum'; originalClass = 'btn-outline-secondary'; }
                        else if (type === 'additional') { originalText = 'Attach Signed Copy'; originalClass = 'btn-outline-primary'; }
                        
                        attachBtn.html(`<i class="bi bi-paperclip me-1"></i> ${originalText}`);
                        attachBtn.removeClass('btn-info text-white shadow-sm').addClass(originalClass);
                        attachBtn.attr('onclick', `triggerScopeDocUpload('${type}')`);
                    }
                }
            }
        }
    });
}

function triggerScopeDocUpload(type) {
    $('#scopeDocUploadType').val(type);
    $('#scopeDocUploadAddendum').val((type === 'variation') ? $('#variationAddendumSel').val() : '');
    $('#scopeDocFileInput').click();
}

function handleScopeDocFileSelect() {
    const file = $('#scopeDocFileInput')[0].files[0];
    if (!file) return;

    const formData = new FormData($('#scopeDocUploadForm')[0]);
    
    Swal.fire({
        title: 'Uploading signed document...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: APP_URL + '/api/operations/save_scope_document.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res) {
            if (res.success) {
                Swal.fire('Success', res.message, 'success');
                loadScopes($('#scopeDocUploadType').val());
                loadProjectDetails(); // Refresh Docs Library tab with new document
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Upload failed.', 'error');
        }
    });
}

function deleteScopeDoc(type) {
    const addendum_no = (type === 'variation') ? $('#variationAddendumSel').val() : null;
    
    Swal.fire({
        title: 'Remove this document?',
        text: 'This action will permanently remove the link to the signed document.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/operations/delete_scope_document.php', {
                project_id: projectId,
                scope_type: type,
                addendum_no: addendum_no
            }, function(res) {
                if (res.success) {
                    Swal.fire('Deleted', res.message, 'success');
                    loadScopes(type);
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}


function initVariationScope() {
    // New Variation Tab: Determine the next sequential number and keep it EMPTY for new entry
    $.getJSON(APP_URL + '/api/operations/get_scopes.php', { project_id: projectId, scope_type: 'variation', meta_only: true }, function(res) {
        let nextNo = 1;
        if (res.used_nos && res.used_nos.length > 0) {
            nextNo = Math.max(...res.used_nos.map(Number)) + 1;
        }
        $('#variationAddendumSel').val(nextNo);
        $('#variationAddendumDisplay').html(`<i class="bi bi-hash me-1"></i> Addendum NO: ${nextNo}`);
        $('#print-variation-no').text(nextNo);
        
        // Manual clear of table
        $('#variationScopeTable tbody').empty();
        updateScopeGrandTotal('variation');
        $(`#signedDocContainer-variation`).addClass('d-none');
    });
}

function initVariationArchive() {
    // Archive Tab: Load the list of addendums and default to No: 1
    $.getJSON(APP_URL + '/api/operations/get_scopes.php', { project_id: projectId, scope_type: 'variation', meta_only: true }, function(res) {
        const selector = $('#addendumHistorySelector');
        selector.empty();
        
        if (res.used_nos && res.used_nos.length > 0) {
            res.used_nos.forEach(no => {
                const isActive = (no == $('#variationHistoryAddendumSel').val());
                selector.append(`
                    <button class="btn btn-sm ${isActive ? 'btn-primary text-white' : 'btn-outline-primary'} px-4 py-2 fw-bold" onclick="selectHistoryAddendum(${no})">
                        Addendum NO: ${no}
                    </button>
                `);
            });
            
            // If the current selection isn't in used_nos (e.g. first load), default to used_nos[0] or 1
            const current = $('#variationHistoryAddendumSel').val();
            if (!res.used_nos.includes(current.toString())) {
                selectHistoryAddendum(res.used_nos[0]);
            } else {
                loadScopes('variation-history');
            }
        } else {
            selector.html('<div class="p-3 text-muted bg-light rounded border w-100 text-center"><i class="bi bi-info-circle me-1"></i> No variation history found yet.</div>');
            $('#variationHistoryTable tbody').empty();
            updateScopeGrandTotal('variationHistory');
        }
    });
}

function selectHistoryAddendum(no) {
    $('#variationHistoryAddendumSel').val(no);
    $('#print-variation-history-no').text(no);
    initVariationArchive(); // Refresh selector UI classes
    loadScopes('variation-history');
}

function deleteAddendum() {
    const no = $('#variationHistoryAddendumSel').val();
    const displayNo = (no === '' || no === null) ? 'unspecified' : no;

    Swal.fire({
        title: 'Delete this Addendum?',
        text: `Are you sure you want to permanently delete Variation Addendum NO: ${displayNo}? This cannot be undone.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post(APP_URL + '/api/operations/delete_scope_addendum.php', {
                project_id: projectId,
                addendum_no: no
            }, function(res) {
                if (res.success) {
                    Swal.fire('Deleted!', res.message, 'success');
                    // Reset selection and refresh
                    $('#variationHistoryAddendumSel').val('1');
                    initVariationArchive();
                    refreshProjectGrandTotal(); // Update global totals
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            }, 'json');
        }
    });
}

// --- Global Print Button Visibility based on Active Tab ---
// Hide print button on Planning and Review tabs; show on all others.
function updatePrintBtnVisibility() {
    const hideTabs = ['planning', 'review', 'reporting', 'reports'];
    // Check which tab-pane is currently active (target the main workspace container)
    const activePaneId = $('#projectWorkspaceContent > .tab-pane.active').attr('id') || '';
    if (hideTabs.includes(activePaneId)) {
        $('#globalPrintBtn').addClass('d-none');
    } else {
        $('#globalPrintBtn').removeClass('d-none');
    }
}

// Listen for Bootstrap tab change events
$(document).on('shown.bs.tab', '[data-bs-toggle="tab"]', function () {
    updatePrintBtnVisibility();
});

// Also handle the Planning dropdown item which uses onclick, not tab events
$(document).on('click', '#planning-tab', function () {
    setTimeout(updatePrintBtnVisibility, 50);
});
$(document).on('click', '#review-tab-trigger', function () {
    setTimeout(updatePrintBtnVisibility, 50);
});
$(document).on('click', '#schedules-tab-trigger', function () {
    setTimeout(updatePrintBtnVisibility, 50);
});

// On page load, check once
$(function() {
    updatePrintBtnVisibility();
});

