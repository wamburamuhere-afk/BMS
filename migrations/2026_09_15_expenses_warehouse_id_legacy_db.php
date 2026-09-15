<?php
/**
 * migrations/2026_09_15_expenses_warehouse_id_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_15_expenses_warehouse_id.php onto the
 * LEGACY / non-tenant database. See 2026_09_08_pos_network_printer_legacy_db.php
 * for why this pairing exists.
 *
 * Degrades instead of failing: a failure here (exit 1) is `script_stop: true`
 * on the deploy script, halting the release for EVERY host. If `expenses`
 * doesn't exist at all on this database, that's a separate, pre-existing gap
 * outside this migration's job to fix.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: expenses.warehouse_id on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'expenses'")->fetch();
    if (!$table) {
        echo "  · expenses does not exist on this database — nothing to add a column to. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $exists = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'warehouse_id'")->fetch();
    if ($exists) {
        echo "  · expenses.warehouse_id already present.\n";
    } else {
        $anchor = $pdo->query("SHOW COLUMNS FROM expenses LIKE 'project_id'")->fetch();
        $position = $anchor ? " AFTER project_id" : "";
        $pdo->exec("ALTER TABLE expenses ADD COLUMN warehouse_id INT DEFAULT NULL$position");
        echo "  + expenses.warehouse_id added" . ($position ? "" : " (anchor column 'project_id' not found — appended at end instead)") . ".\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
