<?php
require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: void orphan payroll_accrual entries (test-data cleanup)...\n";

/*
 * An "orphan" is a POSTED journal_entries row with entity_type='payroll_accrual'
 * whose entity_id has NO matching row in `payroll`. These were created by payroll
 * test runs writing into the real ledger (future-dated 2031, fake payroll ids) and
 * inflate Wages & Salaries (P&L) + Salaries Payable / Trade Creditors (BS) in the
 * all-time / future-dated views. Each orphan is an internally-balanced entry, so
 * soft-voiding it (status='void') removes it from every canonical report
 * (which read status='posted' only) while preserving the audit trail, and keeps
 * the ledger balanced.
 *
 * Criteria-based (no hard-coded ids) + idempotent (only touches status='posted').
 */
try {
    $diffSql = "SELECT ROUND(SUM(CASE WHEN jei.type='debit' THEN jei.amount ELSE -jei.amount END),2)
                  FROM journal_entry_items jei
                  JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.status='posted'";
    $salSql  = "SELECT ROUND(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END),2)
                  FROM journal_entry_items jei
                  JOIN journal_entries je ON je.entry_id=jei.entry_id AND je.status='posted'
                 WHERE jei.account_id=464";

    $diffBefore = (float)$pdo->query($diffSql)->fetchColumn();
    $salBefore  = (float)$pdo->query($salSql)->fetchColumn();

    $ids = $pdo->query("
        SELECT je.entry_id
          FROM journal_entries je
          LEFT JOIN payroll p ON p.payroll_id = je.entity_id
         WHERE je.entity_type='payroll_accrual' AND je.status='posted' AND p.payroll_id IS NULL
    ")->fetchAll(PDO::FETCH_COLUMN);

    echo "Orphan payroll_accrual entries found: " . count($ids) . "\n";

    $n = 0;
    if ($ids) {
        $pdo->beginTransaction();
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare("UPDATE journal_entries SET status='void' WHERE entry_id IN ($ph) AND status='posted'");
        $upd->execute($ids);
        $n = $upd->rowCount();
        $pdo->commit();
        echo "Voided $n orphan payroll_accrual entries.\n";
    } else {
        echo "No orphan entries to void - already clean.\n";
    }

    $diffAfter = (float)$pdo->query($diffSql)->fetchColumn();
    $salAfter  = (float)$pdo->query($salSql)->fetchColumn();

    echo "Ledger Dr-Cr diff  before: " . number_format($diffBefore, 2) . "   after: " . number_format($diffAfter, 2) . "  (0 = balanced)\n";
    echo "Salaries Payable (464) posted, all dates  before: " . number_format($salBefore, 2) . "   after: " . number_format($salAfter, 2) . "\n";

    if (abs($diffAfter) > 0.005) {
        echo "Migration ABORTED logic check: ledger not balanced after cleanup.\n";
        exit(1);
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
