<?php
/**
 * 2026_06_11_heal_bank_cash_flow_marker.php
 * -----------------------------------------
 * Aligns the legacy cash-flow tag with the account CLASSIFICATION so the two can
 * never disagree. Any account classified as Bank/Cash (Sub Type with is_bank = 1)
 * that is missing cash_flow_category = 'cash' gets it set.
 *
 * Why: bank statement, payment "Paid From" lists and some reports historically
 * keyed off cash_flow_category = 'cash'. The bank surfaces now follow the Sub Type
 * (is_bank) classification directly, but this heal keeps the derived tag consistent
 * for every OTHER reader too — so a Sub Type = Bank account behaves as a bank
 * everywhere, regardless of how it was created.
 *
 * Idempotent + criteria-based; safe to re-run. No hard-coded ids.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: heal bank/cash cash_flow marker from Sub Type classification...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'account_sub_types'")->fetch()) {
        echo "  ~ account_sub_types absent — nothing to heal.\n\nMigration complete.\n";
        exit(0);
    }

    $stmt = $pdo->prepare("
        UPDATE accounts a
          JOIN account_sub_types st ON a.sub_type_id = st.sub_type_id
           SET a.cash_flow_category = 'cash'
         WHERE st.is_bank = 1
           AND (a.cash_flow_category IS NULL OR a.cash_flow_category <> 'cash')
    ");
    $stmt->execute();
    echo "  + Set cash_flow_category='cash' on {$stmt->rowCount()} Bank/Cash-classified account(s) that were missing it.\n";

    echo "\nMigration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
