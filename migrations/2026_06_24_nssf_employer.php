<?php
/**
 * 2026_06_24_nssf_employer.php
 * ----------------------------
 * Add NSSF employer-side (10%) tracking to payroll.
 * Tanzania NSSF total = 20% of gross: employee 10% + employer 10%.
 * BMS previously only tracked the employee half; this migration adds the
 * employer half without touching any existing data or account mappings.
 *
 * Additive & idempotent. No DDL transactions.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: NSSF employer contribution (10%)...\n";

try {
    // ── 1. payroll.nssf_employer column ─────────────────────────────────────
    if (!$pdo->query("SHOW COLUMNS FROM payroll LIKE 'nssf_employer'")->fetch()) {
        $pdo->exec("ALTER TABLE payroll ADD COLUMN nssf_employer DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER nssf_employee");
        echo "  + payroll.nssf_employer added (DEFAULT 0.00 — existing rows untouched).\n";
    } else {
        echo "  · payroll.nssf_employer already present.\n";
    }

    // ── 2. payroll_settings: nssf_employer_rate ─────────────────────────────
    $pdo->prepare("INSERT IGNORE INTO payroll_settings (setting_key, setting_value, category, description, updated_at)
                   VALUES ('nssf_employer_rate', '10', 'statutory',
                           'NSSF employer contribution (% of gross; employer cost, not deducted from staff)',
                           NOW())")
        ->execute();
    echo "  + payroll_setting 'nssf_employer_rate' ensured (= 10).\n";

    // ── 3. NSSF Employer Expense account + system_settings mapping ───────────
    $expTypeId = $pdo->query("SELECT type_id FROM account_types WHERE category = 'expense' LIMIT 1")->fetchColumn();
    $expTypeId = ($expTypeId !== false) ? (int)$expTypeId : null;

    $existingId = $pdo->query(
        "SELECT account_id FROM accounts WHERE account_name = 'NSSF Employer Expense' LIMIT 1"
    )->fetchColumn();

    if ($existingId) {
        $id = (int)$existingId;
        echo "  · account 'NSSF Employer Expense' already exists, id = {$id}.\n";
    } else {
        $pdo->prepare("INSERT INTO accounts
                           (account_code, account_name, account_type, account_type_id,
                            cash_flow_category, opening_balance, current_balance, status, created_at)
                       VALUES ('NSSF-EXP', 'NSSF Employer Expense', 'expense', ?,
                               'operating', 0, 0, 'active', NOW())")
            ->execute([$expTypeId]);
        $id = (int)$pdo->lastInsertId();
        echo "  + account 'NSSF Employer Expense' created, id = {$id}.\n";
    }

    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at)
                   VALUES ('default_nssf_employer_expense_account_id', ?, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
        ->execute([(string)$id]);
    echo "  + setting default_nssf_employer_expense_account_id = {$id}.\n";

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
