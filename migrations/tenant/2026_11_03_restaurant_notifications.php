<?php
/**
 * migrations/tenant/2026_11_03_restaurant_notifications.php
 *
 * Restaurant notifications: registers the 11 restaurant events (module "Restaurant")
 * and the default rule "All admins — in-app + email" for each (plus pos_credit.*),
 * so they appear on Settings → Notification rules where other staff are added.
 * Body: core/restaurant_notify_schema.php. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_notify_schema.php';
global $pdo;

echo "Starting tenant migration: restaurant notifications...\n";
try {
    restaurantNotifyEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
