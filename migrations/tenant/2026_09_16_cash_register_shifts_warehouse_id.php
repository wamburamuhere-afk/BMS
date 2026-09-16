<?php
/**
 * migrations/tenant/2026_09_16_cash_register_shifts_warehouse_id.php
 *
 * Companion to 2026_09_16_pos_registers_warehouse_id.php. The shift itself
 * needs its own warehouse_id, stamped at Open Shift time from the register's
 * shop — mirrors how pos_sales already denormalises register_id/register_name
 * onto the sale rather than deriving it later via a join (see the comment in
 * api/pos/process_sale.php), so a shift's shop stays a fixed fact of that
 * shift even if the register's own assignment is changed afterwards.
 *
 * Purely additive, nullable column + index. NULL for every existing shift and
 * for any shift opened on a still-unassigned ("legacy") register.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: add cash_register_shifts.warehouse_id...\n";

try {
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
