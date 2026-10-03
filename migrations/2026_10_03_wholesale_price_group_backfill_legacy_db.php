<?php
/**
 * migrations/2026_10_03_wholesale_price_group_backfill_legacy_db.php
 * Legacy-DB mirror of migrations/tenant/2026_10_03_wholesale_price_group_backfill.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

if (!(bool)$pdo->query("SHOW TABLES LIKE 'products'")->fetch()) {
    echo "Legacy DB has no products table — skipping wholesale_price_group_backfill.\n";
    exit(0);
}

echo "Starting legacy migration: wholesale_price_group_backfill...\n";

try {
    require_once __DIR__ . '/../core/pos_price_groups.php';
    $n = backfillWholesaleGroupPrices($pdo);
    echo "Migration complete: wholesale_price_group_backfill (legacy, $n product(s) fixed).\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
