<?php
/**
 * Areas B + C — Income Statement revenue is TRUE to source — CLI test
 *   php tests/test_income_statement_revenue_truth_cli.php
 *
 * Area B (supplier credits are NOT revenue):
 *   - the supplier-credits account is re-classified OUT of `revenue` (→ cogs, a cost
 *     reduction); no revenue line is a supplier-credit account.
 *   - forward: a debit-note refund posts Dr Bank / Cr Accounts Payable (purchase-side),
 *     never income (proven via a rolled-back posting).
 * Area C (revenue completeness):
 *   - no recognised-status invoice (approved/partial/paid/overdue) is missing its GL
 *     revenue; recognition is idempotent (re-run = already_posted).
 * Cross-cutting: Trial Balance / Balance Sheet still reconcile (net profit == retained).
 *
 * Read-only except a rolled-back posting probe; touches no data permanently.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
require_once "$root/core/revenue_posting.php";
require_once "$root/core/gl_accounts.php";
require_once "$root/core/money_in_posting.php";   // postInflow path / postDepositEntry
require_once "$root/core/payment_source.php";
global $pdo;
$uid = (int)($pdo->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses:   \033[32m$pass\033[0m\nFailures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});

$range = $pdo->query("SELECT MIN(entry_date) lo, MAX(entry_date) hi FROM journal_entries WHERE status='posted'")->fetch(PDO::FETCH_ASSOC);
$from = $range['lo'] ?: date('Y-01-01'); $to = $range['hi'] ?: date('Y-m-d');

// ─────────────────────────────────────────────────────────────────────────
section('B1. Supplier credits are OUT of the Revenue category');
$scId = (int)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='default_supplier_credits_account_id' AND setting_value REGEXP '^[0-9]+$' LIMIT 1")->fetchColumn() ?: 0);
if (!$scId) $scId = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='4-9000' LIMIT 1")->fetchColumn() ?: 0);
if ($scId) {
    $cat = $pdo->query("SELECT at.category FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id WHERE a.account_id=$scId")->fetchColumn();
    ($cat !== 'revenue') ? pass("supplier-credits account (#$scId) is NOT revenue (now '$cat')") : fail('supplier-credits account is still category=revenue');
} else { pass('no supplier-credits account configured (nothing to mis-classify)'); }
$pl = glProfitLoss($pdo, $from, $to);
$revHasSupCredit = false;
foreach ($pl['revenue'] as $l) { if ((int)$l['account_id'] === $scId) $revHasSupCredit = true; }
$revHasSupCredit ? fail('a supplier-credit account still appears in the Revenue section') : pass('no supplier-credit line in the Revenue section');

// ─────────────────────────────────────────────────────────────────────────
section('B2. Forward: a debit-note refund posts Dr Bank / Cr Accounts Payable');
$src = file_get_contents("$root/api/purchase/pay_debit_note.php");
(strpos($src, 'apAccountId(') !== false) ? pass('pay_debit_note resolves Accounts Payable for the credit leg') : fail('pay_debit_note no longer routes to AP');
(strpos($src, 'default_supplier_credits_account_id') === false) ? pass('pay_debit_note no longer credits the supplier-credits INCOME account') : fail('pay_debit_note still uses the income account');
// Prove the entry SHAPE with a rolled-back posting (Dr Bank / Cr AP).
$bank = (int)(cashBankAccounts($pdo)[0]['account_id'] ?? 0);
$ap   = (int)(apAccountId($pdo) ?? 0);
($bank && $ap) ? pass("resolvers: bank #$bank, AP #$ap") : fail('cannot resolve bank/AP');
if ($bank && $ap) {
    $jeBefore = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
    $pdo->beginTransaction();
    try {
        $res = postDepositEntry($pdo, 'debit_note_refund', 999000001, $bank, $ap, 33000.00, $to, "DNR-TST", "refund test", null, $uid);
        $eid = (int)($res['entry_id'] ?? 0);
        if ($eid) {
            $L = $pdo->query("SELECT account_id,type,amount FROM journal_entry_items WHERE entry_id=$eid")->fetchAll(PDO::FETCH_ASSOC);
            $drBank=0;$crAp=0;$dr=0;$cr=0;
            foreach($L as $l){$t=(float)$l['amount']; if($l['type']==='debit'){$dr+=$t; if((int)$l['account_id']===$bank)$drBank+=$t;} else {$cr+=$t; if((int)$l['account_id']===$ap)$crAp+=$t;}}
            (abs($dr-$cr)<0.01) ? pass("refund entry balances (Dr ".money($dr)." = Cr ".money($cr).")") : fail('refund entry unbalanced');
            (abs($drBank-33000)<0.01) ? pass('Bank (Received Into) debited') : fail('bank not debited');
            (abs($crAp-33000)<0.01) ? pass('Accounts Payable credited — NOT income') : fail('AP not credited');
        } else { fail('refund posting probe did not post: '.($res['reason']??'?')); }
    } finally { $pdo->rollBack(); }
    (((int)$pdo->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn())===$jeBefore) ? pass('probe rolled back cleanly') : fail('probe leaked rows');
}

// ─────────────────────────────────────────────────────────────────────────
section('C1. Revenue completeness — every earned invoice is recognised');
$missing = (int)$pdo->query("
    SELECT COUNT(*) FROM invoices i
     WHERE i.status IN ('approved','partial','paid','overdue')
       AND NOT EXISTS (SELECT 1 FROM journal_entries je
                        WHERE je.entity_type='invoice' AND je.entity_id=i.invoice_id AND je.status='posted')
")->fetchColumn();
($missing === 0) ? pass('0 recognised-status invoices lack a posted revenue entry') : fail("$missing recognised invoices still have no revenue — backfill incomplete");
echo "   total_revenue (net category balance, may be 0 after a period close) = ".money($pl['total_revenue'])."\n";
// Close-aware: a Period Close debits every revenue account to zero it into Retained
// Earnings (the closing entry is stamped entity_type='period_close'). That is correct
// year-end accounting, but it nets the revenue CATEGORY to 0 — so the net balance alone
// can't tell "revenue never posted (the original bug)" from "revenue posted, then
// closed (correct)". To prove real sales WERE recognised, measure the revenue credits
// from operating entries, EXCLUDING the period-close reversal.
$revRecognised = (float)$pdo->query("
    SELECT COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END), 0)
      FROM journal_entry_items jei
      JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status = 'posted'
      JOIN accounts a ON a.account_id = jei.account_id
      JOIN account_types at ON a.account_type_id = at.type_id
     WHERE at.category = 'revenue'
       AND (je.entity_type IS NULL OR je.entity_type <> 'period_close')
       AND je.entry_date <= ".$pdo->quote($to)."
")->fetchColumn();
echo "   revenue recognised (excl. period close) = ".money($revRecognised)."\n";
($revRecognised > 0)
    ? pass('Revenue section carries real recognised sales ('.money($revRecognised).')')
    : fail('Revenue is empty even before any period close — invoice/POS recognition is not posting');

// ─────────────────────────────────────────────────────────────────────────
section('C2. Recognition is idempotent (re-run never double-posts)');
$paidId = (int)($pdo->query("SELECT invoice_id FROM invoices WHERE status='paid' ORDER BY invoice_id LIMIT 1")->fetchColumn() ?: 0);
if ($paidId) {
    $again = postInvoiceRevenue($pdo, $paidId, $uid);
    (($again['reason'] ?? '') === 'already_posted') ? pass("re-running recognition on invoice #$paidId is a no-op (already_posted)") : fail('recognition not idempotent: '.($again['reason']??'?'));
} else { pass('no paid invoice to probe idempotency (skipped)'); }

// ─────────────────────────────────────────────────────────────────────────
section('X. Trial Balance / Balance Sheet still reconcile');
$plAll = glProfitLoss($pdo, $from, $to);
$bs = glBalanceSheet($pdo, $to);
(abs($plAll['net_profit'] - $bs['retained_earnings']) < 0.01)
    ? pass('GL P&L net profit ties to BS retained earnings ('.money($bs['retained_earnings']).')')
    : fail("IS net ".money($plAll['net_profit'])." != BS retained ".money($bs['retained_earnings']));
$g = assertLedgerBalanced($pdo, $to);
$g['ok'] ? pass('assertLedgerBalanced ok (Σ Dr = Σ Cr and Assets = Liab + Equity)') : fail('ledger out of balance: '.json_encode($g));
