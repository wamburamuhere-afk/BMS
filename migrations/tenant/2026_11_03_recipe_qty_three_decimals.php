<?php
/**
 * migrations/tenant/2026_11_03_recipe_qty_three_decimals.php
 *
 * A recipe line's amount kept only 2 decimal places, so small ingredients were wrong:
 * 5 g of salt in a recipe counted in kg (0.005) was saved as 0.01 — double — and 3 g as 0,
 * so stock and the cost of a plate were off. Same 3 places as product_stocks / POS sales:
 *   product_assembly_components.qty_per_unit  DECIMAL(15,2) NOT NULL DEFAULT 1  →  DECIMAL(15,3)
 *   product_assembly_components.total_qty     DECIMAL(15,2) NULL DEFAULT 0      →  DECIMAL(15,3)
 * Only widens (no value is cut), keeps each column's NULL/DEFAULT. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: recipe amounts three decimals...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'product_assembly_components'")->fetch()) {
        echo "  ~ product_assembly_components table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }
    foreach (['qty_per_unit' => '1.000', 'total_qty' => '0.000'] as $name => $default) {
        $col = $pdo->query("SHOW COLUMNS FROM product_assembly_components LIKE " . $pdo->quote($name))->fetch(PDO::FETCH_ASSOC);
        if (!$col) { echo "  ~ product_assembly_components.$name absent — skipped.\n"; continue; }
        if (preg_match('/decimal\(\d+,\s*3\)/i', (string)$col['Type'])) { echo "  ~ product_assembly_components.$name already has 3 decimals.\n"; continue; }
        $null = strtoupper((string)$col['Null']) === 'NO' ? 'NOT NULL' : 'NULL';
        $def  = "DECIMAL(15,3) $null DEFAULT $default";
        $pdo->exec("ALTER TABLE product_assembly_components MODIFY COLUMN `$name` $def");
        echo "  + product_assembly_components.$name is now $def.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
