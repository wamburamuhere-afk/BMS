<?php
/**
 * Professional Cash Flow Statement
 * Indirect Method - Premium UI Design
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$company_name = get_setting('company_name') ?: 'Business Management System';

try {
    // 1. Net Income
    $income_sql = "
        SELECT SUM(
            CASE 
                WHEN jei.type = 'credit' THEN jei.amount 
                WHEN jei.type = 'debit' THEN -jei.amount 
                ELSE 0 
            END
        ) as net_income
        FROM journal_entry_items jei
        JOIN accounts a ON jei.account_id = a.account_id
        JOIN account_types at ON a.account_type_id = at.type_id
        JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE je.entry_date BETWEEN ? AND ?
        AND je.status = 'posted'
        AND LOWER(at.type_name) IN ('income', 'revenue', 'expense', 'cost of goods sold')
    ";
    $stmt = $pdo->prepare($income_sql);
    $stmt->execute([$start_date, $end_date]);
    $net_income = floatval($stmt->fetchColumn() ?: 0);

    // 2. Changes in Balance Sheet Accounts
    $changes_sql = "
        SELECT 
            a.account_id,
            a.account_name,
            a.account_code,
            LOWER(at.type_name) as account_type,
            SUM(CASE 
                WHEN jei.type = 'debit' THEN jei.amount 
                WHEN jei.type = 'credit' THEN -jei.amount 
                ELSE 0 
            END) as net_change
        FROM journal_entry_items jei
        JOIN accounts a ON jei.account_id = a.account_id
        JOIN account_types at ON a.account_type_id = at.type_id
        JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE je.entry_date BETWEEN ? AND ?
        AND je.status = 'posted'
        AND LOWER(at.type_name) IN ('asset', 'liability', 'equity', 'current asset', 'current liability', 'fixed asset', 'non-current asset', 'long term liability')
        GROUP BY a.account_id, a.account_name, a.account_code, at.type_name
        HAVING ABS(net_change) > 0.001
        ORDER BY a.account_code
    ";
    $stmt = $pdo->prepare($changes_sql);
    $stmt->execute([$start_date, $end_date]);
    $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $operating_activities = [];
    $investing_activities = [];
    $financing_activities = [];
    $cash_movement = 0;

    foreach ($changes as $acc) {
        $change  = floatval($acc['net_change']);
        $name    = strtolower($acc['account_name']);
        $type    = $acc['account_type'];

        $is_cash       = (strpos($name, 'cash') !== false || strpos($name, 'bank') !== false || strpos($name, 'petty') !== false);
        $is_fixed_asset = (strpos($name, 'fixed') !== false || strpos($name, 'equipment') !== false || strpos($name, 'property') !== false || strpos($name, 'vehicle') !== false || strpos($name, 'computer') !== false || strpos($name, 'machinery') !== false || strpos($name, 'land') !== false || strpos($name, 'building') !== false);
        $is_accum_dep  = (strpos($name, 'accumulated depreciation') !== false);
        $is_equity     = (strpos($type, 'equity') !== false || strpos($type, 'capital') !== false);
        $is_payable    = (strpos($name, 'payable') !== false || strpos($name, 'creditor') !== false || strpos($name, 'tax') !== false || strpos($name, 'salary') !== false || strpos($name, 'wages') !== false);
        $is_loan       = (strpos($name, 'loan') !== false || (strpos($type, 'liability') !== false && !$is_payable));

        if ($is_cash) {
            $cash_movement += $change;
            continue;
        }

        $cf_impact = -$change;
        $acc['cf_impact'] = $cf_impact;

        if ($is_accum_dep) {
            $operating_activities[] = $acc;
        } elseif ($is_fixed_asset) {
            $investing_activities[] = $acc;
        } elseif ($is_equity || $is_loan) {
            $financing_activities[] = $acc;
        } else {
            $operating_activities[] = $acc;
        }
    }

    $total_operating = $net_income;
    foreach ($operating_activities as $act) $total_operating += $act['cf_impact'];

    $total_investing = 0;
    foreach ($investing_activities as $act) $total_investing += $act['cf_impact'];

    $total_financing = 0;
    foreach ($financing_activities as $act) $total_financing += $act['cf_impact'];

    $net_increase_cash = $total_operating + $total_investing + $total_financing;

    // Cash at Beginning
    $cash_start_sql = "
        SELECT SUM(CASE WHEN jei.type = 'debit' THEN jei.amount WHEN jei.type = 'credit' THEN -jei.amount ELSE 0 END)
        FROM journal_entry_items jei
        JOIN accounts a ON jei.account_id = a.account_id
        JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE je.entry_date < ? AND je.status = 'posted'
        AND (LOWER(a.account_name) LIKE '%cash%' OR LOWER(a.account_name) LIKE '%bank%' OR LOWER(a.account_name) LIKE '%petty%')
    ";
    $stmt = $pdo->prepare($cash_start_sql);
    $stmt->execute([$start_date]);
    $cash_start = floatval($stmt->fetchColumn() ?: 0);
    $cash_end   = $cash_start + $net_increase_cash;

} catch (Exception $e) {
    $error_message = $e->getMessage();
}
?>

<div class="container py-4">
    <!-- Action Bar -->
    <div class="row mb-5 d-print-none">
        <div class="col-12">
            <div class="glass-action-bar p-3 shadow-sm rounded-4 d-flex flex-wrap justify-content-between align-items-center bg-white border">
                <div class="filter-section d-flex align-items-center gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="icon-circle bg-primary-subtle text-primary">
                            <i class="bi bi-calendar-range"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark d-none d-lg-block">Analysis Range</h6>
                    </div>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-white border-end-0 text-muted">From</span>
                            <input type="date" name="start_date" class="form-control border-start-0 ps-0" value="<?= $start_date ?>" style="width: 140px;">
                            <span class="input-group-text bg-white border-x-0 text-muted">To</span>
                            <input type="date" name="end_date" class="form-control border-start-0 ps-0" value="<?= $end_date ?>" style="width: 140px;">
                            <button type="submit" class="btn btn-primary px-3 fw-bold">
                                <i class="bi bi-arrow-clockwise me-1"></i> Update
                            </button>
                        </div>
                    </form>
                </div>
                <div class="action-buttons d-flex gap-2">
                    <button class="btn btn-sm btn-light border text-dark fw-bold px-3 d-flex align-items-center gap-2" onclick="window.print()">
                        <i class="bi bi-printer fs-6 text-primary"></i> <span>Print</span>
                    </button>
                    <button class="btn btn-sm btn-dark fw-bold px-3 d-flex align-items-center gap-2" onclick="exportToPDF()">
                        <i class="bi bi-file-earmark-pdf fs-6 text-warning"></i> <span>Save PDF</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

 

    <!-- REPORT BODY -->
    <div class="report-paper shadow mb-5" id="reportContent">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <?php 
        $c_name = getSetting('company_name', 'BMS');
        $c_logo = getSetting('company_logo', '');
        ?>
        <?php if(!empty($c_logo)): ?>
            <div class="mb-3 text-center">
                <img src="<?= htmlspecialchars('../../../' . $c_logo) ?>" alt="Logo" style="max-height: 80px; width: auto;">
            </div>
        <?php endif; ?>
        <h1 style="color: #0d6efd; font-weight: 800; text-transform: uppercase; margin: 0; font-size: 24pt;" class="text-center"><?= safe_output($c_name) ?></h1>
        
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">CASH FLOW STATEMENT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Detailed analysis of cash inflows and outflows from operating, investing, and financing activities.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

        <!-- Screen Header -->
        <div class="text-center mb-5 pb-3 border-bottom-double d-print-none">
            <h2 class="company-title mb-0"><?= htmlspecialchars((string)($company_name ?? '')) ?></h2>
            <h1 class="report-type mb-1">CASH FLOW</h1>
            <p class="report-date mb-0"><?= date('F d, Y', strtotime($start_date)) ?> to <?= date('F d, Y', strtotime($end_date)) ?></p>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger mx-4"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="px-4">
            <div class="table-responsive">
                <table class="table table-sm cf-table">
                    <thead>
                        <tr class="bg-dark text-white">
                            <th class="ps-3 py-3 border-0" style="width: 70%">FLOW CLASSIFICATION</th>
                            <th class="text-end pe-3 py-3 border-0" style="width: 30%">AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- OPERATING -->
                        <tr class="bg-light bg-opacity-50">
                            <td colspan="2" class="ps-3 py-3 fw-bold text-primary text-uppercase ls-1">
                                <i class="bi bi-lightning-charge-fill me-2"></i>Cash flows from operating activities
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-5 py-2">Net Income / Retained Earnings</td>
                            <td class="text-end pe-3 py-2 fw-bold"><?= format_currency($net_income) ?></td>
                        </tr>
                        <?php if (!empty($operating_activities)): ?>
                            <tr><td colspan="2" class="ps-5 small text-muted fst-italic py-1">Adjustments for changes in working capital:</td></tr>
                            <?php foreach ($operating_activities as $act): ?>
                            <tr>
                                <td class="ps-5 border-0 py-2">
                                    <span class="text-muted small me-2"><?= $act['cf_impact'] >= 0 ? '<i class="bi bi-plus-circle text-success"></i>' : '<i class="bi bi-dash-circle text-danger"></i>' ?></span>
                                    <?= htmlspecialchars(ucwords((string)($act['account_name'] ?? ''))) ?>
                                </td>
                                <td class="text-end pe-3 border-0 py-2"><?= format_currency($act['cf_impact']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr class="subtotal-row border-top">
                            <td class="ps-4 fw-bold py-2">Net Cash provided by Operating Activities</td>
                            <td class="text-end pe-3 py-2 fw-bold text-primary border-bottom border-primary"><?= format_currency($total_operating) ?></td>
                        </tr>

                        <!-- INVESTING -->
                        <tr class="bg-light bg-opacity-50 mt-4">
                            <td colspan="2" class="ps-3 py-3 fw-bold text-info text-uppercase ls-1">
                                <i class="bi bi-building-up me-2"></i>Cash flows from investing activities
                            </td>
                        </tr>
                        <?php if (!empty($investing_activities)): ?>
                            <?php foreach ($investing_activities as $act): ?>
                            <tr>
                                <td class="ps-5 border-0 py-2">
                                    <span class="text-muted small me-2"><?= $act['cf_impact'] >= 0 ? '<i class="bi bi-arrow-up text-success"></i>' : '<i class="bi bi-arrow-down text-danger"></i>' ?></span>
                                    <?= ($act['cf_impact'] < 0 ? 'Purchase of ' : 'Sale of ') . htmlspecialchars(ucwords((string)($act['account_name'] ?? ''))) ?>
                                </td>
                                <td class="text-end pe-3 border-0 py-2"><?= format_currency($act['cf_impact']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="2" class="ps-5 text-muted fst-italic py-2 small">No investing activities captured.</td></tr>
                        <?php endif; ?>
                        <tr class="subtotal-row border-top">
                            <td class="ps-4 fw-bold py-2">Net Cash provided by Investing Activities</td>
                            <td class="text-end pe-3 py-2 fw-bold text-info border-bottom border-info"><?= format_currency($total_investing) ?></td>
                        </tr>

                        <!-- FINANCING -->
                        <tr class="bg-light bg-opacity-50 mt-4">
                            <td colspan="2" class="ps-3 py-3 fw-bold text-warning-emphasis text-uppercase ls-1">
                                <i class="bi bi-bank2 me-2"></i>Cash flows from financing activities
                            </td>
                        </tr>
                        <?php if (!empty($financing_activities)): ?>
                            <?php foreach ($financing_activities as $act): ?>
                            <tr>
                                <td class="ps-5 border-0 py-2">
                                    <span class="text-muted small me-2"><?= $act['cf_impact'] >= 0 ? '<i class="bi bi-graph-up text-success"></i>' : '<i class="bi bi-graph-down text-danger"></i>' ?></span>
                                    Change in <?= htmlspecialchars(ucwords((string)($act['account_name'] ?? ''))) ?>
                                </td>
                                <td class="text-end pe-3 border-0 py-2"><?= format_currency($act['cf_impact']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="2" class="ps-5 text-muted fst-italic py-2 small">No financing activities captured.</td></tr>
                        <?php endif; ?>
                        <tr class="subtotal-row border-top">
                            <td class="ps-4 fw-bold py-2">Net Cash provided by Financing Activities</td>
                            <td class="text-end pe-3 py-2 fw-bold text-warning-emphasis border-bottom border-warning"><?= format_currency($total_financing) ?></td>
                        </tr>

                        <!-- FINAL RECONCILIATION -->
                        <tr class="bg-dark text-white mt-5">
                            <td class="ps-3 py-3 fw-bold h5 mb-0">NET INCREASE/DECREASE IN CASH</td>
                            <td class="text-end pe-3 py-3 fw-bold h5 mb-0"><?= format_currency($net_increase_cash) ?></td>
                        </tr>
                        <tr>
                            <td class="ps-3 py-3 text-muted">Cash and cash equivalents at beginning of period</td>
                            <td class="text-end pe-3 py-3"><?= format_currency($cash_start) ?></td>
                        </tr>
                        <tr class="bg-primary text-white">
                            <td class="ps-3 py-3 fw-bold h4 mb-0 text-uppercase">Cash and cash equivalents at end of period</td>
                            <td class="text-end pe-3 py-3 fw-bold h4 mb-0 border-bottom-double"><?= format_currency($cash_end) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer Note -->
        <div class="footer-note mt-5 px-4 pt-5 border-top d-print-none">
            <div class="row">
                <div class="col-6">
                    <p class="small text-muted mb-0">Generated by: <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?></p>
                    <p class="small text-muted">Period: <?= $start_date ?> to <?= $end_date ?></p>
                </div>
                <div class="col-6 text-end">
                    <p class="small text-muted mb-0">Verification Method: Indirect</p>
                    <p class="small text-muted">ID: <?= session_id() ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
:root { --border-double: 3px double #000; }
.report-paper { background: #fff; min-height: 1000px; padding: 60px 40px; font-family: 'Inter', 'Segoe UI', serif; color: #333; border: 1px solid #ddd; border-radius: 12px; }
.company-title { font-size: 1.4rem; font-weight: 800; color: #111; letter-spacing: 0.5px; }
.report-type { font-size: 2.2rem; font-weight: 300; color: #555; }
.report-date { font-size: 1.1rem; font-style: italic; color: #777; }
.border-bottom-double { border-bottom: var(--border-double); }
.ls-1 { letter-spacing: 1px; }
.cf-table { width: 100%; border-collapse: separate; border-spacing: 0 2px; }
.cf-table td { padding: 10px 8px; border-bottom: 1px solid #f8f9fa; }
.glass-action-bar { background: rgba(255,255,255,0.9) !important; backdrop-filter: blur(10px); border-radius: 1.25rem !important; border: 1px solid rgba(0,0,0,0.05) !important; }
.icon-circle { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 1.2rem; }
.text-warning-emphasis { color: #856404 !important; }
@media print {
    .glass-action-bar, .d-print-none { display: none !important; }
    body { background: #fff !important; }
    .report-paper { border: none !important; padding: 0 !important; margin: 0 !important; border-radius: 0; }
    .container { width: 100% !important; max-width: 100% !important; }
}
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function exportToPDF() {
    const element = document.getElementById('reportContent');
    const opt = {
        margin: [0.5, 0.5],
        filename: 'Cash_Flow_Statement_<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save();
}

$(document).ready(function() {
    if(typeof logReportAction === 'function') {
        logReportAction('Viewed Cash Flow (Premium)', 'User analyzed the cash flow statement from <?= $start_date ?> to <?= $end_date ?>');
    }
});
</script>

<?php 
includeFooter(); 
ob_end_flush();
?>
