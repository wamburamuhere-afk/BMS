<?php
/**
 * Fix stock_movements.movement_type ENUM on production
 * -----------------------------------------------------
 * The production cPanel server has an older ENUM definition for
 * stock_movements.movement_type that is missing several values added
 * during local development (notably 'adjustment_in', 'adjustment_out',
 * 'transfer_in', 'transfer_out', 'return_in', 'return_out',
 * 'production_in', 'production_out', 'damaged', 'expired', 'found',
 * 'theft', 'correction', 'issue_out').
 *
 * Symptom: creating a product with initial stock fails with:
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column
 *   'movement_type' at row 1
 *
 * Fix: MODIFY the column to the full canonical ENUM list that matches
 * local development and the test suite in
 * tests/test_stock_movements_enum_safety_cli.php.
 *
 * Idempotent: reads the current ENUM definition first; skips the ALTER
 * if the column already contains all required values.
 */

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: stock_movements.movement_type ENUM fix...\n";

$required = [
    'purchase_in', 'sale_out', 'adjustment_in', 'adjustment_out',
    'transfer_in', 'transfer_out', 'return_in', 'return_out',
    'production_in', 'production_out', 'damaged', 'expired',
    'found', 'theft', 'correction', 'issue_out',
];

try {
    // Read the current ENUM values from information_schema
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'stock_movements'
          AND COLUMN_NAME  = 'movement_type'
    ");
    $stmt->execute();
    $current_type = $stmt->fetchColumn();

    if (!$current_type) {
        echo "  SKIP: stock_movements.movement_type column not found (table may not exist yet).\n";
        exit(0);
    }

    echo "  Current definition: $current_type\n";

    // Extract existing values from the ENUM string
    preg_match_all("/'([^']+)'/", $current_type, $matches);
    $existing = $matches[1] ?? [];

    $missing = array_diff($required, $existing);

    if (empty($missing)) {
        echo "  OK: All required ENUM values already present — no change needed.\n";
        exit(0);
    }

    echo "  Missing values: " . implode(', ', $missing) . "\n";
    echo "  Applying ALTER TABLE...\n";

    // Build the full ENUM with every required value
    $enum_list = "'" . implode("','", $required) . "'";

    $pdo->exec("
        ALTER TABLE stock_movements
        MODIFY COLUMN movement_type
        ENUM($enum_list) NOT NULL
    ");

    echo "  Done. movement_type now includes: " . implode(', ', $required) . "\n";
    echo "Migration complete.\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
