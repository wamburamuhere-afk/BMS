<?php
/**
 * 2026_07_01_payroll_items_employer_cost.php
 * -------------------------------------------
 * The 2026-06-24 NSSF-employer feature made api/process_payroll.php write an
 * 'employer_cost' breakdown line to payroll_items, but never added that value
 * to payroll_items.item_type's ENUM. Every payroll run with nssf_employer > 0
 * (i.e. every employee) hit MySQL warning 1265 "Data truncated for column
 * 'item_type'" and aborted. Adds the missing ENUM member.
 *
 * Additive & idempotent. No DDL transactions (MyISAM table).
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add 'employer_cost' to payroll_items.item_type...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM payroll_items LIKE 'item_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && strpos($col['Type'], "'employer_cost'") === false) {
        $pdo->exec("ALTER TABLE payroll_items
                    MODIFY COLUMN item_type
                    ENUM('allowance','deduction','bonus','advance','loan','other','employer_cost')
                    NOT NULL");
        echo "  + payroll_items.item_type: added 'employer_cost'.\n";
    } else {
        echo "  · payroll_items.item_type already includes 'employer_cost'.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
