<?php
/**
 * migrations/tenant/2026_11_06_restaurant_order_flow.php
 *
 * restaurant_order_flow_plan.md Phase 1 — Waiter → Cashier → Kitchen → Waiter → Bill.
 * Ticket statuses pending/cancelled + who/when columns, restaurant_order_cancellations,
 * permission rows restaurant_orders / kitchen_display with back-compat grants, two
 * notification events, stale-ticket clean-up, ready-made roles for restaurant companies.
 * All logic in core/restaurant_order_flow_schema.php (dependency-free). Idempotent.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../core/tenant_migration_bootstrap.php';
require_once __DIR__ . '/../../core/restaurant_order_flow_schema.php';
global $pdo;   // connected to the ONE tenant this run is processing

echo "Starting tenant migration: restaurant order flow...\n";

try {
    restaurantOrderFlowEnsureSchema($pdo, function (string $m) { echo $m . "\n"; });
    echo "Migration complete.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
