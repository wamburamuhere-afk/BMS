<?php
/**
 * migrations/2026_11_03_restaurant_online_menu_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_03_restaurant_online_menu.php onto the LEGACY /
 * non-tenant database. Same body (core/restaurant_online_schema.php). Idempotent;
 * skips cleanly when the restaurant module schema is absent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/restaurant_online_schema.php';
global $pdo;

echo "Starting migration: restaurant online menu (legacy database)...\n";

try {
    restaurantOnlineEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
