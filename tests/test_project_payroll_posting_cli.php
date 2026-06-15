<?php
/**
 * OUT-16 — Project payroll → GL accrual — CLI test
 *   php tests/test_project_payroll_posting_cli.php
 *
 * Guards api/operations/process_project_payroll.php: when a project payroll run is
 * AUTO-APPROVED, every created payslip must accrue to the GL like the normal payroll
 * approval (OUT-4) — Dr Salaries Expense / Cr PAYE + NSSF + Salaries Payable — instead
 * of inserting payroll rows that never post.
 *
 * NOTE: the `payroll` table is MyISAM (non-transactional), so a wrapping ROLLBACK
 * cannot undo its inserts. The test therefore drives the REAL endpoint for a future,
 * unused period (2031-01) and TEARS DOWN every row + accrual it created (payroll,
 * transactions, books_transactions, journal_entries mirror, statutory_remittances),
 * leaving the database as found.
 */

$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/financial_reports.php";
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['user_id']  = 4;
$_SESSION['username'] = 'admin';
$_SESSION['role']     = 'admin';
$_SESSION['is_admin'] = true;
global $pdo;

$pass = 0; $fail = 0;
function pass(string $m): void { global $pass; $pass++; echo "  \033[32m✅\033[0m $m\n"; }
function fail(string $m): void { global $fail; $fail++; echo "  \033[31m❌ $m\033[0m\n"; }
function section(string $t): void { echo "\n\033[1m── $t ──\033[0m\n"; }
function money(float $n): string { return number_format($n, 2); }

$TEST_YEAR = 2031; $TEST_MONTH = 1; $TEST_PERIOD = '2031-01';

/** Remove every artefact the endpoint created for the test period (MyISAM-safe). */
function teardown(PDO $pdo, int $year, int $month, string $period): void {
    $rows = $pdo->query("SELECT payroll_id, accrual_transaction_id FROM payroll WHERE year=$year AND month=$month")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $txn = (int)($r['accrual_transaction_id'] ?? 0);
        if ($txn) {
            $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id IN (SELECT entry_id FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=?)")->execute([$txn]);
            $pdo->prepare("DELETE FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=?")->execute([$txn]);
            $pdo->prepare("DELETE FROM books_transactions WHERE transaction_id=?")->execute([$txn]);
            $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$txn]);
        }
        $pdo->prepare("DELETE FROM payroll WHERE payroll_id=?")->execute([(int)$r['payroll_id']]);
    }
    $pdo->prepare("DELETE FROM statutory_remittances WHERE period=?")->execute([$period]);
}

register_shutdown_function(function () use ($pdo, $TEST_YEAR, $TEST_MONTH, $TEST_PERIOD) {
    teardown($pdo, $TEST_YEAR, $TEST_MONTH, $TEST_PERIOD);   // safety net on any exit
    global $pass, $fail; static $printed = false; if ($printed) return; $printed = true;
    echo "\nPasses:   \033[32m$pass\033[0m\n";
    echo "Failures: " . ($fail === 0 ? "\033[32m0\033[0m" : "\033[31m$fail\033[0m") . "\n";
    if ($fail > 0) exit(1);
});

$runEndpoint = function (array $post) use ($root): ?array {
    $_POST = $post;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $prevErr = error_reporting(error_reporting() & ~E_WARNING);
    ob_start(); require "$root/api/operations/process_project_payroll.php";
    $raw = ob_get_clean();
    error_reporting($prevErr);
    return json_decode($raw, true);
};

// ─────────────────────────────────────────────────────────────────────────
section('1. Pick a project with payable staff + confirm the test period is unused');
$proj = $pdo->query("
    SELECT e.project_id, COUNT(*) n
      FROM employees e
     WHERE e.project_id IS NOT NULL AND e.project_id > 0 AND e.status <> 'terminated' AND e.basic_salary > 0
  GROUP BY e.project_id ORDER BY n DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$proj) { fail('no project with payable staff — cannot run the functional test'); return; }
$projectId = (int)$proj['project_id'];

teardown($pdo, $TEST_YEAR, $TEST_MONTH, $TEST_PERIOD);   // ensure a clean window first
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM payroll WHERE year=$TEST_YEAR AND month=$TEST_MONTH")->fetchColumn();
echo "   project #$projectId, {$proj['n']} payable staff, period $TEST_PERIOD\n";
($cnt === 0) ? pass("period $TEST_PERIOD is unused (clean test window)") : fail("period not clean: $cnt rows");

$salExp = (int)$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='default_salaries_expense_account_id'")->fetchColumn();
$gBefore = assertLedgerBalanced($pdo);

// ─────────────────────────────────────────────────────────────────────────
section('2. Auto-approved run posts a balanced accrual per payslip');
try {
    $r = $runEndpoint([
        'project_id'     => $projectId,
        'payroll_period' => $TEST_PERIOD,
        'payroll_date'   => '2031-01-28',
        'auto_approve'   => '1',
        'include_allowances' => '1',
        'include_deductions' => '1',
    ]);
    if (!$r || empty($r['success'])) {
        fail('endpoint did not succeed: ' . json_encode($r));
    } else {
        pass("endpoint success: " . ($r['message'] ?? ''));
        ((int)($r['processed'] ?? 0) > 0) ? pass("processed {$r['processed']} payslip(s)") : fail('processed 0 payslips');

        $rows = $pdo->query("
            SELECT p.payroll_id, p.status, p.gross_salary, p.accrual_transaction_id
              FROM payroll p JOIN employees e ON e.employee_id = p.employee_id
             WHERE p.year=$TEST_YEAR AND p.month=$TEST_MONTH AND e.project_id=$projectId
        ")->fetchAll(PDO::FETCH_ASSOC);
        (count($rows) > 0) ? pass(count($rows) . ' payroll row(s) created') : fail('no payroll rows created');

        $allApproved = true; $allAccrued = true;
        foreach ($rows as $rw) {
            if ($rw['status'] !== 'approved') $allApproved = false;
            if (empty($rw['accrual_transaction_id'])) $allAccrued = false;
        }
        $allApproved ? pass('every created row is status=approved') : fail('some rows not approved');
        $allAccrued  ? pass('every approved row has accrual_transaction_id set (OUT-16 posted)')
                     : fail('a row has NO accrual_transaction_id — accrual did not post');

        $sample = null;
        foreach ($rows as $rw) { if (!empty($rw['accrual_transaction_id'])) { $sample = $rw; break; } }
        if ($sample) {
            $txn = (int)$sample['accrual_transaction_id'];
            $legs = $pdo->query("SELECT account_id, type, amount FROM books_transactions WHERE transaction_id=$txn")->fetchAll(PDO::FETCH_ASSOC);
            $dr = 0.0; $cr = 0.0; $drExp = 0.0;
            foreach ($legs as $l) { $t=(float)$l['amount']; if($l['type']==='debit'){$dr+=$t; if((int)$l['account_id']===$salExp)$drExp+=$t;} else $cr+=$t; }
            (count($legs) >= 2) ? pass("accrual has " . count($legs) . " legs") : fail('accrual has < 2 legs');
            (abs($dr - $cr) < 0.01) ? pass("accrual balances (Dr " . money($dr) . " = Cr " . money($cr) . ")") : fail("accrual unbalanced Dr $dr vs Cr $cr");
            (abs($drExp - (float)$sample['gross_salary']) < 0.01)
                ? pass('Salaries Expense debited the gross salary')
                : fail("Salaries Expense debit ($drExp) != gross ({$sample['gross_salary']})");
            $mir = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE entity_type='books_transaction' AND entity_id=$txn AND status='posted'")->fetchColumn();
            ($mir === 1) ? pass('accrual mirrored into the canonical journal_entries (reports see it)') : fail('accrual not in journal_entries');
        }

        $g = assertLedgerBalanced($pdo);
        $g['ledger_balanced'] ? pass('Σ Dr = Σ Cr holds after posting the project payroll accruals') : fail('ledger Dr≠Cr after posting');
    }
} finally {
    teardown($pdo, $TEST_YEAR, $TEST_MONTH, $TEST_PERIOD);
}

// ─────────────────────────────────────────────────────────────────────────
section('3. Teardown restored the books (no leak, ledger balances as before)');
$cntAfter = (int)$pdo->query("SELECT COUNT(*) FROM payroll WHERE year=$TEST_YEAR AND month=$TEST_MONTH")->fetchColumn();
($cntAfter === 0) ? pass("no payroll rows left for $TEST_PERIOD") : fail("$cntAfter payroll rows leaked");
$gAfter = assertLedgerBalanced($pdo);
(abs($gAfter['sum_debit'] - $gBefore['sum_debit']) < 0.01 && abs($gAfter['sum_credit'] - $gBefore['sum_credit']) < 0.01)
    ? pass('ledger totals identical to before the test (clean teardown)')
    : fail('ledger totals changed: before Dr ' . money($gBefore['sum_debit']) . ' → after Dr ' . money($gAfter['sum_debit']));

// ─────────────────────────────────────────────────────────────────────────
section('4. Pending (no auto-approve) must NOT accrue — accrual is at approval');
try {
    $r = $runEndpoint(['project_id'=>$projectId, 'payroll_period'=>$TEST_PERIOD, 'payroll_date'=>'2031-01-28']);
    if ($r && !empty($r['success'])) {
        $pendingAccrued = (int)$pdo->query("
            SELECT COUNT(*) FROM payroll p JOIN employees e ON e.employee_id=p.employee_id
             WHERE p.year=$TEST_YEAR AND p.month=$TEST_MONTH AND e.project_id=$projectId
               AND p.status='pending' AND p.accrual_transaction_id IS NOT NULL
        ")->fetchColumn();
        ($pendingAccrued === 0)
            ? pass('pending project payroll does NOT post an accrual (correct — posts at approval)')
            : fail("$pendingAccrued pending rows wrongly accrued");
    } else {
        fail('pending run did not succeed: ' . json_encode($r));
    }
} finally {
    teardown($pdo, $TEST_YEAR, $TEST_MONTH, $TEST_PERIOD);
}
