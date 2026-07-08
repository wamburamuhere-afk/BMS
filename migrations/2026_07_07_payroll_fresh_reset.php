<?php
/**
 * 2026_07_07_payroll_fresh_reset.php  —  ONE-TIME, TARGETED payroll reset
 * ======================================================================
 * Resets the PAYROLL side to zero so the named companies start fresh. It deletes
 * every any-status journal ENTRY that touches a payroll-DEDICATED account (whole
 * entry: header + all legs), which cleans accrual, payment, SDL, AND the statutory
 * remittance — the remittance mirrors to journal_entries as the generic entity_type
 * 'books_transaction', so matching by ACCOUNT (not entity_type) is the only reliable
 * way to catch it. Also empties the payroll operational tables and drops empty
 * 2-1440-EMP-* sub-accounts.
 *
 * Payroll-dedicated accounts (resolved from System Settings + standard codes):
 *   6-2410 Salaries/Wages Expense, 6-2430 SDL Expense, NSSF-EXP NSSF Employer Exp,
 *   2-1410 PAYE Payable, 2-1420 NSSF Payable, 2-1430 SDL Payable, 2-1440 Salaries
 *   Payable, and every 2-1440-EMP-* employee sub-account.
 * Shared accounts (Trade Creditors 2-1200, Bank) are NOT wiped — only the payroll
 * entry's own leg on them is removed with the whole balanced entry, so the ledger
 * stays balanced and supplier/bank history is preserved.
 *
 * SCOPED BY DATABASE ($TARGET_DBS) — any other DB is skipped (fail-safe). Being a
 * migration the runner records it and never runs it again.
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

$OP_TABLES  = ['payroll', 'payroll_items', 'payslip_history', 'statutory_remittances', 'payroll_audit_log'];
$LEGACY_TXN = ['statutory_remittance', 'payroll', 'payroll_accrual', 'payroll_payment', 'sdl_accrual'];

$tableExists = fn(string $t): bool => (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
$colExists   = fn(string $t, string $c): bool =>
    $tableExists($t) && (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE " . $pdo->quote($c))->fetch();

try {
    // ── resolve the payroll-dedicated account ids (settings + standard codes + EMP subs)
    $acctIds = [];
    $settingKeys = ['default_salaries_expense_account_id', 'default_nssf_employer_expense_account_id',
                    'default_paye_payable_account_id', 'default_nssf_payable_account_id',
                    'default_sdl_payable_account_id', 'default_salaries_payable_account_id',
                    'default_sdl_expense_account_id'];
    foreach ($settingKeys as $k) {
        $v = (int)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key=" . $pdo->quote($k))->fetchColumn() ?: 0);
        if ($v) $acctIds[$v] = true;
    }
    foreach ($pdo->query("SELECT account_id FROM accounts
                          WHERE account_code IN ('2-1410','2-1420','2-1430','2-1440','6-2410','6-2430','NSSF-EXP')
                             OR account_code LIKE '2-1440-EMP-%'")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        $acctIds[(int)$aid] = true;
    }
    if (!$acctIds) { echo "  ! no payroll accounts resolved — nothing to do.\n"; exit(0); }
    $idList = implode(',', array_map('intval', array_keys($acctIds)));

    // ── every journal entry that touches ANY payroll-dedicated account
    $entryIds = $pdo->query("SELECT DISTINCT entry_id FROM journal_entry_items WHERE account_id IN ($idList)")
                    ->fetchAll(PDO::FETCH_COLUMN);
    echo "  payroll-dedicated accounts: " . count($acctIds) . "; journal entries to remove: " . count($entryIds) . "\n";

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

    // 3. delete the payroll journal entries — WHOLE entries (lines then headers)
    $n1 = 0; $n2 = 0;
    if ($entryIds) {
        $in = implode(',', array_map('intval', $entryIds));
        $n1 = $pdo->exec("DELETE FROM journal_entry_items WHERE entry_id IN ($in)");
        $n2 = $pdo->exec("DELETE FROM journal_entries      WHERE entry_id IN ($in)");
    }
    echo "  deleted $n1 GL lines, $n2 GL headers\n";

    // 4. tidy legacy transactions mirror rows (not read by reports, kept clean)
    if ($tableExists('transactions')) {
        $q = "'" . implode("','", $LEGACY_TXN) . "'";
        $n3 = $pdo->exec("DELETE FROM transactions WHERE transaction_type IN ($q)");
        echo "  deleted $n3 legacy transactions rows\n";
    }

    // 5. drop now-empty employee GL sub-accounts + clear the link
    if ($colExists('employees', 'ledger_account_id')) $pdo->exec("UPDATE employees SET ledger_account_id = NULL");
    $dropped = 0;
    foreach ($pdo->query("SELECT account_id FROM accounts WHERE account_code LIKE '2-1440-EMP-%'")->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        $used = (int)$pdo->query("SELECT COUNT(*) FROM journal_entry_items WHERE account_id = " . (int)$aid)->fetchColumn();
        if ($used === 0) { $pdo->prepare("DELETE FROM accounts WHERE account_id = ?")->execute([$aid]); $dropped++; }
    }
    echo "  dropped $dropped empty employee sub-account(s)\n";

    $pdo->commit();

    // 6. verify: the 7 dedicated accounts are zero + ledger balanced
    $chk = $pdo->query("SELECT a.account_code,
                               COALESCE(SUM(CASE WHEN jei.type='credit' THEN jei.amount ELSE -jei.amount END),0) AS bal
                          FROM accounts a
                          LEFT JOIN journal_entry_items jei ON jei.account_id = a.account_id
                          LEFT JOIN journal_entries je ON je.entry_id = jei.entry_id AND je.status='posted'
                         WHERE a.account_code IN ('2-1410','2-1420','2-1430','2-1440','6-2410','6-2430','NSSF-EXP')
                         GROUP BY a.account_id ORDER BY a.account_code")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($chk as $r) echo sprintf("    %-10s %15.2f\n", $r['account_code'], $r['bal']);
    $bal = assertLedgerBalanced($pdo, date('Y-m-d'));
    echo "  Ledger balanced after reset: " . (!empty($bal['ledger_balanced']) ? 'YES' : 'NO') . "\n";
    echo "Migration complete. Payroll side reset for '$db'.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
