<?php
/**
 * migrations/2026_09_16_pos_registers_warehouse_id_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_16_pos_registers_warehouse_id.php onto the
 * LEGACY / non-tenant database. See 2026_09_08_pos_network_printer_legacy_db.php
 * for why this pairing exists.
 *
 * Degrades instead of failing: this file, unlike a tenant migration, runs as
 * part of migrations/runner.php — a failure here (exit 1) is `script_stop:
 * true` on the deploy script, halting the release for EVERY host. If
 * `pos_registers` doesn't exist at all on this database, that's a separate,
 * pre-existing gap outside this migration's job to fix — log it and exit 0
 * rather than blocking every other host's deploy over it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add pos_registers.warehouse_id on the legacy database...\n";

try {
    $table = $pdo->query("SHOW TABLES LIKE 'pos_registers'")->fetch();
    if (!$table) {
        echo "  · pos_registers does not exist on this database — nothing to add. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }

    $has = $pdo->query("SHOW COLUMNS FROM pos_registers LIKE 'warehouse_id'")->fetch();
    if ($has) {
        echo "  pos_registers.warehouse_id already exists — skipping column add.\n";
    } else {
        $pdo->exec("ALTER TABLE pos_registers ADD COLUMN warehouse_id INT NULL AFTER register_code");
        echo "  + Added pos_registers.warehouse_id (INT NULL).\n";
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM pos_registers WHERE Key_name = 'ix_pos_registers_warehouse'")->fetch();
    if ($hasIdx) {
        echo "  Index ix_pos_registers_warehouse already exists — skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE pos_registers ADD INDEX ix_pos_registers_warehouse (warehouse_id)");
        echo "  + Added index ix_pos_registers_warehouse.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
