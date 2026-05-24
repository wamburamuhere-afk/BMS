<?php
require_once dirname(__DIR__, 3) . '/roots.php';

// Check permissions
if (!isset($_SESSION['user_role'])) {
    redirectTo('login');
}

// Phase 5b — print pages get a canView gate (admin auto-bypass)
if (!canView('payslip')) die("Access Denied");

$payroll_id = $_GET['id'] ?? null;
if (!$payroll_id) {
    die("Invalid payroll ID.");
}

// Fetch detailed payroll data
$stmt = $pdo->prepare("
    SELECT 
        p.*,
        e.first_name,
        e.last_name,
        e.employee_number,
        e.bank_name,
        e.bank_account_number,
        d.department_name,
        des.designation_name
    FROM payroll p
    JOIN employees e ON p.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN designations des ON e.designation_id = des.designation_id
    WHERE p.payroll_id = ?
");
$stmt->execute([$payroll_id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) {
    die("Payroll record not found.");
}

// Fetch business settings
$company_name = getSetting('company_name', 'BMS INDUSTRIAL SOLUTIONS');
$company_address = getSetting('address', '123 Business Street, Industrial Area');
$company_logo = getSetting('company_logo', '');

// Log view action
logAudit($pdo, $_SESSION['user_id'], 'view_payslip', [
    'activity_type' => 'view',
    'entity_type' => 'payroll',
    'entity_id' => $payroll_id,
    'description' => "Viewed payslip for {$p['first_name']} {$p['last_name']} (Period: {$p['payroll_period']}, Record ID: $payroll_id)"
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?= $p['payroll_number'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Inter', sans-serif;
        }
        .payslip-container {
            max-width: 800px;
            margin: 40px auto;
            background: white;
            padding: 40px;
            box-shadow: 0 0 20px rgba(0,0,0,0.05);
            border-radius: 10px;
        }
        .header-logo {
            font-size: 24px;
            font-weight: 800;
            color: #333;
            letter-spacing: -1px;
        }
        .divider {
            height: 1px;
            background: #eee;
            margin: 20px 0;
        }
        .section-title {
            font-size: 12px;
            text-transform: uppercase;
            color: #888;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .data-label {
            font-size: 13px;
            color: #666;
        }
        .data-value {
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }
        .table-payslip th {
            background: #fdfdfd;
            border-bottom: 2px solid #eee;
            font-size: 13px;
            color: #444;
        }
        .table-payslip td {
            font-size: 14px;
            padding: 12px 8px;
        }
        .total-row {
            background: #fcfcfc;
            font-weight: 700;
        }
        .net-salary-box {
            background: #f8f9ff;
            border: 1px solid #eef0ff;
            padding: 20px;
            border-radius: 8px;
            margin-top: 30px;
        }
        @media print {
            body { 
                background: white !important; 
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                margin: 0;
                padding: 0;
            }
            .payslip-container { 
                box-shadow: none; 
                margin: 0 !important;
                padding: 30px !important;
                width: 100% !important;
                max-width: 100% !important;
                padding-bottom: 120px !important;
            }
            .btn-print { display: none; }
            
            /* Page Margin & Footer Protection */
            @page {
                margin-bottom: 100px !important;
                margin-top: 30px !important;
            }
            
            .fixed-print-footer {
                position: fixed;
                bottom: 15px;
                left: 0;
                right: 0;
                width: 100%;
                z-index: 1000;
            }

            /* Orientation Specific Logic */
            .net-salary-box {
                page-break-inside: avoid !important;
            }

            @media (orientation: landscape) {
                .net-salary-box {
                    page-break-before: always !important;
                    margin-top: 50px !important;
                }
            }
        }
    </style>
</head>
<body>

<div class="container text-end mt-4">
    <button class="btn btn-dark btn-print" onclick="logAndPrint()">
        <i class="bi bi-printer"></i> Print Payslip
    </button>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    const APP_URL = '<?= buildUrl('') ?>'.replace(/\/$/, '');
    
    function logAndPrint() {
        $.post(APP_URL + '/api/log_audit', {
            action: 'print_payslip',
            activity_type: 'print',
            entity_type: 'payroll',
            entity_id: <?= (int)$payroll_id ?>,
            description: 'Printed payslip for <?= addslashes($p['first_name'] . " " . $p['last_name']) ?> (Period: <?= $p['payroll_period'] ?>)'
        }).always(function() {
            window.print();
        });
    }
</script>

<div class="payslip-container">
    <div class="text-center">
        <?php if (!empty($company_logo)): ?>
            <img src="<?= htmlspecialchars('../../../' . $company_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;" class="mb-3">
        <?php endif; ?>
        <div class="header-logo mb-1" style="color: #0d6efd; text-transform: uppercase; font-size: 26px;"><?= $company_name ?></div>
        <p class="text-muted small mb-3"><?= $company_address ?></p>
        
        <div style="border-top: 2px solid #0d6efd; width: 100px; margin: 15px auto;"></div>
        
        <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: 2px;">PAYSLIP</h4>
        <p class="text-muted small">#<?= $p['payroll_number'] ?></p>
    </div>

    <div class="divider"></div>

    <div class="row g-4">
        <div class="col-4">
            <div class="section-title">Employee Details</div>
            <div class="mb-2">
                <div class="data-label">Name</div>
                <div class="data-value"><?= $p['first_name'] ?> <?= $p['last_name'] ?></div>
            </div>
            <div>
                <div class="data-label">ID Number</div>
                <div class="data-value"><?= $p['employee_number'] ?></div>
            </div>
        </div>
        <div class="col-4 text-center">
            <div class="section-title">Job Info</div>
            <div class="mb-2">
                <div class="data-label">Designation</div>
                <div class="data-value"><?= $p['designation_name'] ?></div>
            </div>
            <div>
                <div class="data-label">Department</div>
                <div class="data-value"><?= $p['department_name'] ?></div>
            </div>
        </div>
        <div class="col-4 text-end">
            <div class="section-title">Payment Info</div>
            <div class="mb-2">
                <div class="data-label">Period</div>
                <div class="data-value"><?= date('F Y', strtotime($p['payroll_period'] . '-01')) ?></div>
            </div>
            <div>
                <div class="data-label">Account No</div>
                <div class="data-value"><?= $p['bank_account_number'] ?: '---' ?></div>
            </div>
        </div>
    </div>

    <div class="divider"></div>

    <div class="row mt-4">
        <div class="col-6 pe-4">
            <div class="section-title mb-3">Earnings</div>
            <table class="table table-payslip">
                <thead>
                    <tr>
                        <th width="70%">Description</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Basic Salary</td>
                        <td class="text-end"><?= number_format($p['basic_salary'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>Allowances</td>
                        <td class="text-end"><?= number_format($p['allowances'], 2) ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td>Gross Earnings</td>
                        <td class="text-end"><?= number_format($p['gross_salary'], 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="col-6 ps-4">
            <div class="section-title mb-3">Deductions</div>
            <table class="table table-payslip">
                <thead>
                    <tr>
                        <th width="70%">Description</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Income Tax (PAYE)</td>
                        <td class="text-end"><?= number_format($p['tax_amount'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>General Deductions</td>
                        <td class="text-end"><?= number_format($p['deductions'], 2) ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td>Total Deductions</td>
                        <td class="text-end text-danger"><?= number_format($p['tax_amount'] + $p['deductions'], 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="net-salary-box d-flex justify-content-between align-items-center">
        <div>
            <h5 class="fw-bold mb-1 text-primary">Net Salary Distributed</h5>
            <p class="text-muted small mb-0">Paid via <?= $p['payment_method'] ?: 'Standard Transfer' ?></p>
        </div>
        <div class="text-end">
            <h3 class="fw-bold mb-0 text-primary">TSh <?= number_format($p['net_salary'], 2) ?></h3>
        </div>
    </div>

    </div>

    <!-- Fixed Branded Print Footer -->
    <div class="fixed-print-footer d-none d-print-block">
        <div class="mx-auto" style="width: 85%; border-top: 1px solid #eee; padding-top: 10px; text-align: center;">
            <p class="mb-0 text-muted" style="font-size: 8.5pt; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                 This document was Printed by <span class="fw-bold text-dark"><?= ucwords(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?> - <?= ucwords($_SESSION['user_role'] ?? 'Staff') ?></span> on <span class="fw-bold text-dark"><?= date('d M, Y \a\t h:i A') ?></span>
            </p>
            <p class="mb-0 fw-bold text-primary" style="font-size: 10.5pt; letter-spacing: 0.5px; white-space: nowrap;">
                Powered By BJP Technologies  © 2026, All Rights Reserved
            </p>
        </div>
    </div>

</body>
</html>
