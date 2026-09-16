<?php
/**
 * migrations/2026_09_16_cash_register_shifts_warehouse_id_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_16_cash_register_shifts_warehouse_id.php
 * onto the LEGACY / non-tenant database. See
 * 2026_09_08_pos_network_printer_legacy_db.php for why this pairing exists.
 *
 * Degrades instead of failing — see the sibling
 * 2026_09_16_pos_registers_warehouse_id_legacy_db.php for the same reasoning.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add cash_register_shifts.warehouse_id on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'cash_register_shifts'")->fetch();
    if (!$table) {
        echo "  · cash_register_shifts does not exist on this database — nothing to add. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $has = $pdo->query("SHOW COLUMNS FROM cash_register_shifts LIKE 'warehouse_id'")->fetch();
    if ($has) {
        echo "  cash_register_shifts.warehouse_id already exists — skipping column add.\n";
    } else {
        $pdo->exec("ALTER TABLE cash_register_shifts ADD COLUMN warehouse_id INT NULL AFTER register_id");
        echo "  + Added cash_register_shifts.warehouse_id (INT NULL).\n";
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM cash_register_shifts WHERE Key_name = 'ix_shifts_warehouse'")->fetch();
    if ($hasIdx) {
        echo "  Index ix_shifts_warehouse already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE cash_register_shifts ADD INDEX ix_shifts_warehouse (warehouse_id)");
        echo "  + Added index ix_shifts_warehouse.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
