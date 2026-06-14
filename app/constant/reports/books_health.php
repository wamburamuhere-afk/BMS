<?php
/**
 * Books Health Check — read-only ledger integrity diagnostic
 * ----------------------------------------------------------
 * Runs the single-source GL diagnostics from core/financial_reports.php against the
 * live database and reports whether the books are sound, BEFORE anyone trusts the
 * financial statements (e.g. before a production go-live).
 *
 * 100% READ-ONLY — it only runs SELECT-based diagnostics; it writes/changes nothing.
 *
 * Checks:
 *   1. Double-entry integrity   — Σ posted debits == Σ posted credits
 *   2. Balance Sheet            — Assets == Liabilities + Equity (real, no plug)
 *   3. P&L ↔ Balance Sheet tie  — all-time net profit == retained earnings
 *   4. Stranded inactive accounts that still carry posted history (remediation list)
 *   5. accounts.opening_balance field self-balanced? (remediation list)
 *   6. Active accounts carrying a balance but left unclassified (would distort the BS)
 */
ob_start();
require_once __DIR__ . '/../../../roots.php';
require_once __DIR__ . '/../../../helpers.php';
require_once __DIR__ . '/../../../core/financial_reports.php';

includeHeader();
if (function_exists('autoEnforcePermission')) autoEnforcePermission('financial_reports');

$as_of_date = (isset($_GET['as_of_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['as_of_date']))
    ? $_GET['as_of_date'] : date('Y-m-d');

// ── Run the diagnostics (read-only) ──────────────────────────────────────────
$err = null;
try {
    $guard     = assertLedgerBalanced($pdo, $as_of_date);
    $bs        = glBalanceSheet($pdo, $as_of_date);
    $inception = $pdo->query("SELECT COALESCE(MIN(entry_date),'2000-01-01') FROM journal_entries WHERE status='posted'")->fetchColumn();
    $plAll     = glProfitLoss($pdo, $inception, $as_of_date);
    $stranded  = glStrandedInactiveAccounts($pdo);
    $obi       = glOpeningBalanceImbalance($pdo);
    $unclassed = $bs['unclassified'] ?? [];
} catch (Throwable $e) {
    $err = $e->getMessage();
}

$money = fn($n) => number_format((float)$n, 2);

if ($err === null) {
    $tie_ok        = abs($plAll['net_profit'] - $bs['retained_earnings']) < 0.01;
    $issue_count   = (int)(!$guard['ledger_balanced']) + (int)(!$guard['bs_balanced']) + (int)(!$tie_ok)
                   + (count($stranded) ? 1 : 0) + (int)(!$obi['balanced']) + (count($unclassed) ? 1 : 0);
    $all_ok        = $issue_count === 0;
}
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0"><i class="bi bi-heart-pulse me-2"></i>Books Health Check</h4>
            <p class="text-muted small mb-0">Read-only ledger integrity diagnostic — changes nothing.</p>
        </div>
        <form method="get" class="d-flex align-items-end gap-2">
            <input type="hidden" name="page" value="books_health">
            <div>
                <label class="form-label small mb-1">As of date</label>
                <input type="date" name="as_of_date" value="<?= safe_output($as_of_date) ?>" class="form-control form-control-sm">
            </div>
            <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Re-run</button>
        </form>
    </div>

<?php if ($err !== null): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i>
        Diagnostic could not run: <?= safe_output($err) ?>
    </div>
<?php else: ?>

    <!-- Overall verdict -->
    <div class="alert <?= $all_ok ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center mb-4">
        <i class="bi <?= $all_ok ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' ?> fs-3 me-3"></i>
        <div>
            <div class="fw-bold fs-5">
                <?= $all_ok
                    ? 'Healthy — the books balance and the statements reconcile.'
                    : ($issue_count . ' issue' . ($issue_count === 1 ? '' : 's') . ' found — review the details below before trusting the reports.') ?>
            </div>
            <div class="small text-muted">As of <?= safe_output($as_of_date) ?>. This page only reads data; remediate any issues per-record through the normal screens.</div>
        </div>
    </div>

    <!-- Headline checks -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-3"><i class="bi <?= $guard['ledger_balanced'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i></div>
                <div class="small text-muted mt-1">Double-entry (Σ Dr = Σ Cr)</div>
                <div class="small">Dr <?= $money($guard['sum_debit']) ?><br>Cr <?= $money($guard['sum_credit']) ?></div>
                <?php if (!$guard['ledger_balanced']): ?><div class="small text-danger">diff <?= $money($guard['dr_cr_difference']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-3"><i class="bi <?= $guard['bs_balanced'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i></div>
                <div class="small text-muted mt-1">Balance Sheet (A = L + E)</div>
                <div class="small">A <?= $money($bs['total_assets']) ?></div>
                <?php if (!$guard['bs_balanced']): ?><div class="small text-danger">diff <?= $money($bs['difference']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-3"><i class="bi <?= $tie_ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i></div>
                <div class="small text-muted mt-1">P&amp;L ↔ Balance Sheet tie</div>
                <div class="small">Net profit <?= $money($plAll['net_profit']) ?></div>
                <?php if (!$tie_ok): ?><div class="small text-danger">≠ retained <?= $money($bs['retained_earnings']) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-3 fw-bold <?= $issue_count ? 'text-warning' : 'text-success' ?>"><?= $issue_count ?></div>
                <div class="small text-muted mt-1">Issues to remediate</div>
            </div>
        </div>
    </div>

    <!-- Stranded inactive accounts -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-archive me-1"></i>Inactive accounts still holding posted history
            <?php if (count($stranded)): ?><span class="badge bg-warning text-dark ms-2"><?= count($stranded) ?></span><?php else: ?><span class="badge bg-success ms-2">none</span><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!count($stranded)): ?>
                <p class="text-success mb-0"><i class="bi bi-check2 me-1"></i>No deactivated account carries posted activity.</p>
            <?php else: ?>
                <p class="small text-muted">These accounts were deactivated but still hold real postings. The reports include them (so the books still balance), but they should be reactivated or merged into the live chart.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Code</th><th>Account</th><th>Category</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
                        <tbody>
                        <?php foreach ($stranded as $s): ?>
                            <tr><td><?= safe_output($s['account_code']) ?></td><td><?= safe_output($s['account_name']) ?></td>
                                <td><?= safe_output($s['category'] ?? '—') ?></td>
                                <td class="text-end"><?= $money($s['debit']) ?></td><td class="text-end"><?= $money($s['credit']) ?></td>
                                <td class="text-end fw-semibold"><?= $money($s['balance']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Opening-balance field health -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-sliders me-1"></i>Opening-balance field self-balanced?
            <?php if ($obi['balanced']): ?><span class="badge bg-success ms-2">balanced</span><?php else: ?><span class="badge bg-warning text-dark ms-2">off by <?= $money($obi['difference']) ?></span><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($obi['balanced']): ?>
                <p class="text-success mb-0"><i class="bi bi-check2 me-1"></i>The accounts.opening_balance field is self-balanced (debit-side = credit-side).</p>
            <?php else: ?>
                <p class="small text-muted">The opening-balance field does not balance (debit-side <?= $money($obi['debit_side']) ?> vs credit-side <?= $money($obi['credit_side']) ?>). The statements ignore this field, but it should be corrected — post a real opening journal entry, then zero the field.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Code</th><th>Account</th><th>Status</th><th>Side</th><th class="text-end">Opening balance</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($obi['accounts'], 0, 30) as $a): ?>
                            <tr><td><?= safe_output($a['account_code']) ?></td><td><?= safe_output($a['account_name']) ?></td>
                                <td><?= safe_output($a['status']) ?></td><td><?= safe_output($a['normal_side']) ?></td>
                                <td class="text-end"><?= $money($a['opening_balance']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Unclassified accounts carrying a balance -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-question-octagon me-1"></i>Accounts with a balance but no classification
            <?php if (count($unclassed)): ?><span class="badge bg-warning text-dark ms-2"><?= count($unclassed) ?></span><?php else: ?><span class="badge bg-success ms-2">none</span><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!count($unclassed)): ?>
                <p class="text-success mb-0"><i class="bi bi-check2 me-1"></i>Every account carrying a balance is classified (asset/liability/equity/revenue/expense).</p>
            <?php else: ?>
                <p class="small text-muted">These accounts hold a balance but have no account-type category, so they can't be placed on the Balance Sheet — classify them in Settings → Account Types.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Code</th><th>Account</th><th class="text-end">Balance</th></tr></thead>
                        <tbody>
                        <?php foreach ($unclassed as $u): ?>
                            <tr><td><?= safe_output($u['account_code']) ?></td><td><?= safe_output($u['account_name']) ?></td><td class="text-end"><?= $money($u['amount']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
</div>
<script>
// Audit who ran the integrity check (client-side log → api/log_activity.php).
// The page itself performs NO writes; this is the standard report-view log.
$(function(){ if (typeof logReportAction === 'function') logReportAction('Viewed Books Health Check', 'Read-only ledger integrity diagnostic'); });
</script>
<?php includeFooter(); ob_end_flush(); ?>
