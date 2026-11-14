<?php
/**
 * migrations/2026_11_14_pos_line_unit_cost_legacy_db.php
 *
 * pos_sale_items.unit_cost — the buying cost of a sale line, fixed the day it is sold (see
 * core/pos_line_cost.php). Without it a customer return was taken off Sales but not off Cost,
 * so the dashboard showed a loss that did not exist. Adds the column, then fills it for past
 * sales (criteria-based, NULL rows only). Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/pos_line_cost.php';
global $pdo;

echo "Starting migration: POS line unit cost (legacy database)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'pos_sale_items'")->fetch()) {
        echo "  ~ pos_sale_items not present — nothing to do.\n";
    } else {
        if ($pdo->query("SHOW COLUMNS FROM pos_sale_items LIKE 'unit_cost'")->fetch()) {
            echo "  ~ pos_sale_items.unit_cost already exists.\n";
        } else {
            $pdo->exec("ALTER TABLE pos_sale_items ADD COLUMN unit_cost DECIMAL(15,4) NULL DEFAULT NULL AFTER unit_price");
            echo "  + pos_sale_items.unit_cost added.\n";
        }
        if ($pdo->query("SHOW TABLES LIKE 'pos_sale_item_batches'")->fetch() && $pdo->query("SHOW TABLES LIKE 'product_batches'")->fetch()) {
            $n = posLineCostBackfill($pdo);
            echo "  + filled: {$n['batch']} from batches, {$n['returned']} returned lines, {$n['return_lines']} return lines.\n";
        } else {
            echo "  ~ no batch tables — nothing to fill.\n";
        }
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
