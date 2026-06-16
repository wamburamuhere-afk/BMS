<?php
/**
 * 2026_06_14_accrued_expenses_account.php
 * ---------------------------------------
 * money.md OUT-1 enabler. Ensures a dedicated "Accrued Expenses" current-liability
 * account (code 2-1500) so an APPROVED-but-unpaid expense can be recognised on an
 * accrual basis (Dr Expense / Cr Accrued Expenses), separate from Trade Creditors
 * (2-1200, used by GRN / supplier invoices). The payment later clears it
 * (Dr Accrued Expenses / Cr Bank).
 *
 * Idempotent, additive, deploy-safe: one standard account, only when absent.
 * Cloned from an existing current-liability row so every classification / NOT-NULL
 * column matches the live schema.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: accrued expenses account...\n";

try {
    $exists = (int)($pdo->query("SELECT account_id FROM accounts WHERE account_code='2-1500' LIMIT 1")->fetchColumn() ?: 0);
    if ($exists) {
        echo "  ~ Accrued Expenses (2-1500) already exists (#$exists).\n\nMigration complete.\n";
        exit(0);
    }

    // Clone a current-liability template (Other Current Liabilities, else Trade
    // Creditors) so parent/sub-type/classification columns are correct.
    $tmpl = $pdo->query("SELECT * FROM accounts WHERE account_code IN ('2-1700','2-1200') ORDER BY account_code DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$tmpl) {
        echo "  ! No current-liability template account found — Accrued Expenses NOT created.\n";
        exit(1);
    }

    unset($tmpl['account_id']);
    $tmpl['account_code'] = '2-1500';
    $tmpl['account_name'] = 'Accrued Expenses';
    $tmpl['status']       = 'active';
    if (array_key_exists('description', $tmpl))     $tmpl['description'] = 'Expenses incurred/approved but not yet paid';
    if (array_key_exists('current_balance', $tmpl)) $tmpl['current_balance'] = 0;
    if (array_key_exists('opening_balance', $tmpl)) $tmpl['opening_balance'] = 0;
    if (array_key_exists('created_at', $tmpl))      $tmpl['created_at'] = date('Y-m-d H:i:s');
    if (array_key_exists('updated_at', $tmpl))      $tmpl['updated_at'] = date('Y-m-d H:i:s');

    $cols = array_keys($tmpl);
    $ph   = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO accounts (`" . implode('`,`', $cols) . "`) VALUES ($ph)")->execute(array_values($tmpl));
    echo "  + Accrued Expenses (2-1500) created (#" . (int)$pdo->lastInsertId() . ").\n\nMigration complete.\n";
} catch (Throwable $e) {
    echo "  ! Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
