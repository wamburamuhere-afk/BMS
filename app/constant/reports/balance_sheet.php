<?php
/**
 * Professional Balance Sheet Report
 * Standard Accounting Principles: Assets = Liabilities + Equity
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}

// 1. Settings & Filters
$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$company_name = get_setting('company_name') ?: 'Business Management System';
$company_logo = get_setting('company_logo');

/**
 * Accounting formatting for numbers
 * Negative values shown in brackets: (1,000.00)
 */
function format_accounting($amount) {
    $val = (float)$amount;
    if (abs($val) < 0.001) return '-';
    $formatted = number_format(abs($val), 2);
    return ($val < 0) ? '(' . $formatted . ')' : $formatted;
}

try {
    $sql = "
        SELECT 
            a.account_id,
            a.account_name,
            a.account_code,
            at.type_name as account_type,
            SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END) as total_debit,
            SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END) as total_credit
        FROM accounts a
        JOIN account_types at ON a.account_type_id = at.type_id
        LEFT JOIN journal_entry_items jei ON a.account_id = jei.account_id
        LEFT JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE (je.entry_date <= ? OR je.entry_date IS NULL)
        AND (je.status = 'posted' OR je.status IS NULL)
        AND LOWER(at.type_name) IN ('asset', 'liability', 'equity', 'current asset', 'current liability', 'fixed asset', 'non-current asset', 'long term liability')
        GROUP BY a.account_id, a.account_name, a.account_code, at.type_name
        ORDER BY a.account_code ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$as_of_date]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sections = [
        'assets' => [
            'current' => [], 'non_current' => [],
            'total_current' => 0, 'total_non_current' => 0, 'total' => 0
        ],
        'liabilities' => [
            'current' => [], 'non_current' => [],
            'total_current' => 0, 'total_non_current' => 0, 'total' => 0
        ],
        'equity' => ['accounts' => [], 'total' => 0]
    ];

    foreach ($accounts as $acc) {
        $type = strtolower($acc['account_type']);
        $debit = floatval($acc['total_debit']);
        $credit = floatval($acc['total_credit']);

        if (strpos($type, 'asset') !== false) {
            $balance = $debit - $credit;
            if (abs($balance) > 0.001) {
                if ($type === 'asset' || (strpos($type, 'current') !== false && strpos($type, 'non') === false)) {
                    $sections['assets']['current'][] = $acc + ['balance' => $balance];
                    $sections['assets']['total_current'] += $balance;
                } else {
                    $sections['assets']['non_current'][] = $acc + ['balance' => $balance];
                    $sections['assets']['total_non_current'] += $balance;
                }
                $sections['assets']['total'] += $balance;
            }
        } elseif (strpos($type, 'liability') !== false) {
            $balance = $credit - $debit;
            if (abs($balance) > 0.001) {
                if (strpos($type, 'current') !== false) {
                    $sections['liabilities']['current'][] = $acc + ['balance' => $balance];
                    $sections['liabilities']['total_current'] += $balance;
                } else {
                    $sections['liabilities']['non_current'][] = $acc + ['balance' => $balance];
                    $sections['liabilities']['total_non_current'] += $balance;
                }
                $sections['liabilities']['total'] += $balance;
            }
        } elseif (strpos($type, 'equity') !== false) {
            $balance = $credit - $debit;
            if (abs($balance) > 0.001) {
                $sections['equity']['accounts'][] = $acc + ['balance' => $balance];
                $sections['equity']['total'] += $balance;
            }
        }
    }

    // Retained Earnings (Net Income)
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
        WHERE je.entry_date <= ?
        AND je.status = 'posted'
        AND LOWER(at.type_name) IN ('income', 'revenue', 'expense', 'cost of goods sold')
    ";
    $stmt = $pdo->prepare($income_sql);
    $stmt->execute([$as_of_date]);
    $net_income = floatval($stmt->fetchColumn() ?: 0);
    $sections['equity']['total'] += $net_income;

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
                            <i class="bi bi-calendar-event"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark d-none d-lg-block">Report Period</h6>
                    </div>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm report-date-picker">
                            <span class="input-group-text bg-white border-end-0 text-muted">As Of</span>
                            <input type="date" name="as_of_date" class="form-control border-start-0 ps-0" value="<?= $as_of_date ?>" style="width: 150px;">
                            <button type="submit" class="btn btn-primary px-3 fw-bold">
                                <i class="bi bi-arrow-clockwise me-1"></i> Update
                            </button>
                        </div>
                    </form>
                </div>
                <div class="action-buttons d-flex gap-2">
                    <button class="btn btn-sm btn-light border text-dark fw-bold px-3 d-flex align-items-center gap-2" onclick="window.print()">
                        <i class="bi bi-printer fs-6 text-primary"></i> <span>Print Report</span>
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
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">BALANCE SHEET REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Snaphot of the financial position including assets, liabilities, and equity at a specific point in time.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">As of: <?= date('d M Y', strtotime($as_of_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

        <!-- Screen Header -->
        <div class="text-center mb-5 pb-3 border-bottom-double d-print-none">
            <?php if ($company_logo): ?>
                <img src="/<?= ltrim($company_logo, '/') ?>" alt="Logo" class="mb-3" style="max-height: 80px;">
            <?php endif; ?>
            <h2 class="company-title mb-0"><?= htmlspecialchars((string)($company_name ?? '')) ?></h2>
            <h1 class="report-type mb-1">BALANCE SHEET</h1>
            <p class="report-date mb-0">As of <?= date('F d, Y', strtotime($as_of_date)) ?></p>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger mx-4"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="row px-4">
            <!-- ASSETS -->
            <div class="col-md-6 border-end">
                <h4 class="section-title">ASSETS</h4>
                <div class="subsection">
                    <h5 class="subsection-header">Current Assets</h5>
                    <table class="table table-borderless table-sm account-table">
                        <?php foreach ($sections['assets']['current'] as $acc): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($acc['account_name'] ?? '')) ?></td>
                            <td class="text-end"><?= format_accounting($acc['balance']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row">
                            <td>Total Current Assets</td>
                            <td class="text-end"><?= format_accounting($sections['assets']['total_current']) ?></td>
                        </tr>
                    </table>
                </div>
                <div class="subsection mt-4">
                    <h5 class="subsection-header">Non-Current Assets</h5>
                    <table class="table table-borderless table-sm account-table">
                        <?php foreach ($sections['assets']['non_current'] as $acc): ?>
                        <tr>
                            <td><?= htmlspecialchars($acc['account_name']) ?></td>
                            <td class="text-end"><?= format_accounting($acc['balance']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row">
                            <td>Total Non-Current Assets</td>
                            <td class="text-end"><?= format_accounting($sections['assets']['total_non_current']) ?></td>
                        </tr>
                    </table>
                </div>
                <div class="total-box mt-auto">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold h5 mb-0">TOTAL ASSETS</span>
                        <span class="fw-bold h5 mb-0 total-amount double-underline"><?= format_accounting($sections['assets']['total']) ?></span>
                    </div>
                </div>
            </div>

            <!-- LIABILITIES & EQUITY -->
            <div class="col-md-6">
                <h4 class="section-title">LIABILITIES & EQUITY</h4>
                <div class="subsection">
                    <h5 class="subsection-header">Current Liabilities</h5>
                    <table class="table table-borderless table-sm account-table">
                        <?php foreach ($sections['liabilities']['current'] as $acc): ?>
                        <tr>
                            <td><?= htmlspecialchars($acc['account_name']) ?></td>
                            <td class="text-end"><?= format_accounting($acc['balance']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row">
                            <td>Total Current Liabilities</td>
                            <td class="text-end"><?= format_accounting($sections['liabilities']['total_current']) ?></td>
                        </tr>
                    </table>
                </div>
                <?php if (!empty($sections['liabilities']['non_current'])): ?>
                <div class="subsection mt-4">
                    <h5 class="subsection-header">Non-Current Liabilities</h5>
                    <table class="table table-borderless table-sm account-table">
                        <?php foreach ($sections['liabilities']['non_current'] as $acc): ?>
                        <tr>
                            <td><?= htmlspecialchars($acc['account_name']) ?></td>
                            <td class="text-end"><?= format_accounting($acc['balance']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="subtotal-row">
                            <td>Total Non-Current Liabilities</td>
                            <td class="text-end"><?= format_accounting($sections['liabilities']['total_non_current']) ?></td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>
                <div class="subsection mt-4">
                    <h5 class="subsection-header">Equity</h5>
                    <table class="table table-borderless table-sm account-table">
                        <?php foreach ($sections['equity']['accounts'] as $acc): ?>
                        <tr>
                            <td><?= htmlspecialchars($acc['account_name']) ?></td>
                            <td class="text-end"><?= format_accounting($acc['balance']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td class="fw-bold">Net Income / Retained Earnings</td>
                            <td class="text-end fw-bold"><?= format_accounting($net_income) ?></td>
                        </tr>
                        <tr class="subtotal-row">
                            <td>Total Equity</td>
                            <td class="text-end"><?= format_accounting($sections['equity']['total']) ?></td>
                        </tr>
                    </table>
                </div>
                <div class="total-box mt-auto">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold h5 mb-0">TOTAL LIABILITIES & EQUITY</span>
                        <span class="fw-bold h5 mb-0 total-amount double-underline"><?= format_accounting($sections['liabilities']['total'] + $sections['equity']['total']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Balance Check -->
        <?php
        $diff = abs($sections['assets']['total'] - ($sections['liabilities']['total'] + $sections['equity']['total']));
        if ($diff > 0.01): ?>
        <div class="balance-warning py-3 mt-4 text-center text-danger d-print-none">
            <i class="bi bi-exclamation-octagon me-2"></i>
            BALANCE WARNING: Unbalanced by <?= number_format($diff, 2) ?>
        </div>
        <?php endif; ?>

        <!-- Signature Lines -->
        <div class="signature-section mt-5 px-4 pt-5">
            <div class="row">
                <div class="col-4 text-center"><div class="sig-line"></div><p class="small text-uppercase mt-2">Prepared By</p></div>
                <div class="col-4 text-center"><div class="sig-line"></div><p class="small text-uppercase mt-2">Verified By</p></div>
                <div class="col-4 text-center"><div class="sig-line"></div><p class="small text-uppercase mt-2">Approved By</p></div>
            </div>
        </div>

        <div class="footer-note mt-5 text-center text-muted x-small">
            Printed on <?= date('d M Y, H:i') ?> | System ID: <?= session_id() ?>
        </div>
    </div>
</div>

<style>
:root { --accounting-gray: #fcfcfc; --border-color: #333; }
.report-paper { background: #fff; min-height: 1000px; padding: 60px 40px; font-family: 'Inter', 'Segoe UI', serif; color: #333; border: 1px solid #ddd; }
.company-title { font-size: 1.4rem; font-weight: 800; color: #111; letter-spacing: 0.5px; }
.report-type { font-size: 2.2rem; font-weight: 300; color: #555; }
.report-date { font-size: 1.1rem; font-style: italic; color: #777; }
.border-bottom-double { border-bottom: 3px double #000; }
.section-title { background: #444; color: #fff; padding: 8px 15px; font-size: 1.1rem; font-weight: 700; margin-bottom: 20px; text-align: center; }
.subsection-header { border-bottom: 2px solid #555; font-size: 0.95rem; font-weight: 800; margin-bottom: 10px; color: #000; text-transform: uppercase; }
.account-table td { padding: 4px 8px; font-size: 0.9rem; }
.subtotal-row { border-top: 1px solid #000; font-weight: 700; border-bottom: 1px solid #000; }
.subtotal-row td { padding-top: 8px !important; }
.total-box { margin-top: 40px; padding: 15px 0; }
.double-underline { border-bottom: 4px double #000; padding-bottom: 2px; }
.sig-line { border-top: 1px solid #000; width: 80%; margin: 40px auto 0; }
.balance-warning { background: #fff5f5; border: 1px solid #feb2b2; }
.x-small { font-size: 0.75rem; }
.glass-action-bar { background: rgba(255,255,255,0.9) !important; backdrop-filter: blur(10px); border-radius: 1.25rem !important; border: 1px solid rgba(0,0,0,0.05) !important; }
.icon-circle { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 1.2rem; }
.btn-dark { background-color: #111 !important; border: none; transition: all 0.3s ease; }
.btn-dark:hover { background-color: #333 !important; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
.btn-light:hover { background-color: #f8f9fa !important; transform: translateY(-2px); }
@media print {
    .glass-action-bar { display: none !important; }
    body { background: #fff !important; }
    .container { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
    .report-paper { box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 !important; }
    .shadow { box-shadow: none !important; }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
    .col-md-6 { width: 48%; float: left; }
    .row { display: block; }
}
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function exportToPDF() {
    const element = document.getElementById('reportContent');
    const opt = {
        margin: [0.5, 0.5],
        filename: 'Balance_Sheet_<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };
    html2pdf().set(opt).from(element).save();
}

$(document).ready(function() {
    logReportAction('Viewed Balance Sheet (Pro)', 'User viewed the professional balance sheet report as of date <?= $as_of_date ?>');
});

const originalExportToPDF = exportToPDF;
exportToPDF = function() {
    logReportAction('Exported Balance Sheet (Pro)', 'User saved the professional balance sheet to PDF');
    originalExportToPDF();
}
</script>

<?php
includeFooter();
ob_end_flush();
?>
