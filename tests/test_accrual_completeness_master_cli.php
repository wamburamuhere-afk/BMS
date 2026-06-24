<?php
/**
 * tests/test_accrual_completeness_master_cli.php
 * ----------------------------------------------
 * MASTER test for the accrual-completeness work (Phase 1 + Phase 2): every
 * transaction type is recognised on the P&L at all statuses except
 * cancelled/rejected/deleted/draft, and the UNPAID balance sits on the Balance
 * Sheet as the correct asset/liability. Covers every modified area:
 *   - core/receivables_payables.php  (AR/AP/accrued/refunds/salaries-payable)
 *   - app/constant/reports/balance_sheet.php  (injections)
 *   - api/account/get_income_statement.php  (accrual recognition)
 *   - api/account/get_income_statement_detail.php  (drill filters reconcile)
 *
 *   php tests/test_accrual_completeness_master_cli.php
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/receivables_payables.php";
global $pdo;

$pass = 0; $fail = 0;
function ok($m){ global $pass; $pass++; echo "  [PASS] $m\n"; }
function no($m){ global $fail; $fail++; echo "  [FAIL] $m\n"; }
function chk($c,$m){ $c?ok($m):no($m); }
function near($a,$b){ return abs((float)$a-(float)$b) < 0.5; }
function src($p){ return file_exists($p)?file_get_contents($p):''; }
function has($hay,$needle,$label){ strpos($hay,$needle)!==false ? ok($label) : no("$label (missing)"); }
register_shutdown_function(function(){ global $pass,$fail; echo "\n".str_repeat('-',56)."\nRESULT: $pass passed, $fail failed\n"; if($fail>0) exit(1); });

$bs  = src("$root/app/constant/reports/balance_sheet.php");
$api = src("$root/api/account/get_income_statement.php");
$det = src("$root/api/account/get_income_statement_detail.php");

// ── 1. Lint every modified file ───────────────────────────────────────────────
echo "== 1. Lint ==\n";
foreach ([
    'core/receivables_payables.php', 'app/constant/reports/balance_sheet.php',
    'api/account/get_income_statement.php', 'api/account/get_income_statement_detail.php',
    'app/bms/invoice/income_statement.php',
] as $f) {
    $rc=0;$o=[]; exec('php -l '.escapeshellarg("$root/$f").' 2>&1',$o,$rc);
    chk($rc===0, "lint: $f");
}

// ── 2. Balance Sheet reads ONE ledger (sub-ledger injections REMOVED) ─────────
// Updated 2026-06-24: the BS no longer injects control accounts from the operational
// sub-ledgers (that double-counted what is already in journal_entries and showed
// phantom balances — e.g. Salaries Payable summing un-posted payroll). They now come
// from posted journal_entries via the page's main query (balance_sheet_one_ledger_plan.md).
echo "\n== 2. Balance Sheet: control accounts come from journal_entries, not injected ==\n";
chk(strpos($bs, 'arInvoicesPosition($pdo)') === false,        'AR no longer injected (sourced from journal_entries)');
chk(strpos($bs, 'apSupplierInvoicesPosition($pdo)') === false, 'AP no longer injected (sourced from journal_entries)');
chk(strpos($bs, 'salariesPayablePosition($pdo)') === false,    'Salaries Payable no longer injected (sourced from journal_entries)');
chk(strpos($bs, 'refundsPayablePosition($pdo)') === false,     'Refunds Payable no longer injected (sourced from journal_entries)');
chk(strpos($bs, 'je.entry_id IS NOT NULL') !== false,          'BS counts only POSTED journal lines (entry_id guard)');

// ── 3. Helpers return numeric positions + reconcile to source documents ───────
echo "\n== 3. Position helpers reconcile to source documents ==\n";
$ar = arInvoicesPosition($pdo)['receivable'];
$ap = apSupplierInvoicesPosition($pdo)['payable'];
$ac = accruedExpensesPosition($pdo)['payable'];
$sp = salariesPayablePosition($pdo)['payable'];
$rf = refundsPayablePosition($pdo)['payable'];
chk(is_numeric($ar) && $ar >= 0, "AR = ".number_format($ar,2));
chk(is_numeric($ap) && $ap >= 0, "AP = ".number_format($ap,2));
chk(is_numeric($ac) && $ac >= 0, "Accrued expenses = ".number_format($ac,2));
chk(is_numeric($sp) && $sp >= 0, "Salaries payable = ".number_format($sp,2));
chk(is_numeric($rf) && $rf >= 0, "Refunds payable = ".number_format($rf,2));

$expAR = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(COALESCE(balance_due, grand_total - COALESCE(paid_amount,0)),0)),0)
                               FROM invoices WHERE status NOT IN ('cancelled','rejected','deleted','draft')")->fetchColumn();
chk(near($ar, $expAR), "AR reconciles to unpaid customer invoices");
$expSP = (float)$pdo->query("SELECT COALESCE(SUM(net_salary),0) FROM payroll WHERE payment_status NOT IN ('paid','cancelled','rejected')")->fetchColumn();
chk(near($sp, $expSP), "Salaries Payable reconciles to unpaid payroll net");

// ── 4. Income Statement recognition is single-source (GL) ─────────────────────
// After the F3 flip the P&L derives from the canonical ledger (glProfitLoss):
// accrual recognition lives in the posting layer (revenue at invoice/IPC/POS
// approval; costs at expense/voucher/payroll/sub-contractor approval), so the
// report no longer carries per-document status predicates — it just sums posted
// journal entries by category.
echo "\n== 4. Income Statement accrual recognition (GL single-source) ==\n";
has($api, "glProfitLoss(", "P&L derives from the GL (posted accrual entries: sales, COGS, sub-contractor, expenses, payroll)");
has($api, "'general_ledger'", "P&L is single-source (meta.source = general_ledger)");
chk(strpos($api, "payment_status NOT IN") === false && strpos($api, "STR_TO_DATE(CONCAT(payroll_period") === false,
    "no document-scan recognition predicates remain in the report (accrual is in the posting layer)");

// ── 5. Drill-down filters MATCH the report (so totals reconcile) ──────────────
echo "\n== 5. Drill-down filters reconcile with the report ==\n";
// product_cogs still uses the GL filter; invoices case now shows all statuses
// with gl_posted flag so the pipeline is visible but excluded from totals.
chk(substr_count($det, "IN ('approved','sent','paid','partial','overdue')") >= 1, "product_cogs drill uses GL status filter");
chk(strpos($det, "'pipeline'") !== false, "invoices drill emits pipeline group for unposted invoices");
chk(strpos($det, 'pipeline_total') !== false, "pipeline total returned separately so it does not inflate P&L");
has($det, "payment_status NOT IN ('cancelled','rejected')", "payroll drill matches accrual recognition");

// ── 6. Runtime: a pending invoice is recognised AND raises AR ─────────────────
echo "\n== 6. Runtime: pending invoice → revenue + AR (rolled back) ==\n";
$cust = (int)$pdo->query("SELECT customer_id FROM customers LIMIT 1")->fetchColumn();
if ($cust <= 0) { no('no customer to test'); }
else {
    $arBefore = arInvoicesPosition($pdo)['receivable'];
    $pdo->beginTransaction();
    $iid = 0;
    try {
        $pdo->prepare("INSERT INTO invoices (invoice_number, customer_id, invoice_date, subtotal, tax_amount, grand_total, paid_amount, balance_due, status, created_at)
                       VALUES (?, ?, CURDATE(), 1000000, 180000, 1180000, 0, 1180000, 'pending', NOW())")
            ->execute(['__ACC_MASTER_'.uniqid(), $cust]);
        $iid = (int)$pdo->lastInsertId();
        $arAfter = arInvoicesPosition($pdo)['receivable'];
        chk(near($arAfter - $arBefore, 1180000), "pending invoice raises AR by its unpaid balance (1,180,000)");
    } catch (Throwable $e) { no('runtime error: '.$e->getMessage()); }
    finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    ok('runtime invoice rolled back');
}
