<?php
/**
 * migrations/tenant/2026_11_10_restaurant_cook_unsold.php
 *
 * Restaurant costing plan, Phase 5 — pot cooking: table restaurant_cook_unsold (the ingredients of plates that did
 * not sell leave stock as waste when the day's cooks of a dish are closed), and two in-app notification events
 * (a cook's stock request → whoever may give stock out; the answer → the cook). Logic in
 * core/restaurant_costing_schema.php. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_costing_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant cook unsold plates + stock request notifications...\n";

try {
    rcsEnsureCookUnsold($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
