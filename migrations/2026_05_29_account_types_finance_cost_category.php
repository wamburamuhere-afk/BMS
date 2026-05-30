<?php
/**
 * 2026_05_29_account_types_finance_cost_category.php
 * ---------------------------------------------------
 * Adds 'finance_cost' to account_types.category ENUM so that accounts
 * like "Bank Charges", "Interest Expense", "Exchange Loss" can be
 * classified as finance costs and appear in the Finance Costs section
 * of the Income Statement (below EBIT, as required by IAS 1).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: account_types — add finance_cost category...\n";

try {
    $row = $pdo->query("SHOW COLUMNS FROM `account_types` LIKE 'category'")->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo "  ! account_types.category column not found — skipping.\n";
        exit(0);
    }

    if (stripos($row['Type'], 'finance_cost') !== false) {
        echo "  · finance_cost already in ENUM — skipping.\n";
    } else {
        $newType = preg_replace_callback(
            "/enum\\((.*)\\)/i",
            fn($m) => "enum(" . $m[1] . ",'finance_cost')",
            $row['Type']
        );
        $null    = ($row['Null'] === 'YES') ? 'NULL' : 'NOT NULL';
        $default = $row['Default'] !== null ? " DEFAULT " . $pdo->quote($row['Default']) : '';
        $pdo->exec("ALTER TABLE `account_types` MODIFY COLUMN `category` {$newType} {$null}{$default}");
        echo "  + added finance_cost to account_types.category ENUM.\n";
    }

    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
