<?php
/**
 * 2026_06_05_payroll_accrual.php
 * ------------------------------
 * Phase 2 — accrual accounting for payroll. Moves recognition from PAYMENT to
 * APPROVAL so salary expense + statutory liabilities are on the books when payroll
 * is incurred, regardless of whether staff have been paid (Tanzania: PAYE & SDL
 * are owed to TRA whether or not wages were disbursed).
 *
 *   - "Salaries Payable" (liability) — net wages owed to staff until paid. Shows
 *     on the Balance Sheet for any approved-but-unpaid payroll.
 *   - default_salaries_payable_account_id — system_settings mapping.
 *   - payroll.accrual_transaction_id — links a payroll row to its accrual journal
 *     (idempotency + reversal on delete/edit).
 *
 * Additive & idempotent. No transactions around DDL.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: Payroll accrual (Salaries Payable)...\n";

try {
    // Salaries Payable control account + mapping.
    $liabTypeId = ($v = $pdo->query("SELECT type_id FROM account_types WHERE category = 'liability' LIMIT 1")->fetchColumn()) !== false ? (int)$v : null;

    $id = $pdo->query("SELECT account_id FROM accounts WHERE account_name = 'Salaries Payable' LIMIT 1")->fetchColumn();
    if ($id) {
        $pdo->prepare("UPDATE accounts SET account_type_id = COALESCE(account_type_id, ?) WHERE account_id = ?")
            ->execute([$liabTypeId, (int)$id]);
        $id = (int)$id;
        echo "  · account 'Salaries Payable' already exists, id = {$id}.\n";
    } else {
        $pdo->prepare("INSERT INTO accounts (account_code, account_name, account_type, account_type_id,
                          cash_flow_category, opening_balance, current_balance, status, created_at)
                       VALUES ('SAL-PAY', 'Salaries Payable', 'liability', ?, 'operating', 0, 0, 'active', NOW())")
            ->execute([$liabTypeId]);
        $id = (int)$pdo->lastInsertId();
        echo "  + account 'Salaries Payable' created, id = {$id}.\n";
    }
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                   VALUES ('default_salaries_payable_account_id', ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
        ->execute([(string)$id]);
    echo "  + setting default_salaries_payable_account_id = {$id}.\n";

    // Link a payroll row to its accrual journal.
    if (!$pdo->query("SHOW COLUMNS FROM payroll LIKE 'accrual_transaction_id'")->fetch()) {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN accrual_transaction_id INT NULL DEFAULT NULL");
        echo "  + payroll.accrual_transaction_id added.\n";
    } else {
        echo "  · payroll.accrual_transaction_id already present.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
