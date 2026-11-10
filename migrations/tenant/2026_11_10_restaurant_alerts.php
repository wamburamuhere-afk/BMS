<?php
/**
 * migrations/tenant/2026_11_10_restaurant_alerts.php
 *
 * Restaurant alerts pass: notifications.entity_type / entity_id (so a handled alert is cleared for everyone)
 * and "Ready to serve" to the waiter who took the order. All logic in core/restaurant_alerts_schema.php
 * (dependency-free). Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_alerts_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant alerts...\n";

try {
    restaurantAlertsEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
