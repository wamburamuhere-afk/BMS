<?php
/**
 * migrations/2026_11_03_recipe_qty_three_decimals_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_03_recipe_qty_three_decimals.php onto the LEGACY /
 * non-tenant database: recipe amounts (product_assembly_components.qty_per_unit / total_qty)
 * get 3 decimal places, so 5 g of salt (0.005 kg) is kept exactly. Only widens, keeps
 * NULL/DEFAULT. Idempotent; degrades if absent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: recipe amounts three decimals (legacy database)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'product_assembly_components'")->fetch()) {
        echo "  · product_assembly_components does not exist on this database — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    foreach (['qty_per_unit' => '1.000', 'total_qty' => '0.000'] as $name => $default) {
        $col = $pdo->query("SHOW COLUMNS FROM product_assembly_components LIKE " . $pdo->quote($name))->fetch(PDO::FETCH_ASSOC);
        if (!$col) { echo "  · product_assembly_components.$name does not exist — skipped.\n"; continue; }
        if (preg_match('/decimal\(\d+,\s*3\)/i', (string)$col['Type'])) { echo "  · product_assembly_components.$name already has 3 decimals.\n"; continue; }
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
