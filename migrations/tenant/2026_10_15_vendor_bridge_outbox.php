<?php
/**
 * migrations/tenant/2026_10_15_vendor_bridge_outbox.php
 *
 * vendor_bridge_outbox — paid invoices queued for the BJP invoice → subscription
 * bridge (customer_subscription_plan.md Phase 4). Only BJP's own install ever
 * writes rows; elsewhere the table stays empty. Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/vendor_bridge.php';
global $pdo;

echo "Starting migration: vendor_bridge_outbox...\n";
try {
    vbEnsureOutbox($pdo);
    echo "Migration complete: vendor_bridge_outbox.\n";
} catch (PDOException $e) {
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
