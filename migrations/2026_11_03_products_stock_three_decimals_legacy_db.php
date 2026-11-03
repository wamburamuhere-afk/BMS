<?php
/**
 * migrations/2026_11_03_products_stock_three_decimals_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_03_products_stock_three_decimals.php onto the
 * LEGACY / non-tenant database: products.stock_quantity and products.current_stock get
 * 3 decimal places (DECIMAL(15,3)) so a POS sale of 0.125 kg is kept exactly.
 * Only widens, keeps NULL/DEFAULT. Idempotent; degrades if absent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: products stock three decimals (legacy database)...\n";

try {
    if (!$pdo->query("SHOW TABLES LIKE 'products'")->fetch()) {
        echo "  · products does not exist on this database — nothing to do. Skipping.\n";
        echo "Migration complete.\n";
        exit(0);
    }
    $cols = [
        'stock_quantity' => "DECIMAL(15,3) NOT NULL DEFAULT 0.000",
        'current_stock'  => "DECIMAL(15,3) NULL DEFAULT 0.000",
    ];
    foreach ($cols as $name => $def) {
        $col = $pdo->query("SHOW COLUMNS FROM products LIKE " . $pdo->quote($name))->fetch(PDO::FETCH_ASSOC);
        if (!$col) { echo "  · products.$name does not exist — skipped.\n"; continue; }
        if (preg_match('/decimal\(\d+,\s*3\)/i', (string)$col['Type'])) { echo "  · products.$name already has 3 decimals.\n"; continue; }
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
