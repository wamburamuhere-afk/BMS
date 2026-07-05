<?php
/**
 * 2026_07_04_enable_project_expense_types.php
 * -------------------------------------------
 * Make the Administrative / Fixed / Operating expense types selectable in the
 * project Expense form by turning on their `show_project` flag.
 *
 * Criteria-based (matched by NAME, never by hardcoded id) and idempotent — safe to
 * re-run. Works together with the code change that makes the project Expense Type
 * dropdown honour `expense_types.show_project` instead of a hardcoded name list.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Enabling project visibility for Administrative / Fixed / Operating expense types...\n";

try {
    $stmt = $pdo->prepare("
        UPDATE expense_types
        SET show_project = 1
        WHERE status = 'active'
          AND LOWER(TRIM(name)) IN ('administrative', 'fixed', 'operating')
          AND show_project <> 1
    ");
    $stmt->execute();
    echo "  Rows updated: " . $stmt->rowCount() . "\n";
    echo "Done.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
