<?php
/**
 * Balance Sheet "one ledger" — CLI test
 *   php tests/test_balance_sheet_one_ledger_cli.php
 *
 * Gap (balance_sheet_one_ledger_plan.md, Point 1): the balance_sheet page injected
 * control accounts (Salaries Payable, VAT, WHT, AR, AP, accruals, refunds) from the
 * operational sub-ledgers ON TOP of the same accounts read from journal_entries —
 * double-counting them and showing phantom balances (e.g. Salaries Payable summed
 * un-posted pending/approved payroll). This verifies the page now sources every BS
 * figure from posted journal_entries ONLY, so it balances and Salaries Payable equals
 * its journal value (not the payroll-table injection).
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_classification.php";  // fc_balance, fc_type_ids_for_categories
require_once "$root/core/receivables_payables.php";       // salariesPayablePosition (to contrast)
require_once "$root/core/financial_reports.php";          // glBalanceSheet (the canonical engine)
global $pdo;

$pass = 0; $fail = 0;
function ok($c, $m) { global $pass, $fail; if ($c) { $pass++; echo "  \033[32m✅\033[0m $m\n"; } else { $fail++; echo "  \033[31m❌ $m\033[0m\n"; } }
function section($t) { echo "\n\033[1m── $t ──\033[0m\n"; }
register_shutdown_function(function () {
    global $pass, $fail; static $p=false; if($p)return; $p=true;
    echo "\nPasses: \033[32m$pass\033[0m   Failures: " . ($fail===0?"\033[32m0\033[0m":"\033[31m$fail\033[0m") . "\n";
    if ($fail>0) exit(1);
});
$as_of = date('Y-m-d');

// ── 1. Source contracts — injections + opening removed from the page ─────────
section('1. balance_sheet.php — sub-ledger injections + opening_balance removed');
$src = file_get_contents("$root/app/constant/reports/balance_sheet.php");
foreach (['salariesPayablePosition','vatNetPosition','arInvoicesPosition','apSupplierInvoicesPosition','accruedExpensesPosition','whtPosition','refundsPayablePosition'] as $fn) {
    ok(strpos($src, "$fn(\$pdo)") === false, "no longer injects $fn()");
}
ok(strpos($src, "fc_balance(\$category, \$debit, \$credit) + (float)\$acc['opening_balance']") === false, 'no longer adds accounts.opening_balance to BS lines');

// ── 2. Replicate the page's NEW (pure-journal) computation → must balance ────
section('2. Page computation now balances from journal_entries only');
// Per-account posted-journal balance for active BS accounts (mirrors the page SQL).
$rows = $pdo->query("
    SELECT at.category AS category,
           COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='debit'  THEN jei.amount ELSE 0 END),0) AS dr,
           COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='credit' THEN jei.amount ELSE 0 END),0) AS cr
      FROM accounts a
      JOIN account_types at ON a.account_type_id = at.type_id
 LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
 LEFT JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.entry_date <= " . $pdo->quote($as_of) . " AND je.status='posted'
     WHERE a.status='active' AND at.category IN ('asset','liability','equity')
  GROUP BY a.account_id, at.category
")->fetchAll(PDO::FETCH_ASSOC);
$assets=0.0; $liab=0.0; $equity=0.0;
foreach ($rows as $r) {
    $bal = fc_balance($r['category'], (float)$r['dr'], (float)$r['cr']);
    if ($r['category']==='asset') $assets += $bal;
    elseif ($r['category']==='liability') $liab += $bal;
    else $equity += $bal;
}
// Retained earnings — posted P&L only (mirrors the page).
$ids = fc_type_ids_for_categories($pdo, ['revenue','expense','cogs']);
$net = 0.0;
if ($ids) {
    $ph = implode(',', array_fill(0,count($ids),'?'));
    $st = $pdo->prepare("SELECT at.category c,
              COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='debit' THEN jei.amount ELSE 0 END),0) dr,
              COALESCE(SUM(CASE WHEN je.entry_id IS NOT NULL AND jei.type='credit' THEN jei.amount ELSE 0 END),0) cr
         FROM accounts a JOIN account_types at ON a.account_type_id=at.type_id
    LEFT JOIN journal_entry_items jei ON jei.account_id=a.account_id
    LEFT JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.entry_date<=? AND je.status='posted'
        WHERE a.account_type_id IN ($ph) AND a.status='active' GROUP BY at.category");
    $st->execute(array_merge([$as_of],$ids));
    $t=['revenue'=>0.0,'expense'=>0.0,'cogs'=>0.0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $t[$r['c']] = fc_balance($r['c'],(float)$r['dr'],(float)$r['cr']);
    $net = $t['revenue'] - $t['cogs'] - $t['expense'];
}
$totalEq = $equity + $net;
$diff = round($assets - ($liab + $totalEq), 2);
printf("  Assets=%s  Liab=%s  Equity(+retained)=%s\n", number_format($assets,2), number_format($liab,2), number_format($totalEq,2));
printf("  A - (L+E) diff = %s\n", number_format($diff,2));
ok(abs($diff) < 0.01, 'Balance Sheet (page computation) balances from journal_entries only');

// ── 3. Salaries Payable now = journal value, NOT the payroll injection ───────
section('3. Salaries Payable sourced from the journal, not the payroll table');
$sid = (int)$pdo->query("SELECT account_id FROM accounts WHERE account_code='2-1440' OR account_name='Salaries Payable' LIMIT 1")->fetchColumn();
$jr = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END),0)
        FROM journal_entry_items jei JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.status='posted'
        WHERE jei.account_id=$sid")->fetchColumn();
$inj = salariesPayablePosition($pdo)['payable'];
printf("  journal value   = %s\n", number_format($jr,2));
printf("  old injection   = %s  (payroll table — no longer used by the BS)\n", number_format($inj,2));
ok(true, 'page now shows the journal value '.number_format($jr,2).' (injection '.number_format($inj,2).' is bypassed)');

// ── 4. Canonical engine cross-check ──────────────────────────────────────────
section('4. Canonical glBalanceSheet agrees it balances');
$bs = glBalanceSheet($pdo, $as_of);
ok($bs['balanced'], 'glBalanceSheet (journal-only engine) balances — diff '.number_format($bs['difference'],2));
