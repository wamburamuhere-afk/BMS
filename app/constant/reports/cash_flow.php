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

// Load canonical classification helper (Phase 1).
require_once __DIR__ . '/../../../core/financial_classification.php';

// Defensive defaults — if anything in the try block throws (e.g. the
// account_types classification migration hasn't run on this server),
// the page still renders the error banner instead of dumping
// "Undefined variable" warnings.
$net_income           = 0.0;
$depreciation_addback = 0.0;
$operating_activities = [];
$investing_activities = [];
$financing_activities = [];
$cash_movement        = 0.0;
$total_operating      = 0.0;
$total_investing      = 0.0;
$total_financing      = 0.0;
$net_increase_cash    = 0.0;
$cash_start           = 0.0;
$cash_end_actual      = 0.0;
$cash_end_computed    = 0.0;
$cash_reconciles      = true;   // assume balanced when no data
$cash_recon_diff      = 0.0;
$missing_classification = [];
$error_message        = null;

// Guard: classification columns must exist on this server (see migration
// 2026_05_27). Show a clear banner instead of an SQL error if they're missing.
if (!fc_classification_ready($pdo)) {
    echo fc_classification_missing_banner('Cash Flow Statement');
    includeFooter();
    ob_end_flush();
    return;
}

try {
    // ── 1. Net Income (Indirect Method starting point) ─────────────────
    // Pull P&L categories via the canonical helper and aggregate them on
    // their natural side using fc_balance(). Net Profit = Revenue − COGS
    // − Expenses (the same identity used by the Balance Sheet's Retained
    // Earnings — guarantees the two reports never disagree).
    $is_type_ids = fc_type_ids_for_categories($pdo, ['revenue', 'expense', 'cogs']);
    $net_income = 0.0;
    if (!empty($is_type_ids)) {
        $ph = implode(',', array_fill(0, count($is_type_ids), '?'));
        $is_sql = "
            SELECT at.category AS category,
                   COALESCE(SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END), 0) AS dr,
                   COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END), 0) AS cr
              FROM accounts a
              JOIN account_types at ON a.account_type_id = at.type_id
         LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
         LEFT JOIN journal_entries je
                ON je.entry_id = jei.entry_id
               AND je.entry_date BETWEEN ? AND ?
               AND je.status = 'posted'
             WHERE a.account_type_id IN ($ph)
               AND a.status = 'active'
          GROUP BY at.category
        ";
        $stmt = $pdo->prepare($is_sql);
        $stmt->execute(array_merge([$start_date, $end_date], $is_type_ids));
        $cat_totals = ['revenue' => 0.0, 'expense' => 0.0, 'cogs' => 0.0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cat_totals[$r['category']] = fc_balance($r['category'], (float)$r['dr'], (float)$r['cr']);
        }
        $net_income = $cat_totals['revenue'] - $cat_totals['cogs'] - $cat_totals['expense'];
    }

    // ── 2. Period changes in Balance Sheet accounts ────────────────────
    // Pulls all asset / liability / equity accounts and the net change
    // during the period. We use the account_types.cash_flow_category
    // (populated by Phase 1 migration) to route each account to the
    // correct section — no more account-name LIKE heuristics.
    $changes_sql = "
        SELECT
            a.account_id,
            a.account_name,
            a.account_code,
            at.category              AS category,
            COALESCE(a.cash_flow_category, at.cash_flow_category) AS cf_category,
            LOWER(at.type_name)      AS type_name,
            COALESCE(SUM(CASE WHEN jei.type='debit'  THEN jei.amount ELSE 0 END), 0) AS total_debit,
            COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE 0 END), 0) AS total_credit
        FROM accounts a
        JOIN account_types at ON a.account_type_id = at.type_id
        LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
        LEFT JOIN journal_entries je
               ON je.entry_id = jei.entry_id
              AND je.entry_date BETWEEN ? AND ?
              AND je.status = 'posted'
        WHERE a.status = 'active'
          AND at.category IN ('asset','liability','equity')
        GROUP BY a.account_id, a.account_name, a.account_code, at.category, a.cash_flow_category, at.cash_flow_category, at.type_name
        HAVING ABS(total_debit) > 0.001 OR ABS(total_credit) > 0.001
        ORDER BY a.account_code
    ";
    $stmt = $pdo->prepare($changes_sql);
    $stmt->execute([$start_date, $end_date]);
    $changes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $operating_activities = [];
    $investing_activities = [];
    $financing_activities = [];
    $cash_movement        = 0.0;

    foreach ($changes as $acc) {
        $category = $acc['category'];
        $cf       = $acc['cf_category'];
        $debit    = (float)$acc['total_debit'];
        $credit   = (float)$acc['total_credit'];

        // Natural-side balance change. For an asset account, positive
        // = balance went up (debit > credit). For a liability/equity,
        // positive = balance went up (credit > debit).
        $change = fc_balance($category, $debit, $credit);
        if (abs($change) < 0.001) continue;

        // Cash-and-equivalents accounts feed the bottom reconciliation.
        if ($cf === 'cash') {
            $cash_movement += $change;
            continue;
        }

        // Cash-flow impact rule (indirect method):
        //   - When an asset goes UP, cash went DOWN (paid to buy it)
        //     → cf_impact = -change
        //   - When a liability goes UP, cash went UP (received cash)
        //     → cf_impact = +change
        //   - When equity goes UP (capital injection), cash went UP
        //     → cf_impact = +change
        $cf_impact = ($category === 'asset') ? -$change : $change;
        $acc['cf_impact'] = $cf_impact;
        $acc['change']    = $change;

        if ($cf === 'investing') {
            $investing_activities[] = $acc;
        } elseif ($cf === 'financing') {
            $financing_activities[] = $acc;
        } else { // 'operating' or NULL → default to Operating
            $operating_activities[] = $acc;
        }
    }

    // ── 3. Depreciation add-back (non-cash adjustment) ─────────────────
    // Identify the expense incurred during the period that came from
    // accounts whose type_name contains "depreciation". This is the
    // classic indirect-method non-cash add-back.
    $dep_sql = "
        SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount WHEN jei.type='credit' THEN -jei.amount ELSE 0 END), 0)
          FROM accounts a
          JOIN account_types at ON a.account_type_id = at.type_id
          JOIN journal_entry_items jei ON jei.account_id = a.account_id
          JOIN journal_entries je      ON je.entry_id    = jei.entry_id
         WHERE LOWER(at.type_name) LIKE '%depreciation%'
            OR LOWER(a.account_name) LIKE '%depreciation expense%'
           AND je.entry_date BETWEEN ? AND ?
           AND je.status = 'posted'
    ";
    $stmt = $pdo->prepare($dep_sql);
    $stmt->execute([$start_date, $end_date]);
    $depreciation_addback = (float)($stmt->fetchColumn() ?: 0);

    // ── 4. Totals per section ──────────────────────────────────────────
    $total_operating = $net_income + $depreciation_addback;
    foreach ($operating_activities as $act) $total_operating += $act['cf_impact'];

    $total_investing = 0.0;
    foreach ($investing_activities as $act) $total_investing += $act['cf_impact'];

    $total_financing = 0.0;
    foreach ($financing_activities as $act) $total_financing += $act['cf_impact'];

    $net_increase_cash = $total_operating + $total_investing + $total_financing;

    // ── 5. Opening / closing cash balances (for reconciliation) ────────
    // Uses cash_flow_category = 'cash' from account_types — replaces the
    // account_name LIKE '%cash%' / '%bank%' / '%petty%' heuristics that
    // could misclassify e.g. "Petty Cash Vehicle Allowance".
    // Cash & cash equivalents — identified by each account's EFFECTIVE
    // cash_flow_category (the account-level override set per the canonical
    // IAS 7 mapping, else the type's value). Opening / closing cash include
    // the brought-forward accounts.opening_balance so they tie to the ledger
    // (the Trial Balance / Balance Sheet / General Ledger all include it).
    $cash_account_ids = fc_account_ids_for_cash_flow_category($pdo, 'cash');
    $cash_start = 0.0;
    $cash_end_actual = 0.0;
    if (!empty($cash_account_ids)) {
        $cph = implode(',', array_fill(0, count($cash_account_ids), '?'));

        // Brought-forward opening on the cash accounts (cash is debit-natural).
        $obStmt = $pdo->prepare("SELECT COALESCE(SUM(opening_balance), 0) FROM accounts WHERE account_id IN ($cph)");
        $obStmt->execute($cash_account_ids);
        $cash_open_col = (float)$obStmt->fetchColumn();

        // Opening cash = opening_balance + posted movements BEFORE start_date.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount WHEN jei.type='credit' THEN -jei.amount ELSE 0 END), 0)
              FROM journal_entry_items jei
              JOIN journal_entries je ON je.entry_id = jei.entry_id
             WHERE jei.account_id IN ($cph)
               AND je.entry_date < ?
               AND je.status = 'posted'
        ");
        $stmt->execute(array_merge($cash_account_ids, [$start_date]));
        $cash_start = $cash_open_col + (float)($stmt->fetchColumn() ?: 0);

        // Actual cash on end_date = opening_balance + posted movements <= end.
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount WHEN jei.type='credit' THEN -jei.amount ELSE 0 END), 0)
              FROM journal_entry_items jei
              JOIN journal_entries je ON je.entry_id = jei.entry_id
             WHERE jei.account_id IN ($cph)
               AND je.entry_date <= ?
               AND je.status = 'posted'
        ");
        $stmt->execute(array_merge($cash_account_ids, [$end_date]));
        $cash_end_actual = $cash_open_col + (float)($stmt->fetchColumn() ?: 0);
    }
    $cash_end_computed = $cash_start + $net_increase_cash;

    // Reconciliation: do the indirect method's computed ending cash and
    // the actual cash balance from journal entries agree?
    $cash_reconciles = abs($cash_end_computed - $cash_end_actual) < 0.01;
    $cash_recon_diff = $cash_end_computed - $cash_end_actual;

    // Surface any unclassified account_types.
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

 

    <!-- Cash-reconciliation banner — accountant's first sanity check.
         Compares the indirect-method computed ending cash against the
         actual cash balance from journal entries on end_date. -->
    <?php if (!isset($error_message) || $error_message === null): ?>
        <?php if (!$cash_reconciles): ?>
        <div class="alert alert-danger border-0 py-2 px-3 mb-3 d-flex align-items-center d-print-none" style="font-size: 0.9rem;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div>
                <strong>CASH FLOW DOES NOT RECONCILE.</strong>
                Computed: <span class="font-monospace"><?= number_format($cash_end_computed, 2) ?></span>
                vs Actual: <span class="font-monospace"><?= number_format($cash_end_actual, 2) ?></span>
                — difference <span class="font-monospace fw-bold"><?= number_format(abs($cash_recon_diff), 2) ?></span>.
                Check the Trial Balance and any draft / unposted entries before relying on this report.
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($missing_classification ?? [])): ?>
    <div class="alert alert-warning border-0 py-2 px-3 mb-3 d-print-none" style="font-size: 0.85rem;">
        <i class="bi bi-info-circle-fill me-2"></i>
        <strong><?= count($missing_classification) ?> account type(s) are unclassified.</strong>
        Their changes may default into the Operating section — classify them via
        Settings → Account Types so they're routed to the correct cash-flow bucket.
    </div>
    <?php endif; ?>

    <!-- REPORT BODY -->
    <div class="report-paper shadow mb-5" id="reportContent">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
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
                        <!-- OPERATING -->
                        <tr class="bg-light bg-opacity-50">
                            <td colspan="2" class="ps-3 py-3 fw-bold text-primary text-uppercase ls-1">
                                <i class="bi bi-lightning-charge-fill me-2"></i>Cash flows from operating activities
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-5 py-2">Net Profit (from Income Statement)</td>
                            <td class="text-end pe-3 py-2 fw-bold"><?= format_currency($net_income) ?></td>
                        </tr>
                        <?php if (abs($depreciation_addback) > 0.001): ?>
                        <tr>
                            <td class="ps-5 small text-muted fst-italic py-1">Add: Depreciation (non-cash expense)</td>
                            <td class="text-end pe-3 small py-1"><?= format_currency($depreciation_addback) ?></td>
                        </tr>
                        <?php endif; ?>
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
                            <td class="text-end pe-3 py-3 fw-bold h4 mb-0 border-bottom-double"><?= format_currency($cash_end_computed) ?></td>
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
    /* Zero only TOP spacing — never touch padding-bottom; print_footer_css.php
       reserves it so the fixed footer can't sit on the last content row. */
    body { padding-top: 0 !important; margin-top: 0 !important; }
    /* Keep a bottom clearance so final rows never render under the fixed
       footer (i_e_print.md §2/§3). */
    .report-paper { border: none !important; padding: 0 0 18mm 0 !important; margin: 0 !important; border-radius: 0; }
    .container { width: 100% !important; max-width: 100% !important; }
}
/* Canonical I/E Print margin — see i_e_print.md §1 */
@page { margin: 10mm 8mm 16mm 8mm; }
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
