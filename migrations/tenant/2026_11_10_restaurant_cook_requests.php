<?php
/**
 * migrations/tenant/2026_11_10_restaurant_cook_requests.php
 *
 * Restaurant costing plan, Phase 4 — "the cook asks, the storekeeper gives": restaurant_cook_plan_items gets
 * status ENUM('issued','requested','rejected') DEFAULT 'issued', requested_by, decided_by, decided_at.
 * Existing lines keep 'issued' (they left stock when saved). Logic in core/restaurant_costing_schema.php.
 * Idempotent (each column checked first).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_costing_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant cook stock requests...\n";

try {
    rcsEnsureCookRequests($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
