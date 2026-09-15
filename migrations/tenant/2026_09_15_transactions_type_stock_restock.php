<?php
/**
 * migrations/tenant/2026_09_15_transactions_type_stock_restock.php
 *
 * Adds 'stock_restock' to transactions.transaction_type so
 * postOutflow($pdo, 'stock_restock', ...) (api/pos/quick_restock.php's
 * "already paid" cash outflow) can post. Reproduced live: MySQL strict mode
 * rejected the INSERT with "Data truncated for column 'transaction_type'"
 * because that value was never added to the ENUM when Restock Product shipped.
 *
 * Read-then-append, never a hardcoded literal list — see
 * 2026_07_22_transactions_type_trip.php's docblock for why a hardcoded MODIFY
 * previously broke production (it silently dropped values a hardcoded
 * snapshot didn't know about).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: add 'stock_restock' to transactions.transaction_type ENUM...\n";

try {
    $col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) { echo "  ! transactions.transaction_type not found — skipping.\n"; echo "Migration complete.\n"; exit(0); }

    $type = $col['Type'];   // e.g. enum('a','b',...)
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
