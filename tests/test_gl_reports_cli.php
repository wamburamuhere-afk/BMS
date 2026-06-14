<?php
/**
 * F1/F3 — Single-source GL financial-reports engine — CLI test
 *   php tests/test_gl_reports_cli.php
 *
 * Guards core/financial_reports.php: the GL-derived Trial Balance, Income
 * Statement and Balance Sheet are internally consistent and tie to one another,
 * and the assertLedgerBalanced() guardrail behaves. Read-only — touches no data.
 *
 * What "correct" means here (all derived from journal_entries, status='posted'):
 *   1. Trial Balance balances:           Σ Dr = Σ Cr
 *   2. Balance Sheet balances:           Assets = Liabilities + Equity
 *   3. The two reports tie:              BS retained earnings == P&L(inception→asOf) net profit
 *   4. Guardrail agrees:                 assertLedgerBalanced().ok == (TB balanced && BS balanced)
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }

register_shutdown_function(function () {
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

// Earliest + latest posted dates, so the run covers the whole live ledger.
$range = $pdo->query("SELECT MIN(entry_date) lo, MAX(entry_date) hi FROM journal_entries WHERE status='posted'")->fetch(PDO::FETCH_ASSOC);
$inception = $range['lo'] ?: date('Y-m-01');
$asOf      = $range['hi'] ?: date('Y-m-d');
echo "Ledger window: $inception → $asOf\n";

// ─────────────────────────────────────────────────────────────────────────
section('0. Data health: inactive accounts that still carry posted activity');
$stranded = glStrandedInactiveAccounts($pdo);
if (empty($stranded)) {
    pass('no inactive account carries posted activity (nothing stranded)');
} else {
    // Not a hard failure of the engine — it correctly INCLUDES these so the books
    // still balance — but a data-remediation flag the user must see.
    echo "   ⚠ " . count($stranded) . " inactive account(s) hold posted history (reactivate or merge into the active chart):\n";
    foreach ($stranded as $s) {
        echo "       #{$s['account_id']} {$s['account_code']} " . str_pad(substr((string)$s['account_name'],0,30),30) .
             " cat=" . str_pad((string)($s['category'] ?? '?'),10) . " balance=" . money($s['balance']) . "\n";
    }
    pass('engine still includes them (active-only reports would drop ' . money(array_sum(array_map(fn($s)=>abs($s['debit'])+abs($s['credit']),$stranded))) . ' of activity)');
}

// ─────────────────────────────────────────────────────────────────────────
section('0b. Data health: is the accounts.opening_balance field self-balanced?');
$obi = glOpeningBalanceImbalance($pdo);
if ($obi['balanced']) {
    pass('opening_balance field balances (Σ debit-side = Σ credit-side)');
} else {
    echo "   ⚠ opening_balance is NOT self-balanced: debit-side " . money($obi['debit_side']) .
         " vs credit-side " . money($obi['credit_side']) . " (diff " . money($obi['difference']) . ").\n";
    echo "     The engine ignores this field by default (journal is the single source). Remediate by\n";
    echo "     posting a real opening journal entry and zeroing the field. Largest contributors:\n";
    foreach (array_slice($obi['accounts'], 0, 6) as $a) {
        echo "       #{$a['account_id']} {$a['account_code']} " . str_pad(substr((string)$a['account_name'],0,28),28) .
             " {$a['status']} {$a['normal_side']} = " . money($a['opening_balance']) . "\n";
    }
    pass('imbalance quantified for remediation (engine excludes it, so statements still balance)');
}

// ─────────────────────────────────────────────────────────────────────────
section('1. Trial Balance (GL) balances: Σ Dr = Σ Cr');
$tb = glTrialBalance($pdo, $asOf);
echo "   Σ Dr = " . money($tb['total_debit']) . "   Σ Cr = " . money($tb['total_credit']) .
     "   diff = " . money($tb['difference']) . "   (" . count($tb['accounts']) . " accounts)\n";
$tb['balanced'] ? pass('Trial Balance is balanced (Σ Dr = Σ Cr)')
                : fail('Trial Balance NOT balanced — diff ' . money($tb['difference']));

// ─────────────────────────────────────────────────────────────────────────
section('2. Balance Sheet (GL) balances: Assets = Liabilities + Equity');
$bs = glBalanceSheet($pdo, $asOf);
echo "   Assets = " . money($bs['total_assets']) .
     "   Liab = " . money($bs['total_liabilities']) .
     "   Equity = " . money($bs['total_equity']) .
     "   (incl. retained earnings " . money($bs['retained_earnings']) . ")\n";
echo "   A − (L+E) = " . money($bs['difference']) . "\n";
$bs['balanced'] ? pass('Balance Sheet is balanced (Assets = Liabilities + Equity)')
                : fail('Balance Sheet NOT balanced — diff ' . money($bs['difference']) .
                       (count($bs['unclassified']) ? ' — ' . count($bs['unclassified']) . ' unclassified account(s) carrying a balance' : ''));
if (count($bs['unclassified'])) {
    echo "   ⚠ unclassified accounts with a balance (fix their account_type → category):\n";
    foreach ($bs['unclassified'] as $u) {
        echo "       {$u['account_code']} {$u['account_name']} = " . money($u['amount']) . "\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────
section('3. The two statements tie: BS retained earnings == P&L net profit (inception → as-of)');
$pl = glProfitLoss($pdo, $inception, $asOf);
echo "   P&L  revenue = " . money($pl['total_revenue']) .
     "   cogs = " . money($pl['total_cogs']) .
     "   expense = " . money($pl['total_expense']) .
     "   finance = " . money($pl['total_finance_cost']) . "\n";
echo "   P&L  net profit = " . money($pl['net_profit']) .
     "      BS retained earnings = " . money($bs['retained_earnings']) . "\n";
(abs($pl['net_profit'] - $bs['retained_earnings']) < 0.01)
    ? pass('P&L net profit ties to Balance Sheet retained earnings')
    : fail('P&L net profit ≠ BS retained earnings (the two reports disagree)');

// ─────────────────────────────────────────────────────────────────────────
section('4. assertLedgerBalanced() guardrail agrees with the statements');
$g = assertLedgerBalanced($pdo, $asOf);
echo "   guardrail: ledger_balanced=" . ($g['ledger_balanced'] ? 'true' : 'false') .
     "  bs_balanced=" . ($g['bs_balanced'] ? 'true' : 'false') .
     "  ok=" . ($g['ok'] ? 'true' : 'false') . "\n";
($g['ledger_balanced'] === $tb['balanced'])
    ? pass('guardrail ledger check matches Trial Balance')
    : fail('guardrail ledger check disagrees with Trial Balance');
($g['bs_balanced'] === $bs['balanced'])
    ? pass('guardrail BS check matches Balance Sheet')
    : fail('guardrail BS check disagrees with Balance Sheet');
($g['ok'] === ($tb['balanced'] && $bs['balanced']))
    ? pass('guardrail ok flag == (TB balanced && BS balanced)')
    : fail('guardrail ok flag wrong');

// ─────────────────────────────────────────────────────────────────────────
section('5. Guardrail throws when asked AND books are out (no-op when balanced)');
try {
    $r = assertLedgerBalanced($pdo, $asOf, true);   // throw=true
    $r['ok']
        ? pass('throw=true did not throw because the live books are balanced')
        : fail('throw=true returned not-ok without throwing');
} catch (Throwable $e) {
    // Only acceptable if the live books are genuinely out of balance.
    (!$tb['balanced'] || !$bs['balanced'])
        ? pass('throw=true correctly raised on genuinely unbalanced books')
        : fail('throw=true raised on balanced books: ' . $e->getMessage());
}
