<?php
/**
 * migrations/tenant/2026_11_05_online_shop_and_order_record.php
 *
 * Retail online shop (Smart POS) + stronger online-order record (retail_online_shop_plan.md).
 * Body: core/online_shop_schema.php. Additive, idempotent, every switch off by default.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/online_shop_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: online shop + online order record...\n";

try {
    onlineShopEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
