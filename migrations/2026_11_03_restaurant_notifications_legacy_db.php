<?php
/**
 * migrations/2026_11_03_restaurant_notifications_legacy_db.php
 *
 * Mirrors migrations/tenant/2026_11_03_restaurant_notifications.php onto the LEGACY /
 * non-tenant database. Same body (core/restaurant_notify_schema.php). Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
require_once __DIR__ . '/../core/restaurant_notify_schema.php';
global $pdo;

echo "Starting migration: restaurant notifications (legacy database)...\n";
try {
    restaurantNotifyEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
