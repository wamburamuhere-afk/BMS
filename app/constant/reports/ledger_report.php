<?php
/**
 * General Ledger Report
 *
 * Phase 6 — fixed opening-balance double-count and added accountant-style
 * T-ledger presentation:
 *
 *   - Opening balance is derived SOLELY from historical posted journal
 *     entries before start_date. The previous code ALSO added
 *     accounts.opening_balance (a column on the accounts table) which
 *     double-counted when that column had already been seeded as an
 *     opening journal entry — the most common case in BMS.
 *
 *   - Each row shows a running balance with natural-side (Dr/Cr) label,
 *     matching the standard accountant ledger.
 *
 *   - When no account is selected, the report shows a per-account
 *     summary table (opening / debits / credits / closing) instead of
 *     dumping every transaction with no grouping.
 *
 * Filters: je.status = 'posted' only. Print layout untouched.
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/financial_classification.php';
require_once __DIR__ . '/../../../core/gl_source.php';

includeHeader();

autoEnforcePermission('ledger_report');

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date   = $_GET['end_date']   ?? date('Y-12-31');
$account_id = $_GET['account_id'] ?? '';

$entries          = [];
$all_accounts     = [];
$total_debit      = 0.0;
$total_credit     = 0.0;
$opening_balance  = 0.0;
$closing_balance  = 0.0;
$net_change       = 0.0;
$selected_account = null;
$summary_rows     = [];   // for the "no account selected" overview
$error            = null;

// Guard: classification columns must exist on this server (see migration
// 2026_05_27). Show a clear banner instead of an SQL error if they're missing.
if (!fc_classification_ready($pdo)) {
    echo fc_classification_missing_banner('General Ledger');
    includeFooter();
    ob_end_flush();
    return;
}

try {
    // Account picker source — joined with account_types so we can show
    // the natural side label on closing balance.
    $acc_stmt = $pdo->query("
        SELECT a.account_id, a.account_name, a.account_code, a.opening_balance,
               at.category, at.normal_side
          FROM accounts a
     LEFT JOIN account_types at ON a.account_type_id = at.type_id
         WHERE a.status='active'
      ORDER BY a.account_code ASC
    ");
    $all_accounts = $acc_stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($account_id) {
        // ── Single account — full ledger with running balance ──────────

        // Look up the chosen account's metadata.
        foreach ($all_accounts as $a) {
            if ((int)$a['account_id'] === (int)$account_id) {
                $selected_account = $a;
                break;
            }
        }

        // Opening balance (debit-positive) = the account's brought-forward
        // opening_balance column (BMS stores openings here, allocated to the
        // natural side) PLUS the net of any posted journal entries STRICTLY
        // BEFORE start_date. This matches the GL API, the Trial Balance and the
        // Balance Sheet so all four reconcile. (Earlier this page dropped the
        // column, which hid every account whose balance was a pure opening with
        // no journal movement, and made the GL disagree with the TB/BS.)
        $ob_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END), 0) AS balance
              FROM journal_entry_items jei
              JOIN journal_entries je ON jei.entry_id = je.entry_id
             WHERE jei.account_id = ?
               AND je.entry_date < ?
               AND je.status = 'posted'
        ");
        $ob_stmt->execute([$account_id, $start_date]);
        $opening_balance = (float)($ob_stmt->fetchColumn() ?: 0);

        // Fold in the brought-forward column, converted to this page's
        // debit-positive convention (credit-natural accounts hold their opening
        // on the credit side, so it subtracts from a debit-positive balance).
        $col_open = (float)($selected_account['opening_balance'] ?? 0);
        if (($selected_account['normal_side'] ?? 'debit') === 'credit') {
            $opening_balance -= $col_open;
        } else {
            $opening_balance += $col_open;
        }

        // Period entries.
        $stmt = $pdo->prepare("
            SELECT je.entry_date,
                   je.reference_number AS entry_number,
                   je.entity_type,
                   je.entity_id,
                   jei.account_id,
                   a.account_code,
                   a.account_name,
                   jei.type,
                   jei.amount,
                   jei.description,
                   je.entry_id
              FROM journal_entries je
              JOIN journal_entry_items jei ON je.entry_id = jei.entry_id
              JOIN accounts a ON jei.account_id = a.account_id
             WHERE je.entry_date BETWEEN ? AND ?
               AND je.status = 'posted'
               AND jei.account_id = ?
          ORDER BY je.entry_date ASC, je.entry_id ASC, jei.item_id ASC
        ");
        $stmt->execute([$start_date, $end_date, $account_id]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($entries as &$e) {
            if ($e['type'] === 'debit') {
                $total_debit += (float)$e['amount'];
            } else {
                $total_credit += (float)$e['amount'];
            }
        }
        unset($e);

        $net_change      = $total_debit - $total_credit;
        $closing_balance = $opening_balance + $net_change;

    } else {
        // ── No account selected — produce a per-account summary table ──

        $sum_stmt = $pdo->prepare("
            SELECT a.account_id, a.account_code, a.account_name,
                   at.category, at.normal_side,
                   (
                       CASE WHEN at.normal_side = 'credit'
                            THEN -COALESCE(a.opening_balance, 0)
                            ELSE  COALESCE(a.opening_balance, 0) END
                       + COALESCE((
                           SELECT SUM(CASE WHEN jei2.type='debit' THEN jei2.amount ELSE -jei2.amount END)
                             FROM journal_entry_items jei2
                             JOIN journal_entries je2 ON jei2.entry_id = je2.entry_id
                            WHERE jei2.account_id = a.account_id
                              AND je2.entry_date < ?
                              AND je2.status = 'posted'
                       ), 0)
                   ) AS opening,
                   -- Guard on je.entry_id IS NOT NULL: the LEFT JOIN above keeps a jei
                   -- row even when its parent entry falls outside the posted/date
                   -- window, so summing jei.amount unguarded leaks void/draft/
                   -- out-of-range amounts into this period's Dr/Cr (the same class of
                   -- bug the Trial Balance's own query comment warns about).
                   COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='debit'  THEN jei.amount ELSE 0 END), 0) AS dr,
                   COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='credit' THEN jei.amount ELSE 0 END), 0) AS cr
              FROM accounts a
         LEFT JOIN account_types at ON a.account_type_id = at.type_id
         LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
         LEFT JOIN journal_entries je
                ON je.entry_id = jei.entry_id
               AND je.entry_date BETWEEN ? AND ?
               AND je.status = 'posted'
             -- Same inclusion rule as core/financial_reports.php::_gl_account_activity()
             -- and the Trial Balance / Balance Sheet: a deactivated account with real
             -- posted history must still appear here, or this summary's Dr/Cr footer
             -- silently disagrees with every other statutory report.
             WHERE (
                 a.status = 'active'
                 OR COALESCE(a.opening_balance, 0) <> 0
                 OR EXISTS (
                        SELECT 1
                          FROM journal_entry_items jx
                          JOIN journal_entries     jy ON jy.entry_id = jx.entry_id
                         WHERE jx.account_id = a.account_id
                           AND jy.status = 'posted'
                    )
             )
          GROUP BY a.account_id, a.account_code, a.account_name, a.opening_balance, at.category, at.normal_side
            HAVING ABS(opening) > 0.001 OR ABS(dr) > 0.001 OR ABS(cr) > 0.001
          ORDER BY a.account_code ASC
        ");
        $sum_stmt->execute([$start_date, $start_date, $end_date]);
        $summary_rows = $sum_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Roll up totals across all accounts for the bottom footer.
        foreach ($summary_rows as $r) {
            $total_debit  += (float)$r['dr'];
            $total_credit += (float)$r['cr'];
        }
        $net_change      = $total_debit - $total_credit;
        $closing_balance = $net_change; // not meaningful at the aggregate level
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

/**
 * Format an account balance with its natural-side suffix (Dr/Cr).
 * Used for opening / closing balance display.
 */
function gl_balance_label(float $amount, ?string $normalSide): string {
    if (abs($amount) < 0.001) return '0.00';
    $isDebit  = $amount > 0;
    $absStr   = number_format(abs($amount), 2);
    // Suffix the side based on the actual sign — debit-natural side
    // when amount > 0, credit-natural side when amount < 0. We let the
    // sign speak; the $normalSide arg is only used to highlight contra-
    // balances (caller chooses styling).
    return $absStr . ' ' . ($isDebit ? 'Dr' : 'Cr');
}
?>

<div class="container-fluid py-4">
    <!-- Professional Print Header -->
    <div class="print-header d-none d-print-block text-center mb-4">
        <div class="mt-3 text-center">
            <h2 style="color: #495057; font-weight: 600; text-transform: uppercase; margin: 5px 0; font-size: 16pt; letter-spacing: 2px;">GENERAL LEDGER REPORT</h2>
            <p style="color: #6c757d; margin: 0; font-size: 10pt;">Comprehensive record of all journal transactions across registered accounts.</p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Period: <?= date('d M Y', strtotime($start_date)) ?> - <?= date('d M Y', strtotime($end_date)) ?></p>
            <p style="color: #444; margin: 5px 0 0; font-size: 9pt; font-weight: 600; text-transform: uppercase;">Generated At: <?= date('d M Y, h:i A') ?></p>
        </div>
        <div style="border-bottom: 3px solid #0d6efd; margin-top: 15px; margin-bottom: 25px;"></div>
    </div>

    <!-- Print Summary Cards -->
    <div class="d-none d-print-block mb-4">
        <div class="row g-2">
            <?php if($account_id): ?>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Opening Balance</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($opening_balance) ?></h4>
                </div>
            </div>
            <?php endif; ?>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Debits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_debit) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;">Total Credits</p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($total_credit) ?></h4>
                </div>
            </div>
            <div class="col" style="flex: 1 0 0%;">
                <div style="border: 1px solid #dee2e6; padding: 10px; border-radius: 0; text-align: center;">
                    <p style="color: #666; font-size: 8pt; text-transform: uppercase; margin-bottom: 2px; font-weight: 600;"><?= $account_id ? 'Closing Balance' : 'Net Movement' ?></p>
                    <h4 style="color: #333; font-weight: 800; margin: 0; font-size: 14pt;"><?= format_currency($account_id ? $closing_balance : $net_change) ?></h4>
                </div>
            </div>
        </div>
    </div>
    <!-- Header -->
    <div class="row mb-4 align-items-center d-print-none">
        <div class="col-md-6">
            <h2 class="fw-bold text-primary mb-0"><i class="bi bi-journal-richtext me-2"></i>General Ledger</h2>
            <p class="text-muted mb-0">Detailed historical record of financial movements</p>
        </div>
        <div class="col-md-6 text-end">
            <button class="btn btn-outline-primary shadow-sm px-4 fw-bold" onclick="printLedgerX()">
                <i class="bi bi-printer-fill me-2"></i> Print Ledger
            </button>
            <button class="btn btn-dark shadow-sm px-4 fw-bold ms-2" onclick="exportCSV()">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Export CSV
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4 d-print-none" style="border-radius: 15px; background: #fdfdfd;">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Fiscal Start</label>
                    <input type="date" name="start_date" class="form-control rounded-pill border-light shadow-sm" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Fiscal End</label>
                    <input type="date" name="end_date" class="form-control rounded-pill border-light shadow-sm" value="<?= $end_date ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted text-uppercase mb-1">Filter by Account</label>
                    <select name="account_id" class="form-select rounded-pill border-light shadow-sm select2">
                        <option value="">All Accounts (General View)</option>
                        <?php foreach($all_accounts as $acc): ?>
                            <option value="<?= $acc['account_id'] ?>" <?= $account_id == $acc['account_id'] ? 'selected' : '' ?>>
                                [<?= htmlspecialchars((string)($acc['account_code'] ?? '')) ?>] <?= htmlspecialchars((string)($acc['account_name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm rounded-pill">
                        <i class="bi bi-filter me-1"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Metrics -->
    <div class="row g-3 mb-4">
        <?php if($account_id): ?>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Opening Balance</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($opening_balance) ?></h4>
                    <span class="small text-muted fw-bold">Brought Forward</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-md-<?= $account_id ? '3' : '4' ?>">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Debits</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_debit) ?></h4>
                    
                </div>
            </div>
        </div>
        <div class="col-md-<?= $account_id ? '3' : '4' ?>">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1">Total Credits</p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($total_credit) ?></h4>
                    
                </div>
            </div>
        </div>
        <div class="col-md-<?= $account_id ? '3' : '4' ?>">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px; background-color: #d1e7dd; overflow: hidden;">
                <div class="card-body p-3">
                    <p class="text-muted small text-uppercase fw-bold mb-1"><?= $account_id ? 'Closing Balance' : 'Net Movement' ?></p>
                    <h4 class="fw-bold mb-0 text-dark"><?= format_currency($account_id ? $closing_balance : $net_change) ?></h4>
                  
                </div>
            </div>
        </div>
    </div>

    <?php if(isset($error)): ?>
        <div class="alert alert-danger border-0 shadow-sm mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Ledger Table -->
    <div class="card border-0 shadow-lg print-flow-card" style="border-radius: 15px; overflow: hidden;">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            
            <div class="d-print-none">
                <input type="text" id="ledgerSearch" class="form-control form-control-sm px-3 shadow-sm border-light" placeholder="Search entries..." style="width: 250px; border-radius: 20px;">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <?php if ($account_id): ?>
                <!-- Single-account T-ledger: date | ref | description | debit | credit | running balance -->
                <table class="table align-middle mb-0 gl-table gl-table-detail" id="ledgerTable">
                    <thead class="bg-light">
                        <tr style="font-size: 0.78rem;">
                            <th class="ps-4 py-2 text-muted text-uppercase">Date</th>
                            <th class="py-2 text-muted text-uppercase">Reference</th>
                            <th class="py-2 text-muted text-uppercase">Source</th>
                            <th class="py-2 text-muted text-uppercase">Description</th>
                            <th class="text-end py-2 text-muted text-uppercase">Debit</th>
                            <th class="text-end py-2 text-muted text-uppercase">Credit</th>
                            <th class="text-end pe-4 py-2 text-muted text-uppercase">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $normalSide = $selected_account['normal_side'] ?? null; ?>
                        <!-- 7 explicit cells (no colspan) so DataTables' per-row column
                             count stays consistent with the 7-column header. -->
                        <tr class="table-light gl-opening-row">
                            <td class="ps-4 py-2" style="font-size: 0.85rem;"><?= htmlspecialchars(date('d M Y', strtotime($start_date))) ?></td>
                            <td class="py-2"></td>
                            <td class="py-2"></td>
                            <td class="fst-italic text-muted py-2" style="font-size: 0.85rem;">
                                Opening Balance Brought Forward
                            </td>
                            <td class="py-2"></td>
                            <td class="py-2"></td>
                            <td class="text-end pe-4 fw-bold font-monospace py-2" style="font-size: 0.9rem;">
                                <?= htmlspecialchars(gl_balance_label($opening_balance, $normalSide)) ?>
                            </td>
                        </tr>

                        <?php if (empty($entries)): ?>
                            <tr>
                                <td></td><td></td><td></td>
                                <td class="text-center py-5 text-muted fst-italic">No posted entries in this period for this account.</td>
                                <td></td><td></td><td></td>
                            </tr>
                        <?php else:
                            $running_balance = $opening_balance;
                            foreach ($entries as $e):
                                if ($e['type'] === 'debit') $running_balance += (float)$e['amount'];
                                else                        $running_balance -= (float)$e['amount'];
                                $src = gl_source_link($e['entity_type'] ?? null, (int)($e['entity_id'] ?? 0));
                        ?>
                            <tr>
                                <td class="ps-4 py-1" style="font-size: 0.85rem;"><?= htmlspecialchars(date('d M Y', strtotime($e['entry_date']))) ?></td>
                                <td class="font-monospace text-muted py-1" style="font-size: 0.8rem;">
                                    <?= htmlspecialchars((string)($e['entry_number'] ?? '#-')) ?>
                                </td>
                                <td class="py-1" style="font-size: 0.78rem;">
                                    <?php if ($src['label'] === ''): ?>
                                        <span class="text-muted">—</span>
                                    <?php elseif ($src['url']): ?>
                                        <a href="<?= htmlspecialchars($src['url']) ?>" class="badge bg-primary-subtle text-primary border border-primary-subtle text-decoration-none" title="Open source document"><?= htmlspecialchars($src['label']) ?></a>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border"><?= htmlspecialchars($src['label']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-1" style="font-size: 0.85rem;">
                                    <?= htmlspecialchars((string)($e['description'] ?? '-')) ?>
                                </td>
                                <td class="text-end font-monospace py-1" style="font-size: 0.88rem;">
                                    <?= $e['type'] === 'debit' ? number_format((float)$e['amount'], 2) : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-end font-monospace py-1" style="font-size: 0.88rem;">
                                    <?= $e['type'] === 'credit' ? number_format((float)$e['amount'], 2) : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-end pe-4 font-monospace py-1" style="font-size: 0.88rem;">
                                    <?= htmlspecialchars(gl_balance_label($running_balance, $normalSide)) ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <!-- 7 explicit cells per row (NO colspan): DataTables maps one
                         footer cell per column and miscounts colspan'd footers. -->
                    <tfoot class="fw-bold">
                        <tr class="border-top border-2 border-dark">
                            <td class="ps-4 py-2 text-uppercase" style="font-size: 0.88rem; letter-spacing: 0.5px;">Period Totals</td>
                            <td class="py-2"></td>
                            <td class="py-2"></td>
                            <td class="py-2"></td>
                            <td class="text-end font-monospace py-2" style="font-size: 0.9rem;"><?= number_format($total_debit, 2) ?></td>
                            <td class="text-end font-monospace py-2" style="font-size: 0.9rem;"><?= number_format($total_credit, 2) ?></td>
                            <td class="text-end pe-4 py-2 font-monospace" style="font-size: 0.95rem;">
                                <?= htmlspecialchars(gl_balance_label($closing_balance, $normalSide)) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="ps-4 py-2 fst-italic text-muted" style="font-size: 0.82rem;">Closing Balance as of <?= htmlspecialchars(date('d M Y', strtotime($end_date))) ?></td>
                            <td></td><td></td><td></td><td></td><td></td>
                            <td class="text-end pe-4 py-2 font-monospace fw-bold <?= $closing_balance < 0 ? 'text-danger' : 'text-success' ?>" style="font-size: 1.0rem; border-top: 2px solid #0d6efd;">
                                <?= htmlspecialchars(gl_balance_label($closing_balance, $normalSide)) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <?php else: ?>
                <!-- No-account view: per-account summary (opening | debits | credits | closing) -->
                <table class="table align-middle mb-0 gl-table gl-table-summary" id="ledgerTable">
                    <thead class="bg-light">
                        <tr style="font-size: 0.78rem;">
                            <th class="ps-4 py-2 text-muted text-uppercase">Code</th>
                            <th class="py-2 text-muted text-uppercase">Account</th>
                            <th class="py-2 text-muted text-uppercase">Type</th>
                            <th class="text-end py-2 text-muted text-uppercase">Opening</th>
                            <th class="text-end py-2 text-muted text-uppercase">Debits</th>
                            <th class="text-end py-2 text-muted text-uppercase">Credits</th>
                            <th class="text-end pe-4 py-2 text-muted text-uppercase">Closing</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($summary_rows)): ?>
                            <!-- 7 explicit cells (no colspan) so DataTables' per-row column
                                 count stays consistent with the 7-column header. -->
                            <tr>
                                <td></td><td></td>
                                <td class="text-center py-5 text-muted fst-italic">No posted entries in this period across any account.</td>
                                <td></td><td></td><td></td><td></td>
                            </tr>
                        <?php else: foreach ($summary_rows as $r):
                            $closing = (float)$r['opening'] + (float)$r['dr'] - (float)$r['cr'];
                            $side    = $r['normal_side'];
                        ?>
                            <tr>
                                <td class="ps-4 font-monospace text-muted py-1" style="font-size: 0.82rem;"><?= htmlspecialchars((string)$r['account_code']) ?></td>
                                <td class="py-1" style="font-size: 0.88rem;">
                                    <a href="?start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>&account_id=<?= (int)$r['account_id'] ?>" class="text-decoration-none">
                                        <?= htmlspecialchars((string)$r['account_name']) ?>
                                    </a>
                                </td>
                                <td class="text-muted py-1" style="font-size: 0.78rem;"><?= htmlspecialchars((string)($r['category'] ?? '—')) ?></td>
                                <td class="text-end font-monospace py-1" style="font-size: 0.85rem;"><?= htmlspecialchars(gl_balance_label((float)$r['opening'], $side)) ?></td>
                                <td class="text-end font-monospace py-1" style="font-size: 0.85rem;"><?= number_format((float)$r['dr'], 2) ?></td>
                                <td class="text-end font-monospace py-1" style="font-size: 0.85rem;"><?= number_format((float)$r['cr'], 2) ?></td>
                                <td class="text-end pe-4 font-monospace fw-semibold py-1" style="font-size: 0.88rem;"><?= htmlspecialchars(gl_balance_label($closing, $side)) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <!-- 7 explicit cells (NO colspan): DataTables maps one footer
                         cell per column and miscounts colspan'd footers. -->
                    <tfoot class="fw-bold">
                        <tr class="border-top border-2 border-dark">
                            <td class="ps-4 py-2 text-uppercase" style="font-size: 0.88rem; letter-spacing: 0.5px;">Period Totals</td>
                            <td class="py-2"></td>
                            <td class="py-2"></td>
                            <td class="py-2"></td>
                            <td class="text-end font-monospace py-2" style="font-size: 0.9rem;"><?= number_format($total_debit, 2) ?></td>
                            <td class="text-end font-monospace py-2" style="font-size: 0.9rem;"><?= number_format($total_credit, 2) ?></td>
                            <td class="text-end pe-4 py-2 font-monospace" style="font-size: 0.9rem;"><?= number_format($net_change, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    // DataTable init — pagination, sort, Show:N, matching the house standard
    // used by every other list page (and the same proven print-safe pattern
    // already shipped on account_details.php's ledger table). responsive is
    // left off on purpose: DataTables' Responsive extension hides low-priority
    // columns behind a "+" toggle at narrow widths, which is exactly wrong for
    // a financial ledger where every column must always show, print included.
    const table = $('#ledgerTable').DataTable({
        responsive: false,
        order: [],   // keep the original chronological order from SQL
        pageLength: 25,
        dom: 'rtip', // no default search box — #ledgerSearch drives table.search() below
        language: {
            lengthMenu: 'Show _MENU_',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            emptyTable: 'No records found.',
            zeroRecords: 'No matching records.',
            paginate: { first: 'First', last: 'Last', next: 'Next', previous: 'Previous' }
        }
    });
    window.ledgerReportTable = table;

    $('#ledgerSearch').on('input', function () { table.search(this.value).draw(); });

    if(typeof logReportAction==='function') {
        logReportAction('Viewed Ledger Report', 'Generated ledger records for period <?= $start_date ?> to <?= $end_date ?>');
    }
});

// Print — DataTables only keeps the current page's rows in the DOM, so
// printing without this would cut off every row past the first page.
function printLedgerX() {
    logReportAction && logReportAction('Printed Ledger Report', 'Printed ledger for period <?= $start_date ?> to <?= $end_date ?>');
    const table = window.ledgerReportTable;
    if (!table) { window.print(); return; }
    const originalLength = table.page.len();
    table.page.len(-1).draw(false);
    setTimeout(function() {
        window.print();
        table.page.len(originalLength).draw(false);
    }, 100);
}

function exportCSV() {
    // Client-side CSV export via the DataTable API — respects the active
    // search filter but, unlike reading the DOM directly, is not limited to
    // whichever page is currently displayed.
    var lines = [];
    var headerCells = [];
    $('#ledgerTable thead tr th').each(function () {
        headerCells.push('"' + $(this).text().replace(/\s+/g, ' ').trim().replace(/"/g, '""') + '"');
    });
    lines.push(headerCells.join(','));

    var table = window.ledgerReportTable;
    var rows = table ? table.rows({ search: 'applied' }).nodes() : document.querySelectorAll('#ledgerTable tbody tr');
    $(rows).each(function () {
        var cells = $(this).find('td');
        if (!cells.length) return;
        var line = [];
        cells.each(function () {
            var t = ($(this).text() || '').replace(/\s+/g, ' ').trim();
            line.push('"' + t.replace(/"/g, '""') + '"');
        });
        lines.push(line.join(','));
    });
    var blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'general_ledger_<?= $start_date ?>_to_<?= $end_date ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
    if (typeof logReportAction === 'function') {
        logReportAction('Exported Ledger CSV', 'General ledger CSV <?= $start_date ?> to <?= $end_date ?>');
    }
}
</script>

<style>
    .card { border-radius: 15px; }
    .table thead th { border-top: none; }
    .italic { font-style: italic; }
    .x-small { font-size: 0.72rem; }
    .truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }
    @media print {
        .d-print-none, .btn, #ledgerSearch, .row.g-3.mb-4 { display: none !important; }
        /* Blank-first-page fix: zero ONLY top spacing (navbar reserve); never
           touch padding-bottom (print_footer_css.php needs it). */
        body { padding-top: 0 !important; margin-top: 0 !important; }
        .card { border: none !important; box-shadow: none !important; border-radius: 0 !important; }
        .table { border: 1px solid #000 !important; }
        .table th { background-color: #f8f9fa !important; border: 1px solid #000 !important; -webkit-print-color-adjust: exact; }
        .table td { border: 1px solid #dee2e6 !important; }
        .container-fluid { padding: 0 !important; }
        .badge { color: #000 !important; border: 1px solid #ddd !important; background: transparent !important; }

        /* DataTables' own pagination/length/info chrome — printLedgerX()
           already expands the table to show every row before printing, so
           none of this belongs on the page. */
        .dataTables_info, .dataTables_paginate, .dataTables_length, .dataTables_filter {
            display: none !important;
        }

        /* Table wasn't starting on the first printed page — the wrapping
           .card can grow tall with many ledger rows, and the shared
           responsive.css rule `.card { page-break-inside: avoid }` applies to
           every .card on every printed page, pushing the whole card to page
           2. Scoped override, plus resetting the card's own inline
           overflow:hidden (a card allowed to span pages must not clip
           content past its on-screen box height). */
        .print-flow-card {
            page-break-inside: auto !important;
            break-inside: auto !important;
            overflow: visible !important;
        }

        /* Digits wrapping mid-number — lock column widths with
           table-layout:fixed (same recipe already proven on
           suppliers.php/services.php/account_details.php) plus a dedicated
           smaller print font so whole words/numbers fit. */
        #ledgerTable {
            table-layout: fixed !important;
            font-size: 8pt !important;
        }
        #ledgerTable thead th {
            font-size: 7pt !important;
            line-height: 1.15 !important;
            padding: 4px 3px !important;
        }
        #ledgerTable th, #ledgerTable td {
            white-space: normal !important;
            word-wrap: break-word !important;
            width: auto !important;
        }
        /* Every data/footer cell carries its own inline font-size (0.78rem-1.0rem,
           set on screen for visual hierarchy) — an inline style wins over a
           plain ancestor rule, so the #ledgerTable font-size above never
           actually reached these cells. That mismatch, combined with real
           balances running into the billions, is what let amounts overflow
           their column and visually spill into the next one. Target the
           cells directly with !important (which DOES beat a non-important
           inline style) and size money cells smaller still. */
        #ledgerTable tbody td, #ledgerTable tfoot td {
            font-size: 7.5pt !important;
        }
        #ledgerTable th:nth-child(1), #ledgerTable td:nth-child(1) { width: 8%  !important; }
        #ledgerTable th:nth-child(2), #ledgerTable td:nth-child(2) { width: 12% !important; }
        #ledgerTable th:nth-child(3), #ledgerTable td:nth-child(3) { width: 8%  !important; }
        #ledgerTable th:nth-child(4), #ledgerTable td:nth-child(4) { width: 19% !important; }
        #ledgerTable th:nth-child(5), #ledgerTable td:nth-child(5) { width: 18% !important; }
        #ledgerTable th:nth-child(6), #ledgerTable td:nth-child(6) { width: 17% !important; }
        #ledgerTable th:nth-child(7), #ledgerTable td:nth-child(7) { width: 18% !important; }

        /* Money columns must never wrap onto a second line, and get the
           smallest print font of all so genuinely large balances (billions,
           plus the " Dr"/" Cr" suffix on Balance/Closing) still fit inside
           their column instead of overflowing into the next one. Debit/
           Credit/Balance are columns 5-7 in BOTH table variants; the "no
           account selected" summary view also has a money value (Opening) in
           column 4, where the detail view has free-flowing Description text
           — so that one column's rules are scoped to .gl-table-summary only. */
        #ledgerTable td:nth-child(5),
        #ledgerTable td:nth-child(6),
        #ledgerTable td:nth-child(7) {
            white-space: nowrap !important;
            font-size: 7pt !important;
        }
        #ledgerTable.gl-table-summary td:nth-child(4) {
            white-space: nowrap !important;
            font-size: 7pt !important;
        }

        /* tfoot defaults to display:table-footer-group, which browsers
           repeat at the bottom of EVERY printed page when a table spans more
           than one — so "Period Totals" was printing on every page instead
           of once at the true end of the data. table-row-group makes it flow
           as a normal trailing row instead. */
        #ledgerTable tfoot {
            display: table-row-group !important;
        }
        #ledgerTable tfoot td {
            font-size: 8.5pt !important;
            white-space: nowrap !important;
        }
    }
    /* Canonical I/E Print margin — see i_e_print.md §1 */
    @page { margin: 10mm 8mm 16mm 8mm; }
</style>

<?php require_once ROOT_DIR . '/includes/print_footer_css.php'; ?>
<div class="d-none d-print-block">
    <?php require_once ROOT_DIR . '/includes/print_footer_html.php'; ?>
</div>

<?php includeFooter(); ob_end_flush(); ?>
