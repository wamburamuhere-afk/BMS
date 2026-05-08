<?php
/**
 * Professional Trial Balance Report
 * Ensures Debits equal Credits
 * Premium UI/UX Design
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');
$company_name = get_setting('company_name') ?: 'Business Management System';

try {
    $sql = "
        SELECT 
            a.account_id,
            a.account_code,
            a.account_name,
            at.type_name as account_type,
            SUM(CASE WHEN jei.type = 'debit' THEN jei.amount ELSE 0 END) as total_debit_trans,
            SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END) as total_credit_trans
        FROM accounts a
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        LEFT JOIN journal_entry_items jei ON a.account_id = jei.account_id
        LEFT JOIN journal_entries je ON jei.entry_id = je.entry_id
        WHERE (je.entry_date <= ? OR je.entry_date IS NULL)
        AND (je.status = 'posted' OR je.status IS NULL)
        AND a.status = 'active'
        GROUP BY a.account_id, a.account_name, a.account_code, at.type_name
        ORDER BY a.account_code ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$as_of_date]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $trial_balance_data = [];
    $total_debits = 0;
    $total_credits = 0;

    foreach ($accounts as $acc) {
        $debit_sum = floatval($acc['total_debit_trans']);
        $credit_sum = floatval($acc['total_credit_trans']);
        $net = $debit_sum - $credit_sum;

        if (abs($net) < 0.001) continue;

        $row = [
            'code' => $acc['account_code'],
            'name' => $acc['account_name'],
            'type' => $acc['account_type'],
            'debit' => 0,
            'credit' => 0
        ];

        if ($net > 0) {
            $row['debit'] = $net;
            $total_debits += $net;
        } else {
            $row['credit'] = abs($net);
            $total_credits += abs($net);
        }
        $trial_balance_data[] = $row;
    }

    $is_balanced = (abs($total_debits - $total_credits) < 0.01);
    $difference = $total_debits - $total_credits;

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
                            <i class="bi bi-calendar-check"></i>
                        </div>
                        <h6 class="mb-0 fw-bold text-dark d-none d-lg-block">Reporting Point</h6>
                    </div>
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm">
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
                    <button class="btn btn-sm btn-dark fw-bold px-3 d-flex align-items-center gap-2" onclick="alert('Exporting to PDF...')">
                        <i class="bi bi-file-earmark-pdf fs-6 text-warning"></i> <span>Save PDF</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Aggregate Debits</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_debits) ?></h4>
                    <span class="small text-primary fw-bold">Total Inflows</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Aggregate Credits</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_credits) ?></h4>
                    <span class="small text-warning fw-bold">Total Outflows</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Balance Integrity</p>
                    <h4 class="fw-bold mb-0 text-<?= $is_balanced ? 'success' : 'danger' ?>">
                        <?= $is_balanced ? 'BALANCED' : 'UNBALANCED' ?>
                    </h4>
                    <?php if(!$is_balanced): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-2">Diff: <?= number_format(abs($difference), 2) ?></span>
                    <?php else: ?>
                        <span class="small text-success fw-bold">Verified Accurate</span>
                    <?php endif; ?>
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
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">TRIAL BALANCE REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Verification report ensuring all debits and credits are accurately balanced across accounts.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">As of: <?= date('d M Y', strtotime($as_of_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div class="row g-2">
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Debits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_debits) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Credits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_credits) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Status</p>
                    <h4 style="color: <?= $is_balanced ? '#2ecc71' : '#e74c3c' ?>; font-weight: 800; margin: 0; font-size: 14pt;"><?= $is_balanced ? 'BALANCED' : 'UNBALANCED' ?></h4>
                </div>
            </div>
        </div>
    </div>

        <!-- Screen Header -->
        <div class="text-center mb-5 pb-3 border-bottom-double d-print-none">
            <h2 class="company-title mb-0"><?= htmlspecialchars((string)($company_name ?? '')) ?></h2>
            <h1 class="report-type mb-1">TRIAL BALANCE</h1>
            <p class="report-date mb-0">Financial Verification as of <?= date('F d, Y', strtotime($as_of_date)) ?></p>
        </div>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger mx-4"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="px-4">
            <div class="table-responsive">
                <table class="table table-sm account-table">
                    <thead>
                        <tr class="bg-dark text-white">
                            <th class="ps-3 py-3 border-0" style="width: 15%">CODE</th>
                            <th class="py-3 border-0" style="width: 45%">ACCOUNT DESCRIPTION</th>
                            <th class="text-end py-3 border-0" style="width: 20%">DEBIT</th>
                            <th class="text-end pe-3 py-3 border-0" style="width: 20%">CREDIT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trial_balance_data)): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">No accounting records captured for this date.</td></tr>
                        <?php else: ?>
                            <?php 
                            $prev_type = '';
                            foreach ($trial_balance_data as $row): 
                                if($row['type'] !== $prev_type):
                            ?>
                                <tr class="bg-light bg-opacity-50">
                                    <td colspan="4" class="ps-3 py-2 fw-bold text-muted small text-uppercase ls-1"><?= htmlspecialchars((string)($row['type'] ?? '')) ?>S</td>
                                </tr>
                            <?php 
                                $prev_type = $row['type'];
                                endif; 
                            ?>
                                <tr>
                                    <td class="ps-3 text-muted fw-mono"><?= htmlspecialchars((string)($row['code'] ?? '')) ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars((string)($row['name'] ?? '')) ?></td>
                                    <td class="text-end fw-bold"><?= $row['debit'] > 0 ? number_format($row['debit'], 2) : '-' ?></td>
                                    <td class="text-end pe-3 fw-bold"><?= $row['credit'] > 0 ? number_format($row['credit'], 2) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row border-top-double">
                            <td colspan="2" class="ps-3 py-3 fw-bold h5 mb-0">AGGREGATE BALANCE</td>
                            <td class="text-end py-3 fw-bold h5 mb-0 text-primary border-bottom-double"><?= number_format($total_debits, 2) ?></td>
                            <td class="text-end pe-3 py-3 fw-bold h5 mb-0 text-primary border-bottom-double"><?= number_format($total_credits, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Verification Stamp -->
        <div class="verification-seal mt-5 text-center d-print-none">
            <div class="d-inline-block p-3 rounded-circle border <?= $is_balanced ? 'border-success text-success' : 'border-danger text-danger' ?> bg-opacity-10" style="width: 100px; height: 100px;">
                <i class="bi <?= $is_balanced ? 'bi-patch-check-fill' : 'bi-patch-exclamation-fill' ?> fs-1"></i>
            </div>
            <p class="mt-2 fw-bold text-uppercase small ls-1 text-<?= $is_balanced ? 'success' : 'danger' ?>">
                <?= $is_balanced ? 'Accounts Verified' : 'Reconciliation Required' ?>
            </p>
        </div>

        <!-- Footer Note -->
        <div class="footer-note mt-5 px-4 pt-5 border-top">
            <div class="row">
                <div class="col-6">
                    <p class="small text-muted mb-0">Generated by: <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?></p>
                    <p class="small text-muted">Timestamp: <?= date('d M Y, H:i') ?></p>
                </div>
                <div class="col-6 text-end">
                    <p class="small text-muted mb-0">System ID: <?= session_id() ?></p>
                    <p class="small text-muted">Page 1 of 1</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
:root { --primary-color: #0d6efd; --border-double: 3px double #000; }
.report-paper { background: #fff; min-height: 1000px; padding: 60px 40px; font-family: 'Inter', 'Segoe UI', serif; color: #333; border: 1px solid #ddd; border-radius: 8px; }
.company-title { font-size: 1.4rem; font-weight: 800; color: #111; letter-spacing: 0.5px; }
.report-type { font-size: 2.2rem; font-weight: 300; color: #555; }
.report-date { font-size: 1.1rem; font-style: italic; color: #777; }
.border-bottom-double { border-bottom: var(--border-double); }
.border-top-double { border-top: var(--border-double); }
.ls-1 { letter-spacing: 1px; }
.fw-mono { font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; }
.account-table { width: 100%; border-collapse: separate; border-spacing: 0 4px; }
.account-table th { font-weight: 800; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
.account-table td { padding: 12px 8px; border-bottom: 1px solid #f0f0f0; }
.glass-action-bar { background: rgba(255,255,255,0.9) !important; backdrop-filter: blur(10px); border-radius: 1.25rem !important; border: 1px solid rgba(0,0,0,0.05) !important; }
.icon-circle { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 1.2rem; }
.btn-dark { background-color: #111 !important; border: none; transition: all 0.3s ease; }
.btn-dark:hover { background-color: #333 !important; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
    @media print {
        .glass-action-bar, .d-print-none, #summaryCards { display: none !important; }
        body { background: #fff !important; }
        .report-paper { border: none !important; padding: 0 !important; margin: 0 !important; border-radius: 0; }
        .container { width: 100% !important; max-width: 100% !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; color: #000 !important; }
        .table td { border: 1px solid #dee2e6 !important; }
    }
</style>

<script>
$(document).ready(function() {
    if(typeof logReportAction === 'function') {
        logReportAction('Viewed Trial Balance', 'User analyzed the trial balance report as of date <?= $as_of_date ?>');
    }
});
</script>

<?php 
includeFooter(); 
ob_end_flush();
?>
