<?php
/**
 * Migration: 2026_06_24_payroll_backfill_accruals
 *
 * Posts the missing GL accrual (Dr Salaries Expense / Cr PAYE Payable +
 * NSSF Payable + Salaries Payable) for every approved/paid payroll record
 * that was processed before the accrual system was fully wired.
 *
 * Safe to run multiple times — ensurePayrollAccrued() is idempotent: if an
 * accrual_transaction_id already exists it returns immediately without re-posting.
 *
 * Prerequisites: all six payroll GL accounts must be set in System Settings:
 *   default_salaries_expense_account_id
 *   default_paye_payable_account_id
 *   default_nssf_payable_account_id
 *   default_salaries_payable_account_id
 *   default_sdl_expense_account_id
 *   default_sdl_payable_account_id
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/payment_source.php';

// Use the first active admin user as the posting user (no session in migrations)
$adminId = (int)($pdo->query("SELECT user_id FROM users WHERE is_active = 1 ORDER BY user_id LIMIT 1")->fetchColumn() ?: 1);

// Fix ghost accrual_transaction_id on GL-TRACE-001: txn 10013 no longer exists
// in transactions/books_transactions so the reference is dead. Clear it so the
// backfill loop below re-posts a real accrual for that record.
$pdo->prepare("
    UPDATE payroll p
       SET p.accrual_transaction_id = NULL
     WHERE p.accrual_transaction_id IS NOT NULL
       AND NOT EXISTS (
           SELECT 1 FROM transactions t WHERE t.transaction_id = p.accrual_transaction_id
       )
")->execute();
$ghostsCleared = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
echo "Ghost accrual references cleared: {$ghostsCleared}\n";

// Fetch all approved/paid payrolls with no accrual posted.
// Cap at 100M gross to skip obvious test/erroneous records.
$stmt = $pdo->prepare("
    SELECT payroll_id, payroll_number, gross_salary, tax_amount, nssf_employee
      FROM payroll
     WHERE accrual_transaction_id IS NULL
       AND payment_status IN ('approved','paid','partial')
       AND gross_salary > 0
       AND gross_salary <= 100000000
     ORDER BY payroll_id
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Payrolls to backfill: " . count($rows) . "\n";

$posted  = 0;
$skipped = 0;
$failed  = [];

foreach ($rows as $r) {
    try {
        $txn = ensurePayrollAccrued($pdo, (int)$r['payroll_id'], $adminId);
        if ($txn) {
            $posted++;
            echo "  ✓ {$r['payroll_number']} (id={$r['payroll_id']}) → txn {$txn}\n";
        } else {
            $skipped++;
            echo "  - {$r['payroll_number']} (id={$r['payroll_id']}) skipped (amount=0 or accounts not mapped)\n";
        }
    } catch (Throwable $e) {
        $failed[] = $r['payroll_number'] . ': ' . $e->getMessage();
        echo "  ✗ {$r['payroll_number']}: " . $e->getMessage() . "\n";
    }
}

echo "\nSummary: {$posted} accruals posted, {$skipped} skipped, " . count($failed) . " failed.\n";
if (!empty($failed)) {
    echo "Failed:\n";
    foreach ($failed as $f) echo "  - {$f}\n";
}
