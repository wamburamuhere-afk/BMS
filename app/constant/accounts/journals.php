<?php
// Start the buffer
ob_start();

// Include roots configuration
require_once __DIR__ . '/../../../roots.php';

// Include the header and authentication
// Phase 5b — supply explicit page-key; argless call was ineffective.
autoEnforcePermission('journals');

includeHeader();

// Helper function to safely output values
function safe_output($value, $default = 'N/A') {
    return !empty($value) ? htmlspecialchars($value) : $default;
}
?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="bi bi-journal-bookmark"></i> General Journal</h2>
                    <p class="text-muted mb-0">Manage compound journal entries with multiple accounts</p>
                </div>
                <div>
                    <?php if (canCreate('journals')): ?>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addJournalModal">
                        <i class="bi bi-plus-circle"></i> New Compound Entry
                    </button>
                    <?php endif; ?>
                    <a href="<?= getUrl('api/export_journals') ?>" class="btn btn-success btn-sm" onclick="logReportAction('Exported Journals', 'User exported journal entries to Excel/CSV')">
                        <i class="bi bi-download"></i> Export
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-debits">0.00</h4>
                            <p class="mb-0">Total Debits</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-arrow-up-left" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-total-credits">0.00</h4>
                            <p class="mb-0">Total Credits</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-arrow-up-right" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-month-debits">0.00</h4>
                            <p class="mb-0">This Month</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-calendar-month" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card custom-stat-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="mb-0" id="stat-entry-count">0</h4>
                            <p class="mb-0">Journal Entries</p>
                        </div>
                        <div class="align-self-center">
                            <i class="bi bi-journal-text" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Balance Check Alert -->
    <div id="balance-alert-container"></div>

    <!-- Filters Card -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h6 class="mb-0"><i class="bi bi-funnel"></i> Filters & Search</h6>
        </div>
        <div class="card-body">
            <form id="filterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Account</label>
                        <select class="form-select" id="accountFilter" style="width: 100%;">
                            <option></option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="draft">Draft</option>
                            <option value="posted">Posted</option>
                            <option value="void">Void</option>
                            <option value="reversed">Reversed</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" id="dateFromFilter">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" id="dateToFilter">
                    </div>
                    <div class="col-md-12 d-flex justify-content-end">
                        <button type="button" class="btn btn-outline-secondary me-2" onclick="clearFilters()">
                            <i class="bi bi-arrow-clockwise"></i> Clear
                        </button>
                        <button type="button" class="btn btn-primary" onclick="applyFilters()">
                            <i class="bi bi-filter"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Journal Entries Table -->
    <div class="card">
        <div class="card-header custom-table-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Compound Journal Entries</h5>
                <div class="d-flex">
                    <span class="badge bg-light text-dark me-2" id="stat-records-filtered">
                        0 entries
                    </span>
                    <span class="badge" id="stat-balanced-badge">
                        Balanced: Checking...
                    </span>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div id="form-message" class="mb-3"></div>
            
            <div class="table-responsive">
                <table id="journalsTable" class="table table-hover align-middle" style="width:100%">
                    <thead class="bg-light text-muted small uppercase">
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Accounts</th>
                            <th>Debits</th>
                            <th>Credits</th>
                            <th>Ref #</th>
                            <th>Status</th>
                            <th>Created By</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Scripts Section -->
<!-- DataTables and Select2 initialized via global header -->

<script>
$(document).ready(function() {
    const userPermissions = {
        canEdit: <?= canEdit('journals') ? 'true' : 'false' ?>,
        canDelete: <?= canDelete('journals') ? 'true' : 'false' ?>
    };

    // Log page view
    logReportAction('Viewed Journals List', 'User viewed the general journal entries list');

    // Initialize Select2 for Account Filter
    $('#accountFilter').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Search for Account...',
        allowClear: true,
        dropdownParent: $('#filterForm'), // Scopes the dropdown to the filter card
        ajax: {
            url: '<?= getUrl("api/search_accounts") ?>',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term }),
            processResults: data => ({ results: data.results }),
            cache: true
        }
    });

    // Close any open Select2 when a modal opens to prevent UI layering issues
    $(document).on('show.bs.modal', '.modal', function() {
        $('#accountFilter').select2('close');
    });

    const table = $('#journalsTable').DataTable({
        responsive: true,
        serverSide: true,
        processing: true,
        ajax: {
            url: '<?= getUrl("api/get_journals") ?>',
            data: d => {
                d.account_id = $('#accountFilter').val();
                d.status = $('#statusFilter').val();
                d.date_from = $('#dateFromFilter').val();
                d.date_to = $('#dateToFilter').val();
            },
            dataSrc: json => {
                const stats = json.stats;
                $('#stat-total-debits').text(formatCurrency(stats.totalDebits));
                $('#stat-total-credits').text(formatCurrency(stats.totalCredits));
                $('#stat-month-debits').text(formatCurrency(stats.monthDebits));
                $('#stat-entry-count').text(stats.entryCount);
                $('#stat-records-filtered').text(json.recordsFiltered + ' entries');

                const balanced = Math.abs(stats.totalDebits - stats.totalCredits) < 0.01;
                const badge = $('#stat-balanced-badge');
                badge.removeClass('bg-success bg-danger').addClass(balanced ? 'bg-success' : 'bg-danger');
                badge.text('Balanced: ' + (balanced ? 'Yes' : 'No'));

                if (!balanced) {
                    const diff = Math.abs(stats.totalDebits - stats.totalCredits);
                    $('#balance-alert-container').html(`<div class="alert alert-danger d-flex align-items-center" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2" style="font-size: 1.5rem;"></i>
                        <div>
                            <h5 class="alert-heading mb-1">Accounting Imbalance Detected!</h5>
                            <p class="mb-0">Debits (${formatCurrency(stats.totalDebits)}) and Credits (${formatCurrency(stats.totalCredits)}) do not match. Difference: <strong>${formatCurrency(diff)}</strong></p>
                        </div>
                    </div>`);
                } else {
                    $('#balance-alert-container').empty();
                }

                return json.data;
            }
        },
        columns: [
            { 
                data: 'entry_date',
                render: data => `<strong>${new Date(data).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'})}</strong>`
            },
            { 
                data: 'description',
                render: (data, t, row) => `<div><strong>${escapeHtml(data)}</strong>${row.notes ? `<br><small class="text-muted text-truncate d-inline-block" style="max-width:200px">${escapeHtml(row.notes)}</small>` : ''}<br><small class="text-muted"><i class="bi bi-diagram-3"></i> ${row.item_count} account${row.item_count > 1 ? 's' : ''}</small></div>`
            },
            { 
                data: 'items',
                orderable: false,
                render: items => {
                    let debit_count = 0, credit_count = 0;
                    items.forEach(it => it.type === 'debit' ? debit_count++ : credit_count++);
                    
                    let html = `<div class="small">
                        <span class="badge bg-danger me-1">${debit_count} Debit${debit_count !== 1 ? 's' : ''}</span>
                        <span class="badge bg-success">${credit_count} Credit${credit_count !== 1 ? 's' : ''}</span>`;
                    
                    items.slice(0, 3).forEach(it => {
                        html += `<div class="mt-1"><small><span class="badge bg-${it.type === 'debit' ? 'danger' : 'success'}">${it.type.charAt(0).toUpperCase()}</span> ${escapeHtml(it.account_name)}</small></div>`;
                    });
                    
                    if (items.length > 3) html += `<small class="text-muted">+${items.length - 3} more accounts</small>`;
                    html += `</div>`;
                    return html;
                }
            },
            { 
                data: 'total_debits',
                className: 'text-end',
                render: data => `<strong class="text-danger">${formatCurrency(data)}</strong>`
            },
            { 
                data: 'total_credits',
                className: 'text-end',
                render: data => `<strong class="text-success">${formatCurrency(data)}</strong>`
            },
            { 
                data: 'reference_number',
                render: data => data ? `<code class="custom-code">${escapeHtml(data)}</code>` : '<span class="text-muted small">N/A</span>'
            },
            { 
                data: 'status',
                render: data => `<span class="badge bg-${getStatusBadgeClass(data)}">${data.charAt(0).toUpperCase() + data.slice(1)}</span>`
            },
            { 
                data: 'created_by_name',
                render: (data, t, row) => `${escapeHtml(data)}<br><small class="text-muted">${new Date(row.created_at).toLocaleDateString('en-US', {month:'short', day:'numeric'})}</small>`
            },
            {
                data: null,
                orderable: false,
                className: 'text-end',
                render: (data, t, row) => {
                    let html = `<div class="dropdown action-dropdown">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-gear"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= getUrl('journal/view') ?>?id=${row.entry_id}"><i class="bi bi-eye"></i> View Details</a></li>`;
                    
                    if (userPermissions.canEdit) {
                        html += `<li><a class="dropdown-item" href="<?= getUrl('journal/edit') ?>?id=${row.entry_id}" onclick="logReportAction('Initiated Journal Edit', 'User clicked edit for journal entry #${row.entry_id}')"><i class="bi bi-pencil"></i> Edit Entry</a></li>`;
                        if (row.status === 'draft') {
                            html += `<li><a class="dropdown-item" href="#" onclick="updateStatus(${row.entry_id}, 'posted')"><i class="bi bi-check-circle"></i> Post Entry</a></li>`;
                        } else if (row.status === 'posted') {
                            html += `<li><a class="dropdown-item" href="#" onclick="reverseJournal(${row.entry_id})"><i class="bi bi-arrow-counterclockwise"></i> Reverse Entry</a></li>`;
                        }
                        if (row.status !== 'void') {
                            html += `<li><a class="dropdown-item" href="#" onclick="voidJournal(${row.entry_id})"><i class="bi bi-x-circle"></i> Void Entry</a></li>`;
                        }
                    }
                    
                    if (userPermissions.canDelete) {
                        html += `<li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(${row.entry_id})"><i class="bi bi-trash"></i> Delete</a></li>`;
                    }
                    
                    html += `</ul></div>`;
                    return html;
                }
            }
        ],
        dom: 'frtip',
    });
});

function applyFilters() { $('#journalsTable').DataTable().ajax.reload(); }
function clearFilters() {
    $('#accountFilter').val(null).trigger('change');
    $('#statusFilter').val('');
    $('#dateFromFilter, #dateToFilter').val('');
    $('#journalsTable').DataTable().ajax.reload();
}

function updateStatus(id, status) {
    if (!confirm('Update this entry status to ' + status + '?')) return;
    
    // Log the action
    logReportAction('Updated Journal Status', 'User updated journal entry #' + id + ' status to ' + status);

    $.post('<?= getUrl("api/update_journal_status") ?>', { entry_id: id, status: status }, response => {
        // Since update_journal_status.php might not return JSON or might redirect, we'll handle based on script behavior
        // Assuming it's updated to return JSON like others, or we check for success
        location.reload(); // Simple for now if API isn't fully JSON-ified
    });
}

function reverseJournal(id) {
    if (!confirm('Reverse this journal entry?')) return;
    logReportAction('Reversed Journal Entry', 'User reversed journal entry #' + id);
    $.post('<?= getUrl("api/reverse_journal") ?>', { entry_id: id }, () => location.reload());
}

function voidJournal(id) {
    if (!confirm('Void this journal entry?')) return;
    logReportAction('Voided Journal Entry', 'User voided journal entry #' + id);
    $.post('<?= getUrl("api/void_journal") ?>', { entry_id: id }, () => location.reload());
}

function confirmDelete(id) {
    if (!confirm('Permanently delete this journal entry?')) return;
    logReportAction('Deleted Journal Entry', 'User deleted journal entry #' + id);
    $.post('<?= getUrl("api/delete_journal") ?>', { entry_id: id }, () => location.reload());
}

function formatCurrency(v) { return parseFloat(v).toLocaleString('en-US', {minimumFractionDigits: 2}); }
function getStatusBadgeClass(s) {
    return s === 'posted' ? 'success' : s === 'draft' ? 'secondary' : s === 'void' ? 'danger' : s === 'reversed' ? 'warning' : 'secondary';
}
function escapeHtml(t) { return $('<div>').text(t).html(); }
</script>

<style>
.custom-stat-card {
    background-color: #d1e7dd !important;
    border-color: #badbcc !important;
    transition: transform 0.2s;
}
.custom-stat-card:hover { transform: translateY(-3px); }
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
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}
.action-dropdown .dropdown-item { padding: 0.5rem 1rem; }
.action-dropdown .dropdown-item i { margin-right: 0.5rem; }
.select2-container--bootstrap-5 .select2-selection { border-radius: 0.375rem; }
</style>

<?php
// Include the modal
include __DIR__ . '/add_journal.php';

// Include the footer
includeFooter();

// Flush the buffer
ob_end_flush();
?>
