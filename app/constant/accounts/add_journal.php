<?php
// This file is designed to be included as a modal in journals.php
// but can also function standalone if needed.
global $pdo, $pdo_accounts;

require_once ROOT_DIR . '/core/permissions.php';

// Phase 5b — partial is normally included by journals.php (which already
// gates), but a direct hit on this URL should still be denied.
if (!canView('journals')) {
    http_response_code(403);
    die("Access Denied");
}

?>

<!-- Add Compound Journal Entry Modal -->
<div class="modal fade" id="addJournalModal" tabindex="-1" aria-labelledby="addJournalModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addJournalModalLabel">
                    <i class="bi bi-plus-circle"></i> New Compound Journal Entry
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addJournalForm">
                <div class="modal-body">
                    <div id="add-journal-message" class="mb-3"></div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="entry_date" class="form-label">Entry Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="entry_date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="reference_number" class="form-label">Reference Number</label>
                            <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="Auto-generated if empty" value="JRNL-<?= date('YmdHis') ?>">
                        </div>
                        <div class="col-12 mb-3">
                            <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="description" name="description" required placeholder="Brief description of the transaction">
                        </div>
                    </div>

                    <!-- Debit Accounts Section -->
                    <div class="card mb-3 border-0 shadow-sm">
                        <div class="card-header" style="background-color: #eceaeaff; color: #0d6efd;">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-arrow-up-left"></i> DEBIT ACCOUNTS</h6>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addDebitRow()">
                                    <i class="bi bi-plus-circle"></i> Add Debit
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm" id="debitAccountsTable">
                                    <thead>
                                        <tr>
                                            <th>Account</th>
                                            <th>Amount</th>
                                            <th>Description</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="debitAccountsBody">
                                        <!-- Debit rows will be added here -->
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td class="text-end fw-bold">Total Debits:</td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm total-display total-debits-val" id="totalDebits" readonly value="0.00">
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Credit Accounts Section -->
                    <div class="card mb-3 border-0 shadow-sm">
                        <div class="card-header" style="background-color: #eceaeaff; color: #0d6efd;">
                            <div class="d-flex justify-content-between align-items-center">
                                <h6 class="mb-0 fw-bold"><i class="bi bi-arrow-up-right"></i> CREDIT ACCOUNTS</h6>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addCreditRow()">
                                    <i class="bi bi-plus-circle"></i> Add Credit
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm" id="creditAccountsTable">
                                    <thead>
                                        <tr>
                                            <th>Account</th>
                                            <th>Amount</th>
                                            <th>Description</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="creditAccountsBody">
                                        <!-- Credit rows will be added here -->
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td class="text-end fw-bold">Total Credits:</td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm total-display total-credits-val" id="totalCredits" readonly value="0.00">
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="saveJournalBtn" disabled>
                        <i class="bi bi-check-circle"></i> Save Journal Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Select2 CSS -->
<link href="/assets/css/select2.min.css" rel="stylesheet" />
<link href="/assets/css/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<!-- Select2 JS -->
<script src="/assets/js/select2.min.js"></script>

<style>
/* Select2 Modal Fix */
/* Only apply high z-index to select2 containers when inside a modal to show over the modal backdrop */
.modal .select2-container--bootstrap-5.select2-container--open {
    z-index: 1060 !important; /* Above Bootstrap modal (1055) */
}
.select2-selection--single {
    height: 38px !important;
    padding-top: 4px !important;
    font-size: 0.875rem !important;
}
.select2-selection__rendered {
    line-height: 30px !important;
}
.select2-selection__arrow {
    height: 36px !important;
}

/* Ensure select2 dropdowns within modals are handled correctly by the library's dropdownParent option */
.modal-body {
    position: relative;
}

/* Table Widths */
#debitAccountsTable th:nth-child(1), #creditAccountsTable th:nth-child(1) { width: 25%; }
#debitAccountsTable th:nth-child(2), #creditAccountsTable th:nth-child(2) { width: 25%; }
#debitAccountsTable th:nth-child(3), #creditAccountsTable th:nth-child(3) { width: 45%; }
#debitAccountsTable th:nth-child(4), #creditAccountsTable th:nth-child(4) { width: 5%; }

/* Totals Styling */
.total-display {
    font-family: 'Courier New', Courier, monospace;
    font-weight: bold;
    font-size: 1.1rem;
    padding: 5px 10px;
    border-radius: 4px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    text-align: right;
}
.total-debits-val { color: #842029; } /* Muted dark red */
.total-credits-val { color: #0f5132; } /* Muted dark green */
</style>

<script>

function initSelect2(element) {
    $(element).select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#addJournalModal'),
        width: '100%',
        placeholder: 'Search for Account...',
        allowClear: true,
        dropdownAutoWidth: true,
        minimumInputLength: 1, // Start searching after 1 letter
        ajax: {
            url: '<?= getUrl("api/search_accounts") ?>',
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    q: params.term // Send search term
                };
            },
            processResults: function(data) {
                // The backend limits to 20 results automatically
                return {
                    results: data.results 
                };
            },
            cache: true
        }
    });
}

function addDebitRow() {
    const tbody = document.getElementById('debitAccountsBody');
    const row = document.createElement('tr');
    // Note the class 'account-select-ajax' and the empty options
    row.innerHTML = `
        <td>
            <select class="form-select form-select-sm account-select-ajax" name="debit_accounts[]" required>
                <option value="">Select Account</option>
            </select>
        </td>
        <td>
            <input type="number" step="0.01" class="form-control form-control-sm debit-amount" name="debit_amounts[]" required onchange="calculateJournalTotals()" onkeyup="calculateJournalTotals()">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="debit_descriptions[]" placeholder="Optional">
        </td>
        <td>
            <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="this.closest('tr').remove(); calculateJournalTotals();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
    initSelect2(row.querySelector('.account-select-ajax'));
}


function addCreditRow() {
    const tbody = document.getElementById('creditAccountsBody');
    const row = document.createElement('tr');
    // Note the class 'account-select-ajax' and the empty options
    row.innerHTML = `
        <td>
            <select class="form-select form-select-sm account-select-ajax" name="credit_accounts[]" required>
                <option value="">Select Account</option>
            </select>
        </td>
        <td>
            <input type="number" step="0.01" class="form-control form-control-sm credit-amount" name="credit_amounts[]" required onchange="calculateJournalTotals()" onkeyup="calculateJournalTotals()">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm" name="credit_descriptions[]" placeholder="Optional">
        </td>
        <td>
            <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="this.closest('tr').remove(); calculateJournalTotals();">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(row);
    initSelect2(row.querySelector('.account-select-ajax'));
}

function calculateJournalTotals() {
    let totalDebits = 0;
    document.querySelectorAll('.debit-amount').forEach(input => {
        totalDebits += parseFloat(input.value || 0);
    });
    
    let totalCredits = 0;
    document.querySelectorAll('.credit-amount').forEach(input => {
        totalCredits += parseFloat(input.value || 0);
    });
    
    document.getElementById('totalDebits').value = totalDebits.toFixed(2);
    document.getElementById('totalCredits').value = totalCredits.toFixed(2);
    
    const saveBtn = document.getElementById('saveJournalBtn');
    const diff = Math.abs(totalDebits - totalCredits);
    
    if (diff < 0.01 && totalDebits > 0) {
        saveBtn.disabled = false;
        document.getElementById('totalDebits').classList.remove('text-danger');
        document.getElementById('totalDebits').classList.add('text-success');
        document.getElementById('totalCredits').classList.remove('text-danger');
        document.getElementById('totalCredits').classList.add('text-success');
    } else {
        saveBtn.disabled = true;
        document.getElementById('totalDebits').classList.add('text-danger');
        document.getElementById('totalDebits').classList.remove('text-success');
        document.getElementById('totalCredits').classList.add('text-danger');
        document.getElementById('totalCredits').classList.remove('text-success');
    }
}

// Initial rows
document.addEventListener('DOMContentLoaded', function() {
    // Wait for modal to be shown to initialize Select2 if needed, 
    // but here we add rows which will initialize themselves.
    $('#addJournalModal').on('shown.bs.modal', function () {
        if (document.getElementById('debitAccountsBody').children.length === 0) {
            addDebitRow();
        }
        if (document.getElementById('creditAccountsBody').children.length === 0) {
            addCreditRow();
        }
    });
});

$(document).ready(function() {
    $('#addJournalForm').on('submit', function(e) {
        e.preventDefault();
        
        const saveBtn = $('#saveJournalBtn');
        saveBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');
        
        const formData = new FormData(this);
        
        $.ajax({
            url: '<?= getUrl("api/save_journal") ?>',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    logReportAction('Created Journal Entry', 'User created a new journal entry via modal');
                    $('#add-journal-message').html('<div class="alert alert-success">' + response.message + '</div>');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    $('#add-journal-message').html('<div class="alert alert-danger">' + response.message + '</div>');
                    saveBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Journal Entry');
                }
            },
            error: function() {
                $('#add-journal-message').html('<div class="alert alert-danger">Server error occurred</div>');
                saveBtn.prop('disabled', false).html('<i class="bi bi-check-circle"></i> Save Journal Entry');
            }
        });
    });
});
</script>
