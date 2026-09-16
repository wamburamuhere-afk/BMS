<?php
/**
 * migrations/tenant/2026_09_16_pos_registers_warehouse_id.php
 *
 * pos_registers has no warehouse/shop dimension at all (get_registers.php:2,
 * save_register.php:2 both documented it as "a small global lookup table, no
 * project/warehouse scope"). That means Open Shift lets ANY cashier with POS
 * create permission sign in at ANY till, regardless of which shop(s) they were
 * actually granted via Settings > Admin > Project & Warehouse Access
 * (user_scope_overrides, resource_type='warehouse') — the shop-access control
 * the tenant already configured is simply never consulted at the till.
 *
 * Purely additive, nullable column + a supporting index, same convention as
 * 2026_09_11_journal_entries_warehouse_id.php. NULL means "unscoped / legacy
 * register" — every existing register (including the seeded 'Main Counter')
 * stays reachable by everyone exactly as today until an admin explicitly
 * assigns it a shop via the Registers screen.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: add pos_registers.warehouse_id...\n";

try {
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
