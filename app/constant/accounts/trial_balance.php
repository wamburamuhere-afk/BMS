<?php
/**
 * Trial Balance Report
 *
 * Professional accountant layout:
 *   - Section by account category (Assets, Liabilities, Equity, Revenue,
 *     Expenses, COGS) with subtotals per section.
 *   - Each account appears on its NATURAL side. Contra-balances (e.g.,
 *     overdrawn bank) are flagged in red.
 *   - Mandatory balance-check banner at the top.
 *   - Warning banner if any account_type is unclassified (the migration
 *     couldn't auto-map it).
 *
 * Data contract:
 *   - Reads classification metadata (category, normal_side) from
 *     account_types — populated by migration
 *     2026_05_27_account_types_classification.php
 *   - Uses fc_balance() from core/financial_classification.php to compute
 *     natural-side balance and detect contra-balances.
 *   - Filters journal_entries by status = 'posted' only.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/financial_classification.php';

includeHeader();

if (function_exists('autoEnforcePermission')) {
    autoEnforcePermission('financial_reports');
}

$as_of_date = $_GET['as_of_date'] ?? date('Y-m-d');

// Section ordering — the accountant convention. Assets first (debit-natural),
// then Liabilities + Equity (credit-natural), then P&L accounts.
$SECTION_ORDER = ['asset', 'liability', 'equity', 'revenue', 'expense', 'cogs'];
$SECTION_LABEL = [
    'asset'     => 'ASSETS',
    'liability' => 'LIABILITIES',
    'equity'    => 'EQUITY',
    'revenue'   => 'REVENUE',
    'expense'   => 'EXPENSES',
    'cogs'      => 'COST OF GOODS SOLD',
];

$sections          = [];   // category → ['rows' => [...], 'subtotal_debit' => 0, 'subtotal_credit' => 0]
$total_debits      = 0.0;
$total_credits     = 0.0;
$contra_count      = 0;    // accounts in unusual direction (asset with credit balance, etc.)
$unclassified_rows = [];   // accounts whose type has no category
// Defensive defaults — present even if the try block throws (e.g. the
// account_types classification migration hasn't been run).
$is_balanced       = true;
$difference        = 0.0;
$missing_classification = [];
$error_message     = null;

// Guard: classification columns must exist on this server (see migration
// 2026_05_27). Show a clear banner instead of an SQL error if they're missing.
if (!fc_classification_ready($pdo)) {
    echo fc_classification_missing_banner('Trial Balance');
    includeFooter();
    ob_end_flush();
    return;
}

try {
    // Query: posted entries up to and including the as-of date.
    // Returns one row per account with the period's debit/credit totals,
    // plus the account's classification metadata.
    $sql = "
        SELECT
            a.account_id,
            a.account_code,
            a.account_name,
            at.type_name        AS type_name,
            at.category         AS category,
            at.normal_side      AS normal_side,
            COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type = 'debit'  THEN jei.amount ELSE 0 END), 0) AS total_debit,
            COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type = 'credit' THEN jei.amount ELSE 0 END), 0) AS total_credit
        FROM accounts a
        LEFT JOIN account_types at ON a.account_type_id = at.type_id
        LEFT JOIN journal_entry_items jei ON a.account_id = jei.account_id
        LEFT JOIN journal_entries je
               ON jei.entry_id = je.entry_id
              AND je.entry_date <= ?
              AND je.status = 'posted'
              -- The SUM above guards `je.entry_id IS NOT NULL` so unmatched
              -- (draft / future-dated) rows from this LEFT JOIN are not summed.
        WHERE a.status = 'active'
        GROUP BY a.account_id, a.account_code, a.account_name, at.type_name, at.category, at.normal_side
        ORDER BY a.account_code ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$as_of_date]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($accounts as $acc) {
        $debit  = (float)$acc['total_debit'];
        $credit = (float)$acc['total_credit'];

        // Skip dormant accounts (zero on both sides).
        if (abs($debit) < 0.001 && abs($credit) < 0.001) continue;

        $category    = $acc['category'] ?: null;
        $normal_side = $acc['normal_side'] ?: null;

        // Unclassified — accountant must fix the account_type via Settings.
        // We still include the net difference in the totals so the TB
        // arithmetic remains valid.
        if (!$category) {
            $net = $debit - $credit;
            $row = [
                'code'         => $acc['account_code'],
                'name'         => $acc['account_name'],
                'type_name'    => $acc['type_name'] ?: '— uncategorised —',
                'category'     => null,
                'normal_side'  => null,
                'debit'        => $net > 0 ? $net : 0,
                'credit'       => $net < 0 ? -$net : 0,
                'is_contra'    => false,
            ];
            $unclassified_rows[] = $row;
            if ($net > 0) $total_debits  += $net;
            else          $total_credits += -$net;
            continue;
        }

        // Natural-side balance: positive = on the natural side, negative = contra.
        $bal       = fc_balance($category, $debit, $credit);
        $is_contra = $bal < -0.001;
        if ($is_contra) $contra_count++;

        $abs_bal = abs($bal);
        if ($abs_bal < 0.001) continue; // exact zero — skip

        // Place the balance in the column matching the account's natural
        // side. For a contra-balance we still place the amount on the
        // natural side (so the TB still cross-foots) but flag it red.
        $row = [
            'code'         => $acc['account_code'],
            'name'         => $acc['account_name'],
            'type_name'    => $acc['type_name'],
            'category'     => $category,
            'normal_side'  => $normal_side,
            'debit'        => 0.0,
            'credit'       => 0.0,
            'is_contra'    => $is_contra,
        ];

        if ($normal_side === 'debit') {
            // For asset/expense/cogs accounts in their natural direction,
            // the debit column gets debit-credit. Even a contra-balance
            // (credit > debit) gets shown in the debit column with a flag,
            // because moving it to the credit column would break the TB
            // total when the contra is just a posting error.
            $row['debit'] = $debit - $credit;
            if ($row['debit'] >= 0) {
                $total_debits += $row['debit'];
            } else {
                $total_debits += $row['debit']; // negative amount, keeps math correct
            }
        } else { // 'credit'
            $row['credit'] = $credit - $debit;
            $total_credits += $row['credit'];
        }

        $sections[$category]['rows'][] = $row;
        $sections[$category]['subtotal_debit']  = ($sections[$category]['subtotal_debit']  ?? 0) + $row['debit'];
        $sections[$category]['subtotal_credit'] = ($sections[$category]['subtotal_credit'] ?? 0) + $row['credit'];
    }

    $is_balanced = (abs($total_debits - $total_credits) < 0.01);
    $difference  = $total_debits - $total_credits;

    // Surface any account_types the migration left unclassified (NULL category).
    // The accountant should fix these via Settings before trusting the TB.
    $missing_classification = fc_unclassified_types($pdo);

} catch (Exception $e) {
    $error_message = $e->getMessage();
}
?>

<style>
@media print {
    .d-print-none, .btn, .breadcrumb, .navbar, .sidebar, .filter-card, .sticky-top { display: none !important; }
    .container-fluid { width: 100% !important; padding: 0 !important; margin: 0 !important; }
    .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
    .card-header { background-color: #f8f9fa !important; border-bottom: 2px solid #333 !important; padding: 10px 15px !important; -webkit-print-color-adjust: exact; }
    body { background: white !important; font-size: 12px !important; }
    .table thead th { background-color: #333 !important; color: white !important; padding: 10px !important; -webkit-print-color-adjust: exact; }
    .print-header { display: block !important; text-align: center; margin-bottom: 12px; padding-bottom: 8px; }
}
/* Canonical I/E Print margin — see i_e_print.md §1 */
@page { margin: 10mm 8mm 16mm 8mm; }
</style>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-2">
        <div class="mt-2 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 3px 0; font-size: 16pt; letter-spacing: 2px;">TRIAL BALANCE REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Verification report ensuring all debits and credits are accurately balanced across accounts.</p>
            <p style="color: #444; margin: 3px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">As of: <?= date('d M Y', strtotime($as_of_date)) ?></p>
            <p style="color: #444; margin: 3px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 8px; margin-bottom: 10px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-2">
        <div class="row g-2">
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 6px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Debits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_debits) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 6px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Credits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_credits) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 6px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Status</p>
                    <h4 style="color: <?= $is_balanced ? '#2ecc71' : '#e74c3c' ?>; font-weight: 800; margin: 0; font-size: 14pt;"><?= $is_balanced ? 'BALANCED' : 'UNBALANCED' ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Page Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h1 class="h3 mb-0 text-primary fw-bold" style="text-transform:uppercase;"><i class="bi bi-calculator me-2"></i>TRIAL BALANCE</h1>
            <p class="text-muted mb-0 font-monospace small">Verification of financial position as of <?= date('F j, Y', strtotime($as_of_date)) ?></p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-primary shadow-sm px-4" onclick="window.print()" style="border-radius:8px;font-weight:700;">
                <i class="bi bi-printer me-2"></i> PRINT REPORT
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none sticky-top" style="z-index:1020;top:10px;">
        <div class="card-body py-3">
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-uppercase text-muted mb-1">As Of Date</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-calendar3"></i></span>
                        <input type="date" name="as_of_date" class="form-control" value="<?= $as_of_date ?>">
                    </div>
                </div>
                <div class="col-md-2 mt-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-filter me-1"></i> Update Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4 d-print-none">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Total Debits</div>
                    <h3 class="fw-bold text-dark mb-0"><?= format_currency($total_debits) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body">
                    <div class="text-muted small text-uppercase fw-bold mb-1">Total Credits</div>
                    <h3 class="fw-bold text-dark mb-0"><?= format_currency($total_credits) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100 border-start border-4 <?= $is_balanced ? 'border-success' : 'border-danger' ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="text-muted small text-uppercase fw-bold mb-1">Status</div>
                            <h3 class="fw-bold <?= $is_balanced ? 'text-success' : 'text-danger' ?> mb-0">
                                <?= $is_balanced ? 'Balanced' : 'Unbalanced' ?>
                            </h3>
                        </div>
                        <?php if (!$is_balanced): ?>
                            <small class="text-danger fw-bold">Diff: <?= format_currency(abs($difference)) ?></small>
                        <?php else: ?>
                            <i class="bi bi-check-circle-fill text-success fs-1 opacity-25"></i>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Failure-only balance-check banner. Per user request the success
         state is implicit — only show a banner when something's wrong. -->
    <?php if ((!isset($error_message) || $error_message === null) && !$is_balanced): ?>
        <div class="alert alert-danger border-0 py-2 px-3 mb-3 d-flex align-items-center" style="font-size: 0.9rem;">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
            <div>
                <strong>TRIAL BALANCE DOES NOT BALANCE.</strong>
                Difference = <span class="font-monospace fw-bold"><?= format_currency(abs($difference)) ?></span>
                (<?= $difference > 0 ? 'Debits exceed Credits' : 'Credits exceed Debits' ?>).
                Investigate journal entries before relying on the Income Statement, Balance Sheet, or Cash Flow.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($missing_classification ?? [])): ?>
    <div class="alert alert-warning border-0 py-2 px-3 mb-3 d-print-none" style="font-size: 0.85rem;">
        <i class="bi bi-info-circle-fill me-2"></i>
        <strong><?= count($missing_classification) ?> account type(s) are unclassified.</strong>
        Their accounts appear in the "Unclassified" section below — please classify them via
        Settings → Account Types so they're rolled up to the correct section.
    </div>
    <?php endif; ?>

    <?php if (!empty($contra_count)): ?>
    <div class="alert alert-warning border-0 py-2 px-3 mb-3 d-print-none" style="font-size: 0.85rem;">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <strong><?= $contra_count ?> account(s) show contra-balances</strong>
        (debit-natural accounts with credit balances, or vice versa) — flagged in red below.
        Common causes: overdrawn bank, reversed journal posting, or wrong account type.
    </div>
    <?php endif; ?>

    <!-- Report Table — sectioned by accounting category -->
    <div class="card border-0 shadow-lg" id="report-content">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-uppercase ls-1">Account Balances by Category</h5>
            <small class="text-muted">As of <?= htmlspecialchars(date('d M Y', strtotime($as_of_date))) ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm tb-table mb-0 align-middle">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th class="ps-4 py-2" style="width:12%; font-size: 0.85rem;">Code</th>
                            <th class="py-2" style="width:48%; font-size: 0.85rem;">Account Name</th>
                            <th class="text-end py-2" style="width:20%; font-size: 0.85rem;">Debit</th>
                            <th class="text-end pe-4 py-2" style="width:20%; font-size: 0.85rem;">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($error_message)): ?>
                            <tr><td colspan="4" class="text-center py-4 text-danger"><?= htmlspecialchars($error_message) ?></td></tr>
                        <?php elseif (empty($sections) && empty($unclassified_rows)): ?>
                            <tr><td colspan="4" class="text-center py-5 text-muted">No records found for this date.</td></tr>
                        <?php else: ?>
                            <?php foreach ($SECTION_ORDER as $cat):
                                if (empty($sections[$cat]['rows'])) continue;
                                $section = $sections[$cat];
                            ?>
                                <tr class="tb-section-header">
                                    <td colspan="4" class="ps-3 py-2 bg-light fw-bold text-uppercase" style="letter-spacing: 1px; font-size: 0.78rem; color: #495057;">
                                        <?= htmlspecialchars($SECTION_LABEL[$cat]) ?>
                                    </td>
                                </tr>
                                <?php foreach ($section['rows'] as $row):
                                    $rowClass = $row['is_contra'] ? 'table-danger-subtle' : '';
                                ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td class="ps-4 fw-mono text-muted" style="font-size: 0.82rem;"><?= htmlspecialchars($row['code']) ?></td>
                                        <td style="font-size: 0.88rem;">
                                            <?= htmlspecialchars($row['name']) ?>
                                            <?php if ($row['is_contra']): ?>
                                                <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Contra-balance"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end font-monospace <?= $row['debit'] < 0 ? 'text-danger' : '' ?>" style="font-size: 0.88rem;">
                                            <?= abs($row['debit']) > 0.001 ? format_currency($row['debit']) : '—' ?>
                                        </td>
                                        <td class="text-end pe-4 font-monospace <?= $row['credit'] < 0 ? 'text-danger' : '' ?>" style="font-size: 0.88rem;">
                                            <?= abs($row['credit']) > 0.001 ? format_currency($row['credit']) : '—' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="tb-subtotal">
                                    <td colspan="2" class="ps-4 fst-italic text-muted py-1" style="font-size: 0.8rem;">
                                        Subtotal — <?= htmlspecialchars($SECTION_LABEL[$cat]) ?>
                                    </td>
                                    <td class="text-end font-monospace fw-semibold py-1" style="font-size: 0.85rem; border-top: 1px solid #dee2e6;">
                                        <?= abs($section['subtotal_debit']) > 0.001 ? format_currency($section['subtotal_debit']) : '—' ?>
                                    </td>
                                    <td class="text-end pe-4 font-monospace fw-semibold py-1" style="font-size: 0.85rem; border-top: 1px solid #dee2e6;">
                                        <?= abs($section['subtotal_credit']) > 0.001 ? format_currency($section['subtotal_credit']) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (!empty($unclassified_rows)): ?>
                                <tr class="tb-section-header">
                                    <td colspan="4" class="ps-3 py-2 bg-warning-subtle fw-bold text-uppercase text-warning-emphasis" style="letter-spacing: 1px; font-size: 0.78rem;">
                                        UNCLASSIFIED (please assign category via Settings)
                                    </td>
                                </tr>
                                <?php foreach ($unclassified_rows as $row): ?>
                                    <tr>
                                        <td class="ps-4 fw-mono text-muted" style="font-size: 0.82rem;"><?= htmlspecialchars($row['code']) ?></td>
                                        <td style="font-size: 0.88rem;">
                                            <?= htmlspecialchars($row['name']) ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning small ms-2" style="font-size: 0.7rem;">
                                                <?= htmlspecialchars($row['type_name']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end font-monospace" style="font-size: 0.88rem;"><?= $row['debit']  > 0 ? format_currency($row['debit'])  : '—' ?></td>
                                        <td class="text-end pe-4 font-monospace" style="font-size: 0.88rem;"><?= $row['credit'] > 0 ? format_currency($row['credit']) : '—' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="fw-bold">
                        <tr class="border-top border-3 border-dark">
                            <td colspan="2" class="ps-4 py-2 text-uppercase" style="font-size: 0.95rem; letter-spacing: 1px;">Grand Total</td>
                            <td class="text-end py-2 font-monospace <?= $is_balanced ? 'text-success' : 'text-danger' ?>" style="font-size: 0.95rem;">
                                <?= format_currency($total_debits) ?>
                            </td>
                            <td class="text-end pe-4 py-2 font-monospace <?= $is_balanced ? 'text-success' : 'text-danger' ?>" style="font-size: 0.95rem;">
                                <?= format_currency($total_credits) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="text-center mt-4 text-muted small d-print-none">
        <p>Report generated on <?= date('Y-m-d H:i:s') ?> | <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?></p>
    </div>
</div>

<style>
.fw-mono { font-family: 'Courier New', monospace; }
.ls-1 { letter-spacing: 1px; }
.card { border-radius: 10px; }
.shadow-lg { box-shadow: 0 10px 25px rgba(0,0,0,0.05) !important; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php
includeFooter();
ob_end_flush();
?>
