<?php
/**
 * 2026_07_07_payroll_fresh_reset.php  —  ONE-TIME, TARGETED payroll reset
 * ======================================================================
 * Resets the PAYROLL side to zero so the named companies start fresh:
 *   - deletes GL journal entries whose entity_type is a payroll / sdl type
 *     (accrual, payment, and every reversal void) + their lines
 *   • empties payroll operational tables
 *   • clears employee → entry links and drops empty 2-1440-EMP-* sub-accounts
 * It does NOT touch invoices, bills, expenses, banks, employees themselves, or
 * any non-payroll GL — and the ledger stays balanced (proved on local).
 *
 * SCOPED BY DATABASE. It only runs on the exact database names in $TARGET_DBS;
 * on every other tenant it prints "skipped" and does nothing (fail-safe). Being a
 * migration, the runner records it and NEVER runs it again.
 *
 * >>> FILL $TARGET_DBS WITH THE EXACT DATABASE NAMES BEFORE DEPLOY <<<
 */

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/financial_reports.php';   // assertLedgerBalanced
global $pdo;

/* EXACT database names to reset. Anything not listed here is skipped (fail-safe). */
$TARGET_DBS = [
    'bejundas_bms_bejus',
    'bejundas_bms_bjp',
    'bejundas_main',
    'bjptechn_main',
    'bjptechn_mwpt',
];

echo "Starting migration: targeted payroll fresh reset...\n";

$db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
if (!in_array($db, $TARGET_DBS, true)) {
    echo "  · DB '$db' is not a reset target — skipped.\n";
    exit(0);
}
echo "  DB '$db' IS a reset target — proceeding.\n";

$COND    = "(entity_type LIKE 'payroll%' OR entity_type LIKE 'sdl%')";
$COND_JE = "(je.entity_type LIKE 'payroll%' OR je.entity_type LIKE 'sdl%')";
$OP_TABLES = ['payroll', 'payroll_items', 'payslip_history', 'statutory_remittances', 'payroll_audit_log'];

$tableExists = fn(string $t): bool => (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
$colExists   = fn(string $t, string $c): bool =>
    $tableExists($t) && (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch();

try {
    $je = (int)$pdo->query("SELECT COUNT(*) FROM journal_entries WHERE $COND")->fetchColumn();
    echo "  payroll/SDL GL entries to remove: $je\n";

    $pdo->beginTransaction();

    // 1. break employee → entry links so header deletes can't FK-block
    $sets = [];
    if ($colExists('employees', 'accrual_transaction_id')) $sets[] = 'accrual_transaction_id = NULL';
    if ($colExists('employees', 'payment_transaction_id')) $sets[] = 'payment_transaction_id = NULL';
    if ($sets) $pdo->exec("UPDATE employees SET " . implode(', ', $sets));

    // 2. empty payroll operational tables
    foreach ($OP_TABLES as $t) {
        if ($tableExists($t)) { $pdo->exec("DELETE FROM `$t`"); echo "  cleared $t\n"; }
    }

    // 3. delete payroll/SDL GL — lines first, then headers (every *_void variant too)
    $n1 = $pdo->exec("DELETE jei FROM journal_entry_items jei
                      JOIN journal_entries je ON je.entry_id = jei.entry_id
                      WHERE $COND_JE");
    $n2 = $pdo->exec("DELETE FROM journal_entries WHERE $COND");
    echo "  deleted $n1 GL lines, $n2 GL headers\n";

    // 4. drop empty employee GL sub-accounts + clear the link
    if ($colExists('employees', 'ledger_account_id')) $pdo->exec("UPDATE employees SET ledger_account_id = NULL");
    $dropped = 0;
    foreach ($pdo->query("SELECT account_id FROM accounts WHERE account_code LIKE '2-1440-EMP-%'")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        $used = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE account_id = " . (int)$aid)->fetchColumn();
        if ($used === 0) { $pdo->prepare("DELETE FROM accounts WHERE account_id = ?")->execute([$aid]); $dropped++; }
    }
    echo "  dropped $dropped empty employee sub-account(s)\n";

    $pdo->commit();

    $bal = assertLedgerBalanced($pdo, date('Y-m-d'));
    echo "  Ledger balanced after reset: " . (!empty($bal['ledger_balanced']) ? 'YES' : 'NO') . "\n";
    echo "Migration complete. Payroll side reset for '$db'.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
