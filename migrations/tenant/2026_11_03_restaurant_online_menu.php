<?php
/**
 * migrations/tenant/2026_11_03_restaurant_online_menu.php
 *
 * Restaurant online menu & ordering (restaurant_online_menu_plan.md):
 *   warehouses.online_menu_token / online_menu_enabled / online_order_table / online_order_pickup
 *   restaurant_tables.qr_token
 *   restaurant_online_orders (customer order requests waiting for staff)
 * Additive, everything off by default, idempotent. Body: core/restaurant_online_schema.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_online_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant online menu...\n";

try {
    restaurantOnlineEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
