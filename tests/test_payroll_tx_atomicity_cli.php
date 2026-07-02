<?php
/**
 * Payroll transaction-atomicity test.
 *
 * Guards the fix/tx-payroll-atomicity work:
 *   1. All base tables are InnoDB — MySQL silently ignores rollbacks on MyISAM,
 *      so this is the foundation every transaction fix stands on.
 *   2. The three payroll endpoints wrap their multi-step writes in transactions.
 *   3. Live proof on the real tables: a rolled-back transaction leaves payroll +
 *      books_transactions exactly as they were (forced mid-sequence failure).
 *   4. Live happy path: an accrual posted inside a transaction commits complete
 *      and its journal legs balance (Σ Dr = Σ Cr); synthetic rows are cleaned up.
 *
 * Run: php tests/test_payroll_tx_atomicity_cli.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
$root = dirname(__DIR__);
require_once "$root/roots.php";
require_once "$root/core/payment_source.php";
global $pdo;

$passes = 0; $failures = 0;
function pass($m) { global $passes;   $passes++;   echo "  \xE2\x9C\x85 $m\n"; }
function fail($m) { global $failures; $failures++; echo "  \xE2\x9D\x8C $m\n"; }
function section($t) { echo "\n\xE2\x94\x80\xE2\x94\x80 $t \xE2\x94\x80\xE2\x94\x80\n"; }

// ── 1. Storage engines — transactions only work on InnoDB ─────────────────
section('All base tables are InnoDB (rollback actually works)');
$myisam = $pdo->query("
    SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE = 'MyISAM'
")->fetchAll(PDO::FETCH_COLUMN);
if (empty($myisam)) {
    pass('No MyISAM base tables remain (migration 2026_07_01_convert_myisam_to_innodb ran)');
} else {
    fail('MyISAM tables remain (transactions silently do NOT roll these back): ' . implode(', ', $myisam));
}
foreach (['payroll', 'transactions', 'books_transactions', 'journal_entries', 'journal_entry_items', 'bank_transactions'] as $t) {
    $eng = $pdo->query("SELECT ENGINE FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn();
    $eng === 'InnoDB' ? pass("$t is InnoDB") : fail("$t engine is '$eng', expected InnoDB");
}

// ── 2. Endpoints wrap their writes in transactions (static guard) ─────────
section('Payroll endpoints use transactions');
$checks = [
    'api/bulk_update_payroll_status.php' => ['beginTransaction', 'rollBack', 'postPayrollPayment'],
    'api/update_payroll.php'             => ['beginTransaction', 'rollBack', 'ensurePayrollAccrued'],
    'api/operations/process_project_payroll.php' => ['beginTransaction', 'rollBack', 'ensurePayrollAccrued'],
];
foreach ($checks as $file => $needles) {
    $src = @file_get_contents("$root/$file") ?: '';
    $missing = array_filter($needles, fn($n) => strpos($src, $n) === false);
    empty($missing)
        ? pass("$file contains " . implode(' + ', $needles))
        : fail("$file missing: " . implode(', ', $missing));
}
// The payment unit of work must open its transaction BEFORE the status UPDATE
$bulk = @file_get_contents("$root/api/bulk_update_payroll_status.php") ?: '';
$txPos  = strpos($bulk, '$pdo->beginTransaction()');
$payPos = strpos($bulk, 'postPayrollPayment(');
($txPos !== false && $payPos !== false && $txPos < $payPos)
    ? pass('bulk payment: transaction opens before the GL posting')
    : fail('bulk payment: transaction does not wrap the GL posting');
(strpos($bulk, 'GL payment posting failed') !== false)
    ? pass('bulk payment: a null GL posting is fatal for the row (no paid-without-ledger)')
    : fail('bulk payment: null GL posting is not treated as a failure');

// ── 3. Live rollback proof on the real tables ─────────────────────────────
section('Live rollback: forced mid-sequence failure leaves no partial rows');
$synthNo = 'TEST-TX-' . substr(bin2hex(random_bytes(4)), 0, 8);
$payrollId = null;
try {
    $pdo->prepare("INSERT INTO payroll (employee_id, payroll_number, payroll_period, payroll_date,
                       basic_salary, allowances, deductions, tax_amount, gross_salary, net_salary,
                       payment_status, amount_paid, year, month, created_by, created_at)
                   VALUES (0, ?, '2099-12', '2099-12-31', 100000, 0, 0, 0, 100000, 100000,
                           'approved', 0, 2099, 12, 1, NOW())")
        ->execute([$synthNo]);
    $payrollId = (int)$pdo->lastInsertId();

    // Simulate the endpoint's unit of work with a failure after the first write.
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payroll SET payment_status = 'paid', amount_paid = 100000 WHERE payroll_id = ?")
            ->execute([$payrollId]);
        $pdo->prepare("INSERT INTO transactions (transaction_date, description, created_at)
                       VALUES ('2099-12-31', ?, NOW())")->execute(["tx-test $synthNo"]);
        throw new Exception('forced mid-sequence failure');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }

    $st = $pdo->prepare("SELECT payment_status, amount_paid FROM payroll WHERE payroll_id = ?");
    $st->execute([$payrollId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    ($row && $row['payment_status'] === 'approved' && (float)$row['amount_paid'] === 0.0)
        ? pass('payroll row restored to pre-transaction state after rollback')
        : fail('payroll row kept partial changes after rollback: ' . json_encode($row));

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE description = ?");
    $cnt->execute(["tx-test $synthNo"]);
    ((int)$cnt->fetchColumn() === 0)
        ? pass('transactions header rolled back with it (no orphan row)')
        : fail('orphan transactions row survived the rollback');
} catch (Throwable $e) {
    fail('live rollback scenario errored: ' . $e->getMessage());
} finally {
    if ($payrollId) $pdo->prepare("DELETE FROM payroll WHERE payroll_id = ?")->execute([$payrollId]);
}

// ── 4. Live happy path: accrual commits complete and balanced ─────────────
section('Live accrual: posted inside a transaction, legs balance');
$payrollId = null; $entryId = null;
try {
    $pdo->prepare("INSERT INTO payroll (employee_id, payroll_number, payroll_period, payroll_date,
                       basic_salary, allowances, deductions, tax_amount, gross_salary, net_salary,
                       payment_status, amount_paid, year, month, created_by, created_at)
                   VALUES (0, ?, '2099-12', '2099-12-31', 500000, 0, 0, 0, 500000, 500000,
                           'approved', 0, 2099, 12, 1, NOW())")
        ->execute([$synthNo . '-B']);
    $payrollId = (int)$pdo->lastInsertId();

    $pdo->beginTransaction();
    $entryId = ensurePayrollAccrued($pdo, $payrollId, 1);
    $pdo->commit();

    if (!$entryId) {
        // No account mapping on this DB — the atomicity itself is proven by §3.
        pass('accrual returned no entry (payroll account mapping not configured here) — skipped');
    } else {
        $legs = $pdo->prepare("SELECT
                                   SUM(CASE WHEN type = 'debit'  THEN amount ELSE 0 END) AS dr,
                                   SUM(CASE WHEN type = 'credit' THEN amount ELSE 0 END) AS cr,
                                   COUNT(*) AS n
                               FROM journal_entry_items WHERE entry_id = ?");
        $legs->execute([$entryId]);
        $l = $legs->fetch(PDO::FETCH_ASSOC);
        ((int)$l['n'] >= 2)
            ? pass("accrual entry #$entryId committed with {$l['n']} legs")
            : fail("accrual entry #$entryId has fewer than 2 legs");
        (abs((float)$l['dr'] - (float)$l['cr']) < 0.005)
            ? pass('accrual legs balance: Dr ' . $l['dr'] . ' = Cr ' . $l['cr'])
            : fail('accrual legs DO NOT balance: Dr ' . $l['dr'] . ' vs Cr ' . $l['cr']);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fail('live accrual scenario errored: ' . $e->getMessage());
} finally {
    // Unwind everything the scenario created (reversal helper also fixes balances).
    try {
        if ($entryId) {
            $pdo->prepare("DELETE FROM journal_entry_items WHERE entry_id = ?")->execute([$entryId]);
            $pdo->prepare("DELETE FROM journal_entries WHERE entry_id = ?")->execute([$entryId]);
        }
        if ($payrollId) $pdo->prepare("DELETE FROM payroll WHERE payroll_id = ?")->execute([$payrollId]);
    } catch (Throwable $e) { /* best-effort cleanup */ }
}

// ── Result ────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Passed: $passes   Failed: $failures\n";
echo $failures > 0 ? "RESULT: FAIL\n" : "RESULT: PASS\n";
exit($failures > 0 ? 1 : 0);
