<?php
/**
 * migrations/2026_09_15_transactions_type_stock_restock_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_09_15_transactions_type_stock_restock.php onto
 * the LEGACY / non-tenant database — see 2026_09_08_pos_product_batches_legacy_db.php
 * for why this pairing exists (core/tenant_migration_runner.php never reaches
 * a host's own legacy database, only rows registered in the `tenants` control
 * table).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: add 'stock_restock' to transactions.transaction_type ENUM on the legacy database...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { echo "  ! transactions.transaction_type not found — skipping.\n"; echo "Migration complete.\n"; exit(0); }

    $type = $col['Type'];
    if (!preg_match("/^enum\((.*)\)$/i", $type, $m)) {
        echo "  ! column is not an ENUM ({$type}) — nothing to do.\n"; echo "Migration complete.\n"; exit(0);
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vm);
    $existing = array_map(fn($v) => str_replace("''", "'", $v), $vm[1]);

    if (in_array('stock_restock', $existing, true)) {
        echo "  · 'stock_restock' already present in the ENUM — no enum change.\n";
    } else {
        $all = array_merge($existing, ['stock_restock']);
        $quoted = implode(',', array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $all));
        $notNull = ($col['Null'] === 'NO') ? ' NOT NULL' : ' NULL';
        $pdo->exec("ALTER TABLE transactions MODIFY COLUMN transaction_type ENUM($quoted){$notNull}");
        echo "  + added 'stock_restock' — transaction_type now has " . count($all) . " values.\n";
    }

    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
