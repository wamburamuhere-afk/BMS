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
// Presentation format: 'european' = horizontal/two-sided (default, existing) |
// 'british' = vertical/report form (net current assets → capital employed).
$format = (($_GET['format'] ?? 'european') === 'british') ? 'british' : 'european';
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

// Load canonical classification helper (Phase 1).
require_once __DIR__ . '/../../../core/financial_classification.php';
require_once __DIR__ . '/../../../core/vat.php';
require_once __DIR__ . '/../../../core/wht.php';

// Defensive defaults — keep the render layer safe even if the SQL
// below throws (e.g. classification columns missing on a server where
// the Phase 1 migration hasn't run yet).
$sections = [
    'assets'      => ['current' => [], 'non_current' => [], 'total_current' => 0, 'total_non_current' => 0, 'total' => 0],
    'liabilities' => ['current' => [], 'non_current' => [], 'total_current' => 0, 'total_non_current' => 0, 'total' => 0],
    'equity'      => ['accounts' => [], 'total' => 0],
];
$net_income             = 0.0;
$bs_balanced            = true;
$bs_difference          = 0.0;
$missing_classification = [];
$error_message          = null;

// Guard: classification columns must exist on this server (see migration
// 2026_05_27). Show a clear banner instead of an SQL error if they're missing.
if (!fc_classification_ready($pdo)) {
    echo fc_classification_missing_banner('Balance Sheet');
    includeFooter();
    ob_end_flush();
    return;
}

try {
    // Single query — pulls every BS account along with its canonical
    // classification (category + normal_side). We sub-classify into
    // current vs non-current using the type_name as a hint (substring
    // match on "current" / "non" / "fixed" / "long term"), since we
    // don't yet have a dedicated `is_current` column.
    // Current vs non-current now comes from account_types.liquidity (migration
    // 2026_06_03). Select it only when the column exists so the report still
    // runs on a server where that migration hasn't applied yet (staged rollout).
    $hasLiquidity = fc_has_liquidity($pdo);
    $liqSelect    = $hasLiquidity ? "at.liquidity AS liquidity," : "NULL AS liquidity,";
    $liqGroup     = $hasLiquidity ? ", at.liquidity" : "";

    $sql = "
        SELECT
            a.account_id,
            a.account_name,
            a.account_code,
            a.opening_balance,
            at.type_name        AS type_name,
            at.category         AS category,
            at.normal_side      AS normal_side,
            $liqSelect
            COALESCE(SUM(CASE WHEN jei.type = 'debit'  THEN jei.amount ELSE 0 END), 0) AS total_debit,
            COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) AS total_credit
        FROM accounts a
        JOIN account_types at ON a.account_type_id = at.type_id
        LEFT JOIN journal_entry_items jei ON a.account_id = jei.account_id
        LEFT JOIN journal_entries je
               ON jei.entry_id = je.entry_id
              AND je.entry_date <= ?
              AND je.status = 'posted'
        WHERE a.status = 'active'
          AND at.category IN ('asset','liability','equity')
        GROUP BY a.account_id, a.account_name, a.account_code, a.opening_balance, at.type_name, at.category, at.normal_side$liqGroup
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
        $category = $acc['category'];
        $debit    = (float) $acc['total_debit'];
        $credit   = (float) $acc['total_credit'];

        // Natural-side balance from the canonical helper, PLUS the account's
        // opening_balance (allocated to the natural side). This makes the
        // Balance Sheet read the same origin data as the Trial Balance —
        // without it the BS silently drops every opening balance and the two
        // reports disagree.
        $balance = fc_balance($category, $debit, $credit) + (float)$acc['opening_balance'];
        if (abs($balance) < 0.001) continue;

        // Current vs non-current (IAS 1 split). Now DATA-DRIVEN from
        // account_types.liquidity (migration 2026_06_03) via fc_resolve_liquidity(),
        // which falls back to the legacy account-name heuristic for any type not
        // yet classified — so there is no regression on un-seeded rows.
        $isFixed = fc_resolve_liquidity(
            $acc['liquidity'] ?? null,
            $acc['type_name'] ?? '',
            $acc['account_name'] ?? ''
        ) === 'non_current';
        $isLong  = $isFixed;

        $row = $acc + ['balance' => $balance];

        if ($category === 'asset') {
            if ($isFixed) {
                $sections['assets']['non_current'][] = $row;
                $sections['assets']['total_non_current'] += $balance;
            } else {
                $sections['assets']['current'][] = $row;
                $sections['assets']['total_current'] += $balance;
            }
            $sections['assets']['total'] += $balance;
        } elseif ($category === 'liability') {
            if ($isFixed || $isLong) {
                $sections['liabilities']['non_current'][] = $row;
                $sections['liabilities']['total_non_current'] += $balance;
            } else {
                $sections['liabilities']['current'][] = $row;
                $sections['liabilities']['total_current'] += $balance;
            }
            $sections['liabilities']['total'] += $balance;
        } elseif ($category === 'equity') {
            $sections['equity']['accounts'][] = $row;
            $sections['equity']['total'] += $balance;
        }
    }

    // ── VAT control position ────────────────────────────────────────────
    // The VAT control accounts (Output VAT Payable, Input VAT Recoverable) are
    // maintained in the invoice sub-ledger, not the formal GL this page reads,
    // so inject their document-derived balances here — the SAME drift-proof
    // figures the Tax Report shows (summed from posted invoice VAT):
    //   Output VAT = a LIABILITY,  Input VAT = an ASSET.
    if (function_exists('vatNetPosition')) {
        $vat = vatNetPosition($pdo);
        if (abs($vat['input']) >= 0.005) {
            $sections['assets']['current'][]      = ['account_name' => 'Input VAT Recoverable', 'balance' => $vat['input']];
            $sections['assets']['total_current'] += $vat['input'];
            $sections['assets']['total']         += $vat['input'];
        }
        if (abs($vat['output']) >= 0.005) {
            $sections['liabilities']['current'][]      = ['account_name' => 'Output VAT Payable', 'balance' => $vat['output']];
            $sections['liabilities']['total_current'] += $vat['output'];
            $sections['liabilities']['total']         += $vat['output'];
        }
    }

    // ── WHT control position ────────────────────────────────────────────
    // Withholding tax deducted from supplier/sub-contractor payments but not yet
    // remitted to TRA — a current LIABILITY, summed drift-proof from the posted
    // documents (Σ wht_posted), exactly mirroring the VAT block above. Independent
    // of VAT (own account/columns), so the VAT figures above are unaffected.
    if (function_exists('whtPosition')) {
        $wht = whtPosition($pdo);
        if (abs($wht['payable']) >= 0.005) {
            $sections['liabilities']['current'][]      = ['account_name' => 'WHT Payable', 'balance' => $wht['payable']];
            $sections['liabilities']['total_current'] += $wht['payable'];
            $sections['liabilities']['total']         += $wht['payable'];
        }
    }

    // ── WHT Receivable position (sales side) ────────────────────────────
    // Withholding tax customers have withheld from us — a tax credit we reclaim,
    // i.e. a current ASSET (Σ payments.wht_posted). Mirror of WHT Payable above,
    // on the asset side beside Input VAT. Own account/columns — VAT unaffected.
    if (function_exists('whtReceivablePosition')) {
        $whtr = whtReceivablePosition($pdo);
        if (abs($whtr['receivable']) >= 0.005) {
            $sections['assets']['current'][]      = ['account_name' => 'WHT Receivable', 'balance' => $whtr['receivable']];
            $sections['assets']['total_current'] += $whtr['receivable'];
            $sections['assets']['total']         += $whtr['receivable'];
        }
    }

    // ── Trade Receivables / Payables + accruals (Phase 1) ────────────────
    // Document-derived positions injected exactly like VAT/WHT above: the UNPAID
    // balance of operational documents becomes a current asset (owed to us) or
    // current liability (we owe). Drift-proof — summed live from the source docs.
    require_once __DIR__ . '/../../../core/receivables_payables.php';

    // Accounts Receivable — unpaid customer invoices (ASSET).
    if (function_exists('arInvoicesPosition')) {
        $ar = arInvoicesPosition($pdo);
        if (abs($ar['receivable']) >= 0.005) {
            $sections['assets']['current'][]      = ['account_name' => 'Accounts Receivable (Trade)', 'balance' => $ar['receivable']];
            $sections['assets']['total_current'] += $ar['receivable'];
            $sections['assets']['total']         += $ar['receivable'];
        }
    }
    // Accounts Payable — unpaid supplier / received invoices (LIABILITY).
    if (function_exists('apSupplierInvoicesPosition')) {
        $ap = apSupplierInvoicesPosition($pdo);
        if (abs($ap['payable']) >= 0.005) {
            $sections['liabilities']['current'][]      = ['account_name' => 'Accounts Payable (Trade)', 'balance' => $ap['payable']];
            $sections['liabilities']['total_current'] += $ap['payable'];
            $sections['liabilities']['total']         += $ap['payable'];
        }
    }
    // Accrued Expenses — incurred-but-unpaid expenses (LIABILITY).
    if (function_exists('accruedExpensesPosition')) {
        $acc = accruedExpensesPosition($pdo);
        if (abs($acc['payable']) >= 0.005) {
            $sections['liabilities']['current'][]      = ['account_name' => 'Accrued Expenses', 'balance' => $acc['payable']];
            $sections['liabilities']['total_current'] += $acc['payable'];
            $sections['liabilities']['total']         += $acc['payable'];
        }
    }
    // Refunds Payable — refunds owed to customers, not yet settled (LIABILITY).
    if (function_exists('refundsPayablePosition')) {
        $rf = refundsPayablePosition($pdo);
        if (abs($rf['payable']) >= 0.005) {
            $sections['liabilities']['current'][]      = ['account_name' => 'Refunds Payable', 'balance' => $rf['payable']];
            $sections['liabilities']['total_current'] += $rf['payable'];
            $sections['liabilities']['total']         += $rf['payable'];
        }
    }

    // Retained Earnings = NET PROFIT to-date. Pulls every P&L account
    // (revenue + expense + cogs) up to and including the as-of date,
    // using natural-side aggregation via fc_balance() per account.
    $is_type_ids = fc_type_ids_for_categories($pdo, ['revenue', 'expense', 'cogs']);
    $net_income = 0.0;
    if (!empty($is_type_ids)) {
        $ph = implode(',', array_fill(0, count($is_type_ids), '?'));
        $is_sql = "
            SELECT at.category AS category,
                   COALESCE(SUM(CASE WHEN jei.type = 'debit'  THEN jei.amount ELSE 0 END), 0) AS dr,
                   COALESCE(SUM(CASE WHEN jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) AS cr
              FROM accounts a
              JOIN account_types at ON a.account_type_id = at.type_id
         LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
         LEFT JOIN journal_entries je
                ON je.entry_id = jei.entry_id
               AND je.entry_date <= ?
               AND je.status = 'posted'
             WHERE a.account_type_id IN ($ph)
               AND a.status = 'active'
          GROUP BY at.category
        ";
        $stmt = $pdo->prepare($is_sql);
        $stmt->execute(array_merge([$as_of_date], $is_type_ids));
        $cat_totals = ['revenue' => 0.0, 'expense' => 0.0, 'cogs' => 0.0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cat_totals[$r['category']] = fc_balance($r['category'], (float)$r['dr'], (float)$r['cr']);
        }
        // Fold opening balances on P&L accounts into the category totals so
        // the Retained Earnings figure ties to the Trial Balance. Done in a
        // separate, journal-free query to avoid the join row-multiplication
        // that would inflate a SUM(opening_balance).
        $op_stmt = $pdo->prepare("
            SELECT at.category AS category, COALESCE(SUM(a.opening_balance), 0) AS ob
              FROM accounts a
              JOIN account_types at ON a.account_type_id = at.type_id
             WHERE a.account_type_id IN ($ph) AND a.status = 'active'
          GROUP BY at.category
        ");
        $op_stmt->execute($is_type_ids);
        foreach ($op_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($cat_totals[$r['category']])) {
                $cat_totals[$r['category']] += (float)$r['ob'];
            }
        }
        // Net profit = Revenue - COGS - Expenses
        $net_income = $cat_totals['revenue'] - $cat_totals['cogs'] - $cat_totals['expense'];
    }

    // Retained Earnings goes into Equity. Total Equity = sum of explicit
    // equity accounts + cumulative net profit.
    $equity_explicit = $sections['equity']['total'];
    $sections['equity']['total'] = $equity_explicit + $net_income;

    // Balance check — the cornerstone identity: Assets = Liabilities + Equity.
    $total_le      = $sections['liabilities']['total'] + $sections['equity']['total'];
    $bs_difference = $sections['assets']['total'] - $total_le;
    $bs_balanced   = abs($bs_difference) < 0.01;

    // Surface unclassified types as a banner.
    $missing_classification = fc_unclassified_types($pdo);

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
                        <input type="hidden" name="format" value="<?= $format ?>">
                        <div class="input-group input-group-sm report-date-picker">
                            <span class="input-group-text bg-white border-end-0 text-muted">As Of</span>
                            <input type="date" name="as_of_date" class="form-control border-start-0 ps-0" value="<?= $as_of_date ?>" style="width: 150px;">
                            <button type="submit" class="btn btn-primary px-3 fw-bold">
                                <i class="bi bi-arrow-clockwise me-1"></i> Update
                            </button>
                        </div>
                    </form>
                    <!-- Format toggle: European (horizontal) | British (vertical) -->
                    <div class="btn-group btn-group-sm shadow-sm" role="group" aria-label="Balance sheet format">
                        <a href="?as_of_date=<?= urlencode($as_of_date) ?>&format=european"
                           class="btn <?= $format === 'european' ? 'btn-primary' : 'btn-outline-primary' ?> fw-bold"
                           title="Horizontal / two-sided (Assets | Liabilities & Equity)">
                            <i class="bi bi-layout-split me-1"></i> European
                        </a>
                        <a href="?as_of_date=<?= urlencode($as_of_date) ?>&format=british"
                           class="btn <?= $format === 'british' ? 'btn-primary' : 'btn-outline-primary' ?> fw-bold"
                           title="Vertical / report form (Net current assets → Capital employed)">
                            <i class="bi bi-list-columns-reverse me-1"></i> British
                        </a>
                    </div>
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

    <!-- Balance-check banner — accountant's first sanity check -->
    <?php if (!isset($error_message)): ?>
        <?php if ($bs_balanced): ?>
        
        <?php else: ?>
        <div class="alert alert-danger border-0 py-2 px-3 mb-3 d-flex align-items-center d-print-none" style="font-size: 0.9rem;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div>
                <strong>BALANCE SHEET DOES NOT BALANCE.</strong>
                Difference = <span class="font-monospace fw-bold"><?= number_format(abs($bs_difference), 2) ?></span>
                (<?= $bs_difference > 0 ? 'Assets exceed Liab.+Equity' : 'Liab.+Equity exceed Assets' ?>).
                Check the Trial Balance for posting errors before relying on this report.
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($missing_classification ?? [])): ?>
    <div class="alert alert-warning border-0 py-2 px-3 mb-3 d-print-none" style="font-size: 0.85rem;">
        <i class="bi bi-info-circle-fill me-2"></i>
        <strong><?= count($missing_classification) ?> account type(s) are unclassified.</strong>
        Their accounts may be missing from this Balance Sheet — classify them via
        Settings → Account Types.
    </div>
    <?php endif; ?>

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

        <?php if ($format === 'european'): ?>
        <!-- ══════════ EUROPEAN (horizontal / two-sided) ══════════ -->
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
                        <tr class="retained-earnings-row">
                            <td>
                                <strong>Retained Earnings (Net Profit to Date)</strong>
                                <br><small class="text-muted">computed from Revenue − COGS − Expenses up to <?= date('d M Y', strtotime($as_of_date)) ?></small>
                            </td>
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
                        <span class="fw-bold h5 mb-0">TOTAL LIABILITIES &amp; EQUITY</span>
                        <span class="fw-bold h5 mb-0 total-amount double-underline"><?= format_accounting($sections['liabilities']['total'] + $sections['equity']['total']) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($format === 'british'):
            // British vertical (report) form — reuses the SAME $sections data.
            $b_nonCurrentAssets = $sections['assets']['total_non_current'];
            $b_currentAssets    = $sections['assets']['total_current'];
            $b_currentLiab      = $sections['liabilities']['total_current'];
            $b_nonCurrentLiab   = $sections['liabilities']['total_non_current'];
            $b_netCurrentAssets = $b_currentAssets - $b_currentLiab;          // working capital
            $b_totalAssetsLessCL= $b_nonCurrentAssets + $b_netCurrentAssets;
            $b_netAssets        = $b_totalAssetsLessCL - $b_nonCurrentLiab;
            $b_capitalEmployed  = $sections['equity']['total'];               // incl. retained earnings
        ?>
        <!-- ══════════ BRITISH (vertical / report form) ══════════ -->
        <div class="px-4 british-format" style="max-width: 780px; margin: 0 auto;">
            <table class="table table-borderless account-table mb-0">
                <colgroup><col><col style="width:140px;"><col style="width:160px;"></colgroup>

                <!-- Fixed (Non-current) Assets -->
                <tr><td colspan="3" class="brit-head">FIXED (NON-CURRENT) ASSETS</td></tr>
                <?php foreach ($sections['assets']['non_current'] as $acc): ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars((string)($acc['account_name'] ?? '')) ?></td>
                    <td class="text-end"><?= format_accounting($acc['balance']) ?></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                <tr class="brit-carry"><td class="fw-bold">Total Fixed Assets</td><td></td><td class="text-end fw-bold"><?= format_accounting($b_nonCurrentAssets) ?></td></tr>

                <!-- Current Assets -->
                <tr><td colspan="3" class="brit-head pt-3">CURRENT ASSETS</td></tr>
                <?php foreach ($sections['assets']['current'] as $acc): ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars((string)($acc['account_name'] ?? '')) ?></td>
                    <td class="text-end"><?= format_accounting($acc['balance']) ?></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                <tr><td class="ps-3 fst-italic">Total Current Assets</td><td class="text-end" style="border-top:1px solid #999;"><?= format_accounting($b_currentAssets) ?></td><td></td></tr>

                <!-- Less: Current Liabilities -->
                <tr><td colspan="3" class="brit-head pt-2">LESS: CURRENT LIABILITIES</td></tr>
                <?php foreach ($sections['liabilities']['current'] as $acc): ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars((string)($acc['account_name'] ?? '')) ?></td>
                    <td class="text-end">(<?= format_accounting($acc['balance']) ?>)</td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                <tr><td class="ps-3 fst-italic">Total Current Liabilities</td><td class="text-end" style="border-top:1px solid #999;">(<?= format_accounting($b_currentLiab) ?>)</td><td></td></tr>

                <!-- Net Current Assets (working capital) -->
                <tr class="brit-carry"><td class="fw-bold">NET CURRENT ASSETS (Working Capital)</td><td></td><td class="text-end fw-bold"><?= format_accounting($b_netCurrentAssets) ?></td></tr>

                <!-- Total assets less current liabilities -->
                <tr class="brit-subtotal"><td class="fw-bold">TOTAL ASSETS LESS CURRENT LIABILITIES</td><td></td><td class="text-end fw-bold"><?= format_accounting($b_totalAssetsLessCL) ?></td></tr>

                <!-- Less: Non-current liabilities -->
                <?php if (!empty($sections['liabilities']['non_current'])): ?>
                <tr><td colspan="3" class="brit-head pt-2">LESS: NON-CURRENT LIABILITIES</td></tr>
                <?php foreach ($sections['liabilities']['non_current'] as $acc): ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars((string)($acc['account_name'] ?? '')) ?></td>
                    <td class="text-end">(<?= format_accounting($acc['balance']) ?>)</td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                <tr><td class="ps-3 fst-italic">Total Non-Current Liabilities</td><td></td><td class="text-end">(<?= format_accounting($b_nonCurrentLiab) ?>)</td></tr>
                <?php endif; ?>

                <!-- Net Assets -->
                <tr class="brit-total"><td class="fw-bold h6 mb-0">NET ASSETS</td><td></td><td class="text-end fw-bold h6 mb-0 double-underline"><?= format_accounting($b_netAssets) ?></td></tr>

                <!-- Financed by -->
                <tr><td colspan="3" class="brit-head pt-4">FINANCED BY — CAPITAL &amp; RESERVES</td></tr>
                <?php foreach ($sections['equity']['accounts'] as $acc): ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars((string)($acc['account_name'] ?? '')) ?></td>
                    <td class="text-end"><?= format_accounting($acc['balance']) ?></td>
                    <td></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <td class="ps-3">Retained Earnings (Net Profit to Date)</td>
                    <td class="text-end"><?= format_accounting($net_income) ?></td>
                    <td></td>
                </tr>
                <tr class="brit-total"><td class="fw-bold h6 mb-0">CAPITAL EMPLOYED</td><td></td><td class="text-end fw-bold h6 mb-0 double-underline"><?= format_accounting($b_capitalEmployed) ?></td></tr>
            </table>

            <p class="text-center text-muted mt-3" style="font-size:0.8rem;">
                <i class="bi bi-info-circle me-1"></i>Net Assets must equal Capital Employed
                <?= abs($b_netAssets - $b_capitalEmployed) < 0.01 ? '<span class="text-success fw-bold">✓ balanced</span>' : '<span class="text-danger fw-bold">— check Trial Balance</span>' ?>
            </p>
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
.brit-head { font-weight: 800; text-transform: uppercase; font-size: 0.9rem; color: #000; border-bottom: 2px solid #555; padding-top: 6px; }
.brit-carry td { border-top: 1px solid #000; padding-top: 6px; }
.brit-subtotal td { border-top: 1px solid #000; border-bottom: 1px solid #000; background: #f6f8fb; }
.brit-total td { border-top: 2px solid #000; padding-top: 8px; }
.british-format .account-table td { padding: 4px 8px; font-size: 0.9rem; }
.retained-earnings-row td { background: #fafdf5; border-top: 1px dashed #b9c5ad; }
.retained-earnings-row td:first-child { font-size: 0.92rem; color: #1c4532; }
.retained-earnings-row small { font-size: 0.72rem; }
.glass-action-bar { background: rgba(255,255,255,0.9) !important; backdrop-filter: blur(10px); border-radius: 1.25rem !important; border: 1px solid rgba(0,0,0,0.05) !important; }
.icon-circle { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 1.2rem; }
.btn-dark { background-color: #111 !important; border: none; transition: all 0.3s ease; }
.btn-dark:hover { background-color: #333 !important; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
.btn-light:hover { background-color: #f8f9fa !important; transform: translateY(-2px); }
@media print {
    .glass-action-bar, .d-print-none { display: none !important; }
    body { background: #fff !important; }
    /* Zero only TOP spacing — never touch padding-bottom; print_footer_css.php
       reserves it so the fixed footer can't sit on the last content row. */
    body { padding-top: 0 !important; margin-top: 0 !important; }
    .container { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
    /* Keep a bottom clearance on the report so its final rows never render
       under the fixed print footer (i_e_print.md §2/§3). */
    .report-paper { box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 0 18mm 0 !important; }
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
