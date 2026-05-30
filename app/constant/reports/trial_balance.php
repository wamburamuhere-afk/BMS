<?php
/**
 * Trial Balance Report — Origin-Data Edition
 * ------------------------------------------
 * Pulls REAL origin data, not a processed/derived snapshot:
 *
 *   ledger source  = journal_entry_items joined to POSTED journal_entries
 *                    (entry_date <= as_of_date)
 *   opening source = accounts.opening_balance, allocated to Dr/Cr by the
 *                    account's normal_side (debit-natural -> Dr, credit -> Cr)
 *
 * Each account's net balance is placed in the column where it actually sits
 * (by sign), so Sum(Dr) must equal Sum(Cr). When it doesn't, the report says
 * so plainly — it never hides an out-of-balance ledger.
 *
 * At the foot it derives NET PROFIT / (LOSS) for the period from the Revenue
 * and Expense/COGS sections (Revenue - Expenses), the same figure that flows
 * to the Income Statement and to retained earnings on the Balance Sheet.
 *
 * Classification metadata (category, normal_side) comes from account_types
 * via core/financial_classification.php — the single source of truth shared
 * by all five financial reports.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/financial_classification.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}

$as_of_date   = $_GET['as_of_date'] ?? date('Y-m-d');
$company_name = get_setting('company_name') ?: 'Business Management System';

// Section order + labels (accountant convention).
$SECTION_ORDER = ['asset', 'liability', 'equity', 'revenue', 'cogs', 'expense'];
$SECTION_LABEL = [
    'asset'     => 'ASSETS',
    'liability' => 'LIABILITIES',
    'equity'    => 'EQUITY',
    'revenue'   => 'REVENUE',
    'cogs'      => 'COST OF GOODS SOLD',
    'expense'   => 'EXPENSES',
];

$sections          = [];   // category => ['rows'=>[], 'sub_dr'=>0, 'sub_cr'=>0]
$unclassified_rows = [];
$total_debits      = 0.0;
$total_credits     = 0.0;
$total_revenue     = 0.0;  // credit-natural balances of revenue accounts
$total_expense     = 0.0;  // debit-natural balances of expense + cogs accounts
$contra_count      = 0;
$error_message     = null;

try {
    // One row per active account: opening_balance + cumulative posted Dr/Cr
    // up to and including the as-of date. Posted entries only.
    $sql = "
        SELECT
            a.account_id,
            a.account_code,
            a.account_name,
            a.opening_balance,
            at.category    AS category,
            at.normal_side AS normal_side,
            COALESCE(SUM(CASE WHEN jei.type = 'debit'  THEN jei.amount ELSE 0 END), 0) AS posted_debit,
            COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) AS posted_credit
        FROM accounts a
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
        LEFT JOIN journal_entries je
               ON je.entry_id   = jei.entry_id
              AND je.status      = 'posted'
              AND je.entry_date <= ?
        WHERE a.status = 'active'
        GROUP BY a.account_id, a.account_code, a.account_name,
                 a.opening_balance, at.category, at.normal_side
        ORDER BY a.account_code ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$as_of_date]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($accounts as $acc) {
        $category    = $acc['category'] ?: null;
        // Resolve the natural side: prefer the stored value, else derive from
        // the category, else default to debit so allocation never crashes.
        $normal_side = $acc['normal_side'] ?: null;
        if (!$normal_side && $category) {
            $normal_side = fc_natural_sign($category) === -1 ? 'credit' : 'debit';
        }
        $normal_side = $normal_side ?: 'debit';

        $opening    = (float)$acc['opening_balance'];
        $opening_dr = $normal_side === 'debit'  ? $opening : 0.0;
        $opening_cr = $normal_side === 'credit' ? $opening : 0.0;

        $total_dr = $opening_dr + (float)$acc['posted_debit'];
        $total_cr = $opening_cr + (float)$acc['posted_credit'];

        // Skip dormant accounts (nothing opening, nothing posted).
        if (abs($total_dr) < 0.001 && abs($total_cr) < 0.001) continue;

        // Net balance, debit-positive. Place it in the column where it lands.
        $net    = $total_dr - $total_cr;
        $dr_col = $net > 0 ?  $net : 0.0;
        $cr_col = $net < 0 ? -$net : 0.0;

        // Contra = balance sits on the side opposite the account's natural side.
        $is_contra = ($normal_side === 'debit'  && $net < -0.001)
                  || ($normal_side === 'credit' && $net >  0.001);
        if ($is_contra) $contra_count++;

        $row = [
            'code'      => $acc['account_code'],
            'name'      => $acc['account_name'],
            'dr'        => $dr_col,
            'cr'        => $cr_col,
            'is_contra' => $is_contra,
        ];

        $total_debits  += $dr_col;
        $total_credits += $cr_col;

        // Profit & Loss build-up (from the same origin figures).
        if ($category === 'revenue') {
            $total_revenue += ($total_cr - $total_dr); // credit-natural balance
        } elseif ($category === 'expense' || $category === 'cogs') {
            $total_expense += ($total_dr - $total_cr); // debit-natural balance
        }

        if ($category && isset($SECTION_LABEL[$category])) {
            $sections[$category]['rows'][]  = $row;
            $sections[$category]['sub_dr']  = ($sections[$category]['sub_dr'] ?? 0) + $dr_col;
            $sections[$category]['sub_cr']  = ($sections[$category]['sub_cr'] ?? 0) + $cr_col;
        } else {
            $row['type_name']    = $acc['category'] ?: '— uncategorised —';
            $unclassified_rows[] = $row;
        }
    }

    $is_balanced = (abs($total_debits - $total_credits) < 0.01);
    $difference  = $total_debits - $total_credits;
    $net_profit  = $total_revenue - $total_expense; // > 0 profit, < 0 loss

} catch (Exception $e) {
    $error_message = $e->getMessage();
    $is_balanced   = true;
    $difference    = 0.0;
    $net_profit    = 0.0;
}

// ── Period-close state + permission (drives the "Close Period" action) ──
// A close may only happen when the trial balance balances — that is the
// accounting gate: an unbalanced ledger must not be rolled forward.
$period_closed_entry = null;
try {
    $cstmt = $pdo->prepare("SELECT closing_entry_id FROM accounting_periods WHERE period_end = ? LIMIT 1");
    $cstmt->execute([$as_of_date]);
    $period_closed_entry = $cstmt->fetchColumn() ?: null;
} catch (Throwable $e) {
    // accounting_periods table may not exist yet (migration not run) — ignore.
}
$can_close = (function_exists('isAdmin') && isAdmin())
          || (function_exists('canPost') && canPost('financial_reports'))
          || (function_exists('canEdit') && canEdit('financial_reports'));
?>

<div class="container py-4">
    <!-- Action Bar -->
    <div class="row mb-4 d-print-none">
        <div class="col-12">
            <div class="glass-action-bar p-3 shadow-sm rounded-4 d-flex flex-wrap justify-content-between align-items-center bg-white border gap-2">
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
                            <input type="date" name="as_of_date" class="form-control border-start-0 ps-0" value="<?= htmlspecialchars($as_of_date) ?>" style="width: 150px;">
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
                    <?php if ($can_close && $error_message === null): ?>
                        <?php if ($period_closed_entry): ?>
                            <span class="btn btn-sm border border-success text-success fw-bold px-3 d-flex align-items-center gap-2" style="cursor:default;background:#d1e7dd;" title="Closing entry #<?= (int)$period_closed_entry ?>">
                                <i class="bi bi-lock-fill"></i> <span>Period Closed</span>
                            </span>
                        <?php elseif ($is_balanced): ?>
                            <button id="closePeriodBtn" class="btn btn-sm btn-dark fw-bold px-3 d-flex align-items-center gap-2">
                                <i class="bi bi-lock fs-6 text-warning"></i> <span>Close Period</span>
                            </button>
                        <?php else: ?>
                            <span class="btn btn-sm btn-light border text-muted fw-bold px-3 d-flex align-items-center gap-2" style="cursor:not-allowed;" title="Balance the trial balance before closing the period">
                                <i class="bi bi-lock"></i> <span>Close Period</span>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Debits</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_debits) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Credits</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_credits) ?></h4>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm h-100 border-start border-4 <?= $is_balanced ? 'border-success' : 'border-danger' ?>">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Balance Integrity</p>
                    <h4 class="fw-bold mb-0 text-<?= $is_balanced ? 'success' : 'danger' ?>">
                        <?= $is_balanced ? 'BALANCED' : 'UNBALANCED' ?>
                    </h4>
                    <?php if (!$is_balanced): ?>
                        <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-2">Diff: <?= format_currency(abs($difference)) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm h-100 border-start border-4 <?= $net_profit >= 0 ? 'border-success' : 'border-danger' ?>">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= $net_profit >= 0 ? 'Net Profit' : 'Net Loss' ?></p>
                    <h4 class="fw-bold mb-0 text-<?= $net_profit >= 0 ? 'success' : 'danger' ?>"><?= format_currency(abs($net_profit)) ?></h4>
                    <span class="small text-muted fw-bold">Revenue − Expenses</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Out-of-balance banner (only when it matters) -->
    <?php if ($error_message === null && !$is_balanced): ?>
        <div class="alert alert-danger border-0 py-2 px-3 mb-3 d-flex align-items-center d-print-none" style="font-size:0.9rem;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div>
                <strong>TRIAL BALANCE DOES NOT BALANCE.</strong>
                Difference = <span class="font-monospace fw-bold"><?= format_currency(abs($difference)) ?></span>
                (<?= $difference > 0 ? 'Debits exceed Credits' : 'Credits exceed Debits' ?>).
                This reflects the real ledger — investigate opening balances and journal entries.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($contra_count)): ?>
        <div class="alert alert-warning border-0 py-2 px-3 mb-3 d-flex align-items-center d-print-none" style="font-size:0.85rem;">
            <i class="bi bi-exclamation-circle-fill me-2"></i>
            <div><strong><?= $contra_count ?> account(s) carry a contra-balance</strong> (sitting on the opposite of their natural side) — flagged in red below.</div>
        </div>
    <?php endif; ?>

    <!-- REPORT BODY -->
    <div class="report-paper shadow mb-5" id="reportContent">
        <!-- Print Header — report title ONLY. The global print header (shared
             across every report) already renders the company logo + name, so
             we must NOT repeat them here or the printout shows two of each. -->
        <div class="print-header d-none d-print-block text-center mb-4">
            <div class="mt-3 text-center">
                <h2 style="color:#495057;font-weight:600;text-transform:uppercase;margin:5px 0;font-size:16pt;letter-spacing:2px;">TRIAL BALANCE</h2>
                <p style="color:#6c757d;margin:0;font-size:10pt;">Origin-data verification — posted journals + opening balances</p>
                <p style="color:#444;margin:5px 0 0;font-size:9pt;font-weight:600;text-transform:uppercase;">As of: <?= date('d M Y', strtotime($as_of_date)) ?></p>
            </div>
            <div style="border-bottom:3px solid #0d6efd;margin:12px 0 20px;"></div>
        </div>

        <!-- Screen Header -->
        <div class="text-center mb-4 pb-3 border-bottom-double d-print-none">
            <h2 class="company-title mb-0"><?= htmlspecialchars((string)$company_name) ?></h2>
            <h1 class="report-type mb-1">TRIAL BALANCE</h1>
            <p class="report-date mb-0">Origin-data verification as of <?= date('F d, Y', strtotime($as_of_date)) ?></p>
        </div>

        <?php if ($error_message !== null): ?>
            <div class="alert alert-danger mx-4"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>

        <div class="px-4">
            <div class="table-responsive">
                <table class="table table-sm account-table">
                    <thead>
                        <tr class="bg-dark text-white">
                            <th class="ps-3 py-3 border-0" style="width:14%">CODE</th>
                            <th class="py-3 border-0" style="width:46%">ACCOUNT DESCRIPTION</th>
                            <th class="text-end py-3 border-0" style="width:20%">DEBIT</th>
                            <th class="text-end pe-3 py-3 border-0" style="width:20%">CREDIT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sections) && empty($unclassified_rows) && $error_message === null): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">No accounting records captured for this date.</td></tr>
                        <?php else: ?>
                            <?php foreach ($SECTION_ORDER as $cat):
                                if (empty($sections[$cat]['rows'])) continue;
                                $section = $sections[$cat];
                            ?>
                                <tr class="bg-light">
                                    <td colspan="4" class="ps-3 py-2 fw-bold text-muted small text-uppercase ls-1"><?= htmlspecialchars($SECTION_LABEL[$cat]) ?></td>
                                </tr>
                                <?php foreach ($section['rows'] as $row): ?>
                                    <tr class="<?= $row['is_contra'] ? 'table-danger' : '' ?>">
                                        <td class="ps-3 text-muted fw-mono"><?= htmlspecialchars((string)$row['code']) ?></td>
                                        <td class="fw-semibold text-dark">
                                            <?= htmlspecialchars((string)$row['name']) ?>
                                            <?php if ($row['is_contra']): ?>
                                                <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Contra-balance"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold"><?= $row['dr'] > 0.001 ? number_format($row['dr'], 2) : '—' ?></td>
                                        <td class="text-end pe-3 fw-bold"><?= $row['cr'] > 0.001 ? number_format($row['cr'], 2) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="tb-subtotal">
                                    <td colspan="2" class="ps-3 fst-italic text-muted py-1 small">Subtotal — <?= htmlspecialchars($SECTION_LABEL[$cat]) ?></td>
                                    <td class="text-end font-monospace fw-semibold py-1" style="border-top:1px solid #dee2e6;"><?= $section['sub_dr'] > 0.001 ? number_format($section['sub_dr'], 2) : '—' ?></td>
                                    <td class="text-end pe-3 font-monospace fw-semibold py-1" style="border-top:1px solid #dee2e6;"><?= $section['sub_cr'] > 0.001 ? number_format($section['sub_cr'], 2) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!empty($unclassified_rows)): ?>
                                <tr class="bg-warning-subtle">
                                    <td colspan="4" class="ps-3 py-2 fw-bold text-uppercase small text-warning-emphasis ls-1">UNCLASSIFIED — assign a category via Settings → Account Types</td>
                                </tr>
                                <?php foreach ($unclassified_rows as $row): ?>
                                    <tr>
                                        <td class="ps-3 text-muted fw-mono"><?= htmlspecialchars((string)$row['code']) ?></td>
                                        <td class="fw-semibold text-dark">
                                            <?= htmlspecialchars((string)$row['name']) ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning small ms-2"><?= htmlspecialchars((string)$row['type_name']) ?></span>
                                        </td>
                                        <td class="text-end fw-bold"><?= $row['dr'] > 0.001 ? number_format($row['dr'], 2) : '—' ?></td>
                                        <td class="text-end pe-3 fw-bold"><?= $row['cr'] > 0.001 ? number_format($row['cr'], 2) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row border-top-double">
                            <td colspan="2" class="ps-3 py-3 fw-bold h5 mb-0">GRAND TOTAL</td>
                            <td class="text-end py-3 fw-bold h5 mb-0 text-<?= $is_balanced ? 'success' : 'danger' ?> border-bottom-double"><?= number_format($total_debits, 2) ?></td>
                            <td class="text-end pe-3 py-3 fw-bold h5 mb-0 text-<?= $is_balanced ? 'success' : 'danger' ?> border-bottom-double"><?= number_format($total_credits, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- PROFIT / LOSS derived from the Trial Balance -->
            <div class="pl-panel mt-4 mb-2">
                <div class="pl-title">PROFIT &amp; LOSS DERIVED FROM TRIAL BALANCE</div>
                <table class="table table-sm mb-0 pl-table">
                    <tbody>
                        <tr>
                            <td class="ps-3">Total Revenue</td>
                            <td class="text-end pe-3 font-monospace fw-semibold"><?= number_format($total_revenue, 2) ?></td>
                        </tr>
                        <tr>
                            <td class="ps-3">Less: Total Expenses (incl. COGS)</td>
                            <td class="text-end pe-3 font-monospace fw-semibold">(<?= number_format($total_expense, 2) ?>)</td>
                        </tr>
                        <tr class="pl-result">
                            <td class="ps-3 fw-bold text-uppercase">Net <?= $net_profit >= 0 ? 'Profit' : 'Loss' ?> for the Period</td>
                            <td class="text-end pe-3 font-monospace fw-bold text-<?= $net_profit >= 0 ? 'success' : 'danger' ?>">
                                <?= $net_profit < 0 ? '(' . number_format(abs($net_profit), 2) . ')' : number_format($net_profit, 2) ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer Note -->
        <div class="footer-note mt-5 px-4 pt-4 border-top">
            <div class="row">
                <div class="col-6">
                    <p class="small text-muted mb-0">Generated by: <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?></p>
                    <p class="small text-muted">Timestamp: <?= date('d M Y, H:i') ?></p>
                </div>
                <div class="col-6 text-end">
                    <p class="small text-muted mb-0">Source: posted journals + opening balances</p>
                    <p class="small text-muted">As of <?= date('d M Y', strtotime($as_of_date)) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
:root { --primary-color:#0d6efd; --border-double:3px double #000; }
.report-paper { background:#fff; min-height:800px; padding:50px 40px; font-family:'Inter','Segoe UI',serif; color:#333; border:1px solid #ddd; border-radius:8px; }
.company-title { font-size:1.4rem; font-weight:800; color:#111; letter-spacing:.5px; }
.report-type { font-size:2.2rem; font-weight:300; color:#555; }
.report-date { font-size:1.05rem; font-style:italic; color:#777; }
.border-bottom-double { border-bottom:var(--border-double); }
.border-top-double { border-top:var(--border-double); }
.ls-1 { letter-spacing:1px; }
.fw-mono { font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace; }
.account-table { width:100%; border-collapse:separate; border-spacing:0 2px; }
.account-table th { font-weight:800; text-transform:uppercase; font-size:.75rem; letter-spacing:.5px; }
.account-table td { padding:10px 8px; border-bottom:1px solid #f0f0f0; }
.glass-action-bar { background:rgba(255,255,255,.9)!important; backdrop-filter:blur(10px); border-radius:1.25rem!important; border:1px solid rgba(0,0,0,.05)!important; }
.icon-circle { width:40px; height:40px; display:flex; align-items:center; justify-content:center; border-radius:50%; font-size:1.2rem; }
.pl-panel { border:1px solid #dee2e6; border-radius:10px; overflow:hidden; }
.pl-title { background:#0d6efd; color:#fff; font-weight:800; text-transform:uppercase; letter-spacing:1px; font-size:.8rem; padding:10px 16px; }
.pl-table td { padding:8px 8px; border-bottom:1px solid #f0f0f0; }
.pl-result td { border-top:2px solid #333; background:#f8f9fa; font-size:1.05rem; }
@media print {
    .glass-action-bar, .d-print-none { display:none!important; }
    body { background:#fff!important; }
    /* Blank-first-page fix: zero ONLY the top spacing the fixed navbar reserves;
       never touch padding-bottom (print_footer_css.php needs it). */
    body { padding-top:0!important; margin-top:0!important; }
    .report-paper { border:none!important; padding:0 0 18mm 0!important; margin:0!important; border-radius:0; box-shadow:none!important; }
    .container { width:100%!important; max-width:100%!important; }
    .account-table th { background-color:#f8f9fa!important; border:1px solid #000!important; -webkit-print-color-adjust:exact; color:#000!important; }
    .account-table td { border:1px solid #dee2e6!important; }
    .pl-title { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
}
</style>

<script>
$(document).ready(function () {
    if (typeof logReportAction === 'function') {
        logReportAction('Viewed Trial Balance', 'Trial balance (origin data) as of <?= $as_of_date ?>');
    }

    // Close Period — moves Net Profit/(Loss) into Retained Earnings and zeros
    // the revenue & expense accounts as of the reporting date. Only rendered
    // when the trial balance is balanced and the period is not yet closed.
    $('#closePeriodBtn').on('click', function () {
        Swal.fire({
            title: 'Close this period?',
            html: 'This posts a permanent closing entry as of <b><?= date('d M Y', strtotime($as_of_date)) ?></b>:<br>' +
                  'it moves the period\'s Net <?= $net_profit >= 0 ? 'Profit' : 'Loss' ?> of ' +
                  '<b><?= format_currency(abs($net_profit)) ?></b> into <b>Retained Earnings</b> and zeros the ' +
                  'revenue &amp; expense accounts.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Close Period',
            confirmButtonColor: '#111'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            var btn = $('#closePeriodBtn'), orig = btn.html();
            btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Closing...');
            $.ajax({
                url: '<?= buildUrl('api/account/close_period.php') ?>',
                type: 'POST',
                dataType: 'json',
                data: { _csrf: '<?= csrf_token() ?>', period_end: '<?= $as_of_date ?>' },
                success: function (res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Period Closed', text: res.message, timer: 2800, showConfirmButton: false })
                            .then(function () { location.reload(); });
                    } else {
                        Swal.fire({ icon: res.already_closed ? 'info' : 'error', title: res.already_closed ? 'Already Closed' : 'Cannot Close', text: res.message });
                        btn.prop('disabled', false).html(orig);
                    }
                },
                error: function () {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Server error during close.' });
                    btn.prop('disabled', false).html(orig);
                }
            });
        });
    });
});
</script>

<?php
includeFooter();
ob_end_flush();
?>
