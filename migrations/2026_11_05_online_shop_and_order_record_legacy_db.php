<?php
/**
 * migrations/2026_11_05_online_shop_and_order_record_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_05_online_shop_and_order_record.php onto the LEGACY /
 * non-tenant database. Same body (core/online_shop_schema.php). Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/online_shop_schema.php';
global $pdo;

echo "Starting migration: online shop + online order record (legacy database)...\n";

try {
    onlineShopEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
