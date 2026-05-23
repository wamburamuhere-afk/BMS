<?php
// Include roots configuration
require_once dirname(__DIR__, 3) . '/roots.php';

// Enforce permission BEFORE any output
autoEnforcePermission('payroll');

// Include the header
includeHeader();


$payroll_id = $_GET['id'] ?? null;
if (!$payroll_id) {
    header('Location: payroll.php');
    exit;
}
$page_title = 'Payroll Details';
?>

<style>
:root {
    --clean-bg: #ffffff;
    --soft-bg: #f8fafc;
    --border-color: #e2e8f0;
    --primary-blue: #0d6efd;
    --text-main: #1e293b;
    --text-muted: #64748b;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.1);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
}

.details-dashboard {
    background: var(--soft-bg);
    min-height: 100vh;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
}

.details-card {
    background: var(--clean-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
    transition: box-shadow 0.3s ease;
}

.details-card:hover {
    box-shadow: var(--shadow-md);
}

.info-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    font-weight: 700;
}

.info-value {
    color: var(--text-main);
    font-weight: 600;
}

.amount-card {
    border-radius: 12px;
    padding: 1.25rem;
    border: 1px solid var(--border-color);
}

.status-badge-lg {
    padding: 6px 16px;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.btn-clean-blue {
    background: var(--primary-blue);
    color: white;
    border: none;
    border-radius: 10px;
    padding: 12px 28px;
    font-weight: 600;
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
    transition: all 0.2s ease;
}

.btn-clean-blue:hover {
    background: #0b5ed7;
    transform: translateY(-1px);
    box-shadow: 0 6px 15px rgba(13, 110, 253, 0.3);
    color: white;
}

.btn-clean-white {
    background: white;
    color: #000;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 10px 24px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-clean-white:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #000;
}

.payout-banner {
    background: #f8fafc;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    padding: 2rem;
}

.payroll-section-header {
    font-size: 0.9rem;
    font-weight: 800;
    color: var(--text-main);
    border-bottom: 2px solid #f1f5f9;
    padding-bottom: 10px;
}

.audit-timeline {
    border-left: 2px dashed #e2e8f0;
}

@page { margin: 10mm 8mm 16mm 8mm; }
    @media print {
        #printHeader { display: block !important; margin-bottom: 20px; }
        .details-dashboard { background: #fff !important; padding: 0 !important; }
        .details-card { border: 1px solid #eee !important; box-shadow: none !important; }
        .btn, .d-print-none, .text-decoration-none { display: none !important; }
        body { background: #fff !important; }
        .container { width: 100% !important; max-width: none !important; }
        .payout-banner { border: 2px solid #000 !important; background: #f8fafc !important; -webkit-print-color-adjust: exact; }
        .payroll-section-header { border-bottom-color: #000 !important; }

    }
</style>

<div class="details-dashboard py-5 px-3">
    <div class="container">
        <!-- Professional Print Header -->
        <div class="print-header text-center mb-4" id="printHeader" style="display: none;">
            <?php 
            $c_name = get_setting('company_name', 'BMS');
            $c_logo = get_setting('company_logo', '');
            ?>
            <?php if(!empty($c_logo)): ?>
                <div class="mb-3">
                    <img src="<?= getUrl($c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
                </div>
            <?php endif; ?>
            <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;"><?= safe_output($c_name) ?></h1>
            <h2 style="color: #000; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">PAYROLL DISBURSEMENT REPORT</h2>
            <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
        </div>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
            <div>
                <a href="<?= getUrl('payroll') ?>" class="text-decoration-none text-muted mb-2 d-inline-block">
                    <i class="bi bi-arrow-left me-1"></i> Back to Payroll
                </a>
                <h2 class="fw-bold text-dark mb-0">Record Details</h2>
                <p class="text-muted" id="payroll_number_display">Loading record...</p>
            </div>
            <div class="d-flex gap-2">
                <a href="payslip?id=<?= $payroll_id ?>" target="_blank" class="btn btn-clean-white">
                    <i class="bi bi-printer me-2"></i>Print Slip
                </a>
                <div id="action_buttons"></div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Summary Info -->
            <div class="col-lg-8">
                <div class="details-card p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="d-flex align-items-center">
                                <div id="employee_avatar" class="avatar-lg rounded-circle bg-primary-soft text-primary d-flex align-items-center justify-content-center fw-bold me-3" style="width:64px; height:64px; font-size: 1.5rem; background: rgba(99,102,241,0.1)">
                                    --
                                </div>
                                <div>
                                    <h4 class="fw-bold mb-0" id="employee_name">--</h4>
                                    <p class="text-muted mb-0" id="designation_dept">--</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <div id="status_container"></div>
                            <p class="text-muted small mt-2 mb-0">Period: <span class="fw-bold text-dark" id="payroll_period">--</span></p>
                        </div>
                    </div>

                    <hr class="my-4 opacity-50">

                    <!-- Breakdown Table -->
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h6 class="payroll-section-header mb-3"><i class="bi bi-graph-up-arrow text-success me-2"></i>Earnings</h6>
                            <div class="p-2 mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted small">Basic Salary</span>
                                    <span class="fw-bold" id="basic_salary">0.00</span>
                                </div>
                                <div id="allowances_list"></div>
                                <div class="d-flex justify-content-between border-top pt-3 mt-3">
                                    <span class="fw-bold text-dark">Gross Earnings</span>
                                    <span class="fw-bold text-dark fs-5" id="gross_salary">0.00</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="payroll-section-header mb-3"><i class="bi bi-graph-down-arrow text-danger me-2"></i>Deductions</h6>
                            <div class="p-2 mb-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted small">Income Tax (PAYE)</span>
                                    <span class="text-danger fw-bold" id="tax_amount">0.00</span>
                                </div>
                                <div id="deductions_list"></div>
                                <div class="d-flex justify-content-between border-top pt-3 mt-3">
                                    <span class="fw-bold text-dark">Total Reductions</span>
                                    <span class="text-danger fw-bold fs-5" id="total_deductions">0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Final Payout -->
                    <div class="payout-banner d-flex justify-content-between align-items-center">
                        <div>
                            <p class="text-muted small text-uppercase fw-bold mb-1">Net Payable Amount</p>
                            <h1 class="fw-bold text-dark mb-0" id="net_salary" style="letter-spacing: -0.02em;">0.00</h1>
                        </div>
                        <div class="text-end">
                            <div class="icon-shape bg-white shadow-sm border rounded-circle d-flex align-items-center justify-content-center" style="width: 60px; height: 60px;">
                                <i class="bi bi-wallet2 fs-3 text-primary"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Meta Info -->
            <div class="col-lg-4">
                <div class="details-card p-4 h-100">
                    <h5 class="fw-bold mb-4">Industrial Audit</h5>
                    
                    <div class="mb-4">
                        <p class="info-label mb-1">Created By</p>
                        <p class="info-value mb-0 hstack gap-2">
                            <i class="bi bi-person-circle"></i>
                            <span id="creator_name">--</span>
                        </p>
                        <small class="text-muted" id="created_at">--</small>
                    </div>

                    <div class="mb-4">
                        <p class="info-label mb-2">Status Progression</p>
                        <div class="vstack gap-3 audit-timeline ps-3 py-1 ms-2">
                            <div id="approval_info">
                                <p class="info-value mb-0 hstack gap-2">
                                    <i class="bi bi-shield-check text-primary"></i>
                                    <span id="approver_name">Not Approved</span>
                                </p>
                                <small class="text-muted" id="approved_at">--</small>
                            </div>
                            <div id="payment_info">
                                <p class="info-value mb-0 hstack gap-2 text-success">
                                    <i class="bi bi-cash-stack"></i>
                                    <span>Payment Completed</span>
                                </p>
                                <small class="text-muted" id="payment_date">--</small>
                            </div>
                        </div>
                    </div>

                    <div class="mt-auto">
                        <div class="bg-light p-3 rounded-3">
                            <p class="info-label mb-1">Notes</p>
                            <p class="small text-muted mb-0 italic" id="notes">No notes available for this record.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
$(document).ready(function() {
    loadPayrollDetails();
});

function logAndPrintDetails() {
    const payrollNum = $('#payroll_number_display').text();
    $.post(APP_URL + '/api/log_audit', {
        action: 'print_payroll_details',
        activity_type: 'print',
        entity_type: 'payroll',
        entity_id: <?= (int)$payroll_id ?>,
        description: `Printed detailed payroll report for record ${payrollNum}`
    }).always(function() {
        window.print();
    });
}

function loadPayrollDetails() {
    $.getJSON(APP_URL + `/api/get_payroll_details?id=<?= $payroll_id ?>`, function(res) {
        if (!res.success) {
            Swal.fire('Error', res.message, 'error');
            return;
        }

        const p = res.data.payroll;
        $('#payroll_number_display').text(p.payroll_number);
        $('#employee_name').text(`${p.first_name} ${p.last_name}`);
        $('#designation_dept').text(`${p.designation_name} | ${p.department_name}`);
        $('#employee_avatar').text(`${p.first_name.charAt(0)}${p.last_name.charAt(0)}`);
        $('#payroll_period').text(p.payroll_period);
        
        // Status Badge
        let badgeCls = 'bg-secondary';
        if (p.payment_status === 'paid') badgeCls = 'bg-success';
        if (p.payment_status === 'approved') badgeCls = 'bg-primary';
        if (p.payment_status === 'pending') badgeCls = 'bg-warning text-dark';
        
        $('#status_container').html(`<span class="status-badge-lg ${badgeCls}">${p.payment_status}</span>`);

        // Financials
        $('#basic_salary').text(formatCurrency(p.basic_salary));
        $('#gross_salary').text(formatCurrency(p.gross_salary));
        $('#tax_amount').text(formatCurrency(p.tax_amount));
        $('#total_deductions').text(formatCurrency(parseFloat(p.deductions) + parseFloat(p.tax_amount)));
        $('#net_salary').text(formatCurrency(p.net_salary));

        // Render Allowances
        let allowHtml = '';
        if (res.data.allowances && res.data.allowances.length > 0) {
            res.data.allowances.forEach(a => {
                allowHtml += `<div class="d-flex justify-content-between mb-1 small text-muted">
                    <span>${a.allowance_type}</span>
                    <span>+${formatCurrency(a.amount)}</span>
                </div>`;
            });
        }
        $('#allowances_list').html(allowHtml);

        // Render Deductions
        let deductHtml = '';
        if (res.data.deductions && res.data.deductions.length > 0) {
            res.data.deductions.forEach(d => {
                deductHtml += `<div class="d-flex justify-content-between mb-1 small text-muted text-danger">
                    <span>${d.deduction_type}</span>
                    <span>-${formatCurrency(d.amount)}</span>
                </div>`;
            });
        }
        $('#deductions_list').html(deductHtml);

        // Audit
        $('#creator_name').text(p.creator_name || 'System');
        $('#created_at').text(p.created_at);
        
        if (p.approved_by) {
            $('#approver_name').text(p.approver_name);
            $('#approved_at').text(p.updated_at); // Assuming updated_at as approval time
            $('#approval_info').show();
        } else {
            $('#approval_info').hide();
        }

        if (p.payment_status === 'paid') {
            $('#payment_date').text(p.payment_date);
            $('#payment_info').show();
        } else {
            $('#payment_info').hide();
        }

        $('#notes').text(p.notes || 'No notes available.');

        // Action Buttons logic
        let btns = '';
        if (p.payment_status === 'pending') {
            btns += `<button class="btn btn-clean-blue" onclick="approveRecord(${p.payroll_id})">Approve Record</button>`;
        } else if (p.payment_status === 'approved' || p.payment_status === 'processing') {
            btns += `<button class="btn btn-clean-blue" onclick="markPaid(${p.payroll_id})">Mark as Paid</button>`;
        }
        $('#action_buttons').html(btns);
    });
}

function formatCurrency(amount) {
    return 'TSh ' + parseFloat(amount).toLocaleString(undefined, {minimumFractionDigits: 2});
}

function approveRecord(id) {
    bulkAction('approved', [id]);
}

function markPaid(id) {
    bulkAction('paid', [id]);
}

function bulkAction(status, ids) {
    $.ajax({
        url: APP_URL + '/api/bulk_update_payroll_status',
        type: 'POST',
        data: { payroll_ids: ids, status: status },
        success: function(res) {
            if (res.success) {
                Swal.fire('Success', res.message, 'success').then(() => loadPayrollDetails());
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}
</script>

<?php includeFooter(); ?>
