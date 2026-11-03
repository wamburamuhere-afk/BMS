<?php
/**
 * migrations/tenant/2026_11_03_products_stock_three_decimals.php
 *
 * POS sells goods by measure in parts (0.5 kg, 0.25 kg, 0.125 kg). Every sale also
 * reduces the copy of the stock kept on the product itself (api/pos/process_sale.php),
 * but those two columns kept only 2 decimal places — 0.125 kg would be saved as 0.13
 * and the copy would slowly drift away from the real stock in product_stocks (3 places).
 *   products.stock_quantity  DECIMAL(15,2) NOT NULL DEFAULT 0  →  DECIMAL(15,3)
 *   products.current_stock   DECIMAL(10,2) NULL DEFAULT 0      →  DECIMAL(15,3)
 * Only widens (no value is cut), keeps each column's NULL/DEFAULT. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: products stock three decimals...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'products'")->fetch()) {
        echo "  ~ products table absent — nothing to do.\n\nMigration complete.\n";
        exit(0);
    }
    $cols = [
        'stock_quantity' => "DECIMAL(15,3) NOT NULL DEFAULT 0.000",
        'current_stock'  => "DECIMAL(15,3) NULL DEFAULT 0.000",
    ];
    foreach ($cols as $name => $def) {
        $col = $pdo->query("SHOW COLUMNS FROM products LIKE " . $pdo->quote($name))->fetch(PDO::FETCH_ASSOC);
        if (!$col) { echo "  ~ products.$name absent — skipped.\n"; continue; }
        if (preg_match('/decimal\(\d+,\s*3\)/i', (string)$col['Type'])) { echo "  ~ products.$name already has 3 decimals.\n"; continue; }
        if ($name === 'current_stock' && strtoupper((string)$col['Null']) === 'NO') {
            $def = "DECIMAL(15,3) NOT NULL DEFAULT 0.000";
        }
        $pdo->exec("ALTER TABLE products MODIFY COLUMN `$name` $def");
        echo "  + products.$name is now $def.\n";
    }
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
