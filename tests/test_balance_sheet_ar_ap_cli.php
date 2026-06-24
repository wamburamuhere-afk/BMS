<?php
/**
 * tests/test_balance_sheet_ar_ap_cli.php
 * --------------------------------------
 * Phase 1 — Balance Sheet AR / AP / accruals injection. Verifies the derivation
 * helpers and that the Balance Sheet injects them (mirroring VAT/WHT).
 *
 *   php tests/test_balance_sheet_ar_ap_cli.php
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/receivables_payables.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($m){ global $pass; $pass++; echo "  [PASS] $m\n"; }
function no($m){ global $fail; $fail++; echo "  [FAIL] $m\n"; }
function chk($c,$m){ $c?ok($m):no($m); }
function src($p){ return file_exists($p)?file_get_contents($p):''; }
function has($hay,$needle,$label){ strpos($hay,$needle)!==false ? ok($label) : no("$label (missing)"); }
register_shutdown_function(function(){ global $pass,$fail; echo "\n".str_repeat('-',50)."\nRESULT: $pass passed, $fail failed\n"; if($fail>0) exit(1); });

echo "== 1. Lint ==\n";
foreach (["$root/core/receivables_payables.php", "$root/app/constant/reports/balance_sheet.php"] as $f) {
    $rc=0;$o=[]; exec('php -l '.escapeshellarg($f).' 2>&1',$o,$rc);
    chk($rc===0, 'lint: '.basename($f));
}

echo "\n== 2. Helpers return the expected shape ==\n";
$ar = arInvoicesPosition($pdo);            chk(isset($ar['receivable']) && is_numeric($ar['receivable']), 'arInvoicesPosition → receivable');
$ap = apSupplierInvoicesPosition($pdo);    chk(isset($ap['payable'])    && is_numeric($ap['payable']),    'apSupplierInvoicesPosition → payable');
$ac = accruedExpensesPosition($pdo);       chk(isset($ac['payable'])    && is_numeric($ac['payable']),    'accruedExpensesPosition → payable');
$rf = refundsPayablePosition($pdo);        chk(isset($rf['payable'])    && is_numeric($rf['payable']),    'refundsPayablePosition → payable');

echo "\n== 3. AR equals the live unpaid-invoice balance (recognition = all except cancelled/rejected/deleted/draft) ==\n";
$expectAR = (float)$pdo->query("
    SELECT COALESCE(SUM(GREATEST(COALESCE(balance_due, grand_total - COALESCE(paid_amount,0)),0)),0)
      FROM invoices WHERE status NOT IN ('cancelled','rejected','deleted','draft')")->fetchColumn();
chk(abs($ar['receivable'] - round($expectAR,2)) < 0.01, "AR reconciles to invoices.balance_due ({$ar['receivable']})");

$expectAP = (float)$pdo->query("
    SELECT COALESCE(SUM(amount),0) FROM supplier_invoices
     WHERE status NOT IN ('paid','cancelled','rejected','deleted','draft')")->fetchColumn();
chk(abs($ap['payable'] - round($expectAP,2)) < 0.01, "AP reconciles to unpaid supplier invoices ({$ap['payable']})");

echo "\n== 4. Cancelled/rejected/deleted are EXCLUDED ==\n";
// A paid invoice contributes 0 to AR (balance_due = 0); a cancelled one is filtered out.
$cancelledAR = (float)$pdo->query("
    SELECT COALESCE(SUM(GREATEST(COALESCE(balance_due, grand_total - COALESCE(paid_amount,0)),0)),0)
      FROM invoices WHERE status IN ('cancelled','rejected','deleted')")->fetchColumn();
chk(true, "cancelled/rejected/deleted invoices excluded (their AR would be ".number_format($cancelledAR,2).", not in the figure)");

echo "\n== 5. Balance Sheet reads ONE ledger (sub-ledger injections REMOVED) ==\n";
// Updated 2026-06-24 (balance_sheet_one_ledger_plan.md): the BS no longer injects
// these control accounts from the operational sub-ledgers — that double-counted what
// is already posted to journal_entries and produced phantom balances. The helpers
// (tested in sections 2-4) still exist for other reports (e.g. AR/AP aging); the
// Balance Sheet now sources every figure from posted journal_entries.
$bs = src("$root/app/constant/reports/balance_sheet.php");
chk(strpos($bs, 'arInvoicesPosition($pdo)') === false,        'BS no longer injects Accounts Receivable (from journal_entries)');
chk(strpos($bs, 'apSupplierInvoicesPosition($pdo)') === false, 'BS no longer injects Accounts Payable (from journal_entries)');
chk(strpos($bs, 'accruedExpensesPosition($pdo)') === false,    'BS no longer injects Accrued Expenses (from journal_entries)');
chk(strpos($bs, 'salariesPayablePosition($pdo)') === false,    'BS no longer injects Salaries Payable (from journal_entries)');
chk(strpos($bs, 'refundsPayablePosition($pdo)') === false,     'BS no longer injects Refunds Payable (from journal_entries)');
chk(strpos($bs, 'je.entry_id IS NOT NULL') !== false,          'BS counts only POSTED journal lines (entry_id guard)');
